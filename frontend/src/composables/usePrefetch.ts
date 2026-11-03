import { useFeedStore } from '@/stores/feed'
import { warmCache } from '@/lib/cache/decrypted-cache'
import { getPEKForPhoto, fetchAndDecrypt } from '@/services/storage'

export function usePrefetch() {
  const feedStore = useFeedStore()
  let prefetching = false

  async function prefetchNextPage() {
    if (prefetching || !feedStore.hasMore) return
    prefetching = true

    try {
      // Kept in the feed store, so scrolling to this page does not request it again.
      const data = await feedStore.prefetchNextPage() // thumbnail fields only; tags are not decrypted here

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
    } catch {
      // ponytail: best-effort prefetch
    } finally {
      prefetching = false
    }
  }

  return { prefetchNextPage }
}
