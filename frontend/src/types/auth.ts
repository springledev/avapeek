/**
 * User data returned from the API.
 */
export interface User {
  id: number
  email: string
  display_name: string
  master_key_salt: string
  public_key: string
}

/**
 * Wrapped encryption key for a child.
 */
export interface EncryptionKey {
  child_id: number
  child_name: string | null
  encrypted_dek: string
  key_version: number
}

/**
 * Registration request payload.
 */
export interface RegisterRequest {
  email: string
  password: string
  display_name: string
  master_key_salt: string
  public_key: string
}

/**
 * Login request payload.
 */
export interface LoginRequest {
  email: string
  password: string
}

/**
 * Authentication response from register/login.
 */
export interface AuthResponse {
  user: User
  token: string
  encryption_keys?: EncryptionKey[]
}

/**
 * Login response when MFA is required.
 */
export interface MfaLoginResponse {
  mfa_required: true
  mfa_token: string
  mfa_methods: string[]
}

/**
 * Available MFA methods for the authenticated user.
 */
export interface MfaMethodsResponse {
  methods: string[]
  passkeys: Array<{ id: string; name: string; last_used_at: string | null }>
}

/**
 * API error response.
 */
export interface ApiError {
  message: string
  errors?: Record<string, string[]>
}
