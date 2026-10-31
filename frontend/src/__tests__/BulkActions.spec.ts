import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

vi.mock('@/composables/useLazyDecrypt', () => ({
  useLazyDecrypt: () => ({ observePhoto: vi.fn<() => void>(), unobserve: vi.fn<() => void>() }),
}))

const removePhotos = vi.hoisted(() => vi.fn<(ids: number[]) => Promise<unknown>>())
vi.mock('@/services/bulk', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/services/bulk')>()),
  removePhotos,
  sharePhotos,
}))

vi.mock('vue-sonner', () => ({
  toast: {
    success: vi.fn<() => void>(),
    error: vi.fn<() => void>(),
    message: vi.fn<() => void>(),
    warning: vi.fn<() => void>(),
  },
}))

const sharePhotos = vi.hoisted(() => vi.fn<(p: unknown, c: number) => Promise<unknown>>())

vi.mock('@/stores/child', () => ({
  useChildStore: () => ({
    children: [
      { id: 1, name: 'Ana', avatar_color: null },
      { id: 2, name: 'Bo', avatar_color: null },
    ],
    tryGetChildDEK: async () => null,
    isPhotoLocked: () => false,
    canUploadTo: () => true,
    canManageTags: true,
    canUploadAnywhere: true,
  }),
}))

vi.mock('@/stores/tags', () => ({
  cleanTagName: (n: string) => n.trim(),
  useTagsStore: () => ({ suggest: () => [], loadAll: async () => {} }),
}))

import BulkActions from '@/components/BulkActions.vue'
import PhotoTile from '@/components/feed/PhotoTile.vue'
import { MSG } from '@/lib/messages'
import { toast } from 'vue-sonner'
import type { Photo } from '@/types/photo'

function photo(id: number, childIds: number[], canDelete = true): Photo {
  return {
    id,
    can_delete: canDelete,
    tags: [],
    children: childIds.map((c) => ({
      id: c,
      name: `kid${c}`,
      avatar_color: null,
      encrypted_pek: 'p',
      iv_pek: 'i',
    })),
  } as unknown as Photo
}

beforeEach(() => {
  vi.clearAllMocks()
  setActivePinia(createPinia())
})

