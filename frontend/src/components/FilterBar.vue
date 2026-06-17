<script setup lang="ts">
import { ref, computed } from 'vue'
import { useFeedStore } from '@/stores/feed'
import { useChildStore } from '@/stores/child'
import ChildChip from '@/components/ChildChip.vue'

const feedStore = useFeedStore()
const childStore = useChildStore()

const tagInput = ref('')
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

function addTag() {
  const tag = tagInput.value.trim()
  if (tag && !feedStore.filters.tags.includes(tag)) {
    const current = [...feedStore.filters.tags, tag]
    feedStore.setTagFilter(current)
    feedStore.fetchPhotos()
  }
  tagInput.value = ''
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
        <input
          v-model="tagInput"
          type="text"
          placeholder="Filter by tag..."
          class="w-full rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm placeholder-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:placeholder-slate-400"
          @keydown.enter.prevent="addTag"
        />
      </div>

      <button
        @click="showDateFilter = !showDateFilter"
        class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-slate-600 transition-colors hover:bg-gray-100 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-700"
      >
        <svg class="mr-1 inline h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
        </svg>
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
        <button @click="removeTag(tag)" class="text-indigo-400 hover:text-indigo-600 dark:text-indigo-300 dark:hover:text-indigo-100">&times;</button>
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
