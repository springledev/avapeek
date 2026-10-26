import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import type { AxiosResponse } from 'axios'

vi.mock('@/services/api', () => ({
  api: {
    get: vi.fn<(url: string) => Promise<unknown>>(),
    post: vi.fn<(url: string, data?: unknown) => Promise<unknown>>(),
    delete: vi.fn<(url: string, config?: unknown) => Promise<unknown>>(),
  },
}))

vi.mock('@/crypto/auth-hash', () => ({
  deriveKekAndAuthHash: vi.fn<(password: string, salt: string) => Promise<{ kek: CryptoKey; authHash: string }>>(
    async (password: string) => ({ kek: {} as CryptoKey, authHash: `auth-hash-of-${password}` }),
  ),
}))

vi.mock('vue-sonner', () => ({
  toast: {
    success: vi.fn<(message: string) => void>(),
    error: vi.fn<(message: string) => void>(),
  },
}))

vi.mock('qrcode', () => ({
  default: { toDataURL: vi.fn<(uri: string) => Promise<string>>(async () => 'data:image/png;base64,') },
}))

vi.mock('@simplewebauthn/browser', () => ({
  startRegistration: vi.fn<() => Promise<unknown>>(),
}))

import { api } from '@/services/api'
import { toast } from 'vue-sonner'
import { startRegistration } from '@simplewebauthn/browser'
import { useAuthStore } from '@/stores/auth'
import SecuritySettingsView from '@/views/SecuritySettingsView.vue'

function ok<T>(data: T): AxiosResponse<T> {
  return { data, status: 200, statusText: 'OK', headers: {}, config: {} as never }
}

function methodsResponse(methods: string[], passkeys: Array<{ id: string; name: string }> = []) {
  return ok({
    methods,
    recovery_codes_remaining: methods.length > 0 ? 10 : 0,
    passkeys: passkeys.map((p) => ({ ...p, last_used_at: null })),
  })
}

async function mountView() {
  setActivePinia(createPinia())
  useAuthStore().user = {
    id: 1,
    email: 'ana@example.com',
    display_name: 'Ana',
    master_key_salt: 'salt',
    public_key: 'pub',
  }
  const wrapper = mount(SecuritySettingsView, { global: { stubs: { teleport: true } } })
  await flushPromises()
  return wrapper
}

function buttonWithText(wrapper: Awaited<ReturnType<typeof mountView>>, text: string) {
  const button = wrapper.findAll('button').find((b) => b.text().trim() === text)
  if (!button) throw new Error(`No button "${text}"`)
  return button
}

async function confirmPassword(wrapper: Awaited<ReturnType<typeof mountView>>, password: string) {
  await wrapper.find('#confirm-password').setValue(password)
  await wrapper.find('.password-form').trigger('submit.prevent')
  await flushPromises()
}

