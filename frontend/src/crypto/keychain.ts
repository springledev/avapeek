/**
 * Key Encryption Key (KEK) and Data Encryption Key (DEK) management
 * using Web Crypto API for zero-knowledge encryption.
 */

/**
 * Generates a cryptographically secure random salt for key derivation.
 */
export function generateSalt(): string {
  const buffer = new Uint8Array(32)
  crypto.getRandomValues(buffer)
  return arrayBufferToHex(buffer.buffer as ArrayBuffer)
}

/**
 * Derives a KEK (Key Encryption Key) from a password using PBKDF2.
 * Note: For production, consider upgrading to Argon2id via WASM.
 *
 * @param password - User's password
 * @param salt - Hex-encoded salt for key derivation
 * @returns Promise resolving to the derived KEK
 */
export async function deriveKEK(password: string, salt: string): Promise<CryptoKey> {
  const encoder = new TextEncoder()
  const passwordBuffer = encoder.encode(password)
  const saltBuffer = hexToArrayBuffer(salt)

  // Import password as key material
  const keyMaterial = await crypto.subtle.importKey('raw', passwordBuffer, 'PBKDF2', false, [
    'deriveBits',
    'deriveKey',
  ])

  // Derive KEK using PBKDF2 (600,000 iterations as per OWASP recommendations)
  const kek = await crypto.subtle.deriveKey(
    {
      name: 'PBKDF2',
      salt: saltBuffer,
      iterations: 600000,
      hash: 'SHA-256',
    },
    keyMaterial,
    { name: 'AES-GCM', length: 256 },
    true, // Extractable so it can be persisted to sessionStorage
    ['wrapKey', 'unwrapKey'],
  )

  return kek
}

/**
 * Exports a KEK to a JWK string for persistence in sessionStorage.
 */
export async function exportKEK(kek: CryptoKey): Promise<string> {
  const jwk = await crypto.subtle.exportKey('jwk', kek)
  return JSON.stringify(jwk)
}

/**
 * Imports a KEK from a JWK string (previously exported via exportKEK).
 */
export async function importKEK(jwkString: string): Promise<CryptoKey> {
  const jwk = JSON.parse(jwkString)
  return await crypto.subtle.importKey('jwk', jwk, { name: 'AES-GCM', length: 256 }, true, [
    'wrapKey',
    'unwrapKey',
  ])
}

/**
 * Generates a new random DEK (Data Encryption Key) for encrypting files.
 */
export async function generateDEK(): Promise<CryptoKey> {
  return await crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, true, [
    'encrypt',
    'decrypt',
  ])
}

/** Byte length of the random AES-GCM IV prefixed to every KEK-wrapped DEK. */
const KEK_WRAP_IV_LENGTH = 12

/** Byte length of a KEK-wrapped DEK: 12-byte IV + 32-byte raw AES-256 key + 16-byte GCM tag. */
const KEK_WRAPPED_DEK_LENGTH = KEK_WRAP_IV_LENGTH + 32 + 16

/**
 * Wraps (encrypts) a DEK using a KEK for secure storage.
 * Output is base64(iv || ciphertext) with a fresh random IV per wrap.
 *
 * @param dek - The Data Encryption Key to wrap
 * @param kek - The Key Encryption Key used for wrapping
 * @returns Promise resolving to base64-encoded wrapped DEK
 */
export async function wrapDEK(dek: CryptoKey, kek: CryptoKey): Promise<string> {
  const iv = crypto.getRandomValues(new Uint8Array(KEK_WRAP_IV_LENGTH))
  const wrappedKey = await crypto.subtle.wrapKey('raw', dek, kek, { name: 'AES-GCM', iv })

  const combined = new Uint8Array(iv.length + wrappedKey.byteLength)
  combined.set(iv)
  combined.set(new Uint8Array(wrappedKey), iv.length)

  return arrayBufferToBase64(combined.buffer as ArrayBuffer)
}

/**
 * Unwraps a DEK produced by wrapDEK: splits off the leading IV and decrypts
 * the remainder with the KEK. Rejects any input that is not exactly
 * iv || ciphertext before handing it to Web Crypto.
 */
