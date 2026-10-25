// @vitest-environment node
import { describe, it, expect } from 'vitest'
import { predictLength } from 'client-zip'
import {
  PART_BYTES_DESKTOP,
  PART_BYTES_MOBILE,
  createZipStream,
  defaultPartBytes,
  planParts,
  type ZipEntry,
} from '@/lib/export/zip'

const CRC_TABLE = (() => {
  const table = new Uint32Array(256)
  for (let n = 0; n < 256; n++) {
    let c = n
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1
    table[n] = c >>> 0
  }
  return table
})()

function crc32(bytes: Uint8Array): number {
  let c = 0xffffffff
  for (const byte of bytes) c = CRC_TABLE[(c ^ byte) & 0xff]! ^ (c >>> 8)
  return (c ^ 0xffffffff) >>> 0
}

/** Minimal store-only zip reader (no Zip64) used to verify archives written by the library. */
function readZip(buffer: Uint8Array): Map<string, Uint8Array> {
  const view = new DataView(buffer.buffer, buffer.byteOffset, buffer.byteLength)
  let eocd = buffer.length - 22
  while (view.getUint32(eocd, true) !== 0x06054b50) eocd--
  const count = view.getUint16(eocd + 10, true)
  let offset = view.getUint32(eocd + 16, true)
  const files = new Map<string, Uint8Array>()

  for (let i = 0; i < count; i++) {
    expect(view.getUint32(offset, true)).toBe(0x02014b50)
    const method = view.getUint16(offset + 10, true)
    const crc = view.getUint32(offset + 16, true)
    const size = view.getUint32(offset + 24, true)
    const nameLength = view.getUint16(offset + 28, true)
    const extraLength = view.getUint16(offset + 30, true)
    const commentLength = view.getUint16(offset + 32, true)
    const localOffset = view.getUint32(offset + 42, true)
    const name = new TextDecoder().decode(buffer.subarray(offset + 46, offset + 46 + nameLength))

    expect(method).toBe(0)
    const localNameLength = view.getUint16(localOffset + 26, true)
    const localExtraLength = view.getUint16(localOffset + 28, true)
    const start = localOffset + 30 + localNameLength + localExtraLength
    const data = buffer.slice(start, start + size)
    expect(crc32(data)).toBe(crc)
    files.set(name, data)
    offset += 46 + nameLength + extraLength + commentLength
  }
  return files
}

async function* gen(entries: ZipEntry[]): AsyncGenerator<ZipEntry> {
  for (const entry of entries) yield entry
}

describe('crc32 reference', () => {
  it('matches the known check value', () => {
    expect(crc32(new TextEncoder().encode('123456789'))).toBe(0xcbf43926)
  })
})

describe('createZipStream', () => {
  it('round-trips store-only entries byte for byte, including binary and unicode names', async () => {
    const binary = new Uint8Array(4096).map((_, i) => (i * 31) % 256)
    const archive = new Uint8Array(
      await new Response(
        createZipStream(
          gen([
            { name: 'kid/photos/2024/a.jpg', input: binary },
            { name: 'kid/photos/2024/写真.heic', input: new Uint8Array([1, 2, 3]) },
            { name: 'kid/manifest.json', input: '{"ok":true}' },
          ]),
        ),
      ).arrayBuffer(),
    )

    const files = readZip(archive)

    expect([...files.keys()]).toEqual([
      'kid/photos/2024/a.jpg',
      'kid/photos/2024/写真.heic',
      'kid/manifest.json',
    ])
    expect(files.get('kid/photos/2024/a.jpg')).toEqual(binary)
    expect(new TextDecoder().decode(files.get('kid/manifest.json'))).toBe('{"ok":true}')
  })

  it('pulls entries lazily so memory stays bounded to one photo', async () => {
    let produced = 0
    async function* lazy(): AsyncGenerator<ZipEntry> {
      for (let i = 0; i < 3; i++) {
        produced++
        yield { name: `f${i}.bin`, input: new Uint8Array(10) }
      }
    }
    const reader = createZipStream(lazy()).getReader()
    await reader.read()
    expect(produced).toBeLessThan(3)
    await reader.cancel().catch(() => {})
  })
})

describe('Zip64 support of the zip library', () => {
  it('adds Zip64 structures for entries over 4 GB and for more than 65535 entries', () => {
    const gb5 = 5n * 1024n ** 3n
    const small = predictLength([{ name: 'a.bin', size: 10 }])
    const big = predictLength([{ name: 'a.bin', size: gb5 }])
    expect(big - gb5).toBeGreaterThan(small - 10n)

    const many = Array.from({ length: 70000 }, (_, i) => ({ name: `f${i}.bin`, size: 1 }))
    expect(predictLength(many)).toBeGreaterThan(70000n * 30n)
  })
})

describe('planParts', () => {
  const items = [
    { id: 1, size_bytes: 40 },
    { id: 2, size_bytes: 40 },
    { id: 5, size_bytes: 40 },
    { id: 9, size_bytes: 150 },
    { id: 10, size_bytes: 10 },
  ]

  it('splits by the byte limit with keyset boundaries', () => {
    expect(planParts(items, 100)).toEqual([
      { startAfterId: 0, lastId: 2, count: 2, bytes: 80 },
      { startAfterId: 2, lastId: 5, count: 1, bytes: 40 },
      { startAfterId: 5, lastId: 9, count: 1, bytes: 150 },
      { startAfterId: 9, lastId: 10, count: 1, bytes: 10 },
    ])
  })

  it('keeps everything in one part when the limit is infinite', () => {
    expect(planParts(items, Number.POSITIVE_INFINITY)).toEqual([
      { startAfterId: 0, lastId: 10, count: 5, bytes: 280 },
    ])
  })

  it('returns one empty part for an empty child and honours the starting cursor', () => {
    expect(planParts([], 100, 7)).toEqual([{ startAfterId: 7, lastId: 7, count: 0, bytes: 0 }])
    expect(planParts(items.slice(3), 100, 5)[0]!.startAfterId).toBe(5)
  })

  it('picks 400 MB parts on mobile and 1.5 GB on desktop', () => {
    expect(defaultPartBytes('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)')).toBe(
      PART_BYTES_MOBILE,
    )
    expect(defaultPartBytes('Mozilla/5.0 (Linux; Android 14) Mobile')).toBe(PART_BYTES_MOBILE)
    expect(defaultPartBytes('Mozilla/5.0 (X11; Linux x86_64) Firefox/130.0')).toBe(PART_BYTES_DESKTOP)
  })
})
