<script setup lang="ts">
import { onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useDarkMode } from '@/composables/useDarkMode'

const authStore = useAuthStore()
const { initDarkMode } = useDarkMode()

initDarkMode()

onMounted(async () => {
  await authStore.restoreKEK()
})
</script>

<template>
  <RouterView />
</template>

<style scoped></style>
