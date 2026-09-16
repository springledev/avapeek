<script setup lang="ts">
/**
 * Warm empty state: icon slot, display title, plain-language body, action slot.
 */
withDefaults(
  defineProps<{
    title: string
    message?: string
  }>(),
  { message: '' },
)
</script>

<template>
  <div class="u-empty">
    <div v-if="$slots.icon" class="u-empty__icon" aria-hidden="true">
      <slot name="icon" />
    </div>
    <h2 class="u-empty__title">{{ title }}</h2>
    <p v-if="message" class="u-empty__message">{{ message }}</p>
    <div v-if="$slots.action" class="u-empty__action">
      <slot name="action" />
    </div>
  </div>
</template>

<style scoped>
.u-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  padding: var(--space-12) var(--space-6);
}

.u-empty__icon {
  margin-bottom: var(--space-4);
  color: var(--color-accent-ink);
}

.u-empty__title {
  font-size: var(--text-display);
  font-weight: 800;
  line-height: var(--lh-tight);
  color: var(--color-ink);
  margin: 0 0 var(--space-2);
}

.u-empty__message {
  font-size: var(--text-body);
  color: var(--color-ink-soft);
  max-width: 40ch;
  margin: 0;
}

.u-empty__action {
  margin-top: var(--space-6);
}
</style>
