import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createWebHistory } from 'vue-router'
import App from '../App.vue'
import { useAuthStore } from '@/stores/auth'
import { resyncPushSubscription } from '@/services/push'
import type { User } from '@/types/auth'

vi.mock('@/services/push', () => ({
  resyncPushSubscription: vi.fn<() => Promise<void>>(() => Promise.resolve()),
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
})
