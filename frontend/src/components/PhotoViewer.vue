<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, watch, computed } from 'vue'
import { fetchOriginal, cleanupComponent } from '@/services/storage'
import { useChildStore } from '@/stores/child'
import { decryptBlob, revokeObjectURL } from '@/crypto/encrypt'
import { ageAt } from '@/utils/childHelpers'
import TagChip from '@/components/TagChip.vue'
import type { Photo } from '@/types/photo'
import type { TagItem } from '@/components/TagSidebar.vue'

const props = defineProps<{
  photo: Photo
  hasPrev: boolean
  hasNext: boolean
  canDelete: boolean
  childBirthDate: string | null
  allTags: TagItem[]
}>()

const emit = defineEmits<{
  close: []
  prev: []
  next: []
  delete: [photoId: number]
  tagAdded: [photoId: number, tagName: string]
  tagRemoved: [photoId: number, tagId: number]
}>()

const childStore = useChildStore()
const componentKey = 'photoviewer'

const imageUrl = ref<string | null>(null)
const isLoading = ref(true)
const showInfo = ref(false)
const zoom = ref(1)
const exifData = ref<Record<string, unknown> | null>(null)
const showDeleteConfirm = ref(false)
const isDeleting = ref(false)
const showAddTagDropdown = ref(false)
const showNewTagInput = ref(false)
const newTagName = ref('')

const photoAge = computed(() => {
  if (!props.childBirthDate || !props.photo.taken_at) return null
  return ageAt(props.childBirthDate, new Date(props.photo.taken_at))
})

const existingTagIds = computed(() => new Set(props.photo.tags?.map((t) => t.id) ?? []))

const availableTagsToAdd = computed(() =>
  props.allTags.filter((t) => !existingTagIds.value.has(t.id)),
)

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

function formatDate(dateStr: string | null): string {
  if (!dateStr) return 'Unknown'
  return new Date(dateStr).toLocaleDateString('default', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  })
}

function formatShutterSpeed(et: unknown): string {
  if (typeof et === 'number' && et < 1) return `1/${Math.round(1 / et)}s`
  return `${et}s`
}

function zoomIn() {
  zoom.value = Math.min(zoom.value + 0.5, 4)
}

function zoomOut() {
  zoom.value = Math.max(zoom.value - 0.5, 0.5)
}

function resetZoom() {
  zoom.value = 1
}

function onWheel(event: WheelEvent) {
  event.preventDefault()
  if (event.deltaY < 0) zoomIn()
  else zoomOut()
}

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') {
    if (showDeleteConfirm.value) { showDeleteConfirm.value = false; return }
    if (showAddTagDropdown.value) { showAddTagDropdown.value = false; return }
    emit('close')
  }
  if (event.key === 'ArrowLeft' && props.hasPrev) emit('prev')
  if (event.key === 'ArrowRight' && props.hasNext) emit('next')
  if (event.key === 'i') showInfo.value = !showInfo.value
}

async function decryptExif() {
  if (!props.photo.exif_encrypted || !props.photo.iv_exif) return
  try {
    const dek = await childStore.ensureDEK(props.photo.child_id)
    const binary = atob(props.photo.exif_encrypted)
    const bytes = new Uint8Array(binary.length)
    for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i)
    const decrypted = await decryptBlob(bytes.buffer, dek, props.photo.iv_exif)
    const text = new TextDecoder().decode(decrypted)
    exifData.value = JSON.parse(text)
  } catch {
    // EXIF decryption is best-effort
  }
}

async function loadImage() {
  isLoading.value = true
  try {
    const dek = await childStore.ensureDEK(props.photo.child_id)
    imageUrl.value = await fetchOriginal(
      props.photo.child_id,
      props.photo.id,
      dek,
      props.photo.iv_original,
      props.photo.mime_type,
    )
  } finally {
    isLoading.value = false
  }
}

async function handleDelete() {
  isDeleting.value = true
  try {
    emit('delete', props.photo.id)
  } finally {
    isDeleting.value = false
    showDeleteConfirm.value = false
  }
}

