import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import { ref } from 'vue'

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

const uploadMock = vi.fn<(files: File[], childIds: number[]) => Promise<unknown>>()
vi.mock('@/composables/useUploadPhotos', () => ({
  useUploadPhotos: () => ({
    isUploading: ref(false),
    current: ref(0),
    total: ref(0),
    fileName: ref(''),
    errors: ref([]),
    upload: uploadMock,
  }),
}))

import SetupView from '@/views/SetupView.vue'
import { useAuthStore } from '@/stores/auth'
import { useChildStore } from '@/stores/child'
import { useSetupState } from '@/composables/useSetupState'
import type { Child } from '@/types/child'

let wrapper: VueWrapper | null = null

function makeChild(overrides: Partial<Child> = {}): Child {
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
    photo_count: 0,
    created_at: '',
    ...overrides,
  }
}

function keyFor(child: Child) {
  return { child_id: child.id, child_name: child.name, encrypted_dek: 'dek', key_version: 1 }
}

async function mountSetup(options: { children?: Child[]; query?: string } = {}) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const authStore = useAuthStore()
  authStore.kek = { type: 'kek' } as unknown as CryptoKey
  const childStore = useChildStore()
  childStore.children = options.children ?? []
  childStore.childrenLoaded = true
  // A child with a KEK-wrapped key is uploadable.
  authStore.encryptionKeys = childStore.children.map(keyFor)

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/setup', name: 'setup', component: SetupView },
      { path: '/feed', name: 'feed', component: { template: '<div>feed</div>' } },
      { path: '/settings/children/:id', component: { template: '<div>child</div>' } },
    ],
  })
  router.push(options.query ?? '/setup')
  await router.isReady()

  wrapper = mount(SetupView, { global: { plugins: [pinia, router] } })
  await flushPromises()
  return { router, childStore }
}

describe('SetupView', () => {
  beforeEach(() => {
    uploadMock.mockReset()
    vi.spyOn(console, 'error').mockImplementation(() => {})
  })

  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
    vi.restoreAllMocks()
  })

  it('starts on the kid step and can skip it to the done step with a note', async () => {
    await mountSetup()

    expect(wrapper!.find('[data-test="setup-step-kid"]').exists()).toBe(true)
    await wrapper!.find('[data-test="setup-skip"]').trigger('click')
    await flushPromises()

    expect(wrapper!.find('[data-test="setup-step-done"]').exists()).toBe(true)
    expect(wrapper!.find('[data-test="setup-skipped-kid-note"]').exists()).toBe(true)
    expect(wrapper!.find('[data-test="setup-later-kid"]').exists()).toBe(true)
    expect(wrapper!.find('[data-test="setup-invite"]').exists()).toBe(false)
  })

  it('creating a kid moves to the photo step targeting that kid', async () => {
    const { childStore } = await mountSetup()
    const created = makeChild({ id: 9, name: 'Wren' })
    vi.spyOn(childStore, 'createChild').mockImplementation(async () => {
      childStore.children.push(created)
      useAuthStore().encryptionKeys.push(keyFor(created))
      return created
    })

    await wrapper!.find('#add-name').setValue('Wren')
    await wrapper!.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper!.find('[data-test="setup-step-photo"]').exists()).toBe(true)
    expect(wrapper!.text()).toContain('For Wren')

    uploadMock.mockResolvedValue({ succeeded: 1, failed: 0 })
    const file = new File(['x'], 'a.jpg', { type: 'image/jpeg' })
    const input = wrapper!.find('input[type="file"]')
    Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
    await input.trigger('change')
    await flushPromises()

    expect(uploadMock).toHaveBeenCalledWith([file], [9])
    expect(wrapper!.find('[data-test="setup-step-done"]').exists()).toBe(true)
    expect(wrapper!.text()).toContain('Added Wren')
    expect(wrapper!.find('[data-test="setup-invite"]').attributes('href')).toBe(
      '/settings/children/9',
    )
  })

  it('disables the photo step without a kid and offers to go back to step 1', async () => {
    await mountSetup({ query: '/setup?step=2' })

    expect(wrapper!.find('[data-test="setup-step-photo"]').exists()).toBe(true)
    expect(wrapper!.find('[data-test="setup-needs-kid"]').exists()).toBe(true)
    expect(wrapper!.find('.setup__picker-disabled').attributes('inert')).toBeDefined()

    await wrapper!.find('[data-test="setup-back-to-kid"]').trigger('click')
    await flushPromises()
    expect(wrapper!.find('[data-test="setup-step-kid"]').exists()).toBe(true)
  })

  it('skips the photo step to the done step with a do-later link', async () => {
    await mountSetup({ children: [makeChild()] })

    expect(wrapper!.find('[data-test="setup-step-photo"]').exists()).toBe(true)
    await wrapper!.find('[data-test="setup-skip"]').trigger('click')
    await flushPromises()

    expect(wrapper!.find('[data-test="setup-step-done"]').exists()).toBe(true)
    expect(wrapper!.find('[data-test="setup-later-photo"]').exists()).toBe(true)
    expect(wrapper!.find('[data-test="setup-invite"]').exists()).toBe(true)
  })

  it('opens the step named in the ?step= query', async () => {
    await mountSetup({ children: [makeChild()], query: '/setup?step=3' })
    expect(wrapper!.find('[data-test="setup-step-done"]').exists()).toBe(true)
    wrapper!.unmount()

    await mountSetup({ children: [makeChild()], query: '/setup?step=1' })
    expect(wrapper!.find('[data-test="setup-step-kid"]').exists()).toBe(true)
    wrapper!.unmount()

    await mountSetup({ children: [makeChild()], query: '/setup?step=bogus' })
    expect(wrapper!.find('[data-test="setup-step-photo"]').exists()).toBe(true)
  })

  it('redirects to /feed when the user already has a kid and photos', async () => {
    const { router } = await mountSetup({ children: [makeChild({ photo_count: 4 })] })

    expect(router.currentRoute.value.path).toBe('/feed')
  })
})

describe('useSetupState dismissal', () => {
  afterEach(() => {
    vi.restoreAllMocks()
    localStorage.clear()
  })

  it('stores the flag per user', () => {
    setActivePinia(createPinia())
    useAuthStore().user = { id: 7 } as never
    const state = useSetupState()

    expect(state.isDismissed.value).toBe(false)
    state.dismiss()

    expect(localStorage.getItem('avapeek.setup.dismissed.7')).toBe('1')
  })

  it('still works when localStorage throws', () => {
    setActivePinia(createPinia())
    useAuthStore().user = { id: 7 } as never
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('denied')
    })
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('denied')
    })
    const state = useSetupState()

    expect(state.isDismissed.value).toBe(false)
    expect(() => state.dismiss()).not.toThrow()
    expect(state.isDismissed.value).toBe(true)
  })

  it('derives completion and the first incomplete step from data', () => {
    setActivePinia(createPinia())
    const childStore = useChildStore()
    const state = useSetupState()

    expect(state.firstIncompleteStep.value).toBe(1)
    childStore.children = [makeChild()]
    expect(state.firstIncompleteStep.value).toBe(2)
    childStore.children = [makeChild({ photo_count: 2 })]
    expect(state.isComplete.value).toBe(true)
  })
})
