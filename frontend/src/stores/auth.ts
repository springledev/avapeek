import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { api } from '@/services/api'
import apiService from '@/services/api'
import { apiErrorMessage } from '@/lib/util'
import { clearDecryptedCache } from '@/lib/cache/decrypted-cache'
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
  ): Promise<void> {
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
      const response = await api.post<AuthResponse & { accepted_child?: { child_id: number; child_name: string; child_slug: string } }>('/auth/register', registerData)
      const { user: userData, token, accepted_child } = response.data

      // Store user and token
      user.value = userData
      apiService.setAuthToken(token)

      // ponytail: KEK in localStorage, re-auth on next app load if XSS risk is unacceptable
      const kekJwk = await exportKEK(derivedKEK)
      localStorage.setItem('kek', kekJwk)

      localStorage.setItem('user', JSON.stringify(userData))

      if (accepted_child) {
        encryptionKeys.value = []
        localStorage.setItem('encryption_keys', JSON.stringify([]))
      }

      localStorage.removeItem('pending_invite_token')
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
  async function login(email: string, password: string): Promise<void> {
    isLoading.value = true
    error.value = null

    try {
      const loginData: LoginRequest = { email, password }
      const response = await api.post<AuthResponse>('/auth/login', loginData)
      const { user: userData, token, encryption_keys } = response.data

      user.value = userData
      apiService.setAuthToken(token)

      const derivedKEK = await deriveKEK(password, userData.master_key_salt)
      kek.value = derivedKEK

      if (encryption_keys) {
        encryptionKeys.value = encryption_keys
        localStorage.setItem('encryption_keys', JSON.stringify(encryption_keys))
      }

      // ponytail: KEK in localStorage, re-auth on next app load if XSS risk is unacceptable
      const kekJwk = await exportKEK(derivedKEK)
      localStorage.setItem('kek', kekJwk)

      localStorage.setItem('user', JSON.stringify(userData))
    } catch (err) {
      console.error('Login error:', err)
      error.value = apiErrorMessage(err, 'Login failed. Please check your credentials.')
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
      localStorage.removeItem('user')
      localStorage.removeItem('encryption_keys')
      localStorage.removeItem('kek')
      clearDecryptedCache()
      isLoading.value = false
    }
  }

  /**
   * Initializes auth state from localStorage (synchronous).
   * Must be called before router initialization to ensure guards have correct state.
   */
  function initializeAuth(): void {
    const token = localStorage.getItem('auth_token')
    if (!token) {
      localStorage.removeItem('user')
      localStorage.removeItem('encryption_keys')
      return
    }

    const storedUser = localStorage.getItem('user')
    if (storedUser) {
      try {
        user.value = JSON.parse(storedUser)
      } catch (err) {
        console.error('Failed to parse stored user data:', err)
        localStorage.removeItem('user')
      }
    }

    const storedEncryptionKeys = localStorage.getItem('encryption_keys')
    if (storedEncryptionKeys) {
      try {
        encryptionKeys.value = JSON.parse(storedEncryptionKeys)
      } catch (err) {
        console.error('Failed to parse stored encryption keys:', err)
        localStorage.removeItem('encryption_keys')
      }
    }
  }

  /**
   * Restores KEK from sessionStorage (asynchronous).
   * Called after app mount to avoid blocking initial render.
   */
  async function restoreKEK(): Promise<void> {
    const storedKekJwk = localStorage.getItem('kek')
    if (storedKekJwk) {
      try {
        kek.value = await importKEK(storedKekJwk)
      } catch (err) {
        console.error('Failed to import KEK from localStorage:', err)
        localStorage.removeItem('kek')
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
      localStorage.setItem('user', JSON.stringify(userData))

      if (data.new_password && userData.master_key_salt) {
        const newKEK = await deriveKEK(data.new_password, userData.master_key_salt)
        kek.value = newKEK
        const kekJwk = await exportKEK(newKEK)
        localStorage.setItem('kek', kekJwk)
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
    logout,
    initializeAuth,
    restoreKEK,
    updateProfile,
    clearError,
    postLoginWarmup,
  }
})
