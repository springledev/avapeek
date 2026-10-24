import { describe, it, expect, vi, beforeEach } from 'vitest'

const post = vi.hoisted(() => vi.fn<(url: string, body: unknown, config?: unknown) => Promise<unknown>>())
vi.mock('@/services/api', () => ({ api: { post } }))

const rewrapPEK = vi.hoisted(() =>
  vi.fn<(w: string, iv: string, source: unknown, target: unknown) => Promise<unknown>>(),
)
vi.mock('@/crypto/keychain', () => ({ rewrapPEK }))

const getPEKForPhoto = vi.hoisted(() => vi.fn<(c: unknown) => Promise<unknown>>())
vi.mock('@/services/storage', () => ({ getPEKForPhoto }))

const prepareOriginalDownload = vi.hoisted(() =>
  vi.fn<(photo: { id: number }, pek: unknown, o?: unknown) => Promise<{ blob: Blob; filename: string }>>(),
)
vi.mock('@/services/export', () => ({ prepareOriginalDownload }))

const saveBlob = vi.hoisted(() => vi.fn<(blob: Blob, name: string) => void>())
vi.mock('@/lib/export/sink', () => ({ saveBlob }))

const dekFor = vi.hoisted(() => new Map<number, unknown>())
vi.mock('@/stores/child', () => ({
  useChildStore: () => ({
    tryGetChildDEK: async (id: number) => dekFor.get(id) ?? null,
    ensureDEK: async (id: number) => {
      const dek = dekFor.get(id)
      if (!dek) throw new Error('no key')
      return dek
    },
  }),
}))

const findOrCreate = vi.hoisted(() =>
  vi.fn<(childId: number, name: string) => Promise<Record<string, unknown>>>(),
)
vi.mock('@/stores/tags', () => ({ useTagsStore: () => ({ findOrCreate }) }))

import {
  buildTagAssignments,
  childrenLinkingAll,
  downloadPhotos,
  removePhotos,
  sharePhotos,
  tagPhotos,
} from '@/services/bulk'
import type { Photo } from '@/types/photo'

function photo(id: number, childIds: number[], extra: Partial<Photo> = {}): Photo {
  return {
    id,
    original_mime: 'image/heic',
    taken_at: null,
    created_at: '2024-01-01T00:00:00Z',
    tags: [],
    children: childIds.map((c) => ({
      id: c,
      name: `kid${c}`,
      avatar_color: null,
      encrypted_pek: `wrap-${id}-${c}`,
      iv_pek: `iv-${id}-${c}`,
    })),
    ...extra,
  } as Photo
}

beforeEach(() => {
  vi.clearAllMocks()
  dekFor.clear()
})

