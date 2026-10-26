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

vi.mock('@/crypto/auth-hash', () => ({
  deriveKekAndAuthHash: vi.fn<(password: string, salt: string) => Promise<{ kek: CryptoKey; authHash: string }>>(),
}))

vi.mock('@/crypto/keychain', () => ({
  generateSalt: vi.fn<() => string>(),
  generateKeyPair: vi.fn<() => Promise<{ publicKey: CryptoKey; privateKey: CryptoKey }>>(),
  exportPublicKey: vi.fn<(key: CryptoKey) => Promise<string>>(),
  exportKEK: vi.fn<(kek: CryptoKey) => Promise<string>>(),
  importKEK: vi.fn<(jwk: string) => Promise<CryptoKey>>(),
  wrapPrivateKey: vi.fn<(privateKey: CryptoKey, kek: CryptoKey) => Promise<string>>(
    async () => 'wrapped-private-key',
  ),
  rewrapDEK: vi.fn<(wrapped: string, oldKek: CryptoKey, newKek: CryptoKey) => Promise<string>>(),
  rewrapPrivateKey: vi.fn<(wrapped: string, oldKek: CryptoKey, newKek: CryptoKey) => Promise<string>>(),
}))

vi.mock('@/lib/cache/decrypted-cache', () => ({
  clearDecryptedCache: vi.fn<() => void>(),
  setDecryptedCacheUser: vi.fn<(userId: number | null) => void>(),
}))

vi.mock('@/lib/share-inbox', () => ({
  clearShareInbox: vi.fn<() => void>(),
}))

import { api } from '@/services/api'
import { clearDecryptedCache } from '@/lib/cache/decrypted-cache'
import { clearShareInbox } from '@/lib/share-inbox'
import {
  setStaySignedIn,
  setSessionItem,
  getSessionItem,
  removePendingInviteToken,
} from '@/lib/auth-storage'
import { deriveKekAndAuthHash } from '@/crypto/auth-hash'
import {
  exportKEK,
  generateSalt,
  generateKeyPair,
  exportPublicKey,
  wrapPrivateKey,
  rewrapDEK,
  rewrapPrivateKey,
} from '@/crypto/keychain'
import apiService from '@/services/api'
import {
  useAuthStore,
  isMfaRequired,
  WrongPasswordError,
  NewPasswordError,
  type MfaRequiredResponse,
} from '@/stores/auth'
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
    encrypted_private_key: 'wrapped-private-key',
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

/** The mocked derivation: the auth hash is visibly derived from the password it came from. */
function authHashOf(password: string): string {
  return `auth-hash-of-${password}`
}

/** Answers pre-login, and every other POST with the handler. */
function mockPost(handler: (url: string, data?: unknown) => unknown) {
  vi.mocked(api.post).mockImplementation((url: string, data?: unknown) => {
    if (url === '/auth/prelogin') {
      return Promise.resolve(ok({ data: { master_key_salt: 'deadbeef' } }))
    }
    return Promise.resolve(handler(url, data))
  })
}