function handleAddTag(tagName: string) {
  emit('tagAdded', props.photo.id, tagName)
  showAddTagDropdown.value = false
  showNewTagInput.value = false
  newTagName.value = ''
}

function handleShowNewTagInput() {
  showNewTagInput.value = true
}

function submitNewTag() {
  const name = newTagName.value.trim()
  if (name) handleAddTag(name)
}

function handleRemoveTag(tagId: number) {
  emit('tagRemoved', props.photo.id, tagId)
}

function toggleAddTag() {
  showAddTagDropdown.value = !showAddTagDropdown.value
  showNewTagInput.value = false
  newTagName.value = ''
}

watch(() => props.photo.id, () => {
  if (imageUrl.value) revokeObjectURL(imageUrl.value)
  imageUrl.value = null
  zoom.value = 1
  exifData.value = null
  showInfo.value = false
  showDeleteConfirm.value = false
  showAddTagDropdown.value = false
  loadImage()
  decryptExif()
})

onMounted(() => {
  loadImage()
  decryptExif()
  window.addEventListener('keydown', onKeydown)
})

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
  if (imageUrl.value) revokeObjectURL(imageUrl.value)
  cleanupComponent(componentKey)
})
</script>

<template>
  <div class="photo-viewer-overlay" @click.self="emit('close')">
    <!-- Close -->
    <button class="photo-viewer-close" @click="emit('close')">
      <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
      </svg>
    </button>

    <!-- Info toggle -->
    <button class="photo-viewer-info-btn" @click="showInfo = !showInfo">
      <template v-if="showInfo">Hide info</template>
      <template v-else>Info</template>
    </button>

    <!-- Image -->
    <div class="flex items-center justify-center w-full h-full" @wheel="onWheel">
      <div v-if="isLoading" class="flex flex-col items-center gap-3">
        <span class="spinner-large border-white/30 border-t-white" />
        <span class="text-white/50 text-sm">Decrypting...</span>
      </div>
      <img
        v-else-if="imageUrl"
        :src="imageUrl"
        class="photo-viewer-image"
        :style="{ transform: `scale(${zoom})`, cursor: zoom > 1 ? 'grab' : 'zoom-in' }"
        @dblclick="zoom === 1 ? zoomIn() : resetZoom()"
      />
    </div>

    <!-- Navigation -->
    <button
      v-if="hasPrev"
      class="photo-viewer-nav left-4"
      @click.stop="emit('prev')"
    >
      <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
      </svg>
    </button>
    <button
      v-if="hasNext"
      class="photo-viewer-nav right-4"
      @click.stop="emit('next')"
    >
      <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
      </svg>
    </button>

    <!-- Zoom controls -->
    <div class="zoom-controls">
      <button class="zoom-btn" @click="zoomOut" :disabled="zoom <= 0.5">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4" />
        </svg>
      </button>
      <span class="zoom-level">{{ Math.round(zoom * 100) }}%</span>
      <button class="zoom-btn" @click="zoomIn" :disabled="zoom >= 4">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
      </button>
    </div>

    <!-- Metadata sidebar -->
    <div v-if="showInfo" class="photo-viewer-sidebar">
      <h3 class="text-lg font-semibold text-slate-900 mb-4 dark:text-slate-100">Details</h3>

      <div class="photo-viewer-meta">
        <div>
          <p class="photo-viewer-meta-label">Taken</p>
          <p class="photo-viewer-meta-value">{{ formatDate(photo.taken_at) }}</p>
        </div>
        <div v-if="photoAge">
          <p class="photo-viewer-meta-label">Age at photo</p>
          <p class="photo-viewer-meta-value">{{ photoAge }}</p>
        </div>
        <div>
          <p class="photo-viewer-meta-label">Uploaded by</p>
          <p class="photo-viewer-meta-value">{{ photo.uploader_name || 'Unknown' }}</p>
        </div>
        <div>
          <p class="photo-viewer-meta-label">File size</p>
          <p class="photo-viewer-meta-value">{{ formatBytes(photo.size_bytes) }}</p>
        </div>
        <div v-if="photo.width && photo.height">
          <p class="photo-viewer-meta-label">Dimensions</p>
          <p class="photo-viewer-meta-value">{{ photo.width }} &times; {{ photo.height }}</p>
        </div>

        <div v-if="exifData">
          <div v-if="exifData.Make || exifData.Model">
            <p class="photo-viewer-meta-label">Camera</p>
            <p class="photo-viewer-meta-value">{{ [exifData.Make, exifData.Model].filter(Boolean).join(' ') }}</p>
          </div>
          <div v-if="exifData.LensModel">
            <p class="photo-viewer-meta-label">Lens</p>
            <p class="photo-viewer-meta-value">{{ exifData.LensModel }}</p>
          </div>
          <div v-if="exifData.ISO !== undefined">
            <p class="photo-viewer-meta-label">ISO</p>
            <p class="photo-viewer-meta-value">{{ exifData.ISO }}</p>
          </div>
          <div v-if="exifData.ExposureTime">
            <p class="photo-viewer-meta-label">Shutter speed</p>
            <p class="photo-viewer-meta-value">{{ formatShutterSpeed(exifData.ExposureTime) }}</p>
          </div>
          <div v-if="exifData.FocalLength">
            <p class="photo-viewer-meta-label">Focal length</p>
            <p class="photo-viewer-meta-value">{{ exifData.FocalLength }}</p>
          </div>
        </div>

        <div v-if="photo.tags && photo.tags.length > 0 || canDelete">
          <p class="photo-viewer-meta-label mt-3">Tags</p>
          <div class="photo-viewer-actions">
            <TagChip
              v-for="tag in photo.tags"
              :key="tag.id"
              :name="tag.name"
              :clickable="true"
              :removable="canDelete"
              @remove="handleRemoveTag(tag.id)"
            />
            <span v-if="canDelete && availableTagsToAdd.length > 0 || !showNewTagInput" class="tag-add-wrapper">
              <button class="tag-add-btn" @click="toggleAddTag">
                + Add tag
              </button>
              <div v-if="showAddTagDropdown" class="tag-dropdown" @click.stop>
                <input
                  v-if="showNewTagInput"
                  v-model="newTagName"
                  class="tag-dropdown-input"
                  type="text"
                  placeholder="Type tag name..."
                  maxlength="100"
                  @keyup.enter="submitNewTag"
                  @keyup.escape="showAddTagDropdown = false; showNewTagInput = false"
                />
                <button
                  v-for="tag in availableTagsToAdd"
                  :key="tag.id"
                  class="tag-dropdown-item"
                  @click="handleAddTag(tag.name)"
                >
                  {{ tag.name }}
                </button>
                <button
                  v-if="!showNewTagInput"
                  class="tag-dropdown-item-new"
                  @click="handleShowNewTagInput"
                >
                  + Create new tag
                </button>
              </div>
            </span>
          </div>
        </div>

        <!-- Delete button -->
        <div v-if="canDelete" class="pt-4 mt-2 border-t border-slate-100 dark:border-slate-700">
          <button class="btn-danger w-full justify-center" @click="showDeleteConfirm = true">
            Delete photo
          </button>
        </div>
      </div>
    </div>

    <!-- Delete confirmation dialog -->
    <div v-if="showDeleteConfirm" class="confirm-overlay" @click.self="showDeleteConfirm = false">
      <div class="confirm-card">
        <h3 class="confirm-title">Delete this photo?</h3>
        <p class="confirm-message">
          This will permanently delete the photo and all its copies from storage. This action cannot be undone.
        </p>
        <div class="confirm-actions">
          <button class="btn-cancel" @click="showDeleteConfirm = false">Cancel</button>
          <button class="btn-danger" :disabled="isDeleting" @click="handleDelete">
            <span v-if="isDeleting" class="spinner" />
            Delete
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
