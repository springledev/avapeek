<script setup lang="ts">
import { computed, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useChildStore } from '@/stores/child'
import { useAuthStore } from '@/stores/auth'
import { useRecentsStore } from '@/stores/recents'
import { useFeedStore } from '@/stores/feed'
import AppTabBar from '@/components/ui/AppTabBar.vue'

const route = useRoute()
const authStore = useAuthStore()
const childStore = useChildStore()
const recentsStore = useRecentsStore()
const feedStore = useFeedStore()

const showShell = computed(() => {
  const authPaths = ['/login', '/register', '/invite/']
  return !authPaths.some((p) => route.path.startsWith(p))
})

/** User id the shell data was last loaded for, so it loads once per session. */
let loadedForUserId: number | null = null

/**
 * The shell can first mount on /login, so loading on mount would miss data after
 * signing in without a reload. Load whenever the shell shows for a signed-in user.
 */
watch(
  () => [showShell.value, authStore.currentUser?.id ?? null] as const,
  async ([visible, userId]) => {
    if (!visible || userId === null || loadedForUserId === userId) return
    loadedForUserId = userId
    try {
      await childStore.fetchChildren()
    } catch {
      // Allow a retry the next time the shell shows.
      loadedForUserId = null
      return
    }
    void recentsStore.fetchUnseenCount()
    void feedStore.fetchPhotos()
  },
  { immediate: true },
)
</script>

<template>
  <template v-if="showShell">
    <div class="shell">
      <AppTabBar />
      <main class="shell__content">
        <router-view />
      </main>
    </div>
  </template>
  <template v-else>
    <router-view />
  </template>
</template>

<style scoped>
.shell {
  min-height: 100dvh;
  display: flex;
  flex-direction: column;
  background: var(--color-bg);
}

.shell__content {
  flex: 1;
  padding: var(--space-4);
  padding-top: calc(var(--space-4) + var(--safe-area-t));
  padding-left: max(var(--space-4), env(safe-area-inset-left, 0px));
  padding-right: max(var(--space-4), env(safe-area-inset-right, 0px));
  padding-bottom: calc(var(--tabbar-h) + var(--safe-area-b) + var(--space-4));
}

@media (min-width: 640px) {
  .shell {
    flex-direction: row;
  }

  .shell__content {
    padding-bottom: calc(var(--space-6) + var(--safe-area-b));
    padding-left: var(--space-6);
  }
}
</style>
