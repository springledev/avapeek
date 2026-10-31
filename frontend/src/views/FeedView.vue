<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { preloadOriginal } from '@/services/storage'
import { useFeedStore } from '@/stores/feed'
import { useChildStore } from '@/stores/child'
import { useTagsStore } from '@/stores/tags'
import { usePrefetch } from '@/composables/usePrefetch'
import { useSetupState } from '@/composables/useSetupState'
import { useSelection } from '@/composables/useSelection'
import { Image, Lock } from '@lucide/vue'
import FeedToolbar from '@/components/feed/FeedToolbar.vue'
import PhotoTile from '@/components/feed/PhotoTile.vue'
import OnThisDayCard from '@/components/feed/OnThisDayCard.vue'
import PhotoViewer from '@/components/PhotoViewer.vue'
import UiEmptyState from '@/components/ui/UiEmptyState.vue'
import UiButton from '@/components/ui/UiButton.vue'
import CreateChildSheet from '@/components/CreateChildSheet.vue'
import MemberAccessBanner from '@/components/MemberAccessBanner.vue'
import AdminPendingAccessBanner from '@/components/AdminPendingAccessBanner.vue'
import BulkActions from '@/components/BulkActions.vue'
import { MSG } from '@/lib/messages'
import { apiErrorMessage } from '@/lib/util'
import type { Photo } from '@/types/photo'

const feedStore = useFeedStore()
const childStore = useChildStore()
const showCreateChild = ref(false)
const { prefetchNextPage } = usePrefetch()
const setup = useSetupState()
const route = useRoute()
const selection = useSelection({ routeKey: () => route.path })

const selectedPhotos = computed(() =>
  feedStore.photos.filter((photo) => selection.isSelected(photo.id)),
)

function onTileClick(photo: Photo) {
  if (selection.isSelecting.value) selection.toggle(photo.id)
  else openPhoto(photo)
}

function onBulkRemoved(deleted: number[], detachedCount: number) {
  const gone = new Set(deleted)
  feedStore.photos = feedStore.photos.filter((photo) => !gone.has(photo.id))
  // Detached photos may stay visible through another of the user's kids.
  if (detachedCount > 0) feedStore.fetchPhotos()
}

const showFinishSetup = computed(
  () => childStore.childrenLoaded && !setup.isComplete.value && !setup.isDismissed.value,
)

/** Every album the user belongs to is still waiting for an admin to share its key. */
const allChildrenLocked = computed(
  () =>
    childStore.childrenLoaded &&
    childStore.hasChildren &&
    childStore.lockedChildren.length === childStore.children.length,
)

const selectedPhoto = ref<Photo | null>(null)
const selectedPhotoIndex = ref(0)

const isStandalonePhoto = ref(false)

const hasPrev = computed(() => !isStandalonePhoto.value && selectedPhotoIndex.value > 0)
const hasNext = computed(
  () => !isStandalonePhoto.value && selectedPhotoIndex.value < feedStore.photos.length - 1,
)
const prevPhotoObj = computed(() =>
  hasPrev.value ? (feedStore.photos[selectedPhotoIndex.value - 1] ?? null) : null,
)
const nextPhotoObj = computed(() =>
  hasNext.value ? (feedStore.photos[selectedPhotoIndex.value + 1] ?? null) : null,
)
const canDelete = computed(() => selectedPhoto.value?.can_delete === true)
const canEdit = computed(() => selectedPhoto.value?.can_edit === true)

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
  if (feedStore.photos.length === 0 && !feedStore.isLoading) {
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
  isStandalonePhoto.value = false
  selectedPhotoIndex.value = idx >= 0 ? idx : 0
  selectedPhoto.value = photo
  schedulePreload()
}

function openMemory(photo: Photo) {
  const idx = feedStore.photos.findIndex((p) => p.id === photo.id)
  if (idx >= 0) {
    openPhoto(photo)
    return
  }
  isStandalonePhoto.value = true
  selectedPhotoIndex.value = 0
  selectedPhoto.value = photo
}

function closePhoto() {
  selectedPhoto.value = null
}

