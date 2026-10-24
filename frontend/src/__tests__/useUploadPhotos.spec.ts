import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

vi.mock('@/services/api', () => ({
  api: {
    get: vi.fn<(url: string) => Promise<unknown>>(),
    post: vi.fn<(url: string, data?: unknown, config?: unknown) => Promise<unknown>>(),
  },
  default: {
    setAuthToken: vi.fn<(token: string) => void>(),
    clearAuthToken: vi.fn<() => void>(),
  },
}))

vi.mock('@/lib/auth-storage', () => ({
  getSessionItem: vi.fn<(key: string) => string | null>(() => null),
  setSessionItem: vi.fn<(key: string, value: string) => void>(),
  removeSessionItem: vi.fn<(key: string) => void>(),
  clearSession: vi.fn<() => void>(),
  removePendingInviteToken: vi.fn<() => void>(),
  setStaySignedIn: vi.fn<(value: boolean) => void>(),
}))

vi.mock('@/crypto/keychain', () => ({
  generatePEK: vi.fn<() => Promise<CryptoKey>>(async () => ({ type: 'pek' }) as unknown as CryptoKey),
  wrapPEKWithDEK: vi.fn<(pek: CryptoKey, dek: CryptoKey) => Promise<unknown>>(async () => ({
    encrypted_pek: 'wrapped',
    iv_pek: 'iv',
  })),
}))

vi.mock('@/crypto/thumbnails', () => ({
  generateThumbnail: vi.fn<(b: ImageBitmap) => Promise<{ thumb: Blob }>>(async () => ({
    thumb: new Blob(['t']),
  })),
  generateLQIP: vi.fn<(b: ImageBitmap) => Promise<Blob>>(async () => new Blob(['l'])),
}))

vi.mock('@/crypto/encrypt', () => ({
  encryptBuffer: vi.fn<(buf: ArrayBuffer, key: CryptoKey) => Promise<unknown>>(async () => ({
    encrypted: new ArrayBuffer(4),
    iv: 'iv-b64',
  })),
}))

vi.mock('@/crypto/heic', () => ({
  isHeic: vi.fn<(f: File) => boolean>(() => false),
  decodeHeic: vi.fn<(f: File) => Promise<ImageBitmap>>(),
}))

vi.mock('@/lib/exif/takenAt', () => ({
  extractTakenAt: vi.fn<(f: File) => Promise<string | null>>(async () => null),
}))

import { api } from '@/services/api'
import { useChildStore } from '@/stores/child'
import { useFeedStore } from '@/stores/feed'
import { useUploadPhotos } from '@/composables/useUploadPhotos'

const closeSpy = vi.fn<() => void>()

function file(name: string): File {
  return new File(['pixels'], name, { type: 'image/jpeg' })
}

describe('useUploadPhotos', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(api.post).mockReset()
    closeSpy.mockReset()
    vi.stubGlobal(
      'createImageBitmap',
      vi.fn<() => Promise<ImageBitmap>>(async () => ({
        width: 10,
        height: 20,
        close: closeSpy,
      }) as unknown as ImageBitmap),
    )
    vi.spyOn(console, 'error').mockImplementation(() => {})

    const childStore = useChildStore()
    vi.spyOn(childStore, 'ensureDEK').mockResolvedValue({ type: 'dek' } as unknown as CryptoKey)
    vi.spyOn(childStore, 'fetchChildren').mockResolvedValue()
    vi.spyOn(useFeedStore(), 'fetchPhotos').mockResolvedValue()
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
  })

  it('uploads every file with only encrypted fields and reports success', async () => {
    vi.mocked(api.post).mockResolvedValue({ data: {} })
    const { upload, errors, isUploading } = useUploadPhotos()

    const result = await upload([file('a.jpg'), file('b.jpg')], [5])

    expect(result).toEqual({ succeeded: 2, failed: 0 })
    expect(errors.value).toEqual([])
    expect(isUploading.value).toBe(false)
    expect(api.post).toHaveBeenCalledTimes(2)
    const [url, body] = vi.mocked(api.post).mock.calls[0]! as [string, FormData]
    expect(url).toBe('/photos/upload')
    expect(JSON.parse(body.get('children') as string)).toEqual({
      5: { encrypted_pek: 'wrapped', iv_pek: 'iv' },
    })
    expect(body.get('iv')).toBe('iv-b64')
    expect(closeSpy).toHaveBeenCalledTimes(2)
    expect(useFeedStore().fetchPhotos).toHaveBeenCalled()
  })

  it('records a per-file failure and keeps uploading the rest', async () => {
    vi.mocked(api.post)
      .mockRejectedValueOnce(new Error('boom'))
      .mockResolvedValueOnce({ data: {} })
    const { upload, errors } = useUploadPhotos()
    const bad = file('bad.jpg')

    const result = await upload([bad, file('good.jpg')], [5])

    expect(result).toEqual({ succeeded: 1, failed: 1 })
    expect(errors.value).toHaveLength(1)
    expect(errors.value[0]!.file).toBe(bad)
    expect(closeSpy).toHaveBeenCalledTimes(2)
  })

  it('does nothing without files or without a child', async () => {
    const { upload } = useUploadPhotos()

    expect(await upload([], [5])).toEqual({ succeeded: 0, failed: 0 })
    expect(await upload([file('a.jpg')], [])).toEqual({ succeeded: 0, failed: 0 })
    expect(api.post).not.toHaveBeenCalled()
  })
})
