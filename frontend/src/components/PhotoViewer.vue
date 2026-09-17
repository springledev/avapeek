<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, watch, computed, nextTick } from 'vue'
import {
  X,
  ChevronLeft,
  ChevronRight,
  Minus,
  Plus,
  Download,
  Heart,
  Pencil,
  AlertTriangle,
  Send,
  Trash2,
} from '@lucide/vue'
import { fetchOriginal, fetchThumbnail, getPEKForPhoto, cleanupComponent } from '@/services/storage'
import { useMotionPolicy } from '@/composables/useMotionPolicy'
import { useChildStore } from '@/stores/child'
import { useCommentsStore, MAX_COMMENT_LENGTH } from '@/stores/comments'
import { useAuthStore } from '@/stores/auth'
import UiAvatar from '@/components/ui/UiAvatar.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiSheet from '@/components/ui/UiSheet.vue'
import UiEmptyState from '@/components/ui/UiEmptyState.vue'
import { MSG } from '@/lib/messages'
import type { Photo, DecryptedComment } from '@/types/photo'
import type { TagItem } from '@/components/TagSidebar.vue'

const props = defineProps<{
  photo: Photo
  hasPrev: boolean
  hasNext: boolean
  canDelete: boolean
  canEdit: boolean
  childBirthDate: string | null
  allTags: TagItem[]
}>()

const emit = defineEmits<{
  close: []
  prev: []
  next: []
  delete: [photoId: number]
  updateDate: [photoId: number, takenAt: string]
  tagAdded: [photoId: number, tagName: string]
  tagRemoved: [photoId: number, tagId: number]
}>()

const childStore = useChildStore()
const commentsStore = useCommentsStore()
const authStore = useAuthStore()
const { reducedMotion, resolve } = useMotionPolicy()

const thumbnailUrl = ref<string | null>(null)
const originalUrl = ref<string | null>(null)
const thumbnailMotion = ref('')
const originalMotion = ref('')
const isLoading = ref(true)
const hasError = ref(false)
const showChrome = ref(true)
const showInfo = ref(false)
const showEditSheet = ref(false)
const zoom = ref(1)
const panX = ref(0)
const panY = ref(0)
const touchStartX = ref(0)
const touchStartY = ref(0)
const pinchStartDist = ref(0)
const pinchStartZoom = ref(1)
const lastTapTime = ref(0)
const chromeTimer = ref<number | null>(null)
const componentKey = 'photoviewer-new'
const thumbStartTs = ref(0)
const origStartTs = ref(0)

const currentPek = ref<CryptoKey | null>(null)
const newComment = ref('')
const commentListEl = ref<HTMLElement | null>(null)
let commentErrorTimer: number | null = null

const photoComments = computed(() => commentsStore.commentsFor(props.photo.id))

const isAdmin = computed(() =>
  childStore.children.some((c) => c.role === 'owner' || c.role === 'admin'),
)

const visibleChildren = computed(() => props.photo.children.slice(0, 3))

function resetChromeTimer() {
  if (chromeTimer.value) clearTimeout(chromeTimer.value)
  chromeTimer.value = window.setTimeout(() => {
    if (zoom.value === 1) showChrome.value = false
  }, 2000)
}

function onInteraction() {
  showChrome.value = true
  resetChromeTimer()
}

function onThumbLoad() {
  thumbnailMotion.value = resolve(thumbStartTs.value)
}

function onOriginalLoad() {
  originalMotion.value = resolve(origStartTs.value)
}

function downloadImage() {
  const url = originalUrl.value || thumbnailUrl.value
  if (!url) return
  const a = document.createElement('a')
  a.href = url
  a.download = `photo-${props.photo.id}.${props.photo.original_mime.split('/')[1] || 'jpg'}`
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
}

function zoomIn() {
  zoom.value = Math.min(zoom.value + 0.5, 4)
}
function zoomOut() {
  zoom.value = Math.max(zoom.value - 0.5, 1)
}
function resetZoom() {
  zoom.value = 1
  panX.value = 0
  panY.value = 0
}

