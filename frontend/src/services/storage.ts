import { api } from '@/services/api'
import { decryptBlob, blobToObjectURL, revokeObjectURL } from '@/crypto/encrypt'
import { unwrapPEK } from '@/crypto/keychain'
import { useChildStore } from '@/stores/child'
import { decodeHeicBuffer } from '@/crypto/heic'
import type { Photo, PhotoChild } from '@/types/photo'
import { getCachedOriginal, setCachedOriginal, getCachedThumbnail, setCachedThumbnail } from '@/services/imageCache'

export async function fetchEncryptedBlob(presignedUrl: string): Promise<ArrayBuffer> {
  const response = await fetch(presignedUrl)
  return await response.arrayBuffer()
}

export async function fetchAndDecrypt(
  presignedUrl: string,
  dek: CryptoKey,
  iv: string,
): Promise<ArrayBuffer> {
  const encrypted = await fetchEncryptedBlob(presignedUrl)
  return await decryptBlob(encrypted, dek, iv)
}

export async function getPEKForPhoto(children: PhotoChild[]): Promise<CryptoKey | null> {
  const childStore = useChildStore()
  for (const child of children) {
    const dek = await childStore.tryGetChildDEK(child.id)
    if (dek) {
      return unwrapPEK(child.encrypted_pek, child.iv_pek, dek)
    }
  }
  return null
}

async function decryptedToDisplayableUrl(
  decrypted: ArrayBuffer,
  mimeType: string,
): Promise<string> {
  if (mimeType === 'image/heic' || mimeType === 'image/heif') {
    const bitmap = await decodeHeicBuffer(decrypted)
    const canvas = new OffscreenCanvas(bitmap.width, bitmap.height)
    const ctx = canvas.getContext('2d')
    if (!ctx) throw new Error('Could not get OffscreenCanvas context')
    ctx.drawImage(bitmap, 0, 0)
    const blob = await canvas.convertToBlob({ type: 'image/webp', quality: 0.92 })
    bitmap.close()
    return blobToObjectURL(await blob.arrayBuffer(), 'image/webp')
  }
  return blobToObjectURL(decrypted, mimeType)
}

const trackedUrls = new Map<string, string[]>()

function trackObjectUrl(key: string, url: string) {
  const urls = trackedUrls.get(key) || []
  urls.push(url)
  trackedUrls.set(key, urls)
}

export function cleanupComponent(key: string) {
  const urls = trackedUrls.get(key)
  if (urls) {
    for (const url of urls) {
      revokeObjectURL(url)
    }
    trackedUrls.delete(key)
  }
}

export async function fetchThumbnail(
  photoId: number,
  pek: CryptoKey,
  iv: string,
  componentKey: string,
  presignedUrl?: string,
): Promise<string> {
  const cached = getCachedThumbnail(photoId)
  if (cached) return cached

  let url = presignedUrl
  if (!url) {
    const response = await api.get(`/photos/${photoId}/thumbnail`)
    url = response.data.data.presigned_url as string
  }
  const decrypted = await fetchAndDecrypt(url, pek, iv)
  const objectUrl = blobToObjectURL(decrypted, 'image/webp')
  trackObjectUrl(componentKey, objectUrl)
  setCachedThumbnail(photoId, objectUrl)
  return objectUrl
}

export async function fetchOriginal(
  photoId: number,
  pek: CryptoKey,
  iv: string,
  mimeType: string,
): Promise<string> {
  const cached = getCachedOriginal(photoId)
  if (cached) return cached
  const response = await api.get(`/photos/${photoId}`)
  const presignedUrl = response.data.data.presigned_url as string
  const decrypted = await fetchAndDecrypt(presignedUrl, pek, iv)
  const objectUrl = await decryptedToDisplayableUrl(decrypted, mimeType)
  setCachedOriginal(photoId, objectUrl)
  return objectUrl
}

export async function preloadOriginal(photo: Photo): Promise<void> {
  if (getCachedOriginal(photo.id)) return
  try {
    const pek = await getPEKForPhoto(photo.children)
    if (!pek) return
    await fetchOriginal(photo.id, pek, photo.iv_original, photo.original_mime)
  } catch {
    // ponytail: best-effort preload
  }
}
