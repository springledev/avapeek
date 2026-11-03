import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import type { AxiosResponse } from 'axios'

vi.mock('@/services/api', () => ({
  api: { get: vi.fn<(url: string) => Promise<unknown>>() },
  default: {},
}))

vi.mock('vue-sonner', () => ({
  toast: { success: vi.fn<(m: string) => void>(), error: vi.fn<(m: string) => void>() },
}))

vi.mock('@/composables/usePushNotifications', () => ({
  usePushNotifications: () => ({ available: { value: false }, init: vi.fn<() => void>() }),
}))

import { api } from '@/services/api'
import { toast } from 'vue-sonner'
import { useChildStore } from '@/stores/child'
import SettingsView from '@/views/SettingsView.vue'
import type { Child } from '@/types/child'

function ok<T>(data: T): AxiosResponse<T> {
  return { data, status: 200, statusText: 'OK', headers: {}, config: {} as never }
}

async function mountView(role: string) {
  const pinia = createPinia()
  setActivePinia(pinia)
  useChildStore().children = [{ id: 1, name: 'Emma', role } as Child]
  vi.mocked(api.get).mockResolvedValue(
    ok({
      data: [
        {
          id: 9,
          child_name: 'Emma',
          role: 'member',
          relationship: null,
          token: 'abc',
          expires_at: '2026-12-01T00:00:00Z',
          created_at: '2026-11-01T00:00:00Z',
        },
      ],
    }),
  )
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/settings', component: SettingsView }],
  })
  router.push('/settings')
  await router.isReady()
  const wrapper = mount(SettingsView, { global: { plugins: [pinia, router] } })
  await flushPromises()
  return wrapper
}

describe('SettingsView pending invites', () => {
  beforeEach(() => {
    vi.mocked(toast.success).mockReset()
    vi.mocked(toast.error).mockReset()
  })

  it('copies the invite link again for admins', async () => {
    const writeText = vi.fn<(text: string) => Promise<void>>().mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
    const wrapper = await mountView('admin')
    await wrapper.get('[data-testid="copy-invite-link"]').trigger('click')
    await flushPromises()
    expect(writeText).toHaveBeenCalledWith(`${window.location.origin}/invite/abc`)
    expect(toast.success).toHaveBeenCalledWith('Link copied to clipboard.')
  })

  it('shows no pending invites to plain members', async () => {
    const wrapper = await mountView('member')
    expect(wrapper.find('[data-testid="copy-invite-link"]').exists()).toBe(false)
  })
})
