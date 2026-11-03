import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory, type Router } from 'vue-router'

/**
 * Counts every API request a signed-in session makes. Only the api client is
 * mocked (plus decryption, which makes no requests), so App.vue, the shell, the
 * views, the viewer and every store run for real.
 */
const requests: string[] = []
let blobCounter = 0

function normalise(url: string): string {
  return url.replace(/\/\d+/g, '/:id')
}

function photo(id: number) {
  return {
    id,
    uuid: `photo-${id}`,
    uploaded_by: 7,
    original_path: `o/${id}`,
    thumbnail_path: `t/${id}`,
    thumbnail_url: `https://files.test/t/${id}`,
    iv_original: 'iv',
    iv_thumbnail: 'iv',
    original_mime: 'image/jpeg',
    size_bytes: 1000,
    width: 10,
    height: 10,
    exif_encrypted: null,
    iv_exif: null,
    taken_at: '2026-09-01T00:00:00Z',
    created_at: '2026-09-01T00:00:00Z',
    tags: [],
    children: [{ id: 1, name: 'Mia', avatar_color: null, encrypted_pek: 'pek', iv_pek: 'iv' }],
    comment_count: 0,
  }
}

const children = [
  { id: 1, name: 'Mia', slug: 'mia', role: 'owner', birth_date: null, avatar_color: null },
  { id: 2, name: 'Leo', slug: 'leo', role: 'admin', birth_date: null, avatar_color: null },
]

function reply(url: string, params?: { page?: string | number }) {
  if (url === '/children') return { data: children }
  if (/^\/children\/\d+$/.test(url)) return { data: { ...children[0], members: [] } }
  if (url === '/photos' || url === '/recents') {
    const page = Number(params?.page ?? 1)
    const base = (page - 1) * 20
    return {
      data: Array.from({ length: 20 }, (_, i) => photo(base + i + 1)),
      meta: { current_page: page, last_page: 5, per_page: 20, total: 100 },
    }
  }
  if (url === '/recents/unseen-count') return { data: { count: 0 } }
  if (url === '/memories') return { data: [] }
  if (url.endsWith('/comments')) {
    return { data: [], meta: { current_page: 1, last_page: 1, per_page: 50, total: 0 } }
  }
  if (/^\/photos\/\d+$/.test(url)) return { data: { presigned_url: 'https://files.test/o' } }
  return { data: [] }
}

vi.mock('@/services/api', () => ({
  api: {
    get: vi.fn<(url: string, config?: { params?: { page?: string | number } }) => Promise<unknown>>(
      async (url, config) => {
      requests.push(normalise(url))
        return { data: reply(url, config?.params), status: 200 }
      },
    ),
    post: vi.fn<(url: string) => Promise<unknown>>(async (url) => {
      requests.push(`POST ${normalise(url)}`)
      return { data: {}, status: 200 }
    }),
    delete: vi.fn<(url: string) => Promise<unknown>>(),
  },
  default: { setAuthToken: vi.fn<() => void>(), clearAuthToken: vi.fn<() => void>() },
}))

vi.mock('@/services/push', () => ({
  resyncPushSubscription: vi.fn<() => Promise<void>>(async () => undefined),
  getPreferences: vi.fn<() => Promise<unknown[]>>(async () => []),
}))

vi.mock('@/composables/usePushNotifications', () => ({
  usePushNotifications: () => ({
    init: vi.fn<() => Promise<void>>(async () => undefined),
    available: { value: false },
  }),
}))

vi.mock('@/services/storage', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/services/storage')>()
  return {
    ...actual,
    fetchEncryptedBlob: vi.fn<() => Promise<ArrayBuffer>>(async () => new ArrayBuffer(8)),
  }
})

// jsdom has no IndexedDB, so the real cache would re-fetch what a browser keeps on disk.
// Model the browser: each photo's thumbnail and original is fetched once, then served locally.
vi.mock('@/lib/cache/decrypted-cache', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/cache/decrypted-cache')>()
  const thumbs = new Map<number, Promise<string>>()
  const originals = new Map<number, Promise<string>>()
  const through =
    (store: Map<number, Promise<string>>) =>
    (id: number, ...args: unknown[]) => {
      const load = args[args.length - 1] as () => Promise<Blob>
      if (!store.has(id)) store.set(id, load().then(() => `blob:${id}`))
      return store.get(id)!
    }
  return {
    ...actual,
    getThumbnail: through(thumbs),
    getOriginal: through(originals),
    warmCache: vi.fn<() => Promise<void>>(async () => undefined),
  }
})

vi.mock('@/crypto/keychain', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/crypto/keychain')>()
  return { ...actual, unwrapPEK: vi.fn<() => Promise<CryptoKey>>(async () => ({}) as CryptoKey) }
})

