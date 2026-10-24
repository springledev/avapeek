import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '@/services/api'
import type { Photo } from '@/types/photo'

interface MemoriesResponse {
  data: Photo[]
  meta: { total: number }
}

export const useMemoriesStore = defineStore('memories', () => {
  const photos = ref<Photo[]>([])
  const isLoading = ref(false)
  const hasError = ref(false)

  async function fetchMemories(date: string, tz: string): Promise<void> {
    isLoading.value = true
    hasError.value = false

    try {
      const response = await api.get<MemoriesResponse>('/memories', { params: { date, tz } })
      photos.value = response.data.data
    } catch (err) {
      console.error('Failed to fetch memories:', err)
      photos.value = []
      hasError.value = true
    } finally {
      isLoading.value = false
    }
  }

  return { photos, isLoading, hasError, fetchMemories }
})
