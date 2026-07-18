<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, watch, computed } from 'vue'
import { X, Info, ChevronLeft, ChevronRight, Minus, Plus, AlertTriangle, Trash2 } from '@lucide/vue'
import { fetchOriginal, getPEKForPhoto } from '@/services/storage'
import { getCachedOriginal } from '@/services/imageCache'
import { decryptBlob } from '@/crypto/encrypt'
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

const imageUrl = ref<string | null>(null)
const isLoading = ref(true)
const hasError = ref(false)
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
    const pek = await getPEKForPhoto(props.photo.children)
    if (!pek) return
    const binary = atob(props.photo.exif_encrypted)
    const bytes = new Uint8Array(binary.length)
    for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i)
    const decrypted = await decryptBlob(bytes.buffer, pek, props.photo.iv_exif)
    const text = new TextDecoder().decode(decrypted)
    exifData.value = JSON.parse(text)
  } catch {
    // EXIF decryption is best-effort
  }
}

async function loadImage() {
  hasError.value = false
  const cached = getCachedOriginal(props.photo.id)
  if (cached) {
    imageUrl.value = cached
    isLoading.value = false
    return
  }
  isLoading.value = true
  try {
    const pek = await getPEKForPhoto(props.photo.children)
    if (!pek) {
      hasError.value = true
      return
    }
    imageUrl.value = await fetchOriginal(
      props.photo.id,
      pek,
      props.photo.iv_original,
      props.photo.original_mime,
    )
  } catch {
    hasError.value = true
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
})
</script>

