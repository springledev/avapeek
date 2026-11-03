import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'

vi.mock('vue-sonner', () => ({
  toast: { success: vi.fn<(m: string) => void>(), error: vi.fn<(m: string) => void>() },
}))

vi.mock('@/services/api', () => ({
  api: {
    get: vi.fn<(url: string) => Promise<unknown>>(),
    delete: vi.fn<(url: string) => Promise<unknown>>(),
  },
}))

vi.mock('@/lib/sign-out', () => ({ signOut: vi.fn<() => Promise<void>>() }))

import { api } from '@/services/api'
import SettingsView from '@/views/SettingsView.vue'

async function mountView() {
  const pinia = createPinia()
  setActivePinia(pinia)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/settings', component: SettingsView }],
  })
  await router.push('/settings')
  await router.isReady()
  const wrapper = mount(SettingsView, { global: { plugins: [pinia, router] } })
  await flushPromises()
  return wrapper
}

describe('SettingsView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(api.get).mockImplementation((url: string) =>
      url === '/quota'
        ? Promise.resolve({
            data: {
              data: { used_bytes: 1024 * 1024 * 512, limit_bytes: 1024 * 1024 * 1024, percentage: 50 },
            },
          })
        : Promise.resolve({ data: { data: [] } }),
    )
  })

  it('shows storage usage from /quota', async () => {
    const wrapper = await mountView()

    expect(api.get).toHaveBeenCalledWith('/quota')
    expect(wrapper.find('[data-testid="settings-storage"]').text()).toContain('512.0 MB / 1.0 GB')
  })
})
