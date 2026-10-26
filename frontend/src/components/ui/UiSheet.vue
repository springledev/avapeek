<script setup lang="ts">
import { computed, useId } from 'vue'

/**
 * Dialog shell: bottom sheet on mobile, centered card on >=640px.
 * Backdrop and Esc close. Whatever lives inside decides admin-ness —
 * the shell is just a container.
 */
const props = withDefaults(
  defineProps<{
    open: boolean
    title?: string
    /** Stack above the full-screen photo viewer (which itself sits above regular sheets). */
    elevated?: boolean
  }>(),
  { title: '', elevated: false },
)

const emit = defineEmits<{ (e: 'close'): void }>()

const titleId = useId()
const labelledBy = computed(() => (props.title ? titleId : undefined))

function onEscape(event: KeyboardEvent) {
  if (props.open && event.key === 'Escape') {
    emit('close')
  }
}
</script>

<template>
  <Teleport v-if="open" to="body">
    <div
      class="sheet"
      :class="{ 'sheet--elevated': elevated }"
      role="dialog"
      aria-modal="true"
      :aria-labelledby="labelledBy"
      tabindex="-1"
      @keydown="onEscape"
    >
      <button type="button" class="sheet__backdrop" aria-label="Close" @click="emit('close')" />
      <div class="sheet__card">
        <h2 v-if="title" :id="titleId" class="sheet__title">{{ title }}</h2>
        <div class="sheet__body">
          <slot />
        </div>
        <footer v-if="$slots.footer" class="sheet__footer">
          <slot name="footer" />
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.sheet {
  position: fixed;
  inset: 0;
  z-index: var(--z-sheet);
  display: flex;
  align-items: flex-end;
  justify-content: center;
  outline: none;
}

.sheet--elevated {
  z-index: var(--z-viewer-sheet);
}

.sheet__backdrop {
  position: absolute;
  inset: 0;
  border: 0;
  background: var(--color-overlay);
  cursor: pointer;
}

.sheet__card {
  position: relative;
  width: 100%;
  max-width: 100%;
  background: var(--color-surface);
  color: var(--color-ink);
  border-radius: var(--radius-sheet) var(--radius-sheet) 0 0;
  box-shadow: var(--shadow-sheet);
  padding: var(--space-6);
  padding-bottom: calc(var(--space-6) + var(--safe-area-b));
  padding-left: max(var(--space-6), env(safe-area-inset-left, 0px));
  padding-right: max(var(--space-6), env(safe-area-inset-right, 0px));
  animation: sheet-in var(--dur-standard) var(--ease-soft) both;
  max-height: 88vh;
  overflow-y: auto;
}

@media (min-width: 640px) {
  .sheet {
    align-items: center;
  }
  .sheet__card {
    max-width: 30rem;
    border-radius: var(--radius-sheet);
    padding-bottom: var(--space-6);
  }
}

.sheet__title {
  font-size: var(--text-title);
  font-weight: 800;
  line-height: var(--lh-title);
  margin: 0 0 var(--space-4);
}

.sheet__footer {
  margin-top: var(--space-6);
  display: flex;
  justify-content: flex-end;
  gap: var(--space-3);
}

@media (prefers-reduced-motion: reduce) {
  .sheet__card {
    animation: none;
  }
}

@keyframes sheet-in {
  from {
    opacity: 0;
    transform: translateY(24px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
</style>
