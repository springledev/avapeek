import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

vi.mock('@/services/api', () => ({
  api: {
    get: vi.fn<(url: string, config?: unknown) => Promise<unknown>>(),
  },
}))

import { api } from '@/services/api'
import { encryptBuffer } from '@/crypto/encrypt'
import {
  ExportDownloadError,
  buildManifest,
  buildManifestEntry,
  decryptExif,
  decryptPhotoName,
  decryptTagNames,
  downloadOriginalBytes,
  listExportPage,
  prepareOriginalDownload,
  type ExportPhoto,
} from '@/services/export'

async function newKey(): Promise<CryptoKey> {
  return crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, true, ['encrypt', 'decrypt'])
}

async function sha256(buffer: ArrayBuffer): Promise<string> {
  const digest = new Uint8Array(await crypto.subtle.digest('SHA-256', buffer))
  return [...digest].map((b) => b.toString(16).padStart(2, '0')).join('')
}

function toBuffer(bytes: number[]): ArrayBuffer {
  return new Uint8Array(bytes).buffer
}

function okResponse(body: ArrayBuffer): Response {
  return new Response(body, { status: 200 })
}

async function encryptedText(text: string, key: CryptoKey): Promise<{ encrypted: string; iv: string }> {
  const { encrypted, iv } = await encryptBuffer(new TextEncoder().encode(text).buffer as ArrayBuffer, key)
  return { encrypted: btoa(String.fromCharCode(...new Uint8Array(encrypted))), iv }
}

const fetchMock = vi.fn<(url: string, init?: RequestInit) => Promise<Response>>()

