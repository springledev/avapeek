import { describe, it, expect, vi, beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import type { AxiosResponse } from 'axios'

vi.mock('@/services/api', () => ({
  api: {
    get: vi.fn<(url: string, config?: unknown) => Promise<unknown>>(),
    post: vi.fn<(url: string, data?: unknown) => Promise<unknown>>(),
    put: vi.fn<(url: string, data?: unknown) => Promise<unknown>>(),
    delete: vi.fn<(url: string) => Promise<unknown>>(),
  },
  default: {
    setAuthToken: vi.fn<(token: string) => void>(),
    clearAuthToken: vi.fn<() => void>(),
  },
}))

vi.mock('@/lib/auth-storage', () => ({
  getSessionItem: vi.fn<(key: string) => string | null>(),
  setSessionItem: vi.fn<(key: string, value: string) => void>(),
  removeSessionItem: vi.fn<(key: string) => void>(),
  clearSession: vi.fn<() => void>(),
  getPendingInviteToken: vi.fn<() => string | null>(),
  setPendingInviteToken: vi.fn<(token: string) => void>(),
  removePendingInviteToken: vi.fn<() => void>(),
  setStaySignedIn: vi.fn<(value: boolean) => void>(),
  getStaySignedIn: vi.fn<() => boolean>(),
}))

vi.mock('@/crypto/keychain', () => ({
  deriveKEK: vi.fn<(password: string, salt: string) => Promise<CryptoKey>>(),
  generateSalt: vi.fn<() => string>(),
  generateKeyPair: vi.fn<() => Promise<{ publicKey: CryptoKey; privateKey: CryptoKey }>>(),
  exportPublicKey: vi.fn<(key: CryptoKey) => Promise<string>>(),
  exportKEK: vi.fn<(kek: CryptoKey) => Promise<string>>(),
  importKEK: vi.fn<(jwk: string) => Promise<CryptoKey>>(),
}))

vi.mock('@/lib/cache/decrypted-cache', () => ({
  clearDecryptedCache: vi.fn<() => void>(),
}))

import { api } from '@/services/api'
import {
  setStaySignedIn,
  setSessionItem,
  getSessionItem,
  removePendingInviteToken,
} from '@/lib/auth-storage'
import {
  deriveKEK,
  exportKEK,
  generateSalt,
  generateKeyPair,
  exportPublicKey,
} from '@/crypto/keychain'
import apiService from '@/services/api'
import { useAuthStore, isMfaRequired, type MfaRequiredResponse } from '@/stores/auth'
import type { User, AuthResponse } from '@/types/auth'

function ok<T>(data: T): AxiosResponse<T> {
  return {
    data,
    status: 200,
    statusText: 'OK',
    headers: {},
    config: { headers: {} },
  } as unknown as AxiosResponse<T>
}

function makeUser(overrides: Partial<User> = {}): User {
  return {
    id: 1,
    email: 'ana@example.com',
    display_name: 'Ana',
    master_key_salt: 'deadbeef',
    public_key: 'jwk',
    ...overrides,
  }
}

function makeAuthResponse(overrides: Partial<AuthResponse> = {}): AuthResponse {
  return {
    user: makeUser(),
    token: 'token-123',
    encryption_keys: [],
    ...overrides,
  }
}

const fakeKEK = { type: 'secret' } as unknown as CryptoKey

describe('auth store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(api.get).mockReset()
    vi.mocked(api.post).mockReset()
    vi.mocked(api.put).mockReset()
    vi.mocked(api.delete).mockReset()
    vi.mocked(setStaySignedIn).mockReset()
    vi.mocked(setSessionItem).mockReset()
    vi.mocked(getSessionItem).mockReset()
    vi.mocked(removePendingInviteToken).mockReset()
    vi.mocked(deriveKEK).mockReset()
    vi.mocked(exportKEK).mockReset()
    vi.mocked(generateSalt).mockReset()
    vi.mocked(generateKeyPair).mockReset()
    vi.mocked(exportPublicKey).mockReset()
    vi.mocked(apiService.setAuthToken).mockReset()

    vi.mocked(deriveKEK).mockResolvedValue(fakeKEK)
    vi.mocked(exportKEK).mockResolvedValue('kek-jwk')
    vi.mocked(generateSalt).mockReturnValue('saltsalt')
    vi.mocked(generateKeyPair).mockResolvedValue({
      publicKey: { type: 'public' } as unknown as CryptoKey,
      privateKey: { type: 'private' } as unknown as CryptoKey,
    })
    vi.mocked(exportPublicKey).mockResolvedValue('public-key-jwk')
  })

  describe('isMfaRequired', () => {
    it('returns true for an MFA required payload', () => {
      const payload = { mfa_required: true, mfa_token: 'abc', mfa_methods: ['totp'] }
      expect(isMfaRequired(payload)).toBe(true)
    })

    it('returns false for a plain auth response', () => {
      expect(isMfaRequired(makeAuthResponse())).toBe(false)
    })

    it('returns false for non-objects and incomplete shapes', () => {
      expect(isMfaRequired(null)).toBe(false)
      expect(isMfaRequired(undefined)).toBe(false)
      expect(isMfaRequired({ mfa_required: true })).toBe(false)
      expect(isMfaRequired({ mfa_token: 'abc' })).toBe(false)
    })
  })

  it('logs in and stores the session in active storage', async () => {
    vi.mocked(api.post).mockResolvedValue(ok(makeAuthResponse()))

    const store = useAuthStore()
    const result = await store.login('ana@example.com', 'secret', false)

    expect(isMfaRequired(result)).toBe(false)
    expect(setStaySignedIn).toHaveBeenCalledWith(false)
    expect(apiService.setAuthToken).toHaveBeenCalledWith('token-123')
    expect(deriveKEK).toHaveBeenCalledWith('secret', 'deadbeef')
    expect(setSessionItem).toHaveBeenCalledWith('kek', 'kek-jwk')
    expect(setSessionItem).toHaveBeenCalledWith(
      'user',
      JSON.stringify(makeUser()),
    )
    expect(setSessionItem).toHaveBeenCalledWith('encryption_keys', '[]')
    expect(store.user?.email).toBe('ana@example.com')
  })

  it('returns MFA required instead of storing a session', async () => {
    const mfaPayload: MfaRequiredResponse = {
      mfa_required: true,
      mfa_token: 'mfa-token',
      mfa_methods: ['totp', 'passkey'],
    }
    vi.mocked(api.post).mockResolvedValue(ok(mfaPayload))

    const store = useAuthStore()
    const result = await store.login('ana@example.com', 'secret', true)

    expect(isMfaRequired(result)).toBe(true)
    expect(result).toEqual(mfaPayload)
    expect(apiService.setAuthToken).not.toHaveBeenCalled()
    expect(setSessionItem).not.toHaveBeenCalled()
    expect(store.user).toBeNull()
  })

  it('completes an MFA challenge and stores the session', async () => {
    vi.mocked(api.post).mockResolvedValue(ok(makeAuthResponse()))

    const store = useAuthStore()
    await store.mfaChallenge('mfa-token', 'totp', '123456', undefined, 'secret')

    expect(api.post).toHaveBeenCalledWith('/auth/mfa/challenge', {
      mfa_token: 'mfa-token',
      method: 'totp',
      code: '123456',
    })
    expect(apiService.setAuthToken).toHaveBeenCalledWith('token-123')
    expect(setSessionItem).toHaveBeenCalledWith('kek', 'kek-jwk')
    expect(store.user?.email).toBe('ana@example.com')
  })

  it('throws when mfaChallenge is called without a password', async () => {
    vi.mocked(api.post).mockResolvedValue(ok(makeAuthResponse()))

    const store = useAuthStore()
    await expect(store.mfaChallenge('mfa-token', 'totp', '123456')).rejects.toThrow(
      'Password is required',
    )
  })

  it('registers a new user and clears the pending invite token', async () => {
    vi.mocked(api.post).mockResolvedValue(ok(makeAuthResponse()))

    const store = useAuthStore()
    await store.register('ana@example.com', 'secret', 'Ana', 'invite-123', true)

    expect(setStaySignedIn).toHaveBeenCalledWith(true)
    expect(apiService.setAuthToken).toHaveBeenCalledWith('token-123')
    expect(removePendingInviteToken).toHaveBeenCalled()
    expect(store.user?.display_name).toBe('Ana')
  })

  it('surfaces API errors on login failure', async () => {
    vi.mocked(api.post).mockRejectedValue({
      response: { data: { message: 'Invalid credentials' } },
    })

    const store = useAuthStore()
    await expect(store.login('ana@example.com', 'secret')).rejects.toBeDefined()
    expect(store.error).toBe('Invalid credentials')
  })

  it('initializes auth from stored session', () => {
    vi.mocked(getSessionItem).mockImplementation((key: string) => {
      if (key === 'auth_token') return 'stored-token'
      if (key === 'user') return JSON.stringify(makeUser({ email: 'stored@example.com' }))
      if (key === 'encryption_keys') return '[]'
      return null
    })

    const store = useAuthStore()
    store.initializeAuth()

    expect(store.isAuthenticated).toBe(true)
    expect(store.user?.email).toBe('stored@example.com')
  })

  it('clears the session on logout', async () => {
    vi.mocked(api.post).mockResolvedValue(ok({}))

    const store = useAuthStore()
    store.user = makeUser()
    store.kek = fakeKEK
    await store.logout()

    expect(apiService.clearAuthToken).toHaveBeenCalled()
    expect(store.user).toBeNull()
    expect(store.kek).toBeNull()
  })
})
