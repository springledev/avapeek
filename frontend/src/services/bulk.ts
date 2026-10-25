/**
 * Bulk photo actions: download, remove, tags, share. UI-free; callers pass photos and
 * an optional AbortSignal. Keys are only handled through the crypto helpers and never
 * leave the browser: sharing sends only a fresh wrap of each photo key under the target
 * kid's key.
 */
import { api } from '@/services/api'
import { rewrapPEK } from '@/crypto/keychain'
import { getPEKForPhoto } from '@/services/storage'
import { prepareOriginalDownload } from '@/services/export'
import { saveBlob } from '@/lib/export/sink'
import { uniquePath } from '@/lib/export/filename'
import { runBatches } from '@/lib/batch'
import { normalizeTagName } from '@/lib/tags'
import { useChildStore } from '@/stores/child'
import { useTagsStore } from '@/stores/tags'
import type { Photo, PhotoChild } from '@/types/photo'
import type { DecryptedChildTag } from '@/types/child'

export const DELETE_BATCH = 100
export const TAGS_BATCH = 100
export const SHARE_BATCH = 50
export const MAX_TAGS_PER_PHOTO = 20

type Progress = (processed: number, total: number) => void

interface BulkOptions {
  signal?: AbortSignal
  onProgress?: Progress
}

// ---------------------------------------------------------------- download

export interface DownloadSummary {
  saved: number
  failed: number
  cancelled: boolean
}

/** One file at a time, original bytes and decrypted name; a failing photo does not stop the rest. */
export async function downloadPhotos(
  photos: readonly Photo[],
  options: BulkOptions = {},
): Promise<DownloadSummary> {
  const { signal, onProgress } = options
  const used = new Set<string>()
  const summary: DownloadSummary = { saved: 0, failed: 0, cancelled: false }
  onProgress?.(0, photos.length)

  for (const [index, photo] of photos.entries()) {
    if (signal?.aborted) {
      summary.cancelled = true
      break
    }
    try {
      const pek = await getPEKForPhoto(photo.children)
      if (!pek) throw new Error('No key for this photo')
      const { blob, filename } = await prepareOriginalDownload(photo, pek, { signal })
      saveBlob(blob, uniquePath('', filename, used).slice(1))
      summary.saved++
    } catch {
      if (signal?.aborted) {
        summary.cancelled = true
        break
      }
      summary.failed++
    }
    onProgress?.(index + 1, photos.length)
  }
  return summary
}

// ------------------------------------------------------------------ remove

export interface RemoveSummary {
  deleted: number[]
  detached: number[]
  forbidden: number[]
  /** Photos in batches that were not applied (cancelled or failed). */
  notProcessed: number
  cancelled: boolean
  error: unknown
}

interface BulkDeleteResponse {
  deleted: number[]
  detached: number[]
  forbidden: number[]
}

export async function removePhotos(
  photoIds: readonly number[],
  options: BulkOptions = {},
): Promise<RemoveSummary> {
  const run = await runBatches(
    photoIds,
    DELETE_BATCH,
    async (batch, signal) =>
      (await api.post<BulkDeleteResponse>('/photos/bulk-delete', { photo_ids: batch }, { signal }))
        .data,
    { signal: options.signal, onProgress: options.onProgress },
  )
  return {
    deleted: run.results.flatMap((r) => r.deleted),
    detached: run.results.flatMap((r) => r.detached),
    forbidden: run.results.flatMap((r) => r.forbidden),
    notProcessed: photoIds.length - run.processed,
    cancelled: run.cancelled,
    error: run.error,
  }
}

// -------------------------------------------------------------------- tags

export interface TagAssignment {
  photo_id: number
  tag_ids: number[]
}

/**
 * Per photo, the ids of the tags (one per kid the photo is linked to, found or created by
 * `resolveTagId`) that carry each requested name. `resolveTagId` returns null for kids the
 * user cannot tag for; photos that end up with nothing to attach are left out.
 */
export async function buildTagAssignments(
  photos: readonly Photo[],
  names: readonly string[],
  resolveTagId: (childId: number, name: string) => Promise<number | null>,
): Promise<TagAssignment[]> {
  const assignments: TagAssignment[] = []
  for (const photo of photos) {
    const ids = new Set<number>()
    for (const name of names) {
      for (const child of photo.children) {
        const id = await resolveTagId(child.id, name)
        if (id !== null) ids.add(id)
      }
    }
    if (ids.size > 0) assignments.push({ photo_id: photo.id, tag_ids: [...ids] })
  }
  return assignments
}

export interface TagSummary {
  updated: number[]
  skipped: number[]
  notProcessed: number
  cancelled: boolean
  error: unknown
}

/**
 * Tags every photo with the given names. Missing per-kid tags are created first; a 422 from
 * the per-kid tag cap rejects with the API error before anything is attached.
 */
