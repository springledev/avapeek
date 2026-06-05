/**
 * Utility functions for child age calculations and display.
 */

/**
 * Calculate age at a given date (or current date).
 */
export function ageAt(birthDate: string | null, date?: Date): string | null {
  if (!birthDate) {
    return null
  }

  const birth = new Date(birthDate)
  const at = date ?? new Date()

  let months =
    (at.getFullYear() - birth.getFullYear()) * 12 + (at.getMonth() - birth.getMonth())

  if (at.getDate() < birth.getDate()) {
    months--
  }
  
  const years = Math.floor(months / 12)
  const remainingMonths = months % 12

  if (years === 0 && remainingMonths === 0) {
    const days = Math.floor((at.getTime() - birth.getTime()) / 86400000)
    return `${days} day${days !== 1 ? 's' : ''} old`
  }

  if (years === 0) {
    return `${remainingMonths} month${remainingMonths > 1 ? 's' : ''} old`
  }

  if (years < 5) {
    return `${years} year${years > 1 ? 's' : ''}, ${remainingMonths} month${remainingMonths > 1 ? 's' : ''}`
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
  return palette[id % palette.length]
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
