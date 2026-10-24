/**
 * Utility functions for child age calculations and display.
 */

/**
 * Parse a `YYYY-MM-DD` (optionally followed by a time part) into calendar components
 * without going through `Date`, so the result never depends on the host time zone.
 */
function parseDateParts(value: string): { year: number; month: number; day: number } | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value)
  if (!match) {
    return null
  }
  return { year: Number(match[1]), month: Number(match[2]) - 1, day: Number(match[3]) }
}

/**
 * Calculate age at a given date (or now). The reference date is read as local calendar
 * components, the birth date as a plain calendar date. Returns null when there is no
 * valid birth date or the reference date is before birth.
 */
export function ageAt(birthDate: string | null, date?: Date): string | null {
  if (!birthDate) {
    return null
  }

  const birth = parseDateParts(birthDate)
  if (!birth) {
    return null
  }

  const ref = date ?? new Date()
  const at = { year: ref.getFullYear(), month: ref.getMonth(), day: ref.getDate() }

  const dayDiff = Math.round(
    (Date.UTC(at.year, at.month, at.day) - Date.UTC(birth.year, birth.month, birth.day)) / 86400000,
  )
  if (dayDiff < 0) {
    return null
  }

  let months = (at.year - birth.year) * 12 + (at.month - birth.month)

  if (at.day < birth.day) {
    months--
  }

  const years = Math.floor(months / 12)
  const remainingMonths = months % 12

  if (years === 0 && remainingMonths === 0) {
    return `${dayDiff} day${dayDiff !== 1 ? 's' : ''} old`
  }

  if (years === 0) {
    return `${remainingMonths} month${remainingMonths > 1 ? 's' : ''} old`
  }

  if (years < 5) {
    return `${years} year${years > 1 ? 's' : ''}, ${remainingMonths} month${remainingMonths !== 1 ? 's' : ''}`
  }

  return `${years} year${years !== 1 ? 's' : ''} old`
}

/**
 * Get a preset avatar color from a palette based on an ID.
 */
export function getAvatarColor(id: number): string {
  const palette = [
    '#FF6B6B',
    '#4ECDC4',
    '#45B7D1',
    '#96CEB4',
    '#FFEAA7',
    '#DDA0DD',
    '#98D8C8',
    '#F7DC6F',
    '#BB8FCE',
    '#85C1E9',
  ]
  return palette[id % palette.length]!
}

/**
 * Get initials from a name for avatar display.
 */
export function getInitials(name: string): string {
  return name
    .split(' ')
    .map((part) => part[0])
    .join('')
    .toUpperCase()
    .substring(0, 2)
}
