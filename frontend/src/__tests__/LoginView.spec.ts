import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createWebHistory } from 'vue-router'
import type { AxiosResponse } from 'axios'
import LoginView from '@/views/LoginView.vue'

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
  deriveKEK: vi.fn<(password: string, salt: string) => Promise<CryptoKey>>(),
  generateSalt: vi.fn<() => string>(),
  generateKeyPair: vi.fn<() => Promise<{ publicKey: CryptoKey; privateKey: CryptoKey }>>(),
  exportPublicKey: vi.fn<(key: CryptoKey) => Promise<string>>(),
  exportKEK: vi.fn<(kek: CryptoKey) => Promise<string>>(),
  importKEK: vi.fn<(jwk: string) => Promise<CryptoKey>>(),
}))

vi.mock('@/lib/cache/decrypted-cache', () => ({
  clearDecryptedCache: vi.fn<() => void>(),
}))

vi.mock('@simplewebauthn/browser', () => ({
  startAuthentication: vi.fn<(args: unknown) => Promise<unknown>>(),
}))

import { api } from '@/services/api'
import { setStaySignedIn } from '@/lib/auth-storage'
import { deriveKEK, exportKEK } from '@/crypto/keychain'
import { startAuthentication } from '@simplewebauthn/browser'
import { useAuthStore } from '@/stores/auth'

function ok<T>(data: T): AxiosResponse<T> {
  return { data, status: 200, statusText: 'OK', headers: {}, config: {} as never }
}

const authResponse = {
  user: {
    id: 1,
    email: 'ana@example.com',
    display_name: 'Ana',
    master_key_salt: 'salt',
    public_key: 'pub',
    encrypted_private_key: 'wrapped-private',
  },
  token: 'token-123',
  encryption_keys: [],
}

async function mountLoginView(path = '/login', beforeMount?: () => void) {
  setActivePinia(createPinia())
  beforeMount?.()

  const router = createRouter({
    history: createWebHistory(),
    routes: [
      { path: '/login', name: 'login', component: LoginView },
      { path: '/', name: 'home', component: { template: '<div>home</div>' } },
      { path: '/register', name: 'register', component: { template: '<div>register</div>' } },
    ],
  })

  router.push(path)
  await router.isReady()

  const wrapper = mount(LoginView, { global: { plugins: [router] } })
  await flushPromises()

  return { wrapper, router }
}

async function signInWithPassword(wrapper: Awaited<ReturnType<typeof mountLoginView>>['wrapper']) {
  await wrapper.find('#login-email').setValue('ana@example.com')
  await wrapper.find('#login-password').setValue('secret')
  await wrapper.find('form').trigger('submit.prevent')
  await flushPromises()
}

