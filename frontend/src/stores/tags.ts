import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api } from '@/services/api'
import { encryptString, decryptBlob } from '@/crypto/encrypt'
import { base64ToArrayBuffer } from '@/lib/util'
import { MAX_TAG_LENGTH, normalizeTagName, rankTagSuggestions } from '@/lib/tags'
import { useChildStore } from '@/stores/child'
import type { ChildTag, DecryptedChildTag } from '@/types/child'
import type { ApiPhoto, Photo, PhotoTag, RawPhotoTag } from '@/types/photo'

/**
 * Tags with the same normalised name (across children, or duplicates created by two
 * members racing) behave as one logical tag exposing every member id.
 */
export interface LogicalTag {
  key: string
  name: string
  ids: number[]
  childIds: number[]
  photo_count: number
}

const decoder = new TextDecoder()

async function decryptName(tag: { name_encrypted: string; iv_name: string }, dek: CryptoKey) {
  const bytes = await decryptBlob(base64ToArrayBuffer(tag.name_encrypted), dek, tag.iv_name)
  return decoder.decode(bytes)
}

/** Trimmed name capped at the UI limit; empty when nothing usable was entered. */
export function cleanTagName(name: string): string {
  return name.trim().replace(/\s+/g, ' ').slice(0, MAX_TAG_LENGTH)
}

/** After a failed background load, screens wait this long before asking for the same tags again. */
const TAG_RETRY_DELAY_MS = 30_000

