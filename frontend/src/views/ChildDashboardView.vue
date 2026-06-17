<script setup lang="ts">
import { ref, watch, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useChildStore } from '@/stores/child'
import { useAuthStore } from '@/stores/auth'
import { ageAt, getAvatarColor, getInitials } from '@/utils/childHelpers'
import { api } from '@/services/api'
import { toast } from 'vue-sonner'
import AppHeader from '@/components/AppHeader.vue'
import UploadDropzone from '@/components/UploadDropzone.vue'
import PhotoGrid from '@/components/PhotoGrid.vue'
import PhotoViewer from '@/components/PhotoViewer.vue'
import TagSidebar from '@/components/TagSidebar.vue'
import QuotaBar from '@/components/QuotaBar.vue'
import type { TagItem } from '@/components/TagSidebar.vue'
import type { Photo } from '@/types/photo'
import 'vue-virtual-scroller/dist/vue-virtual-scroller.css'

const route = useRoute()
const router = useRouter()
const childStore = useChildStore()
const authStore = useAuthStore()

const childSlug = computed(() => route.params.slug as string)
const childId = ref(0)
const showUpload = ref(false)
const showMobileTags = ref(false)
const isLoading = ref(true)
const activeTag = ref<string | null>(null)
const activeDateFilter = ref<string | null>(null)
const tags = ref<TagItem[]>([])
const viewerPhoto = ref<Photo | null>(null)
const allPhotos = ref<Photo[]>([])
const quotaBarRef = ref<InstanceType<typeof QuotaBar> | null>(null)
const photoGridRef = ref<InstanceType<typeof PhotoGrid> | null>(null)

const child = computed(() => childStore.currentChildDetail)

const userRole = computed(() => child.value?.role ?? 'member')
const userId = computed(() => authStore.user?.id)
const isAdmin = computed(() => userRole.value === 'owner' || userRole.value === 'admin')

const childQuota = computed(() => child.value ? {
  used_bytes: child.value.storage_used,
  limit_bytes: child.value.storage_limit_bytes,
  percentage: child.value.storage_percentage,
} : null)

const canDeletePhoto = (photo: Photo): boolean => {
  if (isAdmin.value) return true
  return photo.uploaded_by === userId.value
}

const canDeleteCurrentPhoto = computed(() =>
  viewerPhoto.value ? canDeletePhoto(viewerPhoto.value) : false,
)

const viewerIndex = computed(() =>
  viewerPhoto.value ? allPhotos.value.findIndex((p) => p.id === viewerPhoto.value!.id) : -1,
)

const currentMonthLabel = computed(() => {
  if (!activeDateFilter.value) return null
  return new Date(activeDateFilter.value).toLocaleString('default', {
    month: 'long',
    year: 'numeric',
  })
})

function roleBadgeClass(role: string): string {
  if (role === 'owner') return 'role-owner'
  if (role === 'admin') return 'role-admin'
  return 'role-member'
}

async function fetchTags() {
  try {
    const response = await api.get(`/children/${childId.value}/tags`)
    tags.value = response.data.data as TagItem[]
  } catch {
    tags.value = []
  }
}

async function init() {
  isLoading.value = true

  const slug = childSlug.value

  if (childStore.children.length === 0) {
    await childStore.fetchChildren()
  }
  const found = childStore.children.find((c) => c.slug === slug)
  if (!found) {
    router.push('/')
    return
  }
  childId.value = found.id

  await childStore.fetchChildDetail(childId.value)
  tags.value = childStore.currentChildDetail?.tags ?? []
  isLoading.value = false
}

watch(
  () => photoGridRef.value?.flatPhotos,
  (photos) => {
    if (photos) allPhotos.value = photos
  },
  { immediate: true },
)

function selectTag(tag: string | null) {
  activeTag.value = tag
}

function createTag(name: string) {
  api.post(`/children/${childId.value}/tags`, { name }).then(() => fetchTags())
}

function openViewer(photo: Photo) {
  viewerPhoto.value = { ...photo }
  document.body.style.overflow = 'hidden'
}

function closeViewer() {
  viewerPhoto.value = null
  document.body.style.overflow = ''
}

function viewerPrev() {
  if (viewerIndex.value > 0) {
    viewerPhoto.value = { ...allPhotos.value[viewerIndex.value - 1]! }
  }
}

function viewerNext() {
  if (viewerIndex.value < allPhotos.value.length - 1) {
    viewerPhoto.value = { ...allPhotos.value[viewerIndex.value + 1]! }
  }
}

function onPhotoUploaded() {
  showUpload.value = false
  fetchTags()
  quotaBarRef.value?.refresh()
  photoGridRef.value?.refresh()
}

