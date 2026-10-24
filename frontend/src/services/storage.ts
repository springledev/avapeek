import { getThumbnail, getOriginal, removeUrlFromCache } from '@/lib/cache/decrypted-cache'
import { api } from '@/services/api'
import { decryptBlob, revokeObjectURL } from '@/crypto/encrypt'
import { unwrapPEK } from '@/crypto/keychain'
import { useChildStore } from '@/stores/child'
import { decodeHeicBuffer } from '@/crypto/heic'
import type { Photo, PhotoChild } from '@/types/photo'

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

async function decryptedToBlob(decrypted: ArrayBuffer, mimeType: string): Promise<Blob> {
  if (mimeType === 'image/heic' || mimeType === 'image/heif') {
    const bitmap = await decodeHeicBuffer(decrypted)
    const canvas = new OffscreenCanvas(bitmap.width, bitmap.height)
    const ctx = canvas.getContext('2d')
    if (!ctx) throw new Error('Could not get OffscreenCanvas context')
    ctx.drawImage(bitmap, 0, 0)
    const blob = await canvas.convertToBlob({ type: 'image/webp', quality: 0.92 })
    bitmap.close()
    return blob
  }
  return new Blob([decrypted], { type: mimeType })
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
      removeUrlFromCache(url)
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
  const url = await getThumbnail(photoId, async () => {
    let url = presignedUrl
    if (!url) {
      const response = await api.get(`/photos/${photoId}/thumbnail`)
      url = response.data.data.presigned_url as string
    }
    const decrypted = await fetchAndDecrypt(url, pek, iv)
    return new Blob([decrypted], { type: 'image/webp' })
  })
  trackObjectUrl(componentKey, url)
  return url
}

export async function fetchOriginal(
  photoId: number,
  pek: CryptoKey,
  iv: string,
  mimeType: string,
  sizeBytes: number,
): Promise<string> {
  return await getOriginal(photoId, sizeBytes, async () => {
    const response = await api.get(`/photos/${photoId}`)
    const presignedUrl = response.data.data.presigned_url as string
    const decrypted = await fetchAndDecrypt(presignedUrl, pek, iv)
    return await decryptedToBlob(decrypted, mimeType)
  })
}

export async function preloadOriginal(photo: Photo): Promise<void> {
  try {
    const pek = await getPEKForPhoto(photo.children)
    if (!pek) return
    await fetchOriginal(photo.id, pek, photo.iv_original, photo.original_mime, photo.size_bytes)
  } catch {
    // ponytail: best-effort preload
  }
}

// ---------------------------------------------------------------------------
// Direct-to-storage uploads (presigned PUT). Only ciphertext ever goes here.
// ---------------------------------------------------------------------------

export interface UploadTarget {
  url: string
  headers: Record<string, string>
}

export interface UploadIntent {
  uuid: string
  original: UploadTarget
  thumbnail: UploadTarget
  expires_at: string
}

export interface UploadIntentRequest {
  original_bytes: number
  thumbnail_bytes: number
  original_mime: string
}

/** A non-2xx answer (status > 0) or a network failure (status 0) from storage. */
export class StoragePutError extends Error {
  constructor(
    public readonly status: number,
    message?: string,
  ) {
    super(message ?? `Storage PUT failed with status ${status}`)
    this.name = 'StoragePutError'
  }
}

export interface DirectUploadOptions {
  /** Base delay between PUT retries; doubles per attempt. */
  backoffMs?: number
  /** Combined progress of both blobs, 0..1. */
  onProgress?: (fraction: number) => void
}

const PUT_ATTEMPTS = 3
const MAX_INTENT_RENEWALS = 2
const EXPIRY_MARGIN_MS = 5000

// Headers a browser refuses to set; Authorization must never reach storage.
const FORBIDDEN_PUT_HEADERS = new Set(['host', 'content-length', 'authorization'])

// Remembered for the session: once the server answers 404 (direct uploads off),
// skip the intent call for every following file.
let directUploadsDisabled = false

export function resetDirectUploadState(): void {
  directUploadsDisabled = false
}

export function isDirectUploadDisabled(): boolean {
  return directUploadsDisabled
}

/**
 * Asks the server for an upload slot. Returns null only when the server
 * answers 404, meaning direct uploads are disabled; every other failure throws.
 */
