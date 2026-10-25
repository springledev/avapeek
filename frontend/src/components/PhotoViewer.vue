<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, watch, computed, nextTick } from 'vue'
import {
  X,
  ChevronLeft,
  ChevronRight,
  ChevronDown,
  Minus,
  Plus,
  Download,
  Heart,
  Pencil,
  Info,
  AlertTriangle,
  Send,
  Trash2,
  MessageSquare,
  MoreVertical,
} from '@lucide/vue'
import { fetchOriginal, fetchThumbnail, getPEKForPhoto, cleanupComponent } from '@/services/storage'
import { prepareOriginalDownload } from '@/services/export'
import { saveBlob } from '@/lib/export/sink'
import { toast } from 'vue-sonner'
import { useMotionPolicy } from '@/composables/useMotionPolicy'
import { useChildStore } from '@/stores/child'
import { useCommentsStore, MAX_COMMENT_LENGTH } from '@/stores/comments'
import { useAuthStore } from '@/stores/auth'
import UiAvatar from '@/components/ui/UiAvatar.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiEmptyState from '@/components/ui/UiEmptyState.vue'
import UiSheet from '@/components/ui/UiSheet.vue'
import { MSG } from '@/lib/messages'
import type { Photo, DecryptedComment } from '@/types/photo'
import TagChip from '@/components/TagChip.vue'
import TagInput from '@/components/TagInput.vue'
import { dedupeTagsByName } from '@/lib/tags'

