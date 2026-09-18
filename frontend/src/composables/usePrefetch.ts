import { useFeedStore } from '@/stores/feed'
import { useCommentsStore } from '@/stores/comments'
import { warmCache } from '@/lib/cache/decrypted-cache'
import { getPEKForPhoto, fetchAndDecrypt } from '@/services/storage'
import { api } from '@/services/api'
import type { Photo } from '@/types/photo'

export function usePrefetch() {
  const feedStore = useFeedStore()
  let prefetching = false

  async function prefetchNextPage() {
    if (prefetching || !feedStore.hasMore) return
    prefetching = true

    try {
      const params: Record<string, string> = {}
      const { filters } = feedStore
      if (filters.childIds.length > 0) params.children = filters.childIds.join(',')
      if (filters.tags.length > 0) params.tags = filters.tags.join(',')
      if (filters.dateFrom) params.date_from = filters.dateFrom
      if (filters.dateTo) params.date_to = filters.dateTo
      params.page = String(feedStore.page + 1)

      const response = await api.get('/photos', { params })
      const data = response.data.data as Photo[]

      await warmCache(
        data.map((photo) => ({
          id: photo.id,
          fetchAndDecrypt: async () => {
            const pek = await getPEKForPhoto(photo.children)
            if (!pek) throw new Error('No PEK')
            const decrypted = await fetchAndDecrypt(
              photo.thumbnail_url!,
              pek,
              photo.iv_thumbnail,
            )
            return new Blob([decrypted], { type: 'image/webp' })
          },
        })),
      )

      try {
        void useCommentsStore().prefetchComments(data)
      } catch {
        // comments warm-up must never break thumbnail prefetch
      }
    } catch {
      // ponytail: best-effort prefetch
    } finally {
      prefetching = false
    }
  }

  return { prefetchNextPage }
}
