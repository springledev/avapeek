<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useFeedStore } from '@/stores/feed'
import { useChildStore } from '@/stores/child'
import { useTagsStore } from '@/stores/tags'
import { Calendar, Tag } from '@lucide/vue'
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
const selectedTags = computed(() => feedStore.filters.tags)

function monthKey(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`
}

function monthLabel(key: string): string {
  const [year, month] = key.split('-').map(Number)
  return new Date(year!, month! - 1, 1).toLocaleString('default', { month: 'long', year: 'numeric' })
}

function dateString(date: Date): string {
  return `${monthKey(date)}-${String(date.getDate()).padStart(2, '0')}`
}

const selectedMonthKey = computed(() => feedStore.filters.dateFrom?.slice(0, 7) ?? null)
const selectedMonth = computed(() =>
  selectedMonthKey.value ? monthLabel(selectedMonthKey.value) : null,
)

// Remembered across refetches so the list doesn't collapse to the chosen month.
const knownMonthKeys = ref(new Set<string>())
watch(
  () => feedStore.photos,
  (photos) => {
    for (const photo of photos) {
      if (photo.taken_at) knownMonthKeys.value.add(monthKey(new Date(photo.taken_at)))
    }
  },
  { immediate: true, deep: false },
)

const availableMonths = computed(() => {
  const keys = new Set(knownMonthKeys.value)
  if (selectedMonthKey.value) keys.add(selectedMonthKey.value)
  return [...keys].sort().reverse().map((key) => ({ key, label: monthLabel(key) }))
})

const tagsStore = useTagsStore()
const showTagSheet = ref(false)
watch(
  () => childStore.children.map((c) => c.id),
  (ids) => {
    void tagsStore.loadAll(ids)
  },
  { immediate: true },
)
// suggest() returns nothing for an empty query, so list every tag directly.
const availableTags = computed(() => tagsStore.logicalTags)

function toggleTag(name: string) {
  const current = feedStore.filters.tags
  feedStore.setTagFilter(
    current.includes(name) ? current.filter((t) => t !== name) : [...current, name],
  )
  feedStore.fetchPhotos()
}

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

function selectMonth(key: string) {
  const [year, month] = key.split('-').map(Number)
  const from = dateString(new Date(year!, month! - 1, 1))
  const to = dateString(new Date(year!, month!, 0))
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

      <UiChip
        v-for="name in selectedTags"
        :key="name"
        :label="name"
        tone="tag"
        selected
        remover
        @toggle="showTagSheet = true"
        @remove="toggleTag(name)"
      />
      <UiChip v-if="availableTags.length > 0" label="Tags" tone="tag" @toggle="showTagSheet = true">
        <Tag class="toolbar__cal" :size="16" aria-hidden="true" />
      </UiChip>
    </div>
  </div>

  <UiSheet :open="showTagSheet" title="Filter by tag" @close="showTagSheet = false">
    <div class="month-list">
      <button
        v-for="tag in availableTags"
        :key="tag.key"
        type="button"
        class="month-list__item u-focus-ring"
        :class="{ 'month-list__item--selected': selectedTags.includes(tag.name) }"
        @click="toggleTag(tag.name)"
      >
        {{ tag.name }}
      </button>
    </div>
  </UiSheet>

  <UiSheet :open="showMonthSheet" title="Pick a month" @close="showMonthSheet = false">
    <div class="month-list">
      <button
        v-for="month in availableMonths"
        :key="month.key"
        type="button"
        class="month-list__item u-focus-ring"
        :class="{ 'month-list__item--selected': selectedMonthKey === month.key }"
        @click="selectMonth(month.key)"
      >
        {{ month.label }}
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

/* Chips scroll sideways; shrinking would squash and clip their labels. */
.toolbar__scroll > * {
  flex-shrink: 0;
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