function onDblTap() {
  if (zoom.value === 1) {
    zoom.value = 2
  } else {
    resetZoom()
  }
}

function onTouchStart(event: TouchEvent) {
  onInteraction()
  if (event.touches.length === 1) {
    touchStartX.value = event.touches[0]!.clientX
    touchStartY.value = event.touches[0]!.clientY
    const now = Date.now()
    if (now - lastTapTime.value < 300) {
      onDblTap()
    }
    lastTapTime.value = now
  } else if (event.touches.length === 2) {
    const dx = event.touches[0]!.clientX - event.touches[1]!.clientX
    const dy = event.touches[0]!.clientY - event.touches[1]!.clientY
    pinchStartDist.value = Math.sqrt(dx * dx + dy * dy)
    pinchStartZoom.value = zoom.value
  }
}

function onTouchMove(event: TouchEvent) {
  if (event.touches.length === 2 && pinchStartDist.value > 0) {
    const dx = event.touches[0]!.clientX - event.touches[1]!.clientX
    const dy = event.touches[0]!.clientY - event.touches[1]!.clientY
    const dist = Math.sqrt(dx * dx + dy * dy)
    const scale = dist / pinchStartDist.value
    zoom.value = Math.min(Math.max(pinchStartZoom.value * scale, 1), 4)
  }
}

function onTouchEnd(event: TouchEvent) {
  if (zoom.value !== 1 || touchStartX.value === 0) return
  if (event.changedTouches.length === 1) {
    const deltaX = event.changedTouches[0]!.clientX - touchStartX.value
    const deltaY = event.changedTouches[0]!.clientY - touchStartY.value
    touchStartX.value = 0
    touchStartY.value = 0

    if (Math.abs(deltaX) > 50 && Math.abs(deltaX) > Math.abs(deltaY)) {
      if (deltaX < 0 && props.hasNext) emit('next')
      else if (deltaX > 0 && props.hasPrev) emit('prev')
    } else if (Math.abs(deltaY) > 100) {
      emit('close')
    }
  }
  pinchStartDist.value = 0
}

function onStageClick(event: MouseEvent) {
  if (zoom.value !== 1) return
  if (event.target === event.currentTarget) {
    emit('close')
  }
}

function onWheel(event: WheelEvent) {
  event.preventDefault()
  if (event.deltaY < 0) zoomIn()
  else zoomOut()
}

function canDeleteComment(comment: DecryptedComment): boolean {
  return isAdmin.value || comment.user_id === authStore.user?.id
}

function formatRelative(dateStr: string): string {
  const date = new Date(dateStr)
  const diffMs = Date.now() - date.getTime()
  const diffMins = Math.floor(diffMs / 60000)
  const diffHours = Math.floor(diffMs / 3600000)
  const diffDays = Math.floor(diffMs / 86400000)

  if (diffMins < 1) return 'Just now'
  if (diffMins < 60) return `${diffMins}m ago`
  if (diffHours < 24) return `${diffHours}h ago`
  if (diffDays < 7) return `${diffDays}d ago`
  return date.toLocaleDateString()
}

function scrollToLatestComment() {
  const el = commentListEl.value
  if (!el) return
  el.scrollTo({ top: el.scrollHeight, behavior: reducedMotion.value ? 'auto' : 'smooth' })
}

async function sendComment() {
  const text = newComment.value.trim()
  if (!text || !currentPek.value || text.length > MAX_COMMENT_LENGTH) return
  newComment.value = ''
  const posted = await commentsStore.postComment(props.photo.id, text, currentPek.value)
  if (!posted) newComment.value = text
  await nextTick()
  scrollToLatestComment()
}

async function removeComment(comment: DecryptedComment) {
  if (!confirm('Delete this comment?')) return
  await commentsStore.deleteComment(props.photo.id, comment.id)
}

