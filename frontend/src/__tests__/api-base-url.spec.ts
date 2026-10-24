import { describe, it, expect, vi, afterEach } from 'vitest'

describe('api base URL', () => {
  afterEach(() => {
    vi.unstubAllEnvs()
    vi.resetModules()
  })

  it('defaults to the same-origin /api so one image works for every domain', async () => {
    vi.stubEnv('VITE_API_URL', '')
    vi.resetModules()
    const { api } = await import('@/services/api')
    expect(api.defaults.baseURL).toBe('/api')
  })

  it('honours VITE_API_URL when set', async () => {
    vi.stubEnv('VITE_API_URL', 'https://api.example.com/api')
    vi.resetModules()
    const { api } = await import('@/services/api')
    expect(api.defaults.baseURL).toBe('https://api.example.com/api')
  })
})
