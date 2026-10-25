<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { X } from '@lucide/vue'
import { useChildStore } from '@/stores/child'
import UiSheet from '@/components/ui/UiSheet.vue'
import UiChip from '@/components/ui/UiChip.vue'
import UiButton from '@/components/ui/UiButton.vue'
import TagInput from '@/components/TagInput.vue'
import TagChip from '@/components/TagChip.vue'
import { useTagsStore } from '@/stores/tags'
import { normalizeTagName } from '@/lib/tags'
import CreateChildSheet from '@/components/CreateChildSheet.vue'
import type { Child } from '@/types/child'

const props = defineProps<{
  open: boolean
  files: File[]
}>()

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'upload', files: File[], childIds: number[], tagNames: string[]): void
}>()

const childStore = useChildStore()
const tagsStore = useTagsStore()
const tagNames = ref<string[]>([])

const selectedChildIds = ref<number[]>(
  childStore.uploadableChildren.length === 1 ? [childStore.uploadableChildren[0]!.id] : [],
)
const showCreateChild = ref(false)

const needsChildChoice = computed(
  () => childStore.uploadableChildren.length > 1 && selectedChildIds.value.length === 0,
)

// Children can load (or be created) after mount: drop stale ids and preselect a lone child.
watch(
  () => childStore.uploadableChildren.map((c) => c.id),
  (ids) => {
    selectedChildIds.value = selectedChildIds.value.filter((id) => ids.includes(id))
    if (ids.length === 1 && selectedChildIds.value.length === 0) {
      selectedChildIds.value = [ids[0]!]
    }
  },
)

function onChildCreated(child: Child) {
  if (!selectedChildIds.value.includes(child.id)) selectedChildIds.value.push(child.id)
}

const objectUrls = computed(() => props.files.map((f) => URL.createObjectURL(f)))

function toggleChild(childId: number) {
  const idx = selectedChildIds.value.indexOf(childId)
  if (idx >= 0) selectedChildIds.value.splice(idx, 1)
  else selectedChildIds.value.push(childId)
}

function addTagName(name: string) {
  if (!tagNames.value.some((t) => normalizeTagName(t) === normalizeTagName(name))) {
    tagNames.value.push(name)
  }
}

function removeTagName(name: string) {
  tagNames.value = tagNames.value.filter((t) => t !== name)
}

watch(
  selectedChildIds,
  (ids) => {
    void tagsStore.loadAll(ids)
  },
  { immediate: true, deep: true },
)

function removeFile(index: number) {
  const newFiles = [...props.files]
  newFiles.splice(index, 1)
  if (newFiles.length === 0) emit('close')
}

function startUpload() {
  if (selectedChildIds.value.length === 0) return
  emit('upload', props.files, [...selectedChildIds.value], [...tagNames.value])
}

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1048576) return `${(bytes / 1024).toFixed(0)} KB`
  return `${(bytes / 1048576).toFixed(1)} MB`
}
</script>

<template>
  <UiSheet :open="open" title="Review photos" @close="emit('close')">
    <div class="batch">
      <div v-if="childStore.uploadableChildren.length === 0" class="batch__no-kids">
        <p class="batch__hint">
          Photos live in a kid's album. Add a kid first, then choose who is in these photos.
        </p>
        <UiButton variant="accent" @click="showCreateChild = true">Add a kid</UiButton>
      </div>

      <p v-else-if="needsChildChoice" class="batch__hint">Choose who's in these photos</p>

      <div v-if="childStore.uploadableChildren.length > 0" class="batch__kids">
        <UiChip
          v-for="child in childStore.uploadableChildren"
          :key="child.id"
          :label="child.name"
          tone="kid"
          :kid-id="child.id"
          :kid-color="child.avatar_color"
          :selected="selectedChildIds.includes(child.id)"
          @toggle="toggleChild(child.id)"
        />
      </div>

      <div v-if="selectedChildIds.length > 0" class="batch__tags">
        <div v-if="tagNames.length > 0" class="batch__tag-chips">
          <TagChip v-for="name in tagNames" :key="name" :name="name" removable @remove="removeTagName(name)" />
        </div>
        <TagInput :child-ids="selectedChildIds" :exclude="tagNames" placeholder="Add tags (optional)" @add="addTagName" />
      </div>

      <div class="batch__grid">
        <div v-for="(file, idx) in files" :key="idx" class="batch__item">
          <img :src="objectUrls[idx]" class="batch__thumb" alt="" />
          <button type="button" class="batch__remove u-focus-ring" @click="removeFile(idx)">
            <X :size="16" />
          </button>
          <div class="batch__meta">
            <p class="batch__name">{{ file.name }}</p>
            <p class="batch__size">{{ formatBytes(file.size) }}</p>
          </div>
        </div>
      </div>

      <p class="batch__summary">
        {{ files.length }} {{ files.length === 1 ? 'photo' : 'photos' }} ({{
          formatBytes(files.reduce((sum, f) => sum + f.size, 0))
        }})
      </p>

      <UiButton
        variant="accent"
        size="lg"
        :disabled="selectedChildIds.length === 0 || files.length === 0"
        class="batch__upload-btn"
        @click="startUpload"
      >
        Upload {{ files.length }} {{ files.length === 1 ? 'photo' : 'photos' }}
      </UiButton>
    </div>
  </UiSheet>

  <CreateChildSheet
    :open="showCreateChild"
    @close="showCreateChild = false"
    @created="onChildCreated"
  />
</template>

<style scoped>
.batch__tags {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.batch__tag-chips {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
}

.batch {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.batch__no-kids {
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
  align-items: flex-start;
}

.batch__hint {
  font-size: var(--text-body);
  color: var(--color-ink-soft);
  margin: 0;
}

.batch__kids {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
}

.batch__grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
  gap: var(--space-3);
}

.batch__item {
  position: relative;
  aspect-ratio: 1;
  border-radius: var(--radius-photo);
  overflow: hidden;
  background: var(--color-surface-2);
}

.batch__thumb {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.batch__remove {
  position: absolute;
  top: var(--space-1);
  right: var(--space-1);
  width: 28px;
  height: 28px;
  border: 0;
  border-radius: 50%;
  background: rgb(0 0 0 / 0.6);
  color: #fff;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
}

.batch__meta {
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  padding: var(--space-1) var(--space-2);
  background: linear-gradient(transparent, rgb(0 0 0 / 0.6));
  color: #fff;
}

.batch__name {
  font-size: 11px;
  font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  margin: 0;
}

.batch__size {
  font-size: 10px;
  opacity: 0.8;
  margin: 0;
}

.batch__summary {
  font-size: var(--text-meta);
  color: var(--color-ink-soft);
  text-align: center;
  margin: 0;
}

.batch__upload-btn {
  width: 100%;
}
</style>
