export function generateIV(): Uint8Array<ArrayBuffer> {
  const iv = new Uint8Array(16)
  crypto.getRandomValues(iv)
  return iv
}

export function ivToString(iv: Uint8Array): string {
  return Array.from(iv)
    .map((b) => b.toString(16).padStart(2, '0'))
    .join('')
}

export function ivFromString(hex: string): Uint8Array<ArrayBuffer> {
  const bytes = new Uint8Array(16)
  for (let i = 0; i < 16; i++) {
    const byteStr = hex.slice(i * 2, i * 2 + 2)
    if (!byteStr) throw new Error('Invalid hex IV')
    bytes[i] = parseInt(byteStr, 16)
  }
  return bytes as Uint8Array<ArrayBuffer>
}

export async function encryptBuffer(
  buffer: ArrayBuffer,
  dek: CryptoKey,
): Promise<{ encrypted: ArrayBuffer; iv: string }> {
  const iv = generateIV()
  const encrypted = await crypto.subtle.encrypt(
    { name: 'AES-GCM', iv },
    dek,
    buffer,
  )
  return { encrypted, iv: ivToString(iv) }
}

export async function encryptString(
  text: string,
  dek: CryptoKey,
): Promise<{ encrypted: string; iv: string }> {
  const { encrypted, iv } = await encryptBuffer(
    new TextEncoder().encode(text).buffer as ArrayBuffer,
    dek,
  )
  return { encrypted: bufferToBase64(encrypted), iv }
}

function bufferToBase64(buffer: ArrayBuffer): string {
  const bytes = new Uint8Array(buffer)
  let binary = ''
  for (let i = 0; i < bytes.length; i++) {
    binary += String.fromCharCode(bytes[i]!)
  }
  return btoa(binary)
}

export async function decryptBlob(
  encrypted: ArrayBuffer,
  dek: CryptoKey,
  iv: string,
): Promise<ArrayBuffer> {
  return await crypto.subtle.decrypt(
    { name: 'AES-GCM', iv: ivFromString(iv) },
    dek,
    encrypted,
  )
}

const objectUrls = new Set<string>()

export function blobToObjectURL(
  decrypted: ArrayBuffer,
  mimeType: string,
): string {
  const blob = new Blob([decrypted], { type: mimeType })
  const url = URL.createObjectURL(blob)
  objectUrls.add(url)
  return url
}

export function revokeObjectURL(url: string): void {
  URL.revokeObjectURL(url)
  objectUrls.delete(url)
}

export function revokeAllObjectURLs(): void {
  objectUrls.forEach((url) => URL.revokeObjectURL(url))
  objectUrls.clear()
}
