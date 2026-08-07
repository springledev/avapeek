<script setup lang="ts">
import { Clock } from '@lucide/vue'
import { ref, computed, onMounted } from 'vue'
import { api } from '@/services/api'
import { preloadOriginal } from '@/services/storage'
import { useRecentsStore } from '@/stores/recents'
import RecentUploadGroup from '@/components/RecentUploadGroup.vue'
import PhotoViewer from '@/components/PhotoViewer.vue'
import { toast } from 'vue-sonner'
import type { Photo } from '@/types/photo'

const recentsStore = useRecentsStore()

const selectedPhoto = ref<Photo | null>(null)
const selectedGroupPhotos = ref<Photo[]>([])
const selectedPhotoIndex = ref(0)
const photoViewerKey = ref(0)

const grouped = computed(() => {
  const groups: { key: string; uploaderName: string; childName: string; childColor: string | null; relativeTime: string; photos: typeof recentsStore.photos }[] = []
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
    const relativeTime = offset < 1 ? 'Just now' : offset < 24 ? `${offset}h ago` : `${Math.floor(offset / 24)}d ago`

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
  selectedPhotoIndex.value = groupPhotos.findIndex(p => p.id === photo.id)
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
  } catch (err: any) {
    toast.error(err.response?.data?.message || 'Failed to delete photo')
  }
}

async function addTag(photoId: number, tagName: string) {
  try {
    // Will be implemented properly later
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
    recentsStore.fetchRecents()
  } catch (err: any) {
    toast.error(err.response?.data?.message || 'Failed to update date')
  }
}

onMounted(async () => {
  await recentsStore.fetchRecents()
  await recentsStore.markAsSeen()
})
</script>

<template>
  <div>
    <div class="mb-6">
      <div class="flex items-center gap-3">
        <h1 class="text-2xl font-bold text-slate-900 dark:text-white">Recent Uploads</h1>
        <span
          v-if="recentsStore.unseenCount > 0"
          class="rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-700 dark:bg-red-900/30 dark:text-red-400"
        >
          {{ recentsStore.unseenCount }} new
        </span>
      </div>
    </div>

    <div v-if="recentsStore.isLoading && recentsStore.photos.length === 0" class="space-y-4">
      <div v-for="i in 3" :key="i" class="h-36 animate-pulse rounded-xl bg-slate-200 dark:bg-slate-700" />
    </div>

    <div v-else-if="recentsStore.photos.length === 0" class="py-16 text-center">
      <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-slate-100 dark:bg-slate-700">
        <Clock class="h-8 w-8 text-slate-400" />
      </div>
      <p class="text-lg font-medium text-slate-900 dark:text-white">No recent uploads</p>
      <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Upload a photo and it will appear here.</p>
    </div>

    <div v-else class="space-y-4">
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

    <div v-if="recentsStore.isLoading && recentsStore.photos.length > 0" class="mt-4 flex justify-center">
      <div class="h-6 w-6 animate-spin rounded-full border-2 border-indigo-600 border-t-transparent" />
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
