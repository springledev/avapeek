<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { toast } from 'vue-sonner'
import { MSG } from '@/lib/messages'
import { useUploadPhotos } from '@/composables/useUploadPhotos'
import UploadPicker from '@/components/upload/UploadPicker.vue'
import BatchReview from '@/components/upload/BatchReview.vue'
import UiProgressOverlay from '@/components/ui/UiProgressOverlay.vue'

const router = useRouter()
const { isUploading, current, total, fileName, errors, upload } = useUploadPhotos()

const selectedFiles = ref<File[]>([])
const showBatchReview = ref(false)

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

  const { succeeded, failed } = await upload(files, childIds)

  if (failed > 0) {
    // Keep only the failed photos in the review so a retry doesn't duplicate the rest.
    selectedFiles.value = errors.value.map((e) => e.file)
    toast.error(succeeded > 0 ? MSG.uploadPartial(succeeded, files.length) : MSG.uploadFailed)
    return
  }

  toast.success(MSG.photosAdded(succeeded))
  showBatchReview.value = false
  selectedFiles.value = []
  router.push('/feed')
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
      :current="current"
      :total="total"
      :file-name="fileName"
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
