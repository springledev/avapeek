/**
 * Share inbox: stages files received through the Web Share Target.
 *
 * SECURITY: these are the user's raw, UNENCRYPTED originals, held in IndexedDB
 * (`avapeek-share-inbox`) only until the upload page picks them up. Entries are
 * deleted on pickup, after 1 hour, and on logout / 401 (`clearShareInbox`).
 *
 * Used from both the service worker and the window, so it only touches
 * `indexedDB` and never DOM or Vue APIs.
 */

export const SHARE_INBOX_DB = 'avapeek-share-inbox'
const STORE = 'files'
export const SHARE_INBOX_MAX_AGE_MS = 60 * 60 * 1000

interface InboxRecord {
  id?: number
  blob: Blob
  name: string
  type: string
  lastModified: number
  storedAt: number
}

const openConnections = new Set<IDBDatabase>()

function openInbox(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(SHARE_INBOX_DB, 1)
    req.onupgradeneeded = () => {
      if (!req.result.objectStoreNames.contains(STORE)) {
        req.result.createObjectStore(STORE, { keyPath: 'id', autoIncrement: true })
      }
    }
    req.onsuccess = () => {
      openConnections.add(req.result)
      resolve(req.result)
    }
    req.onerror = () => reject(req.error)
  })
}

function closeInbox(db: IDBDatabase): void {
  openConnections.delete(db)
  db.close()
}

function txDone(tx: IDBTransaction): Promise<void> {
  return new Promise((resolve, reject) => {
    tx.oncomplete = () => resolve()
    tx.onerror = () => reject(tx.error)
    tx.onabort = () => reject(tx.error)
  })
}

function requestResult<T>(req: IDBRequest<T>): Promise<T> {
  return new Promise((resolve, reject) => {
    req.onsuccess = () => resolve(req.result)
    req.onerror = () => reject(req.error)
  })
}

async function purgeExpiredIn(db: IDBDatabase, now: number): Promise<number> {
  const tx = db.transaction(STORE, 'readwrite')
  const store = tx.objectStore(STORE)
  const records = await requestResult(store.getAll() as IDBRequest<InboxRecord[]>)
  let removed = 0
  for (const record of records) {
    if (record.id !== undefined && now - record.storedAt > SHARE_INBOX_MAX_AGE_MS) {
      store.delete(record.id)
      removed++
    }
  }
  await txDone(tx)
  return removed
}

/** Deletes entries older than one hour. Returns the number removed. */
export async function purgeExpiredShares(now: number = Date.now()): Promise<number> {
  const db = await openInbox()
  try {
    return await purgeExpiredIn(db, now)
  } finally {
    closeInbox(db)
  }
}

export async function stageSharedFiles(files: File[], now: number = Date.now()): Promise<void> {
  const db = await openInbox()
  try {
    await purgeExpiredIn(db, now)
    const tx = db.transaction(STORE, 'readwrite')
    const store = tx.objectStore(STORE)
    for (const file of files) {
      const record: InboxRecord = {
        blob: file,
        name: file.name,
        type: file.type,
        lastModified: file.lastModified,
        storedAt: now,
      }
      store.add(record)
    }
    await txDone(tx)
  } finally {
    closeInbox(db)
  }
}

/** Reads all non-expired staged files and deletes every entry (expired ones included). */
export async function takeSharedFiles(now: number = Date.now()): Promise<File[]> {
  const db = await openInbox()
  try {
    const tx = db.transaction(STORE, 'readwrite')
    const store = tx.objectStore(STORE)
    const records = await requestResult(store.getAll() as IDBRequest<InboxRecord[]>)
    store.clear()
    await txDone(tx)
    return records
      .filter((record) => now - record.storedAt <= SHARE_INBOX_MAX_AGE_MS)
      .map(
        (record) =>
          new File([record.blob], record.name, {
            type: record.type,
            lastModified: record.lastModified,
          }),
      )
  } finally {
    closeInbox(db)
  }
}

/**
 * Upload-page entry point: on a shared visit (`?shared=1`) returns and empties the
 * staged files; on any other visit only purges entries older than one hour.
 */
export async function pickUpSharedFiles(isSharedVisit: boolean): Promise<File[]> {
  if (isSharedVisit) {
    return takeSharedFiles()
  }
  await purgeExpiredShares()
  return []
}

/** Closes any open connection, then deletes the whole inbox database. */
export function clearShareInbox(): void {
  for (const db of openConnections) {
    db.close()
  }
  openConnections.clear()
  try {
    const req = indexedDB.deleteDatabase(SHARE_INBOX_DB)
    req.onerror = () => console.warn('Failed to delete share inbox database')
  } catch {
    /* indexedDB unavailable */
  }
}
