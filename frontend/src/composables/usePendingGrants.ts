import { computed, ref } from 'vue'
import { api } from '@/services/api'
import {
  fetchPendingGrants,
  invalidatePendingGrants,
  type PendingGrant,
} from '@/services/pending-grants'
import { useChildStore } from '@/stores/child'

export type { PendingGrant }

/** Shared across the banners and App.vue so all see the same list. */
const grants = ref<PendingGrant[]>([])

/**
 * Members (owners/admins only) who joined an album but still wait for its key.
 * Nothing here runs on its own: a key is only shared by `approve`, which only
 * an admin's click may call.
 *
 * `refresh` never throws; a failed request leaves the last known state.
 */
export function usePendingGrants() {
  const pendingCount = computed(() => new Set(grants.value.map((g) => g.user_id)).size)

  /** Reads the list; reuses a recent one unless `force` is set. */
  async function refresh(options: { force?: boolean } = {}): Promise<void> {
    try {
      grants.value = await fetchPendingGrants(options)
    } catch (err) {
      console.error('Failed to load pending access grants:', err)
    }
  }

  /** Shares the album key with this member. Throws if that fails; the list is re-read either way. */
  async function approve(grant: PendingGrant): Promise<void> {
    try {
      await useChildStore().grantAccess(grant.child_id, grant.user_id, grant.public_key)
    } finally {
      await refresh({ force: true })
    }
  }

  /**
   * Removes this member from the album, the same as "Remove from family", so
   * nothing is shared and they aren't listed again unless they are re-invited.
   * Throws if that fails; the list is re-read either way.
   */
  async function decline(grant: PendingGrant): Promise<void> {
    try {
      await api.delete(`/children/${grant.child_id}/members/${grant.user_id}`)
    } finally {
      await refresh({ force: true })
    }
  }

  function reset(): void {
    invalidatePendingGrants()
    grants.value = []
  }

  return { grants, pendingCount, refresh, approve, decline, reset }
}
