import axios, { type AxiosInstance, type InternalAxiosRequestConfig } from 'axios'
import { toast } from 'vue-sonner'
import { getSessionItem, setSessionItem, clearSession } from '@/lib/auth-storage'
import { clearDecryptedCache } from '@/lib/cache/decrypted-cache'
import { clearShareInbox } from '@/lib/share-inbox'

class ApiService {
  private client: AxiosInstance

  constructor() {
    this.client = axios.create({
      baseURL: import.meta.env.VITE_API_URL || '/api',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      withCredentials: true,
      timeout: 30000,
    })

    this.client.interceptors.request.use(
      (config: InternalAxiosRequestConfig) => {
        const token = getSessionItem('auth_token')
        if (token && config.headers) {
          config.headers.Authorization = `Bearer ${token}`
        }
        return config
      },
      (error) => Promise.reject(error),
    )

    this.client.interceptors.response.use(
      (response) => response,
      async (error) => {
        if (error.code === 'ERR_NETWORK') {
          toast.error('Connection failed. Check your internet connection.')
          return Promise.reject(error)
        }

        if (error.response?.status === 401) {
          clearSession()
          clearDecryptedCache()
          clearShareInbox()
          const publicPaths = ['/login', '/register', '/invite/']
          const isPublicPage = publicPaths.some(p => window.location.pathname.startsWith(p))
          if (!isPublicPage) {
            const redirect = encodeURIComponent(window.location.pathname + window.location.search)
            window.location.href = `/login?reauth=1&redirect=${redirect}`
          }
          return Promise.reject(error)
        }

        if (error.response?.status === 403) {
          toast.error("You don't have permission to perform this action.")
          return Promise.reject(error)
        }

        if (error.response?.status === 429) {
          toast.error('Too many requests. Please wait and try again.')
          return Promise.reject(error)
        }

        if (error.response?.status === 413) {
          toast.error(error.response.data?.message || 'File too large.')
          return Promise.reject(error)
        }

        if (error.response?.status && error.response.status >= 500) {
          toast.error('Something went wrong. Please try again.')
          return Promise.reject(error)
        }

        return Promise.reject(error)
      },
    )
  }

  setAuthToken(token: string) {
    setSessionItem('auth_token', token)
  }

  clearAuthToken() {
    clearSession()
  }

  getInstance(): AxiosInstance {
    return this.client
  }
}

export const api = new ApiService().getInstance()
export default new ApiService()
