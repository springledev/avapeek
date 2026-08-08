<script setup lang="ts">
import { Image } from '@lucide/vue'
import { ref, computed, onMounted, watch } from 'vue'
import { api } from '@/services/api'
import { preloadOriginal } from '@/services/storage'
import { useFeedStore } from '@/stores/feed'
import { useChildStore } from '@/stores/child'
import { usePrefetch } from '@/composables/usePrefetch'
import FilterBar from '@/components/FilterBar.vue'
import MonthStrip from '@/components/MonthStrip.vue'
import PhotoCard from '@/components/PhotoCard.vue'
import PhotoViewer from '@/components/PhotoViewer.vue'
import QuotaBar from '@/components/QuotaBar.vue'
import { toast } from 'vue-sonner'
import type { Photo } from '@/types/photo'

const feedStore = useFeedStore()
const childStore = useChildStore()
const { prefetchNextPage } = usePrefetch()

const selectedPhoto = ref<Photo | null>(null)
const selectedPhotoIndex = ref(0)
const photoViewerKey = ref(0)

const hasPrev = computed(() => selectedPhotoIndex.value > 0)
const hasNext = computed(() => selectedPhotoIndex.value < feedStore.photos.length - 1)
const canDelete = computed(() => true) // Simplified for now
const canEdit = computed(() => true) // Simplified; same as delete: uploader or child admin

const selectedMonth = computed(() => {
  if (!feedStore.filters.dateFrom) return null
  const d = new Date(feedStore.filters.dateFrom)
  return d.toLocaleString('default', { month: 'long', year: 'numeric' })
})

const availableMonths = computed(() => {
  const months = new Set<string>()
  for (const photo of feedStore.photos) {
    if (photo.taken_at) {
      months.add(new Date(photo.taken_at).toLocaleString('default', { month: 'long', year: 'numeric' }))
    }
  }
  return [...months].sort((a, b) => new Date(b).getTime() - new Date(a).getTime())
})

function selectMonth(month: string | null) {
  if (month) {
    const d = new Date(month)
    const from = new Date(d.getFullYear(), d.getMonth(), 1).toISOString().split('T')[0]!
    const to = new Date(d.getFullYear(), d.getMonth() + 1, 0).toISOString().split('T')[0]!
    feedStore.setDateRange(from, to)
  } else {
    feedStore.setDateRange(null, null)
  }
  feedStore.fetchPhotos()
}

onMounted(async () => {
  if (feedStore.photos.length === 0) {
    await feedStore.fetchPhotos()
  }
})

watch(() => feedStore.photos.length, () => {
  if ('requestIdleCallback' in window) {
    (window as any).requestIdleCallback(() => prefetchNextPage(), { timeout: 3000 })
  } else {
    setTimeout(() => prefetchNextPage(), 1000)
  }
})

function schedulePreload() {
  requestAnimationFrame(async () => {
    if (hasNext.value) {
      const next = feedStore.photos[selectedPhotoIndex.value + 1]
      if (next) preloadOriginal(next).catch(() => {})
    }
    if (hasPrev.value) {
      const prev = feedStore.photos[selectedPhotoIndex.value - 1]
      if (prev) preloadOriginal(prev).catch(() => {})
    }
  })
}

function openPhoto(photo: Photo) {
  const idx = feedStore.photos.findIndex(p => p.id === photo.id)
  selectedPhotoIndex.value = idx >= 0 ? idx : 0
  selectedPhoto.value = photo
  photoViewerKey.value++
  schedulePreload()
}

function closePhoto() {
  selectedPhoto.value = null
}

function prevPhoto() {
  if (selectedPhotoIndex.value > 0) {
    selectedPhotoIndex.value--
    selectedPhoto.value = feedStore.photos[selectedPhotoIndex.value]!
    photoViewerKey.value++
    schedulePreload()
  }
}

function nextPhoto() {
  if (selectedPhotoIndex.value < feedStore.photos.length - 1) {
    selectedPhotoIndex.value++
    selectedPhoto.value = feedStore.photos[selectedPhotoIndex.value]!
    photoViewerKey.value++
    schedulePreload()
  }
}

