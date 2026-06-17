<script setup lang="ts">
import { useAuthStore } from '@/stores/auth'
import { useRouter } from 'vue-router'
import ThemeToggle from '@/components/ThemeToggle.vue'

const authStore = useAuthStore()
const router = useRouter()

async function handleLogout() {
  await authStore.logout()
  router.push('/login')
}
</script>

<template>
  <nav class="nav-bar">
    <div class="nav-container">
      <div class="nav-content">
        <div class="flex items-center gap-2">
          <svg class="w-8 h-8 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
          </svg>
          <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">Avapeek</h1>
        </div>
        <slot name="center" />
        <div class="flex items-center gap-2">
          <ThemeToggle />
          <span class="text-sm text-slate-600 dark:text-slate-400">{{ authStore.currentUser?.display_name }}</span>
          <button @click="handleLogout" class="btn-logout">Logout</button>
        </div>
      </div>
    </div>
  </nav>
</template>
