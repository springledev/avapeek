import { computed } from 'vue'
import { useMemoriesStore } from '@/stores/memories'
import type { Photo } from '@/types/photo'

export interface MemoryGroup {
  year: number
  photos: Photo[]
}

/** Viewer-local calendar date as `YYYY-MM-DD`. */
export function localDateString(now: Date = new Date()): string {
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')
  return `${now.getFullYear()}-${month}-${day}`
}

export function useMemories() {
  const store = useMemoriesStore()

  const groups = computed<MemoryGroup[]>(() => {
    const byYear = new Map<number, Photo[]>()
    for (const photo of store.photos) {
      if (!photo.taken_at) continue
      const year = new Date(photo.taken_at).getFullYear()
      const list = byYear.get(year)
      if (list) {
        list.push(photo)
      } else {
        byYear.set(year, [photo])
      }
    }
    return [...byYear.entries()].sort(([a], [b]) => b - a).map(([year, photos]) => ({ year, photos }))
  })

  const isVisible = computed(
    () => !store.isLoading && !store.hasError && groups.value.length > 0,
  )

  async function load(): Promise<void> {
    const tz = Intl.DateTimeFormat().resolvedOptions().timeZone
    await store.fetchMemories(localDateString(), tz)
  }

  return { groups, isVisible, load }
}
