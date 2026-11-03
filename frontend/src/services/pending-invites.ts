import { api } from '@/services/api'
import { createSharedRequest } from '@/lib/shared-request'

export interface PendingInvite {
  id: number
  child_name: string
  role: string
  relationship: string | null
  token: string
  expires_at: string
  created_at: string
}

/** Settings and a kid's settings page open together, so they can share one list. */
export const PENDING_INVITES_TTL_MS = 30_000

const pendingInvites = createSharedRequest(async () => {
  const { data } = await api.get<{ data: PendingInvite[] }>('/invites/pending')
  return data.data
}, PENDING_INVITES_TTL_MS)

/** Invites that were sent and not yet accepted; callers share one request and a recent result. */
export const fetchPendingInvites = pendingInvites.get

/** Call after creating or revoking an invite. */
export const invalidatePendingInvites = pendingInvites.invalidate
