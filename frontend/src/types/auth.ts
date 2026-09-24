/**
 * User data returned from the API.
 */
export interface User {
  id: number
  email: string
  display_name: string
  master_key_salt: string
  public_key: string
  /** RSA private key wrapped with the KEK; null for accounts created before it was stored. */
  encrypted_private_key?: string | null
}

/**
 * How a DEK is wrapped: 'kek' opens with the password-derived KEK;
 * 'public_key' was shared by another member and must be re-wrapped first.
 */
export type KeyWrapType = 'kek' | 'public_key'

/**
 * Wrapped encryption key for a child.
 */
export interface EncryptionKey {
  child_id: number
  child_name: string | null
  encrypted_dek: string
  wrap_type?: KeyWrapType
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
  encrypted_private_key: string
  invite_token?: string
  remember: boolean
}

/**
 * Login request payload.
 */
export interface LoginRequest {
  email: string
  password: string
  remember: boolean
}

/**
 * Authentication response from register/login.
 */
export interface AuthResponse {
  user: User
  token: string
  encryption_keys?: EncryptionKey[]
  accepted_child?: { child_id: number; child_name: string; child_slug: string } | null
}

/**
 * Login response when MFA is required.
 */
export interface MfaRequiredResponse {
  mfa_required: true
  mfa_token: string
  mfa_methods: string[]
}

/**
 * Available MFA methods for the authenticated user.
 */
export interface MfaMethodsResponse {
  methods: string[]
  recovery_codes_remaining: number
  passkeys: Array<{ id: string; name: string; last_used_at: string | null }>
}

/**
 * API error response.
 */
export interface ApiError {
  message: string
  errors?: Record<string, string[]>
}