describe('share', () => {
  it('rewraps with the source and target DEKs and never sends the photo key', async () => {
    dekFor.set(1, 'dek1')
    dekFor.set(2, 'dek2')
    rewrapPEK.mockImplementation(async (w) => ({ encrypted_pek: `new-${w}`, iv_pek: 'fresh-iv' }))
    post.mockResolvedValue({ data: { shared: [10], skipped: [] } })

    const summary = await sharePhotos([photo(10, [1])], 2)

    expect(rewrapPEK).toHaveBeenCalledWith('wrap-10-1', 'iv-10-1', 'dek1', 'dek2')
    expect(post).toHaveBeenCalledWith(
      '/photos/share',
      { child_id: 2, photos: [{ photo_id: 10, encrypted_pek: 'new-wrap-10-1', iv_pek: 'fresh-iv' }] },
      expect.anything(),
    )
    expect(summary.shared).toEqual([10])
    expect(summary.wraps.get(10)).toEqual({ encrypted_pek: 'new-wrap-10-1', iv_pek: 'fresh-iv' })
  })

  it('uses the first linked kid whose key is held and falls through a failing wrap', async () => {
    dekFor.set(1, 'dek1')
    dekFor.set(3, 'dek3')
    dekFor.set(2, 'dek2')
    rewrapPEK.mockRejectedValueOnce(new Error('bad wrap')).mockResolvedValueOnce({
      encrypted_pek: 'ok',
      iv_pek: 'iv',
    })
    post.mockResolvedValue({ data: { shared: [10], skipped: [] } })

    await sharePhotos([photo(10, [1, 3])], 2)
    expect(rewrapPEK).toHaveBeenNthCalledWith(2, 'wrap-10-3', 'iv-10-3', 'dek3', 'dek2')
  })

  it('reports photos without a usable key and photos the target already links, sending neither', async () => {
    dekFor.set(2, 'dek2')
    const summary = await sharePhotos([photo(10, [9]), photo(11, [2])], 2)
    expect(post).not.toHaveBeenCalled()
    expect(summary.unwrappable).toEqual([10])
    expect(summary.skipped).toEqual([11])
  })

  it('batches 120 photos as 50, 50, 20', async () => {
    dekFor.set(1, 'dek1')
    dekFor.set(2, 'dek2')
    rewrapPEK.mockResolvedValue({ encrypted_pek: 'x', iv_pek: 'y' })
    post.mockImplementation(async (_url, body) => ({
      data: { shared: (body as { photos: { photo_id: number }[] }).photos.map((p) => p.photo_id), skipped: [] },
    }))

    const photos = Array.from({ length: 120 }, (_, i) => photo(i + 1, [1]))
    const summary = await sharePhotos(photos, 2)

    expect(post.mock.calls.map((c) => (c[1] as { photos: unknown[] }).photos.length)).toEqual([50, 50, 20])
    expect(summary.shared).toHaveLength(120)
  })

  it('keeps completed batches when a later batch fails', async () => {
    dekFor.set(1, 'dek1')
    dekFor.set(2, 'dek2')
    rewrapPEK.mockResolvedValue({ encrypted_pek: 'x', iv_pek: 'y' })
    post
      .mockResolvedValueOnce({ data: { shared: Array.from({ length: 50 }, (_, i) => i + 1), skipped: [] } })
      .mockRejectedValueOnce(new Error('network'))

    const summary = await sharePhotos(
      Array.from({ length: 70 }, (_, i) => photo(i + 1, [1])),
      2,
    )
    expect(summary.shared).toHaveLength(50)
    expect(summary.notProcessed).toBe(20)
    expect(summary.error).toBeInstanceOf(Error)
  })

  it('stops before the next batch when cancelled', async () => {
    dekFor.set(1, 'dek1')
    dekFor.set(2, 'dek2')
    rewrapPEK.mockResolvedValue({ encrypted_pek: 'x', iv_pek: 'y' })
    const controller = new AbortController()
    post.mockImplementation(async () => {
      controller.abort()
      return { data: { shared: [], skipped: [] } }
    })

    const summary = await sharePhotos(
      Array.from({ length: 60 }, (_, i) => photo(i + 1, [1])),
      2,
      { signal: controller.signal },
    )
    expect(post).toHaveBeenCalledTimes(1)
    expect(summary.cancelled).toBe(true)
    expect(summary.notProcessed).toBe(10)
  })

  it('finds kids that already link every selected photo', () => {
    const linked = childrenLinkingAll([photo(1, [1, 2]), photo(2, [2, 3])])
    expect([...linked]).toEqual([2])
    expect(childrenLinkingAll([]).size).toBe(0)
  })
})

describe('remove', () => {
  it('batches at 100 and merges the buckets', async () => {
    post.mockImplementation(async (_url, body) => {
      const ids = (body as { photo_ids: number[] }).photo_ids
      return { data: { deleted: ids.slice(0, 1), detached: ids.slice(1, 2), forbidden: ids.slice(2) } }
    })
    const ids = Array.from({ length: 101 }, (_, i) => i + 1)
    const summary = await removePhotos(ids)
    expect(post.mock.calls.map((c) => (c[1] as { photo_ids: number[] }).photo_ids.length)).toEqual([100, 1])
    expect(summary.deleted).toEqual([1, 101])
    expect(summary.detached).toEqual([2])
    expect(summary.forbidden).toHaveLength(98)
    expect(summary.notProcessed).toBe(0)
  })
})

