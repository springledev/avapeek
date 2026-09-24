// @vitest-environment node
import { describe, it, expect, vi } from 'vitest'
import {
  deriveKEK,
  generateSalt,
  generateDEK,
  generateKeyPair,
  wrapDEK,
  unwrapDEK,
  exportPublicKey,
  importPublicKey,
  wrapDEKWithPublicKey,
  rewrapDEK,
  rewrapSharedDEK,
  rewrapDEKForPublicKey,
  wrapPrivateKey,
  unwrapPrivateKey,
  rewrapPrivateKey,
} from '@/crypto/keychain'

/** Encrypts with one DEK handle and decrypts with another to prove they hold the same key. */
async function sameKey(a: CryptoKey, b: CryptoKey): Promise<boolean> {
  const iv = crypto.getRandomValues(new Uint8Array(12))
  const plaintext = new TextEncoder().encode('family photo')
  const ciphertext = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, a, plaintext)
  const decrypted = await crypto.subtle.decrypt({ name: 'AES-GCM', iv }, b, ciphertext)
  return new TextDecoder().decode(decrypted) === 'family photo'
}

// PBKDF2 at 600k iterations makes each derivation slow; give the suite room.
vi.setConfig({ testTimeout: 30_000 })

describe('keychain key re-wrapping', () => {
  it('keeps a DEK readable after a password change', async () => {
    const salt = generateSalt()
    const oldKEK = await deriveKEK('old password', salt)
    const newKEK = await deriveKEK('new password', salt)
    const dek = await generateDEK()

    const rewrapped = await rewrapDEK(await wrapDEK(dek, oldKEK), oldKEK, newKEK)

    expect(await sameKey(dek, await unwrapDEK(rewrapped, newKEK))).toBe(true)
    await expect(unwrapDEK(rewrapped, oldKEK)).rejects.toBeDefined()
  })

  it('refuses to re-wrap with the wrong old password', async () => {
    const salt = generateSalt()
    const realKEK = await deriveKEK('real', salt)
    const wrongKEK = await deriveKEK('typo', salt)
    const wrapped = await wrapDEK(await generateDEK(), realKEK)

    await expect(rewrapDEK(wrapped, wrongKEK, realKEK)).rejects.toBeDefined()
  })

  it('round-trips the private key and re-wraps it for a new password', async () => {
    const salt = generateSalt()
    const oldKEK = await deriveKEK('old password', salt)
    const newKEK = await deriveKEK('new password', salt)
    const keyPair = await generateKeyPair()

    const wrapped = await wrapPrivateKey(keyPair.privateKey, oldKEK)
    const rewrapped = await rewrapPrivateKey(wrapped, oldKEK, newKEK)
    const privateKey = await unwrapPrivateKey(rewrapped, newKEK)

    const dek = await generateDEK()
    const shared = await wrapDEKWithPublicKey(dek, keyPair.publicKey)
    const opened = await crypto.subtle.unwrapKey(
      'raw',
      Uint8Array.from(atob(shared), (c) => c.charCodeAt(0)),
      privateKey,
      { name: 'RSA-OAEP' },
      { name: 'AES-GCM', length: 256 },
      false,
      ['encrypt', 'decrypt'],
    )
    expect(await sameKey(dek, opened)).toBe(true)
  })

  it('shares a DEK from an admin to a new member end to end', async () => {
    const adminKEK = await deriveKEK('admin password', generateSalt())
    const memberKEK = await deriveKEK('member password', generateSalt())
    const memberKeys = await generateKeyPair()
    const memberWrappedPrivateKey = await wrapPrivateKey(memberKeys.privateKey, memberKEK)
    const dek = await generateDEK()
    const adminWrappedDek = await wrapDEK(dek, adminKEK)

    // Admin side: only their own wrapped DEK and the member's public key.
    const memberPublicKey = await importPublicKey(await exportPublicKey(memberKeys.publicKey))
    const shared = await rewrapDEKForPublicKey(adminWrappedDek, adminKEK, memberPublicKey)

    // Member side: only their own KEK and the stored private key.
    const privateKey = await unwrapPrivateKey(memberWrappedPrivateKey, memberKEK)
    const memberWrappedDek = await rewrapSharedDEK(shared, privateKey, memberKEK)

    expect(await sameKey(dek, await unwrapDEK(memberWrappedDek, memberKEK))).toBe(true)
  })
})
