<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { fetchThumbnail, cleanupComponent, getPEKForPhoto } from '@/services/storage'
import { dedupeTagsByName } from '@/lib/tags'
import { LONG_PRESS_MS } from '@/composables/useSelection'
import { MSG } from '@/lib/messages'
import { Check } from '@lucide/vue'
import type { Photo } from '@/types/photo'

const props = defineProps<{
  photos: Photo[]
  uploaderName: string
  childName: string
  childColor: string | null
  relativeTime: string
  /** Selection mode: a tap toggles instead of opening. */
  selecting?: boolean
  selectedIds?: number[]
}>()

const emit = defineEmits<{
  select: [photo: Photo]
  toggle: [photo: Photo]
  toggleAll: []
}>()

const LONG_PRESS = LONG_PRESS_MS
let pressTimer: ReturnType<typeof setTimeout> | null = null
let longPressFired = false

function isChosen(photo: Photo): boolean {
  return props.selectedIds?.includes(photo.id) ?? false
}

const allChosen = computed(
  () => props.photos.length > 0 && props.photos.every((p) => isChosen(p)),
)

function startPress(event: PointerEvent, photo: Photo) {
  if (event.pointerType === 'mouse' || props.selecting) return
  longPressFired = false
  pressTimer = setTimeout(() => {
    pressTimer = null
    longPressFired = true
    emit('toggle', photo)
  }, LONG_PRESS)
}

function endPress() {
  if (pressTimer) clearTimeout(pressTimer)
  pressTimer = null
}

function onThumbClick(photo: Photo) {
  if (longPressFired) {
    longPressFired = false
    return
  }
  if (props.selecting) emit('toggle', photo)
  else emit('select', photo)
}

const thumbnails = ref<Map<number, string>>(new Map())
const loaded = ref<Set<number>>(new Set())

const count = computed(() => props.photos.length)
const tags = computed(() => {
  const allTags = props.photos.flatMap(p => p.tags || [])
  return dedupeTagsByName(allTags).slice(0, 3)
})

const displayThumbnails = computed(() => props.photos.slice(0, 8))

async function loadThumbnail(photo: Photo) {
  if (loaded.value.has(photo.id)) return
  loaded.value.add(photo.id)
  try {
    const pek = await getPEKForPhoto(photo.children)
    if (!pek) return
    const url = await fetchThumbnail(
      photo.id,
      pek,
      photo.iv_thumbnail,
      'recents',
      photo.thumbnail_url,
    )
    thumbnails.value.set(photo.id, url)
  } catch {
    // Silent fail — just show placeholder
  }
}

onMounted(() => {
  for (const photo of displayThumbnails.value) {
    loadThumbnail(photo)
  }
})

onBeforeUnmount(() => {
  cleanupComponent('recents')
})
</script>

<template>
  <div class="group">
    <div class="group__header">
      <div
        class="group__avatar"
        :style="{ backgroundColor: childColor || 'var(--color-accent)' }"
      >
        {{ childName.charAt(0).toUpperCase() }}
      </div>
      <div class="group__info">
        <p class="group__title">
          {{ uploaderName }} uploaded {{ count }} {{ count === 1 ? 'photo' : 'photos' }}
        </p>
        <p class="group__meta">
          of {{ childName }} &middot; {{ relativeTime }}
        </p>
      </div>
    </div>

    <button
      v-if="selecting"
      type="button"
      class="group__select u-focus-ring"
      data-test="select-group"
      @click="emit('toggleAll')"
    >
      {{ allChosen ? MSG.deselectAllIn('this group') : MSG.selectAllIn('this group') }}
    </button>

    <div v-if="tags.length > 0" class="group__tags">
      <span
        v-for="tag in tags"
        :key="tag.id"
        class="group__tag"
      >
        {{ tag.name }}
      </span>
    </div>

    <div class="group__thumbs">
      <button
        v-for="photo in displayThumbnails"
        :key="photo.id"
        :class="{ 'group__thumb--selected': isChosen(photo) }"
        :aria-pressed="selecting ? isChosen(photo) : undefined"
        class="group__thumb u-focus-ring"
        @click="onThumbClick(photo)"
        @pointerdown="startPress($event, photo)"
        @pointerup="endPress"
        @pointercancel="endPress"
        @pointerleave="endPress"
        @contextmenu="(selecting || longPressFired) && $event.preventDefault()"
      >
        <span
          v-if="selecting"
          class="group__check"
          :class="{ 'group__check--on': isChosen(photo) }"
          aria-hidden="true"
        >
          <Check v-if="isChosen(photo)" :size="14" :stroke-width="3" />
        </span>
        <img
          v-if="thumbnails.get(photo.id)"
          :src="thumbnails.get(photo.id)"
          class="group__img"
          alt=""
          loading="lazy"
        />
        <div v-else class="group__placeholder">
          <div class="group__skeleton" />
        </div>
      </button>
      <div
        v-if="photos.length > 8"
        class="group__more"
      >
        +{{ photos.length - 8 }}
      </div>
    </div>
  </div>
