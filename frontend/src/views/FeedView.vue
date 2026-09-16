<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { preloadOriginal } from '@/services/storage'
import { useFeedStore } from '@/stores/feed'
import { usePrefetch } from '@/composables/usePrefetch'
import { Image } from '@lucide/vue'
import FeedToolbar from '@/components/feed/FeedToolbar.vue'
import PhotoTile from '@/components/feed/PhotoTile.vue'
import PhotoViewer from '@/components/PhotoViewer.vue'
import UiEmptyState from '@/components/ui/UiEmptyState.vue'
import { MSG } from '@/lib/messages'
import type { Photo } from '@/types/photo'

const feedStore = useFeedStore()
const { prefetchNextPage } = usePrefetch()

const selectedPhoto = ref<Photo | null>(null)
const selectedPhotoIndex = ref(0)
const photoViewerKey = ref(0)

const hasPrev = computed(() => selectedPhotoIndex.value > 0)
const hasNext = computed(() => selectedPhotoIndex.value < feedStore.photos.length - 1)
const canDelete = computed(() => true)
const canEdit = computed(() => true)

interface MonthGroup {
  key: string
  label: string
  photos: Photo[]
}

const groupedPhotos = computed<MonthGroup[]>(() => {
  const groups = new Map<string, Photo[]>()
  for (const photo of feedStore.photos) {
    const date = photo.taken_at || photo.created_at
    const d = new Date(date)
    const key = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
    if (!groups.has(key)) groups.set(key, [])
    groups.get(key)!.push(photo)
  }
  return [...groups.entries()]
    .sort(([a], [b]) => b.localeCompare(a))
    .map(([key, photos]) => {
      const d = new Date(photos[0]!.taken_at || photos[0]!.created_at)
      return { key, label: d.toLocaleString('default', { month: 'long', year: 'numeric' }), photos }
    })
})

const hasActiveFilters = computed(
  () =>
    feedStore.filters.childIds.length > 0 ||
    feedStore.filters.tags.length > 0 ||
    feedStore.filters.dateFrom !== null,
)

onMounted(async () => {
  if (feedStore.photos.length === 0) {
    await feedStore.fetchPhotos()
  }
})

watch(
  () => feedStore.photos.length,
  () => {
    if ('requestIdleCallback' in window) {
      ;(
        window as Window & {
          requestIdleCallback: (cb: () => void, opts?: { timeout: number }) => number
        }
      ).requestIdleCallback(() => prefetchNextPage(), { timeout: 3000 })
    } else {
      setTimeout(() => prefetchNextPage(), 1000)
    }
  },
)

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
  const idx = feedStore.photos.findIndex((p) => p.id === photo.id)
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
  const { api } = await import('@/services/api')
  const { toast } = await import('vue-sonner')
  try {
    await api.delete(`/photos/${photoId}`)
    toast.success('Photo deleted')
    selectedPhoto.value = null
    feedStore.fetchPhotos()
  } catch (err: unknown) {
    const message =
      err instanceof Error && 'response' in err
        ? (err as { response?: { data?: { message?: string } } }).response?.data?.message
        : undefined
    toast.error(message || 'Failed to delete photo')
  }
}

async function addTag(photoId: number, tagName: string) {
  void tagName
  const { api } = await import('@/services/api')
  const { toast } = await import('vue-sonner')
  try {
    await api.post(`/photos/${photoId}/tags`, { tag_ids: [] })
    toast.success('Tag added')
  } catch (err: unknown) {
    const message =
      err instanceof Error && 'response' in err
        ? (err as { response?: { data?: { message?: string } } }).response?.data?.message
        : undefined
    toast.error(message || 'Failed to add tag')
  }
}

async function removeTag(photoId: number, tagId: number) {
  const { api } = await import('@/services/api')
  const { toast } = await import('vue-sonner')
  try {
    await api.delete(`/photos/${photoId}/tags/${tagId}`)
    toast.success('Tag removed')
  } catch (err: unknown) {
    const message =
      err instanceof Error && 'response' in err
        ? (err as { response?: { data?: { message?: string } } }).response?.data?.message
        : undefined
    toast.error(message || 'Failed to remove tag')
  }
}

async function updateDate(photoId: number, takenAt: string) {
  const { api } = await import('@/services/api')
  const { toast } = await import('vue-sonner')
  try {
    await api.patch(`/photos/${photoId}`, { taken_at: takenAt })
    toast.success('Date updated')
    feedStore.fetchPhotos()
  } catch (err: unknown) {
    const message =
      err instanceof Error && 'response' in err
        ? (err as { response?: { data?: { message?: string } } }).response?.data?.message
        : undefined
    toast.error(message || 'Failed to update date')
  }
}
</script>

<template>
  <div>
    <h1 class="feed-title">Photos</h1>

    <FeedToolbar />

    <div v-if="feedStore.isLoading && feedStore.photos.length === 0" class="u-grid">
      <div v-for="i in 8" :key="i" class="tile-skeleton" />
    </div>

    <UiEmptyState
      v-else-if="feedStore.photos.length === 0 && !hasActiveFilters"
      :title="MSG.feedEmpty"
    >
      <template #icon>
        <Image :size="48" />
      </template>
    </UiEmptyState>

    <UiEmptyState
      v-else-if="feedStore.photos.length === 0 && hasActiveFilters"
      title="No matches"
      message="Try adjusting your filters."
    />

    <div v-else>
      <div v-for="group in groupedPhotos" :key="group.key" class="feed-group">
        <h2 class="feed-group__heading">{{ group.label }}</h2>
        <div class="u-grid">
          <PhotoTile
            v-for="photo in group.photos"
            :key="photo.id"
            :photo="photo"
            @click="openPhoto(photo)"
          />
        </div>
      </div>

      <div v-if="feedStore.isLoading" class="feed-loading">
        <div class="feed-spinner" />
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

<style scoped>
.feed-title {
  font-size: var(--text-title);
  font-weight: 800;
  line-height: var(--lh-title);
  color: var(--color-ink);
  margin: 0 0 var(--space-4);
}

.u-grid {
  display: grid;
  grid-template-columns: repeat(var(--feed-cols), 1fr);
  gap: var(--feed-gap);
}

.feed-group {
  margin-bottom: var(--space-6);
}

.feed-group__heading {
  font-size: var(--text-body);
  font-weight: 700;
  color: var(--color-ink-soft);
  margin: 0 0 var(--space-3);
}

.tile-skeleton {
  aspect-ratio: 1;
  border-radius: var(--radius-photo);
  background: var(--color-surface-2);
  animation: skeleton-pulse 1.5s ease-in-out infinite;
}

.feed-loading {
  display: flex;
  justify-content: center;
  padding: var(--space-6) 0;
}

.feed-spinner {
  width: 24px;
  height: 24px;
  border: 2px solid var(--color-accent);
  border-top-color: transparent;
  border-radius: 50%;
  animation: spin 0.7s linear infinite;
}

@media (prefers-reduced-motion: reduce) {
  .tile-skeleton {
    animation: none;
  }
  .feed-spinner {
    animation: none;
    border-top-color: var(--color-accent);
  }
}

@keyframes skeleton-pulse {
  0%,
  100% {
    opacity: 1;
  }
  50% {
    opacity: 0.5;
  }
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
