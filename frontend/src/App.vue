<script setup lang="ts">
import { onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()

onMounted(() => {
  // Initialize auth state from localStorage on app mount
  authStore.initializeAuth()
})
</script>

<template>
  <RouterView />
</template>

<style scoped></style>
