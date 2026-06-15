<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { DynamicScroller, DynamicScrollerItem } from 'vue-virtual-scroller'
import { api } from '@/services/api'
import { cleanupComponent } from '@/services/storage'
import PhotoCard from '@/components/PhotoCard.vue'
import type { Photo } from '@/types/photo'
import 'vue-virtual-scroller/dist/vue-virtual-scroller.css'

interface MonthGroup {
  id: string
  label: string
  photos: Photo[]
}

const props = defineProps<{
  childId: number
  tagFilter: string | null
  dateFilter: string | null
}>()

const emit = defineEmits<{
  selectPhoto: [photo: Photo]
}>()

const groups = ref<MonthGroup[]>([])
const page = ref(1)
const hasMore = ref(true)
const loadingMore = ref(false)
const scrollerRef = ref<InstanceType<typeof DynamicScroller> | null>(null)

const flatPhotos = computed<Photo[]>(() =>
  groups.value.flatMap((g) => g.photos),
)

function groupByMonth(photos: Photo[]): MonthGroup[] {
  const map = new Map<string, Photo[]>()
  for (const photo of photos) {
    const month = photo.taken_at
      ? new Date(photo.taken_at).toLocaleString('default', {
          month: 'long',
          year: 'numeric',
        })
      : 'Unknown date'
    const key = month
    if (!map.has(key)) map.set(key, [])
    map.get(key)!.push(photo)
  }
  return [...map.entries()].map(([label, p]) => ({
    id: label,
    label,
    photos: p,
  }))
}

async function fetchPhotos(reset = false) {
  if (loadingMore.value) return
  loadingMore.value = true

  try {
    if (reset) {
      page.value = 1
      groups.value = []
      hasMore.value = true
    }

    const params: Record<string, string | number> = {
      page: page.value,
      per_page: 50,
    }
    if (props.tagFilter) params.tag = props.tagFilter
    if (props.dateFilter) params.month = props.dateFilter

    const response = await api.get(`/children/${props.childId}/photos`, { params })
    const data = response.data.data as Photo[]
    const meta = response.data.meta

    if (data.length > 0) {
      const newGroups = groupByMonth(data)
      groups.value.push(...newGroups)
      page.value++
    }

    if (meta && meta.current_page >= meta.last_page) {
      hasMore.value = false
    }
  } finally {
    loadingMore.value = false
  }
}

function onScrollEnd() {
  if (hasMore.value && !loadingMore.value) {
    fetchPhotos()
  }
}

watch(
  () => [props.tagFilter, props.dateFilter],
  () => {
    cleanupComponent('photogrid')
    fetchPhotos(true)
  },
)

onMounted(() => {
  fetchPhotos(true)
})

onBeforeUnmount(() => {
  cleanupComponent('photogrid')
})

const scrollerItems = computed(() =>
  groups.value.map((g) => ({
    id: g.id,
    data: g,
    size: Math.ceil(g.photos.length / 3) * 280 + 50,
  })),
)

function refresh(): void {
  fetchPhotos(true)
}

defineExpose({ refresh, flatPhotos })
</script>

<template>
  <DynamicScroller
    v-if="groups.length > 0"
    ref="scrollerRef"
    :items="scrollerItems"
    :min-item-size="50"
    class="scroller"
    key-field="id"
    @scroll-end="onScrollEnd"
  >
    <template #default="slotProps">
      <DynamicScrollerItem
        :item="slotProps.item"
        :active="slotProps.active"
        :size-dependencies="[(slotProps.item as any).data.photos.length]"
      >
        <div class="date-group-header">
          <span class="date-group-label">{{ (slotProps.item as any).data.label }}</span>
        </div>
        <div class="photo-grid">
          <PhotoCard
            v-for="photo in (slotProps.item as any).data.photos"
            :key="photo.id"
            :photo="photo"
            :child-id="childId"
            @click="emit('selectPhoto', photo)"
          />
        </div>
      </DynamicScrollerItem>
    </template>
  </DynamicScroller>

  <div
    v-else-if="!loadingMore"
    class="feed-empty"
  >
    <div class="empty-icon">
      <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
        <circle cx="8.5" cy="8.5" r="1.5"/>
        <polyline points="21 15 16 10 5 21"/>
      </svg>
    </div>
    <p class="mt-4 text-slate-500 text-lg font-medium">No photos yet</p>
    <p class="text-slate-400 text-sm mt-1">
      Upload some photos to get started
    </p>
  </div>

  <div v-if="loadingMore" class="flex justify-center py-8">
    <span class="spinner-large" />
  </div>
</template>

<style scoped>
.scroller {
  height: calc(100vh - 200px);
}
</style>