export async function tagPhotos(
  photos: readonly Photo[],
  names: readonly string[],
  options: BulkOptions = {},
): Promise<TagSummary> {
  const childStore = useChildStore()
  const tagsStore = useTagsStore()
  const resolved = new Map<number, DecryptedChildTag>()

  const wanted = await buildTagAssignments(photos, names, async (childId, name) => {
    if (!(await childStore.tryGetChildDEK(childId))) return null
    const tag = await tagsStore.findOrCreate(childId, name)
    resolved.set(tag.id, tag)
    return tag.id
  })

  // The server allows 20 tags per photo; never truncate silently, report those photos as skipped.
  const assignments = wanted.filter((a) => a.tag_ids.length <= MAX_TAGS_PER_PHOTO)
  const overLimit = wanted.filter((a) => a.tag_ids.length > MAX_TAGS_PER_PHOTO).map((a) => a.photo_id)

  const run = await runBatches(
    assignments,
    TAGS_BATCH,
    async (batch, signal) =>
      (
        await api.post<{ updated: number[]; skipped: number[] }>(
          '/photos/bulk-tags',
          { assignments: batch },
          { signal },
        )
      ).data,
    { signal: options.signal, onProgress: options.onProgress },
  )

  const updated = run.results.flatMap((r) => r.updated)
  applyTagsLocally(photos, assignments, updated, resolved)
  return {
    updated,
    skipped: [...overLimit, ...run.results.flatMap((r) => r.skipped)],
    notProcessed: assignments.length - run.processed,
    cancelled: run.cancelled,
    error: run.error,
  }
}

function applyTagsLocally(
  photos: readonly Photo[],
  assignments: readonly TagAssignment[],
  updated: readonly number[],
  tags: ReadonlyMap<number, DecryptedChildTag>,
): void {
  const done = new Set(updated)
  for (const assignment of assignments) {
    if (!done.has(assignment.photo_id)) continue
    const photo = photos.find((p) => p.id === assignment.photo_id)
    if (!photo) continue
    const have = new Set(photo.tags.map((t) => t.id))
    for (const id of assignment.tag_ids) {
      const tag = tags.get(id)
      if (!tag || have.has(id)) continue
      photo.tags = [
        ...photo.tags,
        {
          id,
          child_id: tag.child_id,
          name_encrypted: tag.name_encrypted,
          iv_name: tag.iv_name,
          name: tag.name,
        },
      ]
      tag.photo_count++
    }
  }
}

/** Normalises and dedupes typed tag names, keeping the first spelling. */
export function uniqueTagNames(names: readonly string[]): string[] {
  const seen = new Map<string, string>()
  for (const name of names) {
    const key = normalizeTagName(name)
    if (key && !seen.has(key)) seen.set(key, name)
  }
  return [...seen.values()]
}

// ------------------------------------------------------------------- share

interface ShareEntry {
  photo_id: number
  encrypted_pek: string
  iv_pek: string
}

export interface ShareSummary {
  shared: number[]
  skipped: number[]
  /** Photos we could not wrap (no key held for any linked kid, or decryption failed). */
  unwrappable: number[]
  /** Wraps stored for the shared photos, for updating the local photo objects. */
  wraps: Map<number, { encrypted_pek: string; iv_pek: string }>
  notProcessed: number
  cancelled: boolean
  error: unknown
}

/** Kids (by id) that already link every photo in the list; they are no useful share target. */
export function childrenLinkingAll(photos: readonly Photo[]): Set<number> {
  const [first, ...rest] = photos
  if (!first) return new Set()
  const ids = new Set(first.children.map((c) => c.id))
  for (const photo of rest) {
    const linked = new Set(photo.children.map((c) => c.id))
    for (const id of ids) if (!linked.has(id)) ids.delete(id)
  }
  return ids
}

/**
 * Re-wraps each photo key from a kid whose key we hold to the target kid's key, entirely in
 * the browser (`rewrapPEK`), then posts the wraps in batches of 50. Photos the target already
 * links are not sent.
 */
export async function sharePhotos(
  photos: readonly Photo[],
  targetChildId: number,
  options: BulkOptions = {},
): Promise<ShareSummary> {
  const childStore = useChildStore()
  const targetDek = await childStore.ensureDEK(targetChildId)

  const entries: ShareEntry[] = []
  const unwrappable: number[] = []
  const alreadyLinked: number[] = []

  for (const photo of photos) {
    if (photo.children.some((c) => c.id === targetChildId)) {
      alreadyLinked.push(photo.id)
      continue
    }
    const wrap = await rewrapFromAnyKnownChild(photo.children, targetDek)
    if (wrap) entries.push({ photo_id: photo.id, ...wrap })
    else unwrappable.push(photo.id)
  }

  const run = await runBatches(
    entries,
    SHARE_BATCH,
    async (batch, signal) =>
      (
        await api.post<{ shared: number[]; skipped: number[] }>(
          '/photos/share',
          { child_id: targetChildId, photos: batch },
          { signal },
        )
      ).data,
    { signal: options.signal, onProgress: options.onProgress },
  )

  const shared = run.results.flatMap((r) => r.shared)
  const sharedSet = new Set(shared)
  const wraps = new Map(
    entries
      .filter((e) => sharedSet.has(e.photo_id))
      .map((e) => [e.photo_id, { encrypted_pek: e.encrypted_pek, iv_pek: e.iv_pek }] as const),
  )
  return {
    shared,
    skipped: [...alreadyLinked, ...run.results.flatMap((r) => r.skipped)],
    unwrappable,
    wraps,
    notProcessed: entries.length - run.processed,
    cancelled: run.cancelled,
    error: run.error,
  }
}

async function rewrapFromAnyKnownChild(
  children: readonly PhotoChild[],
  targetDek: CryptoKey,
): Promise<{ encrypted_pek: string; iv_pek: string } | null> {
  const childStore = useChildStore()
  for (const child of children) {
    const sourceDek = await childStore.tryGetChildDEK(child.id)
    if (!sourceDek) continue
    try {
      return await rewrapPEK(child.encrypted_pek, child.iv_pek, sourceDek, targetDek)
    } catch {
      // Wrong key or damaged wrap for this kid: try the next one.
    }
  }
  return null
}
