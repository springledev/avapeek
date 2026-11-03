import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import type { AxiosResponse } from 'axios'

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

vi.mock('@/services/key-sync', () => ({
  syncEncryptionKeys: vi.fn<() => Promise<{ granted: number; failed: number }>>(),
}))

vi.mock('vue-sonner', () => ({
  toast: {
    success: vi.fn<(message: string) => void>(),
    error: vi.fn<(message: string) => void>(),
  },
}))

import { api } from '@/services/api'
import { toast } from 'vue-sonner'
import { syncEncryptionKeys } from '@/services/key-sync'
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
let pendingInvites: unknown[] = []

async function mountView(role: string) {
  const pinia = createPinia()
  setActivePinia(pinia)
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
    if (url === '/invites/pending') return ok({ data: pendingInvites })
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
    vi.mocked(syncEncryptionKeys).mockReset()
    members = [member(2, 'Ada', true), member(3, 'Max', false)]
  })

  it('badges only awaiting members for admins', async () => {
    const wrapper = await mountView('admin')
    const badges = wrapper.findAll('[data-testid="awaiting-badge"]')
    expect(badges).toHaveLength(1)
    expect(badges[0]!.text()).toBe('Waiting for access')
    expect(wrapper.find('[data-testid="share-access-now"]').exists()).toBe(true)
  })

  it('hides badge and button when nobody is waiting', async () => {
    members = [member(3, 'Max', false)]
    const wrapper = await mountView('owner')
    expect(wrapper.find('[data-testid="awaiting-badge"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="share-access-now"]').exists()).toBe(false)
  })

  it('hides both for plain members', async () => {
    const wrapper = await mountView('member')
    expect(wrapper.find('[data-testid="awaiting-badge"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="share-access-now"]').exists()).toBe(false)
  })

  it('syncs, refreshes the child and toasts success', async () => {
    vi.mocked(syncEncryptionKeys).mockResolvedValue({ granted: 1, failed: 0 })
    const wrapper = await mountView('admin')
    const before = vi.mocked(api.get).mock.calls.length
    members = [member(2, 'Ada', false)]
    await wrapper.get('[data-testid="share-access-now"]').trigger('click')
    await flushPromises()
    expect(syncEncryptionKeys).toHaveBeenCalledTimes(1)
    expect(vi.mocked(api.get).mock.calls.length).toBeGreaterThan(before)
    expect(toast.success).toHaveBeenCalledWith('Access shared with your family.')
    expect(wrapper.find('[data-testid="awaiting-badge"]').exists()).toBe(false)
  })

  it('toasts an error when a grant fails', async () => {
    vi.mocked(syncEncryptionKeys).mockResolvedValue({ granted: 0, failed: 1 })
    const wrapper = await mountView('admin')
    await wrapper.get('[data-testid="share-access-now"]').trigger('click')
    await flushPromises()
    expect(toast.error).toHaveBeenCalledWith("Couldn't share access — try again")
    expect(toast.success).not.toHaveBeenCalled()
  })
})

describe('ChildSettingsView invite control', () => {
  beforeEach(() => {
    members = [member(3, 'Max', false)]
  })

  it.each(['owner', 'admin'])('shows Generate invite link for %s', async (role) => {
    const wrapper = await mountView(role)
    expect(wrapper.text()).toContain('Generate invite link')
  })

  it('hides Generate invite link for plain members', async () => {
    const wrapper = await mountView('member')
    expect(wrapper.text()).not.toContain('Generate invite link')
  })
})

describe('ChildSettingsView member order', () => {
  it('lists owner first, then admins, then members', async () => {
    members = [
      { ...member(2, 'Mia', false), role: 'member' },
      { ...member(3, 'Ben', false), role: 'admin' },
      { ...member(4, 'Zed', false), role: 'owner' },
      { ...member(5, 'Amy', false), role: 'member' },
    ]
    const wrapper = await mountView('owner')
    const names = wrapper.findAll('.child-settings__member-name').map((n) => n.text())
    expect(names).toEqual(['Zed', 'Ben', 'Mia', 'Amy'])
  })
})

describe('ChildSettingsView pending invite link', () => {
  beforeEach(() => {
    vi.mocked(toast.success).mockReset()
    vi.mocked(toast.error).mockReset()
    members = []
    pendingInvites = [
      {
        id: 5,
        child_name: 'Emma',
        role: 'member',
        relationship: null,
        token: 'tok123',
        expires_at: '2026-12-01T00:00:00Z',
      },
    ]
  })

  it('copies the invite link again', async () => {
    const writeText = vi.fn<(text: string) => Promise<void>>().mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
    const wrapper = await mountView('admin')
    await wrapper.get('[data-testid="copy-invite-link"]').trigger('click')
    await flushPromises()
    expect(writeText).toHaveBeenCalledWith(`${window.location.origin}/invite/tok123`)
    expect(toast.success).toHaveBeenCalledWith('Link copied to clipboard.')
  })

  it('toasts an error when the clipboard is unavailable', async () => {
    const writeText = vi.fn<(text: string) => Promise<void>>().mockRejectedValue(new Error('no'))
    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
    const wrapper = await mountView('owner')
    await wrapper.get('[data-testid="copy-invite-link"]').trigger('click')
    await flushPromises()
    expect(toast.error).toHaveBeenCalledWith("Couldn't copy the link — try again")
  })
})