export async function requestUploadIntent(
  request: UploadIntentRequest,
): Promise<UploadIntent | null> {
  try {
    const response = await api.post('/photos/upload-intent', request)
    return response.data.data as UploadIntent
  } catch (error: unknown) {
    if ((error as { response?: { status?: number } })?.response?.status === 404) {
      directUploadsDisabled = true
      return null
    }
    throw error
  }
}

/**
 * PUTs a blob to a presigned URL with XMLHttpRequest (upload progress; no axios
 * timeout and no Authorization header, which storage must never receive).
 */
export function putEncryptedBlob(
  url: string,
  headers: Record<string, string>,
  blob: Blob,
  onProgress?: (loaded: number, total: number) => void,
): Promise<void> {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest()
    xhr.open('PUT', url)
    for (const [name, value] of Object.entries(headers)) {
      if (!FORBIDDEN_PUT_HEADERS.has(name.toLowerCase())) {
        xhr.setRequestHeader(name, value)
      }
    }
    if (onProgress) {
      xhr.upload.onprogress = (event) => onProgress(event.loaded, event.total)
    }
    xhr.onload = () => {
      if (xhr.status >= 200 && xhr.status < 300) {
        resolve()
      } else {
        reject(new StoragePutError(xhr.status))
      }
    }
    xhr.onerror = () => reject(new StoragePutError(0, 'Network error during upload'))
    xhr.onabort = () => reject(new StoragePutError(0, 'Upload aborted'))
    xhr.send(blob)
  })
}

const sleep = (ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms))

function isRetryable(error: unknown): boolean {
  if (!(error instanceof StoragePutError)) return false
  return error.status === 0 || error.status === 408 || error.status === 429 || error.status >= 500
}

/** An expired (or otherwise rejected) presigned URL answers 403. */
function isExpiredIntent(error: unknown): boolean {
  return error instanceof StoragePutError && error.status === 403
}

async function putWithRetry(
  target: UploadTarget,
  blob: Blob,
  backoffMs: number,
  onProgress: (loaded: number) => void,
): Promise<void> {
  for (let attempt = 0; ; attempt++) {
    try {
      onProgress(0)
      await putEncryptedBlob(target.url, target.headers, blob, (loaded) => onProgress(loaded))
      onProgress(blob.size)
      return
    } catch (error: unknown) {
      if (attempt + 1 >= PUT_ATTEMPTS || !isRetryable(error)) throw error
      await sleep(backoffMs * 2 ** attempt)
    }
  }
}

/**
 * Reserves a slot and PUTs the already-encrypted original and thumbnail in
 * parallel. Retries each PUT up to 3 times; when the intent expired, requests a
 * fresh intent and re-PUTs the same ciphertext (never re-encrypts).
 *
 * Returns the intent uuid to pass to `completeUpload`, or null when direct
 * uploads are disabled and the caller must use the proxy upload.
 */
export async function uploadEncryptedBlobs(
  originalMime: string,
  original: Blob,
  thumbnail: Blob,
  options: DirectUploadOptions = {},
): Promise<string | null> {
  if (directUploadsDisabled) return null

  const backoffMs = options.backoffMs ?? 500
  const totalBytes = original.size + thumbnail.size

  for (let renewal = 0; ; renewal++) {
    const intent = await requestUploadIntent({
      original_bytes: original.size,
      thumbnail_bytes: thumbnail.size,
      original_mime: originalMime,
    })
    if (!intent) return null

    const loaded = [0, 0]
    const report = (index: number) => (bytes: number) => {
      loaded[index] = bytes
      options.onProgress?.(Math.min(1, (loaded[0]! + loaded[1]!) / totalBytes))
    }

    try {
      if (Date.parse(intent.expires_at) <= Date.now() + EXPIRY_MARGIN_MS) {
        throw new StoragePutError(403, 'Upload intent expired')
      }
      await Promise.all([
        putWithRetry(intent.original, original, backoffMs, report(0)),
        putWithRetry(intent.thumbnail, thumbnail, backoffMs, report(1)),
      ])
      return intent.uuid
    } catch (error: unknown) {
      if (isExpiredIntent(error) && renewal < MAX_INTENT_RENEWALS) continue
      throw error
    }
  }
}

/** Finishes a direct upload; the server verifies the objects and creates the photo. */
export async function completeUpload(uuid: string, metadata: FormData): Promise<void> {
  await api.post(`/photos/${uuid}/complete`, metadata, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
}
