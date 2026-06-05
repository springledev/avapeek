import { api } from '@/services/api'
import { decryptBlob, blobToObjectURL } from '@/crypto/encrypt'

export async function fetchPresignedThumbnail(
  childId: number,
  photoId: number,
  size: 'sm' | 'md' | 'lg',
): Promise<string> {
  const response = await api.get(
    `/children/${childId}/photos/${photoId}/thumbnail/${size}`,
  )
  return response.data.url as string
}

export async function fetchPresignedOriginal(
  childId: number,
  photoId: number,
): Promise<string> {
  const response = await api.get(`/children/${childId}/photos/${photoId}`)
  return response.data.url as string
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

export async function fetchThumbnail(
  childId: number,
  photoId: number,
  size: 'sm' | 'md' | 'lg',
  dek: CryptoKey,
  iv: string,
): Promise<string> {
  const presignedUrl = await fetchPresignedThumbnail(childId, photoId, size)
  const decrypted = await fetchAndDecrypt(presignedUrl, dek, iv)
  return blobToObjectURL(decrypted, 'image/webp')
}

export async function fetchOriginal(
  childId: number,
  photoId: number,
  dek: CryptoKey,
  iv: string,
): Promise<string> {
  const presignedUrl = await fetchPresignedOriginal(childId, photoId)
  const decrypted = await fetchAndDecrypt(presignedUrl, dek, iv)
  return blobToObjectURL(decrypted, 'image/webp')
}
