import { defineStore } from 'pinia'
import { ref, reactive } from 'vue'
import { api } from '@/services/api'
import { useTagsStore } from '@/stores/tags'
import type { Photo, PhotoListResponse } from '@/types/photo'

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

  async function fetchPhotos(reset = true): Promise<void> {
    if (reset) {
      page.value = 1
      hasMore.value = true
    }

    isLoading.value = true
    loadFailed.value = false

    try {
      const response = await api.get<PhotoListResponse>('/photos', {
        params: buildParams(page.value),
      })
      const decrypted = await useTagsStore().decryptPhotoTags(response.data.data)

      if (reset) {
        photos.value = decrypted
      } else {
        photos.value.push(...decrypted)
      }

      hasMore.value = response.data.meta.current_page < response.data.meta.last_page
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
    await fetchPhotos(false)
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
    fetchPhotos,
    fetchMore,
    resetFilters,
    setChildFilter,
    setTagFilter,
    setDateRange,
  }
})
