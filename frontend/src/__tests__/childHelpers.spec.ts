import { describe, it, expect } from 'vitest'
import { ageAt } from '@/utils/childHelpers'

// Dates are built from local components, so the assertions hold in any host time zone.
const at = (y: number, m: number, d: number) => new Date(y, m - 1, d, 12)

describe('ageAt', () => {
  it('returns null without a birth date', () => {
    expect(ageAt(null, at(2025, 1, 1))).toBeNull()
  })

  it('returns null before birth', () => {
    expect(ageAt('2025-05-10', at(2025, 5, 9))).toBeNull()
  })

  it('counts days when under a month', () => {
    expect(ageAt('2025-05-10', at(2025, 5, 10))).toBe('0 days old')
    expect(ageAt('2025-05-10', at(2025, 5, 11))).toBe('1 day old')
    expect(ageAt('2025-05-10', at(2025, 5, 20))).toBe('10 days old')
  })

  it('counts whole months', () => {
    expect(ageAt('2025-01-15', at(2025, 4, 15))).toBe('3 months old')
    expect(ageAt('2025-01-15', at(2025, 4, 14))).toBe('2 months old')
  })

  it('handles a birth on the 31st', () => {
    expect(ageAt('2025-01-31', at(2025, 2, 28))).toBe('28 days old')
    expect(ageAt('2025-01-31', at(2025, 3, 1))).toBe('1 month old')
    expect(ageAt('2025-01-31', at(2025, 3, 31))).toBe('2 months old')
  })

  it('handles a leap-day birth', () => {
    expect(ageAt('2024-02-29', at(2025, 2, 28))).toBe('11 months old')
    expect(ageAt('2024-02-29', at(2025, 3, 1))).toBe('1 year, 0 months')
  })

  it('formats years and months under five years', () => {
    expect(ageAt('2022-01-01', at(2025, 3, 1))).toBe('3 years, 2 months')
  })

  it('formats five or more years as whole years', () => {
    expect(ageAt('2018-06-01', at(2025, 6, 1))).toBe('7 years old')
  })

  it('does not shift the day for date-only birth dates', () => {
    // new Date('2025-03-01') is UTC midnight and reads as Feb 28 in negative-offset zones.
    expect(ageAt('2025-03-01', at(2025, 3, 1))).toBe('0 days old')
  })
})
