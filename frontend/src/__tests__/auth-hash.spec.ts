// @vitest-environment node
import { createHmac } from 'node:crypto'
import { describe, it, expect } from 'vitest'
import { deriveAuthHash, deriveKekAndAuthHash } from '@/crypto/auth-hash'
import { deriveKEK, generateSalt } from '@/crypto/keychain'

async function rawHex(kek: CryptoKey): Promise<string> {
  return Buffer.from(await crypto.subtle.exportKey('raw', kek)).toString('hex')
}

describe('auth hash', () => {
  const salt = generateSalt()

  it('is 64 lowercase hex characters', async () => {
    const kek = await deriveKEK('correct horse battery', salt)

    expect(await deriveAuthHash(kek)).toMatch(/^[0-9a-f]{64}$/)
  })

  it('is HMAC-SHA256 of the label keyed with the raw KEK bytes', async () => {
    const kek = await deriveKEK('correct horse battery', salt)
    const expected = createHmac('sha256', Buffer.from(await rawHex(kek), 'hex'))
      .update('avapeek-auth-v1', 'utf8')
      .digest('hex')

    expect(await deriveAuthHash(kek)).toBe(expected)
  })

  it('is deterministic for the same KEK', async () => {
    const kek = await deriveKEK('correct horse battery', salt)
    const again = await deriveKEK('correct horse battery', salt)

    expect(await deriveAuthHash(kek)).toBe(await deriveAuthHash(kek))
    expect(await deriveAuthHash(again)).toBe(await deriveAuthHash(kek))
  })

  it('differs for a different password or a different salt', async () => {
    const base = await deriveAuthHash(await deriveKEK('correct horse battery', salt))
    const otherPassword = await deriveAuthHash(await deriveKEK('correct horse batterz', salt))
    const otherSalt = await deriveAuthHash(await deriveKEK('correct horse battery', generateSalt()))

    expect(otherPassword).not.toBe(base)
    expect(otherSalt).not.toBe(base)
    expect(otherSalt).not.toBe(otherPassword)
  })

  it('does not contain the KEK or the password', async () => {
    const kek = await deriveKEK('correct horse battery', salt)
    const hash = await deriveAuthHash(kek)

    expect(hash).not.toBe(await rawHex(kek))
    expect(hash).not.toContain('correct')
  })

  it('returns the KEK and its hash from a single derivation', async () => {
    const { kek, authHash } = await deriveKekAndAuthHash('correct horse battery', salt)

    expect(authHash).toBe(await deriveAuthHash(kek))
    expect(await rawHex(kek)).toBe(await rawHex(await deriveKEK('correct horse battery', salt)))
  })
})
