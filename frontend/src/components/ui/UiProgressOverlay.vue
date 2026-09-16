<script setup lang="ts">
import { computed } from 'vue'

/**
 * Batch upload overlay — plain, calm language; no jargon, no spinners.
 * "Processing 12 of 100…" is all the user needs to see.
 */
const props = withDefaults(
  defineProps<{
    active: boolean
    current: number
    total: number
    fileName?: string
  }>(),
  { fileName: '' },
)

const percent = computed(() =>
  props.total > 0 ? Math.round((props.current / props.total) * 100) : 0,
)
</script>

<template>
  <Teleport v-if="active" to="body">
    <div class="overlay" role="status" aria-live="polite" aria-atomic="true">
      <div class="overlay__card">
        <p class="overlay__title">Processing {{ current }} of {{ total }}…</p>
        <div
          class="overlay__track"
          role="progressbar"
          :aria-valuenow="current"
          aria-valuemin="0"
          :aria-valuemax="total"
        >
          <div class="overlay__fill" :style="{ width: `${percent}%` }" />
        </div>
        <p v-if="fileName" class="overlay__name">{{ fileName }}</p>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.overlay {
  position: fixed;
  inset: 0;
  z-index: var(--z-upload-overlay);
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--color-overlay);
  padding: var(--space-6);
}

.overlay__card {
  width: 100%;
  max-width: 24rem;
  background: var(--color-surface);
  border-radius: var(--radius-sheet);
  box-shadow: var(--shadow-sheet);
  padding: var(--space-8);
  text-align: center;
}

.overlay__title {
  font-size: var(--text-title);
  font-weight: 800;
  line-height: var(--lh-title);
  color: var(--color-ink);
  margin: 0 0 var(--space-4);
}

.overlay__track {
  width: 100%;
  height: 12px;
  background: var(--color-surface-2);
  border-radius: var(--radius-pill);
  overflow: hidden;
}

.overlay__fill {
  height: 100%;
  background: var(--color-accent);
  border-radius: var(--radius-pill);
  transition: width var(--dur-standard) var(--ease-soft);
}

.overlay__name {
  margin: var(--space-3) 0 0;
  color: var(--color-ink-soft);
  font-size: var(--text-body);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

@media (prefers-reduced-motion: reduce) {
  .overlay__fill {
    transition: none;
  }
}
</style>