async function unwrapKekWrappedDEK(
  wrappedDEK: string,
  kek: CryptoKey,
  extractable: boolean,
): Promise<CryptoKey> {
  const combined = new Uint8Array(base64ToArrayBuffer(wrappedDEK))

  if (combined.length !== KEK_WRAPPED_DEK_LENGTH) {
    throw new Error(
      `Invalid wrapped DEK: expected ${KEK_WRAPPED_DEK_LENGTH} bytes (iv || ciphertext), got ${combined.length}`,
    )
  }

  return await crypto.subtle.unwrapKey(
    'raw',
    combined.slice(KEK_WRAP_IV_LENGTH),
    kek,
    { name: 'AES-GCM', iv: combined.slice(0, KEK_WRAP_IV_LENGTH) },
    { name: 'AES-GCM', length: 256 },
    extractable,
    ['encrypt', 'decrypt'],
  )
}

/**
 * Unwraps (decrypts) a DEK using a KEK.
 *
 * @param wrappedDEK - Base64-encoded wrapped DEK, base64(iv || ciphertext) as produced by wrapDEK
 * @param kek - The Key Encryption Key used for unwrapping
 * @returns Promise resolving to the unwrapped DEK
 */
export async function unwrapDEK(wrappedDEK: string, kek: CryptoKey): Promise<CryptoKey> {
  return await unwrapKekWrappedDEK(wrappedDEK, kek, false)
}

/**
 * Generates an RSA key pair for DEK sharing during child invites.
 */
export async function generateKeyPair(): Promise<CryptoKeyPair> {
  return await crypto.subtle.generateKey(
    {
      name: 'RSA-OAEP',
      modulusLength: 2048,
      publicExponent: new Uint8Array([1, 0, 1]),
      hash: 'SHA-256',
    },
    true,
    ['wrapKey', 'unwrapKey'],
  )
}

/**
 * Exports a public key to JWK format for storage.
 */
export async function exportPublicKey(publicKey: CryptoKey): Promise<string> {
  const jwk = await crypto.subtle.exportKey('jwk', publicKey)
  return JSON.stringify(jwk)
}

/**
 * Imports a public key from JWK format (RSA-OAEP).
 */
export async function importPublicKey(publicKeyJwk: string): Promise<CryptoKey> {
  const jwk = JSON.parse(publicKeyJwk)
  return await crypto.subtle.importKey('jwk', jwk, { name: 'RSA-OAEP', hash: 'SHA-256' }, true, [
    'wrapKey',
  ])
}

/**
 * Wraps a DEK using an invitee's RSA public key.
 * Used during the invite flow to share the DEK with a new member.
 */
export async function wrapDEKWithPublicKey(dek: CryptoKey, publicKey: CryptoKey): Promise<string> {
  const wrappedKey = await crypto.subtle.wrapKey('raw', dek, publicKey, { name: 'RSA-OAEP' })
  return arrayBufferToBase64(wrappedKey)
}

export async function generatePEK(): Promise<CryptoKey> {
  return await crypto.subtle.generateKey(
    { name: 'AES-GCM', length: 256 },
    true,
    ['encrypt', 'decrypt'],
  )
}

export async function wrapPEKWithDEK(
  pek: CryptoKey,
  dek: CryptoKey,
): Promise<{ encrypted_pek: string; iv_pek: string }> {
  const pekBytes = await crypto.subtle.exportKey('raw', pek)
  const iv = crypto.getRandomValues(new Uint8Array(12))
  const encrypted = await crypto.subtle.encrypt(
    { name: 'AES-GCM', iv },
    dek,
    pekBytes,
  )
  return {
    encrypted_pek: arrayBufferToBase64(encrypted),
    iv_pek: arrayBufferToBase64(iv.buffer as ArrayBuffer),
  }
}

export async function unwrapPEK(
  encryptedPek: string,
  ivPek: string,
  dek: CryptoKey,
): Promise<CryptoKey> {
  const pekBytes = await crypto.subtle.decrypt(
    { name: 'AES-GCM', iv: base64ToArrayBuffer(ivPek) },
    dek,
    base64ToArrayBuffer(encryptedPek),
  )
  return await crypto.subtle.importKey(
    'raw',
    pekBytes,
    { name: 'AES-GCM', length: 256 },
    false,
    ['encrypt', 'decrypt'],
  )
}

/**
 * Re-wraps a KEK-wrapped DEK under a new KEK. Used when the password (and so
 * the KEK) changes; the DEK itself never changes, so photos stay readable.
 * Input and output are base64(iv || ciphertext); the output gets a fresh random IV.
 */
