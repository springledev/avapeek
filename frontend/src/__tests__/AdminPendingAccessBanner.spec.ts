import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import type { AxiosResponse } from 'axios'

vi.mock('@/services/api', () => ({
  api: { get: vi.fn<(url: string) => Promise<unknown>>() },
}))

vi.mock('@/services/key-sync', () => ({
  syncEncryptionKeys: vi.fn<() => Promise<{ granted: number; failed: number }>>(),
}))

import { api } from '@/services/api'
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
  useChildStore().children = roles.map((r, i) => child(i + 1, r))
  usePendingGrants().reset()
}

let grants: Array<{ child_id: number; user_id: number }>

describe('AdminPendingAccessBanner', () => {
  beforeEach(() => {
    vi.mocked(api.get).mockReset()
    vi.mocked(syncEncryptionKeys).mockReset()
    vi.mocked(syncEncryptionKeys).mockResolvedValue({ granted: 0, failed: 0 })
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
    grants = [{ child_id: 1, user_id: 9 }]
    const wrapper = mount(AdminPendingAccessBanner)
    await flushPromises()
    expect(wrapper.text()).toContain('1 family member waiting for access')
  })

  it('counts distinct members across albums', async () => {
    setup(['admin', 'owner'])
    grants = [
      { child_id: 1, user_id: 9 },
      { child_id: 2, user_id: 9 },
      { child_id: 2, user_id: 10 },
    ]
    const wrapper = mount(AdminPendingAccessBanner)
    await flushPromises()
    expect(wrapper.text()).toContain('2 family members waiting for access')
  })

  it('share access now re-syncs and hides once cleared', async () => {
    setup(['owner'])
    grants = [{ child_id: 1, user_id: 9 }]
    const wrapper = mount(AdminPendingAccessBanner)
    await flushPromises()
    grants = []
    await wrapper.get('[data-testid="pending-access-share"]').trigger('click')
    await flushPromises()
    expect(syncEncryptionKeys).toHaveBeenCalledTimes(2)
    expect(wrapper.find('[data-testid="pending-access-banner"]').exists()).toBe(false)
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
