/**
 * How the server checks the password. 'v2': it only gets an auth hash derived from the KEK.
 * 'legacy': it still holds a hash of the plaintext password until the next sign-in or confirmation.
 */
export type AuthScheme = 'v2' | 'legacy'

/**
 * User data returned from the API.
 */
export interface User {
  id: number
  email: string
  display_name: string
  master_key_salt: string
  /** Missing in sessions saved before the field existed; those accounts are legacy. */
  auth_scheme?: AuthScheme
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
  /** Derived from the KEK; the password itself is never sent. */
  auth_hash: string
  display_name: string
  master_key_salt: string
  public_key: string
  encrypted_private_key: string
  invite_token?: string
  remember: boolean
}

/**
 * Pre-login answer: what the client needs before it can derive its auth hash.
 */
export interface PreloginResponse {
  data: { master_key_salt: string; auth_scheme: AuthScheme }
}

/**
 * Login request payload for v2 accounts.
 */
export interface LoginRequest {
  email: string
  auth_hash: string
  remember: boolean
}

/**
 * Login request payload for a legacy account: the plaintext password is sent one last
 * time, together with the auth hash that replaces it on the server.
 */
export interface LegacyLoginRequest {
  email: string
  password: string
  new_auth_hash: string
  remember: boolean
}

/**
 * Proof of the current password for sensitive requests.
 * v2 accounts send the auth hash; legacy accounts send the password once plus the hash to switch to.
 */
export type PasswordProof =
  | { current_auth_hash: string }
  | { current_password: string; new_auth_hash: string }

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
