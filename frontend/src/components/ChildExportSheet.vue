<script setup lang="ts">
import { computed, watch } from 'vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiSheet from '@/components/ui/UiSheet.vue'
import { useChildExport } from '@/composables/useChildExport'
import { useMotionPolicy } from '@/composables/useMotionPolicy'

const props = defineProps<{
  open: boolean
  child: { id: number; name: string }
}>()

const emit = defineEmits<{ (e: 'close'): void }>()

const exporter = useChildExport()
const {
  state,
  total,
  totalBytes,
  photosDone,
  bytesDone,
  partIndex,
  partCount,
  failed,
  prepared,
  canResume,
  errorMessage,
} = exporter
const { reducedMotion: reduced } = useMotionPolicy()

const isBusy = computed(() => state.value === 'listing' || state.value === 'exporting')
const percent = computed(() =>
  total.value === 0 ? 0 : Math.min(100, Math.round((photosDone.value / total.value) * 100)),
)

function formatSize(bytes: number): string {
  if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`
  if (bytes < 1024 ** 3) return `${(bytes / 1024 ** 2).toFixed(0)} MB`
  return `${(bytes / 1024 ** 3).toFixed(1)} GB`
}

watch(
  () => props.open,
  (open) => {
    if (open && !isBusy.value && state.value !== 'done') {
      void exporter.prepare(props.child)
    }
  },
  { immediate: true },
)

function close() {
  if (isBusy.value) exporter.cancel()
  emit('close')
}
</script>

<template>
  <UiSheet :open="open" :title="`Export ${child.name}'s photos`" @close="close">
    <div class="export-sheet">
      <template v-if="state === 'listing' && !prepared">
        <p class="export-sheet__text" role="status">Counting photos…</p>
      </template>

      <template v-else-if="state === 'idle' || state === 'cancelled' || state === 'paused' || state === 'error'">
        <p v-if="errorMessage" class="export-sheet__error" role="alert">
          {{ errorMessage }}
        </p>
        <p class="export-sheet__text">
          {{ total }} {{ total === 1 ? 'photo' : 'photos' }}, about {{ formatSize(totalBytes) }}.
          You get the original files plus a manifest with dates, tags and comments.
        </p>
        <p class="export-sheet__warning">
          The files are saved unencrypted on this device. The manifest can include location data,
          comments and tag names. Keep the download somewhere safe.
        </p>
        <p class="export-sheet__text">
          Keep this screen open while it runs. On phones the export may be split into several zip
          files.
        </p>
        <UiButton v-if="canResume" variant="accent" @click="exporter.resume()">
          Resume
        </UiButton>
        <UiButton
          variant="accent"
          :disabled="!prepared || total === 0"
          @click="exporter.start()"
        >
          {{ canResume ? 'Start over' : 'Start export' }}
        </UiButton>
        <UiButton variant="ghost" @click="close">Close</UiButton>
      </template>

      <template v-else-if="isBusy">
        <p class="export-sheet__text" role="status">
          {{ photosDone }} of {{ total }} photos
          <span v-if="partCount > 1"> (zip {{ partIndex }} of {{ partCount }})</span>
        </p>
        <div
          class="export-sheet__bar"
          role="progressbar"
          aria-label="Export progress"
          aria-valuemin="0"
          aria-valuemax="100"
          :aria-valuenow="percent"
        >
          <div
            class="export-sheet__fill"
            :class="{ 'export-sheet__fill--still': reduced }"
            :style="{ width: `${percent}%` }"
          />
        </div>
        <p class="export-sheet__meta">{{ formatSize(bytesDone) }} saved</p>
        <p class="export-sheet__text">Keep this screen open until it finishes.</p>
        <UiButton variant="subtle" @click="exporter.cancel()">Cancel</UiButton>
      </template>

      <template v-else-if="state === 'done'">
        <p class="export-sheet__text" role="status">
          Export finished: {{ photosDone }} photos saved.
        </p>
        <p v-if="failed.length > 0" class="export-sheet__error" role="alert">
          {{ failed.length }} {{ failed.length === 1 ? 'photo' : 'photos' }} could not be exported
          and {{ failed.length === 1 ? 'is' : 'are' }} listed in the manifest.
        </p>
        <UiButton variant="accent" @click="close">Done</UiButton>
      </template>
    </div>
  </UiSheet>
</template>

<style scoped>
.export-sheet {
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
}

.export-sheet__text {
  margin: 0;
  font-size: var(--text-body);
  color: var(--color-ink);
}

.export-sheet__meta {
  margin: 0;
  font-size: var(--text-meta);
  color: var(--color-ink-soft);
}

.export-sheet__warning {
  margin: 0;
  padding: var(--space-3);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-card);
  background: var(--color-surface-2);
  font-size: var(--text-meta);
  color: var(--color-ink);
}

.export-sheet__error {
  margin: 0;
  padding: var(--space-3);
  border-radius: var(--radius-card);
  background: var(--color-danger);
  color: var(--color-on-danger);
  font-size: var(--text-body);
}

.export-sheet__bar {
  height: 8px;
  border-radius: 4px;
  background: var(--color-surface-2);
  overflow: hidden;
}

.export-sheet__fill {
  height: 100%;
  background: var(--color-accent);
  transition: width 200ms ease;
}

.export-sheet__fill--still {
  transition: none;
}
</style>
