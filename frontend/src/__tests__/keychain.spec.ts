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
  generatePEK,
  wrapPEKWithDEK,
  unwrapPEK,
  rewrapPEK,
} from '@/crypto/keychain'
import { encryptBuffer, decryptBlob } from '@/crypto/encrypt'

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

function decodeBase64(value: string): Uint8Array {
  return Uint8Array.from(atob(value), (c) => c.charCodeAt(0))
}

function encodeBase64(bytes: Uint8Array): string {
  return btoa(String.fromCharCode(...bytes))
}

describe('keychain KEK-wrapped DEK format', () => {
  it('uses a fresh random IV for every wrap', async () => {
    const kek = await deriveKEK('password', generateSalt())
    const dek = await generateDEK()

    const first = await wrapDEK(dek, kek)
    const second = await wrapDEK(dek, kek)

    expect(first).not.toBe(second)
    expect(decodeBase64(first).slice(0, 12)).not.toEqual(decodeBase64(second).slice(0, 12))
  })

  it('produces base64(iv || ciphertext) of exactly 60 bytes', async () => {
    const kek = await deriveKEK('password', generateSalt())
    const wrapped = await wrapDEK(await generateDEK(), kek)

    expect(decodeBase64(wrapped)).toHaveLength(60)
  })

  it('round-trips a DEK through wrap and unwrap', async () => {
    const kek = await deriveKEK('password', generateSalt())
    const dek = await generateDEK()

    expect(await sameKey(dek, await unwrapDEK(await wrapDEK(dek, kek), kek))).toBe(true)
  })

  it('rejects a wrapped DEK with a tampered IV byte', async () => {
    const kek = await deriveKEK('password', generateSalt())
    const bytes = decodeBase64(await wrapDEK(await generateDEK(), kek))
    bytes[0] = bytes[0]! ^ 0x01

    await expect(unwrapDEK(encodeBase64(bytes), kek)).rejects.toBeDefined()
  })

  it('rejects a wrapped DEK with a tampered ciphertext byte', async () => {
    const kek = await deriveKEK('password', generateSalt())
    const bytes = decodeBase64(await wrapDEK(await generateDEK(), kek))
    bytes[20] = bytes[20]! ^ 0x01

    await expect(unwrapDEK(encodeBase64(bytes), kek)).rejects.toBeDefined()
  })

  it('rejects unwrapping with the wrong KEK', async () => {
    const salt = generateSalt()
    const wrapped = await wrapDEK(await generateDEK(), await deriveKEK('right', salt))

    await expect(unwrapDEK(wrapped, await deriveKEK('wrong', salt))).rejects.toBeDefined()
  })

  it('rejects a legacy 48-byte zero-IV wrapped DEK with a clear error', async () => {
    const kek = await deriveKEK('password', generateSalt())
    const legacy = new Uint8Array(
      await crypto.subtle.wrapKey('raw', await generateDEK(), kek, {
        name: 'AES-GCM',
        iv: new Uint8Array(12),
      }),
    )
    const legacyWrapped = encodeBase64(legacy)
    const { publicKey } = await generateKeyPair()

    expect(legacy).toHaveLength(48)
    await expect(unwrapDEK(legacyWrapped, kek)).rejects.toThrow(/Invalid wrapped DEK/)
    await expect(rewrapDEK(legacyWrapped, kek, kek)).rejects.toThrow(/Invalid wrapped DEK/)
    await expect(rewrapDEKForPublicKey(legacyWrapped, kek, publicKey)).rejects.toThrow(
      /Invalid wrapped DEK/,
    )
  })
})

