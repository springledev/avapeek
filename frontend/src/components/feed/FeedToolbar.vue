<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useFeedStore } from '@/stores/feed'
import { useChildStore } from '@/stores/child'
import { Calendar } from '@lucide/vue'
import UiChip from '@/components/ui/UiChip.vue'
import UiSheet from '@/components/ui/UiSheet.vue'

const route = useRoute()
const router = useRouter()
const feedStore = useFeedStore()
const childStore = useChildStore()

const showMonthSheet = computed({
  get: () => route.query.monthSheet === '1',
  set: (v: boolean) => {
    const query = { ...route.query }
    if (v) query.monthSheet = '1'
    else delete query.monthSheet
    router.replace({ query })
  },
})

const selectedChildIds = computed(() => feedStore.filters.childIds)
const selectedMonth = computed(() => {
  if (!feedStore.filters.dateFrom) return null
  const d = new Date(feedStore.filters.dateFrom)
  return d.toLocaleString('default', { month: 'long', year: 'numeric' })
})

const availableMonths = computed(() => {
  const months = new Set<string>()
  for (const photo of feedStore.photos) {
    if (photo.taken_at) {
      months.add(
        new Date(photo.taken_at).toLocaleString('default', { month: 'long', year: 'numeric' }),
      )
    }
  }
  return [...months].sort((a, b) => new Date(b).getTime() - new Date(a).getTime())
})

function toggleChild(childId: number) {
  const current = [...feedStore.filters.childIds]
  const idx = current.indexOf(childId)
  if (idx >= 0) current.splice(idx, 1)
  else current.push(childId)
  feedStore.setChildFilter(current)
  feedStore.fetchPhotos()
}

function removeChild(childId: number) {
  const current = feedStore.filters.childIds.filter((id) => id !== childId)
  feedStore.setChildFilter(current)
  feedStore.fetchPhotos()
}

function selectMonth(month: string) {
  const d = new Date(month)
  const from = new Date(d.getFullYear(), d.getMonth(), 1).toISOString().split('T')[0]!
  const to = new Date(d.getFullYear(), d.getMonth() + 1, 0).toISOString().split('T')[0]!
  feedStore.setDateRange(from, to)
  feedStore.fetchPhotos()
  showMonthSheet.value = false
}

function clearMonth() {
  feedStore.setDateRange(null, null)
  feedStore.fetchPhotos()
  showMonthSheet.value = false
}
</script>

<template>
  <div class="toolbar">
    <div class="toolbar__scroll">
      <UiChip
        v-for="child in childStore.children"
        :key="child.id"
        :label="child.name"
        tone="kid"
        :kid-id="child.id"
        :kid-color="child.avatar_color"
        :selected="selectedChildIds.includes(child.id)"
        :remover="selectedChildIds.includes(child.id)"
        @toggle="toggleChild(child.id)"
        @remove="removeChild(child.id)"
      />

      <UiChip
        :label="selectedMonth || 'Month'"
        tone="date"
        :selected="!!selectedMonth"
        :remover="!!selectedMonth"
        @toggle="showMonthSheet = true"
        @remove="clearMonth"
      >
        <Calendar class="toolbar__cal" :size="16" aria-hidden="true" />
      </UiChip>
    </div>
  </div>

  <UiSheet :open="showMonthSheet" title="Pick a month" @close="showMonthSheet = false">
    <div class="month-list">
      <button
        v-for="month in availableMonths"
        :key="month"
        type="button"
        class="month-list__item u-focus-ring"
        :class="{ 'month-list__item--selected': selectedMonth === month }"
        @click="selectMonth(month)"
      >
        {{ month }}
      </button>
    </div>
  </UiSheet>
</template>

<style scoped>
.toolbar {
  margin-bottom: var(--space-4);
}

.toolbar__scroll {
  display: flex;
  gap: var(--space-2);
  overflow-x: auto;
  padding-bottom: var(--space-2);
  scrollbar-width: none;
}

.toolbar__scroll::-webkit-scrollbar {
  display: none;
}

.toolbar__cal {
  color: var(--color-accent-ink);
}

.month-list {
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
}

.month-list__item {
  display: block;
  width: 100%;
  text-align: left;
  padding: var(--space-3) var(--space-4);
  border: 0;
  border-radius: var(--radius-card);
  background: transparent;
  color: var(--color-ink);
  font-family: inherit;
  font-size: var(--text-body);
  font-weight: 600;
  cursor: pointer;
  min-height: var(--target-min);
}

.month-list__item:hover {
  background: var(--color-surface-2);
}

.month-list__item--selected {
  background: var(--color-accent-soft);
  color: var(--color-accent-ink);
}
</style>
