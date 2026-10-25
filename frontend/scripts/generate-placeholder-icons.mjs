// PLACEHOLDER icon generator: accent square with an "A". Replace with real artwork.
// Usage (from frontend/): node scripts/generate-placeholder-icons.mjs
// Uses only Node built-ins (zlib) to write PNGs.
import { deflateSync } from 'node:zlib'
import { mkdirSync, writeFileSync } from 'node:fs'

const ACCENT = [0xe8, 0x7a, 0x2e] // --color-accent (light)
const INK = [0x2e, 0x1a, 0x0a] // --color-on-accent

const crcTable = Array.from({ length: 256 }, (_, n) => {
  let c = n
  for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1
  return c >>> 0
})
function crc32(buf) {
  let c = 0xffffffff
  for (const b of buf) c = crcTable[(c ^ b) & 0xff] ^ (c >>> 8)
  return (c ^ 0xffffffff) >>> 0
}
function chunk(type, data) {
  const len = Buffer.alloc(4)
  len.writeUInt32BE(data.length)
  const body = Buffer.concat([Buffer.from(type), data])
  const crc = Buffer.alloc(4)
  crc.writeUInt32BE(crc32(body))
  return Buffer.concat([len, body, crc])
}

function distToSegment(px, py, ax, ay, bx, by) {
  const dx = bx - ax
  const dy = by - ay
  const t = Math.max(0, Math.min(1, ((px - ax) * dx + (py - ay) * dy) / (dx * dx + dy * dy)))
  return Math.hypot(px - (ax + t * dx), py - (ay + t * dy))
}

/** Coverage of the "A" glyph at unit coords (0..1), scaled around the centre. */
function insideGlyph(x, y, scale) {
  const u = (x - 0.5) / scale + 0.5
  const v = (y - 0.5) / scale + 0.5
  const w = 0.085 // half stroke width
  const apexX = 0.5
  const topY = 0.2
  const botY = 0.8
  const leftX = 0.28
  const rightX = 0.72
  if (distToSegment(u, v, apexX, topY, leftX, botY) < w) return true
  if (distToSegment(u, v, apexX, topY, rightX, botY) < w) return true
  return distToSegment(u, v, 0.355, 0.62, 0.645, 0.62) < w * 0.8
}

function renderPng(size, { scale, rounded }) {
  const ss = 3
  const raw = Buffer.alloc((size * 4 + 1) * size)
  for (let y = 0; y < size; y++) {
    raw[y * (size * 4 + 1)] = 0
    for (let x = 0; x < size; x++) {
      let glyph = 0
      for (let sy = 0; sy < ss; sy++) {
        for (let sx = 0; sx < ss; sx++) {
          if (insideGlyph((x + (sx + 0.5) / ss) / size, (y + (sy + 0.5) / ss) / size, scale)) glyph++
        }
      }
      const g = glyph / (ss * ss)
      let alpha = 255
      if (rounded) {
        const r = size * 0.18
        const cx = Math.min(Math.max(x + 0.5, r), size - r)
        const cy = Math.min(Math.max(y + 0.5, r), size - r)
        alpha = Math.hypot(x + 0.5 - cx, y + 0.5 - cy) <= r ? 255 : 0
      }
      const o = y * (size * 4 + 1) + 1 + x * 4
      for (let c = 0; c < 3; c++) raw[o + c] = Math.round(ACCENT[c] * (1 - g) + INK[c] * g)
      raw[o + 3] = alpha
    }
  }
  const ihdr = Buffer.alloc(13)
  ihdr.writeUInt32BE(size, 0)
  ihdr.writeUInt32BE(size, 4)
  ihdr[8] = 8
  ihdr[9] = 6 // RGBA
  return Buffer.concat([
    Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
    chunk('IHDR', ihdr),
    chunk('IDAT', deflateSync(raw)),
    chunk('IEND', Buffer.alloc(0)),
  ])
}

mkdirSync('public/icons', { recursive: true })
// Maskable: full-bleed square, glyph inside the 80% safe zone.
writeFileSync('public/icons/icon-192.png', renderPng(192, { scale: 1, rounded: false }))
writeFileSync('public/icons/icon-512.png', renderPng(512, { scale: 1, rounded: false }))
writeFileSync('public/icons/icon-maskable-512.png', renderPng(512, { scale: 0.8, rounded: false }))
writeFileSync('public/apple-touch-icon.png', renderPng(180, { scale: 1, rounded: false }))
