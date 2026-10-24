<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { api } from '@/services/api'

interface QuotaData {
  used_bytes: number
  limit_bytes: number | null
  percentage: number | null
}

const props = withDefaults(defineProps<{
  childId?: number | null
}>(), {
  childId: null,
})

const quota = ref<QuotaData | null>(null)
const isLoading = ref(false)

const percentage = computed(() => Math.min(quota.value?.percentage ?? 0, 100))

function formatBytes(bytes: number): string {
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  if (bytes < 1024 * 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
  return `${(bytes / (1024 * 1024 * 1024)).toFixed(1)} GB`
}

const hasLimit = computed(() => quota.value?.limit_bytes != null)

function freeBytes(): number {
  if (!quota.value || quota.value.limit_bytes == null) return 0
  return quota.value.limit_bytes - quota.value.used_bytes
}

const barColorClass = computed(() => {
  if (percentage.value >= 95) return 'bg-red-500'
  if (percentage.value >= 80) return 'bg-amber-500'
  return 'bg-blue-500'
})

const textColorClass = computed(() => {
  if (percentage.value >= 95) return 'text-red-600 dark:text-red-400'
  if (percentage.value >= 80) return 'text-amber-600 dark:text-amber-400'
  return 'text-slate-500 dark:text-slate-400'
})

async function fetchQuota() {
  isLoading.value = true
  try {
    const endpoint = props.childId ? `/children/${props.childId}/quota` : '/quota'
    const response = await api.get(endpoint)
    quota.value = response.data.data as QuotaData
  } catch {
    quota.value = null
  } finally {
    isLoading.value = false
  }
}

onMounted(() => {
  fetchQuota()
})

defineExpose({ refresh: fetchQuota })
</script>

<template>
  <div v-if="quota" class="rounded-lg border border-gray-200 bg-white p-3 dark:border-slate-600 dark:bg-slate-800">
    <div class="mb-1.5 flex items-center justify-between text-xs">
      <span class="text-slate-500 dark:text-slate-400">
        <template v-if="hasLimit">{{ formatBytes(quota.used_bytes) }} / {{ formatBytes(quota.limit_bytes!) }}</template>
        <template v-else>{{ formatBytes(quota.used_bytes) }} used</template>
      </span>
      <slot name="plan-info" :usage="quota">
        <span v-if="hasLimit" :class="textColorClass">
          {{ formatBytes(freeBytes()) }} free
        </span>
        <span v-else class="text-slate-500 dark:text-slate-400" data-testid="quota-limit-unknown">
          Limit unknown
        </span>
      </slot>
    </div>
    <div v-if="hasLimit" class="h-1.5 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
      <div
        class="h-full rounded-full transition-all duration-300"
        :class="barColorClass"
        :style="{ width: `${percentage}%` }"
      />
    </div>
  </div>
  <div v-else-if="isLoading" class="h-14 animate-pulse rounded-lg bg-slate-100 dark:bg-slate-700" />
</template>
