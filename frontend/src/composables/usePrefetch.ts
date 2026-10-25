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
      const params = feedStore.buildParams(feedStore.page + 1)

      const response = await api.get('/photos', { params })
      const data = response.data.data as Photo[] // thumbnail fields only; tags are not decrypted here

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
