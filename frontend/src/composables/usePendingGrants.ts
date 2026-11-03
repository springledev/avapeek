import { computed, ref } from 'vue'
import { api } from '@/services/api'
import { useChildStore } from '@/stores/child'

/** Someone the server lists as waiting. The name and key are the server's word, not proof. */
export interface PendingGrant {
  child_id: number
  user_id: number
  display_name: string
  public_key: string
}

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

  async function refresh(): Promise<void> {
    try {
      const { data } = await api.get<{ data: PendingGrant[] }>('/encryption-keys/pending-grants')
      grants.value = data.data
    } catch (err) {
      console.error('Failed to load pending access grants:', err)
    }
  }

  /** Shares the album key with this member. Throws if that fails; the list is re-read either way. */
  async function approve(grant: PendingGrant): Promise<void> {
    try {
      await useChildStore().grantAccess(grant.child_id, grant.user_id, grant.public_key)
    } finally {
      await refresh()
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
      await refresh()
    }
  }

  function reset(): void {
    grants.value = []
  }

  return { grants, pendingCount, refresh, approve, decline, reset }
}
