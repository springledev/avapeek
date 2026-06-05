const HEIC_MIME_TYPES = ['image/heic', 'image/heif']
const HEIC_EXTENSIONS = ['.heic', '.heif']

export function isHeic(file: File): boolean {
  if (HEIC_MIME_TYPES.includes(file.type)) return true
  const name = file.name.toLowerCase()
  return HEIC_EXTENSIONS.some((ext) => name.endsWith(ext))
}

async function heicToImageBitmap(data: ArrayBuffer): Promise<ImageBitmap> {
  const decoder = await import('heic-to')
  const blob = new Blob([data])
  const result = await decoder.heicTo({ blob, type: 'bitmap' })
  return result
}

export async function decodeHeic(file: File): Promise<ImageBitmap> {
  try {
    return await createImageBitmap(file)
  } catch {
    // Safari didn't handle it natively, try WASM
  }

  const buffer = await file.arrayBuffer()
  return await heicToImageBitmap(buffer)
}
