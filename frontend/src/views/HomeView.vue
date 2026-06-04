<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useChildStore } from '@/stores/child'
import { useRouter } from 'vue-router'
import { ageAt, getAvatarColor, getInitials } from '@/utils/childHelpers'
import type { Child } from '@/types/child'

const authStore = useAuthStore()
const childStore = useChildStore()
const router = useRouter()

const hasLoaded = ref(false)

onMounted(async () => {
  try {
    await childStore.fetchChildren()
  } catch {
    // Error is handled by the store
  } finally {
    hasLoaded.value = true
  }
})

function goToChild(child: Child) {
  childStore.setCurrentChild(child.id)
  router.push(`/child/${child.id}`)
}

function goToCreateChild() {
  router.push('/child/create')
}

function handleLogout() {
  authStore.logout()
  router.push('/login')
}

function formatBytes(bytes: number): string {
  if (bytes === 0) return '0 MB'
  const mb = bytes / (1024 * 1024)
  if (mb < 1024) return `${mb.toFixed(0)} MB`
  return `${(mb / 1024).toFixed(1)} GB`
}
</script>

<template>
  <div class="min-h-screen bg-slate-50">
    <nav class="bg-white shadow-sm border-b border-slate-200">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center h-16">
          <div class="flex items-center gap-2">
            <svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
            </svg>
            <h1 class="text-xl font-bold text-slate-900">Avapeek</h1>
          </div>
          <div class="flex items-center gap-4">
            <span class="text-sm text-slate-600">{{ authStore.currentUser?.display_name }}</span>
            <button @click="handleLogout" class="py-2 px-4 bg-slate-100 hover:bg-slate-200 text-slate-700 font-medium rounded-lg transition-colors duration-200 text-sm">Logout</button>
          </div>
        </div>
      </div>
    </nav>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
      <div class="flex items-center justify-between mb-8">
        <h2 class="text-2xl font-bold text-slate-900">My Children</h2>
        <button
          @click="goToCreateChild"
          class="inline-flex items-center gap-2 py-2.5 px-5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors duration-200 text-sm"
        >
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Add Child
        </button>
      </div>

      <div v-if="!hasLoaded" class="flex justify-center py-20">
        <span class="inline-block w-8 h-8 border-2 border-blue-600 border-t-transparent rounded-full animate-spin"></span>
      </div>

      <div v-else-if="!childStore.hasChildren" class="text-center py-20">
        <svg class="w-20 h-20 mx-auto text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
        </svg>
        <h3 class="mt-6 text-lg font-medium text-slate-600">Add your first child to start building their photo album</h3>
        <button
          @click="goToCreateChild"
          class="mt-4 inline-flex items-center gap-2 py-2.5 px-5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors duration-200 text-sm"
        >
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Add Child
        </button>
      </div>

      <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
        <div
          v-for="child in childStore.children"
          :key="child.id"
          @click="goToChild(child)"
          class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 cursor-pointer hover:shadow-md hover:border-blue-300 transition-all duration-200"
        >
          <div class="flex items-center gap-4 mb-4">
            <div
              class="w-14 h-14 rounded-full flex items-center justify-center text-white text-xl font-bold shrink-0"
              :style="{ backgroundColor: child.avatar_color || getAvatarColor(child.id) }"
            >
              {{ getInitials(child.name) }}
            </div>
            <div class="min-w-0">
              <h3 class="text-lg font-semibold text-slate-900 truncate">{{ child.name }}</h3>
              <p class="text-sm text-slate-500">{{ ageAt(child.birth_date) }}</p>
            </div>
          </div>

          <div class="space-y-2">
            <div class="flex items-center justify-between text-sm">
              <span class="text-slate-500">{{ child.photo_count ?? 0 }} photos</span>
              <span class="text-slate-500">{{ formatBytes(child.storage_used) }}</span>
            </div>
          </div>
        </div>
      </div>
    </main>
  </div>
</template>