/**
 * Three-tier decrypted image cache.
 * Memory (Map<photoId, blobURL>) → IndexedDB (Blob) → Network+Decrypt
 *
 * Thumbnails are permanent in IDB (~40KB each, fits 25K in 1GB).
 * Originals are LRU-evicted at 500MB cap.
 *
 * The IndexedDB is namespaced per user (`avapeek-decrypted-<userId>`); the auth
 * store tells us who is signed in via `setDecryptedCacheUser`. With no known
 * user nothing is persisted. The cache is wiped on logout, 401 and user change.
 */

const memoryCache = new Map<number, string>()

const DB_NAME_PREFIX = 'avapeek-decrypted'
const STORE_THUMBS = 'thumbs'
const STORE_ORIGINALS = 'originals'
const MAX_ORIGINAL_CACHE_BYTES = 500 * 1024 * 1024

let dbPromise: Promise<IDBDatabase> | null = null
let activeUserId: number | null = null
const usedDbNames = new Set<string>()
let currentOriginalBytes = 0
const originalAccessOrder: number[] = []

function dbNameFor(userId: number): string {
  return `${DB_NAME_PREFIX}-${userId}`
}

/** Sets the signed-in user. Switching directly to a different user wipes the previous user's cache. */
export function setDecryptedCacheUser(userId: number | null): void {
  if (activeUserId !== null && userId !== null && userId !== activeUserId) {
    clearDecryptedCache()
  }
  activeUserId = userId
}

function openDB(): Promise<IDBDatabase> {
  if (activeUserId === null) return Promise.reject(new Error('No active cache user'))
  if (dbPromise) return dbPromise
  const dbName = dbNameFor(activeUserId)
  usedDbNames.add(dbName)
  dbPromise = new Promise((resolve, reject) => {
    const req = indexedDB.open(dbName, 1)
    req.onupgradeneeded = () => {
      const db = req.result
      if (!db.objectStoreNames.contains(STORE_THUMBS)) {
        db.createObjectStore(STORE_THUMBS)
      }
      if (!db.objectStoreNames.contains(STORE_ORIGINALS)) {
        db.createObjectStore(STORE_ORIGINALS)
      }
    }
    req.onsuccess = async () => {
      const db = req.result
      await scanOriginalsStore(db)
      resolve(db)
    }
    req.onerror = () => reject(req.error)
  })
  dbPromise.catch(() => {
    dbPromise = null
  })
  return dbPromise
}

/** Opens the per-user DB, or null when no user is known (memory-only mode). */
async function tryOpenDB(): Promise<IDBDatabase | null> {
  try {
    return await openDB()
  } catch {
    return null
  }
}

async function scanOriginalsStore(db: IDBDatabase): Promise<void> {
  const tx = db.transaction(STORE_ORIGINALS, 'readonly')
  const store = tx.objectStore(STORE_ORIGINALS)
  const keysReq = store.getAllKeys()
  keysReq.onsuccess = () => {
    originalAccessOrder.length = 0
    originalAccessOrder.push(...(keysReq.result as number[]))
  }
  const cursorReq = store.openCursor()
  let tally = 0
  cursorReq.onsuccess = () => {
    const cursor = cursorReq.result
    if (cursor) {
      tally += cursor.value?.size ?? 0
      cursor.continue()
    }
  }
  await new Promise<void>((resolve) => {
    tx.oncomplete = () => {
      currentOriginalBytes = tally
      resolve()
    }
    tx.onerror = () => resolve()
  })
}

async function evictOldestOriginals(db: IDBDatabase, neededBytes: number): Promise<void> {
  const cap = MAX_ORIGINAL_CACHE_BYTES - neededBytes
  while (currentOriginalBytes > cap && originalAccessOrder.length > 0) {
    const oldest = originalAccessOrder.shift()!
    await new Promise<void>((resolve) => {
      const tx = db.transaction(STORE_ORIGINALS, 'readwrite')
      const getReq = tx.objectStore(STORE_ORIGINALS).get(oldest)
      getReq.onsuccess = () => {
        const blob = getReq.result
        if (blob) {
          currentOriginalBytes -= blob.size
          tx.objectStore(STORE_ORIGINALS).delete(oldest)
          memoryCache.delete(oldest)
        }
      }
      tx.oncomplete = () => resolve()
      tx.onerror = () => resolve()
    })
  }
}

function touchOriginal(id: number): void {
  const idx = originalAccessOrder.indexOf(id)
  if (idx >= 0) originalAccessOrder.splice(idx, 1)
  originalAccessOrder.push(id)
}

