import { describe, expect, it } from 'vitest'
import { kidPairFor, kidPairStyle, KID_PAIRS } from '@/utils/kidColors'

describe('kidColors', () => {
  it('is deterministic per child', () => {
    expect(kidPairFor(42, '#FF6B6B')).toEqual(kidPairFor(42, '#FF6B6B'))
  })

  it('always returns a valid pair, with or without avatar_color', () => {
    for (const id of [0, 1, 2, 7, 42, 1000]) {
      const pair = kidPairFor(id, null)
      expect(KID_PAIRS).toContain(pair)
    }
  })

  it('seeds from avatar_color when provided, falling back to id', () => {
    const seeded = kidPairFor(1, '#FF6B6B')
    expect(KID_PAIRS).toContain(seeded)
    const fallback = kidPairFor(999)
    expect(KID_PAIRS).toContain(fallback)
  })

  it('kidPairStyle returns bg + ink properties', () => {
    const style = kidPairStyle(42, '#abc')
    expect(style).toHaveProperty('backgroundColor')
    expect(style).toHaveProperty('color')
  })
})
