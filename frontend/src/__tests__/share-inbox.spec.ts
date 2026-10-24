import { describe, it, expect, beforeEach, vi } from 'vitest'
import {
  SHARE_INBOX_DB,
  SHARE_INBOX_MAX_AGE_MS,
  clearShareInbox,
  pickUpSharedFiles,
  purgeExpiredShares,
  stageSharedFiles,
  takeSharedFiles,
} from '@/lib/share-inbox'

/** Minimal in-memory IndexedDB fake (no test dependency): one auto-increment store per database. */
function installFakeIndexedDB() {
  const rows = new Map<number, Record<string, unknown>>()
  let nextId = 1
  const events: string[] = []

  const makeRequest = <T>(work: () => T) => {
    const req: { result?: T; onsuccess?: () => void; onerror?: () => void } = {}
    setTimeout(() => {
      req.result = work()
      req.onsuccess?.()
    }, 0)
    return req
  }

  const db = {
    objectStoreNames: { contains: () => true },
    createObjectStore: () => undefined,
    close: () => {
      events.push('close')
    },
    transaction: () => {
      const tx: { oncomplete?: () => void } = {}
      const finish = () => setTimeout(() => tx.oncomplete?.(), 5)
      finish()
      return {
        set oncomplete(fn: () => void) {
          tx.oncomplete = fn
        },
        objectStore: () => ({
          add: (record: Record<string, unknown>) => {
            rows.set(nextId, { ...record, id: nextId })
            nextId++
          },
          getAll: () => makeRequest(() => [...rows.values()]),
          delete: (id: number) => {
            rows.delete(id)
          },
          clear: () => {
            rows.clear()
          },
        }),
      }
    },
  }

  const fake = {
    open: () => {
      const req: { result: typeof db; onsuccess?: () => void; onupgradeneeded?: () => void } = {
        result: db,
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
  }
  vi.stubGlobal('indexedDB', fake)
  return { rows, events }
}

const file = (name: string) => new File(['x'], name, { type: 'image/jpeg', lastModified: 5 })

describe('share inbox', () => {
  let fake: ReturnType<typeof installFakeIndexedDB>

  beforeEach(() => {
    fake = installFakeIndexedDB()
  })

  it('stages shared files and hands them back once, emptying the inbox', async () => {
    await stageSharedFiles([file('a.jpg'), file('b.jpg')], 1000)
    expect(fake.rows.size).toBe(2)

    const taken = await takeSharedFiles(2000)
    expect(taken.map((f) => f.name)).toEqual(['a.jpg', 'b.jpg'])
    expect(taken[0]?.type).toBe('image/jpeg')
    expect(fake.rows.size).toBe(0)
    expect(await takeSharedFiles(2000)).toEqual([])
  })

  it('only stages image files', async () => {
    await stageSharedFiles([
      file('a.jpg'),
      new File(['x'], 'notes.pdf', { type: 'application/pdf' }),
      new File(['x'], 'untyped', { type: '' }),
    ])
    expect([...fake.rows.values()].map((r) => r.name)).toEqual(['a.jpg'])
  })

  it('drops entries older than one hour on pickup and on purge', async () => {
    await stageSharedFiles([file('old.jpg')], 0)
    const now = SHARE_INBOX_MAX_AGE_MS + 1
    await stageSharedFiles([file('fresh.jpg')], now)
    // staging purged the expired entry already
    expect([...fake.rows.values()].map((r) => r.name)).toEqual(['fresh.jpg'])

    fake.rows.set(99, { id: 99, name: 'stale.jpg', blob: new Blob(['x']), type: '', lastModified: 0, storedAt: 0 })
    expect(await purgeExpiredShares(now)).toBe(1)
    expect(fake.rows.has(99)).toBe(false)

    fake.rows.set(98, { id: 98, name: 'stale2.jpg', blob: new Blob(['x']), type: '', lastModified: 0, storedAt: 0 })
    const taken = await takeSharedFiles(now)
    expect(taken.map((f) => f.name)).toEqual(['fresh.jpg'])
    expect(fake.rows.size).toBe(0)
  })

  it('pickUpSharedFiles takes files on a shared visit and only purges otherwise', async () => {
    await stageSharedFiles([file('a.jpg')])
    expect(await pickUpSharedFiles(false)).toEqual([])
    expect(fake.rows.size).toBe(1)

    const files = await pickUpSharedFiles(true)
    expect(files.map((f) => f.name)).toEqual(['a.jpg'])
    expect(fake.rows.size).toBe(0)
  })

  it('clearShareInbox deletes the database', () => {
    clearShareInbox()
    expect(fake.events).toContain(`delete:${SHARE_INBOX_DB}`)
  })

  it('clearShareInbox does not throw without IndexedDB', () => {
    vi.stubGlobal('indexedDB', undefined)
    expect(() => clearShareInbox()).not.toThrow()
  })
})