export async function getThumbnail(
  photoId: number,
  fetchAndDecrypt: () => Promise<Blob>,
): Promise<string> {
  const memHit = memoryCache.get(photoId)
  if (memHit) return memHit

  const db = await tryOpenDB()
  if (!db) {
    const decrypted = await fetchAndDecrypt()
    const url = URL.createObjectURL(decrypted)
    memoryCache.set(photoId, url)
    return url
  }
  const idbBlob: Blob | undefined = await new Promise((resolve) => {
    const tx = db.transaction(STORE_THUMBS, 'readonly')
    const req = tx.objectStore(STORE_THUMBS).get(photoId)
    req.onsuccess = () => resolve(req.result)
    req.onerror = () => resolve(undefined)
  })

  if (idbBlob) {
    const url = URL.createObjectURL(idbBlob)
    memoryCache.set(photoId, url)
    return url
  }

  const decrypted = await fetchAndDecrypt()
  const url = URL.createObjectURL(decrypted)
  memoryCache.set(photoId, url)

  const tx = db.transaction(STORE_THUMBS, 'readwrite')
  tx.objectStore(STORE_THUMBS).put(decrypted, photoId)

  return url
}

export async function getOriginal(
  photoId: number,
  sizeBytes: number,
  fetchAndDecrypt: () => Promise<Blob>,
): Promise<string> {
  const memHit = memoryCache.get(photoId)
  if (memHit) {
    touchOriginal(photoId)
    return memHit
  }

  const db = await tryOpenDB()
  if (!db) {
    const decrypted = await fetchAndDecrypt()
    const url = URL.createObjectURL(decrypted)
    memoryCache.set(photoId, url)
    return url
  }
  const idbBlob: Blob | undefined = await new Promise((resolve) => {
    const tx = db.transaction(STORE_ORIGINALS, 'readonly')
    const req = tx.objectStore(STORE_ORIGINALS).get(photoId)
    req.onsuccess = () => resolve(req.result)
    req.onerror = () => resolve(undefined)
  })

  if (idbBlob) {
    const url = URL.createObjectURL(idbBlob)
    memoryCache.set(photoId, url)
    touchOriginal(photoId)
    return url
  }

  const decrypted = await fetchAndDecrypt()
  const url = URL.createObjectURL(decrypted)
  memoryCache.set(photoId, url)

  await evictOldestOriginals(db, decrypted.size)
  currentOriginalBytes += decrypted.size
  touchOriginal(photoId)

  const tx = db.transaction(STORE_ORIGINALS, 'readwrite')
  tx.objectStore(STORE_ORIGINALS).put(decrypted, photoId)

  return url
}

export async function warmCache(
  photos: { id: number; fetchAndDecrypt: () => Promise<Blob> }[],
): Promise<void> {
  const CONCURRENCY = 4
  const queue = [...photos]
  const workers = Array.from({ length: CONCURRENCY }, async () => {
    while (queue.length > 0) {
      const photo = queue.shift()!
      try {
        await getThumbnail(photo.id, photo.fetchAndDecrypt)
      } catch {
        /* best effort */
      }
    }
  })
  await Promise.all(workers)
}

/** Deletes every decrypted-cache database (this user's, plus any legacy or other user's). */
function deleteAllDecryptedDatabases(): void {
  const names = new Set<string>([DB_NAME_PREFIX, ...usedDbNames])
  if (activeUserId !== null) names.add(dbNameFor(activeUserId))
  usedDbNames.clear()
  const remove = (name: string) => {
    const req = indexedDB.deleteDatabase(name)
    req.onerror = () => console.warn('Failed to delete decrypted cache database')
  }
  names.forEach(remove)
  if (typeof indexedDB.databases === 'function') {
    indexedDB
      .databases()
      .then((all) => {
        for (const { name } of all) {
          if (name && name.startsWith(DB_NAME_PREFIX) && !names.has(name)) remove(name)
        }
      })
      .catch(() => {})
  }
}

/**
 * Wipes all decrypted data. Closes the open connection first, otherwise
 * `deleteDatabase` stays blocked until the page unloads.
 */
export function clearDecryptedCache(): void {
  for (const url of memoryCache.values()) URL.revokeObjectURL(url)
  memoryCache.clear()
  originalAccessOrder.length = 0
  currentOriginalBytes = 0
  const pending = dbPromise
  dbPromise = null
  if (pending) {
    pending
      .then((db) => db.close())
      .catch(() => {})
      .finally(deleteAllDecryptedDatabases)
  } else {
    deleteAllDecryptedDatabases()
  }
}

export function removeUrlFromCache(url: string): void {
  for (const [key, cachedUrl] of memoryCache.entries()) {
    if (cachedUrl === url) {
      memoryCache.delete(key)
      break
    }
  }
}
