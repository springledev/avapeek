<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, watch } from 'vue'
import { fetchOriginal, cleanupComponent } from '@/services/storage'
import { useChildStore } from '@/stores/child'
import { decryptBlob, revokeObjectURL } from '@/crypto/encrypt'
import TagChip from '@/components/TagChip.vue'
import type { Photo } from '@/types/photo'

const props = defineProps<{
  photo: Photo
  hasPrev: boolean
  hasNext: boolean
}>()

const emit = defineEmits<{
  close: []
  prev: []
  next: []
}>()

const childStore = useChildStore()
const componentKey = 'photoviewer'

const imageUrl = ref<string | null>(null)
const isLoading = ref(true)
const showInfo = ref(false)
const zoom = ref(1)
const exifData = ref<Record<string, unknown> | null>(null)

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
  if (event.key === 'Escape') emit('close')
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
    )
  } finally {
    isLoading.value = false
  }
}

watch(() => props.photo.id, () => {
  if (imageUrl.value) revokeObjectURL(imageUrl.value)
  imageUrl.value = null
  zoom.value = 1
  exifData.value = null
  showInfo.value = false
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
        <span class="text-white/50 text-sm">Decrypting…</span>
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
      <h3 class="text-lg font-semibold text-slate-900 mb-4">Details</h3>

      <div class="photo-viewer-meta">
        <div>
          <p class="photo-viewer-meta-label">Taken</p>
          <p class="photo-viewer-meta-value">{{ formatDate(photo.taken_at) }}</p>
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
          <div v-if="exifData.ISO">
            <p class="photo-viewer-meta-label">ISO</p>
            <p class="photo-viewer-meta-value">{{ exifData.ISO }}</p>
          </div>
          <div v-if="exifData.FocalLength">
            <p class="photo-viewer-meta-label">Focal length</p>
            <p class="photo-viewer-meta-value">{{ exifData.FocalLength }}</p>
          </div>
        </div>

        <div v-if="photo.tags && photo.tags.length > 0">
          <p class="photo-viewer-meta-label mt-2">Tags</p>
          <div class="photo-viewer-actions">
            <TagChip
              v-for="tag in photo.tags"
              :key="tag.id"
              :name="tag.name"
              :clickable="true"
              removable
              @remove="{}"
            />
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
