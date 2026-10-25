import { describe, it, expect, vi, beforeEach } from 'vitest'
import { AxiosError, type InternalAxiosRequestConfig } from 'axios'

vi.mock('@/lib/auth-storage', () => ({
  getSessionItem: vi.fn<(key: string) => string | null>(() => null),
  setSessionItem: vi.fn<(key: string, value: string) => void>(),
  clearSession: vi.fn<() => void>(),
}))

vi.mock('@/lib/cache/decrypted-cache', () => ({
  clearDecryptedCache: vi.fn<() => void>(),
}))

vi.mock('@/lib/share-inbox', () => ({
  clearShareInbox: vi.fn<() => void>(),
}))

import { api } from '@/services/api'
import { clearSession } from '@/lib/auth-storage'
import { clearDecryptedCache } from '@/lib/cache/decrypted-cache'
import { clearShareInbox } from '@/lib/share-inbox'

describe('api 401 handling', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    window.history.pushState({}, '', '/login')
  })

  it('wipes the decrypted cache and the share inbox on a 401', async () => {
    api.defaults.adapter = async (config: InternalAxiosRequestConfig) => {
      throw new AxiosError('Unauthorized', 'ERR_BAD_REQUEST', config, null, {
        status: 401,
        statusText: 'Unauthorized',
        data: {},
        headers: {},
        config,
      })
    }

    await expect(api.get('/photos')).rejects.toBeDefined()

    expect(clearSession).toHaveBeenCalled()
    expect(clearDecryptedCache).toHaveBeenCalled()
    expect(clearShareInbox).toHaveBeenCalled()
  })
})
