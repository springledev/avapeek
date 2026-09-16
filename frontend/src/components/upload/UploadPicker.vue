<script setup lang="ts">
import { ref } from 'vue'
import { Upload } from '@lucide/vue'
import { isHeic } from '@/crypto/heic'

const emit = defineEmits<{
  (e: 'select', files: File[]): void
}>()

const fileInput = ref<HTMLInputElement | null>(null)
const dragOver = ref(false)

function handleFileSelect(event: Event) {
  const input = event.target as HTMLInputElement
  if (input.files) {
    emit('select', Array.from(input.files))
  }
  input.value = ''
}

function handleFileDrop(event: DragEvent) {
  dragOver.value = false
  const droppedFiles = event.dataTransfer?.files
  if (droppedFiles) {
    const allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif']
    const valid = Array.from(droppedFiles).filter((f) => allowed.includes(f.type) || isHeic(f))
    emit('select', valid)
  }
}

function browseFiles() {
  fileInput.value?.click()
}
</script>

<template>
  <div
    class="picker"
    :class="{ 'picker--drag': dragOver }"
    @drop.prevent="handleFileDrop"
    @dragover.prevent="dragOver = true"
    @dragleave.prevent="dragOver = false"
  >
    <Upload :size="48" class="picker__icon" />
    <p class="picker__title">Tap to browse or drag photos here</p>
    <p class="picker__hint">JPEG, PNG, WebP, HEIC</p>
    <input
      ref="fileInput"
      type="file"
      accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif"
      multiple
      class="picker__input"
      @change="handleFileSelect"
    />
    <button type="button" class="picker__btn u-focus-ring" @click="browseFiles">
      Choose photos
    </button>
  </div>
</template>

<style scoped>
.picker {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: var(--space-12) var(--space-6);
  border: 2px dashed var(--color-border);
  border-radius: var(--radius-card);
  background: var(--color-surface);
  text-align: center;
  transition:
    border-color var(--dur-micro) var(--ease-soft),
    background var(--dur-micro) var(--ease-soft);
}

.picker--drag {
  border-color: var(--color-accent);
  background: var(--color-accent-soft);
}

.picker__icon {
  color: var(--color-accent-ink);
  margin-bottom: var(--space-4);
}

.picker__title {
  font-size: var(--text-body);
  font-weight: 600;
  color: var(--color-ink);
  margin: 0 0 var(--space-2);
}

.picker__hint {
  font-size: var(--text-meta);
  color: var(--color-ink-soft);
  margin: 0 0 var(--space-4);
}

.picker__input {
  display: none;
}

.picker__btn {
  padding: var(--space-3) var(--space-6);
  border: 0;
  border-radius: var(--radius-card);
  background: var(--color-accent);
  color: var(--color-on-accent);
  font-family: inherit;
  font-size: var(--text-body);
  font-weight: 700;
  cursor: pointer;
  min-height: var(--target-min);
}
</style>
