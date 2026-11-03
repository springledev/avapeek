export interface SharedRequest<T> {
  /** Whether a result was fetched recently enough that `get` makes no request. */
  isFresh: () => boolean
  /**
   * Resolves with the data. Callers share one request while it is on the wire and reuse
   * the result for the time-to-live; `force` always makes a new request.
   */
  get: (options?: { force?: boolean }) => Promise<T>
  /** Call after a change that makes the data stale. Never fails, never refetches by itself. */
  invalidate: () => void
}

/**
 * Wraps a read request with in-flight de-duplication and a short time-to-live.
 * A request that was started before `invalidate` never fills the reuse window,
 * and a failed request is not remembered.
 */
export function createSharedRequest<T>(load: () => Promise<T>, ttlMs: number): SharedRequest<T> {
  let cached: { value: T; at: number } | null = null
  let inFlight: Promise<T> | null = null
  let generation = 0

  function isFresh(): boolean {
    return cached !== null && Date.now() - cached.at < ttlMs
  }

  function get(options: { force?: boolean } = {}): Promise<T> {
    if (!options.force) {
      if (cached && isFresh()) return Promise.resolve(cached.value)
      if (inFlight) return inFlight
    }

    const startedIn = generation
    const request: Promise<T> = load()
      .then((value) => {
        if (startedIn === generation) cached = { value, at: Date.now() }
        return value
      })
      .finally(() => {
        if (inFlight === request) inFlight = null
      })
    inFlight = request
    return request
  }

  function invalidate(): void {
    generation++
    cached = null
    inFlight = null
  }

  return { isFresh, get, invalidate }
}
