import { computed, ref } from 'vue'
import { api } from '@/services/api'

interface PendingGrant {
  child_id: number
  user_id: number
}

/** Shared across the banner and App.vue so both see the same pending count. */
const pendingUserIds = ref<number[]>([])

/**
 * Members (owners/admins only) who joined an album but still wait for its key.
 * `refresh` never throws; a failed request leaves the last known state.
 */
export function usePendingGrants() {
  const pendingCount = computed(() => pendingUserIds.value.length)

  async function refresh(): Promise<void> {
    try {
      const { data } = await api.get<{ data: PendingGrant[] }>('/encryption-keys/pending-grants')
      pendingUserIds.value = [...new Set(data.data.map((grant) => grant.user_id))]
    } catch (err) {
      console.error('Failed to load pending access grants:', err)
    }
  }

  function reset(): void {
    pendingUserIds.value = []
  }

  return { pendingCount, refresh, reset }
}
