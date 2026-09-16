import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createWebHistory } from 'vue-router'
import App from '../App.vue'

vi.mock('@/composables/useDarkMode', () => ({
  useDarkMode: () => ({
    initDarkMode: vi.fn(),
  }),
}))

vi.mock('@/composables/useNetwork', () => ({
  useNetwork: () => ({
    initNetwork: vi.fn(),
    cleanup: vi.fn(),
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
})
