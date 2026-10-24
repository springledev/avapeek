// @vitest-environment node
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

vi.mock('@/services/api', () => ({
  api: {
    get: vi.fn<(url: string, config?: { params?: Record<string, number> }) => Promise<unknown>>(),
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ isAuthenticated: true }),
}))

vi.mock('@/stores/child', () => ({
  useChildStore: () => ({ ensureDEK: async () => ({ type: 'dek' }) }),
}))

vi.mock('@/crypto/keychain', () => ({
  unwrapPEK: vi.fn<() => Promise<unknown>>(async () => ({ type: 'pek' })),
}))

// Identity "decryption": ciphertext is the plaintext bytes, so assertions stay readable.
vi.mock('@/crypto/encrypt', () => ({
  decryptBlob: vi.fn<(buffer: ArrayBuffer) => Promise<ArrayBuffer>>(async (buffer) => buffer),
}))

vi.mock('@/lib/cache/decrypted-cache', () => ({
  getOriginal: vi.fn<() => Promise<string>>(),
  getThumbnail: vi.fn<() => Promise<string>>(),
  removeUrlFromCache: vi.fn<() => void>(),
}))

import { api } from '@/services/api'
import * as decryptedCache from '@/lib/cache/decrypted-cache'
import { useChildExport } from '@/composables/useChildExport'
import type { ExportSink } from '@/lib/export/sink'
import type { ExportComment, ExportPhoto } from '@/services/export'

const b64 = (text: string) => Buffer.from(text).toString('base64')

function makePhoto(id: number, overrides: Partial<ExportPhoto> = {}): ExportPhoto {
  return {
    id,
    uuid: `uuid-${id}`,
    original_mime: 'image/jpeg',
    size_bytes: 100,
    width: 10,
    height: 10,
    iv_original: 'iv',
    name_encrypted: null,
    iv_name: null,
    exif_encrypted: null,
    iv_exif: null,
    taken_at: '2024-06-01T12:00:00.000Z',
    created_at: '2024-06-02T12:00:00.000Z',
    uploader_name: 'Ana',
    tags: [],
    encrypted_pek: 'p',
    iv_pek: 'i',
    presigned_url: `https://s3.test/${id}`,
    ...overrides,
  }
}

interface SavedFile {
  filename: string
  entries: Map<string, Uint8Array>
  discarded: boolean
}

/** Reads names and contents of a store-only zip (sizes are in the central directory). */
function readZip(bytes: Uint8Array): Map<string, Uint8Array> {
  const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength)
  let eocd = bytes.length - 22
  while (view.getUint32(eocd, true) !== 0x06054b50) eocd--
  const count = view.getUint16(eocd + 10, true)
  let offset = view.getUint32(eocd + 16, true)
  const files = new Map<string, Uint8Array>()
  for (let i = 0; i < count; i++) {
    const size = view.getUint32(offset + 24, true)
    const nameLength = view.getUint16(offset + 28, true)
    const extraLength = view.getUint16(offset + 30, true)
    const commentLength = view.getUint16(offset + 32, true)
    const localOffset = view.getUint32(offset + 42, true)
    const name = new TextDecoder().decode(bytes.subarray(offset + 46, offset + 46 + nameLength))
    const start = localOffset + 30 + view.getUint16(localOffset + 26, true) + view.getUint16(localOffset + 28, true)
    files.set(name, bytes.slice(start, start + size))
    offset += 46 + nameLength + extraLength + commentLength
  }
  return files
}

function makeSink(options: { streaming?: boolean; failOnFile?: number | undefined } = {}) {
  const saved: SavedFile[] = []
  let opened = 0
  const sink: ExportSink = {
    streaming: options.streaming ?? false,
    async open(filename) {
      opened++
      const fileNumber = opened
      const record: SavedFile = { filename, entries: new Map(), discarded: false }
      return {
        async write(stream) {
          const buffer = new Uint8Array(await new Response(stream).arrayBuffer())
          if (options.failOnFile === fileNumber) throw new Error('disk full')
          record.entries = readZip(buffer)
          saved.push(record)
        },
        async discard() {
          record.discarded = true
        },
      }
    },
  }
  return { sink, saved, options, opened: () => opened }
}

let photos: ExportPhoto[]
let comments: ExportComment[]
let fetchMock: ReturnType<typeof vi.fn<(url: string, init?: RequestInit) => Promise<Response>>>
let apiGetMock: ReturnType<typeof vi.mocked<typeof api.get>>