export async function rewrapDEK(
  wrappedDEK: string,
  oldKek: CryptoKey,
  newKek: CryptoKey,
): Promise<string> {
  const dek = await unwrapKekWrappedDEK(wrappedDEK, oldKek, true)

  return await wrapDEK(dek, newKek)
}

/**
 * Converts a DEK that another member wrapped with this user's RSA public key
 * into the user's own KEK-wrapped form, so it opens like any other DEK.
 */
export async function rewrapSharedDEK(
  sharedDEK: string,
  privateKey: CryptoKey,
  kek: CryptoKey,
): Promise<string> {
  const dek = await crypto.subtle.unwrapKey(
    'raw',
    base64ToArrayBuffer(sharedDEK),
    privateKey,
    { name: 'RSA-OAEP' },
    { name: 'AES-GCM', length: 256 },
    true,
    ['encrypt', 'decrypt'],
  )

  return await wrapDEK(dek, kek)
}

/**
 * Shares a KEK-wrapped DEK with another member by re-wrapping it with their
 * RSA public key. The DEK is only ever handled inside Web Crypto.
 * The input is a KEK-wrapped DEK in wrapDEK's base64(iv || ciphertext) format.
 */
export async function rewrapDEKForPublicKey(
  wrappedDEK: string,
  kek: CryptoKey,
  publicKey: CryptoKey,
): Promise<string> {
  const dek = await unwrapKekWrappedDEK(wrappedDEK, kek, true)

  return await wrapDEKWithPublicKey(dek, publicKey)
}

/**
 * Wraps the user's RSA private key with their KEK for server-side storage.
 * Output is base64(iv || ciphertext) with a fresh random IV per wrap.
 */
export async function wrapPrivateKey(privateKey: CryptoKey, kek: CryptoKey): Promise<string> {
  const iv = crypto.getRandomValues(new Uint8Array(12))
  const wrapped = await crypto.subtle.wrapKey('pkcs8', privateKey, kek, { name: 'AES-GCM', iv })

  const combined = new Uint8Array(iv.length + wrapped.byteLength)
  combined.set(iv)
  combined.set(new Uint8Array(wrapped), iv.length)

  return arrayBufferToBase64(combined.buffer as ArrayBuffer)
}

/**
 * Unwraps a private key produced by wrapPrivateKey.
 */
export async function unwrapPrivateKey(wrappedPrivateKey: string, kek: CryptoKey): Promise<CryptoKey> {
  const combined = new Uint8Array(base64ToArrayBuffer(wrappedPrivateKey))

  return await crypto.subtle.unwrapKey(
    'pkcs8',
    combined.slice(12),
    kek,
    { name: 'AES-GCM', iv: combined.slice(0, 12) },
    { name: 'RSA-OAEP', hash: 'SHA-256' },
    true,
    ['unwrapKey'],
  )
}

/**
 * Re-wraps a KEK-wrapped private key under a new KEK (password change).
 */
export async function rewrapPrivateKey(
  wrappedPrivateKey: string,
  oldKek: CryptoKey,
  newKek: CryptoKey,
): Promise<string> {
  return await wrapPrivateKey(await unwrapPrivateKey(wrappedPrivateKey, oldKek), newKek)
}

// Utility functions for encoding/decoding

function arrayBufferToHex(buffer: ArrayBuffer): string {
  return Array.from(new Uint8Array(buffer))
    .map((b) => b.toString(16).padStart(2, '0'))
    .join('')
}

function hexToArrayBuffer(hex: string): ArrayBuffer {
  const bytes = new Uint8Array(hex.length / 2)
  for (let i = 0; i < hex.length; i += 2) {
    bytes[i / 2] = parseInt(hex.substring(i, i + 2), 16)
  }
  return bytes.buffer
}

function arrayBufferToBase64(buffer: ArrayBuffer): string {
  const bytes = new Uint8Array(buffer)
  let binary = ''
  for (let i = 0; i < bytes.length; i++) {
    binary += String.fromCharCode(bytes[i]!)
  }
  return btoa(binary)
}

function base64ToArrayBuffer(base64: string): ArrayBuffer {
  const binary = atob(base64)
  const bytes = new Uint8Array(binary.length)
  for (let i = 0; i < binary.length; i++) {
    bytes[i] = binary.charCodeAt(i)
  }
  return bytes.buffer
}
