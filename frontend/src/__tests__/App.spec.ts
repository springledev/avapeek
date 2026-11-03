import { describe, it, expect, vi, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createWebHistory } from 'vue-router'
import App from '../App.vue'
import { useAuthStore } from '@/stores/auth'
import { resyncPushSubscription } from '@/services/push'
import { useChildStore } from '@/stores/child'
import { usePendingGrants } from '@/composables/usePendingGrants'
import { syncEncryptionKeys } from '@/services/key-sync'
import { api } from '@/services/api'
import type { User } from '@/types/auth'

vi.mock('@/services/push', () => ({
  resyncPushSubscription: vi.fn<() => Promise<void>>(() => Promise.resolve()),
}))

vi.mock('@/services/key-sync', () => ({
  syncEncryptionKeys: vi.fn<() => Promise<void>>(async () => undefined),
}))

vi.mock('@/services/api', () => ({
  api: {
    get: vi.fn<(url: string) => Promise<unknown>>(async () => ({ data: { data: [] } })),
  },
}))

vi.mock('@/composables/useDarkMode', () => ({
  useDarkMode: () => ({
    initDarkMode: vi.fn<() => void>(),
  }),
}))

vi.mock('@/composables/useNetwork', () => ({
  useNetwork: () => ({
    initNetwork: vi.fn<() => void>(),
    cleanup: vi.fn<() => void>(),
  }),
}))

describe('App', () => {
  it('mounts renders properly', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)

    const router = createRouter({
      history: createWebHistory(),
      routes: [
        { path: '/', component: { template: '<div>Home</div>' } },
      ],
    })

    const wrapper = mount(App, {
      global: {
        plugins: [pinia, router],
        stubs: {
          AppShell: true,
          Toaster: true,
        },
      },
    })

    await router.isReady()

    expect(wrapper.exists()).toBe(true)
  })

  it('applies the stored feed density on startup', () => {
    localStorage.setItem('avapeek-feed-density', '3')
    document.documentElement.dataset.feedDensity = ''
    mount(App, {
      global: {
        plugins: [createPinia(), createRouter({ history: createWebHistory(), routes: [] })],
        stubs: { AppShell: true, Toaster: true },
      },
    })
    expect(document.documentElement.dataset.feedDensity).toBe('3')
    localStorage.removeItem('avapeek-feed-density')
    document.documentElement.dataset.feedDensity = ''
  })

  it('configures the Toaster with design-token offsets and no richColors', () => {
    const wrapper = mount(App, {
      global: {
        plugins: [createPinia(), createRouter({ history: createWebHistory(), routes: [] })],
        stubs: { AppShell: true, Toaster: true },
      },
    })
    const toaster = wrapper.findComponent({ name: 'Toaster' })
    expect(toaster.attributes('position')).toBe('top-right')
    expect(toaster.attributes('richcolors')).not.toBe('true')
    expect(toaster.attributes('offset')).toBeDefined()
  })

  it('resyncs push after an in-app sign-in', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    mount(App, {
      global: {
        plugins: [pinia, createRouter({ history: createWebHistory(), routes: [] })],
        stubs: { AppShell: true, Toaster: true },
      },
    })
    vi.mocked(resyncPushSubscription).mockClear()

    useAuthStore().user = { id: 1 } as User
    await Promise.resolve()

    expect(resyncPushSubscription).toHaveBeenCalledOnce()
  })

  describe('pending grants poll', () => {
    afterEach(() => {
      vi.useRealTimers()
    })

    async function mountAs(role: string, pending: number) {
      const pinia = createPinia()
      setActivePinia(pinia)
      useAuthStore().kek = {} as CryptoKey
      useChildStore().children = [{ id: 1, role } as never]
      vi.mocked(api.get).mockResolvedValue({
        data: { data: Array.from({ length: pending }, (_, i) => ({ child_id: 1, user_id: i + 1 })) },
      })
      await usePendingGrants().refresh()
      vi.useFakeTimers()
      const wrapper = mount(App, {
        global: {
          plugins: [pinia, createRouter({ history: createWebHistory(), routes: [] })],
          stubs: { AppShell: true, Toaster: true },
        },
      })
      await vi.advanceTimersByTimeAsync(0)
      vi.mocked(syncEncryptionKeys).mockClear()
      vi.mocked(api.get).mockClear()
      return wrapper
    }

    it('re-syncs every 60s for admins with members waiting', async () => {
      const wrapper = await mountAs('admin', 1)
      await vi.advanceTimersByTimeAsync(60_000)
      expect(syncEncryptionKeys).toHaveBeenCalled()
      expect(api.get).toHaveBeenCalledWith('/encryption-keys/pending-grants')
      wrapper.unmount()
    })

    it('updates who is waiting for admins when the app comes back into view', async () => {
      const wrapper = await mountAs('owner', 0)
      await vi.advanceTimersByTimeAsync(60_001)
      document.dispatchEvent(new Event('visibilitychange'))
      await vi.advanceTimersByTimeAsync(0)
      expect(api.get).toHaveBeenCalledWith('/encryption-keys/pending-grants')
      wrapper.unmount()
    })

    it('does not ask plain members who is waiting when the app comes back into view', async () => {
      const wrapper = await mountAs('member', 0)
      await vi.advanceTimersByTimeAsync(60_001)
      document.dispatchEvent(new Event('visibilitychange'))
      await vi.advanceTimersByTimeAsync(0)
      expect(syncEncryptionKeys).toHaveBeenCalled()
      expect(api.get).not.toHaveBeenCalled()
      wrapper.unmount()
    })

    it('forgets who is waiting on sign-out', async () => {
      const wrapper = await mountAs('owner', 1)
      useAuthStore().user = { id: 1 } as User
      await vi.advanceTimersByTimeAsync(0)
      expect(usePendingGrants().pendingCount.value).toBe(1)
      useAuthStore().user = null
      await vi.advanceTimersByTimeAsync(0)
      expect(usePendingGrants().pendingCount.value).toBe(0)
      wrapper.unmount()
    })

    it('does not poll when nobody is waiting', async () => {
      const wrapper = await mountAs('owner', 0)
      await vi.advanceTimersByTimeAsync(60_000)
      expect(api.get).not.toHaveBeenCalled()
      wrapper.unmount()
    })

    it('does not poll for plain members', async () => {
      const wrapper = await mountAs('member', 2)
      await vi.advanceTimersByTimeAsync(60_000)
      expect(api.get).not.toHaveBeenCalled()
      wrapper.unmount()
    })
  })
})
