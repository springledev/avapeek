import { defineStore } from 'pinia'
import { ref, reactive } from 'vue'
import { api } from '@/services/api'
import { useTagsStore } from '@/stores/tags'
import type { ApiPhoto, Photo, PhotoListResponse } from '@/types/photo'

/** A prefetched page is dropped after this long, so a stale page is never shown. */
const PREFETCH_TTL_MS = 60_000

export interface FeedFilters {
  childIds: number[]
  /** Logical (decrypted, normalised-grouped) tag names; resolved to opaque ids for the API. */
  tags: string[]
  dateFrom: string | null
  dateTo: string | null
}

export const useFeedStore = defineStore('feed', () => {
  const filters = reactive<FeedFilters>({
    childIds: [],
    tags: [],
    dateFrom: null,
    dateTo: null,
  })

  const photos = ref<Photo[]>([])
  const page = ref(1)
  const hasMore = ref(true)
  const isLoading = ref(false)
  /** True when the last fetch failed. Photos already loaded are kept, so a failed refresh never empties the screen. */
  const loadFailed = ref(false)

  /** Requests currently on the wire, by query string, so identical callers share one. */
  const inFlight = new Map<string, Promise<PhotoListResponse>>()
  /** Pages fetched ahead of time and not yet used; a page is served once, then dropped. */
  const prefetched = new Map<string, { response: PhotoListResponse; at: number }>()

  /**
   * Query params for a feed page. Tag names never leave the client: they are resolved
   * to opaque tag ids. A tag filter that matches no known tag yields no photos (id 0).
   */
  function buildParams(pageNumber: number): Record<string, string> {
    const params: Record<string, string> = {}
    if (filters.childIds.length > 0) {
      params.children = filters.childIds.join(',')
    }
    if (filters.tags.length > 0) {
      const ids = useTagsStore().resolveIds(filters.tags)
      params.tag_ids = ids.length > 0 ? ids.join(',') : '0'
    }
    if (filters.dateFrom) {
      params.date_from = filters.dateFrom
    }
    if (filters.dateTo) {
      params.date_to = filters.dateTo
    }
    params.page = String(pageNumber)
    return params
  }

  function pageKey(pageNumber: number): string {
    return new URLSearchParams(buildParams(pageNumber)).toString()
  }

  function requestPage(pageNumber: number): Promise<PhotoListResponse> {
    const key = pageKey(pageNumber)
    const pending = inFlight.get(key)
    if (pending) return pending

    const request = api
      .get<PhotoListResponse>('/photos', { params: buildParams(pageNumber) })
      .then((response) => response.data)
      .finally(() => inFlight.delete(key))
    inFlight.set(key, request)
    return request
  }

  /**
   * Loads one page. `reuse` lets a caller share a request already on the wire or a
   * prefetched page. Callers that follow a change (delete, upload, filter) leave it off and
   * always get a fresh page; that also drops every prefetched page, which may be stale.
   */
  async function loadPage(pageNumber: number, reuse: boolean): Promise<PhotoListResponse> {
    if (!reuse) {
      prefetched.clear()
      inFlight.clear()
      return requestPage(pageNumber)
    }

    const key = pageKey(pageNumber)
    const ready = prefetched.get(key)
    if (ready) {
      prefetched.delete(key)
      if (Date.now() - ready.at < PREFETCH_TTL_MS) return ready.response
    }
    return requestPage(pageNumber)
  }

  /** Fetches the next page into the cache for `fetchMore` to use; resolves with its photos. */
  async function prefetchNextPage(): Promise<ApiPhoto[]> {
    const key = pageKey(page.value + 1)
    const ready = prefetched.get(key)
    if (ready && Date.now() - ready.at < PREFETCH_TTL_MS) return ready.response.data
    const response = await requestPage(page.value + 1)
    prefetched.set(key, { response, at: Date.now() })
    return response.data
  }

  async function fetchPhotos(reset = true, reuse = false): Promise<void> {
    if (reset) {
      page.value = 1
      hasMore.value = true
    }

    isLoading.value = true
    loadFailed.value = false

    try {
      const response = await loadPage(page.value, reuse)
      const decrypted = await useTagsStore().decryptPhotoTags(response.data)

      if (reset) {
        photos.value = decrypted
      } else {
        photos.value.push(...decrypted)
      }

      hasMore.value = response.meta.current_page < response.meta.last_page
    } catch (err) {
      console.error('Failed to fetch feed photos:', err)
      loadFailed.value = true
      if (!reset) page.value--
    } finally {
      isLoading.value = false
    }
  }

  async function fetchMore(): Promise<void> {
    if (!hasMore.value || isLoading.value) return
    page.value++
    await fetchPhotos(false, true)
  }

  function resetFilters(): void {
    filters.childIds = []
    filters.tags = []
    filters.dateFrom = null
    filters.dateTo = null
  }

  function setChildFilter(childIds: number[]): void {
    filters.childIds = childIds
  }

  function setTagFilter(tags: string[]): void {
    filters.tags = tags
  }

  function setDateRange(from: string | null, to: string | null): void {
    filters.dateFrom = from
    filters.dateTo = to
  }

  return {
    filters,
    photos,
    page,
    hasMore,
    isLoading,
    loadFailed,
    buildParams,
    prefetchNextPage,
    fetchPhotos,
    fetchMore,
    resetFilters,
    setChildFilter,
    setTagFilter,
    setDateRange,
  }
})