<template>
  <div class="photo-viewer-overlay" @click.self="emit('close')">
    <button class="photo-viewer-close" @click="emit('close')">
      <X class="h-6 w-6" />
    </button>

    <button class="photo-viewer-info-btn" @click="showInfo = !showInfo">
      <Info class="h-5 w-5" />
      <span class="ml-1">{{ showInfo ? 'Hide' : 'Info' }}</span>
    </button>

    <div class="flex h-full w-full items-center justify-center" @wheel="onWheel">
      <div v-if="isLoading" class="flex flex-col items-center gap-3">
        <span class="spinner-large border-white/30 border-t-white" />
        <span class="text-sm text-white/50">Decrypting...</span>
      </div>

      <div v-else-if="hasError" class="flex flex-col items-center gap-3 px-8 text-center">
        <AlertTriangle class="h-12 w-12 text-white/40" />
        <span class="text-sm text-white/50">Failed to decrypt photo.</span>
        <button class="text-sm font-medium text-blue-400 hover:text-blue-300" @click="loadImage">Try again</button>
      </div>

      <img
        v-else-if="imageUrl"
        :src="imageUrl"
        class="photo-viewer-image"
        :style="{ transform: `scale(${zoom})` }"
        alt=""
        @dblclick="resetZoom"
      />
    </div>

    <div v-if="hasPrev" class="photo-viewer-nav left-4" @click="emit('prev')">
      <ChevronLeft class="h-8 w-8" />
    </div>

    <div v-if="hasNext" class="photo-viewer-nav right-4" @click="emit('next')">
      <ChevronRight class="h-8 w-8" />
    </div>

    <div class="absolute bottom-4 left-1/2 z-20 flex -translate-x-1/2 items-center gap-2 rounded-full bg-black/60 px-3 py-1.5 text-white">
      <button @click="zoomOut" class="rounded p-1 hover:bg-white/20" title="Zoom out">
        <Minus class="h-4 w-4" />
      </button>
      <span class="min-w-[3ch] text-center text-xs">{{ Math.round(zoom * 100) }}%</span>
      <button @click="zoomIn" class="rounded p-1 hover:bg-white/20" title="Zoom in">
        <Plus class="h-4 w-4" />
      </button>
    </div>

    <Transition name="slide">
      <div v-if="showInfo" class="photo-viewer-sidebar">
        <div class="photo-viewer-meta">
          <div>
            <span class="photo-viewer-meta-label">Taken</span>
            <span class="photo-viewer-meta-value">{{ formatDate(props.photo.taken_at) }}</span>
          </div>
          <div v-if="photoAge">
            <span class="photo-viewer-meta-label">Age in photo</span>
            <span class="photo-viewer-meta-value">{{ photoAge }}</span>
          </div>
          <div>
            <span class="photo-viewer-meta-label">Uploaded by</span>
            <span class="photo-viewer-meta-value">{{ props.photo.uploader_name || 'Unknown' }}</span>
          </div>
          <div>
            <span class="photo-viewer-meta-label">File size</span>
            <span class="photo-viewer-meta-value">{{ formatBytes(props.photo.size_bytes) }}</span>
          </div>
          <div v-if="props.photo.width && props.photo.height">
            <span class="photo-viewer-meta-label">Dimensions</span>
            <span class="photo-viewer-meta-value">{{ props.photo.width }} × {{ props.photo.height }}</span>
          </div>
        </div>

        <div v-if="exifData" class="border-t border-gray-200 px-4 py-3 dark:border-slate-600">
          <h4 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Camera Info</h4>
          <div class="photo-viewer-meta">
            <div v-if="exifData.Make || exifData.Model">
              <span class="photo-viewer-meta-label mr-2">Camera</span>
              <span class="photo-viewer-meta-value">{{ [exifData.Make, exifData.Model].filter(Boolean).join(' ') }}</span>
            </div>
            <div v-if="exifData.LensModel">
              <span class="photo-viewer-meta-label">Lens</span>
              <span class="photo-viewer-meta-value">{{ exifData.LensModel }}</span>
            </div>
            <div v-if="exifData.ISO">
              <span class="photo-viewer-meta-label">ISO</span>
              <span class="photo-viewer-meta-value">{{ exifData.ISO }}</span>
            </div>
            <div v-if="exifData.FNumber">
              <span class="photo-viewer-meta-label">Aperture</span>
              <span class="photo-viewer-meta-value">f/{{ exifData.FNumber }}</span>
            </div>
            <div v-if="exifData.ExposureTime">
              <span class="photo-viewer-meta-label">Shutter</span>
              <span class="photo-viewer-meta-value">{{ formatShutterSpeed(exifData.ExposureTime) }}</span>
            </div>
            <div v-if="exifData.FocalLength">
              <span class="photo-viewer-meta-label">Focal Length</span>
              <span class="photo-viewer-meta-value">{{ exifData.FocalLength }}mm</span>
            </div>
          </div>
        </div>

        <div class="photo-viewer-actions">
          <div class="flex flex-wrap gap-1.5">
            <TagChip
              v-for="tag in props.photo.tags || []"
              :key="tag.id"
              :name="tag.name"
              removable
              @remove="handleRemoveTag(tag.id)"
            />
            <div class="relative">
              <button
                @click="toggleAddTag"
                class="inline-flex items-center gap-1 rounded-full border border-dashed border-slate-300 px-2.5 py-0.5 text-xs text-slate-500 hover:border-slate-400 dark:border-slate-500 dark:text-slate-400"
              >
                + Add tag
              </button>
              <div
                v-if="showAddTagDropdown"
                class="absolute bottom-full left-0 z-30 mb-2 w-48 rounded-lg border border-gray-200 bg-white p-2 shadow-lg dark:border-slate-600 dark:bg-slate-800"
              >
                <div v-if="!showNewTagInput">
                  <button
                    v-for="tag in availableTagsToAdd"
                    :key="tag.id"
                    @click="handleAddTag(tag.name)"
                    class="w-full rounded px-2 py-1 text-left text-sm hover:bg-gray-100 dark:hover:bg-slate-700"
                  >
                    {{ tag.name }}
                  </button>
                  <button
                    @click="handleShowNewTagInput"
                    class="mt-1 w-full rounded px-2 py-1 text-left text-sm text-indigo-600 hover:bg-gray-100 dark:text-indigo-400 dark:hover:bg-slate-700"
                  >
                    + Create new tag
                  </button>
                </div>
                <div v-else class="flex gap-1">
                  <input
                    v-model="newTagName"
                    type="text"
                    placeholder="Tag name"
                    class="flex-1 rounded border border-gray-300 px-2 py-1 text-sm dark:border-slate-600 dark:bg-slate-700"
                    @keydown.enter.prevent="submitNewTag"
                  />
                  <button @click="submitNewTag" class="rounded bg-indigo-600 px-2 py-1 text-xs text-white">Add</button>
                </div>
              </div>
            </div>
          </div>

          <button
            v-if="props.canDelete"
            @click="showDeleteConfirm = true"
            class="mt-4 flex items-center gap-1.5 text-sm text-red-500 hover:text-red-600"
          >
            <Trash2 class="h-4 w-4" />
            Delete photo
          </button>
        </div>
      </div>
    </Transition>

    <div
      v-if="showDeleteConfirm"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
      @click.self="showDeleteConfirm = false"
    >
      <div class="mx-4 w-full max-w-sm rounded-xl bg-white p-6 shadow-xl dark:bg-slate-800">
        <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Delete photo?</h3>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">This action cannot be undone. The photo will be permanently deleted.</p>
        <div class="mt-6 flex justify-end gap-3">
          <button @click="showDeleteConfirm = false" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-700">
            Cancel
          </button>
          <button @click="handleDelete" :disabled="isDeleting" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50">
            {{ isDeleting ? 'Deleting...' : 'Delete' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