function onKeydown(event: KeyboardEvent) {
  onInteraction()
  if (event.key === 'Escape') {
    if (showEditSheet.value) {
      showEditSheet.value = false
      return
    }
    emit('close')
  }
  const target = event.target as HTMLElement | null
  if (target && (target.tagName === 'TEXTAREA' || target.tagName === 'INPUT')) return
  if (event.key === 'ArrowLeft' && props.hasPrev) emit('prev')
  if (event.key === 'ArrowRight' && props.hasNext) emit('next')
  if (event.key === 'i') showInfo.value = !showInfo.value
}

async function loadImage() {
  hasError.value = false
  isLoading.value = true
  originalLoaded.value = false
  thumbnailUrl.value = null
  originalUrl.value = null
  thumbnailMotion.value = ''
  originalMotion.value = ''
  newComment.value = ''
  currentPek.value = null
  commentsStore.clearError()
  resetZoom()

  try {
    const pek = await getPEKForPhoto(props.photo.children)
    if (!pek) {
      hasError.value = true
      isLoading.value = false
      return
    }
    currentPek.value = pek
    void commentsStore.fetchComments(props.photo.id, pek)

    thumbStartTs.value = performance.now()
    thumbnailUrl.value = await fetchThumbnail(
      props.photo.id,
      pek,
      props.photo.iv_thumbnail,
      componentKey,
      props.photo.thumbnail_url,
    )
    isLoading.value = false

    origStartTs.value = performance.now()
    const nav = navigator as Navigator & { connection?: { downlink?: number } }
    const downlink = nav.connection?.downlink ?? Infinity
    if (downlink >= 1.5 || props.photo.size_bytes < 5 * 1024 * 1024) {
      originalUrl.value = await fetchOriginal(
        props.photo.id,
        pek,
        props.photo.iv_original,
        props.photo.original_mime,
        props.photo.size_bytes,
      )
    }
  } catch {
    hasError.value = true
    isLoading.value = false
  }
}

const originalLoaded = ref(false)

watch(
  () => props.photo.id,
  () => {
    loadImage()
  },
)

watch(
  () => commentsStore.error,
  (message) => {
    if (commentErrorTimer) clearTimeout(commentErrorTimer)
    if (message) {
      commentErrorTimer = window.setTimeout(() => commentsStore.clearError(), 5000)
    }
  },
)

onMounted(() => {
  loadImage()
  window.addEventListener('keydown', onKeydown)
  resetChromeTimer()
})

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
  if (chromeTimer.value) clearTimeout(chromeTimer.value)
  if (commentErrorTimer) clearTimeout(commentErrorTimer)
  cleanupComponent(componentKey)
})
</script>

