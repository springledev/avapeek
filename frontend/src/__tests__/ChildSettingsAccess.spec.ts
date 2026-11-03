import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import type { AxiosResponse } from 'axios'

vi.mock('@/services/api', () => ({
  api: {
    get: vi.fn<(url: string) => Promise<unknown>>(),
    post: vi.fn<(url: string, data?: unknown) => Promise<unknown>>(),
    delete: vi.fn<(url: string) => Promise<unknown>>(),
  },
  default: {
    setAuthToken: vi.fn<(token: string) => void>(),
    clearAuthToken: vi.fn<() => void>(),
  },
}))

vi.mock('@/services/key-sync', () => ({
  syncEncryptionKeys: vi.fn<() => Promise<void>>(async () => undefined),
}))

vi.mock('@/crypto/keychain', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/crypto/keychain')>()),
  importPublicKey: vi.fn<(jwk: string) => Promise<CryptoKey>>(
    async () => ({ type: 'public' }) as unknown as CryptoKey,
  ),
  rewrapDEKForPublicKey: vi.fn<
    (wrapped: string, kek: CryptoKey, publicKey: CryptoKey) => Promise<string>
  >(async (wrapped: string) => `rsa(${wrapped})`),
}))

vi.mock('vue-sonner', () => ({
  toast: {
    success: vi.fn<(message: string) => void>(),
    error: vi.fn<(message: string) => void>(),
  },
}))

import { api } from '@/services/api'
import { toast } from 'vue-sonner'
import { useAuthStore } from '@/stores/auth'
import { useChildStore } from '@/stores/child'
import ChildSettingsView from '@/views/ChildSettingsView.vue'
import type { ChildMember } from '@/types/child'

function ok<T>(data: T): AxiosResponse<T> {
  return { data, status: 200, statusText: 'OK', headers: {}, config: {} as never }
}

function member(id: number, name: string, awaiting: boolean): ChildMember {
  return {
    id,
    display_name: name,
    role: 'member',
    relationship: null,
    joined_at: '2026-01-01',
    has_key: !awaiting,
    awaiting_key: awaiting,
  }
}

let members: ChildMember[]
let grants: Array<{ child_id: number; user_id: number; display_name: string; public_key: string }>

async function mountView(role: string) {
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().kek = {} as CryptoKey
  useAuthStore().encryptionKeys = [
    { child_id: 7, child_name: 'Emma', encrypted_dek: 'own', wrap_type: 'kek', key_version: 1 },
  ]
  useAuthStore().user = {
    id: 1,
    email: 'me@example.com',
    display_name: 'Me',
    master_key_salt: 'salt',
    public_key: 'pub',
  }
  useChildStore().children = [
    {
      id: 7,
      slug: 'emma',
      name: 'Emma',
      birth_date: null,
      avatar_color: '#FF6B6B',
      age: null,
      role,
      relationship: null,
      storage_used: 0,
      storage_limit_bytes: null,
      storage_percentage: 0,
      created_at: '2026-01-01T00:00:00Z',
    },
  ]
  vi.mocked(api.get).mockImplementation(async (url: string) => {
    if (url === '/invites/pending') return ok({ data: [] })
    if (url === '/encryption-keys/pending-grants') return ok({ data: grants })
    return ok({ data: { members } })
  })
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/settings/children/:id', component: ChildSettingsView }],
  })
  router.push('/settings/children/7')
  await router.isReady()
  const wrapper = mount(ChildSettingsView, {
    global: { plugins: [pinia, router], stubs: { teleport: true } },
  })
  await flushPromises()
  return wrapper
}

describe('ChildSettingsView waiting for access', () => {
  beforeEach(() => {
    vi.mocked(toast.success).mockReset()
    vi.mocked(toast.error).mockReset()
    vi.mocked(api.post).mockReset()
    vi.mocked(api.delete).mockReset()
    members = [member(2, 'Ada', true), member(3, 'Max', false)]
    grants = [{ child_id: 7, user_id: 2, display_name: 'Ada', public_key: 'ada-jwk' }]
  })

  it('badges only awaiting members and offers approve and decline to admins', async () => {
    const wrapper = await mountView('admin')
    const badges = wrapper.findAll('[data-testid="awaiting-badge"]')
    expect(badges).toHaveLength(1)
    expect(badges[0]!.text()).toBe('Waiting for access')
    expect(wrapper.get('[data-testid="pending-access-item"]').text()).toContain(
      'Ada wants to see photos of Emma',
    )
  })

  it('only lists people waiting for this album', async () => {
    grants = [
      { child_id: 7, user_id: 2, display_name: 'Ada', public_key: 'ada-jwk' },
      { child_id: 8, user_id: 4, display_name: 'Other album', public_key: 'other-jwk' },
    ]
    const wrapper = await mountView('owner')
    expect(wrapper.findAll('[data-testid="pending-access-item"]')).toHaveLength(1)
    expect(wrapper.text()).not.toContain('Other album')
  })

  it('shares nothing just by opening the page', async () => {
    await mountView('admin')
    expect(api.post).not.toHaveBeenCalled()
    expect(api.delete).not.toHaveBeenCalled()
  })

  it('hides the prompt when nobody is waiting', async () => {
    members = [member(3, 'Max', false)]
    grants = []
    const wrapper = await mountView('owner')
    expect(wrapper.find('[data-testid="awaiting-badge"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="pending-access-banner"]').exists()).toBe(false)
  })

  it('hides badge and prompt for plain members', async () => {
    const wrapper = await mountView('member')
    expect(wrapper.find('[data-testid="awaiting-badge"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="pending-access-banner"]').exists()).toBe(false)
  })

  it('approve shares the key and refreshes the member list', async () => {
    vi.mocked(api.post).mockResolvedValue(ok({}))
    const wrapper = await mountView('admin')
    members = [member(2, 'Ada', false)]
    grants = []
    await wrapper.get('[data-testid="pending-access-approve"]').trigger('click')
    await flushPromises()
    expect(api.post).toHaveBeenCalledWith('/encryption-keys/wrap-for-invitee', {
      child_id: 7,
      user_id: 2,
      wrapped_dek: 'rsa(own)',
    })
    expect(toast.success).toHaveBeenCalledWith('Ada can now see photos of Emma.')
    expect(wrapper.find('[data-testid="awaiting-badge"]').exists()).toBe(false)
  })

  it('toasts an error when approving fails and keeps them waiting', async () => {
    vi.mocked(api.post).mockRejectedValue(new Error('boom'))
    const wrapper = await mountView('admin')
    await wrapper.get('[data-testid="pending-access-approve"]').trigger('click')
    await flushPromises()
    expect(toast.error).toHaveBeenCalledWith("Couldn't share access — try again")
    expect(toast.success).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="pending-access-item"]').exists()).toBe(true)
  })

  it('decline removes the membership and refreshes the member list', async () => {
    vi.stubGlobal(
      'confirm',
      vi.fn<(message: string) => boolean>(() => true),
    )
    vi.mocked(api.delete).mockResolvedValue(ok({}))
    const wrapper = await mountView('admin')
    members = [member(3, 'Max', false)]
    grants = []
    await wrapper.get('[data-testid="pending-access-decline"]').trigger('click')
    await flushPromises()
    expect(api.delete).toHaveBeenCalledWith('/children/7/members/2')
    expect(api.post).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="awaiting-badge"]').exists()).toBe(false)
    vi.unstubAllGlobals()
  })
})
