<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { toast } from 'vue-sonner'
import { Download, Tag, Share2, Trash2, X } from '@lucide/vue'
import UiSheet from '@/components/ui/UiSheet.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiProgressOverlay from '@/components/ui/UiProgressOverlay.vue'
import TagChip from '@/components/TagChip.vue'
import { useChildStore } from '@/stores/child'
import { useTagsStore } from '@/stores/tags'
import {
  childrenLinkingAll,
  downloadPhotos,
  removePhotos,
  sharePhotos,
  tagPhotos,
  uniqueTagNames,
} from '@/services/bulk'
import { cleanTagName } from '@/stores/tags'
import { MSG } from '@/lib/messages'
import { apiErrorMessage } from '@/lib/util'
import type { Photo } from '@/types/photo'

const props = defineProps<{
  /** The selected photos (live objects from the feed/recents store). */
  photos: Photo[]
}>()

const emit = defineEmits<{
  clear: []
  /** Photos gone from the list (`deleted`), plus how many stay visible through another kid. */
  removed: [deleted: number[], detachedCount: number]
}>()

const childStore = useChildStore()
const tagsStore = useTagsStore()

const count = computed(() => props.photos.length)

type Sheet = 'remove' | 'tags' | 'share' | null
const sheet = ref<Sheet>(null)

const progress = ref({ active: false, current: 0, total: 0, label: '' })
let controller: AbortController | null = null
const busy = computed(() => progress.value.active)

function beginProgress(total: number, label = '') {
  controller = new AbortController()
  progress.value = { active: true, current: 0, total, label }
  return {
    signal: controller.signal,
    onProgress: (current: number, all: number) => {
      progress.value = { ...progress.value, current, total: all }
    },
  }
}

function endProgress() {
  progress.value = { active: false, current: 0, total: 0, label: '' }
  controller = null
}

function cancelAction() {
  controller?.abort()
}

function reportStopped(done: number, left: number, cancelled: boolean, error: unknown): boolean {
  if (cancelled) {
    toast.message(MSG.bulkStopped(done, left))
    return true
  }
  if (error) {
    toast.error(apiErrorMessage(error, MSG.bulkFailedPartial(done, left)))
    return true
  }
  return false
}

// ---------------------------------------------------------------- download

async function download() {
  if (busy.value || count.value === 0) return
  const photos = [...props.photos]
  const options = beginProgress(photos.length)
  try {
    const summary = await downloadPhotos(photos, options)
    if (summary.cancelled) {
      toast.message(MSG.bulkStopped(summary.saved, photos.length - summary.saved - summary.failed))
    } else if (summary.failed > 0) {
      toast.error(MSG.bulkDownloaded(summary.saved, summary.failed))
    } else {
      toast.success(MSG.bulkDownloaded(summary.saved, 0))
    }
    emit('clear')
  } finally {
    endProgress()
  }
}

// ------------------------------------------------------------------ remove

async function confirmRemove() {
  const photos = [...props.photos]
  sheet.value = null
  const options = beginProgress(photos.length)
  try {
    const summary = await removePhotos(
      photos.map((p) => p.id),
      options,
    )
    const done = summary.deleted.length + summary.detached.length + summary.forbidden.length
    if (done > 0) {
      toast.success(
        MSG.bulkRemoved(summary.deleted.length, summary.detached.length, summary.forbidden.length),
      )
    }
    reportStopped(done, summary.notProcessed, summary.cancelled, summary.error)
    // A cancelled or failed batch may already be committed server-side: count it as
    // "unknown" so the parent refetches instead of trusting the local list.
    emit('removed', summary.deleted, summary.detached.length + summary.notProcessed)
    emit('clear')
  } finally {
    endProgress()
  }
}

// -------------------------------------------------------------------- tags

const tagInput = ref('')
const pendingTags = ref<string[]>([])

const tagSuggestions = computed(() =>
  tagsStore
    .suggest(tagInput.value, undefined, 6)
    .filter((t) => !pendingTags.value.some((n) => n.toLowerCase() === t.name.toLowerCase())),
)

function addPendingTag(name: string) {
  const clean = cleanTagName(name)
  if (!clean) return
  pendingTags.value = uniqueTagNames([...pendingTags.value, clean])
  tagInput.value = ''
}

function removePendingTag(name: string) {
  pendingTags.value = pendingTags.value.filter((n) => n !== name)
}

async function openTags() {
  pendingTags.value = []
  tagInput.value = ''
  sheet.value = 'tags'
  await tagsStore.loadAll(childStore.children.map((c) => c.id))
}

