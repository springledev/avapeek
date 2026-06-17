<script setup lang="ts">
import { onMounted, computed } from 'vue'
import { useRecentsStore } from '@/stores/recents'
import RecentUploadGroup from '@/components/RecentUploadGroup.vue'

const recentsStore = useRecentsStore()

const grouped = computed(() => {
  const groups: { key: string; uploaderName: string; childName: string; childColor: string | null; relativeTime: string; photos: typeof recentsStore.photos }[] = []
  const map = new Map<string, typeof recentsStore.photos>()

  for (const photo of recentsStore.photos) {
    const uploader = photo.uploader_name || 'Someone'
    const child = photo.children?.[0]
    const childName = child?.name || 'Unknown'
    const date = new Date(photo.created_at).toLocaleDateString()
    const key = `${uploader}-${date}-${childName}`

    if (!map.has(key)) map.set(key, [])
    map.get(key)!.push(photo)
  }

  for (const [key, photos] of map) {
    const first = photos[0]!
    const child = first.children?.[0]
    const offset = Math.floor((Date.now() - new Date(first.created_at).getTime()) / 3600000)
    const relativeTime = offset < 1 ? 'Just now' : offset < 24 ? `${offset}h ago` : `${Math.floor(offset / 24)}d ago`

    groups.push({
      key,
      uploaderName: first.uploader_name || 'Someone',
      childName: child?.name || 'Unknown',
      childColor: child?.avatar_color || null,
      relativeTime,
      photos,
    })
  }

  return groups
})

onMounted(async () => {
  await recentsStore.fetchRecents()
  await recentsStore.markAsSeen()
})
</script>

<template>
  <div>
    <div class="mb-6">
      <div class="flex items-center gap-3">
        <h1 class="text-2xl font-bold text-slate-900 dark:text-white">Recent Uploads</h1>
        <span
          v-if="recentsStore.unseenCount > 0"
          class="rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-700 dark:bg-red-900/30 dark:text-red-400"
        >
          {{ recentsStore.unseenCount }} new
        </span>
      </div>
    </div>

    <div v-if="recentsStore.isLoading && recentsStore.photos.length === 0" class="space-y-4">
      <div v-for="i in 3" :key="i" class="h-36 animate-pulse rounded-xl bg-slate-200 dark:bg-slate-700" />
    </div>

    <div v-else-if="recentsStore.photos.length === 0" class="py-16 text-center">
      <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-slate-100 dark:bg-slate-700">
        <svg class="h-8 w-8 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
      </div>
      <p class="text-lg font-medium text-slate-900 dark:text-white">No recent uploads</p>
      <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Upload a photo and it will appear here.</p>
    </div>

    <div v-else class="space-y-4">
      <RecentUploadGroup
        v-for="group in grouped"
        :key="group.key"
        :photos="group.photos"
        :uploader-name="group.uploaderName"
        :child-name="group.childName"
        :child-color="group.childColor"
        :relative-time="group.relativeTime"
      />
    </div>

    <div v-if="recentsStore.isLoading && recentsStore.photos.length > 0" class="mt-4 flex justify-center">
      <div class="h-6 w-6 animate-spin rounded-full border-2 border-indigo-600 border-t-transparent" />
    </div>
  </div>
</template>
