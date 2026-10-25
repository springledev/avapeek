<script setup lang="ts">
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
    :class="{ 'tag-chip--clickable': clickable }"
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
      ✕
    </button>
  </span>
</template>

<style scoped>
.tag-chip {
  display: inline-flex;
  align-items: center;
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
  padding: var(--space-1) var(--space-3);
  white-space: nowrap;
}

.tag-chip__remove {
  align-self: stretch;
  min-width: 32px;
  border: 0;
  border-left: 1px solid var(--color-border);
  background: transparent;
  color: inherit;
  font-size: var(--text-meta);
  cursor: pointer;
}
</style>
