import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { api } from '@/services/api'
import apiService from '@/services/api'
import { apiErrorMessage } from '@/lib/util'
import { clearDecryptedCache } from '@/lib/cache/decrypted-cache'
import {
  getSessionItem,
  setSessionItem,
  removeSessionItem,
  clearSession,
  removePendingInviteToken,
  setStaySignedIn,
} from '@/lib/auth-storage'
import type { Photo } from '@/types/photo'
import type {
  User,
  EncryptionKey,
  LoginRequest,
  AuthResponse,
} from '@/types/auth'
import {
  deriveKEK,
  generateSalt,
  generateKeyPair,
  exportPublicKey,
  exportKEK,
  importKEK,
} from '@/crypto/keychain'

export interface MfaRequiredResponse {
  mfa_required: true
  mfa_token: string
  mfa_methods: string[]
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
  const encryptionKeys = ref<EncryptionKey[]>([])
  const kek = ref<CryptoKey | null>(null) // Kept in memory only
  const isLoading = ref(false)
  const error = ref<string | null>(null)

  // Getters
  const isAuthenticated = computed(() => !!user.value)
  const needsReauth = computed(() => !!user.value && !kek.value)
  const currentUser = computed(() => user.value)

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
      // Generate cryptographic materials
      const salt = generateSalt()
      const keyPair = await generateKeyPair()
      const publicKeyJwk = await exportPublicKey(keyPair.publicKey)

      // Derive KEK from password (keep in memory)
      const derivedKEK = await deriveKEK(password, salt)
      kek.value = derivedKEK

      // Prepare registration data
      const registerData: Record<string, unknown> = {
        email,
        password,
        display_name: displayName,
        master_key_salt: salt,
        public_key: publicKeyJwk,
      }
      if (inviteToken) {
        registerData.invite_token = inviteToken
      }

      // Call API
      const response = await api.post<AuthResponse>('/auth/register', registerData)
      const { user: userData, token, accepted_child } = response.data

      setStaySignedIn(staySignedIn)

      // Store user and token
      user.value = userData
      apiService.setAuthToken(token)

      // ponytail: KEK in active storage, re-auth on next app load if XSS risk is unacceptable
      const kekJwk = await exportKEK(derivedKEK)
      setSessionItem('kek', kekJwk)

      setSessionItem('user', JSON.stringify(userData))

      if (accepted_child) {
        encryptionKeys.value = []
        setSessionItem('encryption_keys', JSON.stringify([]))
      }

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
   */
  async function login(
    email: string,
    password: string,
    staySignedIn: boolean = true,
  ): Promise<void | MfaRequiredResponse> {
    isLoading.value = true
    error.value = null

    try {
      const loginData: LoginRequest = { email, password }
      const response = await api.post<AuthResponse | MfaRequiredResponse>('/auth/login', loginData)
      const data = response.data

      if (isMfaRequired(data)) {
        return {
          mfa_required: true,
          mfa_token: data.mfa_token,
          mfa_methods: data.mfa_methods,
        }
      }

      setStaySignedIn(staySignedIn)
      await storeAuthSession(data, password)
    } catch (err) {
      console.error('Login error:', err)
      error.value = apiErrorMessage(err, 'Login failed. Please check your credentials.')
      throw err
    } finally {
      isLoading.value = false
    }
  }

  async function storeAuthSession(data: AuthResponse, password: string): Promise<void> {
    const { user: userData, token, encryption_keys } = data

    user.value = userData
    apiService.setAuthToken(token)

    const derivedKEK = await deriveKEK(password, userData.master_key_salt)
    kek.value = derivedKEK

    if (encryption_keys) {
      encryptionKeys.value = encryption_keys
      setSessionItem('encryption_keys', JSON.stringify(encryption_keys))
    }

    // ponytail: KEK in active storage, re-auth on next app load if XSS risk is unacceptable
    const kekJwk = await exportKEK(derivedKEK)
    setSessionItem('kek', kekJwk)

    setSessionItem('user', JSON.stringify(userData))
  }

  /**
   * Completes an MFA challenge and stores the full session on success.
   */
  async function mfaChallenge(
    mfaToken: string,
    method: string,
    code?: string,
    passkeyResponse?: Record<string, unknown>,
    password?: string,
  ): Promise<void> {
    isLoading.value = true
    error.value = null

    try {
      const payload: Record<string, unknown> = { mfa_token: mfaToken, method }
      if (code !== undefined) {
        payload.code = code
      }
      if (passkeyResponse !== undefined) {
        payload.passkey_response = passkeyResponse
      }

      const response = await api.post<AuthResponse>('/auth/mfa/challenge', payload)
      const data = response.data

      if (!password) {
        throw new Error('Password is required to restore encryption keys.')
      }

      await storeAuthSession(data, password)
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
   * Logs out the current user and clears all sensitive data.
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
   * Called after app mount to avoid blocking initial render.
   */
  async function restoreKEK(): Promise<void> {
    const storedKekJwk = getSessionItem('kek')
    if (storedKekJwk) {
      try {
        kek.value = await importKEK(storedKekJwk)
      } catch (err) {
        console.error('Failed to import KEK from storage:', err)
        removeSessionItem('kek')
      }
    }
  }

  /**
   * Updates the authenticated user's profile.
   *
   * Supports changing display_name, email, and/or password.
   * When password is changed, the KEK is re-derived with the new password
   * so the user stays logged in without re-authentication.
   */
  async function updateProfile(data: {
    display_name?: string
    email?: string
    current_password?: string
    new_password?: string
    new_password_confirmation?: string
  }): Promise<void> {
    isLoading.value = true
    error.value = null

    try {
      const response = await api.put('/user/profile', data)
      const userData = response.data.user

      user.value = userData
      setSessionItem('user', JSON.stringify(userData))

      if (data.new_password && userData.master_key_salt) {
        const newKEK = await deriveKEK(data.new_password, userData.master_key_salt)
        kek.value = newKEK
        const kekJwk = await exportKEK(newKEK)
        setSessionItem('kek', kekJwk)
      }
    } catch (err) {
      error.value = apiErrorMessage(err, 'Failed to update profile.')
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
    logout,
    initializeAuth,
    restoreKEK,
    updateProfile,
    clearError,
    postLoginWarmup,
  }
})
