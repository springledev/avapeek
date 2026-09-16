<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(
  defineProps<{
    variant?: 'accent' | 'subtle' | 'ghost' | 'danger'
    size?: 'md' | 'lg'
    loading?: boolean
    disabled?: boolean
    type?: 'button' | 'submit' | 'reset'
  }>(),
  {
    variant: 'accent',
    size: 'md',
    loading: false,
    disabled: false,
    type: 'button',
  },
)

const emit = defineEmits<{ (e: 'click', event: MouseEvent): void }>()

const isDisabled = computed(() => props.disabled || props.loading)

function onClick(event: MouseEvent) {
  if (isDisabled.value) return
  emit('click', event)
}
</script>

<template>
  <button
    :type="type"
    class="uib u-focus-ring"
    :class="[`uib--${variant}`, `uib--${size}`]"
    :disabled="isDisabled"
    :aria-busy="loading || undefined"
    @click="onClick"
  >
    <span v-if="loading" class="uib__spinner" aria-hidden="true" />
    <slot />
  </button>
</template>

<style scoped>
.uib {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-2);
  padding: var(--space-3) var(--space-6);
  min-height: var(--target-min);
  border: 0;
  border-radius: var(--radius-card);
  font-family: inherit;
  font-size: var(--text-body);
  font-weight: 700;
  cursor: pointer;
  transition:
    background-color var(--dur-micro) var(--ease-soft),
    transform var(--dur-micro) var(--ease-soft);
}

.uib--lg {
  min-height: 56px;
  padding: var(--space-4) var(--space-8);
}

.uib--accent {
  background: var(--color-accent);
  color: var(--color-on-accent);
}

.uib--subtle {
  background: var(--color-surface-2);
  color: var(--color-ink);
}

.uib--ghost {
  background: transparent;
  color: var(--color-accent-ink);
}

.uib--danger {
  background: var(--color-danger);
  color: var(--color-on-danger);
}

.uib:active {
  transform: scale(0.98);
}

.uib:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.uib__spinner {
  width: 1em;
  height: 1em;
  border: 2px solid currentColor;
  border-top-color: transparent;
  border-radius: 50%;
  animation: spin 0.7s linear infinite;
}

@media (prefers-reduced-motion: reduce) {
  .uib__spinner {
    animation: none;
    border-top-color: currentColor;
  }
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
