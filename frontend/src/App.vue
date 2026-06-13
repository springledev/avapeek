<script setup lang="ts">
import { onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()

onMounted(async () => {
  await authStore.restoreKEK()
})
</script>

<template>
  <RouterView />
</template>

<style scoped></style>
