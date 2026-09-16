<script setup lang="ts">
import { Clock } from '@lucide/vue'
import { ref, computed, onMounted } from 'vue'
import { api } from '@/services/api'
import { preloadOriginal } from '@/services/storage'
import { useRecentsStore } from '@/stores/recents'
import RecentUploadGroup from '@/components/RecentUploadGroup.vue'
import PhotoViewer from '@/components/PhotoViewer.vue'
import UiEmptyState from '@/components/ui/UiEmptyState.vue'
import { toast } from 'vue-sonner'
import type { Photo } from '@/types/photo'

const recentsStore = useRecentsStore()

const selectedPhoto = ref<Photo | null>(null)
const selectedGroupPhotos = ref<Photo[]>([])
const selectedPhotoIndex = ref(0)
const photoViewerKey = ref(0)

const grouped = computed(() => {
  const groups: {
    key: string
    uploaderName: string
    childName: string
    childColor: string | null
    relativeTime: string
    photos: typeof recentsStore.photos
  }[] = []
  const map = new Map<string, typeof recentsStore.photos>()

  for (const photo of recentsStore.photos) {
    const uploader = photo.uploader_name || 'Someone'
    const child = photo.children?.[0]
    const childName = child?.name || 'Unknown'
    const date = new Date(photo.created_at).toLocaleDateString()
    const key = `${uploader}-${date}-${childName}`

    if (!map.has(key)) map.set(key, [])
    map.get(key)!.push(photo)
  }

  for (const [key, photos] of map) {
    const first = photos[0]!
    const child = first.children?.[0]
    const offset = Math.floor((Date.now() - new Date(first.created_at).getTime()) / 3600000)
    const relativeTime =
      offset < 1 ? 'Just now' : offset < 24 ? `${offset}h ago` : `${Math.floor(offset / 24)}d ago`

    groups.push({
      key,
      uploaderName: first.uploader_name || 'Someone',
      childName: child?.name || 'Unknown',
      childColor: child?.avatar_color || null,
      relativeTime,
      photos,
    })
  }

  return groups
})

const hasPrev = computed(() => selectedPhotoIndex.value > 0)
const hasNext = computed(() => selectedPhotoIndex.value < selectedGroupPhotos.value.length - 1)

function schedulePreload() {
  requestAnimationFrame(async () => {
    if (hasNext.value) {
      const next = selectedGroupPhotos.value[selectedPhotoIndex.value + 1]
      if (next) preloadOriginal(next).catch(() => {})
    }
    if (hasPrev.value) {
      const prev = selectedGroupPhotos.value[selectedPhotoIndex.value - 1]
      if (prev) preloadOriginal(prev).catch(() => {})
    }
  })
}

function openPhoto(photo: Photo, groupPhotos: Photo[]) {
  selectedGroupPhotos.value = groupPhotos
  selectedPhotoIndex.value = groupPhotos.findIndex((p) => p.id === photo.id)
  if (selectedPhotoIndex.value < 0) selectedPhotoIndex.value = 0
  selectedPhoto.value = groupPhotos[selectedPhotoIndex.value]!
  photoViewerKey.value++
  schedulePreload()
}

function closePhoto() {
  selectedPhoto.value = null
}

function prevPhoto() {
  if (selectedPhotoIndex.value > 0) {
    selectedPhotoIndex.value--
    selectedPhoto.value = selectedGroupPhotos.value[selectedPhotoIndex.value]!
    photoViewerKey.value++
    schedulePreload()
  }
}

function nextPhoto() {
  if (selectedPhotoIndex.value < selectedGroupPhotos.value.length - 1) {
    selectedPhotoIndex.value++
    selectedPhoto.value = selectedGroupPhotos.value[selectedPhotoIndex.value]!
    photoViewerKey.value++
    schedulePreload()
  }
}

async function deletePhoto(photoId: number) {
  try {
    await api.delete(`/photos/${photoId}`)
    toast.success('Photo deleted')
    selectedPhoto.value = null
    recentsStore.fetchRecents()
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
  void photoId
  toast.success('Tag added')
}

async function removeTag(photoId: number, tagId: number) {
  void tagId
  void photoId
  toast.success('Tag removed')
}

async function updateDate(photoId: number, takenAt: string) {
  try {
    await api.patch(`/photos/${photoId}`, { taken_at: takenAt })
    toast.success('Date updated')
    recentsStore.fetchRecents()
  } catch (err: unknown) {
    const message =
      err instanceof Error && 'response' in err
        ? (err as { response?: { data?: { message?: string } } }).response?.data?.message
        : undefined
    toast.error(message || 'Failed to update date')
  }
}

onMounted(async () => {
  await recentsStore.fetchRecents()
  await recentsStore.markAsSeen()
})
</script>

<template>
  <div>
    <h1 class="recents__title">Recent uploads</h1>

    <div v-if="recentsStore.isLoading && recentsStore.photos.length === 0" class="recents__loading">
      <div v-for="i in 3" :key="i" class="recents__skeleton" />
    </div>

    <UiEmptyState
      v-else-if="recentsStore.photos.length === 0"
      title="No recent uploads"
      message="Upload a photo and it will appear here."
    >
      <template #icon>
        <Clock :size="48" />
      </template>
    </UiEmptyState>

    <div v-else class="recents__groups">
      <RecentUploadGroup
        v-for="group in grouped"
        :key="group.key"
        :photos="group.photos"
        :uploader-name="group.uploaderName"
        :child-name="group.childName"
        :child-color="group.childColor"
        :relative-time="group.relativeTime"
        @select="openPhoto($event, group.photos)"
      />
    </div>

    <div
      v-if="recentsStore.isLoading && recentsStore.photos.length > 0"
      class="recents__loading-more"
    >
      <div class="recents__spinner" />
    </div>

    <PhotoViewer
      v-if="selectedPhoto"
      :key="photoViewerKey"
      :photo="selectedPhoto"
      :has-prev="hasPrev"
      :has-next="hasNext"
      :can-delete="true"
      :can-edit="true"
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
.recents__title {
  font-size: var(--text-title);
  font-weight: 800;
  line-height: var(--lh-title);
  color: var(--color-ink);
  margin: 0 0 var(--space-4);
}

.recents__loading {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.recents__skeleton {
  height: 144px;
  border-radius: var(--radius-card);
  background: var(--color-surface-2);
  animation: skeleton-pulse 1.5s ease-in-out infinite;
}

.recents__groups {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.recents__loading-more {
  display: flex;
  justify-content: center;
  padding: var(--space-6) 0;
}

.recents__spinner {
  width: 24px;
  height: 24px;
  border: 2px solid var(--color-accent);
  border-top-color: transparent;
  border-radius: 50%;
  animation: spin 0.7s linear infinite;
}

@media (prefers-reduced-motion: reduce) {
  .recents__skeleton {
    animation: none;
  }
  .recents__spinner {
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
