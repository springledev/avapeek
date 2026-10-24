/**
 * Export/download helpers shared by single-photo download, "export everything"
 * and (later) bulk download. No UI and no store coupling: callers pass the PEK.
 *
 * Everything here deliberately bypasses `@/lib/cache/decrypted-cache`: plaintext
 * originals must never be written to the persistent IndexedDB cache by an export.
 */
import { api } from '@/services/api'
import { decryptBlob } from '@/crypto/encrypt'
import { base64ToArrayBuffer } from '@/lib/util'
import { resolveFilename, type FilenameSource } from '@/lib/export/filename'
import type { RawPhotoTag } from '@/types/photo'

/** A photo as returned by `GET /children/{child}/export/photos` (ciphertext only). */
export interface ExportPhoto {
  id: number
  uuid: string
  original_mime: string
  size_bytes: number
  width: number | null
  height: number | null
  iv_original: string
  name_encrypted: string | null
  iv_name: string | null
  exif_encrypted: string | null
  iv_exif: string | null
  taken_at: string | null
  created_at: string
  uploader_name: string | null
  /** Only the requested child's tags; names are encrypted with that child's DEK. */
  tags: RawPhotoTag[]
  /** The requested child's PEK wrap. */
  encrypted_pek: string
  iv_pek: string
  presigned_url: string
}

export interface ExportComment {
  id: number
  photo_id: number
  user_id: number
  author_name: string | null
  body_encrypted: string
  iv_body: string
  created_at: string
}

export interface ExportPage<T> {
  data: T[]
  meta: { next_after_id: number | null; total?: number }
}

export class ExportDownloadError extends Error {
  constructor(public readonly status: number) {
    // Generic on purpose: never put names or other decrypted data in errors.
    super(`Original download failed (${status})`)
    this.name = 'ExportDownloadError'
  }
}

const EXPIRED_URL_STATUSES = new Set([400, 401, 403])
const RATE_LIMIT_RETRIES = 5

function sleep(ms: number, signal?: AbortSignal): Promise<void> {
  return new Promise((resolve, reject) => {
    if (signal?.aborted) return reject(signal.reason)
    const timer = setTimeout(resolve, ms)
    signal?.addEventListener(
      'abort',
      () => {
        clearTimeout(timer)
        reject(signal.reason)
      },
      { once: true },
    )
  })
}

function retryAfterMs(error: unknown): number {
  const header = (error as { response?: { headers?: Record<string, string> } }).response?.headers?.[
    'retry-after'
  ]
  const seconds = Number(header)
  return Number.isFinite(seconds) && seconds > 0 ? Math.min(seconds, 60) * 1000 : 2000
}

/** GET with a bounded retry on 429 (the export limiter is 30 requests per minute). */
async function getWithRateLimitRetry<T>(
  url: string,
  params: Record<string, number>,
  signal?: AbortSignal,
): Promise<T> {
  for (let attempt = 0; ; attempt++) {
    try {
      const response = await api.get<T>(url, { params, signal })
      return response.data
    } catch (error: unknown) {
      const status = (error as { response?: { status?: number } }).response?.status
      if (status !== 429 || attempt >= RATE_LIMIT_RETRIES) throw error
      await sleep(retryAfterMs(error), signal)
    }
  }
}

export function listExportPage(
  childId: number,
  afterId: number,
  signal?: AbortSignal,
  limit = 100,
): Promise<ExportPage<ExportPhoto>> {
  return getWithRateLimitRetry(
    `/children/${childId}/export/photos`,
    { after_id: afterId, limit },
    signal,
  )
}

export function listExportComments(
  childId: number,
  afterId: number,
  signal?: AbortSignal,
): Promise<ExportPage<ExportComment>> {
  return getWithRateLimitRetry(
    `/children/${childId}/export/comments`,
    { after_id: afterId, limit: 200 },
    signal,
  )
}

async function fetchFreshOriginalUrl(photoId: number, signal?: AbortSignal): Promise<string> {
  const response = await api.get<{ data: { presigned_url: string } }>(`/photos/${photoId}`, {
    signal,
  })
  return response.data.data.presigned_url
}

export interface OriginalSource {
  id: number
  iv_original: string
  presigned_url?: string | null
}

/**
 * Downloads and decrypts the original file: the raw bytes exactly as uploaded
 * (HEIC stays HEIC, no re-encoding). Uses `photo.presigned_url` when given and,
 * if storage rejects it as expired, fetches a fresh URL from `GET /photos/{id}`
 * once and retries.
 */
