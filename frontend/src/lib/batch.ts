/**
 * Sequential batching shared by the bulk actions. Batches run one after another
 * (the API limiter is per user); a cancel or a failed batch stops the run and the
 * batches that already completed stay applied and are reported.
 */

export function chunk<T>(items: readonly T[], size: number): T[][] {
  const out: T[][] = []
  for (let i = 0; i < items.length; i += size) out.push(items.slice(i, i + size))
  return out
}

export interface BatchRun<R> {
  /** Results of the batches that completed, in order. */
  results: R[]
  /** Items belonging to completed batches. */
  processed: number
  cancelled: boolean
  /** The error of the batch that stopped the run, if any. */
  error: unknown
}

export async function runBatches<T, R>(
  items: readonly T[],
  size: number,
  worker: (batch: T[], signal?: AbortSignal) => Promise<R>,
  options: { signal?: AbortSignal; onProgress?: (processed: number, total: number) => void } = {},
): Promise<BatchRun<R>> {
  const { signal, onProgress } = options
  const run: BatchRun<R> = { results: [], processed: 0, cancelled: false, error: null }
  onProgress?.(0, items.length)

  for (const batch of chunk(items, size)) {
    if (signal?.aborted) {
      run.cancelled = true
      break
    }
    try {
      run.results.push(await worker(batch, signal))
    } catch (error) {
      if (signal?.aborted) run.cancelled = true
      else run.error = error
      break
    }
    run.processed += batch.length
    onProgress?.(run.processed, items.length)
  }
  return run
}
