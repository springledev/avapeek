<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useChildStore } from '@/stores/child'
import { ageAt, getAvatarColor, getInitials } from '@/utils/childHelpers'
import { api } from '@/services/api'
import AppHeader from '@/components/AppHeader.vue'
import UploadDropzone from '@/components/UploadDropzone.vue'
import PhotoGrid from '@/components/PhotoGrid.vue'
import PhotoViewer from '@/components/PhotoViewer.vue'
import TagSidebar from '@/components/TagSidebar.vue'
import type { TagItem } from '@/components/TagSidebar.vue'
import type { Photo } from '@/types/photo'
import 'vue-virtual-scroller/dist/vue-virtual-scroller.css'

const route = useRoute()
const router = useRouter()
const childStore = useChildStore()

const childId = computed(() => Number(route.params.id))
const showUpload = ref(false)
const showMobileTags = ref(false)
const isLoading = ref(true)
const activeTag = ref<string | null>(null)
const activeDateFilter = ref<string | null>(null)
const tags = ref<TagItem[]>([])
const viewerPhoto = ref<Photo | null>(null)
const allPhotos = ref<Photo[]>([])

const child = computed(() => childStore.currentChildDetail)

const viewerIndex = computed(() =>
  viewerPhoto.value ? allPhotos.value.findIndex((p) => p.id === viewerPhoto.value!.id) : -1,
)

const currentMonthLabel = computed(() => {
  if (!activeDateFilter.value) return null
  return new Date(activeDateFilter.value).toLocaleString('default', { month: 'long', year: 'numeric' })
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

async function refreshPhotos() {
  // PhotoGrid fetches internally, but we track for viewer navigation
  try {
    const response = await api.get(`/children/${childId.value}/photos`, {
      params: { per_page: 200 },
    })
    allPhotos.value = response.data.data as Photo[]
  } catch {
    allPhotos.value = []
  }
}

async function init() {
  isLoading.value = true
  await childStore.fetchChildDetail(childId.value)
  await Promise.all([fetchTags(), refreshPhotos()])
  isLoading.value = false
}

function selectTag(tag: string | null) {
  activeTag.value = tag
}

function createTag(name: string) {
  api
    .post(`/children/${childId.value}/tags`, { name })
    .then(() => fetchTags())
}

function openViewer(photo: Photo) {
  viewerPhoto.value = photo
  document.body.style.overflow = 'hidden'
}

function closeViewer() {
  viewerPhoto.value = null
  document.body.style.overflow = ''
}

function viewerPrev() {
  if (viewerIndex.value > 0) {
    viewerPhoto.value = allPhotos.value[viewerIndex.value - 1]!
  }
}

function viewerNext() {
  if (viewerIndex.value < allPhotos.value.length - 1) {
    viewerPhoto.value = allPhotos.value[viewerIndex.value + 1]!
  }
}

function onPhotoUploaded() {
  showUpload.value = false
  refreshPhotos()
  fetchTags()
}

function goBack() {
  router.push('/')
}

onMounted(init)
</script>

<template>
  <div class="min-h-screen bg-slate-50">
    <AppHeader />

    <div v-if="isLoading" class="flex justify-center py-20">
      <span class="spinner-large" />
    </div>

    <template v-else-if="child">
      <div class="main-container">
        <!-- Child Header -->
        <div class="page-header">
          <div class="flex items-center gap-4">
            <button @click="goBack" class="nav-back-link">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
              </svg>
            </button>
            <div
              class="avatar-circle"
              :style="{ backgroundColor: getAvatarColor(child.id) }"
            >
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
                  <span class="text-slate-400 mx-1">&middot;</span>
                  {{ child.relationship }}
                </template>
              </p>
            </div>
          </div>
          <div class="flex gap-3">
            <button
              class="btn-action"
              @click="router.push(`/child/${childId}/members`)"
            >
              Members
            </button>
            <button
              class="btn-action"
              @click="showUpload = !showUpload"
            >
              <template v-if="showUpload">Cancel</template>
              <template v-else>Upload</template>
            </button>
          </div>
        </div>

        <!-- Upload Section -->
        <div v-if="showUpload" class="card-xl mb-8">
          <h2 class="text-lg font-semibold text-slate-900 mb-2">Upload Photos</h2>
          <p class="text-sm text-slate-500 mb-6">
            Photos are encrypted on your device before upload.
          </p>
          <UploadDropzone
            :child-id="childId"
            @uploaded="onPhotoUploaded"
            @done="onPhotoUploaded"
          />
        </div>

        <!-- Active filter indicator -->
        <div v-if="activeTag || activeDateFilter" class="flex items-center gap-2 mb-4">
          <span class="text-sm text-slate-500">Filtering:</span>
          <span
            v-if="activeTag"
            class="tag-chip bg-blue-100 text-blue-700"
          >
            {{ activeTag }}
            <button class="tag-chip-remove" @click="activeTag = null">&times;</button>
          </span>
          <span
            v-if="currentMonthLabel"
            class="tag-chip bg-purple-100 text-purple-700"
          >
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
              @select="selectTag"
              @create="createTag"
            />
          </div>

          <!-- Mobile tag toggle -->
          <button
            class="tag-toggle-btn mb-3"
            @click="showMobileTags = true"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h10M7 11h10M7 15h10" />
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
              <div
                class="tag-sidebar-mobile"
                @click.stop
              >
                <div class="flex items-center justify-between mb-4">
                  <h2 class="text-lg font-semibold text-slate-900">Tags</h2>
                  <button
                    class="text-slate-400 hover:text-slate-600"
                    @click="showMobileTags = false"
                  >
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                  </button>
                </div>
                <TagSidebar
                  :tags="tags"
                  :active-tag="activeTag"
                  :total-photos="allPhotos.length"
                  @select="(tag) => { selectTag(tag); showMobileTags = false }"
                  @create="createTag"
                />
              </div>
            </div>
          </Teleport>

          <!-- Main feed -->
          <div class="feed-main">
            <PhotoGrid
              :key="`${childId}-${activeTag || ''}-${activeDateFilter || ''}`"
              :child-id="childId"
              :tag-filter="activeTag"
              :date-filter="activeDateFilter"
              @select-photo="openViewer"
            />
          </div>

          <!-- Date filter (optional dropdown) -->
          <div class="hidden lg:block w-40 shrink-0">
            <div class="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-2 pl-1">Jump to</div>
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
                :class="{ 'tag-item-active': activeDateFilter === new Date().toISOString().slice(0, 7) }"
                @click="activeDateFilter = new Date().toISOString().slice(0, 7)"
              >
                This month
              </button>
              <button
                class="tag-item w-full text-left"
                :class="{ 'tag-item-active': activeDateFilter === new Date().getFullYear().toString() }"
                @click="activeDateFilter = '2026'"
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
      @close="closeViewer"
      @prev="viewerPrev"
      @next="viewerNext"
    />
  </div>
</template>