<template>
  <Teleport to="body">
    <div
      class="viewer"
      :class="{ 'viewer--chrome-hidden': !showChrome }"
      @click.self="emit('close')"
      @touchstart="onTouchStart"
      @touchmove="onTouchMove"
      @touchend="onTouchEnd"
      @mousemove="onInteraction"
    >
      <button class="viewer__close u-focus-ring" @click="emit('close')">
        <X :size="24" />
      </button>

      <button
        v-if="hasPrev"
        class="viewer__nav viewer__nav--prev u-focus-ring"
        @click="emit('prev')"
      >
        <ChevronLeft :size="32" />
      </button>

      <button
        v-if="hasNext"
        class="viewer__nav viewer__nav--next u-focus-ring"
        @click="emit('next')"
      >
        <ChevronRight :size="32" />
      </button>

      <div class="viewer__stage" @wheel="onWheel" @click="onStageClick">
        <div v-if="isLoading" class="viewer__loading">
          <div class="viewer__spinner" />
        </div>

        <UiEmptyState v-else-if="hasError" :title="MSG.viewerError">
          <template #icon>
            <AlertTriangle :size="48" />
          </template>
          <template #action>
            <UiButton variant="accent" @click="loadImage">Retry</UiButton>
          </template>
        </UiEmptyState>

        <template v-else>
          <img
            v-if="thumbnailUrl"
            :src="thumbnailUrl"
            :class="thumbnailMotion"
            class="viewer__img viewer__img--thumb"
            :style="{ transform: `scale(${zoom}) translate(${panX}px, ${panY}px)` }"
            alt=""
            @load="onThumbLoad"
          />
          <img
            v-if="originalUrl"
            :src="originalUrl"
            :class="[originalMotion, { 'viewer__img--hidden': !originalLoaded }]"
            class="viewer__img viewer__img--original"
            :style="{ transform: `scale(${zoom}) translate(${panX}px, ${panY}px)` }"
            alt=""
            @load="onOriginalLoad"
            @dblclick="onDblTap"
          />
        </template>
      </div>

      <div
        class="viewer__comments"
        role="region"
        aria-label="Comments"
        @touchstart.stop
        @touchmove.stop
        @touchend.stop
      >
        <div ref="commentListEl" class="viewer__comment-list">
          <p v-if="photoComments.length === 0" class="viewer__comments-status">
            {{ commentsStore.isLoading ? 'Loading comments…' : 'No comments yet — say something nice.' }}
          </p>
          <ul v-else class="viewer__comment-items">
            <li v-for="comment in photoComments" :key="comment.id" class="viewer__comment">
              <p class="viewer__comment-head">
                <span class="viewer__comment-author">{{ comment.author_name }}</span>
                <time class="viewer__comment-time" :datetime="comment.created_at">
                  {{ formatRelative(comment.created_at) }}
                </time>
              </p>
              <p class="viewer__comment-text">{{ comment.text }}</p>
              <button
                v-if="canDeleteComment(comment)"
                type="button"
                class="viewer__comment-delete u-focus-ring"
                aria-label="Delete comment"
                @click="removeComment(comment)"
              >
                <Trash2 :size="16" />
              </button>
            </li>
          </ul>
        </div>

        <p v-if="commentsStore.error" class="viewer__comments-error" role="status">
          {{ commentsStore.error }}
        </p>

        <form class="viewer__comment-form" @submit.prevent="sendComment">
          <textarea
            v-model="newComment"
            class="viewer__comment-input u-focus-ring"
            rows="1"
            :maxlength="MAX_COMMENT_LENGTH"
            placeholder="Add a comment…"
            aria-label="Add a comment"
            @keydown.enter.exact.prevent="sendComment"
          />
          <button
            type="submit"
            class="viewer__comment-send u-focus-ring"
            :disabled="newComment.trim().length === 0 || commentsStore.isPending(photo.id)"
            aria-label="Send comment"
          >
            <Send :size="20" />
          </button>
        </form>
      </div>

      <div v-if="zoom > 1" class="viewer__zoom-controls">
        <button class="viewer__zoom-btn u-focus-ring" @click="zoomOut">
          <Minus :size="16" />
        </button>
        <span class="viewer__zoom-level">{{ Math.round(zoom * 100) }}%</span>
        <button class="viewer__zoom-btn u-focus-ring" @click="zoomIn">
          <Plus :size="16" />
        </button>
      </div>

      <div class="viewer__actions" role="toolbar" aria-label="Photo actions">
        <button class="viewer__action-btn u-focus-ring" aria-label="Favorite">
          <Heart :size="20" />
        </button>
        <button
          class="viewer__action-btn u-focus-ring"
          @click="downloadImage"
          aria-label="Download"
        >
          <Download :size="20" />
        </button>
        <button
          v-if="isAdmin && canEdit"
          class="viewer__action-btn u-focus-ring"
          @click="showEditSheet = true"
          aria-label="Edit"
        >
          <Pencil :size="20" />
        </button>
      </div>

      <div v-if="showInfo" class="viewer__info">
        <div class="viewer__info-kids">
          <UiAvatar
            v-for="child in visibleChildren"
            :key="child.id"
            :id="child.id"
            :name="child.name"
            :color-hex="child.avatar_color"
            :size="32"
          />
        </div>
      </div>
    </div>

    <UiSheet :open="showEditSheet" title="Edit photo" @close="showEditSheet = false">
      <div class="edit-sheet">
        <p class="edit-sheet__label">Children</p>
        <div class="edit-sheet__kids">
          <UiAvatar
            v-for="child in props.photo.children"
            :key="child.id"
            :id="child.id"
            :name="child.name"
            :color-hex="child.avatar_color"
            :size="40"
          />
        </div>
      </div>
    </UiSheet>
  </Teleport>
