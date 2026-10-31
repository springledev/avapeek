<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { getThumbnail } from '@/lib/cache/decrypted-cache'
import { getPEKForPhoto, fetchAndDecrypt, cleanupComponent } from '@/services/storage'
import { decryptBlob, blobToObjectURL } from '@/crypto/encrypt'
import { base64ToArrayBuffer } from '@/lib/util'
import { api } from '@/services/api'
import { Lock } from '@lucide/vue'
import { useChildStore } from '@/stores/child'
import { MSG } from '@/lib/messages'
import TagChip from '@/components/TagChip.vue'
import { dedupeTagsByName } from '@/lib/tags'
import type { Photo } from '@/types/photo'

const props = defineProps<{
  photo: Photo
}>()

const emit = defineEmits<{
  click: []
}>()

const lqipUrl = ref<string | null>(null)
const thumbnailUrl = ref<string | null>(null)
const thumbnailLoaded = ref(false)
const isLoading = ref(false)
const error = ref(false)
const observed = ref(false)
const elRef = ref<HTMLElement | null>(null)
const childStore = useChildStore()
const isLocked = computed(() => !thumbnailUrl.value && childStore.isPhotoLocked(props.photo))
const componentKey = `photocard-${props.photo.id}`
let observer: IntersectionObserver | null = null

async function decryptLQIP() {
  if (lqipUrl.value || !props.photo.lqip || !props.photo.iv_lqip) return
  try {
    const pek = await getPEKForPhoto(props.photo.children)
    if (!pek) return
    const decrypted = await decryptBlob(
      base64ToArrayBuffer(props.photo.lqip),
      pek,
      props.photo.iv_lqip,
    )
    lqipUrl.value = blobToObjectURL(decrypted, 'image/webp')
  } catch { /* LQIP is best-effort */ }
}

async function decryptThumbnail() {
  if (thumbnailUrl.value || isLoading.value || error.value) return
  isLoading.value = true
  try {
    thumbnailUrl.value = await getThumbnail(props.photo.id, async () => {
      const pek = await getPEKForPhoto(props.photo.children)
      if (!pek) throw new Error('No PEK')
      let presignedUrl = props.photo.thumbnail_url
      if (!presignedUrl) {
        const response = await api.get(`/photos/${props.photo.id}/thumbnail`)
        presignedUrl = response.data.data.presigned_url as string
      }
      const decrypted = await fetchAndDecrypt(presignedUrl, pek, props.photo.iv_thumbnail)
      return new Blob([decrypted], { type: 'image/webp' })
    })
  } catch {
    error.value = true
  } finally {
    isLoading.value = false
  }
}

async function load() {
  decryptLQIP()
  await decryptThumbnail()
}

onMounted(() => {
  if (props.photo.thumbnail_url) {
    observed.value = true
    load()
    return
  }
  if (!elRef.value) return
  observer = new IntersectionObserver(
    (entries) => {
      if (entries[0]?.isIntersecting && !observed.value) {
        observed.value = true
        load()
        observer?.disconnect()
      }
    },
    { rootMargin: '300px' },
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
      v-if="lqipUrl"
      :src="lqipUrl"
      class="photo-card-lqip"
      :class="{ faded: thumbnailLoaded }"
      alt=""
    />
    <img
      v-if="thumbnailUrl"
      :src="thumbnailUrl"
      class="photo-card-thumb"
      @load="thumbnailLoaded = true"
      alt=""
    />
    <div
      v-if="isLocked"
      class="photo-card-placeholder"
      role="img"
      :aria-label="MSG.tileLocked"
      data-test="tile-locked"
    >
      <Lock :size="24" aria-hidden="true" />
    </div>
    <div v-else-if="!thumbnailUrl && !error" class="photo-card-placeholder">
      <span class="skeleton w-8 h-8" />
    </div>
    <div v-else-if="error" class="photo-card-placeholder">
      <span class="text-xs text-slate-400">Failed</span>
    </div>

    <div v-if="photo.tags && photo.tags.length > 0" class="photo-card-footer">
      <div class="photo-card-tags">
        <TagChip
          v-for="tag in dedupeTagsByName(photo.tags).slice(0, 2)"
          :key="tag.id"
          :name="tag.name"
        />
      </div>
    </div>
  </div>
</template>

<style scoped>
.photo-card {
  position: relative;
  overflow: hidden;
  background: #f0f0f0;
}

.photo-card-lqip {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  filter: blur(20px);
  transform: scale(1.1);
  transition: opacity 0.3s ease;
}
.photo-card-lqip.faded {
  opacity: 0;
}

.photo-card-thumb {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  opacity: 0;
  transition: opacity 0.3s ease;
}
.photo-card-thumb[src] {
  opacity: 1;
}
</style>