beforeEach(() => {
  photos = [makePhoto(3), makePhoto(5), makePhoto(8), makePhoto(13)]
  comments = []
  fetchMock = vi.fn<(url: string, init?: RequestInit) => Promise<Response>>(async (url) => {
    const id = Number(url.split('/').pop())
    return new Response(new Uint8Array(100).fill(id), { status: 200 })
  })
  vi.stubGlobal('fetch', fetchMock)

  apiGetMock = vi.mocked(api.get)
  apiGetMock.mockReset()
  apiGetMock.mockImplementation(async (url, config) => {
    const afterId = config?.params?.after_id ?? 0
    const limit = config?.params?.limit ?? 100
    if (url.endsWith('/export/photos')) {
      const rest = photos.filter((p) => p.id > afterId)
      const page = rest.slice(0, limit)
      return {
        data: {
          data: page,
          meta: {
            next_after_id: rest.length > limit ? page[page.length - 1]!.id : null,
            ...(afterId === 0 ? { total: photos.length } : {}),
          },
        },
      }
    }
    if (url.endsWith('/export/comments')) {
      return { data: { data: comments.filter((c) => c.id > afterId), meta: { next_after_id: null } } }
    }
    const match = /\/photos\/(\d+)$/.exec(url)
    if (match) return { data: { data: { presigned_url: `https://s3.test/${match[1]}` } } }
    throw new Error(`unexpected GET ${url}`)
  })
})

afterEach(() => {
  vi.unstubAllGlobals()
})

const kid = { id: 1, name: 'Kid' }

function manifestOf(file: SavedFile) {
  return JSON.parse(new TextDecoder().decode(file.entries.get('Kid/manifest.json')!)) as {
    photos: Array<{ id: number; file: string; part: number; tags: string[]; comments: unknown[] }>
    failed: Array<{ id: number }>
    parts: number
  }
}

