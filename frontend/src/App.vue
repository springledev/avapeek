<script setup lang="ts">
import { onMounted, onBeforeUnmount } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useDarkMode } from '@/composables/useDarkMode'
import { useNetwork } from '@/composables/useNetwork'

const authStore = useAuthStore()
const { initDarkMode } = useDarkMode()
const { initNetwork, cleanup } = useNetwork()

initDarkMode()
initNetwork()

onMounted(async () => {
  await authStore.restoreKEK()
})

onBeforeUnmount(() => {
  cleanup()
})
</script>

<template>
  <RouterView />
  <Toaster
    richColors
    closeButton
    position="top-right"
    :toast-options="{
      class: 'dark:bg-slate-800 dark:text-slate-100 dark:border-slate-700',
    }"
  />
</template>

<style scoped></style>
