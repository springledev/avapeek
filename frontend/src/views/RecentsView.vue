<script setup lang="ts">
import { Clock } from '@lucide/vue'
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useSelection } from '@/composables/useSelection'
import BulkActions from '@/components/BulkActions.vue'
import UiButton from '@/components/ui/UiButton.vue'
import { MSG } from '@/lib/messages'
import { api } from '@/services/api'
import { preloadOriginal } from '@/services/storage'
import { useRecentsStore } from '@/stores/recents'
import { useTagsStore } from '@/stores/tags'
import { apiErrorMessage } from '@/lib/util'
import RecentUploadGroup from '@/components/RecentUploadGroup.vue'
import PhotoViewer from '@/components/PhotoViewer.vue'
import UiEmptyState from '@/components/ui/UiEmptyState.vue'
import UiLoadError from '@/components/ui/UiLoadError.vue'
import { toast } from 'vue-sonner'
import type { Photo } from '@/types/photo'

const recentsStore = useRecentsStore()
const route = useRoute()
const selection = useSelection({ routeKey: () => route.path })

const selectedPhotos = computed(() =>
  recentsStore.photos.filter((photo) => selection.isSelected(photo.id)),
)

function onBulkRemoved(deleted: number[], detachedCount: number) {
  const gone = new Set(deleted)
  recentsStore.photos = recentsStore.photos.filter((photo) => !gone.has(photo.id))
  if (detachedCount > 0) recentsStore.fetchRecents()
}

const selectedPhoto = ref<Photo | null>(null)
const selectedGroupPhotos = ref<Photo[]>([])
const selectedPhotoIndex = ref(0)