describe('useChildExport', () => {
  it('prepares count and size, then exports originals byte for byte with a manifest', async () => {
    photos[0] = makePhoto(3, { name_encrypted: b64('IMG_1.JPG'), iv_name: 'iv' })
    photos[1] = makePhoto(5, {
      tags: [{ id: 1, child_id: 1, name_encrypted: b64('beach'), iv_name: 'iv' }],
    })
    comments = [
      {
        id: 1,
        photo_id: 5,
        user_id: 2,
        author_name: 'Ben',
        body_encrypted: b64('so cute'),
        iv_body: 'iv',
        created_at: '2024-06-03T00:00:00.000Z',
      },
    ]
    const { sink, saved } = makeSink()
    const exporter = useChildExport({ createSink: () => sink, partBytes: 10_000 })

    await exporter.prepare(kid)
    expect(exporter.total.value).toBe(4)
    expect(exporter.totalBytes.value).toBe(400)
    expect(exporter.state.value).toBe('idle')

    await exporter.start()

    expect(exporter.state.value).toBe('done')
    expect(exporter.photosDone.value).toBe(4)
    expect(exporter.bytesDone.value).toBe(400)
    expect(saved).toHaveLength(1)
    expect(saved[0]!.filename).toBe('Kid-export-part-01-of-01.zip')
    const entries = saved[0]!.entries
    expect(entries.get('Kid/photos/2024/IMG_1.JPG')).toEqual(new Uint8Array(100).fill(3))
    expect(entries.get('Kid/photos/2024/avapeek-20240601-5.jpg')).toEqual(new Uint8Array(100).fill(5))
    const manifest = manifestOf(saved[0]!)
    expect(manifest.photos).toHaveLength(4)
    expect(manifest.photos[1]).toMatchObject({ id: 5, tags: ['beach'] })
    expect(manifest.photos[1]!.comments).toEqual([
      { author: 'Ben', created_at: '2024-06-03T00:00:00.000Z', text: 'so cute' },
    ])
    expect(manifest.failed).toEqual([])
  })

  it('never touches the decrypted cache', async () => {
    const { sink } = makeSink()
    const exporter = useChildExport({ createSink: () => sink })

    await exporter.prepare(kid)
    await exporter.start()

    expect(exporter.state.value).toBe('done')
    expect(decryptedCache.getOriginal).not.toHaveBeenCalled()
    expect(decryptedCache.getThumbnail).not.toHaveBeenCalled()
    expect(decryptedCache.removeUrlFromCache).not.toHaveBeenCalled()
  })

  it('splits into parts by size, with part indexes and the manifest in the last part only', async () => {
    const { sink, saved } = makeSink()
    const exporter = useChildExport({ createSink: () => sink, partBytes: 250 })
    await exporter.prepare(kid)

    await exporter.start()

    expect(saved.map((s) => s.filename)).toEqual([
      'Kid-export-part-01-of-02.zip',
      'Kid-export-part-02-of-02.zip',
    ])
    expect(saved[0]!.entries.has('Kid/part-index.json')).toBe(true)
    expect(saved[1]!.entries.has('Kid/part-index.json')).toBe(true)
    expect(saved[0]!.entries.has('Kid/manifest.json')).toBe(false)
    const manifest = manifestOf(saved[1]!)
    expect(manifest.photos.map((p) => [p.id, p.part])).toEqual([
      [3, 1],
      [5, 1],
      [8, 2],
      [13, 2],
    ])
    expect(exporter.lastCompletedPhotoId.value).toBe(13)
  })

  it('de-duplicates identical names across the export', async () => {
    for (const id of [3, 5]) {
      photos[photos.findIndex((p) => p.id === id)] = makePhoto(id, {
        name_encrypted: b64('same.jpg'),
        iv_name: 'iv',
      })
    }
    const { sink, saved } = makeSink()
    const exporter = useChildExport({ createSink: () => sink })
    await exporter.prepare(kid)

    await exporter.start()

    expect([...saved[0]!.entries.keys()]).toEqual(
      expect.arrayContaining(['Kid/photos/2024/same.jpg', 'Kid/photos/2024/same (2).jpg']),
    )
  })

  it('streams to a single file when the sink can stream', async () => {
    const { sink, saved, opened } = makeSink({ streaming: true })
    const exporter = useChildExport({ createSink: () => sink, partBytes: 250 })
    await exporter.prepare(kid)

    await exporter.start()

    expect(opened()).toBe(1)
    expect(saved).toHaveLength(1)
    expect(saved[0]!.filename).toBe('Kid-export.zip')
    expect(saved[0]!.entries.has('Kid/manifest.json')).toBe(true)
    expect(saved[0]!.entries.has('Kid/part-index.json')).toBe(false)
    expect(exporter.streaming.value).toBe(true)
    expect(exporter.canResume.value).toBe(false)
  })

  it('pages through large children with keyset cursors', async () => {
    photos = Array.from({ length: 250 }, (_, i) => makePhoto(i + 1))
    const { sink, saved } = makeSink({ streaming: true })
    const exporter = useChildExport({ createSink: () => sink })

    await exporter.prepare(kid)
    expect(exporter.total.value).toBe(250)
    await exporter.start()

    expect(exporter.photosDone.value).toBe(250)
    expect(manifestOf(saved[0]!).photos).toHaveLength(250)
  })

  it('cancels mid-way: aborts the in-flight fetch, stops further downloads and discards the partial file', async () => {
    const { sink, saved } = makeSink()
    const exporter = useChildExport({ createSink: () => sink, partBytes: 10_000 })
    await exporter.prepare(kid)
    fetchMock.mockImplementation(async (url, init) => {
      const id = Number(url.split('/').pop())
      if (id === 5) {
        exporter.cancel()
        throw init!.signal!.reason
      }
      return new Response(new Uint8Array(100).fill(id), { status: 200 })
    })

    await exporter.start()

    expect(exporter.state.value).toBe('cancelled')
    expect(saved).toHaveLength(0)
    expect(fetchMock.mock.calls.map((c) => c[0])).toEqual(['https://s3.test/3', 'https://s3.test/5'])
  })

  it('resumes after a failed part from lastCompletedPhotoId with a new part and complete manifest', async () => {
    const first = makeSink({ failOnFile: 2 })
    const exporter = useChildExport({ createSink: () => first.sink, partBytes: 250 })
    await exporter.prepare(kid)

    await exporter.start()

    expect(exporter.state.value).toBe('error')
    expect(exporter.lastCompletedPhotoId.value).toBe(5)
    expect(exporter.canResume.value).toBe(true)
    expect(first.saved).toHaveLength(1)
    expect(exporter.photosDone.value).toBe(2)

    first.options.failOnFile = undefined
    await exporter.resume()

    expect(exporter.state.value).toBe('done')
    expect(first.saved.slice(1).map((s) => s.filename)).toEqual(['Kid-export-part-02-of-02.zip'])
    const manifest = manifestOf(first.saved[1]!)
    expect(manifest.photos.map((p) => p.id)).toEqual([3, 5, 8, 13])
    expect(new Set(manifest.photos.map((p) => p.file)).size).toBe(4)
    expect(exporter.photosDone.value).toBe(4)
    expect(exporter.lastCompletedPhotoId.value).toBe(13)
  })

  it('pauses on request and resumes into a new part', async () => {
    const { sink, saved } = makeSink()
    const exporter = useChildExport({ createSink: () => sink, partBytes: 10_000 })
    await exporter.prepare(kid)
    fetchMock.mockImplementationOnce(async (url, init) => {
      exporter.pause()
      throw init!.signal!.reason
    })

    await exporter.start()
    expect(exporter.state.value).toBe('paused')
    expect(exporter.canResume.value).toBe(true)

    await exporter.resume()
    expect(exporter.state.value).toBe('done')
    expect(saved).toHaveLength(1)
    expect(manifestOf(saved[0]!).photos).toHaveLength(4)
  })

  it('retries a flaky photo, then records a permanently failing one in failed[] and the manifest', async () => {
    const { sink, saved } = makeSink()
    const exporter = useChildExport({ createSink: () => sink, backoffMs: 0 })
    await exporter.prepare(kid)
    const attempts = new Map<number, number>()
    fetchMock.mockImplementation(async (url) => {
      const id = Number(url.split('/').pop())
      attempts.set(id, (attempts.get(id) ?? 0) + 1)
      if (id === 5 && attempts.get(id)! < 3) throw new TypeError('network')
      if (id === 8) throw new TypeError('network')
      return new Response(new Uint8Array(100).fill(id), { status: 200 })
    })

    await exporter.start()

    expect(exporter.state.value).toBe('done')
    expect(attempts.get(5)).toBe(3)
    expect(attempts.get(8)).toBe(3)
    expect(exporter.failed.value.map((f) => f.id)).toEqual([8])
    expect(exporter.photosDone.value).toBe(3)
    const manifest = manifestOf(saved[0]!)
    expect(manifest.failed.map((f) => f.id)).toEqual([8])
    expect(manifest.photos.map((p) => p.id)).toEqual([3, 5, 13])
  })

  it('refreshes an expired url once through GET /photos/{id} and continues', async () => {
    const { sink, saved } = makeSink()
    const exporter = useChildExport({ createSink: () => sink, backoffMs: 0 })
    await exporter.prepare(kid)
    let first = true
    fetchMock.mockImplementation(async (url) => {
      const id = Number(url.split('/').pop())
      if (id === 3 && first) {
        first = false
        return new Response('expired', { status: 403 })
      }
      return new Response(new Uint8Array(100).fill(id), { status: 200 })
    })

    await exporter.start()

    expect(exporter.state.value).toBe('done')
    expect(exporter.failed.value).toEqual([])
    expect(apiGetMock.mock.calls.filter(([url]) => url === '/photos/3')).toHaveLength(1)
    expect(saved[0]!.entries.get('Kid/photos/2024/avapeek-20240601-3.jpg')).toEqual(
      new Uint8Array(100).fill(3),
    )
  })

  it('reports an error when listing fails and offers no resume without a prior part', async () => {
    const { sink } = makeSink()
    const exporter = useChildExport({ createSink: () => sink })
    apiGetMock.mockRejectedValue(new Error('boom'))

    await exporter.prepare(kid)

    expect(exporter.state.value).toBe('error')
    expect(exporter.errorMessage.value).toBeTruthy()
    expect(exporter.errorMessage.value).not.toContain('boom')
    expect(exporter.prepared.value).toBe(false)
  })

  it('treats a dismissed save dialog as cancelled', async () => {
    const sink: ExportSink = {
      streaming: true,
      open: async () => {
        throw new DOMException('dismissed', 'AbortError')
      },
    }
    const exporter = useChildExport({ createSink: () => sink })
    await exporter.prepare(kid)

    await exporter.start()

    expect(exporter.state.value).toBe('cancelled')
    expect(fetchMock).not.toHaveBeenCalled()
  })
})
