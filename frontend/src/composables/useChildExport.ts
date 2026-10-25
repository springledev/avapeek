import { computed, ref, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useChildStore } from '@/stores/child'
import { unwrapPEK } from '@/crypto/keychain'
import {
  buildManifest,
  buildManifestEntry,
  decryptExif,
  decryptPhotoName,
  decryptTagNames,
  decryptText,
  downloadOriginalBytes,
  listExportComments,
  listExportPage,
  type ExportComment,
  type ExportPhoto,
  type ManifestComment,
  type ManifestEntry,
  type ManifestFailure,
} from '@/services/export'
import { resolveFilename, sanitizeFilename, uniquePath, yearFolder } from '@/lib/export/filename'
import {
  createZipStream,
  defaultPartBytes,
  planParts,
  type PartPlan,
  type PlanItem,
  type ZipEntry,
} from '@/lib/export/zip'
import { createExportSink, type ExportSink } from '@/lib/export/sink'

export type ExportState =
  | 'idle'
  | 'listing'
  | 'exporting'
  | 'paused'
  | 'done'
  | 'error'
  | 'cancelled'

export interface UseChildExportOptions {
  /** Max bytes per zip part when the browser cannot stream to disk. */
  partBytes?: number
  /** Base delay for per-photo retries (doubles each attempt). */
  backoffMs?: number
  maxAttempts?: number
  /** Overrides sink detection (tests). */
  createSink?: () => ExportSink
}

const DEFAULT_BACKOFF_MS = 500
const DEFAULT_ATTEMPTS = 3

function isAbortError(error: unknown): boolean {
  return error instanceof DOMException && error.name === 'AbortError'
}

function pad(n: number): string {
  return String(n).padStart(2, '0')
}

function delay(ms: number, signal: AbortSignal): Promise<void> {
  return new Promise((resolve, reject) => {
    if (signal.aborted) return reject(signal.reason)
    const timer = setTimeout(resolve, ms)
    signal.addEventListener(
      'abort',
      () => {
        clearTimeout(timer)
        reject(signal.reason)
      },
      { once: true },
    )
  })
}

/**
 * Client-side "export everything for one child": lists ciphertext in keyset
 * pages, downloads and decrypts originals one at a time, and streams them into
 * a store-only zip together with a manifest. Plaintext only ever lives in this
 * tab's memory and the user's chosen output; the decrypted cache is never used.
 */
