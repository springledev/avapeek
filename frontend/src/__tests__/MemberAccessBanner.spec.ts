import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'

vi.mock('@/services/api', () => ({
  api: {
    get: vi.fn<(url: string) => Promise<unknown>>(),
    post: vi.fn<(url: string) => Promise<unknown>>(),
  },
  default: { setAuthToken: vi.fn<() => void>(), clearAuthToken: vi.fn<() => void>() },
}))

vi.mock('@/lib/auth-storage', () => ({
  getSessionItem: vi.fn<(key: string) => string | null>(() => null),
  setSessionItem: vi.fn<(key: string, value: string) => void>(),
  removeSessionItem: vi.fn<(key: string) => void>(),
  clearSession: vi.fn<() => void>(),
  removePendingInviteToken: vi.fn<() => void>(),
  setStaySignedIn: vi.fn<(value: boolean) => void>(),
}))

import FeedView from '@/views/FeedView.vue'
import { api } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useChildStore } from '@/stores/child'
import { useFeedStore } from '@/stores/feed'
import type { Child } from '@/types/child'

let wrapper: VueWrapper | null = null

function makeChild(id: number, name: string, role = 'member'): Child {
  return {
    id,
    slug: name.toLowerCase(),
    name,
    birth_date: null,
    avatar_color: '#fff',
    age: null,
    role,
    relationship: null,
    storage_used: 0,
    storage_limit_bytes: null,
    storage_percentage: 0,
    created_at: '',
  }
}

async function mountFeed(children: Child[]) {
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().user = { id: 7 } as never
  const childStore = useChildStore()
  childStore.children = children
  childStore.childrenLoaded = true
  vi.spyOn(useFeedStore(), 'fetchPhotos').mockResolvedValue()

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: FeedView },
      { path: '/setup', component: { template: '<div />' } },
      { path: '/upload', component: { template: '<div />' } },
    ],
  })
  router.push('/')
  await router.isReady()
  wrapper = mount(FeedView, {
    global: {
      plugins: [pinia, router],
      stubs: { FeedToolbar: true, PhotoViewer: true, PhotoTile: true, CreateChildSheet: true },
    },
  })
  await flushPromises()
}

const banners = () => wrapper!.findAll('[data-test="member-access-banner"]')

describe('member access banner in the feed', () => {
  beforeEach(() => {
    localStorage.clear()
    vi.mocked(api.post).mockReset()
  })

  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
    vi.restoreAllMocks()
  })

  it('shows a banner per locked album and the locked empty state without add photos', async () => {
    await mountFeed([makeChild(1, 'Wren'), makeChild(2, 'Ivy')])

    expect(banners()).toHaveLength(2)
    expect(banners()[0]!.text()).toContain("Waiting for access to Wren's photos")
    expect(wrapper!.find('[data-test="feed-locked-empty"]').exists()).toBe(true)
    expect(wrapper!.text()).not.toContain('Add photos')
  })

  it('keeps the normal empty state when some album is open', async () => {
    await mountFeed([makeChild(1, 'Wren'), makeChild(2, 'Ivy', 'owner')])

    expect(banners()).toHaveLength(1)
    expect(wrapper!.find('[data-test="feed-locked-empty"]').exists()).toBe(false)
    expect(wrapper!.text()).toContain('Add photos')
  })

  it('sends a reminder and shows the sent state', async () => {
    vi.mocked(api.post).mockResolvedValue({ status: 202, data: { sent: true } })
    await mountFeed([makeChild(1, 'Wren')])

    await wrapper!.find('[data-test="remind-admins"]').trigger('click')
    await flushPromises()

    expect(api.post).toHaveBeenCalledWith('/children/1/access-reminder')
    expect(wrapper!.find('[data-test="access-status-sent"]').exists()).toBe(true)
    expect(wrapper!.find('[data-test="remind-admins"]').exists()).toBe(false)
  })

  it('shows already sent on a 429', async () => {
    vi.mocked(api.post).mockRejectedValue({ response: { status: 429, data: { retry_after: 60 } } })
    await mountFeed([makeChild(1, 'Wren')])

    await wrapper!.find('[data-test="remind-admins"]').trigger('click')
    await flushPromises()

    expect(wrapper!.find('[data-test="access-status-already"]').text()).toBe(
      'Reminder already sent today',
    )
  })

  it('shows an error and allows retrying', async () => {
    vi.mocked(api.post).mockRejectedValue({ response: { status: 500, data: {} } })
    await mountFeed([makeChild(1, 'Wren')])

    await wrapper!.find('[data-test="remind-admins"]').trigger('click')
    await flushPromises()

    expect(wrapper!.find('[data-test="access-status-error"]').exists()).toBe(true)
    expect(wrapper!.find('[data-test="remind-admins"]').exists()).toBe(true)
  })

  it('disappears when the key arrives', async () => {
    await mountFeed([makeChild(1, 'Wren')])
    expect(banners()).toHaveLength(1)

    useAuthStore().setEncryptionKeys([
      { child_id: 1, child_name: 'Wren', encrypted_dek: 'x', wrap_type: 'kek', key_version: 1 },
    ])
    await flushPromises()

    expect(banners()).toHaveLength(0)
    expect(wrapper!.find('[data-test="feed-locked-empty"]').exists()).toBe(false)
  })
})
