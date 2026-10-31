<script setup lang="ts">
import { useRouter } from 'vue-router'
import { Image } from '@lucide/vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiEmptyState from '@/components/ui/UiEmptyState.vue'
import { MSG } from '@/lib/messages'

const router = useRouter()

function goToFeed() {
  router.push('/feed')
}
</script>

<template>
  <div class="welcome">
    <UiEmptyState :title="MSG.onboardingWelcome" :message="MSG.welcomeWaiting">
      <template #icon>
        <Image :size="64" />
      </template>
      <template #action>
        <UiButton variant="accent" size="lg" @click="goToFeed">
          {{ MSG.seePhotos }}
        </UiButton>
      </template>
    </UiEmptyState>
  </div>
</template>

<style scoped>
.welcome {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: calc(100dvh - var(--tabbar-h) - var(--safe-area-b));
  padding: var(--space-6);
}

@media (min-width: 640px) {
  .welcome {
    min-height: 100dvh;
  }
}
</style>
