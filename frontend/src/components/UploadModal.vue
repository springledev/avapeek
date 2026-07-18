<script setup lang="ts">
import { X, Upload, Plus } from '@lucide/vue'
import { ref } from 'vue'
import { api } from '@/services/api'
import { useChildStore } from '@/stores/child'
import { generatePEK, wrapPEKWithDEK } from '@/crypto/keychain'
import { generateThumbnail } from '@/crypto/thumbnails'
import { encryptBuffer } from '@/crypto/encrypt'
import { decodeHeic, isHeic } from '@/crypto/heic'
import { toast } from 'vue-sonner'

const emit = defineEmits<{
  close: []
  'upload-complete': []
}>()

const childStore = useChildStore()

const selectedChildIds = ref<number[]>(
  childStore.children.length === 1 ? [childStore.children[0]!.id] : [],
)
const files = ref<File[]>([])
const tagInput = ref('')
const tags = ref<string[]>([])
const isProcessing = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)
const processingText = ref('')
const dragOver = ref(false)

function toggleChild(childId: number) {
  const idx = selectedChildIds.value.indexOf(childId)
  if (idx >= 0) {
    selectedChildIds.value.splice(idx, 1)
  } else {
    selectedChildIds.value.push(childId)
  }
}

function addTag() {
  const tag = tagInput.value.trim()
  if (tag && !tags.value.includes(tag)) {
    tags.value.push(tag)
  }
  tagInput.value = ''
}

function removeTag(tag: string) {
  tags.value = tags.value.filter(t => t !== tag)
}

function handleFileDrop(event: DragEvent) {
  dragOver.value = false
  const droppedFiles = event.dataTransfer?.files
  if (droppedFiles) {
    addFiles(Array.from(droppedFiles))
  }
}

function handleFileSelect(event: Event) {
  const input = event.target as HTMLInputElement
  if (input.files) {
    addFiles(Array.from(input.files))
  }
  input.value = ''
}

function addFiles(newFiles: File[]) {
  const allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif']
  const valid = newFiles.filter(f => allowed.includes(f.type) || isHeic(f))
  files.value.push(...valid)
}

function removeFile(index: number) {
  files.value.splice(index, 1)
}

function browseFiles() {
  fileInput.value?.click()
}

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1048576) return `${(bytes / 1024).toFixed(0)} KB`
  return `${(bytes / 1048576).toFixed(1)} MB`
}

const totalSize = ref(0)

