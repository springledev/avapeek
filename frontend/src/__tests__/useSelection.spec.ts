import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, nextTick, ref } from 'vue'
import { useSelection } from '@/composables/useSelection'
import { chunk, runBatches } from '@/lib/batch'

describe('useSelection', () => {
  it('toggles, counts and enters selecting mode', () => {
    const s = useSelection()
    expect(s.isSelecting.value).toBe(false)
    s.toggle(1)
    s.toggle(2)
    expect(s.isSelecting.value).toBe(true)
    expect(s.count.value).toBe(2)
    expect(s.isSelected(1)).toBe(true)
    s.toggle(1)
    expect(s.isSelected(1)).toBe(false)
    expect(s.selectedIds.value).toEqual([2])
  })

  it('selects and clears whole groups', () => {
    const s = useSelection()
    s.toggle(1)
    s.toggleMany([1, 2, 3])
    expect(s.count.value).toBe(3)
    expect(s.allSelected([1, 2, 3])).toBe(true)
    s.toggleMany([1, 2, 3])
    expect(s.count.value).toBe(0)
    s.selectMany([4, 5])
    s.clear()
    expect(s.count.value).toBe(0)
    expect(s.isSelecting.value).toBe(false)
  })

  it('exits on Escape and on a route change, and cleans up its listener', async () => {
    const route = ref('/feed')
    let selection!: ReturnType<typeof useSelection>
    const wrapper = mount(
      defineComponent({
        setup() {
          selection = useSelection({ routeKey: () => route.value })
          return () => null
        },
      }),
    )

    selection.toggle(1)
    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    expect(selection.isSelecting.value).toBe(false)

    selection.toggle(2)
    route.value = '/recents'
    await nextTick()
    expect(selection.count.value).toBe(0)

    wrapper.unmount()
    selection.toggle(3)
    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    expect(selection.isSelecting.value).toBe(true)
  })
})

describe('runBatches', () => {
  it('splits into batches of the given size', () => {
    expect(chunk([1, 2, 3, 4, 5], 2)).toEqual([[1, 2], [3, 4], [5]])
    expect(chunk(Array.from({ length: 101 }, (_, i) => i), 100).map((b) => b.length)).toEqual([
      100, 1,
    ])
  })

  it('reports completed batches when a later batch fails', async () => {
    const progress: number[] = []
    const run = await runBatches(
      [1, 2, 3, 4, 5],
      2,
      async (batch) => {
        if (batch[0] === 3) throw new Error('boom')
        return batch.length
      },
      { onProgress: (n) => progress.push(n) },
    )
    expect(run.results).toEqual([2])
    expect(run.processed).toBe(2)
    expect(run.error).toBeInstanceOf(Error)
    expect(run.cancelled).toBe(false)
    expect(progress).toEqual([0, 2])
  })

  it('stops between batches when aborted and keeps earlier results', async () => {
    const controller = new AbortController()
    const run = await runBatches(
      [1, 2, 3, 4],
      2,
      async (batch) => {
        controller.abort()
        return batch
      },
      { signal: controller.signal },
    )
    expect(run.results).toEqual([[1, 2]])
    expect(run.cancelled).toBe(true)
    expect(run.error).toBeNull()
  })
})
