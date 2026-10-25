import { describe, it, expect, vi, beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import type { AxiosResponse } from 'axios'

vi.mock('@/services/api', () => ({
  api: {
    get: vi.fn<(url: string, config?: unknown) => Promise<unknown>>(),
    post: vi.fn<(url: string, data?: unknown) => Promise<unknown>>(),
    delete: vi.fn<(url: string) => Promise<unknown>>(),
  },
  default: {
    setAuthToken: vi.fn<(token: string) => void>(),
    clearAuthToken: vi.fn<() => void>(),
  },
}))

vi.mock('@/crypto/encrypt', () => ({
  encryptString: vi.fn<(text: string, dek: CryptoKey) => Promise<{ encrypted: string; iv: string }>>(),
  decryptBlob: vi.fn<(buffer: ArrayBuffer, dek: CryptoKey, iv: string) => Promise<ArrayBuffer>>(),
}))

import { api } from '@/services/api'
import { encryptString, decryptBlob } from '@/crypto/encrypt'
import { useChildStore } from '@/stores/child'
import { useFeedStore } from '@/stores/feed'
import { useTagsStore } from '@/stores/tags'
import { normalizeTagName, rankTagSuggestions } from '@/lib/tags'
import type { ApiPhoto, Photo } from '@/types/photo'

const HEX_IV = '00112233445566778899aabbccddeeff'
const dekA = { child: 1 } as unknown as CryptoKey
const dekB = { child: 2 } as unknown as CryptoKey

function ok<T>(data: T): AxiosResponse<T> {
  return { data, status: 200, statusText: 'OK', headers: {}, config: { headers: {} } } as unknown as AxiosResponse<T>
}

/** The mocked "ciphertext" of a name is just its base64; decrypt reverses it. */
function cipher(name: string): string {
  return btoa(String.fromCharCode(...new TextEncoder().encode(name)))
}

function apiTag(id: number, name: string, photoCount = 0) {
  return { id, name_encrypted: cipher(name), iv_name: HEX_IV, photo_count: photoCount, created_at: '2026-10-24T10:00:00.000Z' }
}

function rawTag(id: number, childId: number, name: string) {
  return { id, child_id: childId, name_encrypted: cipher(name), iv_name: HEX_IV }
}

function listFor(map: Record<number, ReturnType<typeof apiTag>[]>) {
  vi.mocked(api.get).mockImplementation(async (url: string) => {
    const match = /\/children\/(\d+)\/tags/.exec(url)
    return ok({ data: match ? (map[Number(match[1])] ?? []) : [] })
  })
}

function basePhoto(overrides: Partial<ApiPhoto> = {}): ApiPhoto {
  return {
    id: 1,
    uuid: 'u',
    uploaded_by: 1,
    original_path: 'o',
    thumbnail_path: 't',
    iv_original: HEX_IV,
    iv_thumbnail: HEX_IV,
    original_mime: 'image/jpeg',
    size_bytes: 1,
    width: null,
    height: null,
    exif_encrypted: null,
    iv_exif: null,
    taken_at: null,
    created_at: '2026-10-24T10:00:00.000Z',
    tags: [],
    children: [{ id: 1, name: 'A', avatar_color: null, encrypted_pek: 'p', iv_pek: HEX_IV }],
    ...overrides,
  }
}

describe('tags store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(api.get).mockReset()
    vi.mocked(api.post).mockReset()
    vi.mocked(api.delete).mockReset()
    vi.mocked(encryptString)
      .mockReset()
      .mockImplementation(async (text: string) => ({ encrypted: cipher(text), iv: HEX_IV }))
    vi.mocked(decryptBlob)
      .mockReset()
      .mockImplementation(async (buffer: ArrayBuffer) => buffer)

    const childStore = useChildStore()
    vi.spyOn(childStore, 'tryGetChildDEK').mockImplementation(async (id: number) =>
      id === 1 ? dekA : id === 2 ? dekB : null,
    )
    vi.spyOn(childStore, 'ensureDEK').mockImplementation(async (id: number) => {
      if (id === 1) return dekA
      if (id === 2) return dekB
      throw new Error('No encryption key found for this child.')
    })
  })

  it('decrypts and caches a child tag list', async () => {
    listFor({ 1: [apiTag(10, 'Spain 2026', 3)] })
    const store = useTagsStore()

    await store.loadForChild(1)
    await store.loadForChild(1)

    expect(api.get).toHaveBeenCalledTimes(1)
    expect(store.tagsFor(1)).toMatchObject([{ id: 10, name: 'Spain 2026', photo_count: 3, child_id: 1 }])
    expect(decryptBlob).toHaveBeenCalledWith(expect.any(ArrayBuffer), dekA, HEX_IV)
  })

  it('skips children whose DEK is missing and tags that fail to decrypt', async () => {
    listFor({ 1: [apiTag(10, 'ok'), apiTag(11, 'broken')], 9: [apiTag(20, 'secret')] })
    vi.mocked(decryptBlob).mockImplementation(async (buffer: ArrayBuffer) => {
      if (new TextDecoder().decode(buffer) === 'broken') throw new Error('bad tag')
      return buffer
    })
    const store = useTagsStore()

    await store.loadAll([1, 9])

    expect(store.tagsFor(1).map((t) => t.name)).toEqual(['ok'])
    expect(store.tagsFor(9)).toEqual([])
    expect(api.get).not.toHaveBeenCalledWith('/children/9/tags')
  })

  it('normalises names and groups duplicates (same child and across children) into one logical tag', async () => {
    listFor({
      1: [apiTag(10, 'Zoë  First Steps', 2), apiTag(12, 'zoë first steps', 1)],
      2: [apiTag(30, 'ZOË FIRST STEPS', 4), apiTag(31, 'Beach', 1)],
    })
    const store = useTagsStore()
    await store.loadAll([1, 2])

    expect(normalizeTagName('  Zoë   First Steps ')).toBe('zoë first steps')
    expect(store.logicalTags).toHaveLength(2)
    const group = store.logicalTags[0]!
    expect(group.ids).toEqual([10, 12, 30])
    expect([...group.childIds].sort()).toEqual([1, 2])
    expect(group.photo_count).toBe(7)
    expect(group.name).toBe('Zoë  First Steps')
  })

  it('findOrCreate reuses an existing normalised name instead of POSTing', async () => {
    listFor({ 1: [apiTag(10, 'Spain 2026')] })
    const store = useTagsStore()

    const tag = await store.findOrCreate(1, '  spain   2026 ')

    expect(tag.id).toBe(10)
    expect(api.post).not.toHaveBeenCalled()
  })

  it('findOrCreate encrypts with the child DEK and POSTs ciphertext only', async () => {
    listFor({ 1: [] })
    vi.mocked(api.post).mockResolvedValue(ok({ data: apiTag(50, 'Beach day') }))
    const store = useTagsStore()

    const tag = await store.findOrCreate(1, ' Beach   day ')

    expect(encryptString).toHaveBeenCalledWith('Beach day', dekA)
    expect(api.post).toHaveBeenCalledTimes(1)
    const [url, body] = vi.mocked(api.post).mock.calls[0]!
    expect(url).toBe('/children/1/tags')
    expect(body).toEqual({ name_encrypted: cipher('Beach day'), iv_name: HEX_IV })
    expect(tag).toMatchObject({ id: 50, name: 'Beach day', child_id: 1 })
    expect(store.tagsFor(1)).toHaveLength(1)
  })

  it('does not POST twice for concurrent creates of the same name', async () => {
    listFor({ 1: [] })
    vi.mocked(api.post).mockResolvedValue(ok({ data: apiTag(51, 'Park') }))
    const store = useTagsStore()

    await Promise.all([store.findOrCreate(1, 'Park'), store.findOrCreate(1, 'park')])

    expect(api.post).toHaveBeenCalledTimes(1)
  })

  it('ranks suggestions: diacritic-insensitive prefix first, then substring, by photo count', () => {
    const tags = [
      { name: 'Birthday', photo_count: 1 },
      { name: 'Zoë first steps', photo_count: 2 },
      { name: 'Zoe at the beach', photo_count: 9 },
      { name: 'Lazo trip', photo_count: 50 },
      { name: 'Beach', photo_count: 5 },
    ]

    expect(rankTagSuggestions(tags, 'zoe').map((t) => t.name)).toEqual([
      'Zoe at the beach',
      'Zoë first steps',
    ])
    expect(rankTagSuggestions(tags, 'zo').map((t) => t.name)).toEqual([
      'Zoe at the beach',
      'Zoë first steps',
      'Lazo trip',
    ])
    expect(rankTagSuggestions(tags, '')).toEqual([])
    const many = Array.from({ length: 20 }, (_, i) => ({ name: `Trip ${i}`, photo_count: i }))
    expect(rankTagSuggestions(many, 'trip')).toHaveLength(8)
  })

  it('filter resolves logical names to every grouped id', async () => {
    listFor({ 1: [apiTag(10, 'Beach')], 2: [apiTag(30, 'beach'), apiTag(31, 'Park')] })
    const store = useTagsStore()
    await store.loadAll([1, 2])

    expect([...store.resolveIds(['BEACH'])].sort()).toEqual([10, 30])

    const feed = useFeedStore()
    feed.setTagFilter(['Beach'])
    expect(feed.buildParams(1)).toMatchObject({ tag_ids: '10,30', page: '1' })
    expect(feed.buildParams(1)).not.toHaveProperty('tags')

    feed.setTagFilter(['unknown'])
    expect(feed.buildParams(1).tag_ids).toBe('0')
  })

  it('decrypts photo tags and skips tags whose DEK is not available', async () => {
    const store = useTagsStore()
    const photos = await store.decryptPhotoTags([
      basePhoto({ tags: [rawTag(10, 1, 'Beach'), rawTag(40, 9, 'Not mine')] }),
    ])

    expect(photos[0]!.tags).toMatchObject([{ id: 10, child_id: 1, name: 'Beach' }])
    expect(photos[0]!.tags).toHaveLength(1)
  })

  it('idsForUpload creates one tag per chosen child and returns ids only', async () => {
    listFor({ 1: [], 2: [apiTag(30, 'Beach')] })
    vi.mocked(api.post).mockResolvedValue(ok({ data: apiTag(60, 'Beach') }))
    const store = useTagsStore()

    const ids = await store.idsForUpload([1, 2], ['Beach'])

    expect(ids).toEqual([60, 30])
    expect(api.post).toHaveBeenCalledTimes(1)
  })

  it('attach tags a photo for each decryptable child and updates its tags', async () => {
    listFor({ 1: [apiTag(10, 'Beach')], 2: [] })
    vi.mocked(api.post).mockImplementation(async (url: string) =>
      url === '/children/2/tags' ? ok({ data: apiTag(70, 'Beach') }) : ok({ data: {} }),
    )
    const store = useTagsStore()
    const photo = (
      await store.decryptPhotoTags([
        basePhoto({
          children: [
            { id: 1, name: 'A', avatar_color: null, encrypted_pek: 'p', iv_pek: HEX_IV },
            { id: 2, name: 'B', avatar_color: null, encrypted_pek: 'p', iv_pek: HEX_IV },
            { id: 9, name: 'Other', avatar_color: null, encrypted_pek: 'p', iv_pek: HEX_IV },
          ],
        }),
      ])
    )[0] as Photo

    await store.attach(photo, 'beach')

    expect(api.post).toHaveBeenCalledWith('/photos/1/tags', { tag_ids: [10, 70] })
    expect(photo.tags.map((t) => t.id)).toEqual([10, 70])
  })

  it('detach removes every same-named tag the photo carries', async () => {
    const store = useTagsStore()
    vi.mocked(api.delete).mockResolvedValue(ok({}))
    const photo = (
      await store.decryptPhotoTags([
        basePhoto({ tags: [rawTag(10, 1, 'Beach'), rawTag(30, 2, 'beach'), rawTag(11, 1, 'Park')] }),
      ])
    )[0] as Photo

    await store.detach(photo, 10)

    expect(api.delete).toHaveBeenCalledWith('/photos/1/tags/10')
    expect(api.delete).toHaveBeenCalledWith('/photos/1/tags/30')
    expect(photo.tags.map((t) => t.id)).toEqual([11])
  })
})
