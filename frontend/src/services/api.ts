import axios, { type AxiosInstance, type InternalAxiosRequestConfig } from 'axios'

/**
 * API client configured for the Avapeek backend.
 */
class ApiService {
  private client: AxiosInstance

  constructor() {
    this.client = axios.create({
      baseURL: import.meta.env.VITE_API_URL || 'http://localhost:8000/api',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      withCredentials: true, // Important for Sanctum cookie-based auth
    })

    // Add auth token to requests if available
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

    // Handle 401 responses (unauthorized)
    this.client.interceptors.response.use(
      (response) => response,
      async (error) => {
        if (error.response?.status === 401) {
          // Clear auth data and redirect to login
          localStorage.removeItem('auth_token')
          localStorage.removeItem('user')
          window.location.href = '/login'
        }
        return Promise.reject(error)
      },
    )
  }

  /**
   * Sets the authentication token for API requests.
   */
  setAuthToken(token: string) {
    localStorage.setItem('auth_token', token)
  }

  /**
   * Clears the authentication token.
   */
  clearAuthToken() {
    localStorage.removeItem('auth_token')
  }

  /**
   * Gets the Axios instance for custom requests.
   */
  getInstance(): AxiosInstance {
    return this.client
  }
}

// Export singleton instance
export const api = new ApiService().getInstance()
export default new ApiService()
