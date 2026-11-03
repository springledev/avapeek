import { describe, it, expect, vi, beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import type { AxiosResponse } from 'axios'

vi.mock('@/services/api', () => ({
  api: {
    get: vi.fn<(url: string, config?: unknown) => Promise<unknown>>(),
  },
}))

import { api } from '@/services/api'
import { useFeedStore } from '@/stores/feed'
import { useTagsStore } from '@/stores/tags'
import type { Photo } from '@/types/photo'

function pageResponse(page: number): AxiosResponse {
  const photo = { id: page, tags: [] } as unknown as Photo
  return {
    data: { data: [photo], meta: { current_page: page, last_page: 3, per_page: 1, total: 3 } },
  } as unknown as AxiosResponse
}

describe('feed page requests', () => {
  let sentChildren: Array<string | undefined> = []

  beforeEach(() => {
    setActivePinia(createPinia())
    sentChildren = []
    vi.mocked(api.get).mockReset()
    vi.mocked(api.get).mockImplementation(async (_url, config) => {
      const params = (config as { params: Record<string, string> }).params
      sentChildren.push(params.children)
      return pageResponse(Number(params.page))
    })
    vi.spyOn(useTagsStore(), 'decryptPhotoTags').mockImplementation(async (photos) => photos as unknown as Photo[])
  })

  it('shares one request between callers that load the first page together', async () => {
    const feed = useFeedStore()
    await Promise.all([feed.fetchPhotos(true, true), feed.fetchPhotos(true, true)])

    expect(api.get).toHaveBeenCalledTimes(1)
    expect(feed.photos).toHaveLength(1)
  })

  it('does not request a page again once it was prefetched', async () => {
    const feed = useFeedStore()
    await feed.fetchPhotos()
    const prefetched = await feed.prefetchNextPage()
    await feed.prefetchNextPage()
    await feed.fetchMore()

    expect(prefetched.map((p) => p.id)).toEqual([2])
    expect(api.get).toHaveBeenCalledTimes(2)
    expect(feed.photos.map((p) => p.id)).toEqual([1, 2])
  })

  it('serves a prefetched page only once', async () => {
    const feed = useFeedStore()
    await feed.fetchPhotos()
    await feed.prefetchNextPage()
    await feed.fetchMore()
    feed.page = 1
    feed.hasMore = true
    await feed.fetchMore()

    expect(api.get).toHaveBeenCalledTimes(3)
  })

  it('fetches fresh data after a change instead of reusing a request or prefetched page', async () => {
    const feed = useFeedStore()
    await feed.fetchPhotos()
    await feed.prefetchNextPage()

    await feed.fetchPhotos() // e.g. after deleting a photo
    await feed.fetchMore()

    // page 1, prefetched page 2, page 1 again, then page 2 again (the prefetched copy was dropped)
    expect(api.get).toHaveBeenCalledTimes(4)
  })

  it('does not reuse a prefetched page after its time is up', async () => {
    vi.useFakeTimers()
    try {
      const feed = useFeedStore()
      await feed.fetchPhotos()
      await feed.prefetchNextPage()
      vi.advanceTimersByTime(61_000)
      await feed.fetchMore()

      expect(api.get).toHaveBeenCalledTimes(3)
    } finally {
      vi.useRealTimers()
    }
  })

  it('keys prefetched pages by filters', async () => {
    const feed = useFeedStore()
    await feed.fetchPhotos()
    await feed.prefetchNextPage()
    feed.setChildFilter([5])
    await feed.fetchPhotos()
    await feed.fetchMore()

    expect(sentChildren.at(-1)).toBe('5')
    expect(api.get).toHaveBeenCalledTimes(4)
  })
})
