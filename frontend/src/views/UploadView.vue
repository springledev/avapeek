<script setup lang="ts">
import { extractTakenAt } from '@/lib/exif/takenAt'
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useChildStore } from '@/stores/child'
import { useFeedStore } from '@/stores/feed'
import { api } from '@/services/api'
import { generatePEK, wrapPEKWithDEK } from '@/crypto/keychain'
import { generateThumbnail, generateLQIP } from '@/crypto/thumbnails'
import { encryptBuffer } from '@/crypto/encrypt'
import { arrayBufferToBase64 } from '@/lib/util'
import { decodeHeic, isHeic } from '@/crypto/heic'
import { toast } from 'vue-sonner'
import { MSG } from '@/lib/messages'
import UploadPicker from '@/components/upload/UploadPicker.vue'
import BatchReview from '@/components/upload/BatchReview.vue'
import UiProgressOverlay from '@/components/ui/UiProgressOverlay.vue'

const router = useRouter()
const childStore = useChildStore()
const feedStore = useFeedStore()

const selectedFiles = ref<File[]>([])
const showBatchReview = ref(false)
const isUploading = ref(false)
const uploadCurrent = ref(0)
const uploadTotal = ref(0)
const uploadFileName = ref('')

function onFilesSelected(files: File[]) {
  selectedFiles.value = files
  showBatchReview.value = true
}

function closeBatchReview() {
  showBatchReview.value = false
  selectedFiles.value = []
}

async function startUpload(files: File[], childIds: number[]) {
  if (childIds.length === 0) {
    toast.error('Please select at least one child.')
    return
  }
  if (files.length === 0) {
    toast.error('Please select photos to upload.')
    return
  }

  isUploading.value = true
  uploadTotal.value = files.length
  uploadCurrent.value = 0
  let successCount = 0

  try {
    for (let i = 0; i < files.length; i++) {
      const f = files[i]!
      uploadCurrent.value = i + 1
      uploadFileName.value = f.name
      await new Promise((r) => setTimeout(r, 0))

      let bitmap: ImageBitmap
      if (isHeic(f)) {
        bitmap = await decodeHeic(f)
      } else {
        bitmap = await createImageBitmap(f)
      }

      const { thumb: thumbnailBlob } = await generateThumbnail(bitmap)
      const lqipBlob = await generateLQIP(bitmap)

      await new Promise((r) => setTimeout(r, 0))

      const pek = await generatePEK()

      const rawBytes = await f.arrayBuffer()
      const { encrypted: encOriginal, iv: ivOriginal } = await encryptBuffer(rawBytes, pek)

      const thumbBytes = await thumbnailBlob.arrayBuffer()
      const { encrypted: encThumbnail, iv: ivThumbnail } = await encryptBuffer(thumbBytes, pek)

      const lqipBytes = await lqipBlob.arrayBuffer()
      const { encrypted: encLQIP, iv: ivLQIP } = await encryptBuffer(lqipBytes, pek)
      const lqipEncrypted = arrayBufferToBase64(encLQIP)

      const ext = f.name.split('.').pop() || 'jpg'
      const mimeMap: Record<string, string> = {
        jpg: 'image/jpeg',
        jpeg: 'image/jpeg',
        png: 'image/png',
        webp: 'image/webp',
        heic: 'image/heic',
        heif: 'image/heif',
      }
      const originalMime = mimeMap[ext] || f.type

      const children: Record<number, { encrypted_pek: string; iv_pek: string }> = {}
      for (const childId of childIds) {
        const dek = await childStore.ensureDEK(childId)
        children[childId] = await wrapPEKWithDEK(pek, dek)
      }

      await new Promise((r) => setTimeout(r, 0))

      const formData = new FormData()
      formData.append('file', new Blob([encOriginal]), f.name)
      formData.append('iv', ivOriginal)
      formData.append('thumbnail', new Blob([encThumbnail]), 'thumb.webp')
      formData.append('iv_thumbnail', ivThumbnail)
      formData.append('lqip_encrypted', lqipEncrypted)
      formData.append('iv_lqip', ivLQIP)
      formData.append('original_mime', originalMime)
      formData.append('size_bytes', String(f.size))
      formData.append('width', String(bitmap.width))
      formData.append('height', String(bitmap.height))
      formData.append('children', JSON.stringify(children))
      const takenAt = await extractTakenAt(f)
      if (takenAt) {
        formData.append('taken_at', takenAt)
      }

      await api.post('/photos/upload', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })

      bitmap.close()
      successCount++
    }

    toast.success(MSG.photosAdded(successCount))
    feedStore.fetchPhotos()
    showBatchReview.value = false
    selectedFiles.value = []
    router.push('/feed')
  } catch (err: unknown) {
    console.error('Upload error:', err)
    toast.error(MSG.uploadFailed)
  } finally {
    isUploading.value = false
    uploadFileName.value = ''
  }
}
</script>

<template>
  <div>
    <h1 class="upload-title">Upload photos</h1>
    <UploadPicker @select="onFilesSelected" />

    <BatchReview
      :open="showBatchReview"
      :files="selectedFiles"
      @close="closeBatchReview"
      @upload="startUpload"
    />

    <UiProgressOverlay
      :active="isUploading"
      :current="uploadCurrent"
      :total="uploadTotal"
      :file-name="uploadFileName"
    />
  </div>
</template>

<style scoped>
.upload-title {
  font-size: var(--text-title);
  font-weight: 800;
  line-height: var(--lh-title);
  color: var(--color-ink);
  margin: 0 0 var(--space-6);
}
</style>