async function deletePhoto(photoId: number) {
  try {
    await api.delete(`/photos/${photoId}`)
    toast.success('Photo deleted')
    selectedPhoto.value = null
    feedStore.fetchPhotos()
  } catch (err: any) {
    toast.error(err.response?.data?.message || 'Failed to delete photo')
  }
}

async function addTag(photoId: number, tagName: string) {
  try {
    const res = await api.post(`/photos/${photoId}/tags`, { tag_ids: [] })
    toast.success('Tag added')
  } catch (err: any) {
    toast.error(err.response?.data?.message || 'Failed to add tag')
  }
}

async function removeTag(photoId: number, tagId: number) {
  try {
    await api.delete(`/photos/${photoId}/tags/${tagId}`)
    toast.success('Tag removed')
  } catch (err: any) {
    toast.error(err.response?.data?.message || 'Failed to remove tag')
  }
}

async function updateDate(photoId: number, takenAt: string) {
  try {
    await api.patch(`/photos/${photoId}`, { taken_at: takenAt })
    toast.success('Date updated')
    feedStore.fetchPhotos()
  } catch (err: any) {
    toast.error(err.response?.data?.message || 'Failed to update date')
  }
}

function onScroll(event: Event) {
  const el = event.target as HTMLElement
  if (!el) return
  const { scrollTop, scrollHeight, clientHeight } = el.closest('.feed-scroll') || document.documentElement
  if (scrollHeight - scrollTop - clientHeight < 400) {
    feedStore.fetchMore()
  }
}
</script>

<template>
  <div class="feed-scroll">
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-slate-900 dark:text-white">Photos</h1>
      <p v-if="childStore.children.length === 0" class="mt-1 text-sm text-slate-500 dark:text-slate-400">
        Create a child profile to start uploading photos.
      </p>
    </div>

    <div v-if="childStore.children.length > 0" class="mb-6">
      <FilterBar />
    </div>

    <MonthStrip
      :months="availableMonths"
      :selected="selectedMonth"
      @select-month="selectMonth"
    />

    <div class="mb-6">
      <QuotaBar />
    </div>

    <div v-if="feedStore.isLoading && feedStore.photos.length === 0" class="grid grid-cols-3 gap-3 md:grid-cols-4">
      <div v-for="i in 12" :key="i" class="aspect-square animate-pulse rounded-xl bg-slate-200 dark:bg-slate-700" />
    </div>

    <div v-else-if="feedStore.photos.length === 0" class="py-16 text-center">
      <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-slate-100 dark:bg-slate-700">
        <Image class="h-8 w-8 text-slate-400" />
      </div>
      <p class="text-lg font-medium text-slate-900 dark:text-white">No photos yet</p>
      <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Upload photos to start building your timeline.</p>
    </div>

    <div v-else @scroll.passive="onScroll">
      <div class="grid grid-cols-3 gap-3 md:grid-cols-4">
        <PhotoCard
          v-for="photo in feedStore.photos"
          :key="photo.id"
          :photo="photo"
          @click="openPhoto(photo)"
        />
      </div>

      <div v-if="feedStore.isLoading" class="mt-4 flex justify-center">
        <div class="h-6 w-6 animate-spin rounded-full border-2 border-indigo-600 border-t-transparent" />
      </div>

      <div v-if="!feedStore.hasMore && feedStore.photos.length > 0" class="mt-8 mb-8 text-center text-sm text-slate-400 dark:text-slate-500">
        All photos loaded
      </div>
    </div>

    <PhotoViewer
      v-if="selectedPhoto"
      :key="photoViewerKey"
      :photo="selectedPhoto"
      :has-prev="hasPrev"
      :has-next="hasNext"
      :can-delete="canDelete"
      :can-edit="canEdit"
      :child-birth-date="null"
      :all-tags="[]"
      @close="closePhoto"
      @prev="prevPhoto"
      @next="nextPhoto"
      @delete="deletePhoto"
      @update-date="updateDate"
      @tag-added="addTag"
      @tag-removed="removeTag"
    />
  </div>
</template>
