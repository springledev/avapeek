import axios, { type AxiosInstance, type InternalAxiosRequestConfig } from 'axios'
import { toast } from 'vue-sonner'

class ApiService {
  private client: AxiosInstance

  constructor() {
    this.client = axios.create({
      baseURL: import.meta.env.VITE_API_URL || 'http://localhost:8000/api',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      withCredentials: true,
      timeout: 30000,
    })

    this.client.interceptors.request.use(
      (config: InternalAxiosRequestConfig) => {
        const token = localStorage.getItem('auth_token')
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
          localStorage.removeItem('auth_token')
          localStorage.removeItem('user')
          window.location.href = '/login'
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
    localStorage.setItem('auth_token', token)
  }

  clearAuthToken() {
    localStorage.removeItem('auth_token')
  }

  getInstance(): AxiosInstance {
    return this.client
  }
}

export const api = new ApiService().getInstance()
export default new ApiService()
