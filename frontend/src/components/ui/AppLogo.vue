<script setup lang="ts">
/**
 * Brand mark (peeking Polaroid), optionally paired with the "Avapeek" wordmark.
 * The mark carries its own accent background, so it reads the same in both modes.
 */
import logoUrl from '@/assets/logo.svg'

withDefaults(
  defineProps<{
    size?: number
    wordmark?: boolean
  }>(),
  { size: 40, wordmark: false },
)
</script>

<template>
  <span class="app-logo">
    <img
      class="app-logo__mark"
      :src="logoUrl"
      :width="size"
      :height="size"
      :alt="wordmark ? '' : 'Avapeek'"
    />
    <span v-if="wordmark" class="app-logo__wordmark">Avapeek</span>
  </span>
</template>

<style scoped>
.app-logo {
  display: inline-flex;
  align-items: center;
  gap: var(--space-2);
}

.app-logo__mark {
  display: block;
  flex-shrink: 0;
}

.app-logo__wordmark {
  font-size: var(--text-title);
  font-weight: 800;
  line-height: var(--lh-tight);
  letter-spacing: -0.01em;
  color: var(--color-ink);
}
</style>
