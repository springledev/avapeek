<script setup lang="ts">
import { ref } from 'vue'

export interface TagItem {
  id: number
  name: string
  photo_count: number
}

defineProps<{
  tags: TagItem[]
  activeTag: string | null
  totalPhotos: number
  canDeleteTag: boolean
}>()

const emit = defineEmits<{
  select: [tag: string | null]
  create: [name: string]
  deleteTag: [tagId: number]
}>()

const showCreate = ref(false)
const newTagName = ref('')
const showDeleteConfirm = ref<number | null>(null)

function handleCreate() {
  const name = newTagName.value.trim()
  if (name) {
    emit('create', name)
    newTagName.value = ''
    showCreate.value = false
  }
}
</script>

<template>
  <nav class="tag-sidebar">
    <div class="flex items-center justify-between mb-3 px-1">
      <h2 class="text-sm font-semibold text-slate-700 uppercase tracking-wide dark:text-slate-300">Tags</h2>
      <button
        class="text-xs text-blue-600 hover:text-blue-700 font-medium dark:text-blue-400 dark:hover:text-blue-300"
        @click="showCreate = !showCreate"
      >
        + New
      </button>
    </div>

    <div v-if="showCreate" class="mb-3 px-1">
      <form @submit.prevent="handleCreate" class="flex gap-1.5">
        <input
          v-model="newTagName"
          type="text"
          class="tag-create-input"
          placeholder="Tag name..."
          maxlength="100"
        />
        <button
          class="px-2.5 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg shrink-0"
          :disabled="!newTagName.trim()"
        >
          Add
        </button>
      </form>
    </div>

    <ul class="tag-list">
      <li
        class="tag-item tag-item-all"
        :class="{ 'tag-item-active': activeTag === null }"
        @click="emit('select', null)"
      >
        All photos
        <span class="tag-item-count">{{ totalPhotos }}</span>
      </li>
      <li
        v-for="tag in tags"
        :key="tag.id"
        class="tag-item group"
        :class="{ 'tag-item-active': activeTag === tag.name }"
        @click="emit('select', tag.name)"
      >
        <span class="truncate">{{ tag.name }}</span>
        <span class="flex items-center gap-1 shrink-0 ml-1">
          <span class="tag-item-count">{{ tag.photo_count }}</span>
          <button
            v-if="canDeleteTag"
            class="tag-delete-btn"
            @click.stop="showDeleteConfirm = tag.id"
          >
            &times;
          </button>
        </span>
      </li>
    </ul>

    <!-- Delete tag confirmation -->
    <div v-if="showDeleteConfirm !== null" class="confirm-overlay" @click.self="showDeleteConfirm = null">
      <div class="confirm-card">
        <h3 class="confirm-title">Delete tag?</h3>
        <p class="confirm-message">
          Remove "{{ tags.find(t => t.id === showDeleteConfirm)?.name }}" from all photos? This doesn't delete any photos.
        </p>
        <div class="confirm-actions">
          <button class="btn-cancel" @click="showDeleteConfirm = null">Cancel</button>
          <button
            class="btn-danger"
            @click="emit('deleteTag', showDeleteConfirm); showDeleteConfirm = null"
          >
            Delete tag
          </button>
        </div>
      </div>
    </div>
  </nav>
</template>
