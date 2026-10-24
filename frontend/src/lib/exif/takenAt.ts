/**
 * Capture-date extraction for uploaded photos. Lives outside `crypto/`.
 * Reads EXIF from the original file bytes (JPEG and HEIC/HEIF via exifr),
 * never from a re-encoded bitmap.
 */

const EXIF_DATE = /^\s*(\d{4})[:\-/](\d{2})[:\-/](\d{2})[ T](\d{2}):(\d{2}):(\d{2})/
const OFFSET = /^\s*(?:UTC|GMT)?\s*([+-])(\d{2}):?(\d{2})\s*$/

/** Parse an EXIF "YYYY:MM:DD HH:MM:SS" string (+ optional "+HH:MM") to a Date, or null. */
export function parseExifDate(value: unknown, offset?: unknown): Date | null {
  if (value instanceof Date) {
    return Number.isNaN(value.getTime()) ? null : value
  }
  if (typeof value !== 'string') return null
  const m = EXIF_DATE.exec(value)
  if (!m) return null
  const [y, mo, d, h, mi, s] = m.slice(1).map(Number) as [number, number, number, number, number, number]
  if (y === 0 || mo < 1 || mo > 12 || d < 1 || d > 31 || h > 23 || mi > 59 || s > 59) return null

  let date: Date
  const off = typeof offset === 'string' ? OFFSET.exec(offset) : null
  if (off) {
    const minutes = (Number(off[2]) * 60 + Number(off[3])) * (off[1] === '-' ? -1 : 1)
    date = new Date(Date.UTC(y, mo - 1, d, h, mi, s) - minutes * 60_000)
  } else {
    // No offset recorded: interpret as the device's local time.
    date = new Date(y, mo - 1, d, h, mi, s)
  }
  return Number.isNaN(date.getTime()) ? null : date
}

/** Pick the best capture date from raw (unrevived) EXIF tags. */
export function pickTakenAt(tags: Record<string, unknown> | null | undefined): Date | null {
  if (!tags) return null
  return (
    parseExifDate(tags.DateTimeOriginal, tags.OffsetTimeOriginal) ??
    parseExifDate(tags.CreateDate, tags.OffsetTimeDigitized) ??
    parseExifDate(tags.DateTime, tags.OffsetTime)
  )
}

/**
 * Capture date as ISO 8601 (UTC). Falls back to File.lastModified, then null
 * (server then uses upload time).
 */
export async function extractTakenAt(file: File): Promise<string | null> {
  try {
    const exifr = await import('exifr')
    const tags = (await exifr.parse(file, {
      pick: [
        'DateTimeOriginal', 'CreateDate', 'DateTime',
        'OffsetTimeOriginal', 'OffsetTimeDigitized', 'OffsetTime',
      ],
      reviveValues: false,
    })) as Record<string, unknown> | undefined
    const fromExif = pickTakenAt(tags)
    if (fromExif) return fromExif.toISOString()
  } catch {
    // best-effort; fall through
  }
  if (file.lastModified > 0) {
    const d = new Date(file.lastModified)
    if (!Number.isNaN(d.getTime())) return d.toISOString()
  }
  return null
}