const props = defineProps<{
  photo: Photo
  hasPrev: boolean
  hasNext: boolean
  canDelete: boolean
  canEdit: boolean
  childBirthDate: string | null
  prevPhoto?: Photo | null
  nextPhoto?: Photo | null
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
const showInfo = ref(false)
const showEditSheet = ref(false)
const showMenu = ref(false)
const zoom = ref(1)
const panX = ref(0)
const panY = ref(0)
const touchStartX = ref(0)
const touchStartY = ref(0)
const pinchStartDist = ref(0)
const pinchStartZoom = ref(1)
const lastTapTime = ref(0)
const componentKey = 'photoviewer-new'
const thumbStartTs = ref(0)
const origStartTs = ref(0)
const stageEl = ref<HTMLElement | null>(null)

// Gesture state: finger-following drag (dragX/dragY on the frame), plus a
// discrete snap/slide phase that toggles CSS transitions on/off.
type DragAxis = 'none' | 'x' | 'y'
type GesturePhase = '' | 'snap' | 'slide'
const dragActive = ref(false)
const dragAxis = ref<DragAxis>('none')
const gesturePhase = ref<GesturePhase>('')
const dragX = ref(0)
const dragY = ref(0)
const panning = ref(false)
const panStartX = ref(0)
const panStartY = ref(0)
const velocityX = ref(0)
const velocityY = ref(0)
const lastMoveX = ref(0)
const lastMoveY = ref(0)
const lastMoveTs = ref(0)
const dismissDir = ref(0)
const suppressClick = ref(false)
let gestureTimer: number | null = null

// Neighbor thumbnails for the horizontal peek; keyed by photo id so a
// late-resolving fetch can never paint the wrong neighbor.
const peekUrls = ref<Record<number, string>>({})

// The peek URL of the photo a slide is advancing to; loadImage promotes it
// into the frame so the centered image never disappears (no black flash).
let heldPeekUrl: string | null = null
let loadToken = 0
let suppressThumbMotion = false

const currentPek = ref<CryptoKey | null>(null)
const newComment = ref('')
const commentListEl = ref<HTMLElement | null>(null)
let commentErrorTimer: number | null = null
let prevHtmlOverflow = ''
let prevBodyOverflow = ''

const photoComments = computed(() => commentsStore.commentsFor(props.photo.id))
const commentsStatus = computed(() => commentsStore.statusFor(props.photo.id))
const previewComments = computed(() => photoComments.value.slice(-2))
const totalComments = computed(() =>
  commentsStatus.value === 'ready'
    ? photoComments.value.length
    : (props.photo.comment_count ?? photoComments.value.length),
)
const commentsExpanded = ref(false)

const isAdmin = computed(() =>
  childStore.children.some((c) => c.role === 'owner' || c.role === 'admin'),
)

const displayTags = computed(() => dedupeTagsByName(props.photo.tags ?? []))
const visibleChildren = computed(() => props.photo.children.slice(0, 3))

const neighborPhotos = computed(() =>
  [props.prevPhoto, props.nextPhoto].filter((p): p is Photo => Boolean(p)),
)

const peekSide = ref<'prev' | 'next'>('next')

const peekPhoto = computed<Photo | null>(() => {
  if (dragAxis.value !== 'x') return null
  return peekSide.value === 'prev' ? (props.prevPhoto ?? null) : (props.nextPhoto ?? null)
})

const peekUrl = computed(() =>
  peekPhoto.value ? (peekUrls.value[peekPhoto.value.id] ?? null) : null,
)

const frameStyle = computed(() => ({
  transform: `translate3d(${dragX.value}px, ${dragY.value}px, 0)`,
}))

const frameClass = computed(() => ({
  'viewer__frame--snap': gesturePhase.value === 'snap',
  'viewer__frame--slide': gesturePhase.value === 'slide',
  'viewer__frame--panning': panning.value,
}))

const peekStyle = computed(() => {
  if (gesturePhase.value === 'slide') {
    // Fully opaque as it takes over the center: the frame handoff at the
    // end of the slide is then pixel-identical (no brightness pop).
    return { transform: 'translate3d(0, 0, 0)', opacity: '1' }
  }
  const side = peekSide.value === 'prev' ? -1 : 1
  return { transform: `translate3d(calc(${side * 100}% + ${dragX.value * 0.35}px), 0, 0)` }
})

const backdropOpacity = computed(() => {
  if (gesturePhase.value === 'slide') return dismissDir.value === 0 ? 1 : 0
  if (dragAxis.value === 'y') return Math.max(0.2, 1 - Math.abs(dragY.value) / 300)
  return 1
})

function onThumbLoad() {
  if (suppressThumbMotion) {
    suppressThumbMotion = false
    thumbnailMotion.value = ''
    return
  }
  thumbnailMotion.value = resolve(thumbStartTs.value)
}

function onOriginalLoad() {
  originalLoaded.value = true
  originalMotion.value = resolve(origStartTs.value)
}

let downloading = false

async function downloadImage() {
  if (downloading) return
  downloading = true
  try {
    const pek = currentPek.value ?? (await getPEKForPhoto(props.photo.children))
    if (!pek) throw new Error('No key for this photo')
    const { blob, filename } = await prepareOriginalDownload(props.photo, pek)
    saveBlob(blob, filename)
  } catch {
    toast.error(MSG.downloadFailed)
  } finally {
    downloading = false
  }
}

function zoomIn() {
  zoom.value = Math.min(zoom.value + 0.5, 4)
}
function zoomOut() {
  zoom.value = Math.max(zoom.value - 0.5, 1)
  if (zoom.value === 1) {
    panX.value = 0
    panY.value = 0
  }
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

function clampPan(value: number, axis: 'x' | 'y'): number {
  const span =
    axis === 'x'
      ? (stageEl.value?.clientWidth ?? window.innerWidth)
      : (stageEl.value?.clientHeight ?? window.innerHeight)
  const limit = ((zoom.value - 1) * span) / 2
  return Math.min(Math.max(value, -limit), limit)
}

function resetGesture() {
  dragActive.value = false
  dragAxis.value = 'none'
  gesturePhase.value = ''
  dragX.value = 0
  dragY.value = 0
  panning.value = false
  velocityX.value = 0
  velocityY.value = 0
  dismissDir.value = 0
}

function onTouchStart(event: TouchEvent) {
  if (gesturePhase.value === 'slide') return
  if (event.touches.length === 1) {
    suppressClick.value = false
    dragActive.value = true
    dragAxis.value = 'none'
    gesturePhase.value = ''
    touchStartX.value = event.touches[0]!.clientX
    touchStartY.value = event.touches[0]!.clientY
    lastMoveX.value = touchStartX.value
    lastMoveY.value = touchStartY.value
    lastMoveTs.value = performance.now()
    velocityX.value = 0
    velocityY.value = 0
    panStartX.value = panX.value
    panStartY.value = panY.value
    const now = Date.now()
    if (now - lastTapTime.value < 300) {
      onDblTap()
    }
    lastTapTime.value = now
  } else if (event.touches.length === 2) {
    dragActive.value = false
    dragAxis.value = 'none'
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
    return
  }

  if (event.touches.length !== 1 || !dragActive.value || gesturePhase.value === 'slide') return
  if (reducedMotion.value) return

  const touch = event.touches[0]!
  const dx = touch.clientX - touchStartX.value
  const dy = touch.clientY - touchStartY.value

  if (zoom.value > 1) {
    panning.value = true
    panX.value = clampPan(panStartX.value + dx / zoom.value, 'x')
    panY.value = clampPan(panStartY.value + dy / zoom.value, 'y')
    return
  }

  if (dragAxis.value === 'none') {
    if (Math.max(Math.abs(dx), Math.abs(dy)) < 10) return
    dragAxis.value = Math.abs(dx) > Math.abs(dy) ? 'x' : 'y'
    peekSide.value = dx > 0 ? 'prev' : 'next'
    suppressClick.value = true
  }

  const now = performance.now()
  const dt = now - lastMoveTs.value
  if (dt > 0) {
    velocityX.value = (touch.clientX - lastMoveX.value) / dt
    velocityY.value = (touch.clientY - lastMoveY.value) / dt
    lastMoveX.value = touch.clientX
    lastMoveY.value = touch.clientY
    lastMoveTs.value = now
  }

  if (dragAxis.value === 'x') {
    const blocked = (dx > 0 && !props.hasPrev) || (dx < 0 && !props.hasNext)
    dragX.value = blocked ? dx * 0.25 : dx
    dragY.value = 0
  } else {
    dragY.value = dy * 0.85
    dragX.value = 0
  }
}

function onTouchEnd(event: TouchEvent) {
  pinchStartDist.value = 0
  const wasSliding = gesturePhase.value === 'slide'
  if (!dragActive.value || wasSliding || event.touches.length > 0) return
  dragActive.value = false

  const touch = event.changedTouches[0]
  if (!touch) return
  const dx = touch.clientX - touchStartX.value
  const dy = touch.clientY - touchStartY.value

  if (zoom.value > 1) {
    panning.value = false
    dragAxis.value = 'none'
    return
  }

  if (reducedMotion.value || dragAxis.value === 'none') {
    dragAxis.value = 'none'
    if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) {
      if (dx < 0 && props.hasNext) emit('next')
      else if (dx > 0 && props.hasPrev) emit('prev')
    } else if (Math.abs(dy) > 100) {
      emit('close')
    }
    return
  }

  if (dragAxis.value === 'x') {
    const goingNext = dx < 0
    const canAdvance = goingNext ? props.hasNext : props.hasPrev
    const pastDistance = Math.abs(dx) > window.innerWidth * 0.3
    const pastVelocity =
      Math.abs(velocityX.value) > 0.4 && (velocityX.value < 0) === goingNext
    if (canAdvance && (pastDistance || pastVelocity)) {
      slideOut(goingNext ? -1 : 1, goingNext)
    } else {
      snapBack()
    }
  } else if (dragAxis.value === 'y') {
    const pastDistance = Math.abs(dy) > 120
    const pastVelocity = Math.abs(velocityY.value) > 0.4
    if (pastDistance || pastVelocity) {
      dismiss(dy >= 0 ? 1 : -1)
    } else {
      snapBack()
    }
  }
}

function snapBack() {
  gesturePhase.value = 'snap'
  dragX.value = 0
  dragY.value = 0
  velocityX.value = 0
  velocityY.value = 0
  if (gestureTimer) clearTimeout(gestureTimer)
  gestureTimer = window.setTimeout(() => {
    if (gesturePhase.value === 'snap') {
      gesturePhase.value = ''
      dragAxis.value = 'none'
    }
  }, 300)
}

function slideOut(dir: -1 | 1, goingNext: boolean) {
  gesturePhase.value = 'slide'
  dragX.value = dir * window.innerWidth
  const approach = goingNext ? props.nextPhoto : props.prevPhoto
  heldPeekUrl = approach ? (peekUrls.value[approach.id] ?? null) : null
  if (gestureTimer) clearTimeout(gestureTimer)
  gestureTimer = window.setTimeout(() => {
    if (goingNext) emit('next')
    else emit('prev')
  }, 260)
}

function dismiss(dir: -1 | 1) {
  gesturePhase.value = 'slide'
  dismissDir.value = dir
  dragY.value = dir * window.innerHeight
  if (gestureTimer) clearTimeout(gestureTimer)
  gestureTimer = window.setTimeout(() => emit('close'), 260)
}

function onStageClick(event: MouseEvent) {
  if (suppressClick.value) {
    suppressClick.value = false
    return
  }
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

function toggleComments() {
  commentsExpanded.value = !commentsExpanded.value
}

function retryLoadComments() {
  if (!currentPek.value) return
  void commentsStore.fetchComments(props.photo.id, currentPek.value, { force: true })
}

function menuDownload() {
  showMenu.value = false
  void downloadImage()
}

function menuEdit() {
  showMenu.value = false
  showEditSheet.value = true
}

function menuInfo() {
  showMenu.value = false
  showInfo.value = true
}

function menuDelete() {
  showMenu.value = false
  if (window.confirm('Delete this photo? This cannot be undone.')) {
    emit('delete', props.photo.id)
  }
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
  if (event.key === 'Escape') {
    if (showMenu.value) {
      showMenu.value = false
      return
    }
    if (showEditSheet.value) {
      showEditSheet.value = false
      return
    }
    if (commentsExpanded.value) {
      commentsExpanded.value = false
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

async function warmPeeks() {
  peekUrls.value = {}
  for (const neighbor of neighborPhotos.value) {
    try {
      const pek = await getPEKForPhoto(neighbor.children)
      if (!pek) continue
      const url = await fetchThumbnail(
        neighbor.id,
        pek,
        neighbor.iv_thumbnail,
        componentKey,
        neighbor.thumbnail_url,
      )
      peekUrls.value = { ...peekUrls.value, [neighbor.id]: url }
    } catch {
      // best-effort peek; a plain backdrop is fine
    }
  }
}

async function loadImage() {
  const token = ++loadToken
  const slidingIn = gesturePhase.value === 'slide' && dismissDir.value === 0
  const promoted = slidingIn ? heldPeekUrl : null
  heldPeekUrl = null

  hasError.value = false
  originalLoaded.value = false
  originalUrl.value = null
  thumbnailMotion.value = ''
  originalMotion.value = ''
  newComment.value = ''
  currentPek.value = null
  showMenu.value = false
  showInfo.value = false
  commentsExpanded.value = false
  commentsStore.clearError()
  resetZoom()

  if (promoted) {
    // The peek already fills the center with this exact photo: hand it to
    // the frame and end the gesture in the same tick — zero black frames.
    suppressThumbMotion = true
    thumbnailUrl.value = promoted
    isLoading.value = false
    resetGesture()
  } else {
    if (!thumbnailUrl.value || slidingIn) {
      thumbnailUrl.value = null
      resetGesture()
    }
    // Otherwise the previous thumbnail stays visible until the new one
    // resolves; the spinner overlay signals the wait instead of a black flash.
    isLoading.value = true
  }

  try {
    const pek = await getPEKForPhoto(props.photo.children)
    if (token !== loadToken) return
    if (!pek) {
      thumbnailUrl.value = null
      hasError.value = true
      isLoading.value = false
      return
    }
    currentPek.value = pek
    void commentsStore.fetchComments(props.photo.id, pek)
    void commentsStore.prefetchComments(neighborPhotos.value, props.photo.id)
    void warmPeeks()

    thumbStartTs.value = performance.now()
    const nextThumb = await fetchThumbnail(
      props.photo.id,
      pek,
      props.photo.iv_thumbnail,
      componentKey,
      props.photo.thumbnail_url,
    )
    if (token !== loadToken) return
    thumbnailUrl.value = nextThumb
    isLoading.value = false

    origStartTs.value = performance.now()
    const nav = navigator as Navigator & { connection?: { downlink?: number } }
    const downlink = nav.connection?.downlink ?? Infinity
    if (downlink >= 1.5 || props.photo.size_bytes < 5 * 1024 * 1024) {
      const nextOriginal = await fetchOriginal(
        props.photo.id,
        pek,
        props.photo.iv_original,
        props.photo.original_mime,
        props.photo.size_bytes,
      )
      if (token !== loadToken) return
      originalUrl.value = nextOriginal
    }
  } catch {
    if (token !== loadToken) return
    thumbnailUrl.value = null
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
  prevHtmlOverflow = document.documentElement.style.overflow
  prevBodyOverflow = document.body.style.overflow
  document.documentElement.style.overflow = 'hidden'
  document.body.style.overflow = 'hidden'
})

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
  if (commentErrorTimer) clearTimeout(commentErrorTimer)
  if (gestureTimer) clearTimeout(gestureTimer)
  document.documentElement.style.overflow = prevHtmlOverflow
  document.body.style.overflow = prevBodyOverflow
  cleanupComponent(componentKey)
})
</script>

<template>
  <Teleport to="body">
    <div
      class="viewer"
      @click.self="onStageClick"
      @touchstart="onTouchStart"
      @touchmove.prevent="onTouchMove"
      @touchend="onTouchEnd"
    >
      <div
        class="viewer__backdrop"
        :style="{
          opacity: backdropOpacity,
          transition: gesturePhase ? 'opacity var(--dur-standard) var(--ease-soft)' : 'none',
        }"
      />

      <button class="viewer__close u-focus-ring" aria-label="Close" @click="emit('close')">
        <X :size="24" />
      </button>

      <div
        ref="stageEl"
        class="viewer__stage"
        @wheel="onWheel"
        @click="onStageClick"
      >
        <button
          v-if="hasPrev"
          class="viewer__nav viewer__nav--prev u-focus-ring"
          aria-label="Previous photo"
          @click="emit('prev')"
        >
          <ChevronLeft :size="32" />
        </button>

        <button
          v-if="hasNext"
          class="viewer__nav viewer__nav--next u-focus-ring"
          aria-label="Next photo"
          @click="emit('next')"
        >
          <ChevronRight :size="32" />
        </button>

        <UiEmptyState v-if="hasError" :title="MSG.viewerError">
          <template #icon>
            <AlertTriangle :size="48" />
          </template>
          <template #action>
            <UiButton variant="accent" @click="loadImage">Retry</UiButton>
          </template>
        </UiEmptyState>

        <template v-else>
          <img
            v-if="peekUrl"
            :src="peekUrl"
            class="viewer__peek"
            :class="{ 'viewer__peek--anim': gesturePhase !== '' }"
            :style="peekStyle"
            alt=""
            aria-hidden="true"
          />
          <div class="viewer__frame" :class="frameClass" :style="frameStyle">
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
          </div>
        </template>

        <div v-if="isLoading && !hasError" class="viewer__loading" aria-hidden="true">
          <div class="viewer__spinner" />
        </div>
      </div>

      <section
        class="viewer__sheet"
        :class="{ 'viewer__sheet--expanded': commentsExpanded }"
        role="region"
        aria-label="Comments"
        @touchstart.stop
        @touchmove.stop
        @touchend.stop
      >
        <div v-if="commentsExpanded" class="viewer__sheet-head">
          <h3 class="viewer__sheet-title">Comments</h3>
          <button
            type="button"
            class="viewer__sheet-collapse u-focus-ring"
            aria-label="Collapse comments"
            @click="commentsExpanded = false"
          >
            <ChevronDown :size="20" />
          </button>
        </div>

        <div
          v-if="commentsStatus === 'loading' && photoComments.length === 0"
          class="viewer__skeleton"
          aria-hidden="true"
        >
          <span class="viewer__skeleton-line" />
          <span class="viewer__skeleton-line viewer__skeleton-line--short" />
        </div>

        <div
          v-else-if="commentsStatus === 'error' && photoComments.length === 0"
          class="viewer__load-error"
        >
          <p class="viewer__load-error-text">
            {{ commentsStore.loadErrorFor(photo.id) ?? MSG.commentsLoadFailed }}
          </p>
          <button type="button" class="viewer__retry u-focus-ring" @click="retryLoadComments">
            Retry
          </button>
        </div>

        <template v-else-if="!commentsExpanded">
          <ul v-if="previewComments.length" class="viewer__preview">
            <li v-for="comment in previewComments" :key="comment.id" class="viewer__preview-item">
              <span class="viewer__comment-author">{{ comment.author_name }}</span>
              <span class="viewer__preview-text">{{ comment.text }}</span>
            </li>
          </ul>
          <p v-else-if="commentsStatus === 'ready'" class="viewer__comments-status">
            No comments yet — say something nice.
          </p>
          <button
            v-if="totalComments > 2"
            type="button"
            class="viewer__view-all u-focus-ring"
            @click="commentsExpanded = true"
          >
            View all {{ totalComments }} comments
          </button>
        </template>

        <div v-else ref="commentListEl" class="viewer__comment-list">
          <p v-if="photoComments.length === 0" class="viewer__comments-status">
            No comments yet — say something nice.
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
      </section>

      <div v-if="zoom > 1" class="viewer__zoom-controls">
        <button class="viewer__zoom-btn u-focus-ring" aria-label="Zoom out" @click="zoomOut">
          <Minus :size="16" />
        </button>
        <span class="viewer__zoom-level">{{ Math.round(zoom * 100) }}%</span>
        <button class="viewer__zoom-btn u-focus-ring" aria-label="Zoom in" @click="zoomIn">
          <Plus :size="16" />
        </button>
      </div>

      <div class="viewer__actions" role="toolbar" aria-label="Photo actions">
        <button
          type="button"
          class="viewer__action-btn u-focus-ring"
          :aria-label="commentsExpanded ? 'Collapse comments' : 'Show comments'"
          :aria-expanded="commentsExpanded"
          @click="toggleComments"
        >
          <MessageSquare :size="20" />
          <span v-if="totalComments > 0" class="viewer__action-count">{{ totalComments }}</span>
        </button>
        <button type="button" class="viewer__action-btn u-focus-ring" aria-label="Favorite">
          <Heart :size="20" />
        </button>
        <span class="viewer__actions-spacer" aria-hidden="true" />
        <button
          type="button"
          class="viewer__action-btn u-focus-ring"
          aria-label="More actions"
          aria-haspopup="menu"
          :aria-expanded="showMenu"
          @click="showMenu = !showMenu"
        >
          <MoreVertical :size="20" />
        </button>
      </div>

      <button
        v-if="showMenu"
        type="button"
        class="viewer__menu-scrim"
        aria-label="Close menu"
        @click="showMenu = false"
        @touchstart.stop
        @touchmove.stop
        @touchend.stop
      />
      <div
        v-if="showMenu"
        class="viewer__menu"
        role="menu"
        aria-label="More photo actions"
        @touchstart.stop
        @touchmove.stop
        @touchend.stop
      >
        <button type="button" class="viewer__menu-item u-focus-ring" role="menuitem" @click="menuDownload">
          <Download :size="18" />
          <span>Download</span>
        </button>
        <button
          v-if="canEdit && isAdmin"
          type="button"
          class="viewer__menu-item u-focus-ring"
          role="menuitem"
          @click="menuEdit"
        >
          <Pencil :size="18" />
          <span>Edit</span>
        </button>
        <button
          v-if="visibleChildren.length > 0"
          type="button"
          class="viewer__menu-item u-focus-ring"
          role="menuitem"
          @click="menuInfo"
        >
          <Info :size="18" />
          <span>Info</span>
        </button>
        <button
          v-if="canDelete"
          type="button"
          class="viewer__menu-item viewer__menu-item--danger u-focus-ring"
          role="menuitem"
          @click="menuDelete"
        >
          <Trash2 :size="18" />
          <span>Delete</span>
        </button>
      </div>

      <div v-if="showInfo" class="viewer__info">
        <div class="viewer__info-tags">
          <TagChip
            v-for="tag in displayTags"
            :key="tag.id"
            :name="tag.name"
            removable
            @remove="emit('tagRemoved', props.photo.id, tag.id)"
          />
          <TagInput
            :child-ids="props.photo.children.map((c) => c.id)"
            :exclude="displayTags.map((t) => t.name)"
            @add="(name: string) => emit('tagAdded', props.photo.id, name)"
          />
        </div>
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
  overflow: hidden;
  display: flex;
  flex-direction: column;
}

.viewer__backdrop {
  position: absolute;
  inset: 0;
  background: var(--color-viewer);
  pointer-events: none;
}

.viewer__close {
  position: absolute;
  top: calc(var(--space-4) + var(--safe-area-t));
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

.viewer__stage {
  position: relative;
  z-index: 1;
  flex: 1;
  min-height: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
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

.viewer__frame {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  transform: translate3d(0, 0, 0);
}

.viewer__frame--snap,
.viewer__frame--slide {
  transition: transform var(--dur-standard) var(--ease-soft);
}

.viewer__frame--panning .viewer__img {
  transition: none;
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

.viewer__peek {
  position: absolute;
  max-width: 90%;
  max-height: 90%;
  object-fit: contain;
  user-select: none;
  -webkit-user-drag: none;
  opacity: 0.85;
}

.viewer__peek--anim {
  transition:
    transform var(--dur-standard) var(--ease-soft),
    opacity var(--dur-standard) var(--ease-soft);
}

.viewer__loading {
  position: absolute;
  inset: 0;
  z-index: 3;
  display: flex;
  align-items: center;
  justify-content: center;
  pointer-events: none;
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
  bottom: calc(var(--target-min) + var(--space-8) + var(--safe-area-b));
  left: 50%;
  transform: translateX(-50%);
  display: flex;
  align-items: center;
  gap: var(--space-2);
  padding: var(--space-2) var(--space-3);
  background: rgb(0 0 0 / 0.6);
  border-radius: var(--radius-pill);
  z-index: 20;
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

.viewer__sheet {
  position: relative;
  z-index: 5;
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  padding: var(--space-2) var(--space-4) var(--space-1);
  color: #fff;
}

.viewer__sheet-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-2);
}

.viewer__sheet-title {
  margin: 0;
  font-size: var(--text-body);
  font-weight: 800;
  color: #fff;
}

.viewer__sheet-collapse {
  width: var(--target-min);
  height: var(--target-min);
  border: 0;
  border-radius: 50%;
  background: transparent;
  color: rgb(255 255 255 / 0.7);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
}

.viewer__sheet-collapse:hover {
  background: rgb(255 255 255 / 0.1);
  color: #fff;
}

.viewer__preview {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
}

.viewer__preview-item {
  margin: 0;
  font-size: var(--text-body);
  line-height: var(--lh-body);
  color: rgb(255 255 255 / 0.92);
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;
  overflow-wrap: anywhere;
}

.viewer__preview-text {
  margin-left: var(--space-2);
}

.viewer__view-all {
  align-self: flex-start;
  border: 0;
  background: transparent;
  padding: var(--space-1) 0;
  min-height: var(--target-min);
  font-family: inherit;
  font-size: var(--text-body);
  font-weight: 700;
  color: #fff;
  cursor: pointer;
}

.viewer__view-all:hover {
  text-decoration: underline;
}

.viewer__comment-list {
  overflow-y: auto;
  overscroll-behavior: contain;
  -webkit-overflow-scrolling: touch;
  max-height: 45vh;
  min-height: 0;
  scrollbar-width: none;
}

.viewer__comment-list::-webkit-scrollbar {
  display: none;
}

.viewer__skeleton {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  padding: var(--space-1) 0;
}

.viewer__skeleton-line {
  height: 12px;
  width: 70%;
  border-radius: var(--radius-pill);
  background: rgb(255 255 255 / 0.14);
  animation: skeleton-pulse 1.4s ease-in-out infinite;
}

.viewer__skeleton-line--short {
  width: 45%;
}

.viewer__load-error {
  display: flex;
  align-items: center;
  gap: var(--space-2);
}

.viewer__load-error-text {
  margin: 0;
  font-size: var(--text-meta);
  color: rgb(255 255 255 / 0.75);
}

.viewer__retry {
  border: 0;
  background: rgb(255 255 255 / 0.12);
  color: #fff;
  font-family: inherit;
  font-size: var(--text-meta);
  font-weight: 700;
  padding: var(--space-2) var(--space-3);
  min-height: var(--target-min);
  border-radius: var(--radius-pill);
  cursor: pointer;
}

.viewer__retry:hover {
  background: rgb(255 255 255 / 0.2);
}

.viewer__comments-status {
  margin: 0;
  font-size: var(--text-meta);
  color: rgb(255 255 255 / 0.55);
}

.viewer__comment-items {
  list-style: none;
  margin: 0;
  padding: 0;
}

.viewer__comment {
  position: relative;
  padding: var(--space-2) 0;
  border-top: 1px solid rgb(255 255 255 / 0.1);
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
  color: #fff;
}

.viewer__comment-time {
  font-size: var(--text-meta);
  color: rgb(255 255 255 / 0.5);
}

.viewer__comment-text {
  margin: var(--space-1) 0 0;
  padding-right: var(--target-min);
  font-size: var(--text-body);
  line-height: var(--lh-body);
  color: rgb(255 255 255 / 0.92);
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
  color: rgb(255 255 255 / 0.5);
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
  color: #ff9d94;
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
  border: 1px solid rgb(255 255 255 / 0.16);
  border-radius: var(--radius-card);
  background: rgb(255 255 255 / 0.08);
  color: #fff;
  font-family: inherit;
  font-size: var(--text-body);
  line-height: var(--lh-body);
  resize: none;
}

.viewer__comment-input::placeholder {
  color: rgb(255 255 255 / 0.45);
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

.viewer__actions {
  position: relative;
  z-index: 6;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  gap: var(--space-1);
  padding: var(--space-2) var(--space-3);
  padding-bottom: calc(var(--space-2) + var(--safe-area-b));
  border-top: 1px solid rgb(255 255 255 / 0.08);
  background: rgb(0 0 0 / 0.35);
}

.viewer__actions-spacer {
  flex: 1;
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
  gap: var(--space-1);
  transition: background var(--dur-micro) var(--ease-soft);
}

.viewer__action-btn:hover {
  background: rgb(255 255 255 / 0.1);
}

.viewer__action-count {
  font-size: var(--text-meta);
  font-weight: 700;
  color: rgb(255 255 255 / 0.85);
}

.viewer__menu-scrim {
  position: absolute;
  inset: 0;
  z-index: 29;
  border: 0;
  background: transparent;
  cursor: default;
}

.viewer__menu {
  position: absolute;
  right: var(--space-4);
  bottom: calc(var(--target-min) + var(--space-8) + var(--safe-area-b));
  z-index: 30;
  display: flex;
  flex-direction: column;
  min-width: 200px;
  padding: var(--space-2);
  background: rgb(0 0 0 / 0.88);
  border: 1px solid rgb(255 255 255 / 0.12);
  border-radius: var(--radius-card);
  box-shadow: var(--shadow-2);
  animation: menu-in var(--dur-micro) var(--ease-soft) both;
}

.viewer__menu-item {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  min-height: var(--target-min);
  padding: var(--space-2) var(--space-3);
  border: 0;
  border-radius: var(--radius-card);
  background: transparent;
  color: #fff;
  font-family: inherit;
  font-size: var(--text-body);
  font-weight: 700;
  text-align: left;
  cursor: pointer;
  transition: background var(--dur-micro) var(--ease-soft);
}

.viewer__menu-item:hover {
  background: rgb(255 255 255 / 0.1);
}

.viewer__menu-item--danger {
  color: #ff9d94;
}

.viewer__info-tags {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  width: min(320px, calc(100vw - 2 * var(--space-4)));
  margin-bottom: var(--space-3);
  padding: var(--space-3);
  border-radius: var(--radius-card);
  background: rgb(0 0 0 / 0.6);
}

.viewer__info {
  position: absolute;
  bottom: calc(var(--target-min) + var(--space-8) + var(--safe-area-b));
  left: var(--space-4);
  z-index: 10;
}

.viewer__info-kids {
  display: flex;
  gap: var(--space-2);
}

@keyframes menu-in {
  from {
    opacity: 0;
    transform: translateY(8px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

@keyframes skeleton-pulse {
  0%,
  100% {
    opacity: 1;
  }
  50% {
    opacity: 0.45;
  }
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
  .viewer__frame--snap,
  .viewer__frame--slide,
  .viewer__peek--anim {
    transition: none;
  }
  .viewer__skeleton-line {
    animation: none;
  }
  .viewer__menu {
    animation: none;
  }
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
