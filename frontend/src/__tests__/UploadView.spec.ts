import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { defineComponent } from 'vue'

const route = { query: {} as Record<string, string> }
const replace = vi.fn<(to: unknown) => Promise<void>>(async () => undefined)

vi.mock('vue-router', () => ({
  useRoute: () => route,
  useRouter: () => ({ replace, push: vi.fn<(to: unknown) => Promise<void>>() }),
}))

vi.mock('@/lib/share-inbox', () => ({
  pickUpSharedFiles: vi.fn<(isSharedVisit: boolean) => Promise<File[]>>(),
}))

vi.mock('@/services/api', () => ({
  api: { get: vi.fn<(url: string) => Promise<unknown>>(), post: vi.fn<(url: string) => Promise<unknown>>() },
  default: {},
}))

vi.mock('@/crypto/thumbnails', () => ({
  generateThumbnail: vi.fn<() => Promise<unknown>>(),
  generateLQIP: vi.fn<() => Promise<unknown>>(),
}))

vi.mock('@/crypto/heic', () => ({
  decodeHeic: vi.fn<() => Promise<unknown>>(),
  isHeic: vi.fn<() => boolean>(() => false),
}))

import UploadView from '@/views/UploadView.vue'
import { pickUpSharedFiles } from '@/lib/share-inbox'

const BatchReviewStub = defineComponent({
  props: { open: Boolean, files: { type: Array, default: () => [] } },
  template: '<div data-testid="batch" :data-open="open" :data-count="files.length" />',
})

function mountView() {
  setActivePinia(createPinia())
  return mount(UploadView, {
    global: {
      stubs: {
        UploadPicker: true,
        UiProgressOverlay: true,
        BatchReview: BatchReviewStub,
      },
    },
  })
}

describe('UploadView share pickup', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    route.query = {}
  })

  it('opens the batch review with shared files and clears the query', async () => {
    route.query = { shared: '1' }
    vi.mocked(pickUpSharedFiles).mockResolvedValue([
      new File(['a'], 'a.jpg', { type: 'image/jpeg' }),
      new File(['b'], 'b.jpg', { type: 'image/jpeg' }),
    ])

    const wrapper = mountView()
    await flushPromises()

    expect(pickUpSharedFiles).toHaveBeenCalledWith(true)
    expect(replace).toHaveBeenCalledWith({ name: 'upload' })
    const batch = wrapper.get('[data-testid="batch"]')
    expect(batch.attributes('data-open')).toBe('true')
    expect(batch.attributes('data-count')).toBe('2')
  })

  it('only purges on a normal visit and opens nothing', async () => {
    vi.mocked(pickUpSharedFiles).mockResolvedValue([])

    const wrapper = mountView()
    await flushPromises()

    expect(pickUpSharedFiles).toHaveBeenCalledWith(false)
    expect(replace).not.toHaveBeenCalled()
    expect(wrapper.get('[data-testid="batch"]').attributes('data-open')).toBe('false')
  })

  it('survives an inbox failure', async () => {
    route.query = { shared: '1' }
    vi.mocked(pickUpSharedFiles).mockRejectedValue(new Error('idb down'))
    vi.spyOn(console, 'warn').mockImplementation(() => undefined)

    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.get('[data-testid="batch"]').attributes('data-open')).toBe('false')
  })
})