beforeEach(() => {
  fetchMock.mockReset()
  vi.mocked(api.get).mockReset()
  vi.stubGlobal('fetch', fetchMock)
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('downloadOriginalBytes', () => {
  it('returns JPEG bytes identical to what was uploaded', async () => {
    const key = await newKey()
    const jpeg = toBuffer(Array.from({ length: 2000 }, (_, i) => (i * 7) % 256))
    const { encrypted, iv } = await encryptBuffer(jpeg, key)
    fetchMock.mockResolvedValue(okResponse(encrypted))

    const result = await downloadOriginalBytes(
      { id: 1, iv_original: iv, presigned_url: 'https://s3.test/1' },
      key,
    )

    expect(await sha256(result)).toBe(await sha256(jpeg))
    expect(api.get).not.toHaveBeenCalled()
  })

  it('keeps HEIC originals as HEIC bytes and names them .heic', async () => {
    const key = await newKey()
    const heic = new TextEncoder().encode('\u0000\u0000\u0000\u0018ftypheic-original-payload').buffer as ArrayBuffer
    const { encrypted, iv } = await encryptBuffer(heic, key)
    fetchMock.mockResolvedValue(okResponse(encrypted))

    const { blob, filename } = await prepareOriginalDownload(
      {
        id: 9,
        iv_original: iv,
        presigned_url: 'https://s3.test/9',
        original_mime: 'image/heic',
        taken_at: '2024-05-01T10:00:00.000Z',
        created_at: '2024-05-02T10:00:00.000Z',
      },
      key,
    )

    expect(blob.type).toBe('image/heic')
    expect(filename).toBe('avapeek-20240501-9.heic')
    expect(await sha256(await blob.arrayBuffer())).toBe(await sha256(heic))
  })

  it('uses the decrypted original filename when stored', async () => {
    const key = await newKey()
    const payload = toBuffer([1, 2, 3])
    const { encrypted, iv } = await encryptBuffer(payload, key)
    const name = await encryptedText('../IMG_0042.JPG', key)
    fetchMock.mockResolvedValue(okResponse(encrypted))

    const { filename } = await prepareOriginalDownload(
      {
        id: 3,
        iv_original: iv,
        presigned_url: 'https://s3.test/3',
        original_mime: 'image/jpeg',
        taken_at: null,
        created_at: '2024-05-02T10:00:00.000Z',
        name_encrypted: name.encrypted,
        iv_name: name.iv,
      },
      key,
    )

    expect(filename).toBe('IMG_0042.JPG')
  })

  it('fetches a fresh url when none is given', async () => {
    const key = await newKey()
    const { encrypted, iv } = await encryptBuffer(toBuffer([5]), key)
    vi.mocked(api.get).mockResolvedValue({ data: { data: { presigned_url: 'https://s3.test/fresh' } } })
    fetchMock.mockResolvedValue(okResponse(encrypted))

    await downloadOriginalBytes({ id: 4, iv_original: iv }, key)

    expect(api.get).toHaveBeenCalledWith('/photos/4', expect.anything())
    expect(fetchMock.mock.calls[0]![0]).toBe('https://s3.test/fresh')
  })

  it('refetches the url once when storage rejects an expired one, then retries', async () => {
    const key = await newKey()
    const { encrypted, iv } = await encryptBuffer(toBuffer([5, 6]), key)
    vi.mocked(api.get).mockResolvedValue({ data: { data: { presigned_url: 'https://s3.test/fresh' } } })
    fetchMock
      .mockResolvedValueOnce(new Response('expired', { status: 403 }))
      .mockResolvedValueOnce(okResponse(encrypted))

    const result = await downloadOriginalBytes(
      { id: 4, iv_original: iv, presigned_url: 'https://s3.test/stale' },
      key,
    )

    expect(new Uint8Array(result)).toEqual(new Uint8Array([5, 6]))
    expect(fetchMock.mock.calls.map((c) => c[0])).toEqual([
      'https://s3.test/stale',
      'https://s3.test/fresh',
    ])
    expect(api.get).toHaveBeenCalledTimes(1)
  })

  it('gives up with a generic error when the fresh url is rejected too', async () => {
    const key = await newKey()
    vi.mocked(api.get).mockResolvedValue({ data: { data: { presigned_url: 'https://s3.test/fresh' } } })
    fetchMock.mockResolvedValue(new Response('no', { status: 403 }))

    await expect(
      downloadOriginalBytes({ id: 4, iv_original: 'x', presigned_url: 'https://s3.test/stale' }, key),
    ).rejects.toBeInstanceOf(ExportDownloadError)
    expect(fetchMock).toHaveBeenCalledTimes(2)
    expect(api.get).toHaveBeenCalledTimes(1)
  })
})

describe('listExportPage', () => {
  it('retries on 429 and then returns the page', async () => {
    vi.useFakeTimers()
    const page = { data: [], meta: { next_after_id: null, total: 0 } }
    vi.mocked(api.get)
      .mockRejectedValueOnce({ response: { status: 429, headers: { 'retry-after': '1' } } })
      .mockResolvedValueOnce({ data: page })

    const promise = listExportPage(1, 0)
    await vi.advanceTimersByTimeAsync(1100)

    await expect(promise).resolves.toEqual(page)
    vi.useRealTimers()
  })
})

describe('manifest', () => {
  const photo: ExportPhoto = {
    id: 1,
    uuid: 'u-1',
    original_mime: 'image/jpeg',
    size_bytes: 10,
    width: 4,
    height: 3,
    iv_original: 'iv',
    name_encrypted: null,
    iv_name: null,
    exif_encrypted: null,
    iv_exif: null,
    taken_at: null,
    created_at: '2025-01-01T00:00:00.000Z',
    uploader_name: 'Ana',
    tags: [],
    encrypted_pek: 'p',
    iv_pek: 'i',
    presigned_url: 'https://s3.test/1',
  }

  it('builds entries with null EXIF and an explicit failed list', () => {
    const entry = buildManifestEntry(photo, {
      file: 'Kid/photos/2025/a.jpg',
      part: 1,
      exif: undefined,
      tags: ['beach'],
      comments: [{ author: 'Ben', created_at: '2025-01-02T00:00:00.000Z', text: 'cute' }],
    })
    const manifest = buildManifest({
      childName: 'Kid',
      exportedAt: new Date('2026-01-01T00:00:00.000Z'),
      parts: 1,
      entries: [entry],
      failed: [{ id: 7, reason: 'ExportDownloadError' }],
    })

    expect(entry.exif).toBeNull()
    expect(manifest).toMatchObject({
      version: 1,
      child: 'Kid',
      exported_at: '2026-01-01T00:00:00.000Z',
      photo_count: 1,
      failed: [{ id: 7, reason: 'ExportDownloadError' }],
    })
    expect(manifest.photos[0]).toMatchObject({
      file: 'Kid/photos/2025/a.jpg',
      mime: 'image/jpeg',
      uploader: 'Ana',
      tags: ['beach'],
    })
  })

  it('decrypts EXIF, names and tags and tolerates failures', async () => {
    const key = await newKey()
    const otherKey = await newKey()
    const exif = await encryptedText('{"gps":{"lat":1}}', key)
    const name = await encryptedText('IMG.JPG', key)
    const good = await encryptedText('beach', key)
    const bad = await encryptedText('secret', otherKey)

    const withData: ExportPhoto = {
      ...photo,
      exif_encrypted: exif.encrypted,
      iv_exif: exif.iv,
      tags: [
        { id: 1, child_id: 1, name_encrypted: good.encrypted, iv_name: good.iv },
        { id: 2, child_id: 1, name_encrypted: bad.encrypted, iv_name: bad.iv },
      ],
    }

    expect(await decryptExif(withData, key)).toEqual({ gps: { lat: 1 } })
    expect(await decryptExif(photo, key)).toBeNull()
    expect(await decryptExif(withData, otherKey)).toBeNull()
    expect(await decryptTagNames(withData, key)).toEqual(['beach'])
    expect(await decryptPhotoName({ name_encrypted: name.encrypted, iv_name: name.iv }, key)).toBe(
      'IMG.JPG',
    )
    expect(await decryptPhotoName({ name_encrypted: null, iv_name: null }, key)).toBeNull()
  })
})