const grouped = computed(() => {
  const groups: {
    key: string
    uploaderName: string
    childId: number
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
      childId: child?.id ?? 0,
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
const prevPhotoObj = computed(() =>
  hasPrev.value ? (selectedGroupPhotos.value[selectedPhotoIndex.value - 1] ?? null) : null,
)
const nextPhotoObj = computed(() =>
  hasNext.value ? (selectedGroupPhotos.value[selectedPhotoIndex.value + 1] ?? null) : null,
)

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
  schedulePreload()
}

function closePhoto() {
  selectedPhoto.value = null
}

function prevPhoto() {
  if (selectedPhotoIndex.value > 0) {
    selectedPhotoIndex.value--
    selectedPhoto.value = selectedGroupPhotos.value[selectedPhotoIndex.value]!
    schedulePreload()
  }
}

function nextPhoto() {
  if (selectedPhotoIndex.value < selectedGroupPhotos.value.length - 1) {
    selectedPhotoIndex.value++
    selectedPhoto.value = selectedGroupPhotos.value[selectedPhotoIndex.value]!
    schedulePreload()
  }
}

async function deletePhoto(photoId: number) {
  try {
    const res = await api.delete(`/photos/${photoId}`)
    if (res.data?.remaining) {
      toast.success(MSG.removedFromKids)
    } else {
      toast.success(MSG.photoDeleted)
    }
    selectedPhoto.value = null
    recentsStore.fetchRecents()
  } catch (err: unknown) {
    const message =
      err instanceof Error && 'response' in err
        ? (err as { response?: { data?: { message?: string } } }).response?.data?.message
        : undefined
    toast.error(message || MSG.photoDeleteFailed)
  }
}

async function addTag(photoId: number, tagName: string) {
  const photo = recentsStore.photos.find((p) => p.id === photoId)
  if (!photo) return
  try {
    await useTagsStore().attach(photo, tagName)
  } catch (err: unknown) {
    toast.error(apiErrorMessage(err, MSG.tagAddFailed))
  }
}

async function removeTag(photoId: number, tagId: number) {
  const photo = recentsStore.photos.find((p) => p.id === photoId)
  if (!photo) return
  try {
    await useTagsStore().detach(photo, tagId)
  } catch (err: unknown) {
    toast.error(apiErrorMessage(err, MSG.tagRemoveFailed))
  }
}

async function updateDate(photoId: number, takenAt: string) {
  try {
    await api.patch(`/photos/${photoId}`, { taken_at: takenAt })
    toast.success(MSG.dateUpdated)
    recentsStore.fetchRecents()
  } catch (err: unknown) {
    const message =
      err instanceof Error && 'response' in err
        ? (err as { response?: { data?: { message?: string } } }).response?.data?.message
        : undefined
    toast.error(message || MSG.dateUpdateFailed)
  }
}

/**
 * Opens the photo named by `?photo=<id>` (set by push notification deep links).
 * Uses the loaded recents when it is there, else fetches it through the
 * authorized photo endpoint; an inaccessible or missing photo is ignored.
 */
async function openPhotoFromQuery() {
  const raw = route.query.photo
  const id = Number(Array.isArray(raw) ? raw[0] : raw)
  if (!Number.isInteger(id) || id <= 0) return

  const loaded = recentsStore.photos.find((p) => p.id === id)
  if (loaded) {
    openPhoto(loaded, [loaded])
    return
  }

  try {
    const response = await api.get<{ data: Photo }>(`/photos/${id}`)
    const [photo] = await useTagsStore().decryptPhotoTags([response.data.data])
    if (photo) openPhoto(photo, [photo])
  } catch {
    /* no access or gone: stay on the recents list */
  }
}

watch(() => route.query.photo, () => void openPhotoFromQuery())

onMounted(async () => {
  await recentsStore.fetchRecents()
  await recentsStore.markAsSeen()
  await openPhotoFromQuery()
})
</script>

<template>
  <div>
    <div class="recents__head">
      <h1 class="recents__title">Recent uploads</h1>
      <UiButton
        v-if="recentsStore.photos.length > 0"
        variant="ghost"
        data-test="select-toggle"
        @click="selection.isSelecting.value ? selection.clear() : selection.start()"
      >
        {{ selection.isSelecting.value ? MSG.selectCancel : MSG.selectPhotos }}
      </UiButton>
    </div>

    <div v-if="recentsStore.isLoading && recentsStore.photos.length === 0" class="recents__loading">
      <div v-for="i in 3" :key="i" class="recents__skeleton" />
    </div>

    <UiLoadError
      v-else-if="recentsStore.loadFailed && recentsStore.photos.length === 0"
      title="Couldn't load recent uploads"
      @retry="recentsStore.fetchRecents()"
    />

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
      <UiLoadError
        v-if="recentsStore.loadFailed"
        compact
        title="Couldn't refresh recent uploads"
        message="Showing what was loaded earlier."
        :retrying="recentsStore.isLoading"
        @retry="recentsStore.fetchRecents()"
      />
      <RecentUploadGroup
        v-for="group in grouped"
        :key="group.key"
        :photos="group.photos"
        :uploader-name="group.uploaderName"
        :child-id="group.childId"
        :child-name="group.childName"
        :child-color="group.childColor"
        :relative-time="group.relativeTime"
        :selecting="selection.isSelecting.value"
        :selected-ids="selection.selectedIds.value"
        @select="openPhoto($event, group.photos)"
        @toggle="selection.toggle($event.id)"
        @toggle-all="selection.toggleMany(group.photos.map((p) => p.id))"
      />
    </div>

    <div
      v-if="recentsStore.isLoading && recentsStore.photos.length > 0"
      class="recents__loading-more"
    >
      <div class="recents__spinner" />
    </div>

    <BulkActions :photos="selectedPhotos" @clear="selection.clear()" @removed="onBulkRemoved" />

    <PhotoViewer
      v-if="selectedPhoto"
      :photo="selectedPhoto"
      :has-prev="hasPrev"
      :has-next="hasNext"
      :can-delete="selectedPhoto.can_delete === true"
      :can-edit="selectedPhoto.can_edit === true"
      :child-birth-date="null"
      :prev-photo="prevPhotoObj"
      :next-photo="nextPhotoObj"
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
.recents__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-3);
  margin: 0 0 var(--space-4);
}

.recents__title {
  font-size: var(--text-title);
  font-weight: 800;
  line-height: var(--lh-title);
  color: var(--color-ink);
  margin: 0;
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
