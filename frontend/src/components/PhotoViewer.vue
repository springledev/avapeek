<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, watch, computed } from 'vue'
import { X, ChevronLeft, ChevronRight, Minus, Plus, AlertTriangle, Trash2, Download, Pencil, PanelRightClose } from '@lucide/vue'
import { fetchOriginal, getPEKForPhoto } from '@/services/storage'
import { getCachedOriginal } from '@/services/imageCache'
import TagChip from '@/components/TagChip.vue'
import type { Photo } from '@/types/photo'
import type { TagItem } from '@/components/TagSidebar.vue'

const props = defineProps<{
  photo: Photo
  hasPrev: boolean
  hasNext: boolean
  canDelete: boolean
  canEdit: boolean
  childBirthDate: string | null
  allTags: TagItem[]
}>()

const emit = defineEmits<{
  close: []
  prev: []
  next: []
  delete: [photoId: number]
  updateDate: [photoId: number, takenAt: string]
  tagAdded: [photoId: number, tagName: string]
  tagRemoved: [photoId: number, tagId: number]
}>()

const imageUrl = ref<string | null>(null)
const isLoading = ref(true)
const hasError = ref(false)
const showActions = ref(false)
const zoom = ref(1)
const showDeleteConfirm = ref(false)
const isDeleting = ref(false)
const showAddTagDropdown = ref(false)
const showNewTagInput = ref(false)
const newTagName = ref('')
const touchStartX = ref(0)
const editDate = ref('')
const isSavingDate = ref(false)

const existingTagIds = computed(() => new Set(props.photo.tags?.map((t) => t.id) ?? []))

const availableTagsToAdd = computed(() =>
  props.allTags.filter((t) => !existingTagIds.value.has(t.id)),
)

function downloadImage() {
  if (!imageUrl.value) return
  const a = document.createElement('a')
  a.href = imageUrl.value
  a.download = `photo-${props.photo.id}.${props.photo.original_mime.split('/')[1] || 'jpg'}`
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
}

async function saveDate() {
  if (!editDate.value) return
  isSavingDate.value = true
  try {
    emit('updateDate', props.photo.id, editDate.value)
  } finally {
    isSavingDate.value = false
  }
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
  if (event.key === 'i') showActions.value = !showActions.value
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

function onTouchStart(event: TouchEvent) {
  if (event.touches.length === 1) {
    touchStartX.value = event.touches[0].clientX
  }
}

function onTouchEnd(event: TouchEvent) {
  if (zoom.value !== 1 || touchStartX.value === 0) return
  const delta = event.changedTouches[0].clientX - touchStartX.value
  touchStartX.value = 0
  if (delta < -50 && props.hasNext) emit('next')
  else if (delta > 50 && props.hasPrev) emit('prev')
}

watch(() => props.photo.id, () => {
  imageUrl.value = null
  zoom.value = 1
  showActions.value = false
  showDeleteConfirm.value = false
  showAddTagDropdown.value = false
  editDate.value = props.photo.taken_at?.split('T')[0] || ''
  loadImage()
})

onMounted(() => {
  editDate.value = props.photo.taken_at?.split('T')[0] || ''
  loadImage()
  window.addEventListener('keydown', onKeydown)
})

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
})
</script>

<template>
  <div class="photo-viewer-overlay" @click.self="emit('close')" @touchstart="onTouchStart" @touchend="onTouchEnd">
    <button class="photo-viewer-close" @click="emit('close')">
      <X class="h-6 w-6" />
    </button>

    <button class="photo-viewer-info-btn photo-viewer-actions-btn" @click="showActions = !showActions">
      <PanelRightClose class="h-5 w-5" />
      <span class="ml-1">Actions</span>
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
      <div v-if="showActions" class="photo-viewer-sidebar">
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-sm font-semibold text-slate-900 dark:text-white">Actions</h3>
          <button @click="showActions = false" class="rounded p-1 hover:bg-slate-200 dark:hover:bg-slate-700">
            <X class="h-4 w-4" />
          </button>
        </div>

        <button
          v-if="imageUrl"
          @click="downloadImage"
          class="w-full mb-3 flex items-center gap-2 rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700"
        >
          <Download class="h-4 w-4" />
          Download
        </button>

        <div v-if="props.canEdit" class="mb-3 border-t border-gray-200 pt-3 dark:border-slate-600">
          <label class="block text-xs font-medium text-slate-500 mb-1 dark:text-slate-400">Date taken</label>
          <div class="flex gap-1">
            <input
              v-model="editDate"
              type="date"
              class="flex-1 rounded-lg border border-gray-300 px-2 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-700 dark:text-slate-100"
            />
            <button
              @click="saveDate"
              :disabled="isSavingDate"
              class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
            >
              Save
            </button>
          </div>
        </div>

        <div class="border-t border-gray-200 pt-3 dark:border-slate-600">
          <div class="flex flex-wrap gap-1.5 mb-3">
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
        </div>

        <button
          v-if="props.canDelete"
          @click="showDeleteConfirm = true"
          class="mt-3 w-full flex items-center justify-center gap-1.5 rounded-lg border border-red-200 px-3 py-2 text-sm text-red-600 hover:bg-red-50 dark:border-red-800 dark:text-red-400 dark:hover:bg-red-900/30"
        >
          <Trash2 class="h-4 w-4" />
          Delete photo
        </button>
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
