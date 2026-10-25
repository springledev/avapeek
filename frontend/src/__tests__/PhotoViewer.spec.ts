import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia, type Pinia } from 'pinia'
import type { AxiosResponse } from 'axios'
import { nextTick } from 'vue'

vi.mock('@/services/api', () => ({
  api: {
    get: vi.fn<(url: string, config?: unknown) => Promise<unknown>>(),
    post: vi.fn<(url: string, data?: unknown) => Promise<unknown>>(),
    delete: vi.fn<(url: string) => Promise<unknown>>(),
  },
  default: {
    setAuthToken: vi.fn<(token: string) => void>(),
    clearAuthToken: vi.fn<() => void>(),
  },
}))

vi.mock('@/crypto/encrypt', () => ({
  encryptString: vi.fn<(text: string, dek: CryptoKey) => Promise<{ encrypted: string; iv: string }>>(),
  decryptBlob: vi.fn<(buffer: ArrayBuffer, dek: CryptoKey, iv: string) => Promise<ArrayBuffer>>(),
}))

vi.mock('@/services/storage', () => ({
  getPEKForPhoto: vi.fn<(children: PhotoChild[]) => Promise<CryptoKey | null>>(
    async () => ({}) as unknown as CryptoKey,
  ),
  fetchThumbnail: vi.fn<(id: number) => Promise<string>>(async () => 'blob:thumb'),
  fetchOriginal: vi.fn<() => Promise<string>>(async () => 'blob:original'),
  cleanupComponent: vi.fn<() => void>(),
  fetchAndDecrypt: vi.fn<() => Promise<ArrayBuffer>>(),
  preloadOriginal: vi.fn<() => Promise<void>>(),
}))

import PhotoViewer from '@/components/PhotoViewer.vue'
import { api } from '@/services/api'
import { decryptBlob } from '@/crypto/encrypt'
import { fetchThumbnail } from '@/services/storage'
import { useChildStore } from '@/stores/child'
import { useCommentsStore } from '@/stores/comments'
import type { Child } from '@/types/child'
import type {
  CommentListResponse,
  DecryptedComment,
  Photo,
  PhotoChild,
  PhotoComment,
} from '@/types/photo'

const HEX_IV = '00112233445566778899aabbccddeeff'

function ok<T>(data: T): AxiosResponse<T> {
  return {
    data,
    status: 200,
    statusText: 'OK',
    headers: {},
    config: { headers: {} },
  } as unknown as AxiosResponse<T>
}

function makePhotoComment(id: number, createdAt: string): PhotoComment {
  return {
    id,
    user_id: 7,
    author_name: `Ana ${id}`,
    body_encrypted: btoa('ciphertext'),
    iv_body: HEX_IV,
    created_at: createdAt,
  }
}

function makeListResponse(items: PhotoComment[]): CommentListResponse {
  return {
    data: items,
    meta: { current_page: 1, last_page: 1, per_page: 50, total: items.length },
  }
}

function encoded(text: string): ArrayBuffer {
  return new TextEncoder().encode(text).buffer as ArrayBuffer
}

function makePhoto(overrides: Partial<Photo> = {}): Photo {
  return {
    id: 5,
    uuid: 'photo-5',
    uploaded_by: 1,
    original_path: 'originals/5.jpg',
    thumbnail_path: 'thumbs/5.webp',
    thumbnail_url: 'https://example.test/thumb',
    iv_original: 'iv-orig',
    iv_thumbnail: 'iv-thumb',
    original_mime: 'image/jpeg',
    size_bytes: 1024,
    width: 800,
    height: 600,
    exif_encrypted: null,
    iv_exif: null,
    taken_at: null,
    created_at: '2026-09-01T00:00:00.000Z',
    tags: [],
    children: [
      { id: 1, name: 'Mia', avatar_color: null, encrypted_pek: 'pek', iv_pek: 'iv' },
    ],
    comment_count: 3,
    ...overrides,
  }
}

function makeChild(): Child {
  return {
    id: 1,
    slug: 'mia',
    name: 'Mia',
    birth_date: null,
    avatar_color: null,
    age: '4',
    role: 'owner',
    relationship: 'parent',
    storage_used: 0,
    storage_limit_bytes: 100,
    storage_percentage: 0,
    created_at: '2026-01-01T00:00:00.000Z',
  }
}

function makeDecrypted(id: number, text: string): DecryptedComment {
  return {
    id,
    user_id: 7,
    author_name: `Ana ${id}`,
    text,
    created_at: `2026-09-17T1${id}:00:00.000Z`,
  }
}

function mountViewer(photo: Photo) {
  const pinia: Pinia = createPinia()
  setActivePinia(pinia)
  useChildStore().children = [makeChild()]

  const wrapper = mount(PhotoViewer, {
    props: {
      photo,
      hasPrev: false,
      hasNext: true,
      canDelete: true,
      canEdit: true,
      childBirthDate: null,
    },
    global: {
      plugins: [pinia],
      stubs: { teleport: true },
    },
  })
  return { wrapper, pinia }
}

