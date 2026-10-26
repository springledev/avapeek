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

/**
 * A log-safe summary of an error. Axios errors carry the request body (which holds
 * the auth hash), so they must never be logged whole.
 */
export function safeErrorSummary(err: unknown): string {
  const e = err as { response?: { status?: number }; message?: string } | null
  const status = e?.response?.status
  const message = typeof e?.message === 'string' ? e.message : 'unknown error'
  return status ? `HTTP ${status}: ${message}` : message
}

/**
 * First field message from a Laravel 422 response, or null when the error has none.
 */
export function firstValidationError(err: unknown): string | null {
  const errors = (err as { response?: { data?: { errors?: Record<string, string[]> } } } | null)
    ?.response?.data?.errors
  if (!errors) return null
  const firstKey = Object.keys(errors)[0]
  return firstKey ? (errors[firstKey]?.[0] ?? null) : null
}
