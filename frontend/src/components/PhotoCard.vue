<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { fetchThumbnail, cleanupComponent } from '@/services/storage'
import { useChildStore } from '@/stores/child'
import TagChip from '@/components/TagChip.vue'
import type { Photo } from '@/types/photo'

const props = defineProps<{
  photo: Photo
  childId: number
}>()

const emit = defineEmits<{
  click: []
}>()

const childStore = useChildStore()

const thumbnailUrl = ref<string | null>(null)
const isLoading = ref(false)
const error = ref(false)
const observed = ref(false)
const elRef = ref<HTMLElement | null>(null)
const componentKey = `photocard-${props.photo.id}`
let observer: IntersectionObserver | null = null

async function decryptThumbnail() {
  if (thumbnailUrl.value || isLoading.value || error.value) return
  isLoading.value = true
  try {
    const dek = await childStore.ensureDEK(props.childId)
    thumbnailUrl.value = await fetchThumbnail(
      props.childId,
      props.photo.id,
      'lg',
      dek,
      props.photo.iv_lg,
      componentKey,
    )
  } catch {
    error.value = true
  } finally {
    isLoading.value = false
  }
}

onMounted(() => {
  if (!elRef.value) return
  observer = new IntersectionObserver(
    (entries) => {
      if (entries[0]?.isIntersecting && !observed.value) {
        observed.value = true
        decryptThumbnail()
        observer?.disconnect()
      }
    },
    { rootMargin: '200px' },
  )
  observer.observe(elRef.value)
})

onBeforeUnmount(() => {
  observer?.disconnect()
  cleanupComponent(componentKey)
})
</script>

<template>
  <div ref="elRef" class="photo-card aspect-square" @click="emit('click')">
    <img
      v-if="thumbnailUrl"
      :src="thumbnailUrl"
      class="photo-card-img"
      alt=""
      loading="lazy"
    />
    <div v-else class="photo-card-placeholder">
      <span v-if="error" class="text-xs text-slate-400">Failed</span>
      <span v-else class="skeleton w-8 h-8" />
    </div>

    <div v-if="photo.tags && photo.tags.length > 0" class="photo-card-footer">
      <div class="photo-card-tags">
        <TagChip
          v-for="tag in photo.tags.slice(0, 2)"
          :key="tag.id"
          :name="tag.name"
        />
      </div>
    </div>
  </div>
</template>
