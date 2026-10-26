import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import AppShell from '@/components/AppShell.vue'
import { useAuthStore } from '@/stores/auth'
import { useChildStore } from '@/stores/child'
import { useRecentsStore } from '@/stores/recents'
import { useFeedStore } from '@/stores/feed'
import type { User } from '@/types/auth'

function makeRouter() {
  const view = { template: '<div />' }
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/login', component: view },
      { path: '/feed', component: view },
    ],
  })
}

describe('AppShell', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('loads shell data after signing in from the login page without a reload', async () => {
    const router = makeRouter()
    await router.push('/login')
    await router.isReady()

    const child = useChildStore()
    const fetchChildren = vi.spyOn(child, 'fetchChildren').mockResolvedValue()
    const unseen = vi.spyOn(useRecentsStore(), 'fetchUnseenCount').mockResolvedValue()
    const photos = vi.spyOn(useFeedStore(), 'fetchPhotos').mockResolvedValue()

    mount(AppShell, {
      global: { plugins: [router], stubs: { AppTabBar: true } },
    })
    await flushPromises()
    expect(fetchChildren).not.toHaveBeenCalled()

    useAuthStore().user = { id: 7 } as User
    await router.push('/feed')
    await flushPromises()

    expect(fetchChildren).toHaveBeenCalledTimes(1)
    expect(unseen).toHaveBeenCalledTimes(1)
    expect(photos).toHaveBeenCalledTimes(1)

    await router.push('/login')
    await router.push('/feed')
    await flushPromises()
    expect(fetchChildren).toHaveBeenCalledTimes(1)
  })

  it('loads on mount when already signed in', async () => {
    const router = makeRouter()
    await router.push('/feed')
    await router.isReady()
    useAuthStore().user = { id: 3 } as User
    const fetchChildren = vi.spyOn(useChildStore(), 'fetchChildren').mockResolvedValue()
    vi.spyOn(useRecentsStore(), 'fetchUnseenCount').mockResolvedValue()
    vi.spyOn(useFeedStore(), 'fetchPhotos').mockResolvedValue()

    mount(AppShell, { global: { plugins: [router], stubs: { AppTabBar: true } } })
    await flushPromises()
    expect(fetchChildren).toHaveBeenCalledTimes(1)
  })
})
