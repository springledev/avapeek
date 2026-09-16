<script setup lang="ts">
import { ref, computed } from 'vue'
import { X } from '@lucide/vue'
import { useChildStore } from '@/stores/child'
import UiSheet from '@/components/ui/UiSheet.vue'
import UiChip from '@/components/ui/UiChip.vue'
import UiButton from '@/components/ui/UiButton.vue'

const props = defineProps<{
  open: boolean
  files: File[]
}>()

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'upload', files: File[], childIds: number[]): void
}>()

const childStore = useChildStore()

const selectedChildIds = ref<number[]>(
  childStore.children.length === 1 ? [childStore.children[0]!.id] : [],
)

const objectUrls = computed(() => props.files.map((f) => URL.createObjectURL(f)))

function toggleChild(childId: number) {
  const idx = selectedChildIds.value.indexOf(childId)
  if (idx >= 0) selectedChildIds.value.splice(idx, 1)
  else selectedChildIds.value.push(childId)
}

function removeFile(index: number) {
  const newFiles = [...props.files]
  newFiles.splice(index, 1)
  if (newFiles.length === 0) emit('close')
}

function startUpload() {
  if (selectedChildIds.value.length === 0) return
  emit('upload', props.files, [...selectedChildIds.value])
}

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1048576) return `${(bytes / 1024).toFixed(0)} KB`
  return `${(bytes / 1048576).toFixed(1)} MB`
}
</script>

<template>
  <UiSheet :open="open" title="Review photos" @close="emit('close')">
    <div class="batch">
      <div v-if="childStore.children.length > 0" class="batch__kids">
        <UiChip
          v-for="child in childStore.children"
          :key="child.id"
          :label="child.name"
          tone="kid"
          :kid-id="child.id"
          :kid-color="child.avatar_color"
          :selected="selectedChildIds.includes(child.id)"
          @toggle="toggleChild(child.id)"
        />
      </div>

      <div class="batch__grid">
        <div v-for="(file, idx) in files" :key="idx" class="batch__item">
          <img :src="objectUrls[idx]" class="batch__thumb" alt="" />
          <button type="button" class="batch__remove u-focus-ring" @click="removeFile(idx)">
            <X :size="16" />
          </button>
          <div class="batch__meta">
            <p class="batch__name">{{ file.name }}</p>
            <p class="batch__size">{{ formatBytes(file.size) }}</p>
          </div>
        </div>
      </div>

      <p class="batch__summary">
        {{ files.length }} {{ files.length === 1 ? 'photo' : 'photos' }} ({{
          formatBytes(files.reduce((sum, f) => sum + f.size, 0))
        }})
      </p>

      <UiButton
        variant="accent"
        size="lg"
        :disabled="selectedChildIds.length === 0 || files.length === 0"
        class="batch__upload-btn"
        @click="startUpload"
      >
        Upload {{ files.length }} {{ files.length === 1 ? 'photo' : 'photos' }}
      </UiButton>
    </div>
  </UiSheet>
</template>

<style scoped>
.batch {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.batch__kids {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
}

.batch__grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
  gap: var(--space-3);
}

.batch__item {
  position: relative;
  aspect-ratio: 1;
  border-radius: var(--radius-photo);
  overflow: hidden;
  background: var(--color-surface-2);
}

.batch__thumb {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.batch__remove {
  position: absolute;
  top: var(--space-1);
  right: var(--space-1);
  width: 28px;
  height: 28px;
  border: 0;
  border-radius: 50%;
  background: rgb(0 0 0 / 0.6);
  color: #fff;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
}

.batch__meta {
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  padding: var(--space-1) var(--space-2);
  background: linear-gradient(transparent, rgb(0 0 0 / 0.6));
  color: #fff;
}

.batch__name {
  font-size: 11px;
  font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  margin: 0;
}

.batch__size {
  font-size: 10px;
  opacity: 0.8;
  margin: 0;
}

.batch__summary {
  font-size: var(--text-meta);
  color: var(--color-ink-soft);
  text-align: center;
  margin: 0;
}

.batch__upload-btn {
  width: 100%;
}
</style>
