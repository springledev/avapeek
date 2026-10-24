import { makeZip } from 'client-zip'

/** One zip entry; names must already be sanitised. Store-only (no compression). */
export interface ZipEntry {
  name: string
  input: Uint8Array | string
  lastModified?: Date
}

/**
 * Lazily builds a store-only zip. Entries are pulled one at a time, so memory
 * stays bounded to the entry being written. client-zip writes Zip64 structures
 * automatically for entries or archives over 4 GB and for more than 65535 files.
 */
export function createZipStream(entries: AsyncIterable<ZipEntry>): ReadableStream<Uint8Array> {
  return makeZip(entries)
}

export interface PlanItem {
  id: number
  size_bytes: number
}

export interface PartPlan {
  /** Photos with id greater than this belong to the part (keyset cursor). */
  startAfterId: number
  /** Last photo id in the part (inclusive). */
  lastId: number
  count: number
  bytes: number
}

/**
 * Splits photos (ascending id) into consecutive parts of at most `partBytes`.
 * A single photo larger than the limit gets a part of its own. Always returns
 * at least one part so an empty child still yields a manifest.
 */
export function planParts(items: PlanItem[], partBytes: number, afterId = 0): PartPlan[] {
  const parts: PartPlan[] = []
  let current: PartPlan | null = null

  for (const item of items) {
    if (current && current.count > 0 && current.bytes + item.size_bytes > partBytes) {
      parts.push(current)
      current = null
    }
    if (!current) {
      current = { startAfterId: parts[parts.length - 1]?.lastId ?? afterId, lastId: item.id, count: 0, bytes: 0 }
    }
    current.lastId = item.id
    current.count++
    current.bytes += item.size_bytes
  }

  if (current) parts.push(current)
  if (parts.length === 0) parts.push({ startAfterId: afterId, lastId: afterId, count: 0, bytes: 0 })
  return parts
}

const MOBILE_UA = /android|iphone|ipad|ipod|mobile/i

export const PART_BYTES_MOBILE = 400 * 1024 * 1024
export const PART_BYTES_DESKTOP = 1.5 * 1024 * 1024 * 1024

export function defaultPartBytes(userAgent: string): number {
  return MOBILE_UA.test(userAgent) ? PART_BYTES_MOBILE : PART_BYTES_DESKTOP
}
