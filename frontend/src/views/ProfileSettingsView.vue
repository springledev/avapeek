<script setup lang="ts">
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()
</script>

<template>
  <div class="mt-8 rounded-xl border border-gray-200 bg-white p-6 dark:border-slate-600 dark:bg-slate-800">
    <h2 class="mb-4 text-lg font-semibold text-slate-900 dark:text-white">Profile</h2>

    <div class="space-y-4">
      <div>
        <label class="text-sm font-medium text-slate-700 dark:text-slate-300">Display Name</label>
        <p class="mt-1 text-sm text-slate-900 dark:text-white">{{ authStore.user?.display_name }}</p>
      </div>

      <div>
        <label class="text-sm font-medium text-slate-700 dark:text-slate-300">Email</label>
        <p class="mt-1 text-sm text-slate-900 dark:text-white">{{ authStore.user?.email }}</p>
      </div>
    </div>
  </div>
</template>
