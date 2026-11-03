import { api } from '@/services/api'

export interface PendingGrant {
  child_id: number
  user_id: number
  public_key: string
}

/** How long a fetched list is reused; admins are re-checked on a 60s timer anyway. */
export const PENDING_GRANTS_TTL_MS = 30_000

let cached: { grants: PendingGrant[]; at: number } | null = null
let inFlight: Promise<PendingGrant[]> | null = null
/** Bumped on every invalidation so a request started before it never fills the cache. */
let generation = 0

/** Whether the list was fetched recently enough that `fetchPendingGrants` makes no request. */
export function hasFreshPendingGrants(): boolean {
  return cached !== null && Date.now() - cached.at < PENDING_GRANTS_TTL_MS
}

/**
 * Members waiting for an album key. Callers share one request while it is on the wire
 * and reuse the result for a short while; `force` skips the reuse.
 */
export function fetchPendingGrants(options: { force?: boolean } = {}): Promise<PendingGrant[]> {
  if (!options.force) {
    if (cached && hasFreshPendingGrants()) return Promise.resolve(cached.grants)
    if (inFlight) return inFlight
  }

  const startedIn = generation
  const request = api
    .get<{ data: PendingGrant[] }>('/encryption-keys/pending-grants')
    .then(({ data }) => {
      if (startedIn === generation) cached = { grants: data.data, at: Date.now() }
      return data.data
    })
    .finally(() => {
      if (inFlight === request) inFlight = null
    })
  inFlight = request
  return request
}

/** Call after anything that changes who is waiting (a key shared, a member removed). */
export function invalidatePendingGrants(): void {
  generation++
  cached = null
  inFlight = null
}
