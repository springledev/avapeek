import { computed, getCurrentInstance, onBeforeUnmount, onMounted, ref, watch } from 'vue'

export const LONG_PRESS_MS = 500

/**
 * Multi-select state for a photo list. `isSelecting` is true from the first selected
 * photo (or an explicit `start()`) until `clear()`. Escape exits, and so does a change
 * of `routeKey` (pass `() => route.fullPath`). Call `clear()` after a completed action.
 */
export function useSelection(options: { routeKey?: () => unknown } = {}) {
  const selecting = ref(false)
  const ids = ref<Set<number>>(new Set())

  const count = computed(() => ids.value.size)
  const isSelecting = computed(() => selecting.value)
  const selectedIds = computed(() => [...ids.value])

  function start(): void {
    selecting.value = true
  }

  function isSelected(id: number): boolean {
    return ids.value.has(id)
  }

  function toggle(id: number): void {
    const next = new Set(ids.value)
    if (next.has(id)) next.delete(id)
    else next.add(id)
    ids.value = next
    selecting.value = true
  }

  function selectMany(list: readonly number[]): void {
    ids.value = new Set([...ids.value, ...list])
    selecting.value = true
  }

  function deselectMany(list: readonly number[]): void {
    const drop = new Set(list)
    ids.value = new Set([...ids.value].filter((id) => !drop.has(id)))
  }

  function allSelected(list: readonly number[]): boolean {
    return list.length > 0 && list.every((id) => ids.value.has(id))
  }

  /** Selects every id, or deselects them all when they were all selected already. */
  function toggleMany(list: readonly number[]): void {
    if (allSelected(list)) deselectMany(list)
    else selectMany(list)
  }

  function clear(): void {
    ids.value = new Set()
    selecting.value = false
  }

  function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && selecting.value) clear()
  }

  if (options.routeKey) watch(options.routeKey, clear)

  if (getCurrentInstance()) {
    onMounted(() => window.addEventListener('keydown', onKeydown))
    onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))
  }

  return {
    isSelecting,
    count,
    selectedIds,
    start,
    isSelected,
    toggle,
    selectMany,
    deselectMany,
    allSelected,
    toggleMany,
    clear,
  }
}
