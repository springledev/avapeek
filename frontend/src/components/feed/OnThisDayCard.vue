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
    <div v-for="group in groups" :key="group.year" class="otd__group">
      <h3 class="otd__year">{{ group.year }} · {{ yearsAgoLabel(group.year) }}</h3>
      <div class="otd__row">
        <div v-for="photo in group.photos" :key="photo.id" class="otd__item">
          <PhotoTile :photo="photo" @click="emit('open', photo)" />
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
.otd {
  margin: 0 0 var(--space-6);
}

.otd__title {
  font-size: var(--text-body);
  font-weight: 800;
  color: var(--color-ink);
  margin: 0 0 var(--space-3);
}

.otd__group {
  margin-bottom: var(--space-3);
}

.otd__year {
  font-size: var(--text-meta);
  font-weight: 700;
  color: var(--color-ink-soft);
  margin: 0 0 var(--space-2);
}

.otd__row {
  display: flex;
  gap: var(--feed-gap);
  overflow-x: auto;
}

.otd__item {
  flex: 0 0 min(40%, 160px);
  min-width: 96px;
}
</style>