describe('keychain PEK re-wrapping', () => {
  const content = new TextEncoder().encode('first steps in the garden')

  async function setup() {
    const dekA = await generateDEK()
    const dekB = await generateDEK()
    const pek = await generatePEK()
    const wrapped = await wrapPEKWithDEK(pek, dekA)
    const { encrypted, iv } = await encryptBuffer(content.slice().buffer as ArrayBuffer, pek)
    return { dekA, dekB, wrapped, encrypted, iv }
  }

  it('moves a PEK from one DEK to another so content still decrypts', async () => {
    const { dekA, dekB, wrapped, encrypted, iv } = await setup()

    const rewrapped = await rewrapPEK(wrapped.encrypted_pek, wrapped.iv_pek, dekA, dekB)
    const pek = await unwrapPEK(rewrapped.encrypted_pek, rewrapped.iv_pek, dekB)

    expect(new Uint8Array(await decryptBlob(encrypted, pek, iv))).toEqual(content)
  })

  it('rejects a wrong source DEK', async () => {
    const { dekB, wrapped } = await setup()

    await expect(
      rewrapPEK(wrapped.encrypted_pek, wrapped.iv_pek, await generateDEK(), dekB),
    ).rejects.toBeDefined()
  })

  it('produces output that a different DEK cannot unwrap', async () => {
    const { dekA, dekB, wrapped } = await setup()

    const rewrapped = await rewrapPEK(wrapped.encrypted_pek, wrapped.iv_pek, dekA, dekB)

    await expect(
      unwrapPEK(rewrapped.encrypted_pek, rewrapped.iv_pek, await generateDEK()),
    ).rejects.toBeDefined()
    await expect(unwrapPEK(rewrapped.encrypted_pek, rewrapped.iv_pek, dekA)).rejects.toBeDefined()
  })

  it('uses a fresh IV on every call and never reuses the input IV', async () => {
    const { dekA, dekB, wrapped } = await setup()

    const first = await rewrapPEK(wrapped.encrypted_pek, wrapped.iv_pek, dekA, dekB)
    const second = await rewrapPEK(wrapped.encrypted_pek, wrapped.iv_pek, dekA, dekB)

    expect(first.iv_pek).not.toBe(second.iv_pek)
    expect(first.encrypted_pek).not.toBe(second.encrypted_pek)
    expect(first.iv_pek).not.toBe(wrapped.iv_pek)
    expect(second.iv_pek).not.toBe(wrapped.iv_pek)
  })

  it('matches the wrapPEKWithDEK output format', async () => {
    const { dekA, dekB, wrapped } = await setup()

    const rewrapped = await rewrapPEK(wrapped.encrypted_pek, wrapped.iv_pek, dekA, dekB)

    expect(rewrapped.encrypted_pek).toMatch(/^[A-Za-z0-9+/]{64}$/)
    expect(rewrapped.iv_pek).toMatch(/^[A-Za-z0-9+/]{16}$/)
    expect(decodeBase64(rewrapped.encrypted_pek)).toHaveLength(48)
    expect(decodeBase64(rewrapped.iv_pek)).toHaveLength(12)
  })

  it('keeps unwrapPEK non-extractable and both wraps open the same PEK', async () => {
    const { dekA, dekB, wrapped, encrypted, iv } = await setup()

    const rewrapped = await rewrapPEK(wrapped.encrypted_pek, wrapped.iv_pek, dekA, dekB)
    const original = await unwrapPEK(wrapped.encrypted_pek, wrapped.iv_pek, dekA)
    const moved = await unwrapPEK(rewrapped.encrypted_pek, rewrapped.iv_pek, dekB)

    expect(original.extractable).toBe(false)
    expect(moved.extractable).toBe(false)
    expect(new Uint8Array(await decryptBlob(encrypted, original, iv))).toEqual(content)
    expect(new Uint8Array(await decryptBlob(encrypted, moved, iv))).toEqual(content)
  })

  it('rejects a tampered encrypted_pek', async () => {
    const { dekA, dekB, wrapped } = await setup()
    const bytes = decodeBase64(wrapped.encrypted_pek)
    bytes[5] = bytes[5]! ^ 0x01

    await expect(rewrapPEK(encodeBase64(bytes), wrapped.iv_pek, dekA, dekB)).rejects.toBeDefined()
  })
})
