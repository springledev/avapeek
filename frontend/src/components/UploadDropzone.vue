<script setup lang="ts">
import { ref, computed } from 'vue'
import { generateThumbnails, encodeOriginal } from '@/crypto/thumbnails'
import { encryptBuffer, encryptString } from '@/crypto/encrypt'
import { isHeic, decodeHeic } from '@/crypto/heic'
import { api } from '@/services/api'
import { useChildStore } from '@/stores/child'
import type { Photo } from '@/types/photo'

interface SelectedFile {
  file: File
  name: string
  size: number
  isHeic: boolean
}

interface ProgressInfo {
  current: number
  total: number
  fileName: string
  stage: 'encoding' | 'encrypting' | 'uploading'
  error?: string
}

const props = defineProps<{
  childId: number
}>()

const emit = defineEmits<{
  uploaded: [photo: Photo]
  done: []
}>()

const childStore = useChildStore()

const fileInputRef = ref<HTMLInputElement | null>(null)
const selectedFiles = ref<SelectedFile[]>([])
const tagInput = ref('')
const isProcessing = ref(false)
const progress = ref<ProgressInfo | null>(null)
const isDragOver = ref(false)

const allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif']

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

const totalSize = computed(() =>
  selectedFiles.value.reduce((sum, f) => sum + f.size, 0),
)

const totalSizeFormatted = computed(() => formatBytes(totalSize.value))

const tags = computed(() =>
  tagInput.value
    .split(',')
    .map((t) => t.trim())
    .filter((t) => t.length > 0),
)

function handleFiles(files: FileList | File[]) {
  const fileArray = Array.from(files)
  for (const file of fileArray) {
    if (allowedTypes.includes(file.type) || isHeic(file)) {
      selectedFiles.value.push({
        file,
        name: file.name,
        size: file.size,
        isHeic: isHeic(file),
      })
    }
  }
}

function handleDrop(event: DragEvent) {
  isDragOver.value = false
  if (event.dataTransfer?.files) {
    handleFiles(event.dataTransfer.files)
  }
}

function onFilePick(event: Event) {
  const input = event.target as HTMLInputElement
  if (input.files) {
    handleFiles(input.files)
  }
}

function removeFile(index: number) {
  selectedFiles.value.splice(index, 1)
}

async function decodeImage(file: File): Promise<ImageBitmap> {
  if (isHeic(file)) {
    return await decodeHeic(file)
  }
  return await createImageBitmap(file)
}

async function processSingleFile(
  file: File,
  index: number,
  total: number,
): Promise<void> {
  const dek = await childStore.ensureDEK(props.childId)

  progress.value = {
    current: index + 1,
    total,
    fileName: file.name,
    stage: 'encoding',
  }
  await new Promise((r) => setTimeout(r, 0))

  let bitmap: ImageBitmap | null = null
  try {
    bitmap = await decodeImage(file)
  } catch {
    throw new Error(
      `HEIC decode failed for ${file.name}. Try converting to JPEG before uploading.`,
    )
  }

  const [thumbnails, originalBlob] = await Promise.all([
    generateThumbnails(bitmap),
    encodeOriginal(bitmap),
  ])

  const { width, height } = bitmap
  bitmap.close()
  bitmap = null

  let exifData: Record<string, unknown> = {}
  try {
    const exifr = await import('exifr')
    exifData = (await exifr.parse(file, {
      pick: ['Make', 'Model', 'LensModel', 'ISO', 'FNumber', 'ExposureTime', 'FocalLength', 'DateTimeOriginal', 'CreateDate', 'DateTime'],
    })) || {}
  } catch {
    // EXIF extraction is best-effort
  }

  progress.value = {
    current: index + 1,
    total,
    fileName: file.name,
    stage: 'encrypting',
  }
  await new Promise((r) => setTimeout(r, 0))

  const originalBuffer = await originalBlob.arrayBuffer()
  const smBuffer = await thumbnails.sm.arrayBuffer()
  const mdBuffer = await thumbnails.md.arrayBuffer()
  const lgBuffer = await thumbnails.lg.arrayBuffer()

  const [
    encryptedOriginal,
    encryptedSm,
    encryptedMd,
    encryptedLg,
  ] = await Promise.all([
    encryptBuffer(originalBuffer, dek),
    encryptBuffer(smBuffer, dek),
    encryptBuffer(mdBuffer, dek),
    encryptBuffer(lgBuffer, dek),
  ])

  const encryptedExif = await encryptString(JSON.stringify(exifData), dek)

  progress.value = {
    current: index + 1,
    total,
    fileName: file.name,
    stage: 'uploading',
  }
  await new Promise((r) => setTimeout(r, 0))

  const formData = new FormData()
  formData.append('file', new Blob([encryptedOriginal.encrypted]), 'original.webp.enc')
  formData.append('iv', encryptedOriginal.iv)
  formData.append('thumbnail_sm', new Blob([encryptedSm.encrypted]), 'sm.webp.enc')
  formData.append('iv_sm', encryptedSm.iv)
  formData.append('thumbnail_md', new Blob([encryptedMd.encrypted]), 'md.webp.enc')
  formData.append('iv_md', encryptedMd.iv)
  formData.append('thumbnail_lg', new Blob([encryptedLg.encrypted]), 'lg.webp.enc')
  formData.append('iv_lg', encryptedLg.iv)
  formData.append('exif_encrypted', encryptedExif.encrypted)
  formData.append('iv_exif', encryptedExif.iv)
  formData.append('mime_type', 'image/webp')
  formData.append('size_bytes', String(file.size))
  formData.append('width', String(width))
  formData.append('height', String(height))

  const dateValue = exifData.DateTimeOriginal ?? exifData.CreateDate ?? exifData.DateTime ?? new Date(file.lastModified)

  formData.append('taken_at', (dateValue as Date).toISOString())

  for (const tag of tags.value) {
    formData.append('tags[]', tag)
  }

  const response = await api.post(
    `/children/${props.childId}/photos/upload`,
    formData,
    {
      headers: { 'Content-Type': 'multipart/form-data' },
    },
  )

  emit('uploaded', response.data.data)
}