</template>

<style scoped>
.viewer {
  position: fixed;
  inset: 0;
  z-index: var(--z-viewer);
  background: var(--color-viewer);
  display: flex;
  align-items: center;
  justify-content: center;
}

.viewer__close {
  position: absolute;
  top: var(--space-4);
  right: var(--space-4);
  z-index: 10;
  width: 48px;
  height: 48px;
  border: 0;
  border-radius: 50%;
  background: rgb(255 255 255 / 0.1);
  color: #fff;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background var(--dur-micro) var(--ease-soft);
}

.viewer__close:hover {
  background: rgb(255 255 255 / 0.2);
}

.viewer__nav {
  position: absolute;
  top: 50%;
  transform: translateY(-50%);
  z-index: 10;
  width: 48px;
  height: 48px;
  border: 0;
  border-radius: 50%;
  background: rgb(255 255 255 / 0.1);
  color: #fff;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background var(--dur-micro) var(--ease-soft);
}

.viewer__nav:hover {
  background: rgb(255 255 255 / 0.2);
}

.viewer__nav--prev {
  left: var(--space-4);
}

.viewer__nav--next {
  right: var(--space-4);
}

.viewer__stage {
  position: relative;
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
}

.viewer__img {
  position: absolute;
  max-width: 90%;
  max-height: 90%;
  object-fit: contain;
  transition: transform 0.2s ease;
  user-select: none;
  -webkit-user-drag: none;
}

.viewer__img--thumb {
  z-index: 1;
}

.viewer__img--original {
  z-index: 2;
}

.viewer__img--hidden {
  opacity: 0;
}

.viewer__loading {
  display: flex;
  align-items: center;
  justify-content: center;
}

.viewer__spinner {
  width: 32px;
  height: 32px;
  border: 2px solid rgb(255 255 255 / 0.3);
  border-top-color: #fff;
  border-radius: 50%;
  animation: spin 0.7s linear infinite;
}

.viewer__zoom-controls {
  position: absolute;
  bottom: calc(var(--tabbar-h) + var(--space-4));
  left: 50%;
  transform: translateX(-50%);
  display: flex;
  align-items: center;
  gap: var(--space-2);
  padding: var(--space-2) var(--space-3);
  background: rgb(0 0 0 / 0.6);
  border-radius: var(--radius-pill);
  z-index: 10;
}

.viewer__zoom-btn {
  width: 32px;
  height: 32px;
  border: 0;
  border-radius: 50%;
  background: transparent;
  color: #fff;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
}

.viewer__zoom-btn:hover {
  background: rgb(255 255 255 / 0.1);
}

.viewer__zoom-level {
  color: #fff;
  font-size: var(--text-meta);
  min-width: 3ch;
  text-align: center;
}

.viewer__actions {
  position: absolute;
  bottom: var(--space-4);
  left: 50%;
  transform: translateX(-50%);
  display: flex;
  gap: var(--space-3);
  padding: var(--space-2);
  background: rgb(0 0 0 / 0.6);
  border-radius: var(--radius-pill);
  z-index: 10;
}

.viewer__action-btn {
  width: 48px;
  height: 48px;
  border: 0;
  border-radius: 50%;
  background: transparent;
  color: #fff;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background var(--dur-micro) var(--ease-soft);
}

.viewer__action-btn:hover {
  background: rgb(255 255 255 / 0.1);
}

.viewer__info {
  position: absolute;
  bottom: var(--space-4);
  left: var(--space-4);
  z-index: 10;
}

.viewer__info-kids {
  display: flex;
  gap: var(--space-2);
}

