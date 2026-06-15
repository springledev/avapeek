import { api } from '@/services/api'
import { decryptBlob, blobToObjectURL, revokeObjectURL } from '@/crypto/encrypt'
import { decodeHeicBuffer } from '@/crypto/heic'

export async function fetchPresignedThumbnail(
  childId: number,
  photoId: number,
): Promise<string> {
  const response = await api.get(
    `/children/${childId}/photos/${photoId}/thumbnail`,
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
  childId: number,
  photoId: number,
  dek: CryptoKey,
  iv: string,
  componentKey: string,
): Promise<string> {
  const presignedUrl = await fetchPresignedThumbnail(childId, photoId)
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
  mimeType: string,
): Promise<string> {
  const presignedUrl = await fetchPresignedOriginal(childId, photoId)
  const decrypted = await fetchAndDecrypt(presignedUrl, dek, iv)
  const objectUrl = await decryptedToDisplayableUrl(decrypted, mimeType)
  trackObjectUrl('photoviewer', objectUrl)
  return objectUrl
}
