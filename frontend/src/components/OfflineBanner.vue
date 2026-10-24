<script setup lang="ts">
import { useNetwork } from '@/composables/useNetwork'

const { isOnline } = useNetwork()
</script>

<template>
  <div
    v-if="!isOnline"
    role="status"
    data-testid="offline-banner"
    class="flex min-h-12 items-center justify-center px-4 text-sm font-semibold"
    style="background: var(--color-surface-2); color: var(--color-ink); border-bottom: 1px solid var(--color-border)"
  >
    Offline. Showing the app shell and photos already on this device.
  </div>
</template>
