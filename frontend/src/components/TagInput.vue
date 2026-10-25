<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useTagsStore } from '@/stores/tags'
import { MAX_TAG_LENGTH, normalizeTagName } from '@/lib/tags'

const props = withDefaults(
  defineProps<{
    /** Only suggest tags that exist for these children (all children when omitted). */
    childIds?: number[]
    /** Names already chosen; hidden from suggestions. */
    exclude?: string[]
    allowCreate?: boolean
    placeholder?: string
  }>(),
  { allowCreate: true, placeholder: 'Add a tag...' },
)

const emit = defineEmits<{
  add: [name: string]
}>()

const tagsStore = useTagsStore()
const query = ref('')
const activeIndex = ref(0)

const excluded = computed(() => new Set((props.exclude ?? []).map(normalizeTagName)))

const suggestions = computed(() =>
  tagsStore
    .suggest(query.value, props.childIds)
    .filter((tag) => !excluded.value.has(tag.key)),
)

const typedKey = computed(() => normalizeTagName(query.value))

const showCreate = computed(
  () =>
    props.allowCreate &&
    typedKey.value !== '' &&
    !excluded.value.has(typedKey.value) &&
    !suggestions.value.some((tag) => tag.key === typedKey.value),
)

/** Rows in display order: suggestions first, then the create row. */
const rows = computed(() => [
  ...suggestions.value.map((tag) => ({ kind: 'suggestion' as const, name: tag.name, count: tag.photo_count })),
  ...(showCreate.value
    ? [{ kind: 'create' as const, name: query.value.trim().replace(/\s+/g, ' ').slice(0, MAX_TAG_LENGTH), count: 0 }]
    : []),
])

watch(query, () => {
  activeIndex.value = 0
})

function pick(index: number) {
  const row = rows.value[index]
  if (!row || row.name === '') return
  emit('add', row.name)
  query.value = ''
}

function move(delta: number) {
  if (rows.value.length === 0) return
  activeIndex.value = (activeIndex.value + delta + rows.value.length) % rows.value.length
}
</script>

<template>
  <div class="tag-input">
    <input
      v-model="query"
      type="text"
      :maxlength="MAX_TAG_LENGTH"
      :placeholder="placeholder"
      class="tag-input__field"
      autocomplete="off"
      @keydown.enter.prevent="pick(activeIndex)"
      @keydown.down.prevent="move(1)"
      @keydown.up.prevent="move(-1)"
    />
    <ul v-if="rows.length > 0" class="tag-input__list" role="listbox">
      <li v-for="(row, index) in rows" :key="row.kind + row.name" role="option" :aria-selected="index === activeIndex">
        <button
          type="button"
          class="tag-input__row"
          :class="{ 'tag-input__row--active': index === activeIndex }"
          @mousedown.prevent
          @click="pick(index)"
        >
          <span v-if="row.kind === 'create'">Create '{{ row.name }}'</span>
          <template v-else>
            <span>{{ row.name }}</span>
            <span class="tag-input__count">{{ row.count }}</span>
          </template>
        </button>
      </li>
    </ul>
  </div>
</template>

<style scoped>
.tag-input {
  position: relative;
  width: 100%;
}

.tag-input__field {
  width: 100%;
  min-height: 48px;
  padding: 0 var(--space-3, 12px);
  border-radius: 12px;
  border: 1px solid rgb(148 163 184 / 0.5);
  background: transparent;
  color: inherit;
  font-size: 1rem;
}

.tag-input__list {
  margin: 4px 0 0;
  padding: 0;
  list-style: none;
  border-radius: 12px;
  border: 1px solid rgb(148 163 184 / 0.4);
  overflow: hidden;
}

.tag-input__row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  width: 100%;
  min-height: 48px;
  padding: 0 12px;
  border: 0;
  background: transparent;
  color: inherit;
  text-align: left;
  cursor: pointer;
}

.tag-input__row--active,
.tag-input__row:hover {
  background: rgb(148 163 184 / 0.18);
}

.tag-input__count {
  font-size: 0.75rem;
  opacity: 0.6;
}
</style>
