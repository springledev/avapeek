<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { toast } from 'vue-sonner'
import { MSG } from '@/lib/messages'
import { pickUpSharedFiles } from '@/lib/share-inbox'
import { useChildStore } from '@/stores/child'
import { useUploadPhotos } from '@/composables/useUploadPhotos'
import UploadPicker from '@/components/upload/UploadPicker.vue'
import BatchReview from '@/components/upload/BatchReview.vue'
import UiProgressOverlay from '@/components/ui/UiProgressOverlay.vue'

const router = useRouter()
const route = useRoute()
const { isUploading, current, total, fileName, errors, upload } = useUploadPhotos()

const childStore = useChildStore()

/** View-only members (kids exist, none allows uploading) have nothing to do here. */
const isViewOnly = computed(
  () =>
    childStore.childrenLoaded &&
    childStore.children.length > 0 &&
    !childStore.canUploadAnywhere,
)

watch(
  isViewOnly,
  (blocked) => {
    if (!blocked) return
    toast.info(MSG.uploadViewOnly)
    void router.replace('/feed')
  },
  { immediate: true },
)

const selectedFiles = ref<File[]>([])
const showBatchReview = ref(false)

function onFilesSelected(files: File[]) {
  selectedFiles.value = files
  showBatchReview.value = true
}

/** Picks up files staged by the Web Share Target (`/upload?shared=1`), then empties the inbox. */
onMounted(async () => {
  if (isViewOnly.value) return
  try {
    const isSharedVisit = route.query.shared === '1'
    const files = await pickUpSharedFiles(isSharedVisit)
    if (isSharedVisit) {
      void router.replace({ name: 'upload' })
    }
    if (files.length > 0) {
      onFilesSelected(files)
    }
  } catch (error) {
    console.warn('Share inbox pickup failed', error)
  }
})

function closeBatchReview() {
  showBatchReview.value = false
  selectedFiles.value = []
}

async function startUpload(files: File[], childIds: number[], tagNames: string[] = []) {
  if (childIds.length === 0) {
    toast.error(MSG.uploadNeedsKid)
    return
  }
  if (files.length === 0) {
    toast.error(MSG.uploadNeedsPhotos)
    return
  }

  const { succeeded, failed } = await upload(files, childIds, tagNames)

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
  <div v-if="!isViewOnly">
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