async function handleDeletePhoto(photoId: number) {
  try {
    await api.delete(`/children/${childId.value}/photos/${photoId}`)
    closeViewer()
    photoGridRef.value?.refresh()
    await Promise.all([fetchTags(), quotaBarRef.value?.refresh()])
  } catch {
    toast.error('Failed to delete photo. You may not have permission.')
  }
}

async function handleTagAdded(photoId: number, tagName: string) {
  try {
    const existing = tags.value.find((t) => t.name === tagName)
    let tagId: number

    if (existing) {
      tagId = existing.id
    } else {
      const response = await api.post(`/children/${childId.value}/tags`, { name: tagName })
      const newTag = response.data.data
      tagId = newTag.id
      await fetchTags()
    }

    await api.post(`/children/${childId.value}/photos/${photoId}/tags`, {
      tag_ids: [tagId],
    })

    const photoIdx = allPhotos.value.findIndex((p) => p.id === photoId)
    if (photoIdx !== -1) {
      const updatedPhoto = { ...allPhotos.value[photoIdx]! }
      if (!updatedPhoto.tags?.find((t) => t.id === tagId)) {
        updatedPhoto.tags = [...(updatedPhoto.tags ?? []), { id: tagId, name: tagName }]
      }
      allPhotos.value[photoIdx] = updatedPhoto
      if (viewerPhoto.value?.id === photoId) {
        viewerPhoto.value = { ...allPhotos.value[photoIdx]! }
      }
    }

    await fetchTags()
  } catch {
    toast.error('Failed to add tag.')
  }
}

async function handleTagRemoved(photoId: number, tagId: number) {
  try {
    await api.delete(`/children/${childId.value}/photos/${photoId}/tags/${tagId}`)
    await fetchTags()
    const photoIdx = allPhotos.value.findIndex((p) => p.id === photoId)
    if (photoIdx !== -1) {
      const updated = { ...allPhotos.value[photoIdx]! }
      updated.tags = updated.tags?.filter((t) => t.id !== tagId) ?? []
      allPhotos.value[photoIdx] = updated
    }
    if (viewerPhoto.value?.id === photoId) {
      viewerPhoto.value = { ...allPhotos.value[photoIdx]! }
    }
  } catch {
    toast.error('Failed to remove tag.')
  }
}

async function handleDeleteTag(tagId: number) {
  try {
    await api.delete(`/children/${childId.value}/tags/${tagId}`)
    if (activeTag.value) {
      const deletedTag = tags.value.find((t) => t.id === tagId)
      if (deletedTag && activeTag.value === deletedTag.name) {
        activeTag.value = null
      }
    }
    await Promise.all([fetchTags(), photoGridRef.value?.refresh()])
  } catch {
    toast.error('Failed to delete tag. You may not have permission.')
  }
}

function goBack() {
  router.push('/')
}

onMounted(init)
</script>

