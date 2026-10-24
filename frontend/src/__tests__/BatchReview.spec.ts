import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick } from 'vue'

vi.mock('@/services/api', () => ({
  api: {
    get: vi.fn<(url: string) => Promise<unknown>>(),
    post: vi.fn<(url: string, data?: unknown) => Promise<unknown>>(),
  },
  default: {
    setAuthToken: vi.fn<(token: string) => void>(),
    clearAuthToken: vi.fn<() => void>(),
  },
}))

vi.mock('@/lib/auth-storage', () => ({
  getSessionItem: vi.fn<(key: string) => string | null>(() => null),
  setSessionItem: vi.fn<(key: string, value: string) => void>(),
  removeSessionItem: vi.fn<(key: string) => void>(),
  clearSession: vi.fn<() => void>(),
  removePendingInviteToken: vi.fn<() => void>(),
  setStaySignedIn: vi.fn<(value: boolean) => void>(),
}))

import BatchReview from '@/components/upload/BatchReview.vue'
import CreateChildSheet from '@/components/CreateChildSheet.vue'
import { useChildStore } from '@/stores/child'
import type { Child } from '@/types/child'

let wrapper: VueWrapper | null = null

function makeChild(id: number, name: string): Child {
  return {
    id,
    slug: name.toLowerCase(),
    name,
    birth_date: null,
    avatar_color: '#FF6B6B',
    age: null,
    role: 'owner',
    relationship: 'parent',
    storage_used: 0,
    storage_limit_bytes: null,
    storage_percentage: 0,
    created_at: '',
  }
}

function mountReview(children: Child[]) {
  setActivePinia(createPinia())
  const store = useChildStore()
  store.children = children
  const file = new File(['x'], 'a.jpg', { type: 'image/jpeg' })
  wrapper = mount(BatchReview, { props: { open: true, files: [file] }, attachTo: document.body })
  return store
}

function uploadButton(): HTMLButtonElement {
  return Array.from(document.body.querySelectorAll('button')).find((b) =>
    b.textContent?.includes('Upload 1 photo'),
  ) as HTMLButtonElement
}

describe('BatchReview', () => {
  beforeEach(() => {
    URL.createObjectURL = vi.fn<(obj: Blob | MediaSource) => string>(() => 'blob:x')
  })

  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
  })

  it('explains the missing kid and offers to add one when there are no children', async () => {
    mountReview([])

    expect(document.body.textContent).toContain('Add a kid first')
    const addButton = Array.from(document.body.querySelectorAll('button')).find(
      (b) => b.textContent?.trim() === 'Add a kid',
    )
    expect(addButton).toBeTruthy()
    expect(uploadButton().disabled).toBe(true)

    addButton!.click()
    await nextTick()
    expect(wrapper!.findComponent(CreateChildSheet).props('open')).toBe(true)
  })

  it('preselects the new child after it is created from the CTA', async () => {
    const store = mountReview([])
    const child = makeChild(5, 'Emma')

    store.children.push(child)
    wrapper!.findComponent(CreateChildSheet).vm.$emit('created', child)
    await flushPromises()

    expect(uploadButton().disabled).toBe(false)
  })

  it('hints to choose a kid when there are several and none is selected', () => {
    mountReview([makeChild(1, 'Emma'), makeChild(2, 'Noah')])

    expect(document.body.textContent).toContain("Choose who's in these photos")
    expect(uploadButton().disabled).toBe(true)
  })

  it('preselects a single child present at mount', () => {
    mountReview([makeChild(1, 'Emma')])

    expect(document.body.textContent).not.toContain("Choose who's in these photos")
    expect(uploadButton().disabled).toBe(false)
  })

  it('preselects reactively when a single child loads after mount', async () => {
    const store = mountReview([])
    expect(uploadButton().disabled).toBe(true)

    store.children = [makeChild(1, 'Emma')]
    await flushPromises()

    expect(uploadButton().disabled).toBe(false)
  })

  it('does not preselect when a second child appears', async () => {
    const store = mountReview([makeChild(1, 'Emma')])
    store.children = [makeChild(1, 'Emma'), makeChild(2, 'Noah')]
    await flushPromises()

    // Emma stays selected; only the empty-selection case shows the hint.
    expect(uploadButton().disabled).toBe(false)
    expect(document.body.textContent).not.toContain("Choose who's in these photos")
  })
})
