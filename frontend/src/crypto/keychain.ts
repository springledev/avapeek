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
  return arrayBufferToHex(buffer)
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
  const keyMaterial = await crypto.subtle.importKey(
    'raw',
    passwordBuffer,
    'PBKDF2',
    false,
    ['deriveBits', 'deriveKey'],
  )

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
  return await crypto.subtle.importKey(
    'jwk',
    jwk,
    { name: 'AES-GCM', length: 256 },
    true,
    ['wrapKey', 'unwrapKey'],
  )
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

/**
 * Wraps (encrypts) a DEK using a KEK for secure storage.
 *
 * @param dek - The Data Encryption Key to wrap
 * @param kek - The Key Encryption Key used for wrapping
 * @returns Promise resolving to base64-encoded wrapped DEK
 */
export async function wrapDEK(dek: CryptoKey, kek: CryptoKey): Promise<string> {
  const wrappedKey = await crypto.subtle.wrapKey('raw', dek, kek, { name: 'AES-GCM', iv: new Uint8Array(12) })

  return arrayBufferToBase64(wrappedKey)
}

/**
 * Unwraps (decrypts) a DEK using a KEK.
 *
 * @param wrappedDEK - Base64-encoded wrapped DEK
 * @param kek - The Key Encryption Key used for unwrapping
 * @returns Promise resolving to the unwrapped DEK
 */
export async function unwrapDEK(wrappedDEK: string, kek: CryptoKey): Promise<CryptoKey> {
  const wrappedBuffer = base64ToArrayBuffer(wrappedDEK)

  return await crypto.subtle.unwrapKey(
    'raw',
    wrappedBuffer,
    kek,
    { name: 'AES-GCM', iv: new Uint8Array(12) },
    { name: 'AES-GCM', length: 256 },
    false,
    ['encrypt', 'decrypt'],
  )
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
  return await crypto.subtle.importKey(
    'jwk',
    jwk,
    { name: 'RSA-OAEP', hash: 'SHA-256' },
    true,
    ['wrapKey'],
  )
}

/**
 * Wraps a DEK using an invitee's RSA public key.
 * Used during the invite flow to share the DEK with a new member.
 */
export async function wrapDEKWithPublicKey(dek: CryptoKey, publicKey: CryptoKey): Promise<string> {
  const wrappedKey = await crypto.subtle.wrapKey('raw', dek, publicKey, { name: 'RSA-OAEP' })
  return arrayBufferToBase64(wrappedKey)
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
    binary += String.fromCharCode(bytes[i])
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
