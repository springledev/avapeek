<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { fetchThumbnail, cleanupComponent, getPEKForPhoto } from '@/services/storage'
import type { Photo } from '@/types/photo'

const props = defineProps<{
  photos: Photo[]
  uploaderName: string
  childName: string
  childColor: string | null
  relativeTime: string
}>()

const emit = defineEmits<{
  select: [photo: Photo]
}>()

const thumbnails = ref<Map<number, string>>(new Map())
const loaded = ref<Set<number>>(new Set())

const count = computed(() => props.photos.length)
const tags = computed(() => {
  const allTags = props.photos.flatMap(p => p.tags || [])
  const unique = new Map(allTags.map(t => [t.id, t]))
  return [...unique.values()].slice(0, 3)
})

const displayThumbnails = computed(() => props.photos.slice(0, 8))

async function loadThumbnail(photo: Photo) {
  if (loaded.value.has(photo.id)) return
  loaded.value.add(photo.id)
  try {
    const pek = await getPEKForPhoto(photo.children)
    if (!pek) return
    const url = await fetchThumbnail(
      photo.id,
      pek,
      photo.iv_thumbnail,
      'recents',
      photo.thumbnail_url,
    )
    thumbnails.value.set(photo.id, url)
  } catch {
    // Silent fail — just show placeholder
  }
}

onMounted(() => {
  for (const photo of displayThumbnails.value) {
    loadThumbnail(photo)
  }
})

onBeforeUnmount(() => {
  cleanupComponent('recents')
})
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
      <button
        v-for="photo in displayThumbnails"
        :key="photo.id"
        @click="emit('select', photo)"
        class="h-20 w-20 flex-shrink-0 overflow-hidden rounded-lg bg-slate-100 dark:bg-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500"
      >
        <img
          v-if="thumbnails.get(photo.id)"
          :src="thumbnails.get(photo.id)"
          class="h-full w-full object-cover"
          alt=""
          loading="lazy"
        />
        <div v-else class="flex h-full w-full items-center justify-center">
          <div class="h-5 w-5 animate-pulse rounded bg-slate-200 dark:bg-slate-600" />
        </div>
      </button>
      <div
        v-if="photos.length > 8"
        class="flex h-20 w-20 flex-shrink-0 items-center justify-center rounded-lg bg-slate-100 text-xs font-medium text-slate-500 dark:bg-slate-700 dark:text-slate-400"
      >
        +{{ photos.length - 8 }}
      </div>
    </div>
  </div>
</template>
