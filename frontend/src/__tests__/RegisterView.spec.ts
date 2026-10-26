import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createWebHistory } from 'vue-router'
import type { AxiosResponse } from 'axios'
import RegisterView from '@/views/RegisterView.vue'

vi.mock('@/services/api', () => ({
  api: {
    get: vi.fn<(url: string, config?: unknown) => Promise<unknown>>(),
    post: vi.fn<(url: string, data?: unknown) => Promise<unknown>>(),
    put: vi.fn<(url: string, data?: unknown) => Promise<unknown>>(),
    delete: vi.fn<(url: string) => Promise<unknown>>(),
  },
  default: {
    setAuthToken: vi.fn<(token: string) => void>(),
    clearAuthToken: vi.fn<() => void>(),
  },
}))

vi.mock('@/lib/auth-storage', () => ({
  getSessionItem: vi.fn<(key: string) => string | null>(),
  setSessionItem: vi.fn<(key: string, value: string) => void>(),
  removeSessionItem: vi.fn<(key: string) => void>(),
  clearSession: vi.fn<() => void>(),
  getPendingInviteToken: vi.fn<() => string | null>(() => null),
  setPendingInviteToken: vi.fn<(token: string) => void>(),
  removePendingInviteToken: vi.fn<() => void>(),
  setStaySignedIn: vi.fn<(value: boolean) => void>(),
  getStaySignedIn: vi.fn<() => boolean>(() => true),
}))

vi.mock('@/crypto/keychain', () => ({
  generateSalt: vi.fn<() => string>(),
  generateKeyPair: vi.fn<() => Promise<{ publicKey: CryptoKey; privateKey: CryptoKey }>>(),
  exportPublicKey: vi.fn<(key: CryptoKey) => Promise<string>>(),
  exportKEK: vi.fn<(kek: CryptoKey) => Promise<string>>(),
  importKEK: vi.fn<(jwk: string) => Promise<CryptoKey>>(),
  wrapPrivateKey: vi.fn<(privateKey: CryptoKey, kek: CryptoKey) => Promise<string>>(
    async () => 'wrapped-private-key',
  ),
  rewrapDEK: vi.fn<(wrapped: string, oldKek: CryptoKey, newKek: CryptoKey) => Promise<string>>(),
  rewrapPrivateKey: vi.fn<(wrapped: string, oldKek: CryptoKey, newKek: CryptoKey) => Promise<string>>(),
}))

vi.mock('@/crypto/auth-hash', () => ({
  deriveKekAndAuthHash: vi.fn<(password: string, salt: string) => Promise<{ kek: CryptoKey; authHash: string }>>(
    async (password: string) => ({ kek: { type: 'secret' } as CryptoKey, authHash: `auth-hash-of-${password}` }),
  ),
}))

vi.mock('@/lib/cache/decrypted-cache', () => ({
  clearDecryptedCache: vi.fn<() => void>(),
  setDecryptedCacheUser: vi.fn<(userId: number | null) => void>(),
}))

import { api } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import {
  exportKEK,
  generateSalt,
  generateKeyPair,
  exportPublicKey,
} from '@/crypto/keychain'

function ok<T>(data: T): AxiosResponse<T> {
  return { data, status: 200, statusText: 'OK', headers: {}, config: {} as never }
}

async function mountRegisterView(initialQuery: string) {
  const pinia = createPinia()
  setActivePinia(pinia)

  const router = createRouter({
    history: createWebHistory(),
    routes: [
      { path: '/register', name: 'register', component: RegisterView },
      { path: '/login', name: 'login', component: { template: '<div>login</div>' } },
      { path: '/welcome', name: 'welcome', component: { template: '<div>welcome</div>' } },
      { path: '/invite/:token', name: 'invite-accept', component: { template: '<div>invite</div>' } },
    ],
  })

  router.push(initialQuery)
  await router.isReady()

  const wrapper = mount(RegisterView, {
    global: { plugins: [pinia, router] },
  })

  await flushPromises()

  return { wrapper, router }
}

