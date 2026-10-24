import { describe, it, expect, vi, afterEach } from 'vitest'
import { parseExifDate, pickTakenAt } from '@/lib/exif/takenAt'

afterEach(() => {
  vi.doUnmock('exifr')
  vi.resetModules()
})

describe('parseExifDate', () => {
  it('parses colon-separated EXIF dates as local time', () => {
    const d = parseExifDate('2024:03:15 14:30:05')!
    expect(d.getFullYear()).toBe(2024)
    expect(d.getMonth()).toBe(2)
    expect(d.getDate()).toBe(15)
    expect(d.getHours()).toBe(14)
    expect(d.getSeconds()).toBe(5)
  })

  it('applies an explicit offset', () => {
    expect(parseExifDate('2024:03:15 14:30:05', '+02:00')!.toISOString()).toBe('2024-03-15T12:30:05.000Z')
    expect(parseExifDate('2024:03:15 14:30:05', '-05:30')!.toISOString()).toBe('2024-03-15T20:00:05.000Z')
  })

  it('rejects invalid input', () => {
    expect(parseExifDate('0000:00:00 00:00:00')).toBeNull()
    expect(parseExifDate('garbage')).toBeNull()
    expect(parseExifDate('2024:13:40 25:00:00')).toBeNull()
    expect(parseExifDate(undefined)).toBeNull()
    expect(parseExifDate(new Date('x'))).toBeNull()
  })
})

describe('pickTakenAt', () => {
  it('prefers DateTimeOriginal, then CreateDate, then DateTime', () => {
    expect(
      pickTakenAt({ DateTimeOriginal: '2020:01:01 00:00:00', OffsetTimeOriginal: '+00:00', CreateDate: '2021:01:01 00:00:00' })!.toISOString(),
    ).toBe('2020-01-01T00:00:00.000Z')
    expect(
      pickTakenAt({ DateTimeOriginal: '0000:00:00 00:00:00', CreateDate: '2021:01:01 00:00:00', OffsetTimeDigitized: '+00:00' })!.toISOString(),
    ).toBe('2021-01-01T00:00:00.000Z')
    expect(pickTakenAt({ DateTime: '2022:01:01 00:00:00', OffsetTime: '+00:00' })!.toISOString()).toBe('2022-01-01T00:00:00.000Z')
    expect(pickTakenAt({})).toBeNull()
    expect(pickTakenAt(null)).toBeNull()
  })
})

describe('extractTakenAt', () => {
  it('uses EXIF when present', async () => {
    vi.doMock('exifr', () => ({
      parse: async () => ({ DateTimeOriginal: '2023:06:01 10:00:00', OffsetTimeOriginal: '+00:00' }),
    }))
    const { extractTakenAt } = await import('@/lib/exif/takenAt')
    const file = new File(['x'], 'a.heic', { lastModified: Date.UTC(2026, 0, 1) })
    expect(await extractTakenAt(file)).toBe('2023-06-01T10:00:00.000Z')
  })

  it('falls back to lastModified when the parser throws', async () => {
    vi.doMock('exifr', () => ({
      parse: async () => {
        throw new Error('nope')
      },
    }))
    const { extractTakenAt } = await import('@/lib/exif/takenAt')
    const file = new File(['x'], 'a.png', { lastModified: Date.UTC(2025, 4, 5) })
    expect(await extractTakenAt(file)).toBe('2025-05-05T00:00:00.000Z')
  })
})
