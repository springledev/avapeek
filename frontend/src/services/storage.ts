import { api } from '@/services/api'
import { decryptBlob, blobToObjectURL, revokeObjectURL } from '@/crypto/encrypt'

export async function fetchPresignedThumbnail(
  childId: number,
  photoId: number,
  size: 'sm' | 'md' | 'lg',
): Promise<string> {
  const response = await api.get(
    `/children/${childId}/photos/${photoId}/thumbnail/${size}`,
  )
  return response.data.data.presigned_url as string
}

export async function fetchPresignedOriginal(
  childId: number,
  photoId: number,
): Promise<string> {
  const response = await api.get(`/children/${childId}/photos/${photoId}`)
  return response.data.data.presigned_url as string
}

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
  childId: number,
  photoId: number,
  size: 'sm' | 'md' | 'lg',
  dek: CryptoKey,
  iv: string,
  componentKey: string,
): Promise<string> {
  const presignedUrl = await fetchPresignedThumbnail(childId, photoId, size)
  const decrypted = await fetchAndDecrypt(presignedUrl, dek, iv)
  const objectUrl = blobToObjectURL(decrypted, 'image/webp')
  trackObjectUrl(componentKey, objectUrl)
  return objectUrl
}

export async function fetchOriginal(
  childId: number,
  photoId: number,
  dek: CryptoKey,
  iv: string,
): Promise<string> {
  const presignedUrl = await fetchPresignedOriginal(childId, photoId)
  const decrypted = await fetchAndDecrypt(presignedUrl, dek, iv)
  const objectUrl = blobToObjectURL(decrypted, 'image/webp')
  return objectUrl
}