vi.mock('@/crypto/encrypt', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/crypto/encrypt')>()
  return {
    ...actual,
    decryptBlob: vi.fn<() => Promise<ArrayBuffer>>(async () => new ArrayBuffer(0)),
    decryptString: vi.fn<() => Promise<string>>(async () => 'x'),
  }
})

import App from '@/App.vue'
import PhotoViewer from '@/components/PhotoViewer.vue'
import PhotoTile from '@/components/feed/PhotoTile.vue'
import { useAuthStore } from '@/stores/auth'
import { useChildStore } from '@/stores/child'
import type { User } from '@/types/auth'

const stub = { template: '<div />' }

function makeRouter(): Router {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/feed', component: () => import('@/views/FeedView.vue') },
      { path: '/recents', component: () => import('@/views/RecentsView.vue') },
      {
        path: '/settings',
        component: () => import('@/views/SettingsView.vue'),
        children: [
          { path: 'children', component: () => import('@/views/ChildrenSettingsView.vue') },
          {
            path: 'children/:id',
            component: () => import('@/views/ChildSettingsView.vue'),
            props: true,
          },
        ],
      },
      { path: '/upload', component: stub },
      { path: '/login', component: stub },
    ],
  })
}

function tally(list: string[]): Record<string, number> {
  const out: Record<string, number> = {}
  for (const url of list) out[url] = (out[url] ?? 0) + 1
  return out
}

async function settle() {
  for (let i = 0; i < 6; i++) {
    await flushPromises()
    await new Promise((r) => setTimeout(r, 20))
  }
}

describe('request budget', () => {
  let wrapper: VueWrapper
  let router: Router

  beforeEach(async () => {
    requests.length = 0
    vi.stubGlobal('requestIdleCallback', (cb: () => void) => setTimeout(cb, 0))
    vi.stubGlobal('matchMedia', () => ({
      matches: false,
      addEventListener: () => undefined,
      removeEventListener: () => undefined,
      addListener: () => undefined,
      removeListener: () => undefined,
    }))
    class NoopObserver {
      observe(): void {}
      unobserve(): void {}
      disconnect(): void {}
    }
    vi.stubGlobal('URL', Object.assign(URL, { createObjectURL: () => `blob:${++blobCounter}`, revokeObjectURL: () => undefined }))
    vi.stubGlobal('IntersectionObserver', NoopObserver)
    vi.stubGlobal('ResizeObserver', NoopObserver)
    const pinia = createPinia()
    setActivePinia(pinia)
    const auth = useAuthStore()
    auth.user = { id: 7, display_name: 'Ana' } as User
    auth.kek = {} as CryptoKey
    vi.spyOn(useChildStore(), 'tryGetChildDEK').mockResolvedValue({} as CryptoKey)
    router = makeRouter()
    await router.push('/feed')
    await router.isReady()
    wrapper = mount(App, { global: { plugins: [pinia, router] }, attachTo: document.body })
    await settle()
  })

  afterEach(() => {
    wrapper?.unmount()
    vi.unstubAllGlobals()
  })

  it('keeps a page load, page changes and viewer steps within budget', async () => {
    // Before the de-duplication work, the same session measured 15 requests for the page
    // load (3x /photos, 2x pending-grants, 4x comments), 2.0 per page change and 2.1 per
    // viewer step. The viewer figure is mostly the comments and original of each new photo.
    const load = tally(requests)
    expect(load['/photos']).toBe(2) // first page, plus the next page read ahead
    expect(load['/encryption-keys/pending-grants']).toBe(1)
    expect(load['/children']).toBe(1)
    expect(load['/encryption-keys']).toBe(1)
    expect(load['/photos/:id/comments']).toBeUndefined()
    expect(requests.length).toBeLessThanOrEqual(9)

    const tour = ['/recents', '/feed', '/settings', '/settings/children', '/settings/children/1', '/feed']
    let navRequests = 0
    for (const path of tour) {
      requests.length = 0
      await router.push(path)
      await settle()
      navRequests += requests.length
      expect(tally(requests)['/encryption-keys/pending-grants']).toBeUndefined()
      expect(tally(requests)['/children']).toBeUndefined()
    }
    expect(navRequests / tour.length).toBeLessThanOrEqual(1)

    await wrapper.findComponent(PhotoTile).trigger('click')
    await settle()
    expect(wrapper.findComponent(PhotoViewer).exists()).toBe(true)
    requests.length = 0
    const steps = 10
    for (let i = 0; i < steps; i++) {
      wrapper.findComponent(PhotoViewer).vm.$emit('next')
      await settle()
    }
    expect(requests.length / steps).toBeLessThanOrEqual(2.2)
  }, 60000)
})
