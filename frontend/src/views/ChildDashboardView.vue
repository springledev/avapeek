<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useChildStore } from '@/stores/child'
import { ageAt, getAvatarColor, getInitials } from '@/utils/childHelpers'
import UploadDropzone from '@/components/UploadDropzone.vue'
import type { Photo } from '@/types/photo'
import AppHeader from '@/components/AppHeader.vue'

const route = useRoute()
const router = useRouter()
const childStore = useChildStore()

const childId = computed(() => Number(route.params.id))
const showUpload = ref(false)
const uploadedPhotos = ref<Photo[]>([])
const isLoading = ref(true)

const child = computed(() => childStore.currentChildDetail)

function roleBadgeClass(role: string): string {
  if (role === 'owner') return 'role-owner'
  if (role === 'admin') return 'role-admin'
  return 'role-member'
}

onMounted(async () => {
  await childStore.fetchChildDetail(childId.value)
  isLoading.value = false
})

function goBack() {
  router.push('/')
}

function onPhotoUploaded(photo: Photo) {
  uploadedPhotos.value.push(photo)
}

function onUploadDone() {
  showUpload.value = false
}
</script>

<template>
  <div class="min-h-screen bg-slate-50">
    <AppHeader />
    <div class="main-container">
      <div v-if="isLoading" class="flex justify-center py-20">
        <span class="spinner-large" />
      </div>

      <template v-else-if="child">
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
                <span
                  class="role-badge"
                  :class="roleBadgeClass(child.role)"
                >
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
              <template v-if="showUpload">Cancel Upload</template>
              <template v-else>Upload Photos</template>
            </button>
          </div>
        </div>

        <!-- Upload Section -->
        <div v-if="showUpload" class="card-xl mb-8">
          <h2 class="text-lg font-semibold text-slate-900 mb-2">Upload Photos</h2>
          <p class="text-sm text-slate-500 mb-6">
            Photos are encrypted on your device before upload. Nobody else can see them without your permission.
          </p>
          <UploadDropzone
            :child-id="childId"
            @uploaded="onPhotoUploaded"
            @done="onUploadDone"
          />
        </div>

        <!-- Empty State -->
        <div
          v-if="uploadedPhotos.length === 0"
          class="text-center py-20"
        >
          <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="empty-icon">
            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
            <circle cx="8.5" cy="8.5" r="1.5"/>
            <polyline points="21 15 16 10 5 21"/>
          </svg>
          <p class="mt-4 text-slate-500 text-lg font-medium">
            No photos yet
          </p>
          <p class="text-slate-400 text-sm mt-1">
            Upload some photos of {{ child.name }} to get started
          </p>
        </div>

        <!-- Uploaded Photos Grid (basic) -->
        <div v-if="uploadedPhotos.length > 0" class="mt-8">
          <h2 class="text-lg font-semibold text-slate-900 mb-4">
            Recently uploaded ({{ uploadedPhotos.length }})
          </h2>
          <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
            <div
              v-for="photo in uploadedPhotos"
              :key="photo.id"
              class="bg-slate-100 rounded-lg aspect-square flex items-center justify-center text-slate-400 text-sm"
            >
              Photo #{{ photo.id }}
            </div>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>