</template>

<style scoped>
.group {
  border: 1px solid var(--color-border);
  background: var(--color-surface);
  border-radius: var(--radius-card);
  padding: var(--space-4);
}

.group__header {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin-bottom: var(--space-3);
}

.group__avatar {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: var(--text-meta);
  font-weight: 700;
  color: var(--color-on-accent);
  flex-shrink: 0;
}

.group__info {
  flex: 1;
  min-width: 0;
}

.group__title {
  font-size: var(--text-body);
  font-weight: 600;
  color: var(--color-ink);
  margin: 0;
}

.group__meta {
  font-size: var(--text-meta);
  color: var(--color-ink-soft);
  margin: 0;
}

.group__tags {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-1);
  margin-bottom: var(--space-2);
}

.group__tag {
  background: var(--color-surface-2);
  color: var(--color-ink-soft);
  padding: var(--space-1) var(--space-2);
  border-radius: var(--radius-pill);
  font-size: var(--text-meta);
}

.group__thumbs {
  display: flex;
  gap: var(--space-2);
  overflow-x: auto;
  padding-bottom: var(--space-1);
}

.group__thumb {
  width: 80px;
  height: 80px;
  flex-shrink: 0;
  overflow: hidden;
  border-radius: var(--radius-photo);
  background: var(--color-surface-2);
  border: none;
  padding: 0;
  cursor: pointer;
}

.group__thumb {
  position: relative;
  -webkit-touch-callout: none;
  user-select: none;
}

.group__thumb--selected {
  outline: 3px solid var(--color-accent);
  outline-offset: -3px;
}

.group__check {
  position: absolute;
  top: var(--space-1);
  left: var(--space-1);
  display: flex;
  align-items: center;
  justify-content: center;
  width: 22px;
  height: 22px;
  border: 2px solid #fff;
  border-radius: 50%;
  background: var(--color-overlay);
  color: var(--color-on-accent);
}

.group__check--on {
  background: var(--color-accent);
}

.group__select {
  min-height: var(--target-min);
  margin-bottom: var(--space-2);
  padding: 0 var(--space-2);
  border: 0;
  background: transparent;
  color: var(--color-accent-ink);
  font-family: inherit;
  font-size: var(--text-body);
  font-weight: 700;
  cursor: pointer;
}

.group__img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.group__placeholder {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
}

.group__skeleton {
  width: 20px;
  height: 20px;
  border-radius: var(--radius-photo);
  background: var(--color-border);
  animation: pulse 1.5s ease-in-out infinite;
}

.group__more {
  width: 80px;
  height: 80px;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: var(--radius-photo);
  background: var(--color-surface-2);
  color: var(--color-ink-soft);
  font-size: var(--text-meta);
  font-weight: 600;
}

@keyframes pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.5; }
}

@media (prefers-reduced-motion: reduce) {
  .group__skeleton {
    animation: none;
  }
}
</style>