function prevPhoto() {
  if (selectedPhotoIndex.value > 0) {
    selectedPhotoIndex.value--
    selectedPhoto.value = feedStore.photos[selectedPhotoIndex.value]!
    schedulePreload()
  }
}

function nextPhoto() {
  if (selectedPhotoIndex.value < feedStore.photos.length - 1) {
    selectedPhotoIndex.value++
    selectedPhoto.value = feedStore.photos[selectedPhotoIndex.value]!
    schedulePreload()
  }
}

async function deletePhoto(photoId: number) {
  const { api } = await import('@/services/api')
  const { toast } = await import('vue-sonner')
  try {
    const res = await api.delete(`/photos/${photoId}`)
    if (res.data?.remaining) {
      toast.success(MSG.removedFromKids)
    } else {
      toast.success(MSG.photoDeleted)
    }
    selectedPhoto.value = null
    feedStore.fetchPhotos()
  } catch (err: unknown) {
    const message =
      err instanceof Error && 'response' in err
        ? (err as { response?: { data?: { message?: string } } }).response?.data?.message
        : undefined
    toast.error(message || MSG.photoDeleteFailed)
  }
}

async function addTag(photoId: number, tagName: string) {
  const photo = feedStore.photos.find((p) => p.id === photoId)
  if (!photo) return
  const { toast } = await import('vue-sonner')
  try {
    await useTagsStore().attach(photo, tagName)
  } catch (err: unknown) {
    toast.error(apiErrorMessage(err, MSG.tagAddFailed))
  }
}

async function removeTag(photoId: number, tagId: number) {
  const photo = feedStore.photos.find((p) => p.id === photoId)
  if (!photo) return
  const { toast } = await import('vue-sonner')
  try {
    await useTagsStore().detach(photo, tagId)
  } catch (err: unknown) {
    toast.error(apiErrorMessage(err, MSG.tagRemoveFailed))
  }
}

async function updateDate(photoId: number, takenAt: string) {
  const { api } = await import('@/services/api')
  const { toast } = await import('vue-sonner')
  try {
    await api.patch(`/photos/${photoId}`, { taken_at: takenAt })
    toast.success(MSG.dateUpdated)
    feedStore.fetchPhotos()
  } catch (err: unknown) {
    const message =
      err instanceof Error && 'response' in err
        ? (err as { response?: { data?: { message?: string } } }).response?.data?.message
        : undefined
    toast.error(message || MSG.dateUpdateFailed)
  }
}
</script>

