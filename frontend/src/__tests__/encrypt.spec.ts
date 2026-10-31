// @vitest-environment node
import { describe, it, expect, vi, afterEach } from 'vitest'
import { createCipheriv } from 'node:crypto'
import {
  generateIV,
  ivToString,
  ivFromString,
  encryptBuffer,
  encryptString,
  decryptBlob,
  blobToObjectURL,
  revokeObjectURL,
  revokeAllObjectURLs,
} from '@/crypto/encrypt'

const RAW_KEY = Buffer.from(
  '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f',
  'hex',
)
const FIXED_IV_HEX = 'a0a1a2a3a4a5a6a7a8a9aaabacadaeaf'
// 'avapeek' under RAW_KEY / FIXED_IV_HEX, generated once with node:crypto aes-256-gcm (ciphertext || tag).
const KAT_HEX = '4bd542cc7da8a6645e5df7b8df4f4dc3f4caa195096e10'

function importKey(raw: Uint8Array = RAW_KEY): Promise<CryptoKey> {
  return crypto.subtle.importKey('raw', raw as BufferSource, 'AES-GCM', false, [
    'encrypt',
    'decrypt',
  ])
}

/** Independent AES-256-GCM via node:crypto; returns ciphertext || tag (WebCrypto layout). */
function nodeEncrypt(plain: Uint8Array, ivHex: string): Buffer {
  const c = createCipheriv('aes-256-gcm', RAW_KEY, Buffer.from(ivHex, 'hex'))
  const ct = Buffer.concat([c.update(plain), c.final()])
  return Buffer.concat([ct, c.getAuthTag()])
}

function toAB(u: Uint8Array): ArrayBuffer {
  return u.buffer.slice(u.byteOffset, u.byteOffset + u.byteLength) as ArrayBuffer
}

async function decryptText(encryptedB64: string, key: CryptoKey, iv: string): Promise<string> {
  const buf = toAB(Buffer.from(encryptedB64, 'base64'))
  return new TextDecoder().decode(await decryptBlob(buf, key, iv))
}

describe('IV helpers', () => {
  it('generateIV returns 16 random bytes that differ per call', () => {
    const a = generateIV()
    const b = generateIV()
    expect(a).toHaveLength(16)
    expect(b).toHaveLength(16)
    expect(ivToString(a)).not.toBe(ivToString(b))
  })

  it('ivToString/ivFromString round trip as 32 hex chars', () => {
    const iv = generateIV()
    const hex = ivToString(iv)
    expect(hex).toMatch(/^[0-9a-f]{32}$/)
    expect(Array.from(ivFromString(hex))).toEqual(Array.from(iv))
  })

  it('ivFromString rejects hex that is too short', () => {
    expect(() => ivFromString('abcd')).toThrow('Invalid hex IV')
  })
})

describe('encryptString round trips', () => {
  it.each([
    ['empty', ''],
    ['ascii', 'hello world'],
    ['unicode', 'Zoë 👨‍👩‍👧 日本語 ñ'],
  ])('round-trips %s string', async (_name, text) => {
    const key = await importKey()
    const { encrypted, iv } = await encryptString(text, key)
    expect(iv).toMatch(/^[0-9a-f]{32}$/)
    expect(await decryptText(encrypted, key, iv)).toBe(text)
  })

  it('produces different IV and ciphertext for the same plaintext', async () => {
    const key = await importKey()
    const a = await encryptString('same', key)
    const b = await encryptString('same', key)
    expect(a.iv).not.toBe(b.iv)
    expect(a.encrypted).not.toBe(b.encrypted)
  })

  it('output is base64 of plaintext length plus 16-byte tag', async () => {
    const key = await importKey()
    const { encrypted } = await encryptString('abcde', key)
    expect(Buffer.from(encrypted, 'base64')).toHaveLength(5 + 16)
  })
})

describe('encryptBuffer round trips', () => {
  it('round-trips an empty buffer', async () => {
    const key = await importKey()
    const { encrypted, iv } = await encryptBuffer(new ArrayBuffer(0), key)
    expect(encrypted.byteLength).toBe(16)
    expect((await decryptBlob(encrypted, key, iv)).byteLength).toBe(0)
  })

  it('round-trips a 2 MiB buffer', async () => {
    const key = await importKey()
    const data = new Uint8Array(2 * 1024 * 1024)
    for (let i = 0; i < data.length; i++) data[i] = (i * 31 + 7) & 0xff
    const { encrypted, iv } = await encryptBuffer(toAB(data), key)
    expect(encrypted.byteLength).toBe(data.length + 16)
    const out = new Uint8Array(await decryptBlob(encrypted, key, iv))
    expect(Buffer.compare(Buffer.from(out), Buffer.from(data))).toBe(0)
  })
})

