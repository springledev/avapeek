<script setup lang="ts">
defineProps<{
  name: string
  color: string
  active: boolean
}>()

const emit = defineEmits<{
  toggle: []
}>()
</script>

<template>
  <button
    @click="emit('toggle')"
    :class="[
      'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-colors',
      active
        ? 'text-white'
        : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-700 dark:text-slate-300 dark:hover:bg-slate-600',
    ]"
    :style="active ? { backgroundColor: color } : {}"
  >
    <span
      class="flex h-4 w-4 items-center justify-center rounded-full text-[9px] font-bold text-white"
      :style="{ backgroundColor: color }"
    >
      {{ name.charAt(0).toUpperCase() }}
    </span>
    {{ name }}
  </button>
</template>
