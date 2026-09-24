import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createWebHistory } from 'vue-router'
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

vi.mock('@/lib/auth-storage', () => ({
  getSessionItem: vi.fn<(key: string) => string | null>(() => null),
  setSessionItem: vi.fn<(key: string, value: string) => void>(),
  removeSessionItem: vi.fn<(key: string) => void>(),
  clearSession: vi.fn<() => void>(),
  setPendingInviteToken: vi.fn<(token: string) => void>(),
  removePendingInviteToken: vi.fn<() => void>(),
  setStaySignedIn: vi.fn<(value: boolean) => void>(),
}))

import { api } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import InviteAcceptView from '@/views/InviteAcceptView.vue'

const TOKEN = 'a'.repeat(64)

function ok<T>(data: T): AxiosResponse<T> {
  return { data, status: 200, statusText: 'OK', headers: {}, config: {} as never }
}

async function mountInvite(path: string, signedIn: boolean) {
  setActivePinia(createPinia())
  if (signedIn) {
    useAuthStore().user = {
      id: 1,
      email: 'ana@example.com',
      display_name: 'Ana',
      master_key_salt: 'salt',
      public_key: 'pub',
    }
  }

  const router = createRouter({
    history: createWebHistory(),
    routes: [
      { path: '/invite/:token', component: InviteAcceptView },
      { path: '/login', name: 'login', component: { template: '<div>login</div>' } },
      { path: '/register', name: 'register', component: { template: '<div>register</div>' } },
      { path: '/welcome', component: { template: '<div>welcome</div>' } },
    ],
  })
  router.push(path)
  await router.isReady()

  const wrapper = mount(InviteAcceptView, { global: { plugins: [router] } })
  await flushPromises()
  return { wrapper, router }
}

describe('InviteAcceptView', () => {
  beforeEach(() => {
    vi.mocked(api.get).mockReset()
    vi.mocked(api.post).mockReset()
    vi.mocked(api.get).mockResolvedValue(
      ok({
        data: {
          token: TOKEN,
          child_name: 'Wren',
          inviter_name: 'Sam',
          role: 'member',
          relationship: 'Aunt',
          expires_at: new Date().toISOString(),
        },
      }),
    )
    vi.mocked(api.post).mockResolvedValue(ok({ data: { child_id: 1, child_slug: 'wren', child_name: 'Wren' } }))
  })

  it('sends existing users to sign in and back here ready to accept', async () => {
    const { wrapper, router } = await mountInvite(`/invite/${TOKEN}`, false)

    const signInButton = wrapper.findAll('button').find((b) => b.text() === 'I already have an account')
    await signInButton!.trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.query.redirect).toBe(`/invite/${TOKEN}?accept=1`)
  })

  it('accepts straight away after signing in', async () => {
    const { router } = await mountInvite(`/invite/${TOKEN}?accept=1`, true)

    expect(api.post).toHaveBeenCalledWith(`/invites/${TOKEN}/accept`)
    expect(router.currentRoute.value.path).toBe('/welcome')
  })

  it('waits for a tap when the user opens the link while signed in', async () => {
    await mountInvite(`/invite/${TOKEN}`, true)

    expect(api.post).not.toHaveBeenCalled()
  })

  it('is honest that the album stays empty until an admin shares the key', async () => {
    const { wrapper } = await mountInvite(`/invite/${TOKEN}`, true)

    expect(wrapper.text()).toContain('until then the album will look empty')
  })
})
