import { defineStore } from 'pinia'
import { ref, computed, watch } from 'vue'
import { api } from '@/services/api'
import apiService from '@/services/api'
import { apiErrorMessage } from '@/lib/util'
import { clearDecryptedCache, setDecryptedCacheUser } from '@/lib/cache/decrypted-cache'
import { clearShareInbox } from '@/lib/share-inbox'
import {
  getSessionItem,
  setSessionItem,
  removeSessionItem,
  clearSession,
  removePendingInviteToken,
  setStaySignedIn,
} from '@/lib/auth-storage'
import type { Photo } from '@/types/photo'
import { MIN_PASSWORD_LENGTH, newPasswordError } from '@/lib/password-rules'
import type {
  User,
  EncryptionKey,
  LoginRequest,
  LegacyLoginRequest,
  PasswordProof,
  PreloginResponse,
  RegisterRequest,
  AuthResponse,
  MfaRequiredResponse,
} from '@/types/auth'
import { deriveKekAndAuthHash } from '@/crypto/auth-hash'
import {
  generateSalt,
  generateKeyPair,
  exportPublicKey,
  exportKEK,
  importKEK,
  wrapPrivateKey,
  rewrapDEK,
  rewrapPrivateKey,
} from '@/crypto/keychain'

export type { MfaRequiredResponse } from '@/types/auth'

export type MfaMethod = 'totp' | 'recovery' | 'passkey'

export interface MfaChallengeRequest {
  mfaToken: string
  method: MfaMethod
  code?: string
  passkeyResponse?: Record<string, unknown>
  staySignedIn?: boolean
}

export interface ChangePasswordRequest {
  currentPassword: string
  newPassword: string
  newPasswordConfirmation: string
}

/** Thrown when the typed current password can't open the user's keys. */
export class WrongPasswordError extends Error {
  constructor() {
    super('The current password is incorrect.')
    this.name = 'WrongPasswordError'
  }
}

/** Key and auth hash of a password that passed step one of an MFA sign-in. */
interface PendingLogin {
  mfaToken: string
  kek: CryptoKey
  authHash: string
}

/** Thrown when the new password breaks a rule the server can't check (it never sees passwords). */
export class NewPasswordError extends Error {
  constructor(message: string) {
    super(message)
    this.name = 'NewPasswordError'
  }
}

export function isMfaRequired(value: unknown): value is MfaRequiredResponse {
  return (
    typeof value === 'object' &&
    value !== null &&
    'mfa_required' in value &&
    (value as Record<string, unknown>).mfa_required === true &&
    'mfa_token' in value &&
    typeof (value as Record<string, unknown>).mfa_token === 'string' &&
    'mfa_methods' in value &&
    Array.isArray((value as Record<string, unknown>).mfa_methods)
  )
}

