import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

vi.mock('@/services/api', () => ({
  api: {
    get: vi.fn<(url: string) => Promise<unknown>>(),
    post: vi.fn<(url: string, data?: unknown, config?: unknown) => Promise<unknown>>(),
  },
}))
vi.mock('@/lib/cache/decrypted-cache', () => ({}))
vi.mock('@/crypto/encrypt', () => ({}))
vi.mock('@/crypto/keychain', () => ({}))
vi.mock('@/crypto/heic', () => ({}))
vi.mock('@/stores/child', () => ({}))

import { api } from '@/services/api'
import {
  StoragePutError,
  completeUpload,
  isDirectUploadDisabled,
  putEncryptedBlob,
  requestUploadIntent,
  resetDirectUploadState,
  uploadEncryptedBlobs,
} from '@/services/storage'

type Responder = (xhr: FakeXhr) => void

class FakeXhr {
  static instances: FakeXhr[] = []
  static responders: Responder[] = []
  method = ''
  url = ''
  headers: Record<string, string> = {}
  body: unknown = null
  status = 0
  upload: { onprogress: ((e: { loaded: number; total: number }) => void) | null } = {
    onprogress: null,
  }
  onload: (() => void) | null = null
  onerror: (() => void) | null = null
  onabort: (() => void) | null = null

  constructor() {
    FakeXhr.instances.push(this)
  }
  open(method: string, url: string) {
    this.method = method
    this.url = url
  }
  setRequestHeader(name: string, value: string) {
    this.headers[name] = value
  }
  send(body: unknown) {
    this.body = body
    const responder = FakeXhr.responders.shift() ?? ((x: FakeXhr) => x.respond(200))
    queueMicrotask(() => responder(this))
  }
  respond(status: number) {
    this.status = status
    this.onload?.()
  }
  fail() {
    this.onerror?.()
  }
}

function intent(uuid: string, expiresInMs = 15 * 60_000) {
  return {
    data: {
      data: {
        uuid,
        original: {
          url: `https://s3.test/${uuid}.jpg.enc`,
          headers: {
            Host: 's3.test',
            'Content-Type': 'application/octet-stream',
            'Cache-Control': 'public, max-age=31536000, immutable',
          },
        },
        thumbnail: {
          url: `https://s3.test/${uuid}_thumb.webp.enc`,
          headers: { 'Content-Type': 'application/octet-stream' },
        },
        expires_at: new Date(Date.now() + expiresInMs).toISOString(),
      },
    },
  }
}

const blob = (n: number) => new Blob([new Uint8Array(n)])

