import { computed, ref } from 'vue'
import { fetchPendingGrants, invalidatePendingGrants } from '@/services/pending-grants'

/** Shared across the banner and App.vue so both see the same pending count. */
const pendingUserIds = ref<number[]>([])

/**
 * Members (owners/admins only) who joined an album but still wait for its key.
 * `refresh` never throws; a failed request leaves the last known state.
 */
export function usePendingGrants() {
  const pendingCount = computed(() => pendingUserIds.value.length)

  /** Reads the list; reuses a recent one unless `force` is set. */
  async function refresh(options: { force?: boolean } = {}): Promise<void> {
    try {
      const grants = await fetchPendingGrants(options)
      pendingUserIds.value = [...new Set(grants.map((grant) => grant.user_id))]
    } catch (err) {
      console.error('Failed to load pending access grants:', err)
    }
  }

  function reset(): void {
    invalidatePendingGrants()
    pendingUserIds.value = []
  }

  return { pendingCount, refresh, reset }
}