export const useAuthStore = defineStore('auth', () => {
  // State
  const user = ref<User | null>(null)
  // Namespaces the persisted decrypted cache per user (sync so it is set before any cache use).
  watch(user, (current) => setDecryptedCacheUser(current?.id ?? null), {
    flush: 'sync',
    immediate: true,
  })
  const encryptionKeys = ref<EncryptionKey[]>([])
  const kek = ref<CryptoKey | null>(null) // Kept in memory only
  const isLoading = ref(false)
  const error = ref<string | null>(null)
  let kekRestore: Promise<void> | null = null
  // Held in memory between the password step and the MFA step, so the password isn't kept
  // around and the 600k-iteration derivation isn't repeated.
  let pendingLogin: PendingLogin | null = null

  // Getters
  const isAuthenticated = computed(() => !!user.value)
  const needsReauth = computed(() => !!user.value && !kek.value)
  const currentUser = computed(() => user.value)

  function setUser(userData: User): void {
    user.value = userData
    setSessionItem('user', JSON.stringify(userData))
  }

  function setEncryptionKeys(keys: EncryptionKey[]): void {
    encryptionKeys.value = keys
    setSessionItem('encryption_keys', JSON.stringify(keys))
  }

  async function setKEK(newKEK: CryptoKey): Promise<void> {
    kek.value = newKEK
    // ponytail: KEK in active storage, re-auth on next app load if XSS risk is unacceptable
    setSessionItem('kek', await exportKEK(newKEK))
  }

  /**
   * Registers a new user account with encryption setup.
   */
  async function register(
    email: string,
    password: string,
    displayName: string,
    inviteToken?: string,
    staySignedIn: boolean = true,
  ): Promise<{ child_id: number; child_name: string; child_slug: string } | null> {
    isLoading.value = true
    error.value = null

    try {
      // The server doesn't check password strength: it never sees the password.
      if (password.length < MIN_PASSWORD_LENGTH) {
        throw new Error(`Password must be at least ${MIN_PASSWORD_LENGTH} characters long`)
      }

      // Generate cryptographic materials
      const salt = generateSalt()
      const keyPair = await generateKeyPair()
      const { kek: derivedKEK, authHash } = await deriveKekAndAuthHash(password, salt)

      const registerData: RegisterRequest = {
        email,
        auth_hash: authHash,
        display_name: displayName,
        master_key_salt: salt,
        public_key: await exportPublicKey(keyPair.publicKey),
        encrypted_private_key: await wrapPrivateKey(keyPair.privateKey, derivedKEK),
        remember: staySignedIn,
      }
      if (inviteToken) {
        registerData.invite_token = inviteToken
      }

      const response = await api.post<AuthResponse>('/auth/register', registerData)
      const { user: userData, token, accepted_child } = response.data

      setStaySignedIn(staySignedIn)
      apiService.setAuthToken(token)
      setUser(userData)
      setEncryptionKeys([])
      await setKEK(derivedKEK)

      removePendingInviteToken()

      return accepted_child ?? null
    } catch (err) {
      console.error('Registration error:', err)
      error.value = apiErrorMessage(err, 'Registration failed. Please try again.')
      throw err
    } finally {
      isLoading.value = false
    }
  }

  /**
   * Logs in an existing user and retrieves their encryption keys.
   *
   * The password is turned into a key in the browser and only the auth hash derived from
   * that key is sent. Accounts from before the auth hash (`legacy`) send the password one
   * last time so the server can switch them over.
   */
  async function login(
    email: string,
    password: string,
    staySignedIn: boolean = true,
  ): Promise<void | MfaRequiredResponse> {
    isLoading.value = true
    error.value = null
    pendingLogin = null

    try {
      const { data: prelogin } = await api.post<PreloginResponse>('/auth/prelogin', { email })
      const { master_key_salt: salt, auth_scheme: scheme } = prelogin.data
      const { kek: derivedKEK, authHash } = await deriveKekAndAuthHash(password, salt)

      const loginData: LoginRequest | LegacyLoginRequest =
        scheme === 'legacy'
          ? { email, password, new_auth_hash: authHash, remember: staySignedIn }
          : { email, auth_hash: authHash, remember: staySignedIn }
      const response = await api.post<AuthResponse | MfaRequiredResponse>('/auth/login', loginData)
      const data = response.data

      if (isMfaRequired(data)) {
        pendingLogin = { mfaToken: data.mfa_token, kek: derivedKEK, authHash }
        return {
          mfa_required: true,
          mfa_token: data.mfa_token,
          mfa_methods: data.mfa_methods,
        }
      }

      setStaySignedIn(staySignedIn)
      await storeAuthSession(data, derivedKEK, authHash)
    } catch (err) {
      console.error('Login error:', err)
      error.value = apiErrorMessage(err, 'Login failed. Please check your credentials.')
      throw err
    } finally {
      isLoading.value = false
    }
  }

  async function storeAuthSession(
    data: AuthResponse,
    derivedKEK: CryptoKey,
    authHash: string,
  ): Promise<void> {
    const { user: userData, token, encryption_keys } = data

    apiService.setAuthToken(token)
    setUser(userData)
    await setKEK(derivedKEK)

    if (encryption_keys) {
      setEncryptionKeys(encryption_keys)
    }

    if (!userData.encrypted_private_key) {
      await createMissingKeyPair(derivedKEK, authHash)
    }
  }

  /**
   * Accounts created before private keys were stored threw theirs away, so
   * nobody could share an album with them. Give them a fresh key pair; the
   * password is only available at sign-in, which is why this runs here. By
   * now the server has switched the account to auth hashes.
   */
  async function createMissingKeyPair(derivedKEK: CryptoKey, authHash: string): Promise<void> {
    try {
      const keyPair = await generateKeyPair()
      const proof: PasswordProof = { current_auth_hash: authHash }
      const response = await api.put<{ user: User }>('/user/keypair', {
        ...proof,
        public_key: await exportPublicKey(keyPair.publicKey),
        encrypted_private_key: await wrapPrivateKey(keyPair.privateKey, derivedKEK),
      })
      setUser(response.data.user)
    } catch (err) {
      // Best effort: sign-in still works, and this is retried at the next sign-in.
      console.error('Failed to create key pair:', err)
    }
  }

  /**
   * Drops the key held for a pending MFA step (e.g. when the user goes back to the password step).
   */
  function discardPendingLogin(): void {
    pendingLogin = null
  }

  /**
   * Completes an MFA challenge and stores the full session on success.
   */
  async function mfaChallenge({
    mfaToken,
    method,
    code,
    passkeyResponse,
    staySignedIn = true,
  }: MfaChallengeRequest): Promise<void> {
    isLoading.value = true
    error.value = null

    try {
      // Checked before the request: a successful challenge consumes the single-use mfa_token.
      const pending = pendingLogin
      if (!pending || pending.mfaToken !== mfaToken) {
        throw new Error('Your sign-in step timed out. Please sign in again.')
      }

      const payload: Record<string, unknown> = { mfa_token: mfaToken, method }
      if (code !== undefined) {
        payload.code = code
      }
      if (passkeyResponse !== undefined) {
        payload.passkey_response = passkeyResponse
      }

      const response = await api.post<AuthResponse>('/auth/mfa/challenge', payload)

      setStaySignedIn(staySignedIn)
      pendingLogin = null
      await storeAuthSession(response.data, pending.kek, pending.authHash)
    } catch (err) {
      console.error('MFA challenge error:', err)
      error.value = apiErrorMessage(err, 'MFA verification failed. Please try again.')
      throw err
    } finally {
      isLoading.value = false
    }
  }

  async function postLoginWarmup(): Promise<void> {
    try {
      const { warmCache } = await import('@/lib/cache/decrypted-cache')
      const { getPEKForPhoto, fetchAndDecrypt } = await import('@/services/storage')
      const response = await api.get('/photos?page=1')
      const data = response.data.data
      if (!Array.isArray(data)) return
      await warmCache(
        data.map((photo: Photo) => ({
          id: photo.id,
          fetchAndDecrypt: async () => {
            const pek = await getPEKForPhoto(photo.children)
            if (!pek) throw new Error('No PEK')
            let url = photo.thumbnail_url
            if (!url) {
              const thumbnailResponse = await api.get(`/photos/${photo.id}/thumbnail`)
              url = thumbnailResponse.data.data.presigned_url as string
            }
            const decrypted = await fetchAndDecrypt(url, pek, photo.iv_thumbnail)
            return new Blob([decrypted], { type: 'image/webp' })
          },
        })),
      )
    } catch { /* best effort */ }
  }

  /**
   * Logs out the current user and clears all sensitive data from storage.
   *
   * Other stores still hold decrypted keys and data in memory, so callers
   * must follow this with a full page load: use signOut() from '@/lib/sign-out'.
   */
  async function logout(): Promise<void> {
    isLoading.value = true
    error.value = null

    try {
      // Call logout API
      await api.post('/auth/logout')
    } catch (err) {
      console.error('Logout error:', err)
      // Continue with local cleanup even if API call fails
    } finally {
      // Clear all auth data
      user.value = null
      encryptionKeys.value = []
      kek.value = null
      apiService.clearAuthToken()
      clearSession()
      clearDecryptedCache()
      clearShareInbox()
      isLoading.value = false
    }
  }

  /**
   * Initializes auth state from storage (synchronous).
   * Must be called before router initialization to ensure guards have correct state.
   */
  function initializeAuth(): void {
    const token = getSessionItem('auth_token')
    if (!token) {
      clearDecryptedCache()
      clearShareInbox()
      clearSession()
      return
    }

    const storedUser = getSessionItem('user')
    if (storedUser) {
      try {
        user.value = JSON.parse(storedUser)
      } catch (err) {
        console.error('Failed to parse stored user data:', err)
        removeSessionItem('user')
      }
    }

    const storedEncryptionKeys = getSessionItem('encryption_keys')
    if (storedEncryptionKeys) {
      try {
        encryptionKeys.value = JSON.parse(storedEncryptionKeys)
      } catch (err) {
        console.error('Failed to parse stored encryption keys:', err)
        removeSessionItem('encryption_keys')
      }
    }
  }

  /**
   * Restores KEK from active storage (asynchronous).
   *
   * Memoized so the router guard can await the same restore App.vue starts;
   * otherwise the first navigation after a reload sees no KEK and bounces
   * the user to the sign-in page.
   */
  function restoreKEK(): Promise<void> {
    kekRestore ??= (async () => {
      const storedKekJwk = getSessionItem('kek')
      if (!storedKekJwk || kek.value) return
      try {
        kek.value = await importKEK(storedKekJwk)
      } catch (err) {
        console.error('Failed to import KEK from storage:', err)
        removeSessionItem('kek')
      }
    })()
    return kekRestore
  }

  /**
   * Updates the authenticated user's display name and/or email.
   */
  async function updateProfile(data: { display_name?: string; email?: string }): Promise<void> {
    isLoading.value = true
    error.value = null

    try {
      const response = await api.put<{ user: User }>('/user/profile', data)
      setUser(response.data.user)
    } catch (err) {
      error.value = apiErrorMessage(err, 'Failed to update profile.')
      throw err
    } finally {
      isLoading.value = false
    }
  }

  /**
   * Runs a request that needs the account password (security settings, ownership transfer).
   *
   * The typed password is turned into an auth hash here and only the hash is sent. A legacy
   * account sends the password once, with the hash that replaces it; after that succeeds
   * the account is v2 for the rest of the session.
   */
  async function withPasswordProof<T>(
    password: string,
    send: (proof: PasswordProof) => Promise<T>,
  ): Promise<T> {
    const current = user.value
    if (!current) {
      throw new Error('You need to be signed in to confirm your password.')
    }

    const { authHash } = await deriveKekAndAuthHash(password, current.master_key_salt)

    if (current.auth_scheme !== 'v2') {
      const result = await send({ current_password: password, new_auth_hash: authHash })
      setUser({ ...current, auth_scheme: 'v2' })
      return result
    }

    return send({ current_auth_hash: authHash })
  }

  /**
   * Changes the password without losing access to any photos.
   *
   * The KEK comes from the password, so every album key and the private key
   * are re-wrapped with the new KEK here and saved with the new password in
   * one request. The server rejects the change if any key is missing.
   */
  async function changePassword({
    currentPassword,
    newPassword,
    newPasswordConfirmation,
  }: ChangePasswordRequest): Promise<void> {
    if (!user.value) {
      throw new Error('You need to be signed in to change your password.')
    }

    isLoading.value = true
    error.value = null

    try {
      // The server never sees either password, so the rules are checked here.
      const ruleError = newPasswordError(newPassword, newPasswordConfirmation, currentPassword)
      if (ruleError) {
        throw new NewPasswordError(ruleError)
      }

      const salt = user.value.master_key_salt
      const { kek: oldKEK, authHash: oldAuthHash } = await deriveKekAndAuthHash(
        currentPassword,
        salt,
      )
      const { kek: newKEK, authHash: newAuthHash } = await deriveKekAndAuthHash(newPassword, salt)

      // Fresh copy, so a key added on another device isn't left behind.
      const { data: keyList } = await api.get<{ data: EncryptionKey[] }>('/encryption-keys')
      const ownKeys = keyList.data.filter((key) => (key.wrap_type ?? 'kek') === 'kek')

      let rewrappedKeys: Array<{ child_id: number; key_version: number; encrypted_dek: string }>
      let rewrappedPrivateKey: string | null = null
      try {
        rewrappedKeys = await Promise.all(
          ownKeys.map(async (key) => ({
            child_id: key.child_id,
            key_version: key.key_version,
            encrypted_dek: await rewrapDEK(key.encrypted_dek, oldKEK, newKEK),
          })),
        )
        if (user.value.encrypted_private_key) {
          rewrappedPrivateKey = await rewrapPrivateKey(
            user.value.encrypted_private_key,
            oldKEK,
            newKEK,
          )
        }
      } catch {
        // AES-GCM unwrap only fails here when the typed password is wrong.
        throw new WrongPasswordError()
      }

      // A legacy account proves the old password in plaintext once; the new hash replaces it.
      const proof =
        user.value.auth_scheme === 'v2'
          ? { current_auth_hash: oldAuthHash }
          : { current_password: currentPassword }

      const response = await api.put<{ user: User; encryption_keys: EncryptionKey[] }>(
        '/user/password',
        {
          ...proof,
          new_auth_hash: newAuthHash,
          encryption_keys: rewrappedKeys,
          encrypted_private_key: rewrappedPrivateKey,
        },
      )

      setUser(response.data.user)
      setEncryptionKeys(response.data.encryption_keys)
      await setKEK(newKEK)
    } catch (err) {
      error.value =
        err instanceof WrongPasswordError || err instanceof NewPasswordError
          ? err.message
          : apiErrorMessage(err, 'Failed to change password.')
      throw err
    } finally {
      isLoading.value = false
    }
  }

  /**
   * Clears any error messages.
   */
  function clearError(): void {
    error.value = null
  }

  return {
    // State
    user,
    encryptionKeys,
    kek,
    isLoading,
    error,
    // Getters
    isAuthenticated,
    needsReauth,
    currentUser,
    // Actions
    register,
    login,
    mfaChallenge,
    discardPendingLogin,
    withPasswordProof,
    logout,
    initializeAuth,
    restoreKEK,
    updateProfile,
    changePassword,
    setEncryptionKeys,
    clearError,
    postLoginWarmup,
  }
})