export const useTagsStore = defineStore('tags', () => {
  const byChild = ref<Map<number, DecryptedChildTag[]>>(new Map())
  const loading = new Map<number, Promise<void>>()
  const failedAt = new Map<number, number>()
  const creating = new Map<string, Promise<DecryptedChildTag>>()

  function tagsFor(childId: number): DecryptedChildTag[] {
    return byChild.value.get(childId) ?? []
  }

  /** Fetches and decrypts one child's tag list; cached for the session unless `force`. */
  async function loadForChild(childId: number, force = false): Promise<void> {
    if (!force && byChild.value.has(childId)) return
    const pending = loading.get(childId)
    if (pending) return pending

    const run = (async () => {
      const dek = await useChildStore().tryGetChildDEK(childId)
      // Without the key the names are unreadable: skip, and retry on a later call.
      if (!dek) return

      const response = await api.get<{ data: ChildTag[] }>(`/children/${childId}/tags`)
      const decrypted: DecryptedChildTag[] = []
      for (const tag of response.data.data) {
        try {
          const name = await decryptName(tag, dek)
          decrypted.push({ ...tag, child_id: childId, name, normalized: normalizeTagName(name) })
        } catch {
          // Undecryptable tag: never show ciphertext.
        }
      }
      byChild.value.set(childId, decrypted)
    })().finally(() => loading.delete(childId))

    loading.set(childId, run)
    return run
  }

  /** Background load for the screens that list tags; a failed child is not retried right away. */
  async function loadAll(childIds: number[]): Promise<void> {
    const now = Date.now()
    await Promise.all(
      childIds
        .filter((id) => now - (failedAt.get(id) ?? -Infinity) >= TAG_RETRY_DELAY_MS)
        .map((id) =>
          loadForChild(id)
            .then(() => failedAt.delete(id))
            .catch((err: unknown) => {
              failedAt.set(id, Date.now())
              console.error('Failed to load tags:', err)
            }),
        ),
    )
  }

  const logicalTags = computed<LogicalTag[]>(() => {
    const groups = new Map<string, DecryptedChildTag[]>()
    for (const tags of byChild.value.values()) {
      for (const tag of tags) {
        const group = groups.get(tag.normalized)
        if (group) group.push(tag)
        else groups.set(tag.normalized, [tag])
      }
    }

    return [...groups.entries()]
      .map(([key, members]) => {
        const oldest = members.reduce((a, b) => (b.id < a.id ? b : a))
        return {
          key,
          name: oldest.name,
          ids: members.map((t) => t.id).sort((a, b) => a - b),
          childIds: [...new Set(members.map((t) => t.child_id))],
          photo_count: members.reduce((sum, t) => sum + t.photo_count, 0),
        }
      })
      .sort((a, b) => b.photo_count - a.photo_count || a.name.localeCompare(b.name))
  })

  /** Suggestions for a typed query, optionally limited to tags that exist for certain children. */
  function suggest(query: string, childIds?: number[], limit?: number): LogicalTag[] {
    const pool = childIds
      ? logicalTags.value.filter((t) => t.childIds.some((id) => childIds.includes(id)))
      : logicalTags.value
    return rankTagSuggestions(pool, query, limit)
  }

  /** Every tag id behind the given logical names; names that match nothing are ignored. */
  function resolveIds(names: string[]): number[] {
    const wanted = new Set(names.map(normalizeTagName))
    return logicalTags.value.filter((t) => wanted.has(t.key)).flatMap((t) => t.ids)
  }

  /**
   * Returns the child's tag with this name, creating it (encrypted with the child's DEK)
   * only when no tag with the same normalised name exists. Dedup is client-side.
   */
  async function findOrCreate(childId: number, name: string): Promise<DecryptedChildTag> {
    const clean = cleanTagName(name)
    const normalized = normalizeTagName(clean)
    if (!normalized) throw new Error('Tag name is empty.')

    await loadForChild(childId)
    const existing = tagsFor(childId)
      .filter((t) => t.normalized === normalized)
      .sort((a, b) => a.id - b.id)[0]
    if (existing) return existing

    const key = `${childId}:${normalized}`
    const inFlight = creating.get(key)
    if (inFlight) return inFlight

    const run = (async () => {
      const dek = await useChildStore().ensureDEK(childId)
      const { encrypted, iv } = await encryptString(clean, dek)
      const response = await api.post<{ data: ChildTag }>(`/children/${childId}/tags`, {
        name_encrypted: encrypted,
        iv_name: iv,
      })
      const created: DecryptedChildTag = {
        ...response.data.data,
        child_id: childId,
        name: clean,
        normalized,
      }
      byChild.value.set(childId, [...tagsFor(childId), created])
      // Hand back the reactive copy so counters bumped by callers update the UI.
      return tagsFor(childId).find((t) => t.id === created.id) ?? created
    })().finally(() => creating.delete(key))

    creating.set(key, run)
    return run
  }

  /** Tag ids (one per child) to send with an upload of `names` to `childIds`. */
  async function idsForUpload(childIds: number[], names: string[]): Promise<number[]> {
    const ids: number[] = []
    for (const name of names) {
      for (const childId of childIds) {
        ids.push((await findOrCreate(childId, name)).id)
      }
    }
    return [...new Set(ids)]
  }

  /** Tags a photo for every one of its children the user can decrypt for. */
  async function attach(photo: Photo, name: string): Promise<void> {
    const normalized = normalizeTagName(name)
    const childStore = useChildStore()
    const toAttach: DecryptedChildTag[] = []

    for (const child of photo.children) {
      if (!(await childStore.tryGetChildDEK(child.id))) continue
      const alreadyTagged = photo.tags.some(
        (t) => t.child_id === child.id && normalizeTagName(t.name) === normalized,
      )
      if (alreadyTagged) continue
      toAttach.push(await findOrCreate(child.id, name))
    }
    if (toAttach.length === 0) return

    await api.post(`/photos/${photo.id}/tags`, { tag_ids: toAttach.map((t) => t.id) })

    photo.tags = [
      ...photo.tags,
      ...toAttach.map(
        (t): PhotoTag => ({
          id: t.id,
          child_id: t.child_id,
          name_encrypted: t.name_encrypted,
          iv_name: t.iv_name,
          name: t.name,
        }),
      ),
    ]
    for (const t of toAttach) t.photo_count++
  }

  /** Removes a logical tag from a photo: detaches every same-named tag the photo carries. */
  async function detach(photo: Photo, tagId: number): Promise<void> {
    const target = photo.tags.find((t) => t.id === tagId)
    if (!target) return
    const normalized = normalizeTagName(target.name)
    const doomed = photo.tags.filter((t) => normalizeTagName(t.name) === normalized)

    for (const tag of doomed) {
      await api.delete(`/photos/${photo.id}/tags/${tag.id}`)
      const cached = tagsFor(tag.child_id).find((t) => t.id === tag.id)
      if (cached && cached.photo_count > 0) cached.photo_count--
    }
    const gone = new Set(doomed.map((t) => t.id))
    photo.tags = photo.tags.filter((t) => !gone.has(t.id))
  }

  /** Deletes a tag everywhere (creator or child owner/admin only; the server enforces it). */
  async function remove(tagId: number): Promise<void> {
    await api.delete(`/tags/${tagId}`)
    for (const [childId, tags] of byChild.value) {
      byChild.value.set(
        childId,
        tags.filter((t) => t.id !== tagId),
      )
    }
  }

  /**
   * Decrypts the tag names in a photo payload with the matching child DEKs.
   * A tag whose DEK is unavailable (or that fails to decrypt) is dropped, never shown as ciphertext.
   */
  async function decryptPhotoTags(photos: ApiPhoto[]): Promise<Photo[]> {
    const childStore = useChildStore()
    const deks = new Map<number, CryptoKey | null>()

    async function dekFor(childId: number): Promise<CryptoKey | null> {
      if (!deks.has(childId)) deks.set(childId, await childStore.tryGetChildDEK(childId))
      return deks.get(childId) ?? null
    }

    return Promise.all(
      photos.map(async (photo): Promise<Photo> => {
        const tags: PhotoTag[] = []
        for (const raw of (photo.tags ?? []) as RawPhotoTag[]) {
          const dek = await dekFor(raw.child_id)
          if (!dek) continue
          try {
            tags.push({ ...raw, name: await decryptName(raw, dek) })
          } catch {
            // skip
          }
        }
        return { ...photo, tags }
      }),
    )
  }

  function reset(): void {
    byChild.value = new Map()
  }

  return {
    byChild,
    logicalTags,
    tagsFor,
    loadForChild,
    loadAll,
    suggest,
    resolveIds,
    findOrCreate,
    idsForUpload,
    attach,
    detach,
    remove,
    decryptPhotoTags,
    reset,
  }
})
