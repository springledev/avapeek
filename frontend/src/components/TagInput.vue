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
  <div class="tagpick">
    <input
      v-model="query"
      type="text"
      :maxlength="MAX_TAG_LENGTH"
      :placeholder="placeholder"
      class="tagpick__field"
      autocomplete="off"
      @keydown.enter.prevent="pick(activeIndex)"
      @keydown.down.prevent="move(1)"
      @keydown.up.prevent="move(-1)"
    />
    <ul v-if="rows.length > 0" class="tagpick__list" role="listbox">
      <li v-for="(row, index) in rows" :key="row.kind + row.name" role="option" :aria-selected="index === activeIndex">
        <button
          type="button"
          class="tagpick__row"
          :class="{ 'tagpick__row--active': index === activeIndex }"
          @mousedown.prevent
          @click="pick(index)"
        >
          <span v-if="row.kind === 'create'">Create '{{ row.name }}'</span>
          <template v-else>
            <span>{{ row.name }}</span>
            <span class="tagpick__count">{{ row.count }}</span>
          </template>
        </button>
      </li>
    </ul>
  </div>
</template>

<style scoped>
.tagpick {
  position: relative;
  width: 100%;
}

.tagpick__field {
  width: 100%;
  min-height: var(--target-min);
  padding: 0 var(--space-4);
  border-radius: var(--radius-pill);
  border: 1px solid var(--color-border);
  background: var(--color-surface);
  color: var(--color-ink);
  font-family: inherit;
  font-size: var(--text-body);
}

.tagpick__field::placeholder {
  color: var(--color-ink-soft);
}

.tagpick__field:focus-visible {
  outline: 2px solid var(--color-focus);
  outline-offset: 2px;
}

.tagpick__list {
  margin: var(--space-1) 0 0;
  padding: var(--space-1);
  list-style: none;
  border-radius: var(--radius-card);
  border: 1px solid var(--color-border);
  background: var(--color-surface);
  box-shadow: var(--shadow-2);
  overflow: hidden;
}

.tagpick__row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-2);
  width: 100%;
  min-height: var(--target-min);
  padding: 0 var(--space-3);
  border: 0;
  border-radius: var(--radius-photo);
  background: transparent;
  color: var(--color-ink);
  font-family: inherit;
  font-size: var(--text-body);
  font-weight: 600;
  text-align: left;
  cursor: pointer;
}

.tagpick__row--active,
.tagpick__row:hover {
  background: var(--color-accent-soft);
  color: var(--color-accent-ink);
}

.tagpick__count {
  font-size: var(--text-meta);
  color: var(--color-ink-soft);
}
</style>