async function applyTags() {
  addPendingTag(tagInput.value)
  const names = [...pendingTags.value]
  if (names.length === 0) return
  const photos = [...props.photos]
  sheet.value = null
  const options = beginProgress(photos.length)
  try {
    const summary = await tagPhotos(photos, names, options)
    if (summary.updated.length > 0) toast.success(MSG.bulkTagsDone(summary.updated.length))
    if (summary.skipped.length > 0) toast.error(MSG.bulkTagsSkipped(summary.skipped.length))
    reportStopped(
      summary.updated.length + summary.skipped.length,
      summary.notProcessed,
      summary.cancelled,
      summary.error,
    )
    emit('clear')
  } catch (err) {
    // Creating a tag failed (for example the per-kid tag limit): nothing was attached.
    toast.error(apiErrorMessage(err, MSG.bulkActionFailed))
  } finally {
    endProgress()
  }
}

// ------------------------------------------------------------------- share

const shareTargets = computed(() => {
  const full = childrenLinkingAll(props.photos)
  return childStore.children.filter((c) => !full.has(c.id))
})

async function shareTo(childId: number) {
  const photos = [...props.photos]
  const target = childStore.children.find((c) => c.id === childId)
  sheet.value = null
  if (!target) return
  const options = beginProgress(photos.length)
  try {
    const summary = await sharePhotos(photos, childId, options)
    for (const photo of photos) {
      const wrap = summary.wraps.get(photo.id)
      if (!wrap) continue
      photo.children = [
        ...photo.children,
        { id: target.id, name: target.name, avatar_color: target.avatar_color, ...wrap },
      ]
    }
    const sharedMessage = MSG.bulkShared(
      summary.shared.length,
      summary.skipped.length,
      summary.unwrappable.length,
    )
    if (summary.shared.length > 0) toast.success(sharedMessage)
    else toast.warning(sharedMessage)
    reportStopped(
      summary.shared.length + summary.skipped.length + summary.unwrappable.length,
      summary.notProcessed,
      summary.cancelled,
      summary.error,
    )
    emit('clear')
  } catch (err) {
    toast.error(apiErrorMessage(err, MSG.bulkActionFailed))
  } finally {
    endProgress()
  }
}

watch(count, (n) => {
  if (n === 0) sheet.value = null
})
</script>