describe('BulkActions permissions', () => {
  it('hides remove when none of the selected photos can be removed', () => {
    const wrapper = mount(BulkActions, { props: { photos: [photo(1, [1], false)] }, attachTo: document.body })
    expect(wrapper.find('[data-test="bulk-remove"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="bulk-download"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('offers remove when at least one selected photo can be removed', () => {
    const wrapper = mount(BulkActions, {
      props: { photos: [photo(1, [1], false), photo(2, [1], true)] },
      attachTo: document.body,
    })
    expect(wrapper.find('[data-test="bulk-remove"]').exists()).toBe(true)
    wrapper.unmount()
  })
})

describe('BulkActions', () => {
  it('shows the count and a remove confirmation that names the limit of what is removed', async () => {
    const wrapper = mount(BulkActions, { props: { photos: [photo(1, [1]), photo(2, [1])] }, attachTo: document.body })
    expect(wrapper.get('[data-test="bulk-count"]').text()).toBe(MSG.selectedCount(2))

    await wrapper.get('[data-test="bulk-remove"]').trigger('click')
    const body = document.body.querySelector('[data-test="remove-body"]')
    expect(body?.textContent).toBe(MSG.bulkRemoveBody)

    removePhotos.mockResolvedValue({
      deleted: [1],
      detached: [2],
      forbidden: [],
      notProcessed: 0,
      cancelled: false,
      error: null,
    })
    ;(document.body.querySelector('[data-test="remove-confirm"]') as HTMLElement).click()
    await vi.waitFor(() => expect(wrapper.emitted('removed')).toBeTruthy())
    expect(wrapper.emitted('removed')![0]).toEqual([[1], 1])
    expect(wrapper.emitted('clear')).toBeTruthy()
    wrapper.unmount()
  })

  it('asks the parent to refetch after a cancelled remove, since the aborted batch may be committed', async () => {
    const wrapper = mount(BulkActions, { props: { photos: [photo(1, [1])] }, attachTo: document.body })
    await wrapper.get('[data-test="bulk-remove"]').trigger('click')
    removePhotos.mockResolvedValue({
      deleted: [],
      detached: [],
      forbidden: [],
      notProcessed: 1,
      cancelled: true,
      error: null,
    })
    ;(document.body.querySelector('[data-test="remove-confirm"]') as HTMLElement).click()
    await vi.waitFor(() => expect(wrapper.emitted('removed')).toBeTruthy())
    expect(wrapper.emitted('removed')![0]).toEqual([[], 1])
    wrapper.unmount()
  })

  it('warns instead of celebrating when nothing was shared', async () => {
    sharePhotos.mockResolvedValue({
      shared: [],
      skipped: [1],
      unwrappable: [],
      wraps: new Map(),
      notProcessed: 0,
      cancelled: false,
      error: null,
    })
    const wrapper = mount(BulkActions, { props: { photos: [photo(1, [1])] }, attachTo: document.body })
    await wrapper.get('[data-test="bulk-share"]').trigger('click')
    ;(document.body.querySelector('[data-test="share-target"]') as HTMLElement).click()
    await vi.waitFor(() => expect(toast.warning).toHaveBeenCalled())
    expect(toast.success).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('offers only kids that do not already link every selected photo, with the tag copy', async () => {
    const wrapper = mount(BulkActions, {
      props: { photos: [photo(1, [1]), photo(2, [1, 2])] },
      attachTo: document.body,
    })
    await wrapper.get('[data-test="bulk-share"]').trigger('click')
    const targets = [...document.body.querySelectorAll('[data-test="share-target"]')].map((el) =>
      el.textContent?.trim(),
    )
    expect(targets).toEqual(['Bo'])
    expect(document.body.querySelector('[data-test="share-hint"]')?.textContent).toContain(
      'Tags from the original kids do not carry over',
    )
    wrapper.unmount()
  })
})

describe('PhotoTile long press', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => vi.useRealTimers())

  const touch = (type: string) => new PointerEvent(type, { pointerType: 'touch', clientX: 5, clientY: 5 })

  it('emits longpress after 500 ms of touch and swallows the following click', async () => {
    const wrapper = mount(PhotoTile, { props: { photo: photo(1, [1]) } })
    const el = wrapper.element
    el.dispatchEvent(touch('pointerdown'))
    vi.advanceTimersByTime(499)
    expect(wrapper.emitted('longpress')).toBeUndefined()
    vi.advanceTimersByTime(1)
    expect(wrapper.emitted('longpress')).toHaveLength(1)

    await wrapper.trigger('click')
    expect(wrapper.emitted('click')).toBeUndefined()
    await wrapper.trigger('click')
    expect(wrapper.emitted('click')).toHaveLength(1)
  })

  it('cancels when released early, ignores the mouse, and shows a checkmark when selected', async () => {
    const wrapper = mount(PhotoTile, { props: { photo: photo(1, [1]), selectable: true, selected: true } })
    expect(wrapper.find('[data-test="tile-check"] svg').exists()).toBe(true)
    expect(wrapper.attributes('aria-pressed')).toBe('true')

    const plain = mount(PhotoTile, { props: { photo: photo(2, [1]) } })
    plain.element.dispatchEvent(touch('pointerdown'))
    vi.advanceTimersByTime(300)
    plain.element.dispatchEvent(touch('pointerup'))
    vi.advanceTimersByTime(500)
    expect(plain.emitted('longpress')).toBeUndefined()

    plain.element.dispatchEvent(new PointerEvent('pointerdown', { pointerType: 'mouse' }))
    vi.advanceTimersByTime(600)
    expect(plain.emitted('longpress')).toBeUndefined()
  })
})
