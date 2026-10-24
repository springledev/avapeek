import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import { defineComponent, ref } from 'vue'

vi.mock('@/services/api', () => ({
  api: { get: vi.fn<(url: string) => Promise<unknown>>(), post: vi.fn<() => Promise<unknown>>() },
  default: { setAuthToken: vi.fn<() => void>(), clearAuthToken: vi.fn<() => void>() },
}))

vi.mock('vue-sonner', () => ({
  toast: { error: vi.fn<(m: string) => void>(), success: vi.fn<(m: string) => void>() },
}))

const failedFile = new File(['b'], 'bad.jpg', { type: 'image/jpeg' })
const goodFile = new File(['g'], 'good.jpg', { type: 'image/jpeg' })

const errors = ref<{ file: File; error: unknown }[]>([])
const uploadMock = vi.fn<(files: File[], childIds: number[]) => Promise<unknown>>()
vi.mock('@/composables/useUploadPhotos', () => ({
  useUploadPhotos: () => ({
    isUploading: ref(false),
    current: ref(0),
    total: ref(0),
    fileName: ref(''),
    errors,
    upload: uploadMock,
  }),
}))

import UploadView from '@/views/UploadView.vue'
import { toast } from 'vue-sonner'
import { MSG } from '@/lib/messages'

const BatchReviewStub = defineComponent({
  props: { open: Boolean, files: { type: Array as () => File[], default: () => [] } },
  emits: ['close', 'upload'],
  template: '<div class="review">{{ files.map((f) => f.name).join(",") }}</div>',
})

const PickerStub = defineComponent({
  emits: ['select'],
  template: '<div class="pick" />',
})

let wrapper: VueWrapper | null = null

describe('UploadView partial failure', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    errors.value = []
    uploadMock.mockReset()
    vi.mocked(toast.error).mockReset()
  })

  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
  })

  it('keeps only the failed file in the review and reports how many succeeded', async () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/', component: UploadView },
        { path: '/feed', component: { template: '<div />' } },
      ],
    })
    router.push('/')
    await router.isReady()
    wrapper = mount(UploadView, {
      global: {
        plugins: [router],
        stubs: { BatchReview: BatchReviewStub, UploadPicker: PickerStub },
      },
    })
    uploadMock.mockImplementation(async () => {
      errors.value = [{ file: failedFile, error: new Error('x') }]
      return { succeeded: 1, failed: 1 }
    })

    wrapper.findComponent(PickerStub).vm.$emit('select', [goodFile, failedFile])
    await flushPromises()
    expect(wrapper.find('.review').text()).toBe('good.jpg,bad.jpg')

    wrapper.findComponent(BatchReviewStub).vm.$emit('upload', [goodFile, failedFile], [3])
    await flushPromises()

    expect(wrapper.find('.review').text()).toBe('bad.jpg')
    expect(toast.error).toHaveBeenCalledWith(MSG.uploadPartial(1, 2))
    expect(vi.mocked(toast.error).mock.calls[0]![0]).toContain(MSG.uploadFailed)
    expect(router.currentRoute.value.path).toBe('/')
  })
})