async function startUpload() {
  if (selectedFiles.value.length === 0) return

  isProcessing.value = true
  const files = [...selectedFiles.value]

  for (let i = 0; i < files.length; i++) {
    try {
      await processSingleFile(files[i]!.file, i, files.length)
    } catch (err: unknown) {
      const message = err instanceof Error ? err.message : 'Upload failed'
      progress.value = {
        current: i + 1,
        total: files.length,
        fileName: files[i]!.name,
        stage: 'encoding',
        error: message,
      }
      isProcessing.value = false
      return
    }
  }

  isProcessing.value = false
  selectedFiles.value = []
  tagInput.value = ''
  progress.value = null
  emit('done')
}
</script>

<template>
  <div>
    <!-- Processing Overlay -->
    <Teleport to="body">
      <div v-if="isProcessing" class="processing-overlay">
        <div class="processing-card">
          <h2 class="processing-title">Processing & encrypting your photos…</h2>

          <div v-if="progress" class="mb-4">
            <div class="flex items-center justify-between gap-2 mb-1">
              <span class="progress-file-name">{{ progress.fileName }}</span>
              <span class="progress-stage">{{
                progress.stage === 'encoding'
                  ? 'Encoding'
                  : progress.stage === 'encrypting'
                    ? 'Encrypting'
                    : 'Uploading'
              }}</span>
            </div>
            <div class="progress-bar-track">
              <div
                class="progress-bar-fill"
                :style="{ width: `${(progress.current / progress.total) * 100}%` }"
              />
            </div>
            <div class="upload-summary">
              <span>Processing {{ progress.current }} of {{ progress.total }} photos</span>
            </div>
          </div>

          <div v-if="progress?.error" class="alert-error">
            {{ progress.error }}
          </div>

          <div v-if="progress?.error" class="flex gap-3 mt-4">
            <button
              class="btn-primary"
              @click="isProcessing = false"
            >
              Dismiss
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- File Selected List -->
    <div v-if="selectedFiles.length > 0" class="file-list">
      <div v-for="(f, i) in selectedFiles" :key="i" class="file-item">
        <span class="file-name">{{ f.name }}</span>
        <span v-if="f.isHeic" class="heic-badge">HEIC</span>
        <span class="file-size">{{ formatBytes(f.size) }}</span>
        <button
          class="remove-file-btn"
          @click="removeFile(i)"
          :disabled="isProcessing"
          aria-label="Remove file"
        >
          ✕
        </button>
      </div>
      <div class="text-xs text-slate-500 mt-2">
        {{ selectedFiles.length }} file{{ selectedFiles.length > 1 ? 's' : '' }}
        &mdash; {{ totalSizeFormatted }}
      </div>
    </div>

    <!-- Dropzone -->
    <div
      class="dropzone"
      :class="{ 'dropzone-active': isDragOver }"
      @dragenter.prevent="isDragOver = true"
      @dragleave.prevent="isDragOver = false"
      @dragover.prevent="isDragOver = true"
      @drop.prevent="handleDrop"
      @click="fileInputRef?.click()"
    >
      <svg v-if="selectedFiles.length" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="dropzone-icon">
        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
        <polyline points="17 8 12 3 7 8"/>
        <line x1="12" y1="3" x2="12" y2="15"/>
      </svg>
      <svg v-else xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="dropzone-icon">
        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
        <polyline points="17 8 12 3 7 8"/>
        <line x1="12" y1="3" x2="12" y2="15"/>
      </svg>
      <p class="dropzone-text">
        <template v-if="selectedFiles.length">Add more photos</template>
        <template v-else>Drag & drop photos here</template>
      </p>
      <p class="dropzone-hint">or click to browse &middot; JPEG, PNG, WebP, HEIC</p>
    </div>

    <input
      ref="fileInputRef"
      type="file"
      accept="image/jpeg,image/png,image/webp,image/heic,image/heif"
      multiple
      class="hidden"
      @change="onFilePick"
    />

    <!-- Tags Input -->
    <div class="mt-4">
      <label class="form-label">Tags (optional)</label>
      <input
        v-model="tagInput"
        type="text"
        class="tag-input mt-1"
        placeholder="e.g. Spain 2026, Beach"
      />
      <p class="text-xs text-slate-400 mt-1">Separate tags with commas</p>
    </div>

    <!-- Upload Button -->
    <button
      class="btn-primary mt-6"
      :disabled="selectedFiles.length === 0 || isProcessing"
      @click="startUpload"
    >
      <template v-if="isProcessing">
        <span class="spinner mr-2" />
        Uploading…
      </template>
      <template v-else>
        Upload {{ selectedFiles.length ? `${selectedFiles.length} photo${selectedFiles.length > 1 ? 's' : ''}` : '' }}
      </template>
    </button>
  </div>
</template>