<template>
  <div v-if="count > 0 || busy" class="bar" role="toolbar" aria-label="Photo actions">
    <button type="button" class="bar__btn u-focus-ring" data-test="bulk-cancel" @click="emit('clear')">
      <X :size="20" aria-hidden="true" />
      <span class="bar__label">{{ MSG.selectCancel }}</span>
    </button>
    <p class="bar__count" aria-live="polite" data-test="bulk-count">{{ MSG.selectedCount(count) }}</p>
    <button type="button" class="bar__btn u-focus-ring" :disabled="busy" data-test="bulk-download" @click="download">
      <Download :size="20" aria-hidden="true" />
      <span class="bar__label">{{ MSG.bulkDownload }}</span>
    </button>
    <button type="button" class="bar__btn u-focus-ring" :disabled="busy" data-test="bulk-tags" @click="openTags">
      <Tag :size="20" aria-hidden="true" />
      <span class="bar__label">{{ MSG.bulkTags }}</span>
    </button>
    <button type="button" class="bar__btn u-focus-ring" :disabled="busy" data-test="bulk-share" @click="sheet = 'share'">
      <Share2 :size="20" aria-hidden="true" />
      <span class="bar__label">{{ MSG.bulkShare }}</span>
    </button>
    <button type="button" class="bar__btn bar__btn--danger u-focus-ring" :disabled="busy" data-test="bulk-remove" @click="sheet = 'remove'">
      <Trash2 :size="20" aria-hidden="true" />
      <span class="bar__label">{{ MSG.bulkRemove }}</span>
    </button>
  </div>

  <UiSheet :open="sheet === 'remove'" :title="MSG.bulkRemoveTitle(count)" @close="sheet = null">
    <p class="copy" data-test="remove-body">{{ MSG.bulkRemoveBody }}</p>
    <template #footer>
      <UiButton variant="subtle" @click="sheet = null">{{ MSG.bulkCancelAction }}</UiButton>
      <UiButton variant="danger" data-test="remove-confirm" @click="confirmRemove">
        {{ MSG.bulkRemoveConfirm }}
      </UiButton>
    </template>
  </UiSheet>

  <UiSheet :open="sheet === 'tags'" :title="MSG.bulkTagsTitle" @close="sheet = null">
    <p class="copy">{{ MSG.bulkTagsHint }}</p>
    <form class="tagform" @submit.prevent="addPendingTag(tagInput)">
      <input
        v-model="tagInput"
        class="tagform__input u-focus-ring"
        type="text"
        maxlength="40"
        :aria-label="MSG.bulkTagInputLabel"
        :placeholder="MSG.bulkTagInputLabel"
        data-test="bulk-tag-input"
      />
      <UiButton type="submit" variant="subtle">{{ MSG.bulkTagAdd }}</UiButton>
    </form>
    <div v-if="tagSuggestions.length > 0" class="chips">
      <button
        v-for="tag in tagSuggestions"
        :key="tag.key"
        type="button"
        class="chips__suggest u-focus-ring"
        @click="addPendingTag(tag.name)"
      >
        {{ tag.name }}
      </button>
    </div>
    <div v-if="pendingTags.length > 0" class="chips" data-test="pending-tags">
      <TagChip v-for="name in pendingTags" :key="name" :name="name" removable @remove="removePendingTag(name)" />
    </div>
    <template #footer>
      <UiButton variant="subtle" @click="sheet = null">{{ MSG.bulkCancelAction }}</UiButton>
      <UiButton
        variant="accent"
        :disabled="pendingTags.length === 0 && !tagInput.trim()"
        data-test="bulk-tags-apply"
        @click="applyTags"
      >
        {{ MSG.bulkTagsApply }}
      </UiButton>
    </template>
  </UiSheet>

  <UiSheet :open="sheet === 'share'" :title="MSG.bulkShareTitle" @close="sheet = null">
    <p class="copy" data-test="share-hint">{{ MSG.bulkShareHint }}</p>
    <p v-if="shareTargets.length === 0" class="copy">{{ MSG.bulkShareNone }}</p>
    <ul v-else class="targets">
      <li v-for="child in shareTargets" :key="child.id">
        <button type="button" class="targets__btn u-focus-ring" data-test="share-target" @click="shareTo(child.id)">
          {{ child.name }}
        </button>
      </li>
    </ul>
  </UiSheet>

  <UiProgressOverlay
    :active="busy"
    :current="progress.current"
    :total="progress.total"
    :file-name="progress.label"
    cancellable
    @cancel="cancelAction"
  />
</template>

<style scoped>
.bar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: calc(var(--tabbar-h) + var(--safe-area-b));
  z-index: calc(var(--z-tabbar) + 1);
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: var(--space-1);
  padding: var(--space-2) var(--space-3);
  background: var(--color-surface);
  border-top: 1px solid var(--color-border);
  box-shadow: var(--shadow-sheet);
}

.bar__count {
  flex: 1;
  min-width: 5rem;
  margin: 0;
  font-weight: 700;
  color: var(--color-ink);
  font-size: var(--text-body);
}

.bar__btn {
  display: inline-flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 2px;
  min-width: var(--target-min);
  min-height: var(--target-min);
  padding: 0 var(--space-2);
  border: 0;
  border-radius: var(--radius-card);
  background: transparent;
  color: var(--color-ink);
  font-family: inherit;
  font-size: var(--text-meta);
  font-weight: 600;
  cursor: pointer;
}

.bar__btn--danger {
  color: var(--color-danger);
}

.bar__btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.copy {
  margin: 0 0 var(--space-4);
  color: var(--color-ink-soft);
  font-size: var(--text-body);
  line-height: var(--lh-body);
}

.tagform {
  display: flex;
  gap: var(--space-2);
  margin-bottom: var(--space-3);
}

.tagform__input {
  flex: 1;
  min-height: var(--target-min);
  padding: 0 var(--space-3);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-card);
  background: var(--color-surface);
  color: var(--color-ink);
  font-family: inherit;
  font-size: var(--text-body);
}

.chips {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  margin-bottom: var(--space-3);
}

.chips__suggest {
  min-height: 36px;
  padding: 0 var(--space-3);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-pill);
  background: var(--color-surface-2);
  color: var(--color-ink);
  font-family: inherit;
  cursor: pointer;
}

.targets {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.targets__btn {
  width: 100%;
  min-height: var(--target-min);
  padding: 0 var(--space-4);
  border: 0;
  border-radius: var(--radius-card);
  background: var(--color-surface-2);
  color: var(--color-ink);
  font-family: inherit;
  font-size: var(--text-body);
  font-weight: 700;
  text-align: left;
  cursor: pointer;
}
</style>