describe('direct upload transport', () => {
  beforeEach(() => {
    FakeXhr.instances = []
    FakeXhr.responders = []
    vi.stubGlobal('XMLHttpRequest', FakeXhr)
    vi.mocked(api.post).mockReset()
    resetDirectUploadState()
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  describe('putEncryptedBlob', () => {
    it('PUTs the blob, never sends Authorization or Host, and reports progress', async () => {
      const progress = vi.fn<(loaded: number, total: number) => void>()
      FakeXhr.responders.push((x) => {
        x.upload.onprogress?.({ loaded: 5, total: 10 })
        x.respond(200)
      })

      await putEncryptedBlob(
        'https://s3.test/a',
        {
          Host: 's3.test',
          Authorization: 'Bearer nope',
          'Content-Type': 'application/octet-stream',
          'Cache-Control': 'immutable',
        },
        blob(10),
        progress,
      )

      const xhr = FakeXhr.instances[0]!
      expect(xhr.method).toBe('PUT')
      expect(xhr.url).toBe('https://s3.test/a')
      expect(Object.keys(xhr.headers).map((h) => h.toLowerCase())).toEqual([
        'content-type',
        'cache-control',
      ])
      expect(progress).toHaveBeenCalledWith(5, 10)
    })

    it('rejects with the HTTP status', async () => {
      FakeXhr.responders.push((x) => x.respond(403))
      await expect(putEncryptedBlob('https://s3.test/a', {}, blob(1))).rejects.toMatchObject({
        status: 403,
      })
    })

    it('rejects with status 0 on a network error', async () => {
      FakeXhr.responders.push((x) => x.fail())
      await expect(putEncryptedBlob('https://s3.test/a', {}, blob(1))).rejects.toMatchObject({
        status: 0,
      })
    })
  })

  describe('requestUploadIntent', () => {
    it('returns null on 404 and remembers that direct uploads are disabled', async () => {
      vi.mocked(api.post).mockRejectedValueOnce({ response: { status: 404 } })

      const result = await requestUploadIntent({
        original_bytes: 1,
        thumbnail_bytes: 1,
        original_mime: 'image/jpeg',
      })

      expect(result).toBeNull()
      expect(isDirectUploadDisabled()).toBe(true)
    })

    it('does not treat other errors as a fallback signal', async () => {
      vi.mocked(api.post).mockRejectedValueOnce({ response: { status: 500 } })

      await expect(
        requestUploadIntent({ original_bytes: 1, thumbnail_bytes: 1, original_mime: 'image/jpeg' }),
      ).rejects.toMatchObject({ response: { status: 500 } })
      expect(isDirectUploadDisabled()).toBe(false)
    })
  })

  describe('uploadEncryptedBlobs', () => {
    it('requests an intent with ciphertext sizes, PUTs both blobs and returns the uuid', async () => {
      vi.mocked(api.post).mockResolvedValueOnce(intent('u1'))
      const onProgress = vi.fn<(fraction: number) => void>()

      const uuid = await uploadEncryptedBlobs('image/jpeg', blob(100), blob(20), {
        backoffMs: 0,
        onProgress,
      })

      expect(uuid).toBe('u1')
      expect(api.post).toHaveBeenCalledWith('/photos/upload-intent', {
        original_bytes: 100,
        thumbnail_bytes: 20,
        original_mime: 'image/jpeg',
      })
      expect(FakeXhr.instances.map((x) => x.url).sort()).toEqual([
        'https://s3.test/u1.jpg.enc',
        'https://s3.test/u1_thumb.webp.enc',
      ])
      expect(onProgress).toHaveBeenLastCalledWith(1)
    })

    it('retries a failing PUT up to 3 times and then succeeds', async () => {
      vi.mocked(api.post).mockResolvedValueOnce(intent('u1'))
      // original: fail, fail, ok ; thumbnail: ok.
      let originalAttempts = 0
      const original = vi.fn<(x: FakeXhr) => void>((x) => {
        originalAttempts++
        if (originalAttempts < 3) x.respond(503)
        else x.respond(200)
      })
      const origSend = FakeXhr.prototype.send
      FakeXhr.prototype.send = function (this: FakeXhr, body: unknown) {
        this.body = body
        queueMicrotask(() => (this.url.includes('_thumb') ? this.respond(200) : original(this)))
      }

      try {
        const uuid = await uploadEncryptedBlobs('image/jpeg', blob(10), blob(5), { backoffMs: 0 })
        expect(uuid).toBe('u1')
        expect(originalAttempts).toBe(3)
      } finally {
        FakeXhr.prototype.send = origSend
      }
    })

    it('gives up after 3 failed attempts', async () => {
      vi.mocked(api.post).mockResolvedValueOnce(intent('u1'))
      const origSend = FakeXhr.prototype.send
      let attempts = 0
      FakeXhr.prototype.send = function (this: FakeXhr) {
        queueMicrotask(() => {
          if (this.url.includes('_thumb')) {
            this.respond(200)
          } else {
            attempts++
            this.respond(500)
          }
        })
      }

      try {
        await expect(
          uploadEncryptedBlobs('image/jpeg', blob(10), blob(5), { backoffMs: 0 }),
        ).rejects.toBeInstanceOf(StoragePutError)
        expect(attempts).toBe(3)
      } finally {
        FakeXhr.prototype.send = origSend
      }
    })

    it('does not retry a 400', async () => {
      vi.mocked(api.post).mockResolvedValueOnce(intent('u1'))
      FakeXhr.responders.push((x) => x.respond(400), (x) => x.respond(200))

      await expect(
        uploadEncryptedBlobs('image/jpeg', blob(10), blob(5), { backoffMs: 0 }),
      ).rejects.toMatchObject({ status: 400 })
      expect(FakeXhr.instances).toHaveLength(2)
    })

    it('requests a new intent when the PUT answers 403 (expired) and re-PUTs the same blobs', async () => {
      vi.mocked(api.post).mockResolvedValueOnce(intent('old')).mockResolvedValueOnce(intent('new'))
      const origSend = FakeXhr.prototype.send
      FakeXhr.prototype.send = function (this: FakeXhr, body: unknown) {
        this.body = body
        queueMicrotask(() => this.respond(this.url.includes('/old') ? 403 : 200))
      }
      const original = blob(10)

      try {
        const uuid = await uploadEncryptedBlobs('image/jpeg', original, blob(5), { backoffMs: 0 })
        expect(uuid).toBe('new')
        expect(api.post).toHaveBeenCalledTimes(2)
        const newOriginalPut = FakeXhr.instances.find((x) => x.url === 'https://s3.test/new.jpg.enc')
        expect(newOriginalPut?.body).toBe(original)
      } finally {
        FakeXhr.prototype.send = origSend
      }
    })

    it('requests a new intent when expires_at has already passed, without PUTting to the stale one', async () => {
      vi.mocked(api.post)
        .mockResolvedValueOnce(intent('stale', -1000))
        .mockResolvedValueOnce(intent('fresh'))

      const uuid = await uploadEncryptedBlobs('image/jpeg', blob(10), blob(5), { backoffMs: 0 })

      expect(uuid).toBe('fresh')
      expect(FakeXhr.instances.every((x) => !x.url.includes('stale'))).toBe(true)
    })

    it('returns null on 404 and skips the intent call for later files', async () => {
      vi.mocked(api.post).mockRejectedValueOnce({ response: { status: 404 } })

      expect(await uploadEncryptedBlobs('image/jpeg', blob(1), blob(1))).toBeNull()
      expect(await uploadEncryptedBlobs('image/jpeg', blob(1), blob(1))).toBeNull()

      expect(api.post).toHaveBeenCalledTimes(1)
      expect(FakeXhr.instances).toHaveLength(0)
    })
  })

  describe('completeUpload', () => {
    it('posts the metadata to the intent complete route via the api client', async () => {
      vi.mocked(api.post).mockResolvedValueOnce({ data: {} })
      const form = new FormData()

      await completeUpload('u1', form)

      expect(api.post).toHaveBeenCalledWith('/photos/u1/complete', form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
    })
  })
})
