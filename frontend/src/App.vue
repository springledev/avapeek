<script setup lang="ts">
import { onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()

onMounted(async () => {
  // Initialize auth state from localStorage/sessionStorage on app mount
  await authStore.initializeAuth()
})
</script>

<template>
  <RouterView />
</template>

<style scoped></style>
