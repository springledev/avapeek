<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { Calendar, X } from '@lucide/vue'
import { useFeedStore } from '@/stores/feed'
import { useChildStore } from '@/stores/child'
import { useTagsStore } from '@/stores/tags'
import ChildChip from '@/components/ChildChip.vue'
import TagInput from '@/components/TagInput.vue'

const feedStore = useFeedStore()
const childStore = useChildStore()
const tagsStore = useTagsStore()

const showDateFilter = ref(false)
const dateFrom = ref('')
const dateTo = ref('')

const selectedChildIds = computed(() => feedStore.filters.childIds)
const selectedTags = computed(() => feedStore.filters.tags)

function toggleChild(childId: number) {
  const current = [...feedStore.filters.childIds]
  const idx = current.indexOf(childId)
  if (idx >= 0) {
    current.splice(idx, 1)
  } else {
    current.push(childId)
  }
  feedStore.setChildFilter(current)
  feedStore.fetchPhotos()
}

watch(
  () => childStore.children.map((c) => c.id),
  (ids) => {
    void tagsStore.loadAll(ids)
  },
  { immediate: true },
)

function addTag(tag: string) {
  if (!feedStore.filters.tags.includes(tag)) {
    feedStore.setTagFilter([...feedStore.filters.tags, tag])
    feedStore.fetchPhotos()
  }
}

function removeTag(tag: string) {
  const current = feedStore.filters.tags.filter(t => t !== tag)
  feedStore.setTagFilter(current)
  feedStore.fetchPhotos()
}

function applyDateRange() {
  feedStore.setDateRange(dateFrom.value || null, dateTo.value || null)
  feedStore.fetchPhotos()
}

function clearDateRange() {
  dateFrom.value = ''
  dateTo.value = ''
  feedStore.setDateRange(null, null)
  feedStore.fetchPhotos()
}

function resetAll() {
  feedStore.resetFilters()
  dateFrom.value = ''
  dateTo.value = ''
  feedStore.fetchPhotos()
}

const hasActiveFilters = computed(() =>
  feedStore.filters.childIds.length > 0 ||
  feedStore.filters.tags.length > 0 ||
  feedStore.filters.dateFrom !== null ||
  feedStore.filters.dateTo !== null,
)
</script>

<template>
  <div class="space-y-3">
    <div v-if="childStore.children.length > 0" class="flex flex-wrap gap-2">
      <ChildChip
        v-for="child in childStore.children"
        :key="child.id"
        :name="child.name"
        :color="child.avatar_color || '#6366f1'"
        :active="selectedChildIds.includes(child.id)"
        @toggle="toggleChild(child.id)"
      />
    </div>

    <div class="flex flex-wrap items-center gap-2">
      <div class="relative flex-1 max-w-xs">
        <TagInput
          :allow-create="false"
          :exclude="selectedTags"
          placeholder="Filter by tag..."
          @add="addTag"
        />
      </div>

      <button
        @click="showDateFilter = !showDateFilter"
        class="inline-flex items-center gap-1 rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-slate-600 transition-colors hover:bg-gray-100 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-700"
      >
        <Calendar class="h-4 w-4" />
        Dates
      </button>

      <button
        v-if="hasActiveFilters"
        @click="resetAll"
        class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-red-600 transition-colors hover:bg-red-50 dark:border-slate-600 dark:text-red-400 dark:hover:bg-slate-700"
      >
        Clear all
      </button>
    </div>

    <div v-if="selectedTags.length > 0" class="flex flex-wrap gap-1.5">
      <span
        v-for="tag in selectedTags"
        :key="tag"
        class="inline-flex items-center gap-1 rounded-full bg-indigo-100 px-2.5 py-0.5 text-xs font-medium text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300"
      >
        {{ tag }}
        <button @click="removeTag(tag)" class="text-indigo-400 hover:text-indigo-600 dark:text-indigo-300 dark:hover:text-indigo-100">
          <X class="h-3 w-3" />
        </button>
      </span>
    </div>

    <div v-if="showDateFilter" class="flex flex-wrap items-center gap-2 rounded-lg border border-gray-200 bg-white p-3 dark:border-slate-600 dark:bg-slate-800">
      <div class="flex items-center gap-2">
        <label class="text-xs text-slate-500 dark:text-slate-400">From</label>
        <input
          v-model="dateFrom"
          type="date"
          class="rounded-md border border-gray-300 px-2 py-1 text-sm focus:border-indigo-500 focus:outline-none dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200"
        />
      </div>
      <div class="flex items-center gap-2">
        <label class="text-xs text-slate-500 dark:text-slate-400">To</label>
        <input
          v-model="dateTo"
          type="date"
          class="rounded-md border border-gray-300 px-2 py-1 text-sm focus:border-indigo-500 focus:outline-none dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200"
        />
      </div>
      <button
        @click="applyDateRange"
        class="rounded-md bg-indigo-600 px-3 py-1 text-xs font-medium text-white hover:bg-indigo-700"
      >
        Apply
      </button>
      <button
        @click="clearDateRange"
        class="rounded-md border border-gray-300 px-3 py-1 text-xs text-slate-600 hover:bg-gray-100 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-700"
      >
        Clear
      </button>
    </div>
  </div>
</template>