/** Everything the store ever sent to the server, as one string. */
function allSentPayloads(): string {
  return JSON.stringify([
    ...vi.mocked(api.post).mock.calls,
    ...vi.mocked(api.put).mock.calls,
  ])
}

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
    vi.mocked(deriveKekAndAuthHash).mockReset()
    vi.mocked(exportKEK).mockReset()
    vi.mocked(generateSalt).mockReset()
    vi.mocked(generateKeyPair).mockReset()
    vi.mocked(exportPublicKey).mockReset()
    vi.mocked(apiService.setAuthToken).mockReset()

    vi.mocked(deriveKekAndAuthHash).mockImplementation(async (password: string) => ({
      kek: fakeKEK,
      authHash: authHashOf(password),
    }))
    vi.mocked(exportKEK).mockResolvedValue('kek-jwk')
    vi.mocked(generateSalt).mockReturnValue('saltsalt')
    vi.mocked(generateKeyPair).mockResolvedValue({
      publicKey: { type: 'public' } as unknown as CryptoKey,
      privateKey: { type: 'private' } as unknown as CryptoKey,
    })
    vi.mocked(exportPublicKey).mockResolvedValue('public-key-jwk')
    vi.mocked(rewrapDEK).mockReset()
    vi.mocked(rewrapPrivateKey).mockReset()
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

  it('logs a v2 account in with only the auth hash and stores the session', async () => {
    mockPost(() => ok(makeAuthResponse()))

    const store = useAuthStore()
    const result = await store.login('ana@example.com', 'secret-password', false)

    expect(isMfaRequired(result)).toBe(false)
    expect(api.post).toHaveBeenCalledWith('/auth/prelogin', { email: 'ana@example.com' })
    expect(deriveKekAndAuthHash).toHaveBeenCalledTimes(1)
    expect(deriveKekAndAuthHash).toHaveBeenCalledWith('secret-password', 'deadbeef')
    expect(api.post).toHaveBeenCalledWith('/auth/login', {
      email: 'ana@example.com',
      auth_hash: authHashOf('secret-password'),
      remember: false,
    })
    expect(allSentPayloads()).not.toContain('"secret-password"')
    expect(setStaySignedIn).toHaveBeenCalledWith(false)
    expect(apiService.setAuthToken).toHaveBeenCalledWith('token-123')
    expect(setSessionItem).toHaveBeenCalledWith('kek', 'kek-jwk')
    expect(setSessionItem).toHaveBeenCalledWith('user', JSON.stringify(makeUser()))
    expect(setSessionItem).toHaveBeenCalledWith('encryption_keys', '[]')
    expect(store.user?.email).toBe('ana@example.com')
  })


  it('returns MFA required instead of storing a session', async () => {
    const mfaPayload: MfaRequiredResponse = {
      mfa_required: true,
      mfa_token: 'mfa-token',
      mfa_methods: ['totp', 'passkey'],
    }
    mockPost(() => ok(mfaPayload))

    const store = useAuthStore()
    const result = await store.login('ana@example.com', 'secret-password', true)

    expect(isMfaRequired(result)).toBe(true)
    expect(result).toEqual(mfaPayload)
    expect(apiService.setAuthToken).not.toHaveBeenCalled()
    expect(setSessionItem).not.toHaveBeenCalled()
    expect(store.user).toBeNull()
  })

  it('completes an MFA challenge with the key from the password step, without the password', async () => {
    mockPost((url: string) =>
      url === '/auth/login'
        ? ok({ mfa_required: true, mfa_token: 'mfa-token', mfa_methods: ['totp'] })
        : ok(makeAuthResponse()),
    )

    const store = useAuthStore()
    await store.login('ana@example.com', 'secret-password', false)
    await store.mfaChallenge({
      mfaToken: 'mfa-token',
      method: 'totp',
      code: '123456',
      staySignedIn: false,
    })

    expect(api.post).toHaveBeenCalledWith('/auth/mfa/challenge', {
      mfa_token: 'mfa-token',
      method: 'totp',
      code: '123456',
    })
    expect(allSentPayloads()).not.toContain('"secret-password"')
    // The key was derived once, at the password step.
    expect(deriveKekAndAuthHash).toHaveBeenCalledTimes(1)
    expect(setStaySignedIn).toHaveBeenCalledWith(false)
    expect(apiService.setAuthToken).toHaveBeenCalledWith('token-123')
    expect(setSessionItem).toHaveBeenCalledWith('kek', 'kek-jwk')
    expect(store.kek).toEqual(fakeKEK)
    expect(store.user?.email).toBe('ana@example.com')
  })

  it('refuses an MFA challenge when no password step came first', async () => {
    vi.mocked(api.post).mockResolvedValue(ok(makeAuthResponse()))

    const store = useAuthStore()
    await expect(
      store.mfaChallenge({ mfaToken: 'mfa-token', method: 'totp', code: '123456' }),
    ).rejects.toThrow('sign in again')
    // The single-use mfa_token must not be spent on a request that can't finish.
    expect(api.post).not.toHaveBeenCalled()
  })

  it('refuses an MFA challenge for a token other than the one from the password step', async () => {
    mockPost(() => ok({ mfa_required: true, mfa_token: 'mfa-token', mfa_methods: ['totp'] }))

    const store = useAuthStore()
    await store.login('ana@example.com', 'secret-password')
    vi.mocked(api.post).mockClear()

    await expect(
      store.mfaChallenge({ mfaToken: 'other-token', method: 'totp', code: '123456' }),
    ).rejects.toThrow('sign in again')
    expect(api.post).not.toHaveBeenCalled()
  })

  it('forgets the pending key once the user goes back to the password step', async () => {
    mockPost(() => ok({ mfa_required: true, mfa_token: 'mfa-token', mfa_methods: ['totp'] }))

    const store = useAuthStore()
    await store.login('ana@example.com', 'secret-password')
    store.discardPendingLogin()
    vi.mocked(api.post).mockClear()

    await expect(
      store.mfaChallenge({ mfaToken: 'mfa-token', method: 'totp', code: '123456' }),
    ).rejects.toThrow('sign in again')
    expect(api.post).not.toHaveBeenCalled()
  })

  it('sends the passkey response for a passkey challenge', async () => {
    mockPost((url: string) =>
      url === '/auth/login'
        ? ok({ mfa_required: true, mfa_token: 'mfa-token', mfa_methods: ['passkey'] })
        : ok(makeAuthResponse()),
    )

    const store = useAuthStore()
    await store.login('ana@example.com', 'secret-password')
    await store.mfaChallenge({
      mfaToken: 'mfa-token',
      method: 'passkey',
      passkeyResponse: { id: 'cred' },
    })

    expect(api.post).toHaveBeenCalledWith('/auth/mfa/challenge', {
      mfa_token: 'mfa-token',
      method: 'passkey',
      passkey_response: { id: 'cred' },
    })
    expect(setStaySignedIn).toHaveBeenCalledWith(true)
  })

  it('registers with only an auth hash and clears the pending invite token', async () => {
    vi.mocked(api.post).mockResolvedValue(ok(makeAuthResponse()))

    const store = useAuthStore()
    const result = await store.register('ana@example.com', 'secret-password', 'Ana', 'invite-123', true)

    expect(deriveKekAndAuthHash).toHaveBeenCalledTimes(1)
    expect(deriveKekAndAuthHash).toHaveBeenCalledWith('secret-password', 'saltsalt')
    expect(api.post).toHaveBeenCalledWith(
      '/auth/register',
      expect.objectContaining({
        email: 'ana@example.com',
        auth_hash: authHashOf('secret-password'),
        invite_token: 'invite-123',
      }),
    )
    expect(allSentPayloads()).not.toContain('"secret-password"')
    expect(allSentPayloads()).not.toContain('"password"')
    expect(setStaySignedIn).toHaveBeenCalledWith(true)
    expect(apiService.setAuthToken).toHaveBeenCalledWith('token-123')
    expect(removePendingInviteToken).toHaveBeenCalled()
    expect(store.user?.display_name).toBe('Ana')
    expect(result).toBeNull()
  })

  it('does not register a password the server would have refused', async () => {
    const store = useAuthStore()
    await expect(store.register('ana@example.com', 'short', 'Ana')).rejects.toThrow('at least 8')
    expect(api.post).not.toHaveBeenCalled()
  })

  it('returns the accepted child when registration auto-accepts an invite', async () => {
    const acceptedChild = { child_id: 7, child_name: 'Wren', child_slug: 'wren' }
    vi.mocked(api.post).mockResolvedValue(ok(makeAuthResponse({ accepted_child: acceptedChild })))

    const store = useAuthStore()
    const result = await store.register('ana@example.com', 'secret-password', 'Ana', 'invite-123', true)

    expect(result).toEqual(acceptedChild)
  })

  it('surfaces API errors on login failure', async () => {
    vi.mocked(api.post).mockImplementation((url: string) =>
      url === '/auth/prelogin'
        ? Promise.resolve(ok({ data: { master_key_salt: 'deadbeef' } }))
        : Promise.reject({ response: { data: { message: 'Invalid credentials' } } }),
    )

    const store = useAuthStore()
    await expect(store.login('ana@example.com', 'secret-password')).rejects.toBeDefined()
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

  it('purges the decrypted cache and share inbox when no token is stored', () => {
    vi.mocked(getSessionItem).mockReturnValue(null)

    useAuthStore().initializeAuth()

    expect(clearDecryptedCache).toHaveBeenCalled()
    expect(clearShareInbox).toHaveBeenCalled()
  })

  it('sends the stay-signed-in choice so the server can expire the token', async () => {
    mockPost(() => ok(makeAuthResponse()))

    const store = useAuthStore()
    await store.login('ana@example.com', 'secret-password', false)

    expect(api.post).toHaveBeenCalledWith('/auth/login', {
      email: 'ana@example.com',
      auth_hash: authHashOf('secret-password'),
      remember: false,
    })
  })

  it('registers with a KEK-wrapped private key so albums can be shared later', async () => {
    vi.mocked(api.post).mockResolvedValue(ok(makeAuthResponse()))

    const store = useAuthStore()
    await store.register('ana@example.com', 'secret-password', 'Ana', undefined, false)

    expect(wrapPrivateKey).toHaveBeenCalledWith({ type: 'private' }, fakeKEK)
    expect(api.post).toHaveBeenCalledWith(
      '/auth/register',
      expect.objectContaining({
        public_key: 'public-key-jwk',
        encrypted_private_key: 'wrapped-private-key',
        remember: false,
      }),
    )
  })

  it('creates a key pair at sign-in for accounts that never stored one', async () => {
    mockPost(() => ok(makeAuthResponse({ user: makeUser({ encrypted_private_key: null }) })))
    vi.mocked(api.put).mockResolvedValue(ok({ user: makeUser() }))

    const store = useAuthStore()
    await store.login('ana@example.com', 'secret-password')

    expect(api.put).toHaveBeenCalledWith('/user/keypair', {
      current_auth_hash: authHashOf('secret-password'),
      public_key: 'public-key-jwk',
      encrypted_private_key: 'wrapped-private-key',
    })
    expect(allSentPayloads()).not.toContain('"secret-password"')
    expect(store.user?.encrypted_private_key).toBe('wrapped-private-key')
  })

  describe('withPasswordProof', () => {
    it('sends only the auth hash', async () => {
      const store = useAuthStore()
      store.user = makeUser()
      const send = vi.fn<(proof: unknown) => Promise<string>>().mockResolvedValue('done')

      await expect(store.withPasswordProof('secret-password', send)).resolves.toBe('done')

      expect(deriveKekAndAuthHash).toHaveBeenCalledWith('secret-password', 'deadbeef')
      expect(send).toHaveBeenCalledWith({ current_auth_hash: authHashOf('secret-password') })
    })
  })

  describe('changePassword', () => {
    const newKEK = { type: 'secret', which: 'new' } as unknown as CryptoKey

    beforeEach(() => {
      vi.mocked(deriveKekAndAuthHash).mockImplementation(async (password: string) => ({
        kek: password === 'new-password' ? newKEK : fakeKEK,
        authHash: authHashOf(password),
      }))
      vi.mocked(api.get).mockResolvedValue(
        ok({
          data: [
            { child_id: 1, child_name: 'Wren', encrypted_dek: 'dek-1', wrap_type: 'kek', key_version: 1 },
            { child_id: 2, child_name: 'Ivy', encrypted_dek: 'shared', wrap_type: 'public_key', key_version: 1 },
          ],
        }),
      )
    })

    it('re-wraps every own key and the private key, then saves them with the new password', async () => {
      vi.mocked(rewrapDEK).mockResolvedValue('dek-1-new')
      vi.mocked(rewrapPrivateKey).mockResolvedValue('private-new')
      const savedKeys = [
        { child_id: 1, child_name: 'Wren', encrypted_dek: 'dek-1-new', wrap_type: 'kek', key_version: 1 },
      ]
      vi.mocked(api.put).mockResolvedValue(
        ok({ user: makeUser({ encrypted_private_key: 'private-new' }), encryption_keys: savedKeys }),
      )

      const store = useAuthStore()
      store.user = makeUser()
      await store.changePassword({
        currentPassword: 'old-password',
        newPassword: 'new-password',
        newPasswordConfirmation: 'new-password',
      })

      expect(rewrapDEK).toHaveBeenCalledTimes(1)
      expect(rewrapDEK).toHaveBeenCalledWith('dek-1', fakeKEK, newKEK)
      expect(rewrapPrivateKey).toHaveBeenCalledWith('wrapped-private-key', fakeKEK, newKEK)
      expect(api.put).toHaveBeenCalledWith('/user/password', {
        current_auth_hash: authHashOf('old-password'),
        new_auth_hash: authHashOf('new-password'),
        encryption_keys: [{ child_id: 1, key_version: 1, encrypted_dek: 'dek-1-new' }],
        encrypted_private_key: 'private-new',
      })
      expect(allSentPayloads()).not.toContain('"old-password"')
      expect(allSentPayloads()).not.toContain('"new-password"')
      expect(store.kek).toEqual(newKEK)
      expect(store.encryptionKeys).toEqual(savedKeys)
    })


    it.each([
      ['too short', 'short', 'short', 'at least 8'],
      ['mismatched', 'new-password', 'new-passwore', "don't match"],
      ['unchanged', 'old-password', 'old-password', 'must be different'],
    ])('rejects a %s new password before deriving anything', async (_name, next, confirm, message) => {
      const store = useAuthStore()
      store.user = makeUser()

      const result = store.changePassword({
        currentPassword: 'old-password',
        newPassword: next,
        newPasswordConfirmation: confirm,
      })

      await expect(result).rejects.toBeInstanceOf(NewPasswordError)
      await expect(result).rejects.toThrow(message)
      expect(deriveKekAndAuthHash).not.toHaveBeenCalled()
      expect(api.put).not.toHaveBeenCalled()
    })

    it('stops before calling the server when the current password is wrong', async () => {
      vi.mocked(rewrapDEK).mockRejectedValue(new DOMException('bad tag', 'OperationError'))

      const store = useAuthStore()
      store.user = makeUser()
      store.kek = fakeKEK

      await expect(
        store.changePassword({
          currentPassword: 'typo',
          newPassword: 'new-password',
          newPasswordConfirmation: 'new-password',
        }),
      ).rejects.toBeInstanceOf(WrongPasswordError)
      expect(api.put).not.toHaveBeenCalled()
      expect(store.kek).toEqual(fakeKEK)
      expect(store.error).toBe('The current password is incorrect.')
    })
  })

  it('restores the KEK once, however many callers wait for it', async () => {
    vi.mocked(getSessionItem).mockImplementation((key: string) => (key === 'kek' ? 'kek-jwk' : null))
    const { importKEK } = await import('@/crypto/keychain')
    vi.mocked(importKEK).mockReset()
    vi.mocked(importKEK).mockResolvedValue(fakeKEK)

    const store = useAuthStore()
    await Promise.all([store.restoreKEK(), store.restoreKEK()])

    expect(importKEK).toHaveBeenCalledTimes(1)
    expect(store.kek).toEqual(fakeKEK)
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
    expect(clearDecryptedCache).toHaveBeenCalled()
    expect(clearShareInbox).toHaveBeenCalled()
  })
})
