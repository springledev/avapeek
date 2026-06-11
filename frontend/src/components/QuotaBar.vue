<script setup lang="ts">
import { ref, onMounted, watch, computed } from 'vue'
import { api } from '@/services/api'

interface QuotaData {
  used_bytes: number
  limit_bytes: number
  percentage: number
}

const props = defineProps<{
  childId: number
}>()

const quota = ref<QuotaData | null>(null)
const isLoading = ref(false)

const percentage = computed(() => Math.min(quota.value?.percentage ?? 0, 100))

function formatBytes(bytes: number): string {
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  if (bytes < 1024 * 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
  return `${(bytes / (1024 * 1024 * 1024)).toFixed(1)} GB`
}

function freeBytes(): number {
  if (!quota.value) return 0
  return quota.value.limit_bytes - quota.value.used_bytes
}

const barColorClass = computed(() => {
  if (percentage.value >= 95) return 'quota-bar-fill-danger'
  if (percentage.value >= 80) return 'quota-bar-fill-warning'
  return 'quota-bar-fill-safe'
})

const textColorClass = computed(() => {
  if (percentage.value >= 95) return 'text-red-600'
  if (percentage.value >= 80) return 'text-amber-600'
  return 'text-slate-500'
})

async function fetchQuota() {
  isLoading.value = true
  try {
    const response = await api.get(`/children/${props.childId}/quota`)
    quota.value = response.data.data as QuotaData
  } catch {
    quota.value = null
  } finally {
    isLoading.value = false
  }
}

onMounted(fetchQuota)

watch(() => props.childId, fetchQuota)

defineExpose({ refresh: fetchQuota })
</script>

<template>
  <div v-if="quota" class="quota-bar-wrapper">
    <div class="quota-bar-header">
      <span class="quota-bar-text">
        {{ formatBytes(quota.used_bytes) }} / {{ formatBytes(quota.limit_bytes) }}
      </span>
      <slot name="plan-info" :usage="quota">
        <span :class="textColorClass" class="quota-bar-subtext">
          {{ formatBytes(freeBytes()) }} free
        </span>
      </slot>
    </div>
    <div class="quota-bar-track">
      <div
        class="quota-bar-fill"
        :class="barColorClass"
        :style="{ width: `${percentage}%` }"
      />
    </div>
  </div>
  <div v-else-if="isLoading" class="skeleton h-12 w-full rounded-lg" />
</template>
