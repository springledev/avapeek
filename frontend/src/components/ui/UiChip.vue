<script setup lang="ts">
import { computed } from 'vue'
import UiAvatar from './UiAvatar.vue'

/**
 * Feed filter chip. Two modes:
 *  - toggle  (child/tag/date selectors): emits 'toggle' on press
 *  - remover (active-filter row): shows an ✕ that emits 'remove'
 * Tones: 'neutral' | 'kid' | 'tag' | 'date'. Kid chips render the mini avatar.
 */
const props = withDefaults(
  defineProps<{
    label: string
    tone?: 'neutral' | 'kid' | 'tag' | 'date'
    selected?: boolean
    remover?: boolean
    kidId?: number
    kidColor?: string | null
  }>(),
  {
    tone: 'neutral',
    selected: false,
    remover: false,
  },
)

const emit = defineEmits<{
  (e: 'toggle'): void
  (e: 'remove'): void
}>()

const showKidAvatar = computed(() => props.tone === 'kid' && props.kidId !== undefined)
</script>

<template>
  <div class="chip" :class="{ 'chip--selected': selected }">
    <button
      type="button"
      class="chip__hit u-focus-ring"
      :aria-pressed="selected || undefined"
      @click="emit('toggle')"
    >
      <UiAvatar
        v-if="showKidAvatar"
        :id="kidId!"
        :name="label"
        :color-hex="kidColor"
        inline-style
        class="chip__avatar"
      />
      <span class="chip__label">{{ label }}</span>
      <slot />
    </button>
    <button
      v-if="remover"
      type="button"
      class="chip__remove u-focus-ring"
      :aria-label="`Remove ${label} filter`"
      @click="emit('remove')"
    >
      ✕
    </button>
  </div>
</template>

<style scoped>
.chip {
  display: inline-flex;
  align-items: stretch;
  border-radius: var(--radius-pill);
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  box-shadow: var(--shadow-1);
  color: var(--color-ink);
  overflow: hidden;
}

.chip__hit {
  display: inline-flex;
  align-items: center;
  gap: var(--space-2);
  padding: var(--space-2) var(--space-4);
  min-height: 52px;
  border: 0;
  background: transparent;
  color: inherit;
  font-family: inherit;
  font-size: var(--text-body);
  font-weight: 600;
  cursor: pointer;
}

.chip--selected {
  background: var(--color-accent-soft);
  border-color: var(--color-accent);
  color: var(--color-accent-ink);
}

.chip__label {
  white-space: nowrap;
}

.chip__avatar {
  width: 24px;
  height: 24px;
  font-size: 0.6rem;
}

.chip__remove {
  min-width: var(--target-min);
  min-height: var(--target-min);
  border: 0;
  border-left: 1px solid var(--color-border);
  background: transparent;
  color: var(--color-ink-soft);
  font-size: var(--text-body);
  cursor: pointer;
}
</style>
