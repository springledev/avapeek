import { describe, it, expect, beforeEach, vi } from 'vitest'

function installFakeIndexedDB() {
  const events: string[] = []
  const blobs = new Map<string, Blob>()

  const makeDb = (name: string) => ({
    objectStoreNames: { contains: () => true },
    createObjectStore: () => undefined,
    close: () => {
      events.push(`close:${name}`)
    },
    transaction: () => {
      const tx: { oncomplete?: () => void; onerror?: () => void } = {}
      setTimeout(() => tx.oncomplete?.(), 0)
      return {
        set oncomplete(fn: () => void) {
          tx.oncomplete = fn
        },
        set onerror(fn: () => void) {
          tx.onerror = fn
        },
        objectStore: () => ({
          getAllKeys: () => ({}) as { onsuccess?: () => void },
          openCursor: () => ({}) as { onsuccess?: () => void },
          get: (key: number) => {
            const req: { result?: Blob; onsuccess?: () => void } = {}
            setTimeout(() => {
              req.result = blobs.get(`${name}:${key}`)
              req.onsuccess?.()
            }, 0)
            return req
          },
          put: (blob: Blob, key: number) => {
            blobs.set(`${name}:${key}`, blob)
          },
        }),
      }
    },
  })

  vi.stubGlobal('indexedDB', {
    open: (name: string) => {
      events.push(`open:${name}`)
      const req: { result: unknown; onsuccess?: () => void; onupgradeneeded?: () => void } = {
        result: makeDb(name),
      }
      setTimeout(() => {
        req.onupgradeneeded?.()
        req.onsuccess?.()
      }, 0)
      return req
    },
    deleteDatabase: (name: string) => {
      events.push(`delete:${name}`)
      return {}
    },
  })
  return { events, blobs }
}

describe('decrypted cache', () => {
  let fake: ReturnType<typeof installFakeIndexedDB>

  beforeEach(() => {
    vi.resetModules()
    vi.stubGlobal('URL', Object.assign(URL, { createObjectURL: () => 'blob:x', revokeObjectURL: () => {} }))
    fake = installFakeIndexedDB()
  })

  it('stores thumbnails in a per-user database', async () => {
    const cache = await import('@/lib/cache/decrypted-cache')
    cache.setDecryptedCacheUser(7)
    await cache.getThumbnail(1, async () => new Blob(['t']))
    expect(fake.events).toContain('open:avapeek-decrypted-7')
    expect(fake.events).not.toContain('open:avapeek-decrypted')
  })

  it('does not persist anything when no user is known', async () => {
    const cache = await import('@/lib/cache/decrypted-cache')
    await cache.getThumbnail(1, async () => new Blob(['t']))
    expect(fake.events.some((e) => e.startsWith('open:'))).toBe(false)
  })

  it('closes the open connection before deleting the database', async () => {
    const cache = await import('@/lib/cache/decrypted-cache')
    cache.setDecryptedCacheUser(7)
    await cache.getThumbnail(1, async () => new Blob(['t']))

    cache.clearDecryptedCache()
    await vi.waitFor(() => expect(fake.events).toContain('delete:avapeek-decrypted-7'))

    expect(fake.events.indexOf('close:avapeek-decrypted-7')).toBeLessThan(
      fake.events.indexOf('delete:avapeek-decrypted-7'),
    )
    expect(fake.events).toContain('delete:avapeek-decrypted')
  })

  it('wipes the previous user cache when a different user signs in', async () => {
    const cache = await import('@/lib/cache/decrypted-cache')
    cache.setDecryptedCacheUser(7)
    await cache.getThumbnail(1, async () => new Blob(['t']))

    cache.setDecryptedCacheUser(8)
    await vi.waitFor(() => expect(fake.events).toContain('delete:avapeek-decrypted-7'))
  })
})
