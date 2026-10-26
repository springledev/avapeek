import { describe, it, expect } from 'vitest'
import { MIN_PASSWORD_LENGTH, newPasswordError } from '@/lib/password-rules'

describe('newPasswordError', () => {
  it('matches the minimum length the server used to enforce', () => {
    expect(MIN_PASSWORD_LENGTH).toBe(8)
  })

  it('accepts a long enough, confirmed, changed password', () => {
    expect(newPasswordError('abcdefgh', 'abcdefgh', 'old-password')).toBeNull()
    expect(newPasswordError('abcdefgh', 'abcdefgh')).toBeNull()
  })

  it('rejects a password shorter than the minimum', () => {
    expect(newPasswordError('abcdefg', 'abcdefg')).toContain('at least 8')
  })

  it('rejects a mismatched confirmation', () => {
    expect(newPasswordError('abcdefgh', 'abcdefgx')).toContain("don't match")
  })

  it('rejects reusing the current password', () => {
    expect(newPasswordError('abcdefgh', 'abcdefgh', 'abcdefgh')).toContain('different')
  })
})