async function startUpload() {
  if (selectedChildIds.value.length === 0) {
    toast.error('Please select at least one child.')
    return
  }
  if (files.value.length === 0) {
    toast.error('Please select photos to upload.')
    return
  }

  isProcessing.value = true

  try {
    for (let i = 0; i < files.value.length; i++) {
      const f = files.value[i]!
      processingText.value = `Processing ${i + 1} of ${files.value.length}: ${f.name}`
      await new Promise(r => setTimeout(r, 0))
      let bitmap: ImageBitmap
      if (isHeic(f)) {
        bitmap = await decodeHeic(f)
      } else {
        bitmap = await createImageBitmap(f)
      }

      const { thumb: thumbnailBlob } = await generateThumbnail(bitmap)

      processingText.value = `Encrypting ${i + 1} of ${files.value.length}...`
      await new Promise(r => setTimeout(r, 0))

      const pek = await generatePEK()

      const rawBytes = await f.arrayBuffer()
      const { encrypted: encOriginal, iv: ivOriginal } = await encryptBuffer(rawBytes, pek)

      const thumbBytes = await thumbnailBlob.arrayBuffer()
      const { encrypted: encThumbnail, iv: ivThumbnail } = await encryptBuffer(thumbBytes, pek)

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
      for (const childId of selectedChildIds.value) {
        const dek = await childStore.ensureDEK(childId)
        children[childId] = await wrapPEKWithDEK(pek, dek)
      }

      processingText.value = `Uploading ${i + 1} of ${files.value.length}...`
      await new Promise(r => setTimeout(r, 0))

      const formData = new FormData()
      formData.append('file', new Blob([encOriginal]), f.name)
      formData.append('iv', ivOriginal)
      formData.append('thumbnail', new Blob([encThumbnail]), 'thumb.webp')
      formData.append('iv_thumbnail', ivThumbnail)
      formData.append('original_mime', originalMime)
      formData.append('size_bytes', String(f.size))
      formData.append('width', String(bitmap.width))
      formData.append('height', String(bitmap.height))
      formData.append('children', JSON.stringify(children))
      if (tags.value.length > 0) {
        for (const tag of tags.value) {
          formData.append('tags[]', tag)
        }
      }

      await api.post('/photos/upload', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })

      bitmap.close()
    }

    toast.success(`Uploaded ${files.value.length} ${files.value.length === 1 ? 'photo' : 'photos'} successfully!`)
    emit('upload-complete')
    emit('close')
  } catch (err: any) {
    console.error('Upload error:', err)
    toast.error(err.response?.data?.message || 'Upload failed. Please try again.')
  } finally {
    isProcessing.value = false
    processingText.value = ''
  }
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="emit('close')">
    <div class="w-full max-w-lg rounded-xl bg-white shadow-2xl dark:bg-slate-800">
      <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4 dark:border-slate-600">
        <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Upload Photos</h2>
        <button @click="emit('close')" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
          <X class="h-5 w-5" />
        </button>
      </div>

      <div v-if="!isProcessing" class="space-y-4 p-5">
        <div>
          <label class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Children</label>
          <div v-if="childStore.children.length > 0" class="flex flex-wrap gap-2">
            <button
              v-for="child in childStore.children"
              :key="child.id"
              @click="toggleChild(child.id)"
              :class="[
                'inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-sm font-medium transition-colors',
                selectedChildIds.includes(child.id)
                  ? 'text-white'
                  : 'border border-slate-300 bg-white text-slate-600 hover:bg-slate-50 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-300',
              ]"
              :style="selectedChildIds.includes(child.id) ? { backgroundColor: child.avatar_color || '#6366f1' } : {}"
            >
              {{ child.name }}
            </button>
          </div>
          <p v-else class="text-sm text-slate-400">Create a child profile first.</p>
        </div>

        <div>
          <label class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Tags (optional)</label>
          <div class="flex flex-wrap gap-1.5 mb-2">
            <span
              v-for="tag in tags"
              :key="tag"
              class="inline-flex items-center gap-1 rounded-full bg-indigo-100 px-2.5 py-0.5 text-xs font-medium text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300"
            >
              {{ tag }}
              <button @click="removeTag(tag)" class="text-indigo-400 hover:text-indigo-600">&times;</button>
            </span>
          </div>
          <input
            v-model="tagInput"
            type="text"
            placeholder="Add a tag..."
            class="w-full rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm placeholder-gray-400 focus:border-indigo-500 focus:outline-none dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200 dark:placeholder-slate-400"
            @keydown.enter.prevent="addTag"
          />
        </div>

        <div
          @drop.prevent="handleFileDrop"
          @dragover.prevent="dragOver = true"
          @dragleave.prevent="dragOver = false"
          :class="[
            'rounded-lg border-2 border-dashed p-8 text-center transition-colors',
            dragOver
              ? 'border-indigo-400 bg-indigo-50 dark:bg-indigo-900/20'
              : 'border-gray-300 hover:border-gray-400 dark:border-slate-600',
          ]"
        >
          <Upload class="mx-auto mb-3 h-10 w-10 text-slate-400" />
          <p class="mb-1 text-sm text-slate-600 dark:text-slate-300">Drag photos here or tap to browse</p>
          <p class="text-xs text-slate-400">JPEG, PNG, WebP, HEIC</p>
          <input
            ref="fileInput"
            type="file"
            accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif"
            multiple
            class="hidden"
            @change="handleFileSelect"
          />
          <button
            @click="browseFiles"
            class="mt-3 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
          >
            Browse Files
          </button>
        </div>

        <div v-if="files.length > 0" class="max-h-40 space-y-1 overflow-y-auto">
          <div v-for="(file, idx) in files" :key="idx" class="flex items-center justify-between rounded-md bg-slate-50 px-3 py-2 dark:bg-slate-700/50">
            <div class="flex-1 min-w-0">
              <p class="truncate text-sm text-slate-700 dark:text-slate-200">{{ file.name }}</p>
              <p class="text-xs text-slate-400">{{ formatBytes(file.size) }}</p>
            </div>
            <button @click="removeFile(idx)" class="ml-2 text-slate-400 hover:text-red-500">
              <X class="h-4 w-4" />
            </button>
          </div>
        </div>

        <div v-if="files.length > 0" class="text-sm text-slate-500 dark:text-slate-400">
          {{ files.length }} {{ files.length === 1 ? 'photo' : 'photos' }} selected
          ({{ formatBytes(files.reduce((sum, f) => sum + f.size, 0)) }})
        </div>

        <button
          @click="startUpload"
          :disabled="selectedChildIds.length === 0 || files.length === 0"
          class="w-full rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white transition-colors hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
        >
          Upload
        </button>
      </div>

      <div v-else class="flex flex-col items-center justify-center p-10 text-center">
        <div class="mb-4 h-10 w-10 animate-spin rounded-full border-3 border-indigo-600 border-t-transparent" />
        <p class="text-sm font-medium text-slate-700 dark:text-slate-200">Processing & encrypting your photos...</p>
        <p class="mt-1 text-xs text-slate-500">{{ processingText }}</p>
      </div>
    </div>
  </div>
</template>
