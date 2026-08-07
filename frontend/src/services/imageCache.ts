function createCache(maxSize: number) {
  const cache = new Map<number, string>()

  return {
    get(photoId: number): string | undefined {
      const url = cache.get(photoId)
      if (url) {
        cache.delete(photoId)
        cache.set(photoId, url)
        return url
      }
      return undefined
    },
    set(photoId: number, url: string): void {
      if (cache.has(photoId)) cache.delete(photoId)
      cache.set(photoId, url)
      if (cache.size > maxSize) {
        const oldestId = cache.keys().next().value
        if (oldestId !== undefined) {
          URL.revokeObjectURL(cache.get(oldestId)!)
          cache.delete(oldestId)
        }
      }
    },
    evict(photoId: number): void {
      const url = cache.get(photoId)
      if (url) {
        URL.revokeObjectURL(url)
        cache.delete(photoId)
      }
    },
    clear(): void {
      for (const url of cache.values()) URL.revokeObjectURL(url)
      cache.clear()
    },
  }
}

const originalCache = createCache(20)
const thumbnailCache = createCache(100)

export const getCachedOriginal = originalCache.get
export const setCachedOriginal = originalCache.set
export const evictOriginal = originalCache.evict
export const clearOriginalCache = originalCache.clear

export function getCachedThumbnail(photoId: number): string | undefined {
  return thumbnailCache.get(photoId)
}

export function setCachedThumbnail(photoId: number, url: string): void {
  thumbnailCache.set(photoId, url)
}

export function evictThumbnail(photoId: number): void {
  thumbnailCache.evict(photoId)
}

export function clearThumbnailCache(): void {
  thumbnailCache.clear()
}
