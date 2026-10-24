import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'

vi.mock('@/services/api', () => ({
  api: { get: vi.fn<(url: string) => Promise<unknown>>(), post: vi.fn<() => Promise<unknown>>() },
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
import { useAuthStore } from '@/stores/auth'
import { useChildStore } from '@/stores/child'
import { useFeedStore } from '@/stores/feed'
import type { Child } from '@/types/child'

let wrapper: VueWrapper | null = null

function makeChild(photoCount: number): Child {
  return {
    id: 3,
    slug: 'emma',
    name: 'Emma',
    birth_date: null,
    avatar_color: '#fff',
    age: null,
    role: 'owner',
    relationship: 'parent',
    storage_used: 0,
    storage_limit_bytes: null,
    storage_percentage: 0,
    photo_count: photoCount,
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

const card = () => wrapper!.find('[data-test="finish-setup-card"]')

describe('Finish setting up card', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
    vi.restoreAllMocks()
    localStorage.clear()
  })

  it('shows with zero kids and links to step 1', async () => {
    await mountFeed([])

    expect(card().exists()).toBe(true)
    expect(wrapper!.find('[data-test="finish-setup-continue"]').attributes('href')).toBe(
      '/setup?step=1',
    )
  })

  it('shows with a kid but no photos and links to step 2', async () => {
    await mountFeed([makeChild(0)])

    expect(card().exists()).toBe(true)
    expect(wrapper!.find('[data-test="finish-setup-continue"]').attributes('href')).toBe(
      '/setup?step=2',
    )
  })

  it('is hidden when a kid and photos exist', async () => {
    await mountFeed([makeChild(2)])

    expect(card().exists()).toBe(false)
  })

  it('hides after dismiss and stores the flag per user', async () => {
    await mountFeed([])

    await wrapper!.find('[data-test="finish-setup-dismiss"]').trigger('click')

    expect(card().exists()).toBe(false)
    expect(localStorage.getItem('avapeek.setup.dismissed.7')).toBe('1')
  })

  it('stays hidden on a later visit once dismissed', async () => {
    localStorage.setItem('avapeek.setup.dismissed.7', '1')
    await mountFeed([])

    expect(card().exists()).toBe(false)
  })

  it('dismisses for the session when localStorage throws', async () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('denied')
    })
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('denied')
    })
    await mountFeed([])

    expect(card().exists()).toBe(true)
    await wrapper!.find('[data-test="finish-setup-dismiss"]').trigger('click')

    expect(card().exists()).toBe(false)
  })
})
