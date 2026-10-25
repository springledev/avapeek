<script setup lang="ts">
import { X } from '@lucide/vue'

defineProps<{
  name: string
  clickable?: boolean
  removable?: boolean
}>()

const emit = defineEmits<{
  click: []
  remove: []
}>()
</script>

<template>
  <span
    class="tag-chip"
    :class="{ 'tag-chip--clickable': clickable, 'tag-chip--removable': removable }"
    @click="clickable && emit('click')"
  >
    <span class="tag-chip__label">{{ name }}</span>
    <button
      v-if="removable"
      type="button"
      class="tag-chip__remove u-focus-ring"
      :aria-label="`Remove tag ${name}`"
      @click.stop="emit('remove')"
    >
      <X :size="16" aria-hidden="true" />
    </button>
  </span>
</template>

<style scoped>
.tag-chip {
  display: inline-flex;
  align-items: center;
  max-width: 100%;
  border-radius: var(--radius-pill);
  background: var(--color-accent-soft);
  color: var(--color-accent-ink);
  font-size: var(--text-meta);
  font-weight: 600;
  overflow: hidden;
}

.tag-chip--clickable {
  cursor: pointer;
}

.tag-chip__label {
  min-width: 0;
  padding: var(--space-1) var(--space-3);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.tag-chip--removable {
  min-height: var(--target-min);
}

.tag-chip--removable .tag-chip__label {
  padding-left: var(--space-4);
  font-size: var(--text-body);
}

.tag-chip__remove {
  flex-shrink: 0;
  align-self: stretch;
  display: flex;
  align-items: center;
  justify-content: center;
  min-width: var(--target-min);
  border: 0;
  background: transparent;
  color: inherit;
  cursor: pointer;
}
</style>
