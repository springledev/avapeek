<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { api } from '@/services/api'

interface QuotaData {
  used_bytes: number
  limit_bytes: number | null
  percentage: number | null
}

const quota = ref<QuotaData | null>(null)
const isLoading = ref(false)
const hasFailed = ref(false)

const percentage = computed(() => Math.min(quota.value?.percentage ?? 0, 100))

function formatBytes(bytes: number): string {
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  if (bytes < 1024 * 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
  return `${(bytes / (1024 * 1024 * 1024)).toFixed(1)} GB`
}

const hasLimit = computed(() => quota.value?.limit_bytes != null)

function freeBytes(): number {
  if (!quota.value || quota.value.limit_bytes == null) return 0
  return Math.max(0, quota.value.limit_bytes - quota.value.used_bytes)
}

const usageLevel = computed(() => {
  if (percentage.value >= 95) return 'danger'
  if (percentage.value >= 80) return 'warning'
  return 'safe'
})

async function fetchQuota() {
  isLoading.value = true
  hasFailed.value = false
  try {
    const response = await api.get('/quota')
    const data = response.data?.data as QuotaData | undefined
    if (!data || typeof data.used_bytes !== 'number') throw new Error('Malformed quota response')
    quota.value = data
  } catch {
    quota.value = null
    hasFailed.value = true
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
  <div v-if="quota" class="quota">
    <div class="quota__header">
      <span>
        <template v-if="hasLimit">{{ formatBytes(quota.used_bytes) }} / {{ formatBytes(quota.limit_bytes!) }}</template>
        <template v-else>{{ formatBytes(quota.used_bytes) }} used</template>
      </span>
      <slot name="plan-info" :usage="quota">
        <span v-if="hasLimit" :class="`quota__free--${usageLevel}`" data-testid="quota-free">
          {{ formatBytes(freeBytes()) }} free
        </span>
        <span v-else data-testid="quota-limit-unknown">Limit unknown</span>
      </slot>
    </div>
    <div v-if="hasLimit" class="quota__track" data-testid="quota-track">
      <div
        class="quota__fill"
        :class="`quota__fill--${usageLevel}`"
        data-testid="quota-fill"
        :style="{ width: `${percentage}%` }"
      />
    </div>
  </div>
  <div v-else-if="isLoading" class="quota__skeleton" />
  <div v-else-if="hasFailed" class="quota quota--error" data-testid="quota-error">
    <span>Storage usage unavailable</span>
    <button type="button" class="quota__retry" @click="fetchQuota">Retry</button>
  </div>
</template>

<style scoped>
.quota {
  padding: var(--space-3) var(--space-4);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-card);
  background: var(--color-surface);
  color: var(--color-ink-soft);
  font-size: var(--text-meta);
}

.quota--error {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-3);
  padding-block: 0;
}

.quota__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-3);
  margin-bottom: var(--space-2);
}

.quota__free--warning {
  color: var(--color-accent-ink);
  font-weight: 700;
}

.quota__free--danger {
  color: var(--color-danger);
  font-weight: 700;
}

.quota__track {
  width: 100%;
  height: 6px;
  overflow: hidden;
  border-radius: var(--radius-pill);
  background: var(--color-surface-2);
}

.quota__fill {
  height: 100%;
  border-radius: var(--radius-pill);
  transition: width var(--dur-standard) var(--ease-soft);
}

.quota__fill--safe {
  background: var(--color-success);
}

.quota__fill--warning {
  background: var(--color-accent);
}

.quota__fill--danger {
  background: var(--color-danger);
}

.quota__skeleton {
  height: 56px;
  border-radius: var(--radius-card);
  background: var(--color-surface-2);
  animation: quota-pulse 1.4s ease-in-out infinite;
}

.quota__retry {
  min-height: var(--target-min);
  padding: 0 var(--space-2);
  border: 0;
  background: transparent;
  color: var(--color-accent-ink);
  font-family: inherit;
  font-size: var(--text-meta);
  font-weight: 700;
  text-decoration: underline;
  cursor: pointer;
}

.quota__retry:focus-visible {
  outline: 3px solid var(--color-focus);
  outline-offset: -3px;
  border-radius: var(--radius-card);
}

@keyframes quota-pulse {
  0%,
  100% {
    opacity: 1;
  }
  50% {
    opacity: 0.5;
  }
}

@media (prefers-reduced-motion: reduce) {
  .quota__skeleton {
    animation: none;
  }
}
</style>
