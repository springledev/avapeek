import { ref } from 'vue'
import { extractTakenAt } from '@/lib/exif/takenAt'
import { useChildStore } from '@/stores/child'
import { useFeedStore } from '@/stores/feed'
import { useTagsStore } from '@/stores/tags'
import { api } from '@/services/api'
import { completeUpload, uploadEncryptedBlobs } from '@/services/storage'
import { generatePEK, wrapPEKWithDEK } from '@/crypto/keychain'
import { generateThumbnail, generateLQIP } from '@/crypto/thumbnails'
import { encryptBuffer, encryptString } from '@/crypto/encrypt'
import { arrayBufferToBase64 } from '@/lib/util'
import { decodeHeic, isHeic } from '@/crypto/heic'

export interface UploadFileError {
  file: File
  error: unknown
}

export interface UploadResult {
  succeeded: number
  failed: number
}

const MIME_BY_EXTENSION: Record<string, string> = {
  jpg: 'image/jpeg',
  jpeg: 'image/jpeg',
  png: 'image/png',
  webp: 'image/webp',
  heic: 'image/heic',
  heif: 'image/heif',
}

/**
 * Proxy transport (fallback when direct uploads are disabled): streams the
 * encrypted blobs and metadata through the API in one multipart request.
 */
async function sendViaProxy(formData: FormData): Promise<void> {
  await api.post('/photos/upload', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
}

/**
 * The only network step of an upload: sends one fully encrypted photo.
 * Everything before this call is client-side encryption. Tries a direct
 * presigned PUT to storage and falls back to the proxy upload when the server
 * reports direct uploads are disabled.
 */
async function sendEncryptedPhoto(
  encrypted: { original: Blob; thumbnail: Blob },
  originalMime: string,
  metadata: FormData,
  onProgress: (fraction: number) => void,
): Promise<void> {
  const uuid = await uploadEncryptedBlobs(originalMime, encrypted.original, encrypted.thumbnail, {
    onProgress,
  })

  if (uuid !== null) {
    await completeUpload(uuid, metadata)
    return
  }

  // Neutral part name: the real filename travels only as `name_encrypted`.
  metadata.append('file', encrypted.original, 'original.enc')
  metadata.append('thumbnail', encrypted.thumbnail, 'thumb.webp')
  await sendViaProxy(metadata)
  onProgress(1)
}

const yieldToUi = () => new Promise<void>((resolve) => setTimeout(resolve, 0))

/**
 * Encrypts and uploads photos for the given children, tracking progress and
 * per-file failures. A failing file does not stop the rest of the batch.
 */
export function useUploadPhotos() {
  const childStore = useChildStore()
  const feedStore = useFeedStore()
  const tagsStore = useTagsStore()

  const isUploading = ref(false)
  const current = ref(0)
  const total = ref(0)
  const fileName = ref('')
  const errors = ref<UploadFileError[]>([])
  /** Upload progress of the file currently being sent, 0..1. */
  const fileProgress = ref(0)

  async function uploadOne(f: File, childIds: number[], tagNames: string[]): Promise<void> {
    const bitmap: ImageBitmap = isHeic(f) ? await decodeHeic(f) : await createImageBitmap(f)

    try {
      const { thumb: thumbnailBlob } = await generateThumbnail(bitmap)
      const lqipBlob = await generateLQIP(bitmap)

      await yieldToUi()

      const pek = await generatePEK()

      const rawBytes = await f.arrayBuffer()
      const { encrypted: encOriginal, iv: ivOriginal } = await encryptBuffer(rawBytes, pek)

      const thumbBytes = await thumbnailBlob.arrayBuffer()
      const { encrypted: encThumbnail, iv: ivThumbnail } = await encryptBuffer(thumbBytes, pek)

      const lqipBytes = await lqipBlob.arrayBuffer()
      const { encrypted: encLQIP, iv: ivLQIP } = await encryptBuffer(lqipBytes, pek)
      const lqipEncrypted = arrayBufferToBase64(encLQIP)

      // The original filename is ciphertext too (PEK); sent on both transports.
      const { encrypted: nameEncrypted, iv: ivName } = await encryptString(f.name, pek)

      const ext = f.name.split('.').pop() || 'jpg'
      const originalMime = MIME_BY_EXTENSION[ext] || f.type

      const children: Record<number, { encrypted_pek: string; iv_pek: string }> = {}
      for (const childId of childIds) {
        const dek = await childStore.ensureDEK(childId)
        children[childId] = await wrapPEKWithDEK(pek, dek)
      }

      await yieldToUi()

      const formData = new FormData()
      formData.append('iv', ivOriginal)
      formData.append('iv_thumbnail', ivThumbnail)
      formData.append('lqip_encrypted', lqipEncrypted)
      formData.append('iv_lqip', ivLQIP)
      formData.append('name_encrypted', nameEncrypted)
      formData.append('iv_name', ivName)
      formData.append('original_mime', originalMime)
      formData.append('size_bytes', String(f.size))
      formData.append('width', String(bitmap.width))
      formData.append('height', String(bitmap.height))
      formData.append('children', JSON.stringify(children))
      // Tags are per child: ensure each exists in every chosen child's list, then send opaque ids only.
      for (const tagId of await tagsStore.idsForUpload(childIds, tagNames)) {
        formData.append('tag_ids[]', String(tagId))
      }
      const takenAt = await extractTakenAt(f)
      if (takenAt) {
        formData.append('taken_at', takenAt)
      }

      await sendEncryptedPhoto(
        {
          original: new Blob([encOriginal]),
          thumbnail: new Blob([encThumbnail]),
        },
        originalMime,
        formData,
        (fraction) => {
          fileProgress.value = fraction
        },
      )
    } finally {
      bitmap.close()
    }
  }

  async function upload(
    files: File[],
    childIds: number[],
    tagNames: string[] = [],
  ): Promise<UploadResult> {
    errors.value = []
    if (files.length === 0 || childIds.length === 0) {
      return { succeeded: 0, failed: 0 }
    }

    isUploading.value = true
    total.value = files.length
    current.value = 0
    let succeeded = 0

    try {
      for (let i = 0; i < files.length; i++) {
        const f = files[i]!
        current.value = i + 1
        fileName.value = f.name
        fileProgress.value = 0
        await yieldToUi()

        try {
          await uploadOne(f, childIds, tagNames)
          succeeded++
        } catch (error: unknown) {
          console.error('Upload error:', error)
          errors.value.push({ file: f, error })
        }
      }

      if (succeeded > 0) {
        feedStore.fetchPhotos()
        childStore.fetchChildren().catch(() => {})
      }
    } finally {
      isUploading.value = false
      fileName.value = ''
    }

    return { succeeded, failed: errors.value.length }
  }

  return { isUploading, current, total, fileName, fileProgress, errors, upload }
}
