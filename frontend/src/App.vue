<script setup lang="ts">
import { onMounted, onBeforeUnmount, watch } from 'vue'
import { Toaster } from 'vue-sonner'
import { useAuthStore } from '@/stores/auth'
import { useChildStore } from '@/stores/child'
import { useDarkMode } from '@/composables/useDarkMode'
import { useNetwork } from '@/composables/useNetwork'
import AppShell from '@/components/AppShell.vue'
import OfflineBanner from '@/components/OfflineBanner.vue'
import { syncEncryptionKeys } from '@/services/key-sync'
import { resyncPushSubscription } from '@/services/push'

const authStore = useAuthStore()
const childStore = useChildStore()
const { initDarkMode } = useDarkMode()
const { initNetwork, cleanup } = useNetwork()

initDarkMode()
initNetwork()

const KEY_SYNC_INTERVAL_MS = 60_000
/** How often to look for a shared key while an album the user joined is still locked. */
const KEY_WAIT_INTERVAL_MS = 15_000
let lastKeySync = 0
let keyWaitTimer: ReturnType<typeof setInterval> | null = null

/** Albums the user joined but cannot open yet: an admin still has to share the key. */
function hasLockedJoinedAlbum(): boolean {
  return childStore.children.some((c) => c.role !== 'owner' && !childStore.hasOwnKey(c.id))
}

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
  keyWaitTimer = setInterval(() => {
    if (document.visibilityState === 'visible' && hasLockedJoinedAlbum()) syncKeys()
  }, KEY_WAIT_INTERVAL_MS)
  await authStore.restoreKEK()
  syncKeys()
  if (authStore.isAuthenticated) {
    void resyncPushSubscription().catch(() => undefined)
  }
})

onBeforeUnmount(() => {
  document.removeEventListener('visibilitychange', onVisibilityChange)
  if (keyWaitTimer) clearInterval(keyWaitTimer)
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