describe('LoginView', () => {
  beforeEach(() => {
    vi.mocked(api.get).mockReset()
    vi.mocked(api.post).mockReset()
    vi.mocked(setStaySignedIn).mockReset()
    vi.mocked(startAuthentication).mockReset()

    vi.mocked(api.get).mockResolvedValue(ok({ data: { mode: 'invite_only' } }))
    vi.mocked(deriveKEK).mockResolvedValue({ type: 'secret' } as unknown as CryptoKey)
    vi.mocked(exportKEK).mockResolvedValue('kek-jwk')
  })

  it('lets a passkey-only account finish sign-in with its passkey', async () => {
    vi.mocked(api.post).mockImplementation((url: string) => {
      if (url === '/auth/login') {
        return Promise.resolve(
          ok({ mfa_required: true, mfa_token: 'mfa-token', mfa_methods: ['passkey'] }),
        )
      }
      if (url === '/auth/mfa/passkey/options') {
        return Promise.resolve(ok({ options: { challenge: 'abc' } }))
      }
      if (url === '/auth/mfa/challenge') {
        return Promise.resolve(ok(authResponse))
      }
      throw new Error(`unexpected POST ${url}`)
    })
    vi.mocked(startAuthentication).mockResolvedValue({ id: 'cred', rawId: 'cred' } as never)

    const { wrapper, router } = await mountLoginView()
    await wrapper.find('input[type="checkbox"]').setValue(false)
    await signInWithPassword(wrapper)

    expect(wrapper.find('#mfa-code').exists()).toBe(false)
    expect(wrapper.text()).toContain('Use your passkey to finish signing in.')
    expect(wrapper.text()).not.toContain('Use a passkey')

    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(startAuthentication).toHaveBeenCalledWith({ optionsJSON: { challenge: 'abc' } })
    expect(api.post).toHaveBeenCalledWith('/auth/mfa/challenge', {
      mfa_token: 'mfa-token',
      method: 'passkey',
      passkey_response: { id: 'cred', rawId: 'cred' },
    })
    expect(setStaySignedIn).toHaveBeenCalledWith(false)
    expect(router.currentRoute.value.path).toBe('/')
  })

  it('returns to the password step when the MFA session has expired', async () => {
    vi.mocked(api.post).mockImplementation((url: string) => {
      if (url === '/auth/login') {
        return Promise.resolve(
          ok({ mfa_required: true, mfa_token: 'mfa-token', mfa_methods: ['totp'] }),
        )
      }
      return Promise.reject({
        response: {
          status: 422,
          data: {
            message: 'The MFA token has expired.',
            errors: { mfa_token: ['Your sign-in step timed out. Please sign in again.'] },
          },
        },
      })
    })

    const { wrapper } = await mountLoginView()
    await signInWithPassword(wrapper)

    const codeInput = wrapper.find('#mfa-code')
    ;(codeInput.element as HTMLInputElement).value = '123456'
    await codeInput.trigger('input')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(wrapper.find('#login-email').exists()).toBe(true)
    expect(wrapper.text()).toContain('Your sign-in step timed out. Please sign in again.')
  })

  it('keeps the user on the code step for a wrong code', async () => {
    vi.mocked(api.post).mockImplementation((url: string) => {
      if (url === '/auth/login') {
        return Promise.resolve(
          ok({ mfa_required: true, mfa_token: 'mfa-token', mfa_methods: ['totp'] }),
        )
      }
      return Promise.reject({
        response: {
          status: 422,
          data: { message: 'The code is invalid.', errors: { code: ['The code is invalid.'] } },
        },
      })
    })

    const { wrapper } = await mountLoginView()
    await signInWithPassword(wrapper)

    const codeInput = wrapper.find('#mfa-code')
    ;(codeInput.element as HTMLInputElement).value = '000000'
    await codeInput.trigger('input')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(wrapper.find('#mfa-code').exists()).toBe(true)
    expect(wrapper.text()).toContain('The code is invalid.')
  })

  it('prefills the email when signing in again after the key was lost', async () => {
    const { wrapper } = await mountLoginView('/login?reauth=1', () => {
      useAuthStore().user = { ...authResponse.user }
    })

    expect((wrapper.find('#login-email').element as HTMLInputElement).value).toBe('ana@example.com')
  })

  it('keeps only digits in the authenticator code, even when typing a letter', async () => {
    vi.mocked(api.post).mockResolvedValue(
      ok({ mfa_required: true, mfa_token: 'mfa-token', mfa_methods: ['totp'] }),
    )
    const { wrapper } = await mountLoginView()
    await signInWithPassword(wrapper)

    const input = wrapper.find('#mfa-code')
    await input.setValue('12')
    await input.setValue('12a')
    await flushPromises()
    expect((input.element as HTMLInputElement).value).toBe('12')

    await input.setValue('123 456 7')
    await flushPromises()
    expect((input.element as HTMLInputElement).value).toBe('123456')
  })

  it('explains the recovery code format', async () => {
    vi.mocked(api.post).mockResolvedValue(
      ok({ mfa_required: true, mfa_token: 'mfa-token', mfa_methods: ['totp'] }),
    )
    const { wrapper } = await mountLoginView()
    await signInWithPassword(wrapper)

    const recoveryOption = wrapper.findAll('.login__option').find((b) => b.text() === 'Use a recovery code')
    await recoveryOption!.trigger('click')

    expect(wrapper.find('#recovery-code').attributes('placeholder')).toBe('xxxx-xxxx-xxxx-xxxx')
    expect(wrapper.text()).toContain('16 letters and numbers')
  })

  it('shows the server reason when the account is locked out of codes', async () => {
    vi.mocked(api.post).mockImplementation((url: string) => {
      if (url === '/auth/login') {
        return Promise.resolve(
          ok({ mfa_required: true, mfa_token: 'mfa-token', mfa_methods: ['totp'] }),
        )
      }
      return Promise.reject({
        response: {
          status: 422,
          data: {
            message: 'Too many incorrect codes. Try again in 15 minutes.',
            errors: { code: ['Too many incorrect codes. Try again in 15 minutes.'] },
          },
        },
      })
    })
    const { wrapper } = await mountLoginView()
    await signInWithPassword(wrapper)

    await wrapper.find('#mfa-code').setValue('123456')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(wrapper.text()).toContain('Too many incorrect codes. Try again in 15 minutes.')
    expect(wrapper.find('#mfa-code').exists()).toBe(true)
  })
})
