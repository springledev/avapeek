import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

vi.mock('@/services/api', () => ({
  api: { get: vi.fn<() => Promise<unknown>>(), post: vi.fn<() => Promise<unknown>>() },
  default: { setAuthToken: vi.fn<() => void>(), clearAuthToken: vi.fn<() => void>() },
}))

vi.mock('@/services/storage', () => ({
  getPEKForPhoto: vi.fn<() => Promise<CryptoKey | null>>(async () => null),
  fetchThumbnail: vi.fn<() => Promise<string>>(async () => 'blob:thumb'),
  fetchOriginal: vi.fn<() => Promise<string>>(async () => 'blob:original'),
  fetchAndDecrypt: vi.fn<() => Promise<ArrayBuffer>>(),
  cleanupComponent: vi.fn<() => void>(),
  preloadOriginal: vi.fn<() => Promise<void>>(),
}))

vi.mock('@/lib/cache/decrypted-cache', () => ({
  getThumbnail: vi.fn<(id: number, load: () => Promise<Blob>) => Promise<string>>(
    async (_id, load) => {
      await load()
      return 'blob:t'
    },
  ),
  clearDecryptedCache: vi.fn<() => void>(),
  setDecryptedCacheUser: vi.fn<() => void>(),
}))

import PhotoTile from '@/components/feed/PhotoTile.vue'
import PhotoViewer from '@/components/PhotoViewer.vue'
import { getPEKForPhoto } from '@/services/storage'
import { useAuthStore } from '@/stores/auth'
import { useChildStore } from '@/stores/child'
import type { Child } from '@/types/child'
import type { Photo } from '@/types/photo'

function makeChild(id: number, role: string): Child {
  return {
    id,
    slug: `k${id}`,
    name: `Kid${id}`,
    birth_date: null,
    avatar_color: null,
    age: null,
    role,
    relationship: null,
    storage_used: 0,
    storage_limit_bytes: null,
    storage_percentage: 0,
    created_at: '',
  }
}

function makePhoto(childIds: number[]): Photo {
  return {
    id: 5,
    uuid: 'p',
    iv_thumbnail: 'iv',
    thumbnail_url: 'https://x/t',
    created_at: '2026-09-01T00:00:00Z',
    tags: [],
    comment_count: 0,
    size_bytes: 1,
    children: childIds.map((id) => ({
      id,
      name: `Kid${id}`,
      avatar_color: null,
      encrypted_pek: 'a',
      iv_pek: 'b',
    })),
  } as unknown as Photo
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(getPEKForPhoto).mockReset().mockResolvedValue(null)
  vi.stubGlobal(
    'IntersectionObserver',
    class {
      observe() {}
      unobserve() {}
      disconnect() {}
    },
  )
})

describe('locked photo placeholders', () => {
  it('PhotoTile shows a labelled lock while all of the photo albums are locked', async () => {
    const childStore = useChildStore()
    childStore.children = [makeChild(1, 'member'), makeChild(2, 'member')]
    const wrapper = mount(PhotoTile, { props: { photo: makePhoto([1, 2]) } })

    expect(wrapper.attributes('aria-label')).toBe('Locked until an admin shares access')
    expect(wrapper.find('[data-test="tile-locked"]').exists()).toBe(true)

    useAuthStore().setEncryptionKeys([
      { child_id: 2, child_name: 'K', encrypted_dek: 'd', wrap_type: 'kek', key_version: 1 },
    ])
    await flushPromises()
    expect(wrapper.find('[data-test="tile-locked"]').exists()).toBe(false)
    expect(wrapper.attributes('aria-label')).toBeUndefined()
  })

  it('PhotoTile is not locked when one album is open (owner)', () => {
    useChildStore().children = [makeChild(1, 'member'), makeChild(2, 'owner')]
    const wrapper = mount(PhotoTile, { props: { photo: makePhoto([1, 2]) } })
    expect(wrapper.find('[data-test="tile-locked"]').exists()).toBe(false)
  })

  it('PhotoViewer shows the locked message instead of retry', async () => {
    useChildStore().children = [makeChild(1, 'member')]
    const wrapper = mount(PhotoViewer, {
      props: {
        photo: makePhoto([1]),
        hasPrev: false,
        hasNext: false,
        canDelete: false,
        canEdit: false,
        childBirthDate: null,
      },
      global: { stubs: { teleport: true } },
    })
    await flushPromises()

    const locked = wrapper.find('[data-test="viewer-locked"]')
    expect(locked.exists()).toBe(true)
    expect(locked.text()).toContain('locked until an album admin shares access')
    expect(wrapper.text()).not.toContain('tap to retry')
  })

  it('PhotoViewer keeps the retry message for unlocked albums that fail', async () => {
    useChildStore().children = [makeChild(1, 'owner')]
    const wrapper = mount(PhotoViewer, {
      props: {
        photo: makePhoto([1]),
        hasPrev: false,
        hasNext: false,
        canDelete: false,
        canEdit: false,
        childBirthDate: null,
      },
      global: { stubs: { teleport: true } },
    })
    await flushPromises()
    expect(wrapper.text()).toContain('tap to retry')
  })
})
