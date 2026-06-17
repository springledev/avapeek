<script setup lang="ts">
import { computed } from 'vue'
import type { Photo } from '@/types/photo'

const props = defineProps<{
  photos: Photo[]
  uploaderName: string
  childName: string
  childColor: string | null
  relativeTime: string
}>()

const count = computed(() => props.photos.length)
const firstPhoto = computed(() => props.photos[0])
const tags = computed(() => {
  const allTags = props.photos.flatMap(p => p.tags || [])
  const unique = new Map(allTags.map(t => [t.id, t]))
  return [...unique.values()].slice(0, 3)
})

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1048576) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / 1048576).toFixed(1)} MB`
}
</script>

<template>
  <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-slate-600 dark:bg-slate-800">
    <div class="mb-3 flex items-center gap-2">
      <div
        class="flex h-8 w-8 items-center justify-center rounded-full text-xs font-bold text-white"
        :style="{ backgroundColor: childColor || '#6366f1' }"
      >
        {{ childName.charAt(0).toUpperCase() }}
      </div>
      <div class="flex-1 min-w-0">
        <p class="text-sm font-medium text-slate-900 dark:text-white">
          {{ uploaderName }} uploaded {{ count }} {{ count === 1 ? 'photo' : 'photos' }}
        </p>
        <p class="text-xs text-slate-500 dark:text-slate-400">
          of {{ childName }} &middot; {{ relativeTime }}
        </p>
      </div>
    </div>

    <div v-if="tags.length > 0" class="mb-2 flex flex-wrap gap-1">
      <span
        v-for="tag in tags"
        :key="tag.id"
        class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600 dark:bg-slate-700 dark:text-slate-300"
      >
        {{ tag.name }}
      </span>
    </div>

    <div class="flex gap-2 overflow-x-auto pb-1">
      <div
        v-for="photo in photos.slice(0, 8)"
        :key="photo.id"
        class="h-20 w-20 flex-shrink-0 overflow-hidden rounded-lg bg-slate-100 dark:bg-slate-700"
      >
        <div class="flex h-full w-full items-center justify-center text-xs text-slate-400">
          {{ formatBytes(photo.size_bytes) }}
        </div>
      </div>
      <div
        v-if="photos.length > 8"
        class="flex h-20 w-20 flex-shrink-0 items-center justify-center rounded-lg bg-slate-100 text-xs font-medium text-slate-500 dark:bg-slate-700 dark:text-slate-400"
      >
        +{{ photos.length - 8 }}
      </div>
    </div>
  </div>
</template>
