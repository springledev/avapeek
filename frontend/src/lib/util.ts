export function base64ToArrayBuffer(base64: string): ArrayBuffer {
  const binary = atob(base64)
  const bytes = new Uint8Array(binary.length)
  for (let i = 0; i < binary.length; i++) {
    bytes[i] = binary.charCodeAt(i)
  }
  return bytes.buffer
}

export function arrayBufferToBase64(buffer: ArrayBuffer): string {
  const bytes = new Uint8Array(buffer)
  let binary = ''
  for (let i = 0; i < bytes.length; i++) {
    binary += String.fromCharCode(bytes[i]!)
  }
  return btoa(binary)
}

/**
 * Extracts a server-provided error message from an unknown throwable,
 * falling back to a friendly default.
 */
export function apiErrorMessage(err: unknown, fallback: string): string {
  const response = (err as { response?: { data?: { message?: string } } } | null)?.response
  return response?.data?.message || fallback
}
