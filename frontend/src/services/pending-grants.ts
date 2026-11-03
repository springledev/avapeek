import { api } from '@/services/api'
import { createSharedRequest } from '@/lib/shared-request'

/** Someone the server lists as waiting. The name and key are the server's word, not proof. */
export interface PendingGrant {
  child_id: number
  user_id: number
  display_name: string
  public_key: string
}

/** How long a fetched list is reused; admins are re-checked on a 60s timer anyway. */
export const PENDING_GRANTS_TTL_MS = 30_000

const pendingGrants = createSharedRequest(async () => {
  const { data } = await api.get<{ data: PendingGrant[] }>('/encryption-keys/pending-grants')
  return data.data
}, PENDING_GRANTS_TTL_MS)

/** Members waiting for an album key; callers share one request and a recent result. */
export const fetchPendingGrants = pendingGrants.get

/** Call after anything that changes who is waiting (a key shared, a member removed). */
export const invalidatePendingGrants = pendingGrants.invalidate
