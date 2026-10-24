<script setup lang="ts">
import { onMounted, computed } from 'vue'
import { useRoute } from 'vue-router'
import { useChildStore } from '@/stores/child'
import { useRecentsStore } from '@/stores/recents'
import { useFeedStore } from '@/stores/feed'
import AppTabBar from '@/components/ui/AppTabBar.vue'

const route = useRoute()
const childStore = useChildStore()
const recentsStore = useRecentsStore()
const feedStore = useFeedStore()

const showShell = computed(() => {
  const authPaths = ['/login', '/register', '/invite/']
  return !authPaths.some((p) => route.path.startsWith(p))
})

onMounted(async () => {
  if (showShell.value) {
    await childStore.fetchChildren()
    recentsStore.fetchUnseenCount()
    feedStore.fetchPhotos()
  }
})
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
  padding-bottom: calc(var(--tabbar-h) + var(--safe-area-b) + var(--space-4));
}

@media (min-width: 640px) {
  .shell {
    flex-direction: row;
  }

  .shell__content {
    padding-bottom: var(--space-6);
    padding-left: var(--space-6);
  }
}
</style>
