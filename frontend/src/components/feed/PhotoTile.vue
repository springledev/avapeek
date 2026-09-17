<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, computed } from 'vue'
import { MessageCircle } from '@lucide/vue'
import { useLazyDecrypt } from '@/composables/useLazyDecrypt'
import { useMotionPolicy } from '@/composables/useMotionPolicy'
import UiAvatar from '@/components/ui/UiAvatar.vue'
import type { Photo } from '@/types/photo'

const props = defineProps<{
  photo: Photo
}>()

const emit = defineEmits<{
  (e: 'click'): void
}>()

const { observePhoto, unobserve } = useLazyDecrypt()
const { resolve } = useMotionPolicy()

const elRef = ref<HTMLElement | null>(null)
const thumbnailUrl = ref<string | null>(null)
const motionClass = ref('')
const startTs = ref(0)

const visibleChildren = computed(() => props.photo.children.slice(0, 3))
const commentCount = computed(() => props.photo.comment_count ?? 0)

function onImgLoad() {
  motionClass.value = resolve(startTs.value)
}

onMounted(() => {
  if (!elRef.value) return
  startTs.value = performance.now()
  observePhoto(elRef.value, props.photo, (url) => {
    thumbnailUrl.value = url
  })
})

onBeforeUnmount(() => {
  if (elRef.value) unobserve(elRef.value)
})
</script>

<template>
  <button ref="elRef" type="button" class="tile u-focus-ring" @click="emit('click')">
    <img
      v-if="thumbnailUrl"
      :src="thumbnailUrl"
      :class="motionClass"
      class="tile__img"
      alt=""
      @load="onImgLoad"
    />
    <div v-else class="tile__placeholder" aria-hidden="true" />

    <div v-if="visibleChildren.length > 0" class="tile__kids">
      <UiAvatar
        v-for="child in visibleChildren"
        :key="child.id"
        :id="child.id"
        :name="child.name"
        :color-hex="child.avatar_color"
        :size="24"
      />
    </div>

    <div
      v-if="commentCount > 0"
      class="tile__badge"
      :aria-label="`${commentCount} ${commentCount === 1 ? 'comment' : 'comments'}`"
    >
      <MessageCircle :size="14" aria-hidden="true" />
      <span>{{ commentCount }}</span>
    </div>
  </button>
</template>

<style scoped>
.tile {
  position: relative;
  display: block;
  width: 100%;
  aspect-ratio: 1;
  border: 0;
  padding: 0;
  border-radius: var(--radius-photo);
  overflow: hidden;
  cursor: pointer;
  background: var(--color-surface-2);
}

.tile__img {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.tile__placeholder {
  position: absolute;
  inset: 0;
  background: var(--color-surface-2);
  animation: pulse 1.5s ease-in-out infinite;
}

.tile__kids {
  position: absolute;
  bottom: var(--space-2);
  left: var(--space-2);
  display: flex;
  gap: 2px;
}

.tile__badge {
  position: absolute;
  bottom: var(--space-2);
  right: var(--space-2);
  display: flex;
  align-items: center;
  gap: var(--space-1);
  padding: 2px var(--space-2);
  border-radius: var(--radius-pill);
  background: var(--color-overlay);
  color: #fff;
  font-size: var(--text-meta);
  font-weight: 700;
  line-height: var(--lh-tight);
}

@media (prefers-reduced-motion: reduce) {
  .tile__placeholder {
    animation: none;
  }
}

@keyframes pulse {
  0%,
  100% {
    opacity: 1;
  }
  50% {
    opacity: 0.5;
  }
}
</style>
