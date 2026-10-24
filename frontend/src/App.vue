<script setup lang="ts">
import { onMounted, onBeforeUnmount, watch } from 'vue'
import { Toaster } from 'vue-sonner'
import { useAuthStore } from '@/stores/auth'
import { useDarkMode } from '@/composables/useDarkMode'
import { useNetwork } from '@/composables/useNetwork'
import AppShell from '@/components/AppShell.vue'
import OfflineBanner from '@/components/OfflineBanner.vue'
import { syncEncryptionKeys } from '@/services/key-sync'

const authStore = useAuthStore()
const { initDarkMode } = useDarkMode()
const { initNetwork, cleanup } = useNetwork()

initDarkMode()
initNetwork()

const KEY_SYNC_INTERVAL_MS = 60_000
let lastKeySync = 0

/** Shares and accepts album keys whenever keys become available or the app comes back into view. */
function syncKeys() {
  if (!authStore.kek) return
  lastKeySync = Date.now()
  void syncEncryptionKeys()
}

function onVisibilityChange() {
  if (document.visibilityState === 'visible' && Date.now() - lastKeySync > KEY_SYNC_INTERVAL_MS) {
    syncKeys()
  }
}

watch(() => authStore.kek, syncKeys)

onMounted(async () => {
  document.addEventListener('visibilitychange', onVisibilityChange)
  await authStore.restoreKEK()
  syncKeys()
})

onBeforeUnmount(() => {
  document.removeEventListener('visibilitychange', onVisibilityChange)
  cleanup()
})
</script>

<template>
  <OfflineBanner />
  <AppShell />
  <Toaster
    richColors
    closeButton
    position="top-right"
    :toast-options="{
      class: 'dark:bg-slate-800 dark:text-slate-100 dark:border-slate-700',
    }"
  />
</template>