export async function downloadOriginalBytes(
  photo: OriginalSource,
  pek: CryptoKey,
  options: { signal?: AbortSignal } = {},
): Promise<ArrayBuffer> {
  const { signal } = options
  let url = photo.presigned_url ?? null
  let refreshed = false
  if (!url) {
    url = await fetchFreshOriginalUrl(photo.id, signal)
    refreshed = true
  }

  for (;;) {
    const response = await fetch(url, { signal })
    if (response.ok) {
      const encrypted = await response.arrayBuffer()
      return await decryptBlob(encrypted, pek, photo.iv_original)
    }
    if (!refreshed && EXPIRED_URL_STATUSES.has(response.status)) {
      url = await fetchFreshOriginalUrl(photo.id, signal)
      refreshed = true
      continue
    }
    throw new ExportDownloadError(response.status)
  }
}

/**
 * Original bytes plus the filename to save them under, ready for `saveBlob`.
 * The blob carries the stored `original_mime`; no re-encoding happens.
 */
export async function prepareOriginalDownload(
  photo: OriginalSource &
    FilenameSource & { name_encrypted?: string | null; iv_name?: string | null },
  pek: CryptoKey,
  options: { signal?: AbortSignal } = {},
): Promise<{ blob: Blob; filename: string }> {
  const bytes = await downloadOriginalBytes(photo, pek, options)
  const filename = resolveFilename(photo, await decryptPhotoName(photo, pek))
  return { blob: new Blob([bytes], { type: photo.original_mime }), filename }
}

/** Decrypts a base64 ciphertext + hex IV into text. */
export async function decryptText(
  encryptedBase64: string,
  iv: string,
  key: CryptoKey,
): Promise<string> {
  const bytes = await decryptBlob(base64ToArrayBuffer(encryptedBase64), key, iv)
  return new TextDecoder().decode(bytes)
}

/** The decrypted original filename, or null when none was stored or it cannot be decrypted. */
export async function decryptPhotoName(
  photo: { name_encrypted?: string | null; iv_name?: string | null },
  pek: CryptoKey,
): Promise<string | null> {
  if (!photo.name_encrypted || !photo.iv_name) return null
  try {
    return await decryptText(photo.name_encrypted, photo.iv_name, pek)
  } catch {
    return null
  }
}

export interface ManifestComment {
  author: string | null
  created_at: string
  text: string
}

export interface ManifestEntry {
  file: string
  part: number
  id: number
  uuid: string
  mime: string
  size_bytes: number
  width: number | null
  height: number | null
  taken_at: string | null
  created_at: string
  uploader: string | null
  tags: string[]
  exif: unknown
  comments: ManifestComment[]
}

export interface ManifestFailure {
  id: number
  reason: string
}

/** Builds one manifest row from an export photo and its already decrypted parts. */
export function buildManifestEntry(
  photo: ExportPhoto,
  decrypted: {
    file: string
    part: number
    exif: unknown
    tags: string[]
    comments: ManifestComment[]
  },
): ManifestEntry {
  return {
    file: decrypted.file,
    part: decrypted.part,
    id: photo.id,
    uuid: photo.uuid,
    mime: photo.original_mime,
    size_bytes: photo.size_bytes,
    width: photo.width,
    height: photo.height,
    taken_at: photo.taken_at,
    created_at: photo.created_at,
    uploader: photo.uploader_name,
    tags: decrypted.tags,
    exif: decrypted.exif ?? null,
    comments: decrypted.comments,
  }
}

export interface Manifest {
  version: 1
  app: 'avapeek'
  child: string
  exported_at: string
  parts: number
  photo_count: number
  photos: ManifestEntry[]
  failed: ManifestFailure[]
}

export function buildManifest(input: {
  childName: string
  exportedAt: Date
  parts: number
  entries: ManifestEntry[]
  failed: ManifestFailure[]
}): Manifest {
  return {
    version: 1,
    app: 'avapeek',
    child: input.childName,
    exported_at: input.exportedAt.toISOString(),
    parts: input.parts,
    photo_count: input.entries.length,
    photos: input.entries,
    failed: input.failed,
  }
}

/** Decrypts EXIF JSON; null when absent, undecryptable or not valid JSON. */
export async function decryptExif(photo: ExportPhoto, pek: CryptoKey): Promise<unknown> {
  if (!photo.exif_encrypted || !photo.iv_exif) return null
  try {
    return JSON.parse(await decryptText(photo.exif_encrypted, photo.iv_exif, pek)) as unknown
  } catch {
    return null
  }
}

/** Decrypts the tag names of an export photo with the requested child's DEK; skips undecryptable ones. */
export async function decryptTagNames(photo: ExportPhoto, childDek: CryptoKey): Promise<string[]> {
  const names: string[] = []
  for (const tag of photo.tags) {
    try {
      names.push(await decryptText(tag.name_encrypted, tag.iv_name, childDek))
    } catch {
      // Never export ciphertext as a name.
    }
  }
  return names
}