<template>
  <div>
    <div class="feed-head">
      <h1 class="feed-title">Photos</h1>
      <UiButton
        v-if="feedStore.photos.length > 0"
        variant="ghost"
        data-test="select-toggle"
        @click="selection.isSelecting.value ? selection.clear() : selection.start()"
      >
        {{ selection.isSelecting.value ? MSG.selectCancel : MSG.selectPhotos }}
      </UiButton>
    </div>

    <section v-if="showFinishSetup" class="finish-setup" data-test="finish-setup-card">
      <div>
        <h2 class="finish-setup__title">{{ MSG.setupFinishTitle }}</h2>
        <p class="finish-setup__body">{{ MSG.setupFinishBody }}</p>
      </div>
      <div class="finish-setup__actions">
        <router-link
          :to="{ path: '/setup', query: { step: setup.firstIncompleteStep.value } }"
          class="feed-add-photos"
          data-test="finish-setup-continue"
        >
          {{ MSG.setupFinishAction }}
        </router-link>
        <UiButton variant="ghost" data-test="finish-setup-dismiss" @click="setup.dismiss()">
          {{ MSG.setupDismiss }}
        </UiButton>
      </div>
    </section>

    <MemberAccessBanner
      v-for="child in childStore.lockedChildren"
      :key="child.id"
      :child-id="child.id"
      :child-name="child.name"
    />

    <AdminPendingAccessBanner />

    <FeedToolbar />

    <OnThisDayCard v-if="!hasActiveFilters" @open="openMemory" />

    <div v-if="feedStore.isLoading && feedStore.photos.length === 0" class="u-grid">
      <div v-for="i in 8" :key="i" class="tile-skeleton" />
    </div>

    <UiEmptyState
      v-else-if="
        feedStore.photos.length === 0 &&
        !hasActiveFilters &&
        childStore.childrenLoaded &&
        !childStore.hasChildren
      "
      title="Add your first kid"
      message="Create a profile for your child, then add their photos."
    >
      <template #icon>
        <Image :size="48" />
      </template>
      <template #action>
        <UiButton variant="accent" size="lg" @click="showCreateChild = true">
          Add your first kid
        </UiButton>
      </template>
    </UiEmptyState>

    <UiEmptyState
      v-else-if="feedStore.photos.length === 0 && !hasActiveFilters && allChildrenLocked"
      :title="MSG.accessLockedEmptyTitle"
      :message="MSG.accessLockedEmptyBody"
      data-test="feed-locked-empty"
    >
      <template #icon>
        <Lock :size="48" />
      </template>
    </UiEmptyState>

    <UiEmptyState
      v-else-if="feedStore.photos.length === 0 && !hasActiveFilters"
      :title="MSG.feedEmpty"
    >
      <template #icon>
        <Image :size="48" />
      </template>
      <template #action>
        <router-link to="/upload" class="feed-add-photos">Add photos</router-link>
      </template>
    </UiEmptyState>

    <UiEmptyState
      v-else-if="feedStore.photos.length === 0 && hasActiveFilters"
      title="No matches"
      message="Try adjusting your filters."
    />

    <div v-else>
      <div v-for="group in groupedPhotos" :key="group.key" class="feed-group">
        <div class="feed-group__head">
          <h2 class="feed-group__heading">{{ group.label }}</h2>
          <button
            v-if="selection.isSelecting.value"
            type="button"
            class="feed-group__select u-focus-ring"
            data-test="select-group"
            @click="selection.toggleMany(group.photos.map((p) => p.id))"
          >
            {{
              selection.allSelected(group.photos.map((p) => p.id))
                ? MSG.deselectAllIn(group.label)
                : MSG.selectAllIn(group.label)
            }}
          </button>
        </div>
        <div class="u-grid">
          <PhotoTile
            v-for="photo in group.photos"
            :key="photo.id"
            :photo="photo"
            :selectable="selection.isSelecting.value"
            :selected="selection.isSelected(photo.id)"
            @click="onTileClick(photo)"
            @longpress="selection.toggle(photo.id)"
          />
        </div>
      </div>

      <div v-if="feedStore.isLoading" class="feed-loading">
        <div class="feed-spinner" />
      </div>
    </div>

    <CreateChildSheet :open="showCreateChild" @close="showCreateChild = false" />

    <BulkActions :photos="selectedPhotos" @clear="selection.clear()" @removed="onBulkRemoved" />

    <PhotoViewer
      v-if="selectedPhoto"
      :photo="selectedPhoto"
      :has-prev="hasPrev"
      :has-next="hasNext"
      :can-delete="canDelete"
      :can-edit="canEdit"
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
.feed-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-3);
  margin: 0 0 var(--space-4);
}

.feed-title {
  font-size: var(--text-title);
  font-weight: 800;
  line-height: var(--lh-title);
  color: var(--color-ink);
  margin: 0;
}

.feed-group__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-3);
  margin: 0 0 var(--space-3);
}

.feed-group__select {
  min-height: var(--target-min);
  padding: 0 var(--space-3);
  border: 0;
  background: transparent;
  color: var(--color-accent-ink);
  font-family: inherit;
  font-size: var(--text-body);
  font-weight: 700;
  cursor: pointer;
}

.finish-setup {
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
  margin: 0 0 var(--space-4);
  padding: var(--space-4);
  border-radius: var(--radius-card);
  background: var(--color-accent-soft);
  color: var(--color-accent-ink);
}

.finish-setup__title {
  margin: 0;
  font-size: var(--text-body);
  font-weight: 800;
  color: var(--color-ink);
}

.finish-setup__body {
  margin: var(--space-1) 0 0;
  font-size: var(--text-body);
  line-height: var(--lh-body);
}

.finish-setup__actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-2);
}

.feed-add-photos {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: var(--target-min);
  padding: 0 var(--space-6);
  border-radius: var(--radius-card);
  background: var(--color-accent);
  color: var(--color-on-accent);
  font-weight: 700;
  text-decoration: none;
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
  margin: 0;
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
