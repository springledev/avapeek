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
  encryptString: vi.fn<(text: string, key: CryptoKey) => Promise<unknown>>(async (text: string) => ({
    encrypted: `enc(${text})`,
    iv: 'iv-name',
  })),
}))

vi.mock('@/crypto/heic', () => ({
  isHeic: vi.fn<(f: File) => boolean>(() => false),
  decodeHeic: vi.fn<(f: File) => Promise<ImageBitmap>>(),
}))

vi.mock('@/lib/exif/takenAt', () => ({
  extractTakenAt: vi.fn<(f: File) => Promise<string | null>>(async () => null),
}))

vi.mock('@/services/storage', () => ({
  uploadEncryptedBlobs: vi.fn<(...args: unknown[]) => Promise<string | null>>(async () => null),
  completeUpload: vi.fn<(uuid: string, metadata: FormData) => Promise<void>>(async () => {}),
}))

import { api } from '@/services/api'
import { completeUpload, uploadEncryptedBlobs } from '@/services/storage'
import { useChildStore } from '@/stores/child'
import { useFeedStore } from '@/stores/feed'
import { useTagsStore } from '@/stores/tags'
import { useUploadPhotos } from '@/composables/useUploadPhotos'

const closeSpy = vi.fn<() => void>()

function file(name: string): File {
  return new File(['pixels'], name, { type: 'image/jpeg' })
}

describe('useUploadPhotos', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(api.post).mockReset()
    vi.mocked(uploadEncryptedBlobs).mockReset().mockResolvedValue(null)
    vi.mocked(completeUpload).mockReset().mockResolvedValue()
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

  it('uses the direct path: PUT blobs, then complete with metadata only', async () => {
    vi.mocked(uploadEncryptedBlobs).mockResolvedValue('intent-uuid')
    const { upload } = useUploadPhotos()

    const result = await upload([file('a.jpg')], [5])

    expect(result).toEqual({ succeeded: 1, failed: 0 })
    expect(api.post).not.toHaveBeenCalled()
    const [mime, original, thumbnail] = vi.mocked(uploadEncryptedBlobs).mock.calls[0]!
    expect(mime).toBe('image/jpeg')
    expect(original).toBeInstanceOf(Blob)
    expect(thumbnail).toBeInstanceOf(Blob)
    const [uuid, body] = vi.mocked(completeUpload).mock.calls[0]!
    expect(uuid).toBe('intent-uuid')
    expect(body.has('file')).toBe(false)
    expect(body.has('thumbnail')).toBe(false)
    expect(body.get('iv')).toBe('iv-b64')
    expect(body.get('iv_thumbnail')).toBe('iv-b64')
    expect(body.get('name_encrypted')).toBe('enc(a.jpg)')
    expect(body.get('iv_name')).toBe('iv-name')
    expect(JSON.stringify([...body.entries()])).not.toContain('"a.jpg"')
    expect(JSON.parse(body.get('children') as string)).toEqual({
      5: { encrypted_pek: 'wrapped', iv_pek: 'iv' },
    })
  })

  it('surfaces a complete failure as a per-file error without re-uploading', async () => {
    vi.mocked(uploadEncryptedBlobs).mockResolvedValue('intent-uuid')
    vi.mocked(completeUpload).mockRejectedValue(new Error('422'))
    const { upload, errors } = useUploadPhotos()

    const result = await upload([file('a.jpg')], [5])

    expect(result).toEqual({ succeeded: 0, failed: 1 })
    expect(errors.value).toHaveLength(1)
    expect(uploadEncryptedBlobs).toHaveBeenCalledTimes(1)
  })

  it('falls back to the proxy upload when direct uploads are disabled', async () => {
    vi.mocked(api.post).mockResolvedValue({ data: {} })
    const { upload } = useUploadPhotos()

    await upload([file('a.jpg')], [5])

    expect(completeUpload).not.toHaveBeenCalled()
    const [url, body] = vi.mocked(api.post).mock.calls[0]! as [string, FormData]
    expect(url).toBe('/photos/upload')
    expect(body.get('file')).toBeInstanceOf(Blob)
    expect(body.get('thumbnail')).toBeInstanceOf(Blob)
    expect(body.get('name_encrypted')).toBe('enc(a.jpg)')
    expect(body.get('iv_name')).toBe('iv-name')
    expect((body.get('file') as File).name).toBe('original.enc')
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

  it('sends tag_ids (one per child) on the proxy upload and never a plaintext tag name', async () => {
    vi.mocked(api.post).mockResolvedValue({ data: {} })
    const idsForUpload = vi.spyOn(useTagsStore(), 'idsForUpload').mockResolvedValue([11, 12])
    const { upload } = useUploadPhotos()

    await upload([file('a.jpg')], [5, 6], ['Spain 2026'])

    expect(idsForUpload).toHaveBeenCalledWith([5, 6], ['Spain 2026'])
    const [, body] = vi.mocked(api.post).mock.calls[0]! as [string, FormData]
    expect(body.getAll('tag_ids[]')).toEqual(['11', '12'])
    expect(body.has('tags[]')).toBe(false)
    expect([...body.values()].some((v) => typeof v === 'string' && v.includes('Spain'))).toBe(false)
  })

  it('sends tag_ids on the direct complete call', async () => {
    vi.mocked(uploadEncryptedBlobs).mockResolvedValue('intent-uuid')
    vi.spyOn(useTagsStore(), 'idsForUpload').mockResolvedValue([7])
    const { upload } = useUploadPhotos()

    await upload([file('a.jpg')], [5], ['beach'])

    const [, body] = vi.mocked(completeUpload).mock.calls[0]!
    expect(body.getAll('tag_ids[]')).toEqual(['7'])
    expect(body.has('tags[]')).toBe(false)
  })

  it('sends no tag_ids when no tags were chosen', async () => {
    vi.mocked(api.post).mockResolvedValue({ data: {} })
    const { upload } = useUploadPhotos()

    await upload([file('a.jpg')], [5])

    const [, body] = vi.mocked(api.post).mock.calls[0]! as [string, FormData]
    expect(body.has('tag_ids[]')).toBe(false)
  })
})