export function useChildExport(options: UseChildExportOptions = {}) {
  const authStore = useAuthStore()
  const childStore = useChildStore()

  const partBytes =
    options.partBytes ?? defaultPartBytes(typeof navigator === 'undefined' ? '' : navigator.userAgent)
  const backoffMs = options.backoffMs ?? DEFAULT_BACKOFF_MS
  const maxAttempts = options.maxAttempts ?? DEFAULT_ATTEMPTS
  const makeSink = options.createSink ?? createExportSink

  const state = ref<ExportState>('idle')
  const total = ref(0)
  const totalBytes = ref(0)
  const photosDone = ref(0)
  const bytesDone = ref(0)
  const partIndex = ref(0)
  const partCount = ref(0)
  const failed = ref<ManifestFailure[]>([])
  const errorMessage = ref<string | null>(null)
  /** Last photo id of the last part that was fully saved; resume continues after it. */
  const lastCompletedPhotoId = ref(0)
  const prepared = ref(false)
  const streaming = ref(false)

  const canResume = computed(
    () =>
      prepared.value &&
      (state.value === 'paused' || state.value === 'error' || state.value === 'cancelled') &&
      !streaming.value,
  )

  let child: { id: number; name: string } | null = null
  let controller: AbortController | null = null
  let abortReason: 'cancel' | 'pause' | null = null
  let manifestEntries: ManifestEntry[] = []
  let usedPaths = new Set<string>()
  let partsSaved = 0
  /** Progress as of the last fully saved part; a failed part is rolled back to this. */
  let committed = { entries: 0, failed: 0, photos: 0, bytes: 0 }

  function rollbackToCommitted(): void {
    manifestEntries = manifestEntries.slice(0, committed.entries)
    usedPaths = new Set(manifestEntries.map((entry) => entry.file.toLowerCase()))
    failed.value = failed.value.slice(0, committed.failed)
    photosDone.value = committed.photos
    bytesDone.value = committed.bytes
  }

  async function listPlan(afterId: number, signal: AbortSignal): Promise<PlanItem[]> {
    const items: PlanItem[] = []
    let cursor = afterId
    for (;;) {
      const page = await listExportPage(child!.id, cursor, signal)
      for (const photo of page.data) items.push({ id: photo.id, size_bytes: photo.size_bytes })
      if (page.meta.next_after_id === null) return items
      cursor = page.meta.next_after_id
    }
  }

  /** Lists the child's photos (ids and sizes only) so the UI can show count and size. */
  async function prepare(target: { id: number; name: string }): Promise<void> {
    child = target
    prepared.value = false
    errorMessage.value = null
    state.value = 'listing'
    controller = new AbortController()
    try {
      const items = await listPlan(0, controller.signal)
      total.value = items.length
      totalBytes.value = items.reduce((sum, item) => sum + item.size_bytes, 0)
      prepared.value = true
      state.value = 'idle'
    } catch (error: unknown) {
      if (controller.signal.aborted || isAbortError(error)) {
        state.value = 'idle'
        return
      }
      errorMessage.value = "Couldn't load the photo list. Try again."
      state.value = 'error'
    }
  }

  async function loadComments(signal: AbortSignal): Promise<Map<number, ExportComment[]>> {
    const byPhoto = new Map<number, ExportComment[]>()
    let cursor = 0
    for (;;) {
      const page = await listExportComments(child!.id, cursor, signal)
      for (const comment of page.data) {
        const list = byPhoto.get(comment.photo_id)
        if (list) list.push(comment)
        else byPhoto.set(comment.photo_id, [comment])
      }
      if (page.meta.next_after_id === null) return byPhoto
      cursor = page.meta.next_after_id
    }
  }

  async function* photosInPart(part: PartPlan, signal: AbortSignal): AsyncGenerator<ExportPhoto> {
    let cursor = part.startAfterId
    for (;;) {
      const page = await listExportPage(child!.id, cursor, signal)
      for (const photo of page.data) {
        if (photo.id > part.lastId) return
        yield photo
      }
      if (page.meta.next_after_id === null) return
      cursor = page.meta.next_after_id
    }
  }

  async function downloadWithRetry(
    photo: ExportPhoto,
    pek: CryptoKey,
    signal: AbortSignal,
  ): Promise<ArrayBuffer> {
    for (let attempt = 1; ; attempt++) {
      try {
        return await downloadOriginalBytes(photo, pek, { signal })
      } catch (error: unknown) {
        if (signal.aborted || attempt >= maxAttempts) throw error
        await delay(backoffMs * 2 ** (attempt - 1), signal)
      }
    }
  }

  async function* entriesForPart(
    part: PartPlan,
    partNumber: number,
    totalParts: number,
    isLast: boolean,
    comments: Map<number, ExportComment[]>,
    signal: AbortSignal,
  ): AsyncGenerator<ZipEntry> {
    const dek = await childStore.ensureDEK(child!.id)
    const root = sanitizeFilename(child!.name) || 'export'
    const partFiles: Array<{ file: string; id: number }> = []

    for await (const photo of photosInPart(part, signal)) {
      signal.throwIfAborted()
      try {
        const pek = await unwrapPEK(photo.encrypted_pek, photo.iv_pek, dek)
        const bytes = await downloadWithRetry(photo, pek, signal)

        const name = resolveFilename(photo, await decryptPhotoName(photo, pek))
        const path = uniquePath(`${root}/photos/${yearFolder(photo)}`, name, usedPaths)

        const photoComments: ManifestComment[] = []
        for (const comment of comments.get(photo.id) ?? []) {
          try {
            photoComments.push({
              author: comment.author_name,
              created_at: comment.created_at,
              text: await decryptText(comment.body_encrypted, comment.iv_body, pek),
            })
          } catch {
            // Skip undecryptable comments rather than exporting ciphertext.
          }
        }

        manifestEntries.push(
          buildManifestEntry(photo, {
            file: path,
            part: partNumber,
            exif: await decryptExif(photo, pek),
            tags: await decryptTagNames(photo, dek),
            comments: photoComments,
          }),
        )
        partFiles.push({ file: path, id: photo.id })
        photosDone.value++
        bytesDone.value += bytes.byteLength

        yield {
          name: path,
          input: new Uint8Array(bytes),
          lastModified: new Date(photo.taken_at || photo.created_at),
        }
      } catch (error: unknown) {
        if (signal.aborted) throw error
        // Generic reason only: never log or store decrypted data.
        failed.value = [
          ...failed.value,
          { id: photo.id, reason: error instanceof Error ? error.name : 'Error' },
        ]
      }
    }

    if (totalParts > 1) {
      yield {
        name: `${root}/part-index.json`,
        input: JSON.stringify({ part: partNumber, of: totalParts, files: partFiles }, null, 2),
      }
    }
    if (isLast) {
      const manifest = buildManifest({
        childName: child!.name,
        exportedAt: new Date(),
        parts: totalParts,
        entries: manifestEntries,
        failed: failed.value,
      })
      yield { name: `${root}/manifest.json`, input: JSON.stringify(manifest, null, 2) }
    }
  }

  async function run(): Promise<void> {
    if (!child) return
    controller = new AbortController()
    abortReason = null
    const signal = controller.signal
    errorMessage.value = null
    rollbackToCommitted()
    const stopWatching = watch(
      () => authStore.isAuthenticated,
      (authenticated) => {
        if (!authenticated) controller?.abort()
      },
    )

    try {
      const sink = makeSink()
      streaming.value = sink.streaming
      const root = sanitizeFilename(child.name) || 'export'

      // A streaming sink opens its save dialog now, still inside the user's click.
      let pending = sink.streaming ? await sink.open(`${root}-export.zip`) : null

      state.value = 'listing'
      const items = await listPlan(lastCompletedPhotoId.value, signal)
      const plan = sink.streaming
        ? planParts(items, Number.POSITIVE_INFINITY, lastCompletedPhotoId.value)
        : planParts(items, partBytes, lastCompletedPhotoId.value)
      const comments = await loadComments(signal)

      state.value = 'exporting'
      const totalParts = partsSaved + plan.length
      partCount.value = totalParts

      for (let i = 0; i < plan.length; i++) {
        const part = plan[i]!
        const partNumber = partsSaved + 1
        partIndex.value = partNumber
        const filename = sink.streaming
          ? `${root}-export.zip`
          : `${root}-export-part-${pad(partNumber)}-of-${pad(totalParts)}.zip`
        const file = pending ?? (await sink.open(filename))
        pending = null

        try {
          await file.write(
            createZipStream(
              entriesForPart(part, partNumber, totalParts, i === plan.length - 1, comments, signal),
            ),
            signal,
          )
        } catch (error: unknown) {
          await file.discard()
          throw error
        }

        lastCompletedPhotoId.value = part.lastId
        partsSaved++
        committed = {
          entries: manifestEntries.length,
          failed: failed.value.length,
          photos: photosDone.value,
          bytes: bytesDone.value,
        }
      }

      state.value = 'done'
    } catch (error: unknown) {
      // Progress shows only what is safely saved; the unsaved part is redone on resume.
      rollbackToCommitted()
      if (signal.aborted || isAbortError(error)) {
        state.value = abortReason === 'pause' ? 'paused' : 'cancelled'
      } else {
        errorMessage.value = "The export stopped before it finished. You can try again."
        state.value = 'error'
      }
    } finally {
      stopWatching()
    }
  }

  /** Starts a fresh export of the prepared child. Call from the click handler. */
  async function start(): Promise<void> {
    if (!child || !prepared.value) return
    photosDone.value = 0
    bytesDone.value = 0
    partIndex.value = 0
    partCount.value = 0
    failed.value = []
    lastCompletedPhotoId.value = 0
    manifestEntries = []
    usedPaths = new Set()
    partsSaved = 0
    committed = { entries: 0, failed: 0, photos: 0, bytes: 0 }
    await run()
  }

  /** Continues after a pause, cancel or error with a new zip part after the last saved one. */
  async function resume(): Promise<void> {
    if (!canResume.value) return
    await run()
  }

  function stop(reason: 'cancel' | 'pause'): void {
    abortReason = reason
    controller?.abort()
  }

  const cancel = () => stop('cancel')
  const pause = () => stop('pause')

  return {
    state,
    total,
    totalBytes,
    photosDone,
    bytesDone,
    partIndex,
    partCount,
    failed,
    errorMessage,
    lastCompletedPhotoId,
    streaming,
    canResume,
    prepared,
    prepare,
    start,
    resume,
    cancel,
    pause,
  }
}