describe('SecuritySettingsView', () => {
  beforeEach(() => {
    vi.mocked(api.get).mockReset()
    vi.mocked(api.post).mockReset()
    vi.mocked(toast.success).mockReset()
    vi.mocked(toast.error).mockReset()
    vi.mocked(startRegistration).mockReset()
  })

  it('explains that passkeys are a second step, not a password replacement', async () => {
    vi.mocked(api.get).mockResolvedValue(methodsResponse([]))
    const wrapper = await mountView()

    expect(wrapper.text()).toContain('Passkeys are a second step after your password')
  })

  it('asks for the password before starting authenticator setup', async () => {
    vi.mocked(api.get).mockResolvedValue(methodsResponse([]))
    vi.mocked(api.post).mockResolvedValue(ok({ secret: 'SECRET', otpauth_uri: 'otpauth://x' }))
    const wrapper = await mountView()

    await buttonWithText(wrapper, 'Set up authenticator').trigger('click')
    expect(api.post).not.toHaveBeenCalled()

    await confirmPassword(wrapper, 'secret')

    expect(api.post).toHaveBeenCalledWith('/auth/mfa/totp/setup', {
      current_auth_hash: 'auth-hash-of-secret',
    })
    expect(JSON.stringify(vi.mocked(api.post).mock.calls)).not.toContain('"secret"')
    expect(wrapper.text()).toContain('SECRET')
  })


  it('shows a wrong password inline instead of as a toast', async () => {
    vi.mocked(api.get).mockResolvedValue(methodsResponse([]))
    vi.mocked(api.post).mockRejectedValue({
      response: {
        status: 422,
        data: {
          message: 'The current password is incorrect.',
          errors: { current_auth_hash: ['The current password is incorrect.'] },
        },
      },
    })
    const wrapper = await mountView()

    await buttonWithText(wrapper, 'Set up authenticator').trigger('click')
    await confirmPassword(wrapper, 'wrong')

    expect(wrapper.find('.password-form__error').text()).toBe('The current password is incorrect.')
    expect(toast.error).not.toHaveBeenCalled()
  })

  it('uses a calm button to add a passkey and a danger button to remove one', async () => {
    vi.mocked(api.get).mockResolvedValue(methodsResponse(['passkey'], [{ id: 'pk-1', name: 'Phone' }]))
    const wrapper = await mountView()

    await buttonWithText(wrapper, 'Add a passkey').trigger('click')
    expect(wrapper.find('.password-form button[type="submit"]').classes()).toContain('uib--accent')

    await wrapper.find('.password-form button[type="button"]').trigger('click')
    await wrapper.find('[aria-label="Remove Phone"]').trigger('click')
    expect(wrapper.find('.password-form button[type="submit"]').classes()).toContain('uib--danger')
  })

  it('keeps existing recovery codes when adding an authenticator after a passkey', async () => {
    vi.mocked(api.get).mockResolvedValue(methodsResponse(['passkey']))
    vi.mocked(api.post).mockImplementation(async (url: string) => {
      if (url === '/auth/mfa/totp/setup') return ok({ secret: 'SECRET', otpauth_uri: 'otpauth://x' })
      return ok({ recovery_codes: null })
    })
    const wrapper = await mountView()

    await buttonWithText(wrapper, 'Set up authenticator').trigger('click')
    await confirmPassword(wrapper, 'secret')
    await wrapper.find('#totp-code').setValue('123 456')
    await flushPromises()
    await buttonWithText(wrapper, 'Confirm').trigger('click')
    await flushPromises()

    expect(api.post).toHaveBeenCalledWith('/auth/mfa/totp/confirm', { code: '123456' })
    expect(toast.success).toHaveBeenCalledWith(
      'Authenticator turned on. Your existing recovery codes still work.',
    )
    expect(wrapper.find('.security__codes').exists()).toBe(false)
  })

  it('shows recovery codes in groups and cannot be dismissed by accident', async () => {
    vi.mocked(api.get).mockResolvedValue(methodsResponse([]))
    vi.mocked(api.post).mockImplementation(async (url: string) => {
      if (url === '/auth/mfa/totp/setup') return ok({ secret: 'SECRET', otpauth_uri: 'otpauth://x' })
      return ok({ recovery_codes: ['abcdef1234567890'] })
    })
    const wrapper = await mountView()

    await buttonWithText(wrapper, 'Set up authenticator').trigger('click')
    await confirmPassword(wrapper, 'secret')
    await wrapper.find('#totp-code').setValue('123456')
    await buttonWithText(wrapper, 'Confirm').trigger('click')
    await flushPromises()

    expect(wrapper.find('.security__code').text()).toBe('abcd-ef12-3456-7890')

    await wrapper.find('.sheet__backdrop').trigger('click')
    expect(wrapper.find('.security__code').exists()).toBe(true)

    await buttonWithText(wrapper, "I've saved them").trigger('click')
    expect(wrapper.find('.security__code').exists()).toBe(false)
  })

  async function openPasskeySheet(wrapper: Awaited<ReturnType<typeof mountView>>) {
    await buttonWithText(wrapper, 'Add a passkey').trigger('click')
    await confirmPassword(wrapper, 'secret')
  }

  it('closes the passkey sheet and lists the new passkey after saving it', async () => {
    let saved = false
    vi.mocked(api.get).mockImplementation(async () =>
      saved ? methodsResponse(['passkey'], [{ id: 'pk-1', name: 'Bitwarden' }]) : methodsResponse([]),
    )
    vi.mocked(api.post).mockImplementation(async (url: string) => {
      if (url === '/auth/mfa/passkeys/register/options') return ok({ options: { challenge: 'abc' } })
      saved = true
      return ok({ passkey_id: 'pk-1', recovery_codes: null })
    })
    vi.mocked(startRegistration).mockResolvedValue({ id: 'cred' } as never)
    const wrapper = await mountView()

    await openPasskeySheet(wrapper)
    await wrapper.find('#passkey-name').setValue('Bitwarden')
    await wrapper.find('.security__passkey-form').trigger('submit.prevent')
    await flushPromises()

    expect(api.post).toHaveBeenCalledWith('/auth/mfa/passkeys/register/verify', {
      response: { id: 'cred' },
      user_chosen_name: 'Bitwarden',
    })
    expect(wrapper.find('#passkey-name').exists()).toBe(false)
    expect(wrapper.find('[aria-label="Remove Bitwarden"]').exists()).toBe(true)
  })

  it('shows the server reason inline and allows retrying when verification fails', async () => {
    vi.mocked(api.get).mockResolvedValue(methodsResponse([]))
    vi.mocked(api.post).mockImplementation(async (url: string) => {
      if (url === '/auth/mfa/passkeys/register/options') return ok({ options: { challenge: 'abc' } })
      throw {
        response: {
          status: 422,
          data: { errors: { response: ['The passkey registration could not be verified.'] } },
        },
      }
    })
    vi.mocked(startRegistration).mockResolvedValue({ id: 'cred' } as never)
    const wrapper = await mountView()

    await openPasskeySheet(wrapper)
    await wrapper.find('.security__passkey-form').trigger('submit.prevent')
    await flushPromises()

    expect(wrapper.find('.security__passkey-error').text()).toContain('could not be verified')
    expect(wrapper.find('#passkey-name').exists()).toBe(true)
    expect(wrapper.find('.security__passkey-form button[type="submit"]').attributes('disabled')).toBeUndefined()
  })

  it('shows an inline message when the passkey prompt is cancelled', async () => {
    vi.mocked(api.get).mockResolvedValue(methodsResponse([]))
    vi.mocked(api.post).mockResolvedValue(ok({ options: { challenge: 'abc' } }))
    const cancelled = new Error('cancelled')
    cancelled.name = 'NotAllowedError'
    vi.mocked(startRegistration).mockRejectedValue(cancelled)
    const wrapper = await mountView()

    await openPasskeySheet(wrapper)
    await wrapper.find('.security__passkey-form').trigger('submit.prevent')
    await flushPromises()

    expect(wrapper.find('.security__passkey-error').text()).toContain('cancelled')
  })
})
