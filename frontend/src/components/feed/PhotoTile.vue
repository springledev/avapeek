<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, computed } from 'vue'
import { MessageCircle, Check, Lock } from '@lucide/vue'
import { useLazyDecrypt } from '@/composables/useLazyDecrypt'
import { useMotionPolicy } from '@/composables/useMotionPolicy'
import { LONG_PRESS_MS } from '@/composables/useSelection'
import { useChildStore } from '@/stores/child'
import UiAvatar from '@/components/ui/UiAvatar.vue'
import { MSG } from '@/lib/messages'
import type { Photo } from '@/types/photo'

const props = defineProps<{
  photo: Photo
  /** Selection mode is on: the tile shows a checkbox and a tap toggles. */
  selectable?: boolean
  selected?: boolean
}>()

const emit = defineEmits<{
  (e: 'click'): void
  (e: 'longpress'): void
}>()

const MOVE_TOLERANCE_PX = 10
let pressTimer: ReturnType<typeof setTimeout> | null = null
let pressOrigin: { x: number; y: number } | null = null
let longPressFired = false

function cancelPress() {
  if (pressTimer) clearTimeout(pressTimer)
  pressTimer = null
  pressOrigin = null
}

function onPointerDown(event: PointerEvent) {
  if (event.pointerType === 'mouse' || props.selectable) return
  longPressFired = false
  pressOrigin = { x: event.clientX, y: event.clientY }
  pressTimer = setTimeout(() => {
    pressTimer = null
    longPressFired = true
    emit('longpress')
  }, LONG_PRESS_MS)
}

function onPointerMove(event: PointerEvent) {
  if (!pressOrigin) return
  if (
    Math.abs(event.clientX - pressOrigin.x) > MOVE_TOLERANCE_PX ||
    Math.abs(event.clientY - pressOrigin.y) > MOVE_TOLERANCE_PX
  ) {
    cancelPress()
  }
}

function onClick() {
  // The click that ends a long press must not also open the photo.
  if (longPressFired) {
    longPressFired = false
    return
  }
  emit('click')
}

function onContextMenu(event: Event) {
  if (props.selectable || pressTimer || longPressFired) event.preventDefault()
}

const { observePhoto, unobserve } = useLazyDecrypt()
const { resolve } = useMotionPolicy()

const childStore = useChildStore()
/** No album of this photo has a key for the user yet: say so instead of a blank tile. */
const isLocked = computed(() => !thumbnailUrl.value && childStore.isPhotoLocked(props.photo))

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
  cancelPress()
  if (elRef.value) unobserve(elRef.value)
})
</script>

<template>
  <button
    ref="elRef"
    type="button"
    class="tile u-focus-ring"
    :class="{ 'tile--selected': selected }"
    :aria-label="isLocked ? MSG.tileLocked : undefined"
    :aria-pressed="selectable ? !!selected : undefined"
    @click="onClick"
    @pointerdown="onPointerDown"
    @pointermove="onPointerMove"
    @pointerup="cancelPress"
    @pointercancel="cancelPress"
    @pointerleave="cancelPress"
    @contextmenu="onContextMenu"
  >
    <img
      v-if="thumbnailUrl"
      :src="thumbnailUrl"
      :class="motionClass"
      class="tile__img"
      alt=""
      @load="onImgLoad"
    />
    <div v-else-if="isLocked" class="tile__locked" data-test="tile-locked" aria-hidden="true">
      <Lock :size="24" />
    </div>
    <div v-else class="tile__placeholder" aria-hidden="true" />

    <span
      v-if="selectable"
      class="tile__check"
      :class="{ 'tile__check--on': selected }"
      data-test="tile-check"
      aria-hidden="true"
    >
      <Check v-if="selected" :size="16" :stroke-width="3" />
    </span>

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
  -webkit-touch-callout: none;
  user-select: none;
  touch-action: manipulation;
}

.tile--selected {
  outline: 3px solid var(--color-accent);
  outline-offset: -3px;
}

.tile__check {
  position: absolute;
  top: var(--space-2);
  left: var(--space-2);
  z-index: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 24px;
  height: 24px;
  border: 2px solid #fff;
  border-radius: 50%;
  background: var(--color-overlay);
  color: var(--color-on-accent);
}

.tile__check--on {
  background: var(--color-accent);
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

.tile__locked {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--color-surface-2);
  color: var(--color-ink-soft);
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
