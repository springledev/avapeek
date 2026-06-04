<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useChildStore } from '@/stores/child'
import { useRouter } from 'vue-router'
import { ageAt, getAvatarColor, getInitials } from '@/utils/childHelpers'
import type { Child } from '@/types/child'
import AppHeader from '@/components/AppHeader.vue'

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

function goToMembers(child: Child, event: Event) {
  event.stopPropagation()
  childStore.setCurrentChild(child.id)
  router.push(`/child/${child.id}/members`)
}

function goToCreateChild() {
  router.push('/child/create')
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
    <AppHeader />

    <main class="main-container">
      <div class="flex items-center justify-between mb-8">
        <h2 class="text-2xl font-bold text-slate-900">My Children</h2>
        <button
          @click="goToCreateChild"
          class="btn-action"
        >
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Add Child
        </button>
      </div>

      <div v-if="!hasLoaded" class="flex justify-center py-20">
        <span class="spinner-large"></span>
      </div>

      <div v-else-if="!childStore.hasChildren" class="text-center py-20">
        <svg class="empty-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
        </svg>
        <h3 class="mt-6 text-lg font-medium text-slate-600">Add your first child to start building their photo album</h3>
        <button
          @click="goToCreateChild"
          class="btn-action mt-4"
        >
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Add Child
        </button>
      </div>

      <div v-else class="child-grid">
        <div
          v-for="child in childStore.children"
          :key="child.id"
          @click="goToChild(child)"
          class="child-card"
        >
          <div class="flex items-center gap-4 mb-4">
            <div
              class="avatar-circle"
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
              <span class="text-slate-500" v-if="child.role === 'owner' || child.role === 'admin'">{{ formatBytes(child.storage_used) }}</span>
            </div>
            <div class="flex items-center justify-between text-sm"
              v-if="child.role === 'owner' || child.role === 'admin'">
              <span class="text-slate-500">{{ child.member_count ?? 1 }} {{ (child.member_count ?? 1) === 1 ? 'member' : 'members' }}</span>
              <button
                @click="goToMembers(child, $event)"
                class="text-xs text-blue-600 hover:text-blue-700 font-medium"
              >
                Manage
              </button>
            </div>
          </div>
        </div>
      </div>
    </main>
  </div>
</template>
