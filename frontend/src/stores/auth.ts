import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { api } from '@/services/api'
import apiService from '@/services/api'
import type {
  User,
  EncryptionKey,
  RegisterRequest,
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
      const registerData: RegisterRequest = {
        email,
        password,
        display_name: displayName,
        master_key_salt: salt,
        public_key: publicKeyJwk,
      }

      // Call API
      const response = await api.post<AuthResponse>('/auth/register', registerData)
      const { user: userData, token } = response.data

      // Store user and token
      user.value = userData
      apiService.setAuthToken(token)

      // Export KEK to sessionStorage for persistence across refreshes
      const kekJwk = await exportKEK(derivedKEK)
      sessionStorage.setItem('kek', kekJwk)

      // Store user data in localStorage for persistence
      localStorage.setItem('user', JSON.stringify(userData))
    } catch (err: any) {
      console.error('Registration error:', err)
      error.value = err.response?.data?.message || 'Registration failed. Please try again.'
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
      // Call login API
      const loginData: LoginRequest = { email, password }
      const response = await api.post<AuthResponse>('/auth/login', loginData)
      const { user: userData, token, encryption_keys } = response.data

      // Store user and token
      user.value = userData
      apiService.setAuthToken(token)

      // Derive KEK from password (keep in memory)
      const derivedKEK = await deriveKEK(password, userData.master_key_salt)
      kek.value = derivedKEK

      // Store encryption keys
      if (encryption_keys) {
        encryptionKeys.value = encryption_keys
        localStorage.setItem('encryption_keys', JSON.stringify(encryption_keys))
      }

      // Export KEK to sessionStorage for persistence across refreshes
      const kekJwk = await exportKEK(derivedKEK)
      sessionStorage.setItem('kek', kekJwk)

      // Store user data in localStorage for persistence
      localStorage.setItem('user', JSON.stringify(userData))
    } catch (err: any) {
      console.error('Login error:', err)
      error.value = err.response?.data?.message || 'Login failed. Please check your credentials.'
      throw err
    } finally {
      isLoading.value = false
    }
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
    } catch (err: any) {
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
      sessionStorage.removeItem('kek')
      isLoading.value = false
    }
  }

  /**
   * Initializes auth state from localStorage (synchronous).
   * Must be called before router initialization to ensure guards have correct state.
   */
  function initializeAuth(): void {
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
    const storedKekJwk = sessionStorage.getItem('kek')
    if (storedKekJwk) {
      try {
        kek.value = await importKEK(storedKekJwk)
      } catch (err) {
        console.error('Failed to import KEK from sessionStorage:', err)
        sessionStorage.removeItem('kek')
      }
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
    clearError,
  }
})
