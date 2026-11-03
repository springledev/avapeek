import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '@/services/api'
import { useTagsStore } from '@/stores/tags'
import type { Photo, PhotoListResponse, UnseenCountResponse, MarkSeenResponse } from '@/types/photo'

export const useRecentsStore = defineStore('recents', () => {
  const photos = ref<Photo[]>([])
  const unseenCount = ref(0)
  const page = ref(1)
  const hasMore = ref(true)
  const isLoading = ref(false)
  /** True when the last fetch failed. Photos already loaded are kept, so a failed refresh never empties the screen. */
  const loadFailed = ref(false)

  async function fetchRecents(reset = true): Promise<void> {
    if (reset) {
      page.value = 1
      hasMore.value = true
    }

    isLoading.value = true
    loadFailed.value = false

    try {
      const response = await api.get<PhotoListResponse>('/recents', {
        params: { page: page.value },
      })

      const decrypted = await useTagsStore().decryptPhotoTags(response.data.data)

      if (reset) {
        photos.value = decrypted
      } else {
        photos.value.push(...decrypted)
      }

      hasMore.value = response.data.meta.current_page < response.data.meta.last_page
    } catch (err) {
      console.error('Failed to fetch recents:', err)
      loadFailed.value = true
      if (!reset) page.value--
    } finally {
      isLoading.value = false
    }
  }

  async function fetchUnseenCount(): Promise<void> {
    try {
      const response = await api.get<UnseenCountResponse>('/recents/unseen-count')
      unseenCount.value = response.data.data.unseen_count
    } catch (err) {
      console.error('Failed to fetch unseen count:', err)
    }
  }

  async function markAsSeen(): Promise<void> {
    try {
      await api.put<MarkSeenResponse>('/user/seen')
      unseenCount.value = 0
    } catch (err) {
      console.error('Failed to mark as seen:', err)
    }
  }

  return {
    photos,
    unseenCount,
    page,
    hasMore,
    isLoading,
    loadFailed,
    fetchRecents,
    fetchUnseenCount,
    markAsSeen,
  }
})