async function mockThreeComments() {
  vi.mocked(api.get).mockResolvedValue(
    ok(
      makeListResponse([
        makePhotoComment(1, '2026-09-17T10:00:00.000Z'),
        makePhotoComment(2, '2026-09-17T11:00:00.000Z'),
        makePhotoComment(3, '2026-09-17T12:00:00.000Z'),
      ]),
    ),
  )
  vi.mocked(decryptBlob).mockResolvedValue(encoded('Nice one!'))
}

describe('PhotoViewer', () => {
  beforeEach(() => {
    vi.mocked(api.get).mockReset()
    vi.mocked(decryptBlob).mockReset()
    setActivePinia(createPinia())
  })

  it('shows "View all N comments" in the collapsed preview when more than two comments', async () => {
    await mockThreeComments()
    const { wrapper } = mountViewer(makePhoto())

    await vi.waitFor(() => {
      expect(wrapper.findAll('.viewer__preview-item')).toHaveLength(2)
    })
    expect(wrapper.text()).toContain('View all 3 comments')
    expect(wrapper.text()).toContain('Ana 3')
    wrapper.unmount()
  })

  it('condensed action row exposes a MessageSquare button with the count and a ⋮ menu', async () => {
    await mockThreeComments()
    const { wrapper } = mountViewer(makePhoto())

    await vi.waitFor(() => {
      expect(wrapper.findAll('.viewer__preview-item')).toHaveLength(2)
    })

    const commentsBtn = wrapper.find('[aria-label="Show comments"]')
    expect(commentsBtn.exists()).toBe(true)
    expect(commentsBtn.text()).toContain('3')
    expect(wrapper.find('[aria-label="More actions"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('tapping ⋮ reveals the overflow menu with Download and Edit items', async () => {
    await mockThreeComments()
    const { wrapper } = mountViewer(makePhoto())

    await vi.waitFor(() => {
      expect(wrapper.findAll('.viewer__preview-item')).toHaveLength(2)
    })

    await wrapper.find('[aria-label="More actions"]').trigger('click')
    await nextTick()

    const menu = wrapper.find('[role="menu"]')
    expect(menu.exists()).toBe(true)
    expect(menu.text()).toContain('Download')
    expect(menu.text()).toContain('Edit')
    wrapper.unmount()
  })

  it('renders cached comments instantly with no loading flash', () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    useChildStore().children = [makeChild()]

    const commentsStore = useCommentsStore()
    commentsStore.comments.set(5, [makeDecrypted(1, 'Nice one!'), makeDecrypted(2, 'So cute')])
    commentsStore.status.set(5, 'ready')

    const wrapper = mount(PhotoViewer, {
      props: {
        photo: makePhoto({ comment_count: 2 }),
        hasPrev: false,
        hasNext: true,
        canDelete: true,
        canEdit: true,
        childBirthDate: null,
      },
      global: { plugins: [pinia], stubs: { teleport: true } },
    })

    expect(wrapper.text()).not.toContain('Loading')
    expect(wrapper.findAll('.viewer__preview-item')).toHaveLength(2)
    expect(wrapper.text()).toContain('Nice one!')
    expect(wrapper.text()).not.toContain('View all')
    wrapper.unmount()
  })

  it('shows skeleton lines (never "Loading comments" text) while fetching an uncached photo', async () => {
    let resolveGet: ((value: AxiosResponse<CommentListResponse>) => void) | undefined
    vi.mocked(api.get).mockReturnValue(
      new Promise<AxiosResponse<CommentListResponse>>((resolve) => {
        resolveGet = resolve
      }),
    )

    const { wrapper } = mountViewer(makePhoto())
    await flushPromises()

    expect(wrapper.find('.viewer__skeleton').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('Loading comments')

    resolveGet!(ok(makeListResponse([makePhotoComment(1, '2026-09-17T10:00:00.000Z')])))
    vi.mocked(decryptBlob).mockResolvedValue(encoded('Late'))
    await vi.waitFor(() => {
      expect(wrapper.find('.viewer__skeleton').exists()).toBe(false)
    })
    wrapper.unmount()
  })

  it('keeps the previous thumbnail visible while the next photo loads (no black flash)', async () => {
    vi.mocked(api.get).mockResolvedValue(ok(makeListResponse([])))
    vi.mocked(fetchThumbnail).mockImplementation(async (id: number) => `blob:thumb-${id}`)

    const { wrapper } = mountViewer(makePhoto({ id: 5 }))
    await vi.waitFor(() => {
      expect(wrapper.find('.viewer__img--thumb').attributes('src')).toBe('blob:thumb-5')
    })

    await wrapper.setProps({ photo: makePhoto({ id: 6 }) })

    const heldThumb = wrapper.find('.viewer__img--thumb')
    expect(heldThumb.exists()).toBe(true)
    expect(heldThumb.attributes('src')).toBe('blob:thumb-5')
    expect(wrapper.find('.viewer__loading').exists()).toBe(true)

    await vi.waitFor(() => {
      expect(wrapper.find('.viewer__img--thumb').attributes('src')).toBe('blob:thumb-6')
    })
    expect(wrapper.find('.viewer__loading').exists()).toBe(false)

    vi.mocked(fetchThumbnail).mockReset()
    vi.mocked(fetchThumbnail).mockImplementation(async () => 'blob:thumb')
    wrapper.unmount()
  })
})
