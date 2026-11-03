import { describe, it, expect, vi, beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import type { AxiosResponse } from 'axios'

vi.mock('@/services/api', () => ({
  api: {
    get: vi.fn<(url: string) => Promise<unknown>>(),
    post: vi.fn<(url: string, data?: unknown) => Promise<unknown>>(),
  },
  default: {
    setAuthToken: vi.fn<(token: string) => void>(),
    clearAuthToken: vi.fn<() => void>(),
  },
}))

vi.mock('@/lib/auth-storage', () => ({
  getSessionItem: vi.fn<(key: string) => string | null>(() => null),
  setSessionItem: vi.fn<(key: string, value: string) => void>(),
  removeSessionItem: vi.fn<(key: string) => void>(),
  clearSession: vi.fn<() => void>(),
  removePendingInviteToken: vi.fn<() => void>(),
  setStaySignedIn: vi.fn<(value: boolean) => void>(),
}))

vi.mock('@/crypto/keychain', () => ({
  unwrapPrivateKey: vi.fn<(wrapped: string, kek: CryptoKey) => Promise<CryptoKey>>(
    async () => ({ type: 'private' }) as unknown as CryptoKey,
  ),
  rewrapSharedDEK: vi.fn<(shared: string, privateKey: CryptoKey, kek: CryptoKey) => Promise<string>>(
    async (shared: string) => `kek(${shared})`,
  ),
  importPublicKey: vi.fn<(jwk: string) => Promise<CryptoKey>>(
    async () => ({ type: 'public' }) as unknown as CryptoKey,
  ),
  rewrapDEKForPublicKey: vi.fn<(wrapped: string, kek: CryptoKey, publicKey: CryptoKey) => Promise<string>>(
    async (wrapped: string) => `rsa(${wrapped})`,
  ),
}))

vi.mock('@/lib/cache/decrypted-cache', () => ({
  clearDecryptedCache: vi.fn<() => void>(),
  setDecryptedCacheUser: vi.fn<(userId: number | null) => void>(),
}))

vi.mock('vue-sonner', () => ({
  toast: { success: vi.fn<(message: string) => void>(), error: vi.fn<(message: string) => void>() },
}))

import { api } from '@/services/api'
import { toast } from 'vue-sonner'
import { useAuthStore } from '@/stores/auth'
import { syncEncryptionKeys } from '@/services/key-sync'
import { importPublicKey, rewrapDEKForPublicKey } from '@/crypto/keychain'
import type { EncryptionKey } from '@/types/auth'

function ok<T>(data: T): AxiosResponse<T> {
  return { data, status: 200, statusText: 'OK', headers: {}, config: {} as never }
}

function respondWith(keys: EncryptionKey[], grants: unknown[] = []) {
  vi.mocked(api.get).mockImplementation(async (url: string) => {
    if (url === '/encryption-keys') return ok({ data: keys })
    if (url === '/encryption-keys/pending-grants') return ok({ data: grants })
    throw new Error(`unexpected GET ${url}`)
  })
}

function signIn() {
  const store = useAuthStore()
  store.user = {
    id: 1,
    email: 'ana@example.com',
    display_name: 'Ana',
    master_key_salt: 'salt',
    public_key: 'jwk',
    encrypted_private_key: 'wrapped-private',
  }
  store.kek = { type: 'secret' } as unknown as CryptoKey
  return store
}

describe('syncEncryptionKeys', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(api.get).mockReset()
    vi.mocked(api.post).mockReset()
    vi.mocked(api.post).mockResolvedValue(ok({}))
    vi.mocked(toast.success).mockReset()
    vi.mocked(rewrapDEKForPublicKey).mockClear()
    vi.mocked(importPublicKey).mockClear()
  })

  it('does nothing without a KEK', async () => {
    useAuthStore()
    await syncEncryptionKeys()
    expect(api.get).not.toHaveBeenCalled()
  })

  it('re-wraps keys shared with this user and uploads them', async () => {
    const store = signIn()
    respondWith([
      { child_id: 1, child_name: 'Wren', encrypted_dek: 'own', wrap_type: 'kek', key_version: 1 },
      { child_id: 2, child_name: 'Ivy', encrypted_dek: 'shared', wrap_type: 'public_key', key_version: 1 },
    ])

    await syncEncryptionKeys()

    expect(api.post).toHaveBeenCalledWith('/encryption-keys', {
      child_id: 2,
      wrapped_dek: 'kek(shared)',
    })
    expect(store.encryptionKeys.find((key) => key.child_id === 2)).toMatchObject({
      encrypted_dek: 'kek(shared)',
      wrap_type: 'kek',
    })
    expect(toast.success).toHaveBeenCalledWith(
      'You can now see photos of Ivy.',
      expect.anything(),
    )
  })

  it('shares nothing with a new member while still re-wrapping own shared keys', async () => {
    const store = signIn()
    respondWith(
      [
        { child_id: 1, child_name: 'Wren', encrypted_dek: 'own', wrap_type: 'kek', key_version: 1 },
        { child_id: 2, child_name: 'Ivy', encrypted_dek: 'shared', wrap_type: 'public_key', key_version: 1 },
      ],
      [{ child_id: 1, user_id: 9, display_name: 'Ben', public_key: 'member-jwk' }],
    )

    await syncEncryptionKeys()

    expect(api.post).toHaveBeenCalledTimes(1)
    expect(api.post).toHaveBeenCalledWith('/encryption-keys', {
      child_id: 2,
      wrapped_dek: 'kek(shared)',
    })
    expect(api.post).not.toHaveBeenCalledWith('/encryption-keys/wrap-for-invitee', expect.anything())
    expect(rewrapDEKForPublicKey).not.toHaveBeenCalled()
    expect(importPublicKey).not.toHaveBeenCalled()
    expect(store.encryptionKeys.find((key) => key.child_id === 2)?.wrap_type).toBe('kek')
  })

  it('does not throw when fetching keys fails', async () => {
    signIn()
    vi.spyOn(console, 'error').mockImplementation(() => undefined)
    vi.mocked(api.get).mockRejectedValue(new Error('offline'))

    await expect(syncEncryptionKeys()).resolves.toBeUndefined()
  })

  it('runs one sync at a time', async () => {
    signIn()
    respondWith([])

    await Promise.all([syncEncryptionKeys(), syncEncryptionKeys()])

    expect(vi.mocked(api.get).mock.calls.filter(([url]) => url === '/encryption-keys')).toHaveLength(1)
  })
})
