const MAX_SIZE = 5
const cache = new Map<number, string>()

export function getCachedOriginal(photoId: number): string | undefined {
  const url = cache.get(photoId)
  if (url) {
    cache.delete(photoId)
    cache.set(photoId, url)
    return url
  }
  return undefined
}

export function setCachedOriginal(photoId: number, url: string): void {
  if (cache.has(photoId)) {
    cache.delete(photoId)
  }
  cache.set(photoId, url)
  if (cache.size > MAX_SIZE) {
    const oldestId = cache.keys().next().value
    if (oldestId !== undefined) {
      const oldestUrl = cache.get(oldestId)!
      URL.revokeObjectURL(oldestUrl)
      cache.delete(oldestId)
    }
  }
}

export function evictOriginal(photoId: number): void {
  const url = cache.get(photoId)
  if (url) {
    URL.revokeObjectURL(url)
    cache.delete(photoId)
  }
}

export function clearOriginalCache(): void {
  for (const url of cache.values()) {
    URL.revokeObjectURL(url)
  }
  cache.clear()
}