describe('tags', () => {
  it('builds one tag id per linked kid with a key, creating missing tags', async () => {
    const resolve = vi.fn<(c: number, n: string) => Promise<number | null>>(async (c, n) =>
      c === 9 ? null : c * 100 + n.length,
    )
    const out = await buildTagAssignments([photo(1, [1, 2]), photo(2, [9])], ['ab', 'cde'], resolve)
    expect(out).toEqual([{ photo_id: 1, tag_ids: [102, 202, 103, 203] }])
  })

  it('posts assignments and updates local photos for updated ids only', async () => {
    dekFor.set(1, 'dek1')
    findOrCreate.mockResolvedValue({
      id: 55,
      child_id: 1,
      name: 'Beach',
      name_encrypted: 'enc',
      iv_name: 'iv',
      photo_count: 0,
    })
    post.mockResolvedValue({ data: { updated: [1], skipped: [2] } })
    const p1 = photo(1, [1])
    const p2 = photo(2, [1])

    const summary = await tagPhotos([p1, p2], ['Beach'])

    expect(post).toHaveBeenCalledWith(
      '/photos/bulk-tags',
      { assignments: [{ photo_id: 1, tag_ids: [55] }, { photo_id: 2, tag_ids: [55] }] },
      expect.anything(),
    )
    expect(summary.updated).toEqual([1])
    expect(p1.tags.map((t) => t.name)).toEqual(['Beach'])
    expect(p2.tags).toEqual([])
  })

  it('reports photos needing more than 20 tags as skipped instead of truncating', async () => {
    dekFor.set(1, 'dek1')
    let next = 0
    findOrCreate.mockImplementation(async (childId) => ({
      id: ++next,
      child_id: childId,
      name: 'n',
      name_encrypted: 'e',
      iv_name: 'i',
      photo_count: 0,
    }))
    const names = Array.from({ length: 21 }, (_, i) => `tag${i}`)

    // 21 names for a single kid is 21 tag ids on one photo, over the server limit.
    const summary = await tagPhotos([photo(1, [1])], names)
    expect(post).not.toHaveBeenCalled()
    expect(summary.skipped).toEqual([1])
    expect(summary.updated).toEqual([])
  })

  it('surfaces a tag cap failure before attaching anything', async () => {
    dekFor.set(1, 'dek1')
    findOrCreate.mockRejectedValue(Object.assign(new Error('cap'), { response: { status: 422 } }))
    await expect(tagPhotos([photo(1, [1])], ['x'])).rejects.toThrow('cap')
    expect(post).not.toHaveBeenCalled()
  })
})

describe('download', () => {
  it('saves the untouched bytes under the resolved filename, one at a time, deduping names', async () => {
    getPEKForPhoto.mockResolvedValue('pek')
    prepareOriginalDownload.mockImplementation(async () => ({
      blob: new Blob(['x'], { type: 'image/heic' }),
      filename: 'IMG_1.heic',
    }))

    const summary = await downloadPhotos([photo(1, [1]), photo(2, [1])])

    expect(prepareOriginalDownload).toHaveBeenCalledTimes(2)
    expect(saveBlob.mock.calls.map((c) => c[1])).toEqual(['IMG_1.heic', 'IMG_1 (2).heic'])
    expect(saveBlob.mock.calls[0]![0].type).toBe('image/heic')
    expect(summary).toEqual({ saved: 2, failed: 0, cancelled: false })
  })

  it('counts failures and continues; cancel stops further downloads', async () => {
    getPEKForPhoto.mockResolvedValueOnce(null).mockResolvedValue('pek')
    const controller = new AbortController()
    prepareOriginalDownload.mockImplementation(async () => {
      controller.abort()
      return { blob: new Blob(['x']), filename: 'a.jpg' }
    })

    const summary = await downloadPhotos([photo(1, [1]), photo(2, [1]), photo(3, [1])], {
      signal: controller.signal,
    })
    expect(summary.failed).toBe(1)
    expect(summary.saved).toBe(1)
    expect(summary.cancelled).toBe(true)
    expect(prepareOriginalDownload).toHaveBeenCalledTimes(1)
  })
})
