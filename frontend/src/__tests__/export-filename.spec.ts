import { describe, it, expect } from 'vitest'
import {
  extensionForMime,
  fallbackFilename,
  resolveFilename,
  sanitizeFilename,
  uniquePath,
  yearFolder,
} from '@/lib/export/filename'

const photo = (overrides: Partial<Parameters<typeof fallbackFilename>[0]> = {}) => ({
  id: 42,
  original_mime: 'image/jpeg',
  taken_at: '2024-03-09T23:30:00.000Z' as string | null,
  created_at: '2025-01-01T00:00:00.000Z',
  ...overrides,
})

describe('sanitizeFilename', () => {
  it('strips path separators and reserved characters', () => {
    expect(sanitizeFilename('a/b\\c:d*e?f"g<h>i|j.jpg')).toBe('abcdefghij.jpg')
  })

  it('defuses traversal and leading dots', () => {
    expect(sanitizeFilename('../../etc/passwd')).toBe('etcpasswd')
    expect(sanitizeFilename('..')).toBe('')
    expect(sanitizeFilename('.hidden.jpg')).toBe('hidden.jpg')
    expect(sanitizeFilename('/abs/olute.png')).toBe('absolute.png')
  })

  it('removes control, bidi and zero-width characters', () => {
    expect(sanitizeFilename('a\u0000b\u001fc‮d​e.jpg')).toBe('abcde.jpg')
  })

  it('trims trailing dots and spaces', () => {
    expect(sanitizeFilename('  photo.jpg. . ')).toBe('photo.jpg')
  })

  it('prefixes Windows device names', () => {
    expect(sanitizeFilename('CON.jpg')).toBe('_CON.jpg')
    expect(sanitizeFilename('nul')).toBe('_nul')
  })

  it('keeps unicode and normalises to NFC', () => {
    expect(sanitizeFilename('Käfer \u{1F600}.jpg')).toBe('Käfer \u{1F600}.jpg')
    expect(sanitizeFilename('写真.heic')).toBe('写真.heic')
  })

  it('limits length to 120 keeping the extension', () => {
    const result = sanitizeFilename(`${'x'.repeat(300)}.jpeg`)
    expect(result).toHaveLength(120)
    expect(result.endsWith('.jpeg')).toBe(true)
  })
})

describe('fallback and resolved names', () => {
  it('builds avapeek-date-id.ext from taken_at, else created_at', () => {
    expect(fallbackFilename(photo())).toBe('avapeek-20240309-42.jpg')
    expect(fallbackFilename(photo({ taken_at: null, original_mime: 'image/heic' }))).toBe(
      'avapeek-20250101-42.heic',
    )
  })

  it('maps mimes to extensions', () => {
    expect(extensionForMime('image/jpeg')).toBe('jpg')
    expect(extensionForMime('image/heif')).toBe('heif')
    expect(extensionForMime('application/x-unknown')).toBe('bin')
  })

  it('uses the decrypted name when usable, else the fallback', () => {
    expect(resolveFilename(photo(), 'IMG_0001.JPG')).toBe('IMG_0001.JPG')
    expect(resolveFilename(photo(), null)).toBe('avapeek-20240309-42.jpg')
    expect(resolveFilename(photo(), '../..')).toBe('avapeek-20240309-42.jpg')
  })

  it('adds the mime extension when the name has none', () => {
    expect(resolveFilename(photo({ original_mime: 'image/png' }), 'holiday')).toBe('holiday.png')
  })

  it('picks the year folder', () => {
    expect(yearFolder(photo())).toBe('2024')
    expect(yearFolder(photo({ taken_at: null }))).toBe('2025')
    expect(yearFolder({ taken_at: null, created_at: 'garbage' })).toBe('unknown')
  })
})

describe('uniquePath', () => {
  it('de-duplicates case-insensitively before the extension', () => {
    const used = new Set<string>()
    expect(uniquePath('kid/photos/2024', 'a.jpg', used)).toBe('kid/photos/2024/a.jpg')
    expect(uniquePath('kid/photos/2024', 'A.JPG', used)).toBe('kid/photos/2024/A (2).JPG')
    expect(uniquePath('kid/photos/2024', 'a.jpg', used)).toBe('kid/photos/2024/a (3).jpg')
    expect(uniquePath('kid/photos/2025', 'a.jpg', used)).toBe('kid/photos/2025/a.jpg')
  })
})
