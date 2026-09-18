import { describe, it, expect, beforeEach } from 'vitest'
import { useGridDensity } from '@/composables/useGridDensity'

describe('useGridDensity', () => {
  beforeEach(() => {
    localStorage.clear()
    document.documentElement.dataset.feedDensity = ''
  })

  it('defaults to density 2', () => {
    const { density, initDensity } = useGridDensity()
    initDensity()
    expect(density.value).toBe('2')
  })

  it('setDensity updates the value and data attribute', () => {
    const { density, setDensity, initDensity } = useGridDensity()
    initDensity()
    setDensity('3')
    expect(density.value).toBe('3')
    expect(document.documentElement.dataset.feedDensity).toBe('3')
  })

  it('initDensity reads from localStorage', () => {
    localStorage.setItem('avapeek-feed-density', '3')
    const { density, initDensity } = useGridDensity()
    initDensity()
    expect(density.value).toBe('3')
  })

  it('initDensity falls back to 2 for invalid stored value', () => {
    localStorage.setItem('avapeek-feed-density', 'invalid')
    const { density, initDensity } = useGridDensity()
    initDensity()
    expect(density.value).toBe('2')
  })

  it('setDensity persists to localStorage', () => {
    const { setDensity, initDensity } = useGridDensity()
    initDensity()
    setDensity('3')
    expect(localStorage.getItem('avapeek-feed-density')).toBe('3')
  })
})
