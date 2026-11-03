import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import type { AxiosResponse } from 'axios'

vi.mock('@/services/api', () => ({
  api: {
    get: vi.fn<(url: string, config?: unknown) => Promise<unknown>>(),
    post: vi.fn<(url: string, data?: unknown) => Promise<unknown>>(),
    put: vi.fn<(url: string, data?: unknown) => Promise<unknown>>(),
  },
  default: {},
}))

vi.mock('@/services/key-sync', () => ({
  syncEncryptionKeys: vi.fn<() => Promise<{ granted: number; failed: number }>>(),
}))

vi.mock('vue-sonner', () => ({
  toast: { success: vi.fn<() => void>(), error: vi.fn<() => void>() },
}))

import { api } from '@/services/api'
import { useFeedStore } from '@/stores/feed'
import { useRecentsStore } from '@/stores/recents'
import { useChildStore } from '@/stores/child'
import { useAuthStore } from '@/stores/auth'
import { useTagsStore } from '@/stores/tags'
import ChildSettingsView from '@/views/ChildSettingsView.vue'
import ChildrenSettingsView from '@/views/ChildrenSettingsView.vue'
import RecentsView from '@/views/RecentsView.vue'
import FeedView from '@/views/FeedView.vue'
import type { Photo } from '@/types/photo'

function ok<T>(data: T): AxiosResponse<T> {
  return { data, status: 200, statusText: 'OK', headers: {}, config: {} as never }
}

const page = (photos: Photo[], current = 1, last = 1) =>
  ok({ data: photos, meta: { current_page: current, last_page: last } })

const photo = { id: 1, created_at: '2026-01-01T00:00:00Z', children: [] } as unknown as Photo

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/settings/children/:id', component: ChildSettingsView },
    ],
  })
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(api.get).mockReset()
  vi.mocked(api.put).mockReset()
  vi.spyOn(console, 'error').mockImplementation(() => {})
  vi.spyOn(useTagsStore(), 'decryptPhotoTags').mockImplementation(async (p) => p as unknown as Photo[])
})

describe('feed and recents stores', () => {
  it('keeps photos on screen when a refresh fails', async () => {
    const feed = useFeedStore()
    vi.mocked(api.get).mockResolvedValueOnce(page([photo]))
    await feed.fetchPhotos()
    vi.mocked(api.get).mockRejectedValueOnce(new Error('offline'))
    await feed.fetchPhotos()
    expect(feed.photos).toHaveLength(1)
    expect(feed.loadFailed).toBe(true)
    vi.mocked(api.get).mockResolvedValueOnce(page([photo]))
    await feed.fetchPhotos()
    expect(feed.loadFailed).toBe(false)
  })

  it('does not skip a page when loading more fails', async () => {
    const feed = useFeedStore()
    vi.mocked(api.get).mockResolvedValueOnce(page([photo], 1, 3))
    await feed.fetchPhotos()
    vi.mocked(api.get).mockRejectedValueOnce(new Error('offline'))
    await feed.fetchMore()
    expect(feed.page).toBe(1)
  })

  it('keeps recents on screen when a refresh fails', async () => {
    const recents = useRecentsStore()
    vi.mocked(api.get).mockResolvedValueOnce(page([photo]))
    await recents.fetchRecents()
    vi.mocked(api.get).mockRejectedValueOnce(new Error('offline'))
    await recents.fetchRecents()
    expect(recents.photos).toHaveLength(1)
    expect(recents.loadFailed).toBe(true)
  })
})

describe('views show an error, not an empty state, when loading fails', () => {
  it('feed: error with retry, then empty state only after a successful empty fetch', async () => {
    const child = useChildStore()
    child.childrenLoaded = true
    child.children = [{ id: 1, name: 'Mia', role: 'owner' } as never]
    vi.mocked(api.get).mockRejectedValue(new Error('offline'))
    const wrapper = mount(FeedView, {
      global: { plugins: [makeRouter()], stubs: { teleport: true } },
    })
    await flushPromises()
    expect(wrapper.find('[data-test="load-error"]').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('No photos yet')

    vi.mocked(api.get).mockReset()
    vi.mocked(api.get).mockResolvedValue(page([]))
    await wrapper.get('[data-test="load-error-retry"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="load-error"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('No photos yet')
  })

  it('recents: error with retry, empty only on success', async () => {
    vi.mocked(api.get).mockRejectedValue(new Error('offline'))
    vi.mocked(api.put).mockResolvedValue(ok({}))
    const wrapper = mount(RecentsView, {
      global: { plugins: [makeRouter()], stubs: { teleport: true } },
    })
    await flushPromises()
    expect(wrapper.find('[data-test="load-error"]').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('No recent uploads')

    vi.mocked(api.get).mockReset()
    vi.mocked(api.get).mockResolvedValue(page([]))
    await wrapper.get('[data-test="load-error-retry"]').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('No recent uploads')
  })

  it('kids list: error with retry, empty only on success', async () => {
    vi.mocked(api.get).mockRejectedValue(new Error('offline'))
    const wrapper = mount(ChildrenSettingsView, {
      global: { plugins: [makeRouter()], stubs: { teleport: true } },
    })
    await flushPromises()
    expect(wrapper.find('[data-test="load-error"]').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('No kids yet')

    vi.mocked(api.get).mockReset()
    vi.mocked(api.get).mockResolvedValue(ok({ data: [] }))
    await wrapper.get('[data-test="load-error-retry"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="load-error"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('No kids yet')
  })

  it('child settings: member list error, not "No family members yet"', async () => {
    useAuthStore().user = { id: 1, email: 'a@b.c', display_name: 'Me' } as never
    useChildStore().children = [{ id: 7, name: 'Emma', role: 'owner' } as never]
    vi.mocked(api.get).mockImplementation(async (url: string) => {
      if (url === '/invites/pending') return ok({ data: [] })
      throw new Error('offline')
    })
    const router = makeRouter()
    await router.push('/settings/children/7')
    const wrapper = mount(ChildSettingsView, {
      global: { plugins: [router], stubs: { teleport: true } },
    })
    await flushPromises()
    expect(wrapper.find('[data-test="load-error"]').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('No family members yet')

    vi.mocked(api.get).mockImplementation(async (url: string) =>
      url === '/invites/pending' ? ok({ data: [] }) : ok({ data: { members: [] } }),
    )
    await wrapper.get('[data-test="load-error-retry"]').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('No family members yet')
  })
})
