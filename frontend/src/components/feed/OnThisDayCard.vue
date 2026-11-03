<script setup lang="ts">
import { onMounted } from 'vue'
import { useMemories } from '@/composables/useMemories'
import PhotoTile from '@/components/feed/PhotoTile.vue'
import type { Photo } from '@/types/photo'

const emit = defineEmits<{
  (e: 'open', photo: Photo): void
}>()

const { groups, isVisible, load } = useMemories()

onMounted(() => {
  void load()
})

function yearsAgoLabel(year: number): string {
  const diff = new Date().getFullYear() - year
  return diff === 1 ? '1 year ago' : `${diff} years ago`
}
</script>

<template>
  <section v-if="isVisible" class="otd" aria-labelledby="otd-title">
    <h2 id="otd-title" class="otd__title">On this day</h2>
    <div class="otd__strip">
      <div v-for="group in groups" :key="group.year" class="otd__group">
        <span class="otd__year">{{ group.year }} · {{ yearsAgoLabel(group.year) }}</span>
        <div class="otd__row">
          <div v-for="photo in group.photos" :key="photo.id" class="otd__item">
            <PhotoTile :photo="photo" @click="emit('open', photo)" />
          </div>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
/*
 * Compact strip: one horizontally scrolling line of fixed-size thumbnails, so
 * the card costs one tile of height at any viewport width (tiles are >= 48px).
 */
.otd {
  margin: 0 0 var(--space-4);
}

.otd__title {
  font-size: var(--text-meta);
  font-weight: 800;
  color: var(--color-ink);
  margin: 0 0 var(--space-2);
}

.otd__strip {
  display: flex;
  align-items: flex-end;
  gap: var(--space-4);
  overflow-x: auto;
}

.otd__group {
  flex: 0 0 auto;
}

.otd__year {
  display: block;
  font-size: var(--text-meta);
  font-weight: 700;
  color: var(--color-ink-soft);
  margin: 0 0 var(--space-1);
  white-space: nowrap;
}

.otd__row {
  display: flex;
  gap: var(--feed-gap);
}

.otd__item {
  flex: 0 0 72px;
  width: 72px;
}
</style>
