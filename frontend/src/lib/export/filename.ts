/**
 * Filename helpers for downloads and exports. Pure and UI-free so single-photo
 * download, child export and bulk download can share them.
 *
 * Decrypted names come from the user's own encrypted field, but another family
 * member can upload a hostile name, so every path component is sanitised.
 */

export const MAX_FILENAME_LENGTH = 120

const MIME_EXTENSIONS: Record<string, string> = {
  'image/jpeg': 'jpg',
  'image/png': 'png',
  'image/webp': 'webp',
  'image/heic': 'heic',
  'image/heif': 'heif',
}

const RESERVED_CHARS = new Set('/\\:*?"<>|')

/** Controls (C0/C1), zero-width and bidi override/isolate characters. */
function isForbiddenCodePoint(code: number): boolean {
  return (
    code <= 0x1f ||
    (code >= 0x7f && code <= 0x9f) ||
    (code >= 0x200b && code <= 0x200f) ||
    (code >= 0x202a && code <= 0x202e) ||
    (code >= 0x2066 && code <= 0x2069) ||
    code === 0xfeff
  )
}

function stripForbidden(name: string): string {
  let out = ''
  for (const char of name) {
    if (!RESERVED_CHARS.has(char) && !isForbiddenCodePoint(char.codePointAt(0)!)) out += char
  }
  return out
}

const WINDOWS_RESERVED = /^(con|prn|aux|nul|com[1-9]|lpt[1-9])(\..*)?$/i

export function extensionForMime(mime: string): string {
  return MIME_EXTENSIONS[mime.toLowerCase()] ?? 'bin'
}

function splitExtension(name: string): { stem: string; ext: string } {
  const dot = name.lastIndexOf('.')
  if (dot <= 0 || dot === name.length - 1) return { stem: name, ext: '' }
  return { stem: name.slice(0, dot), ext: name.slice(dot) }
}

/** Clamps to `max` characters keeping the extension (up to 16 chars of it). */
function clampLength(name: string, max: number): string {
  if (name.length <= max) return name
  const { stem, ext } = splitExtension(name)
  const keptExt = ext.length <= 16 ? ext : ''
  return stem.slice(0, max - keptExt.length).trimEnd() + keptExt
}

/**
 * Makes one path component safe: strips reserved characters, controls and
 * leading dots, trims trailing dots/spaces, defuses Windows device names and
 * limits the length. Returns '' when nothing usable remains.
 */
export function sanitizeFilename(name: string, max = MAX_FILENAME_LENGTH): string {
  let clean = stripForbidden(name.normalize('NFC'))
  clean = clean.replace(/^[.\s]+/, '').replace(/[.\s]+$/, '')
  if (clean === '') return ''
  if (WINDOWS_RESERVED.test(clean)) clean = `_${clean}`
  return clampLength(clean, max)
}

/** YYYYMMDD in UTC from an ISO timestamp, or '00000000' when unparseable. */
function compactDate(iso: string | null | undefined): string {
  if (!iso) return '00000000'
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return '00000000'
  return date.toISOString().slice(0, 10).replace(/-/g, '')
}

export interface FilenameSource {
  id: number
  original_mime: string
  taken_at: string | null
  created_at: string
}

/** `avapeek-{YYYYMMDD}-{id}.{ext}`, used for photos without a stored original name. */
export function fallbackFilename(photo: FilenameSource): string {
  const date = compactDate(photo.taken_at || photo.created_at)
  return `avapeek-${date}-${photo.id}.${extensionForMime(photo.original_mime)}`
}

/**
 * The name a downloaded photo should get: the decrypted original name when
 * present (sanitised, extension added from the mime if missing), else the fallback.
 */
export function resolveFilename(photo: FilenameSource, decryptedName: string | null): string {
  const clean = decryptedName ? sanitizeFilename(decryptedName) : ''
  if (clean === '') return fallbackFilename(photo)
  const { ext } = splitExtension(clean)
  if (ext !== '') return clean
  return clampLength(`${clean}.${extensionForMime(photo.original_mime)}`, MAX_FILENAME_LENGTH)
}

/**
 * Returns `dir/name`, adding ` (2)`, ` (3)` ... before the extension until the
 * path is unused (case-insensitive), and records it in `used`.
 */
export function uniquePath(dir: string, name: string, used: Set<string>): string {
  const { stem, ext } = splitExtension(name)
  let candidate = name
  let counter = 1
  while (used.has(`${dir}/${candidate}`.toLowerCase())) {
    counter++
    candidate = `${stem} (${counter})${ext}`
  }
  const path = `${dir}/${candidate}`
  used.add(path.toLowerCase())
  return path
}

/** Year folder for an export entry, from taken_at falling back to created_at. */
export function yearFolder(photo: Pick<FilenameSource, 'taken_at' | 'created_at'>): string {
  const date = compactDate(photo.taken_at || photo.created_at)
  return date === '00000000' ? 'unknown' : date.slice(0, 4)
}
