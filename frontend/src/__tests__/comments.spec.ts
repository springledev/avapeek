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
import { useCommentsStore, MAX_COMMENT_LENGTH } from '@/stores/comments'
import type { CommentListResponse, PhotoComment } from '@/types/photo'

const pek = {} as CryptoKey
const HEX_IV = '00112233445566778899aabbccddeeff'

function ok<T>(data: T): AxiosResponse<T> {
  return {
    data,
    status: 200,
    statusText: 'OK',
    headers: {},
    config: { headers: {} },
  } as unknown as AxiosResponse<T>
}

function makePhotoComment(overrides: Partial<PhotoComment> = {}): PhotoComment {
  return {
    id: 1,
    user_id: 7,
    author_name: 'Ana',
    body_encrypted: btoa('ciphertext'),
    iv_body: HEX_IV,
    created_at: '2026-09-17T10:00:00.000Z',
    ...overrides,
  }
}

function makeListResponse(items: PhotoComment[]): CommentListResponse {
  return {
    data: items,
    meta: { current_page: 1, last_page: 1, per_page: 50, total: items.length },
  }
}

function encoded(text: string): ArrayBuffer {
  return new TextEncoder().encode(text).buffer as ArrayBuffer
}

describe('comments store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(api.get).mockReset()
    vi.mocked(api.post).mockReset()
    vi.mocked(api.delete).mockReset()
    vi.mocked(encryptString).mockReset()
    vi.mocked(decryptBlob).mockReset()
  })

  it('encrypts with the PEK and posts ciphertext over the wire contract', async () => {
    vi.mocked(encryptString).mockResolvedValue({ encrypted: 'Q0lQSEVS', iv: HEX_IV })
    vi.mocked(decryptBlob).mockResolvedValue(encoded('First!'))
    vi.mocked(api.post).mockResolvedValue(ok({ data: makePhotoComment({ id: 42 }) }))

    const store = useCommentsStore()
    const posted = await store.postComment(5, 'First!', pek)

    expect(encryptString).toHaveBeenCalledWith('First!', pek)
    expect(api.post).toHaveBeenCalledWith('/photos/5/comments', {
      body_encrypted: 'Q0lQSEVS',
      iv_body: HEX_IV,
    })
    expect(posted?.id).toBe(42)
    expect(posted?.text).toBe('First!')
    expect(store.getCommentCount(5)).toBe(1)
  })

  it('decrypts fetched comments with the PEK (round trip)', async () => {
    vi.mocked(api.get).mockResolvedValue(ok(makeListResponse([makePhotoComment()])))
    vi.mocked(decryptBlob).mockResolvedValue(encoded('Hello E2E'))

    const store = useCommentsStore()
    await store.fetchComments(5, pek)

    expect(api.get).toHaveBeenCalledWith('/photos/5/comments')
    expect(decryptBlob).toHaveBeenCalledWith(expect.any(ArrayBuffer), pek, HEX_IV)
    const list = store.commentsFor(5)
    expect(list).toHaveLength(1)
    expect(list[0]?.text).toBe('Hello E2E')
    expect(list[0]?.author_name).toBe('Ana')
  })

  it('shows the comment locally before the API responds (optimistic)', async () => {
    vi.mocked(encryptString).mockResolvedValue({ encrypted: 'Q0lQSEVS', iv: HEX_IV })
    vi.mocked(decryptBlob).mockResolvedValue(encoded('hi there'))

    let resolvePost: ((value: unknown) => void) | undefined
    const postGate = new Promise((resolve) => {
      resolvePost = resolve
    })
    vi.mocked(api.post).mockReturnValue(postGate as Promise<AxiosResponse<{ data: PhotoComment }>>)

    const store = useCommentsStore()
    const inflight = store.postComment(5, 'hi there', pek)

    expect(store.commentsFor(5)).toHaveLength(1)
    expect(store.commentsFor(5)[0]?.text).toBe('hi there')
    expect(store.commentsFor(5)[0]!.id).toBeLessThan(0)
    expect(store.isPending(5)).toBe(true)

    resolvePost!(ok({ data: makePhotoComment({ id: 99 }) }))
    const posted = await inflight

    expect(posted?.id).toBe(99)
    expect(store.commentsFor(5)[0]?.id).toBe(99)
    expect(store.isPending(5)).toBe(false)
  })

  it('rolls back the optimistic comment and sets a warm error on failure', async () => {
    vi.mocked(encryptString).mockResolvedValue({ encrypted: 'Q0lQSEVS', iv: HEX_IV })
    vi.mocked(api.post).mockRejectedValue(new Error('network down'))

    const store = useCommentsStore()
    const inflight = store.postComment(5, 'doomed', pek)
    expect(store.commentsFor(5)).toHaveLength(1)

    const posted = await inflight

    expect(posted).toBeNull()
    expect(store.commentsFor(5)).toHaveLength(0)
    expect(store.error).toBe("Couldn't post that — try again")
    expect(store.isPending(5)).toBe(false)
  })

  it('enforces the 500-character limit in postComment', async () => {
    vi.mocked(encryptString).mockResolvedValue({ encrypted: 'Q0lQSEVS', iv: HEX_IV })
    vi.mocked(decryptBlob).mockResolvedValue(encoded('x'))
    vi.mocked(api.post).mockResolvedValue(ok({ data: makePhotoComment() }))

    const store = useCommentsStore()

    const tooLong = await store.postComment(5, 'a'.repeat(MAX_COMMENT_LENGTH + 1), pek)
    expect(tooLong).toBeNull()
    expect(api.post).not.toHaveBeenCalled()
    expect(store.commentsFor(5)).toHaveLength(0)
    expect(store.error).toBe('Comments are limited to 500 characters.')

    const atLimit = await store.postComment(5, 'b'.repeat(MAX_COMMENT_LENGTH), pek)
    expect(atLimit).not.toBeNull()
    expect(api.post).toHaveBeenCalledTimes(1)
    expect(store.commentsFor(5)).toHaveLength(1)
  })

  it('decrypts fetched comments four at a time', async () => {
    const items = Array.from({ length: 10 }, (_, i) => makePhotoComment({ id: i + 1 }))
    vi.mocked(api.get).mockResolvedValue(ok(makeListResponse(items)))

    let inFlight = 0
    let peak = 0
    vi.mocked(decryptBlob).mockImplementation(async () => {
      inFlight += 1
      peak = Math.max(peak, inFlight)
      await new Promise((resolve) => setTimeout(resolve, 5))
      inFlight -= 1
      return encoded('ok')
    })

    const store = useCommentsStore()
    await store.fetchComments(5, pek)

    expect(decryptBlob).toHaveBeenCalledTimes(10)
    expect(peak).toBe(4)
    expect(store.commentsFor(5)).toHaveLength(10)
  })

  it('removes a comment locally after a successful delete', async () => {
    vi.mocked(api.get).mockResolvedValue(ok(makeListResponse([makePhotoComment({ id: 11 })])))
    vi.mocked(decryptBlob).mockResolvedValue(encoded('bye'))
    vi.mocked(api.delete).mockResolvedValue(ok(undefined))

    const store = useCommentsStore()
    await store.fetchComments(5, pek)
    expect(store.getCommentCount(5)).toBe(1)

    const deleted = await store.deleteComment(5, 11)

    expect(deleted).toBe(true)
    expect(api.delete).toHaveBeenCalledWith('/photos/5/comments/11')
    expect(store.commentsFor(5)).toHaveLength(0)
    expect(store.getCommentCount(5)).toBe(0)
  })

  it('keeps the comment and sets an error when delete fails', async () => {
    vi.mocked(api.get).mockResolvedValue(ok(makeListResponse([makePhotoComment({ id: 11 })])))
    vi.mocked(decryptBlob).mockResolvedValue(encoded('stay'))
    vi.mocked(api.delete).mockRejectedValue(new Error('boom'))

    const store = useCommentsStore()
    await store.fetchComments(5, pek)

    const deleted = await store.deleteComment(5, 11)

    expect(deleted).toBe(false)
    expect(store.commentsFor(5)).toHaveLength(1)
    expect(store.error).toBe("Couldn't delete that — try again")
  })

  it('reports zero for uncached photos', () => {
    const store = useCommentsStore()
    expect(store.getCommentCount(999)).toBe(0)
    expect(store.commentsFor(999)).toEqual([])
  })

  it('merges in-flight optimistic comments into a completed fetch instead of clobbering them', async () => {
    vi.mocked(encryptString).mockResolvedValue({ encrypted: 'Q0lQSEVS', iv: HEX_IV })
    vi.mocked(decryptBlob).mockResolvedValue(encoded('server-side'))

    let resolveGet: ((value: AxiosResponse<CommentListResponse>) => void) | undefined
    const getGate = new Promise<AxiosResponse<CommentListResponse>>((resolve) => {
      resolveGet = resolve
    })
    vi.mocked(api.get).mockReturnValue(getGate)

    let resolvePost: ((value: unknown) => void) | undefined
    const postGate = new Promise((resolve) => {
      resolvePost = resolve
    })
    vi.mocked(api.post).mockReturnValue(postGate as Promise<AxiosResponse<{ data: PhotoComment }>>)

    const store = useCommentsStore()
    const inflightFetch = store.fetchComments(5, pek)

    // Optimistic comment added while the GET is still in flight.
    const inflightPost = store.postComment(5, 'brand new', pek)
    expect(store.commentsFor(5)).toHaveLength(1)

    // Server answers (deliberately out of order, and without the pending one).
    resolveGet!(
      ok(
        makeListResponse([
          makePhotoComment({ id: 12, created_at: '2026-09-17T10:00:00.000Z' }),
          makePhotoComment({ id: 10, created_at: '2026-09-17T09:00:00.000Z' }),
        ]),
      ),
    )
    await inflightFetch

    const ids = store.commentsFor(5).map((c) => c.id)
    expect(ids.slice(0, 2)).toEqual([10, 12])
    expect(ids[2]).toBeDefined()
    expect(ids[2]!).toBeLessThan(0)
    expect(store.commentsFor(5)[2]?.text).toBe('brand new')

    // When the POST lands, the surviving pending entry reconciles in place.
    resolvePost!(ok({ data: makePhotoComment({ id: 11, created_at: '2026-09-17T10:30:00.000Z' }) }))
    await inflightPost
    expect(store.commentsFor(5).map((c) => c.id)).toEqual([10, 12, 11])
  })

  it('completes a real WebCrypto postComment -> fetchComments round trip (unwrapPEK key-usage regression)', async () => {
    // jsdom does not expose SubtleCrypto; fall back to Node's WebCrypto.
    if (!globalThis.crypto.subtle) {
      const { webcrypto } = await import('node:crypto')
      Object.defineProperty(globalThis, 'crypto', { value: webcrypto, configurable: true })
    }

    // Use the REAL @/crypto/encrypt for this test only: drop the file-level
    // mock and re-evaluate the store so it binds the unmocked helpers.
    vi.doUnmock('@/crypto/encrypt')
    vi.resetModules()
    setActivePinia(createPinia())

    const { encryptString: realEncryptString } = await import('@/crypto/encrypt')
    const { generateDEK, generatePEK, wrapPEKWithDEK, unwrapPEK } = await import('@/crypto/keychain')
    const { api: freshApi } = await import('@/services/api')
    const { useCommentsStore: useFreshCommentsStore } = await import('@/stores/comments')

    // Mirror the production key chain: DEK wraps the PEK, unwrapPEK rebuilds it.
    const dek = await generateDEK()
    const pekMaterial = await generatePEK()
    const wrapped = await wrapPEKWithDEK(pekMaterial, dek)
    const unwrappedPek = await unwrapPEK(wrapped.encrypted_pek, wrapped.iv_pek, dek)

    // The fix: unwrapPEK must import with BOTH usages (matches generatePEK).
    expect(unwrappedPek.usages).toContain('encrypt')
    expect(unwrappedPek.usages).toContain('decrypt')

    // Regression guard mirroring unwrapPEK's importKey call: a decrypt-only
    // key cannot encrypt, so the mocked-crypto suites could never catch it.
    const rawPek = await crypto.subtle.exportKey('raw', pekMaterial)
    const decryptOnlyPek = await crypto.subtle.importKey(
      'raw',
      rawPek,
      { name: 'AES-GCM', length: 256 },
      false,
      ['decrypt'],
    )
    await expect(realEncryptString('nope', decryptOnlyPek)).rejects.toThrow(
      /InvalidAccessError|not valid for the provided key/,
    )
    const fullUsagePek = await crypto.subtle.importKey(
      'raw',
      rawPek,
      { name: 'AES-GCM', length: 256 },
      false,
      ['encrypt', 'decrypt'],
    )
    await expect(realEncryptString('yes', fullUsagePek)).resolves.toEqual({
      encrypted: expect.any(String),
      iv: expect.any(String),
    })

    // Full store round trip with real AES-GCM ciphertext on the wire.
    const text = 'Hello for real éçõ ✨'
    let stored: PhotoComment | undefined
    vi.mocked(freshApi.post).mockImplementation(async (_url: string, data: unknown) => {
      const payload = data as { body_encrypted: string; iv_body: string }
      stored = makePhotoComment({ id: 7, body_encrypted: payload.body_encrypted, iv_body: payload.iv_body })
      return ok({ data: stored })
    })
    vi.mocked(freshApi.get).mockImplementation(async () => ok(makeListResponse([stored!])))

    const store = useFreshCommentsStore()
    const posted = await store.postComment(5, text, unwrappedPek)
    expect(posted?.id).toBe(7)
    expect(posted?.text).toBe(text)

    await store.fetchComments(5, unwrappedPek)
    const list = store.commentsFor(5)
    expect(list).toHaveLength(1)
    expect(list[0]?.text).toBe(text)
  })
})
