import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

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

import CreateChildSheet from '@/components/CreateChildSheet.vue'
import { useAuthStore } from '@/stores/auth'
import { useChildStore } from '@/stores/child'
import type { Child } from '@/types/child'

let wrapper: VueWrapper | null = null

function mountSheet() {
  setActivePinia(createPinia())
  useAuthStore().kek = { type: 'kek' } as unknown as CryptoKey
  const store = useChildStore()
  wrapper = mount(CreateChildSheet, { props: { open: true }, attachTo: document.body })
  return store
}

function field(id: string): HTMLInputElement {
  return document.body.querySelector<HTMLInputElement>(`#${id}`)!
}

async function type(id: string, value: string) {
  const el = field(id)
  el.value = value
  el.dispatchEvent(new Event('input'))
  await flushPromises()
}

async function submit() {
  document.body.querySelector('form')!.dispatchEvent(new Event('submit'))
  await flushPromises()
}

const created: Child = {
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
  created_at: '',
}

describe('CreateChildSheet', () => {
  beforeEach(() => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
  })

  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
  })

  it('requires a name', async () => {
    const store = mountSheet()
    const spy = vi.spyOn(store, 'createChild')

    await submit()

    expect(document.body.textContent).toContain('Name is required')
    expect(spy).not.toHaveBeenCalled()
  })

  it('creates the child, then emits created and close', async () => {
    const store = mountSheet()
    const spy = vi.spyOn(store, 'createChild').mockResolvedValue(created)

    await type('add-name', '  Emma ')
    await type('add-birthdate', '2024-05-06')
    await submit()

    expect(spy).toHaveBeenCalledWith('Emma', '2024-05-06')
    expect(wrapper!.emitted('created')?.[0]).toEqual([created])
    expect(wrapper!.emitted('close')).toHaveLength(1)
  })

  it('shows the first server validation error and stays open', async () => {
    const store = mountSheet()
    vi.spyOn(store, 'createChild').mockRejectedValue({
      response: { data: { errors: { name: ['The name field format is invalid.'] } } },
    })

    await type('add-name', '<b>')
    await submit()

    expect(document.body.textContent).toContain('The name field format is invalid.')
    expect(wrapper!.emitted('created')).toBeUndefined()
    expect(wrapper!.emitted('close')).toBeUndefined()
  })

  it('falls back to the store error for non-validation failures', async () => {
    const store = mountSheet()
    vi.spyOn(store, 'createChild').mockImplementation(async () => {
      store.error = 'Server is down'
      throw new Error('x')
    })

    await type('add-name', 'Emma')
    await submit()

    expect(document.body.textContent).toContain('Server is down')
  })

  it('retries only the key step when a child is waiting for its key', async () => {
    const store = mountSheet()
    store.keyPendingChild = created
    const createSpy = vi.spyOn(store, 'createChild')
    const retrySpy = vi.spyOn(store, 'retryKeySetup').mockResolvedValue(created)
    await flushPromises()

    expect(document.body.textContent).toContain('Retry key setup')
    await submit()

    expect(retrySpy).toHaveBeenCalled()
    expect(createSpy).not.toHaveBeenCalled()
    expect(wrapper!.emitted('created')?.[0]).toEqual([created])
  })
})
