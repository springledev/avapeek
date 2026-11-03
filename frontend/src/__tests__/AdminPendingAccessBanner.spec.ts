import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import type { AxiosResponse } from 'axios'

vi.mock('@/services/api', () => ({
  api: {
    get: vi.fn<(url: string) => Promise<unknown>>(),
    post: vi.fn<(url: string, data?: unknown) => Promise<unknown>>(),
    delete: vi.fn<(url: string) => Promise<unknown>>(),
  },
}))

vi.mock('vue-sonner', () => ({
  toast: {
    success: vi.fn<(message: string) => void>(),
    error: vi.fn<(message: string) => void>(),
  },
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

vi.mock('@/services/key-sync', () => ({
  syncEncryptionKeys: vi.fn<() => Promise<void>>(),
}))

import { api } from '@/services/api'
import { toast } from 'vue-sonner'
import { syncEncryptionKeys } from '@/services/key-sync'
import { useAuthStore } from '@/stores/auth'
import { useChildStore } from '@/stores/child'
import { usePendingGrants } from '@/composables/usePendingGrants'
import AdminPendingAccessBanner from '@/components/AdminPendingAccessBanner.vue'
import type { Child } from '@/types/child'

function ok<T>(data: T): AxiosResponse<T> {
  return { data, status: 200, statusText: 'OK', headers: {}, config: {} as never }
}

function child(id: number, role: string): Child {
  return {
    id,
    slug: `c${id}`,
    name: `Kid ${id}`,
    birth_date: null,
    avatar_color: null,
    age: null,
    role,
    relationship: null,
    storage_used: 0,
    storage_limit_bytes: null,
    storage_percentage: 0,
    created_at: '2026-01-01T00:00:00Z',
  }
}

function setup(roles: string[]) {
  setActivePinia(createPinia())
  useAuthStore().kek = {} as CryptoKey
  useAuthStore().encryptionKeys = roles.map((_, i) => ({
    child_id: i + 1,
    child_name: `Kid ${i + 1}`,
    encrypted_dek: `own-${i + 1}`,
    wrap_type: 'kek',
    key_version: 1,
  }))
  useChildStore().children = roles.map((r, i) => child(i + 1, r))
  usePendingGrants().reset()
}

interface Grant {
  child_id: number
  user_id: number
  display_name: string
  public_key: string
}

function grant(childId: number, userId: number, name = `Person ${userId}`): Grant {
  return { child_id: childId, user_id: userId, display_name: name, public_key: `jwk-${userId}` }
}

let grants: Grant[]

describe('AdminPendingAccessBanner', () => {
  beforeEach(() => {
    vi.mocked(api.get).mockReset()
    vi.mocked(syncEncryptionKeys).mockReset()
    vi.mocked(syncEncryptionKeys).mockResolvedValue(undefined)
    vi.mocked(api.post).mockReset()
    vi.mocked(api.post).mockResolvedValue(ok({}))
    vi.mocked(api.delete).mockReset()
    vi.mocked(api.delete).mockResolvedValue(ok({}))
    vi.mocked(toast.success).mockReset()
    vi.mocked(toast.error).mockReset()
    vi.stubGlobal(
      'confirm',
      vi.fn<(message: string) => boolean>(() => true),
    )
    grants = []
    vi.mocked(api.get).mockImplementation(async () => ok({ data: grants }))
  })

  it('does not fetch for plain members', async () => {
    setup(['member'])
    const wrapper = mount(AdminPendingAccessBanner)
    await flushPromises()
    expect(api.get).not.toHaveBeenCalled()
    expect(syncEncryptionKeys).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="pending-access-banner"]').exists()).toBe(false)
  })

  it('syncs first and stays hidden when nothing remains', async () => {
    setup(['owner'])
    const wrapper = mount(AdminPendingAccessBanner)
    await flushPromises()
    expect(syncEncryptionKeys).toHaveBeenCalledTimes(1)
    expect(api.get).toHaveBeenCalledWith('/encryption-keys/pending-grants')
    expect(wrapper.find('[data-testid="pending-access-banner"]').exists()).toBe(false)
  })

  it('shows the singular message for one waiting member', async () => {
    setup(['admin'])
    grants = [grant(1, 9)]
    const wrapper = mount(AdminPendingAccessBanner)
    await flushPromises()
    expect(wrapper.text()).toContain('1 family member waiting for access')
  })

  it('counts distinct members across albums', async () => {
    setup(['admin', 'owner'])
    grants = [grant(1, 9), grant(2, 9), grant(2, 10)]
    const wrapper = mount(AdminPendingAccessBanner)
    await flushPromises()
    expect(wrapper.text()).toContain('2 family members waiting for access')
  })

  it('shows who is waiting and for which album, and shares nothing until a click', async () => {
    setup(['admin', 'owner'])
    grants = [grant(1, 9, 'Ben'), grant(2, 10, 'Cleo')]
    const wrapper = mount(AdminPendingAccessBanner)
    await flushPromises()
    const items = wrapper.findAll('[data-testid="pending-access-item"]')
    expect(items).toHaveLength(2)
    expect(items[0]!.text()).toContain('Ben wants to see photos of Kid 1')
    expect(items[1]!.text()).toContain('Cleo wants to see photos of Kid 2')
    expect(api.post).not.toHaveBeenCalled()
    expect(api.delete).not.toHaveBeenCalled()
  })

  it('approve shares the key with only that person and hides once cleared', async () => {
    setup(['owner'])
    grants = [grant(1, 9, 'Ben'), grant(1, 10, 'Cleo')]
    const wrapper = mount(AdminPendingAccessBanner)
    await flushPromises()
    grants = [grant(1, 10, 'Cleo')]
    await wrapper.findAll('[data-testid="pending-access-approve"]')[0]!.trigger('click')
    await flushPromises()
    expect(api.post).toHaveBeenCalledTimes(1)
    expect(api.post).toHaveBeenCalledWith('/encryption-keys/wrap-for-invitee', {
      child_id: 1,
      user_id: 9,
      wrapped_dek: 'rsa(own-1)',
    })
    expect(wrapper.findAll('[data-testid="pending-access-item"]')).toHaveLength(1)
    expect(wrapper.text()).toContain('Cleo')
    expect(wrapper.text()).not.toContain('Ben')
  })

  it('decline removes the membership and shares nothing', async () => {
    setup(['owner'])
    grants = [grant(1, 9, 'Ben')]
    const wrapper = mount(AdminPendingAccessBanner)
    await flushPromises()
    grants = []
    await wrapper.get('[data-testid="pending-access-decline"]').trigger('click')
    await flushPromises()
    expect(api.delete).toHaveBeenCalledWith('/children/1/members/9')
    expect(api.post).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="pending-access-banner"]').exists()).toBe(false)
  })

  it('does nothing when the decline is not confirmed', async () => {
    vi.mocked(confirm).mockReturnValue(false)
    setup(['owner'])
    grants = [grant(1, 9, 'Ben')]
    const wrapper = mount(AdminPendingAccessBanner)
    await flushPromises()
    await wrapper.get('[data-testid="pending-access-decline"]').trigger('click')
    await flushPromises()
    expect(api.delete).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="pending-access-item"]').exists()).toBe(true)
  })

  it('shows the server reason when a decline is refused and keeps them listed', async () => {
    setup(['admin'])
    grants = [grant(1, 9, 'Ben')]
    vi.mocked(api.delete).mockRejectedValue({
      response: { data: { message: 'Only the owner can remove an admin.' } },
    })
    const wrapper = mount(AdminPendingAccessBanner)
    await flushPromises()
    await wrapper.get('[data-testid="pending-access-decline"]').trigger('click')
    await flushPromises()
    expect(toast.error).toHaveBeenCalledWith('Only the owner can remove an admin.')
    expect(wrapper.find('[data-testid="pending-access-item"]').exists()).toBe(true)
  })

  it('keeps the last state when the request fails', async () => {
    setup(['owner'])
    vi.spyOn(console, 'error').mockImplementation(() => undefined)
    vi.mocked(api.get).mockRejectedValue(new Error('offline'))
    const wrapper = mount(AdminPendingAccessBanner)
    await flushPromises()
    expect(wrapper.find('[data-testid="pending-access-banner"]').exists()).toBe(false)
  })
})
