const THUMBNAIL_MAX_DIM = 600
const THUMBNAIL_QUALITY = 0.78
const LQIP_MAX_DIM = 20
const LQIP_QUALITY = 0.20

export function fitInside(
  srcWidth: number,
  srcHeight: number,
  maxDim: number,
): { width: number; height: number } {
  if (srcWidth <= maxDim && srcHeight <= maxDim) {
    return { width: srcWidth, height: srcHeight }
  }
  const ratio = Math.min(maxDim / srcWidth, maxDim / srcHeight)
  return {
    width: Math.round(srcWidth * ratio),
    height: Math.round(srcHeight * ratio),
  }
}

async function bitmapToWebPBlob(
  bitmap: ImageBitmap,
  width: number,
  height: number,
  quality: number,
): Promise<Blob> {
  const canvas = new OffscreenCanvas(width, height)
  const ctx = canvas.getContext('2d')
  if (!ctx) throw new Error('Could not get OffscreenCanvas context')
  ctx.drawImage(bitmap, 0, 0, width, height)
  return await canvas.convertToBlob({ type: 'image/webp', quality })
}

async function stepDownResize(
  bitmap: ImageBitmap,
  targetMaxDim: number,
  quality: number,
): Promise<Blob> {
  let w = bitmap.width
  let h = bitmap.height
  const steps: { width: number; height: number }[] = []

  while (w > targetMaxDim || h > targetMaxDim) {
    w = Math.max(1, Math.round(w / 2))
    h = Math.max(1, Math.round(h / 2))
    const dims = fitInside(w, h, targetMaxDim)
    steps.push(dims)

    if (dims.width <= targetMaxDim && dims.height <= targetMaxDim) {
      break
    }
  }

  if (steps.length === 0) {
    const dims = fitInside(bitmap.width, bitmap.height, targetMaxDim)
    return await bitmapToWebPBlob(bitmap, dims.width, dims.height, quality)
  }

  let canvas = new OffscreenCanvas(
    steps.length > 0 ? steps[0]!.width * 2 : bitmap.width,
    steps.length > 0 ? steps[0]!.height * 2 : bitmap.height,
  )
  const ctx = canvas.getContext('2d')
  if (!ctx) throw new Error('Could not get OffscreenCanvas context')
  ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height)

  for (let i = 0; i < steps.length - 1; i++) {
    const step = steps[i]!
    const nextCanvas = new OffscreenCanvas(step.width, step.height)
    const nextCtx = nextCanvas.getContext('2d')
    if (!nextCtx) throw new Error('Could not get OffscreenCanvas context')
    nextCtx.drawImage(canvas, 0, 0, step.width, step.height)
    canvas = nextCanvas
  }

  const final = steps[steps.length - 1]!
  return await bitmapToWebPBlob(
    await createImageBitmap(canvas),
    final.width,
    final.height,
    quality,
  )
}

export async function generateThumbnail(bitmap: ImageBitmap): Promise<{ thumb: Blob }> {
  const thumb = await stepDownResize(bitmap, THUMBNAIL_MAX_DIM, THUMBNAIL_QUALITY)
  return { thumb }
}

export async function generateLQIP(bitmap: ImageBitmap): Promise<Blob> {
  const dims = fitInside(bitmap.width, bitmap.height, LQIP_MAX_DIM)
  return await bitmapToWebPBlob(bitmap, dims.width, dims.height, LQIP_QUALITY)
}