<template>
  <div class="min-h-screen bg-slate-50 dark:bg-slate-950">
    <AppHeader />

    <div v-if="isLoading" class="flex justify-center py-20">
      <span class="spinner-large" />
    </div>

    <template v-else-if="child">
      <div class="main-container">
        <!-- Breadcrumbs -->
        <nav class="flex items-center gap-1.5 text-sm mb-4">
          <router-link to="/" class="nav-back-link">Home</router-link>
          <span class="text-slate-300 dark:text-slate-600">/</span>
          <span class="text-slate-700 dark:text-slate-300 font-medium">{{ child.name }}</span>
        </nav>

        <!-- Child Header -->
        <div class="page-header">
          <div class="flex items-center gap-4">
            <div class="avatar-circle" :style="{ backgroundColor: getAvatarColor(child.id) }">
              {{ getInitials(child.name) }}
            </div>
            <div>
              <h1 class="page-title">{{ child.name }}</h1>
              <p class="page-subtitle">
                <template v-if="child.birth_date">{{ ageAt(child.birth_date) }} &middot; </template>
                <span class="role-badge" :class="roleBadgeClass(child.role)">
                  {{ child.role }}
                </span>
                <template v-if="child.relationship">
          <span class="text-slate-400 dark:text-slate-500 mx-1">&middot;</span>
          <span class="dark:text-slate-400">{{ child.relationship }}</span>
                </template>
              </p>
            </div>
          </div>
          <div class="flex gap-3">
            <button class="btn-action" @click="router.push(`/child/${childSlug}/members`)">
              Members
            </button>
            <button class="btn-action" @click="showUpload = !showUpload">
              <template v-if="showUpload">Cancel</template>
              <template v-else>Upload</template>
            </button>
          </div>
        </div>

        <!-- Storage Quota Bar -->
        <QuotaBar :child-id="childId" :initial="childQuota" ref="quotaBarRef" />

        <!-- Upload Section -->
        <div v-if="showUpload" class="card-xl mb-8">
          <h2 class="text-lg font-semibold text-slate-900 mb-2 dark:text-slate-100">Upload Photos</h2>
          <p class="text-sm text-slate-500 mb-6 dark:text-slate-400">
            Photos are encrypted on your device before upload.
          </p>
          <UploadDropzone :child-id="childId" @uploaded="onPhotoUploaded" @done="onPhotoUploaded" />
        </div>

        <!-- Active filter indicator -->
        <div v-if="activeTag || activeDateFilter" class="flex items-center gap-2 mb-4">
          <span class="text-sm text-slate-500 dark:text-slate-400">Filtering:</span>
          <span v-if="activeTag" class="tag-chip bg-blue-100 text-blue-700 dark:bg-blue-900/50 dark:text-blue-300">
            {{ activeTag }}
            <button class="tag-chip-remove" @click="activeTag = null">&times;</button>
          </span>
          <span v-if="currentMonthLabel" class="tag-chip bg-purple-100 text-purple-700 dark:bg-purple-900/50 dark:text-purple-300">
            {{ currentMonthLabel }}
            <button class="tag-chip-remove" @click="activeDateFilter = null">&times;</button>
          </span>
        </div>

        <!-- Feed: Sidebar + Grid -->
        <div class="feed-layout">
          <!-- Desktop sidebar -->
          <div class="hidden lg:block">
            <TagSidebar
              :tags="tags"
              :active-tag="activeTag"
              :total-photos="allPhotos.length"
              :can-delete-tag="isAdmin"
              @select="selectTag"
              @create="createTag"
              @delete-tag="handleDeleteTag"
            />
          </div>

          <!-- Mobile tag toggle -->
          <button class="tag-toggle-btn mb-3" @click="showMobileTags = true">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M7 7h10M7 11h10M7 15h10"
              />
            </svg>
            Tags
          </button>

          <!-- Mobile tag drawer -->
          <Teleport to="body">
            <div
              v-if="showMobileTags"
              class="fixed inset-0 z-40 bg-black/50"
              @click="showMobileTags = false"
            >
              <div class="tag-sidebar-mobile" @click.stop>
                <div class="flex items-center justify-between mb-4">
                  <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Tags</h2>
                  <button
                    class="text-slate-400 hover:text-slate-600 dark:text-slate-500 dark:hover:text-slate-300"
                    @click="showMobileTags = false"
                  >
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M6 18L18 6M6 6l12 12"
                      />
                    </svg>
                  </button>
                </div>
                <TagSidebar
                  :tags="tags"
                  :active-tag="activeTag"
                  :total-photos="allPhotos.length"
                  :can-delete-tag="isAdmin"
                  @select="
                    (tag) => {
                      selectTag(tag)
                      showMobileTags = false
                    }
                  "
                  @create="createTag"
                  @delete-tag="
                    (tagId) => {
                      handleDeleteTag(tagId)
                      showMobileTags = false
                    }
                  "
                />
              </div>
            </div>
          </Teleport>

          <!-- Main feed -->
          <div class="feed-main">
            <PhotoGrid
              ref="photoGridRef"
              :key="`${childId}-${activeTag || ''}-${activeDateFilter || ''}`"
              :child-id="childId"
              :tag-filter="activeTag"
              :date-filter="activeDateFilter"
              @select-photo="openViewer"
              @clear-filter="activeTag = null; activeDateFilter = null"
            />
          </div>

          <!-- Date filter sidebar -->
          <div class="hidden lg:block w-40 shrink-0">
            <div class="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-2 pl-1 dark:text-slate-500">
              Jump to
            </div>
            <div class="space-y-0.5">
              <button
                class="tag-item w-full text-left"
                :class="{ 'tag-item-active': activeDateFilter === null }"
                @click="activeDateFilter = null"
              >
                All time
              </button>
              <button
                class="tag-item w-full text-left"
                :class="{
                  'tag-item-active': activeDateFilter === new Date().toISOString().slice(0, 7),
                }"
                @click="activeDateFilter = new Date().toISOString().slice(0, 7)"
              >
                This month
              </button>
              <button
                class="tag-item w-full text-left"
                :class="{
                  'tag-item-active': activeDateFilter === new Date().getFullYear().toString(),
                }"
                @click="activeDateFilter = new Date().getFullYear().toString()"
              >
                This year
              </button>
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- Photo Viewer Overlay -->
    <PhotoViewer
      v-if="viewerPhoto"
      :photo="viewerPhoto"
      :has-prev="viewerIndex > 0"
      :has-next="viewerIndex < allPhotos.length - 1"
      :can-delete="canDeleteCurrentPhoto"
      :child-birth-date="child?.birth_date ?? null"
      :all-tags="tags"
      @close="closeViewer"
      @prev="viewerPrev"
      @next="viewerNext"
      @delete="handleDeletePhoto"
      @tag-added="handleTagAdded"
      @tag-removed="handleTagRemoved"
    />
  </div>
</template>
