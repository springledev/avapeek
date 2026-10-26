/**
 * Auth hash: what the server sees instead of the password.
 *
 * The KEK is derived from the password in the browser (PBKDF2, 600k iterations) and
 * wraps every album key, so the password itself must never reach the server. The auth
 * hash is HMAC-SHA256 keyed with the raw KEK bytes over a fixed label: one-way, so the
 * KEK cannot be recovered from it, and guessing the password from it still costs a full
 * PBKDF2 run per guess.
 */

import { deriveKEK } from './keychain'

/** Domain separation label; bump the version if the construction ever changes. */
const AUTH_HASH_LABEL = 'avapeek-auth-v1'

function bytesToHex(bytes: Uint8Array): string {
  return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('')
}

/**
 * Derives the auth hash from a KEK: 64 lowercase hex characters.
 */
export async function deriveAuthHash(kek: CryptoKey): Promise<string> {
  const rawKek = await crypto.subtle.exportKey('raw', kek)
  const hmacKey = await crypto.subtle.importKey(
    'raw',
    rawKek,
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign'],
  )
  const signature = await crypto.subtle.sign(
    'HMAC',
    hmacKey,
    new TextEncoder().encode(AUTH_HASH_LABEL),
  )
  return bytesToHex(new Uint8Array(signature))
}

/**
 * Derives the KEK and its auth hash from a password with a single PBKDF2 run.
 */
export async function deriveKekAndAuthHash(
  password: string,
  salt: string,
): Promise<{ kek: CryptoKey; authHash: string }> {
  const kek = await deriveKEK(password, salt)
  return { kek, authHash: await deriveAuthHash(kek) }
}