describe('RegisterView', () => {
  beforeEach(() => {
    vi.mocked(api.get).mockReset()
    vi.mocked(api.post).mockReset()

    vi.mocked(exportKEK).mockResolvedValue('kek-jwk')
    vi.mocked(generateSalt).mockReturnValue('saltsalt')
    vi.mocked(generateKeyPair).mockResolvedValue({
      publicKey: { type: 'public' } as unknown as CryptoKey,
      privateKey: { type: 'private' } as unknown as CryptoKey,
    })
    vi.mocked(exportPublicKey).mockResolvedValue('public-key-jwk')
  })

  it('renders the invite preview with the child, role, and inviter name', async () => {
    vi.mocked(api.get).mockImplementation((url: string) => {
      if (url === '/auth/registration-mode') {
        return Promise.resolve(ok({ data: { mode: 'invite_only' } }))
      }
      if (url === '/invites/invite-123') {
        return Promise.resolve(
          ok({
            data: {
              token: 'invite-123',
              child_name: 'Wren',
              inviter_name: 'Sam',
              role: 'member',
              relationship: null,
              expires_at: new Date().toISOString(),
            },
          }),
        )
      }
      throw new Error(`unexpected GET ${url}`)
    })

    const { wrapper } = await mountRegisterView('/register?invite_token=invite-123')

    const invite = wrapper.find('.register__invite')
    expect(invite.exists()).toBe(true)
    expect(invite.text()).toContain('Wren')
    expect(invite.text()).toContain('member')
    expect(invite.text()).toContain('Sam')
  })

  it('redirects to /welcome when registration auto-accepts an invite', async () => {
    vi.mocked(api.get).mockImplementation((url: string) => {
      if (url === '/auth/registration-mode') {
        return Promise.resolve(ok({ data: { mode: 'invite_only' } }))
      }
      if (url === '/invites/invite-123') {
        return Promise.resolve(
          ok({
            data: {
              token: 'invite-123',
              child_name: 'Wren',
              inviter_name: 'Sam',
              role: 'member',
              relationship: null,
              expires_at: new Date().toISOString(),
            },
          }),
        )
      }
      throw new Error(`unexpected GET ${url}`)
    })
    vi.mocked(api.post).mockResolvedValue(
      ok({
        user: {
          id: 1,
          email: 'ana@example.com',
          display_name: 'Ana',
          master_key_salt: 'salt',
          public_key: 'pub',
        },
        token: 'token-123',
        accepted_child: { child_id: 7, child_name: 'Wren', child_slug: 'wren' },
      }),
    )

    const { wrapper, router } = await mountRegisterView(
      '/register?invite_token=invite-123&redirect=/invite/invite-123',
    )
    const authStore = useAuthStore()

    await wrapper.find('#register-name').setValue('Ana')
    await wrapper.find('#register-email').setValue('ana@example.com')
    await wrapper.find('#register-password').setValue('supersecret')
    await wrapper.find('#register-password-confirmation').setValue('supersecret')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(authStore.error).toBeNull()
    expect(api.post).toHaveBeenCalledWith(
      '/auth/register',
      expect.objectContaining({ email: 'ana@example.com', auth_hash: 'auth-hash-of-supersecret' }),
    )
    expect(JSON.stringify(vi.mocked(api.post).mock.calls)).not.toContain('"supersecret"')
    expect(router.currentRoute.value.path).toBe('/welcome')
  })

  it('routes to /setup when registering without an invite', async () => {
    vi.mocked(api.get).mockResolvedValue(ok({ data: { mode: 'open' } }))
    vi.mocked(api.post).mockResolvedValue(
      ok({
        user: {
          id: 2,
          email: 'ana@example.com',
          display_name: 'Ana',
          master_key_salt: 'salt',
          public_key: 'pub',
        },
        token: 'token-456',
        accepted_child: null,
      }),
    )

    const { wrapper, router } = await mountRegisterView('/register')
    await router.isReady()
    router.addRoute({ path: '/setup', name: 'setup', component: { template: '<div>setup</div>' } })

    await wrapper.find('#register-name').setValue('Ana')
    await wrapper.find('#register-email').setValue('ana@example.com')
    await wrapper.find('#register-password').setValue('supersecret')
    await wrapper.find('#register-password-confirmation').setValue('supersecret')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/setup')
  })

  it('shows the invite gate instead of the form when the invite has expired', async () => {
    vi.mocked(api.get).mockImplementation((url: string) => {
      if (url === '/auth/registration-mode') {
        return Promise.resolve(ok({ data: { mode: 'invite_only' } }))
      }
      return Promise.reject({
        response: { status: 410, data: { message: 'This invite has expired.' } },
      })
    })

    const { wrapper } = await mountRegisterView('/register?invite_token=old-invite')

    expect(wrapper.find('form').exists()).toBe(false)
    expect(wrapper.text()).toContain("This invite can't be used")
    expect(wrapper.text()).toContain('This invite has expired.')
  })

  it('describes an admin invite with the right article', async () => {
    vi.mocked(api.get).mockImplementation((url: string) => {
      if (url === '/auth/registration-mode') {
        return Promise.resolve(ok({ data: { mode: 'invite_only' } }))
      }
      return Promise.resolve(
        ok({
          data: {
            token: 'invite-123',
            child_name: 'Wren',
            inviter_name: 'Sam',
            role: 'admin',
            relationship: null,
            expires_at: new Date().toISOString(),
          },
        }),
      )
    })

    const { wrapper } = await mountRegisterView('/register?invite_token=invite-123')

    expect(wrapper.find('.register__invite').text()).toContain('as an admin')
  })

  it('stops sign-up when the passwords do not match', async () => {
    vi.mocked(api.get).mockResolvedValue(ok({ data: { mode: 'open' } }))
    const { wrapper } = await mountRegisterView('/register')

    await wrapper.find('#register-name').setValue('Ana')
    await wrapper.find('#register-email').setValue('ana@example.com')
    await wrapper.find('#register-password').setValue('supersecret')
    await wrapper.find('#register-password-confirmation').setValue('supersecrt')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(wrapper.text()).toContain("Passwords don't match")
    expect(api.post).not.toHaveBeenCalled()
  })

  it('never calls an accepted password weak', async () => {
    vi.mocked(api.get).mockResolvedValue(ok({ data: { mode: 'open' } }))
    const { wrapper } = await mountRegisterView('/register')

    await wrapper.find('#register-password').setValue('short')
    expect(wrapper.find('.register__strength-text').text()).toContain('Too short')

    await wrapper.find('#register-password').setValue('abcdefgh')
    expect(wrapper.find('.register__strength-text').text()).toBe('Fair')
  })

  it('warns that a forgotten password cannot be recovered', async () => {
    vi.mocked(api.get).mockResolvedValue(ok({ data: { mode: 'open' } }))
    const { wrapper } = await mountRegisterView('/register')

    expect(wrapper.text()).toContain("We can't see it or reset it")
  })
})
