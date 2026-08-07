<script setup lang="ts">
import { ref, computed } from 'vue'

const props = defineProps<{
  months: string[]
  selected?: string | null
}>()

const emit = defineEmits<{
  selectMonth: [month: string | null]
}>()

const scrollRef = ref<HTMLElement | null>(null)

function scroll(dir: 'left' | 'right') {
  if (!scrollRef.value) return
  scrollRef.value.scrollBy({ left: dir === 'left' ? -200 : 200, behavior: 'smooth' })
}
</script>

<template>
  <div v-if="months.length > 0" class="relative mb-4">
    <div class="flex items-center gap-1">
      <button
        v-if="selected"
        @click="emit('selectMonth', null)"
        class="shrink-0 rounded-lg px-3 py-1.5 text-xs font-medium bg-indigo-100 text-indigo-700 hover:bg-indigo-200 dark:bg-indigo-900/30 dark:text-indigo-300 dark:hover:bg-indigo-900/50"
      >
        All
      </button>
      <div
        ref="scrollRef"
        class="flex gap-1 overflow-x-auto scrollbar-hide"
      >
        <button
          v-for="month in months"
          :key="month"
          @click="emit('selectMonth', month)"
          class="shrink-0 rounded-lg px-3 py-1.5 text-xs font-medium transition-colors whitespace-nowrap"
          :class="selected === month
            ? 'bg-indigo-600 text-white dark:bg-indigo-500'
            : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-slate-700 dark:text-slate-300 dark:hover:bg-slate-600'"
        >
          {{ month }}
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.scrollbar-hide {
  -ms-overflow-style: none;
  scrollbar-width: none;
}
.scrollbar-hide::-webkit-scrollbar {
  display: none;
}
</style>
