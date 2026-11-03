<script setup lang="ts">
import UiButton from '@/components/ui/UiButton.vue'

/**
 * Shown when a fetch failed, so a network problem never reads as "no data".
 * `compact` renders an inline banner for when stale data is still on screen.
 */
withDefaults(
  defineProps<{
    title?: string
    message?: string
    compact?: boolean
    retrying?: boolean
  }>(),
  {
    title: "Couldn't load this",
    message: 'Nothing has been lost. Check your connection and try again.',
    compact: false,
    retrying: false,
  },
)

defineEmits<{ (e: 'retry'): void }>()
</script>

<template>
  <div
    class="load-error"
    :class="{ 'load-error--compact': compact }"
    role="alert"
    data-test="load-error"
  >
    <div class="load-error__text">
      <p class="load-error__title">{{ title }}</p>
      <p class="load-error__message">{{ message }}</p>
    </div>
    <UiButton
      variant="subtle"
      :loading="retrying"
      data-test="load-error-retry"
      @click="$emit('retry')"
    >
      Try again
    </UiButton>
  </div>
</template>

<style scoped>
.load-error {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: var(--space-4);
  padding: var(--space-12) var(--space-6);
}

.load-error--compact {
  flex-direction: row;
  justify-content: space-between;
  text-align: left;
  padding: var(--space-3) var(--space-4);
  margin: 0 0 var(--space-4);
  border-radius: var(--radius-card);
  background: var(--color-surface-2);
}

.load-error__title {
  margin: 0 0 var(--space-1);
  font-weight: 800;
  color: var(--color-ink);
  font-size: var(--text-body);
}

.load-error:not(.load-error--compact) .load-error__title {
  font-size: var(--text-display);
  line-height: var(--lh-tight);
}

.load-error__message {
  margin: 0;
  max-width: 40ch;
  color: var(--color-ink-soft);
  font-size: var(--text-body);
}
</style>