describe('known-answer tests', () => {
  it('decrypts a hard-coded vector produced independently with node:crypto', async () => {
    const key = await importKey()
    const out = await decryptBlob(toAB(Buffer.from(KAT_HEX, 'hex')), key, FIXED_IV_HEX)
    expect(new TextDecoder().decode(out)).toBe('avapeek')
  })

  it('decrypts a node:crypto ciphertext computed in the test', async () => {
    const key = await importKey()
    const ct = nodeEncrypt(new TextEncoder().encode('independent vector'), FIXED_IV_HEX)
    const out = await decryptBlob(toAB(ct), key, FIXED_IV_HEX)
    expect(new TextDecoder().decode(out)).toBe('independent vector')
  })

  it('encryptBuffer output equals node:crypto when the IV is pinned', async () => {
    const key = await importKey()
    const iv = Uint8Array.from(Buffer.from(FIXED_IV_HEX, 'hex'))
    const spy = vi.spyOn(crypto, 'getRandomValues').mockImplementation(((arr: Uint8Array) => {
      arr.set(iv)
      return arr
    }) as typeof crypto.getRandomValues)
    try {
      const { encrypted, iv: ivHex } = await encryptBuffer(
        toAB(new TextEncoder().encode('avapeek')),
        key,
      )
      expect(ivHex).toBe(FIXED_IV_HEX)
      expect(Buffer.from(encrypted).toString('hex')).toBe(KAT_HEX)
    } finally {
      spy.mockRestore()
    }
  })
})

describe('tamper detection', () => {
  async function sample() {
    const key = await importKey()
    const { encrypted, iv } = await encryptBuffer(
      toAB(new TextEncoder().encode('secret photo')),
      key,
    )
    return { key, encrypted, iv }
  }

  it('rejects a flipped ciphertext byte', async () => {
    const { key, encrypted, iv } = await sample()
    const bad = new Uint8Array(encrypted.slice(0))
    bad[0] = bad[0]! ^ 0x01
    await expect(decryptBlob(toAB(bad), key, iv)).rejects.toThrow(/.+/)
  })

  it('rejects a flipped auth tag byte', async () => {
    const { key, encrypted, iv } = await sample()
    const bad = new Uint8Array(encrypted.slice(0))
    bad[bad.length - 1] = bad[bad.length - 1]! ^ 0x80
    await expect(decryptBlob(toAB(bad), key, iv)).rejects.toThrow(/.+/)
  })

  it('rejects truncated ciphertext', async () => {
    const { key, encrypted, iv } = await sample()
    await expect(
      decryptBlob(encrypted.slice(0, encrypted.byteLength - 1), key, iv),
    ).rejects.toThrow(/.+/)
  })

  it('rejects the wrong key', async () => {
    const { encrypted, iv } = await sample()
    const other = await importKey(new Uint8Array(32).fill(9))
    await expect(decryptBlob(encrypted, other, iv)).rejects.toThrow(/.+/)
  })

  it('rejects the wrong IV', async () => {
    const { key, encrypted, iv } = await sample()
    const bytes = ivFromString(iv)
    bytes[0] = bytes[0]! ^ 0xff
    await expect(decryptBlob(encrypted, key, ivToString(bytes))).rejects.toThrow(/.+/)
  })
})

describe('object URL tracking', () => {
  afterEach(() => vi.restoreAllMocks())

  it('creates, revokes, and bulk-revokes object URLs', () => {
    let n = 0
    const create = vi.spyOn(URL, 'createObjectURL').mockImplementation(() => `blob:test/${++n}`)
    const revoke = vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {})

    const u1 = blobToObjectURL(new ArrayBuffer(1), 'image/png')
    const u2 = blobToObjectURL(new ArrayBuffer(1), 'image/png')
    const u3 = blobToObjectURL(new ArrayBuffer(1), 'image/png')
    expect((create.mock.calls[0]![0] as Blob).type).toBe('image/png')

    revokeObjectURL(u1)
    expect(revoke).toHaveBeenCalledWith(u1)

    revoke.mockClear()
    revokeAllObjectURLs()
    expect(revoke.mock.calls.map((c) => c[0]).sort()).toEqual([u2, u3].sort())

    revoke.mockClear()
    revokeAllObjectURLs()
    expect(revoke).not.toHaveBeenCalled()
  })
})
