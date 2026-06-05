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

    // If we've reached the target, use fitInside for final sizing
    if (dims.width <= targetMaxDim && dims.height <= targetMaxDim) {
      break
    }
  }

  if (steps.length === 0) {
    const dims = fitInside(bitmap.width, bitmap.height, targetMaxDim)
    return await bitmapToWebPBlob(bitmap, dims.width, dims.height, quality)
  }

  // Step down: first resize by 50% steps, then final resize
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

export async function generateThumbnails(bitmap: ImageBitmap): Promise<{
  sm: Blob
  md: Blob
  lg: Blob
}> {
  const [sm, md, lg] = await Promise.all([
    stepDownResize(bitmap, 200, 0.8),
    stepDownResize(bitmap, 600, 0.8),
    stepDownResize(bitmap, 1200, 0.8),
  ])
  return { sm, md, lg }
}

export async function encodeOriginal(bitmap: ImageBitmap): Promise<Blob> {
  const canvas = new OffscreenCanvas(bitmap.width, bitmap.height)
  const ctx = canvas.getContext('2d')
  if (!ctx) throw new Error('Could not get OffscreenCanvas context')
  ctx.drawImage(bitmap, 0, 0)
  return await canvas.convertToBlob({ type: 'image/webp', quality: 0.92 })
}