.viewer__comments {
  position: absolute;
  left: var(--space-4);
  right: var(--space-4);
  bottom: calc(var(--target-min) + var(--space-4) + var(--space-2) * 2 + var(--space-4));
  z-index: 10;
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  max-height: min(40vh, 320px);
  padding: var(--space-3) var(--space-4) var(--space-4);
  background: var(--color-surface);
  border-radius: var(--radius-sheet);
  box-shadow: var(--shadow-2);
  animation: comments-in var(--dur-standard) var(--ease-soft) both;
}

.viewer__comment-list {
  overflow-y: auto;
  min-height: 0;
  flex: 1;
}

.viewer__comments-status {
  margin: 0;
  font-size: var(--text-meta);
  color: var(--color-ink-soft);
}

.viewer__comment-items {
  list-style: none;
  margin: 0;
  padding: 0;
}

.viewer__comment {
  position: relative;
  padding: var(--space-2) 0;
  border-top: 1px solid var(--color-border);
}

.viewer__comment:first-child {
  border-top: 0;
  padding-top: 0;
}

.viewer__comment-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: var(--space-2);
  margin: 0;
}

.viewer__comment-author {
  font-size: var(--text-meta);
  font-weight: 700;
  color: var(--color-ink-soft);
}

.viewer__comment-time {
  font-size: var(--text-meta);
  color: var(--color-ink-soft);
}

.viewer__comment-text {
  margin: var(--space-1) 0 0;
  padding-right: var(--target-min);
  font-size: var(--text-body);
  line-height: var(--lh-body);
  color: var(--color-ink);
  overflow-wrap: anywhere;
}

.viewer__comment-delete {
  position: absolute;
  top: 0;
  right: 0;
  width: var(--target-min);
  height: var(--target-min);
  border: 0;
  background: transparent;
  color: var(--color-ink-soft);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: color var(--dur-micro) var(--ease-soft);
}

.viewer__comment-delete:hover {
  color: var(--color-danger);
}

.viewer__comments-error {
  margin: 0;
  font-size: var(--text-meta);
  color: var(--color-danger);
}

.viewer__comment-form {
  display: flex;
  align-items: flex-end;
  gap: var(--space-2);
}

.viewer__comment-input {
  flex: 1;
  min-height: var(--target-min);
  max-height: 96px;
  padding: var(--space-3);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-card);
  background: var(--color-surface-2);
  color: var(--color-ink);
  font-family: inherit;
  font-size: var(--text-body);
  line-height: var(--lh-body);
  resize: none;
}

.viewer__comment-send {
  width: var(--target-min);
  height: var(--target-min);
  flex-shrink: 0;
  border: 0;
  border-radius: 50%;
  background: var(--color-accent);
  color: var(--color-on-accent);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: opacity var(--dur-micro) var(--ease-soft);
}

.viewer__comment-send:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

@keyframes comments-in {
  from {
    opacity: 0;
    transform: translateY(12px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.viewer--chrome-hidden .viewer__close,
.viewer--chrome-hidden .viewer__nav,
.viewer--chrome-hidden .viewer__actions,
.viewer--chrome-hidden .viewer__info,
.viewer--chrome-hidden .viewer__zoom-controls {
  opacity: 0;
  pointer-events: none;
  transition: opacity var(--dur-standard) var(--ease-soft);
}

.edit-sheet__label {
  font-size: var(--text-body);
  font-weight: 700;
  color: var(--color-ink);
  margin: 0 0 var(--space-3);
}

.edit-sheet__kids {
  display: flex;
  gap: var(--space-3);
}

@media (prefers-reduced-motion: reduce) {
  .viewer__spinner {
    animation: none;
    border-top-color: #fff;
  }
  .viewer__img {
    transition: none;
  }
  .viewer__comments {
    animation: none;
  }
  .viewer--chrome-hidden .viewer__close,
  .viewer--chrome-hidden .viewer__nav,
  .viewer--chrome-hidden .viewer__actions,
  .viewer--chrome-hidden .viewer__info,
  .viewer--chrome-hidden .viewer__zoom-controls {
    transition: none;
  }
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
