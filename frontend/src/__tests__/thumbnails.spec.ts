// @vitest-environment node
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { fitInside, generateThumbnail, generateLQIP } from '@/crypto/thumbnails'

describe('fitInside', () => {
  it('returns the original size when already within bounds', () => {
    expect(fitInside(300, 200, 600)).toEqual({ width: 300, height: 200 })
    expect(fitInside(600, 600, 600)).toEqual({ width: 600, height: 600 })
  })

  it('scales landscape by width', () => {
    expect(fitInside(1200, 600, 600)).toEqual({ width: 600, height: 300 })
  })

  it('scales portrait by height', () => {
    expect(fitInside(600, 1800, 600)).toEqual({ width: 200, height: 600 })
  })

  it('rounds to integers', () => {
    expect(fitInside(4032, 3024, 600)).toEqual({ width: 600, height: 450 })
    const s = fitInside(1001, 333, 20)
    expect(s.width).toBe(20)
    expect(Number.isInteger(s.height)).toBe(true)
  })
})

describe('thumbnail generation with mocked canvas', () => {
  let canvases: MockCanvas[]
  let hasContext = true

  class MockCanvas {
    constructor(
      public width: number,
      public height: number,
    ) {
      canvases.push(this)
    }
    getContext() {
      return hasContext ? { drawImage: () => {} } : null
    }
    convertToBlob(opts: { type: string; quality: number }) {
      return Promise.resolve(
        Object.assign(new Blob(['x'], { type: opts.type }), {
          meta: { w: this.width, h: this.height, q: opts.quality },
        }),
      )
    }
  }

  beforeEach(() => {
    canvases = []
    hasContext = true
    vi.stubGlobal('OffscreenCanvas', MockCanvas)
    vi.stubGlobal(
      'createImageBitmap',
      vi.fn((c: MockCanvas) => Promise.resolve({ width: c.width, height: c.height })),
    )
  })
  afterEach(() => vi.unstubAllGlobals())

  const bmp = (width: number, height: number) => ({ width, height }) as ImageBitmap
  const meta = (b: Blob) => (b as Blob & { meta: { w: number; h: number; q: number } }).meta

  it('generateLQIP fits into 20px webp at low quality', async () => {
    const blob = await generateLQIP(bmp(4000, 2000))
    expect(blob.type).toBe('image/webp')
    expect(meta(blob)).toEqual({ w: 20, h: 10, q: 0.2 })
  })

  it('generateThumbnail of a small image uses one canvas without step-down', async () => {
    const { thumb } = await generateThumbnail(bmp(400, 300))
    expect(thumb.type).toBe('image/webp')
    expect(meta(thumb)).toEqual({ w: 400, h: 300, q: 0.78 })
    expect(canvases).toHaveLength(1)
  })

  it('generateThumbnail of a large image ends within 600px and keeps aspect', async () => {
    const { thumb } = await generateThumbnail(bmp(4000, 3000))
    const { w, h, q } = meta(thumb)
    expect(Math.max(w, h)).toBeLessThanOrEqual(600)
    expect(q).toBe(0.78)
    expect(w / h).toBeCloseTo(4 / 3, 1)
  })

  it('throws when no 2d context is available', async () => {
    hasContext = false
    await expect(generateLQIP(bmp(100, 100))).rejects.toThrow(
      'Could not get OffscreenCanvas context',
    )
    await expect(generateThumbnail(bmp(4000, 3000))).rejects.toThrow(
      'Could not get OffscreenCanvas context',
    )
  })
})
