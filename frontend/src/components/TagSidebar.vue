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
}>()

const emit = defineEmits<{
  select: [tag: string | null]
  create: [name: string]
}>()

const showCreate = ref(false)
const newTagName = ref('')

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
      <h2 class="text-sm font-semibold text-slate-700 uppercase tracking-wide">Tags</h2>
      <button
        class="text-xs text-blue-600 hover:text-blue-700 font-medium"
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
          ref="createInput"
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
        class="tag-item"
        :class="{ 'tag-item-active': activeTag === tag.name }"
        @click="emit('select', tag.name)"
      >
        {{ tag.name }}
        <span class="tag-item-count">{{ tag.photo_count }}</span>
      </li>
    </ul>
  </nav>
</template>
