import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import type { AxiosResponse } from 'axios'

vi.mock('@/services/api', () => ({
  api: {
    get: vi.fn<(url: string) => Promise<unknown>>(),
    post: vi.fn<(url: string, data?: unknown) => Promise<unknown>>(),
    put: vi.fn<(url: string, data?: unknown) => Promise<unknown>>(),
    patch: vi.fn<(url: string, data?: unknown) => Promise<unknown>>(),
    delete: vi.fn<(url: string) => Promise<unknown>>(),
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
  removePendingInviteToken: vi.fn<() => void>(),
  setStaySignedIn: vi.fn<(value: boolean) => void>(),
}))

vi.mock('@/lib/cache/decrypted-cache', () => ({
  clearDecryptedCache: vi.fn<() => void>(),
  setDecryptedCacheUser: vi.fn<(userId: number | null) => void>(),
}))

vi.mock('@/crypto/keychain', () => ({
  generateDEK: vi.fn<() => Promise<CryptoKey>>(),
  wrapDEK: vi.fn<() => Promise<string>>(),
  unwrapDEK: vi.fn<() => Promise<CryptoKey>>(),
  importPublicKey: vi.fn<() => Promise<CryptoKey>>(),
  rewrapDEKForPublicKey: vi.fn<() => Promise<string>>(),
}))

vi.mock('@/crypto/encrypt', () => ({
  encryptString: vi.fn<() => Promise<unknown>>(),
  encryptBuffer: vi.fn<() => Promise<unknown>>(),
  decryptBlob: vi.fn<() => Promise<unknown>>(),
}))

vi.mock('vue-sonner', () => ({
  toast: {
    success: vi.fn<(message: string) => void>(),
    error: vi.fn<(message: string) => void>(),
  },
}))

import { api } from '@/services/api'
import { toast } from 'vue-sonner'
import { clearDecryptedCache } from '@/lib/cache/decrypted-cache'
import * as keychain from '@/crypto/keychain'
import * as encrypt from '@/crypto/encrypt'
import { useAuthStore } from '@/stores/auth'
import { useChildStore } from '@/stores/child'
import { useFeedStore } from '@/stores/feed'
import { useRecentsStore } from '@/stores/recents'
import ChildSettingsView from '@/views/ChildSettingsView.vue'
import UiButton from '@/components/ui/UiButton.vue'
import type { Child, ChildMember } from '@/types/child'

function ok<T>(data: T): AxiosResponse<T> {
  return { data, status: 200, statusText: 'OK', headers: {}, config: {} as never }
}

function makeChild(role: string): Child {
  return {
    id: 7,
    slug: 'emma',
    name: 'Emma',
    birth_date: null,
    avatar_color: '#FF6B6B',
    age: null,
    role,
    relationship: 'parent',
    storage_used: 0,
    storage_limit_bytes: null,
    storage_percentage: 0,
    created_at: '2026-01-01T00:00:00Z',
  }
}

function member(id: number, name: string, role: string): ChildMember {
  return { id, display_name: name, role, relationship: null, joined_at: '2026-01-01' }
}

const ME = 1

const allMembers = [
  member(ME, 'Me', 'owner'),
  member(2, 'Ada', 'admin'),
  member(3, 'Max', 'member'),
]

function assertNoCrypto() {
  for (const fn of Object.values(keychain)) expect(fn).not.toHaveBeenCalled()
  for (const fn of Object.values(encrypt)) expect(fn).not.toHaveBeenCalled()
}

async function mountView(role: string, members: ChildMember[], myId = ME) {
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().user = {
    id: myId,
    email: 'me@example.com',
    display_name: 'Me',
    master_key_salt: 'salt',
    public_key: 'pub',
  }
  const childStore = useChildStore()
  childStore.children = [makeChild(role)]
  vi.spyOn(useFeedStore(), 'fetchPhotos').mockResolvedValue(undefined as never)
  vi.spyOn(useRecentsStore(), 'fetchRecents').mockResolvedValue(undefined as never)
  vi.spyOn(useRecentsStore(), 'fetchUnseenCount').mockResolvedValue(undefined as never)

  vi.mocked(api.get).mockImplementation(async (url: string) => {
    if (url === '/invites/pending') return ok({ data: [] })
    return ok({ data: { members } })
  })

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/settings/children/:id', component: ChildSettingsView },
      { path: '/settings/children', component: { template: '<div>list</div>' } },
    ],
  })
  router.push('/settings/children/7')
  await router.isReady()

  const wrapper = mount(ChildSettingsView, {
    global: { plugins: [pinia, router], stubs: { teleport: true } },
  })
  await flushPromises()
  return { wrapper, router, childStore }
}

type Wrapper = Awaited<ReturnType<typeof mountView>>['wrapper']

function buttonTexts(wrapper: Wrapper): string[] {
  return wrapper.findAll('button').map((b) => b.text().trim())
}

function button(wrapper: Wrapper, text: string) {
  const found = wrapper.findAll('button').find((b) => b.text().trim() === text)
  if (!found) throw new Error(`No button "${text}" in: ${buttonTexts(wrapper).join(' | ')}`)
  return found
}

async function openMember(wrapper: Wrapper, name: string) {
  const row = wrapper.findAll('button.child-settings__member').find((b) => b.text().includes(name))
  if (!row) throw new Error(`No tappable row for ${name}`)
  await row.trigger('click')
  await flushPromises()
}

function sheetActions(wrapper: Wrapper): string[] {
  return wrapper
    .find('.member-sheet')
    .findAll('button')
    .map((b) => b.text().trim())
}

describe('child membership', () => {
  beforeEach(() => {
    for (const fn of [api.get, api.post, api.put, api.patch, api.delete]) vi.mocked(fn).mockReset()
    vi.mocked(toast.success).mockReset()
    vi.mocked(toast.error).mockReset()
    vi.mocked(clearDecryptedCache).mockReset()
    vi.stubGlobal(
      'confirm',
      vi.fn<(message?: string) => boolean>(() => true),
    )
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  describe('store actions', () => {
    it('setMemberRole PATCHes the member and returns it', async () => {
      const { childStore } = await mountView('owner', allMembers)
      vi.mocked(api.patch).mockResolvedValue(ok({ data: member(3, 'Max', 'admin') }))

      const updated = await childStore.setMemberRole(7, 3, 'admin')

      expect(api.patch).toHaveBeenCalledWith('/children/7/members/3', { role: 'admin' })
      expect(updated.role).toBe('admin')
      assertNoCrypto()
    })

    it('transferOwnership posts the target and password and demotes the caller locally', async () => {
      const { childStore } = await mountView('owner', allMembers)
      vi.mocked(api.post).mockResolvedValue(ok({ data: member(2, 'Ada', 'owner') }))

      await childStore.transferOwnership(7, 2, 'hunter2')

      expect(api.post).toHaveBeenCalledWith('/children/7/transfer-ownership', {
        user_id: 2,
        current_password: 'hunter2',
      })
      expect(childStore.children[0]?.role).toBe('admin')
      assertNoCrypto()
    })

    it('leaveChild clears keys and caches and removes the child', async () => {
      const { childStore } = await mountView('member', [])
      childStore.setDEK(7, { type: 'dek' } as unknown as CryptoKey)
      vi.mocked(api.post).mockResolvedValue(ok({ message: 'ok' }))

      await childStore.leaveChild(7)

      expect(api.post).toHaveBeenCalledWith('/children/7/leave')
      expect(childStore.children).toEqual([])
      expect(childStore.getDEK(7)).toBeUndefined()
      expect(clearDecryptedCache).toHaveBeenCalledTimes(1)
      assertNoCrypto()
    })

    it('leaveChild keeps state when the server refuses', async () => {
      const { childStore } = await mountView('owner', allMembers)
      vi.mocked(api.post).mockRejectedValue(new Error('409'))

      await expect(childStore.leaveChild(7)).rejects.toThrow('409')

      expect(childStore.children).toHaveLength(1)
      expect(clearDecryptedCache).not.toHaveBeenCalled()
    })
  })

  describe('member sheet actions per role', () => {
    it('owner on an admin: make member, transfer, remove', async () => {
      const { wrapper } = await mountView('owner', allMembers)
      await openMember(wrapper, 'Ada')
      expect(sheetActions(wrapper)).toEqual([
        'Make member',
        'Transfer ownership',
        'Remove from family',
      ])
    })

    it('owner on a member: make admin, transfer, remove', async () => {
      const { wrapper } = await mountView('owner', allMembers)
      await openMember(wrapper, 'Max')
      expect(sheetActions(wrapper)).toEqual([
        'Make admin',
        'Transfer ownership',
        'Remove from family',
      ])
    })

    it('owner has no row action on themselves and no Leave', async () => {
      const { wrapper } = await mountView('owner', allMembers)
      expect(
        wrapper.findAll('button.child-settings__member').some((b) => b.text().includes('Me')),
      ).toBe(false)
      expect(buttonTexts(wrapper)).not.toContain('Leave this kid')
    })

    it('admin can only remove members, and leave from their own row', async () => {
      const list = [
        member(ME, 'Me', 'admin'),
        member(2, 'Olly', 'owner'),
        member(3, 'Max', 'member'),
        member(4, 'Ada', 'admin'),
      ]
      const { wrapper } = await mountView('admin', list)

      await openMember(wrapper, 'Max')
      expect(sheetActions(wrapper)).toEqual(['Remove from family'])

      const rows = wrapper.findAll('button.child-settings__member').map((b) => b.text())
      expect(rows.some((t) => t.includes('Olly'))).toBe(false)
      expect(rows.some((t) => t.includes('Ada'))).toBe(false)

      await wrapper.find('.member-sheet').find('button').trigger('click')
      await flushPromises()
      await openMember(wrapper, 'Me')
      expect(sheetActions(wrapper)).toEqual(['Leave this kid'])
    })

    it('plain member gets a standalone Leave and no member rows', async () => {
      const { wrapper } = await mountView('member', [])
      expect(wrapper.findAll('button.child-settings__member')).toHaveLength(0)
      expect(buttonTexts(wrapper)).toContain('Leave this kid')
      expect(wrapper.text()).not.toContain('No family members yet.')
    })

    it('only the owner can offer admin in the invite form', async () => {
      const owner = await mountView('owner', allMembers)
      await button(owner.wrapper, 'Generate invite link').trigger('click')
      expect(owner.wrapper.find('#invite-role').text()).toContain('Admin')
      const admin = await mountView('admin', [member(ME, 'Me', 'admin')])
      await button(admin.wrapper, 'Generate invite link').trigger('click')
      expect(admin.wrapper.find('#invite-role').text()).not.toContain('Admin')
    })
  })

  describe('actions', () => {
    it('promotes a member and updates the list', async () => {
      const { wrapper } = await mountView('owner', allMembers)
      vi.mocked(api.patch).mockResolvedValue(ok({ data: member(3, 'Max', 'admin') }))

      await openMember(wrapper, 'Max')
      await button(wrapper, 'Make admin').trigger('click')
      await flushPromises()

      expect(api.patch).toHaveBeenCalledWith('/children/7/members/3', { role: 'admin' })
      expect(toast.success).toHaveBeenCalled()
      expect(wrapper.text()).toContain('admin')
      assertNoCrypto()
    })

    it('surfaces a role change error via toast', async () => {
      const { wrapper } = await mountView('owner', allMembers)
      vi.mocked(api.patch).mockRejectedValue(
        Object.assign(new Error('x'), {
          response: { data: { message: 'Nope.' } },
          isAxiosError: true,
        }),
      )

      await openMember(wrapper, 'Max')
      await button(wrapper, 'Make admin').trigger('click')
      await flushPromises()

      expect(toast.error).toHaveBeenCalled()
    })

    it.each([
      ['demote', 'Ada', 'Make member'],
      ['remove', 'Max', 'Remove from family'],
    ])('cancelling confirm on %s makes no API call', async (_name, who, label) => {
      vi.stubGlobal(
        'confirm',
        vi.fn<(message?: string) => boolean>(() => false),
      )
      const { wrapper } = await mountView('owner', allMembers)

      await openMember(wrapper, who)
      await button(wrapper, label).trigger('click')
      await flushPromises()

      expect(confirm).toHaveBeenCalledTimes(1)
      expect(api.patch).not.toHaveBeenCalled()
      expect(api.delete).not.toHaveBeenCalled()
    })

    it('remove confirms, deletes and drops the row', async () => {
      const { wrapper } = await mountView('owner', allMembers)
      vi.mocked(api.delete).mockResolvedValue(ok({ message: 'ok' }))

      await openMember(wrapper, 'Max')
      await button(wrapper, 'Remove from family').trigger('click')
      await flushPromises()

      expect(confirm).toHaveBeenCalledTimes(1)
      expect(api.delete).toHaveBeenCalledWith('/children/7/members/3')
      expect(wrapper.text()).not.toContain('Max')
    })

    it('cancelling the transfer confirm opens no password sheet and makes no call', async () => {
      vi.stubGlobal(
        'confirm',
        vi.fn<(message?: string) => boolean>(() => false),
      )
      const { wrapper } = await mountView('owner', allMembers)

      await openMember(wrapper, 'Max')
      await button(wrapper, 'Transfer ownership').trigger('click')
      await flushPromises()

      expect(wrapper.find('#confirm-password').exists()).toBe(false)
      expect(api.post).not.toHaveBeenCalled()
    })

    it('transfer confirms, asks for the password, then posts', async () => {
      const { wrapper, childStore } = await mountView('owner', allMembers)
      vi.mocked(api.post).mockResolvedValue(ok({ data: member(3, 'Max', 'owner') }))

      await openMember(wrapper, 'Max')
      await button(wrapper, 'Transfer ownership').trigger('click')
      await flushPromises()
      expect(confirm).toHaveBeenCalledTimes(1)
      expect(api.post).not.toHaveBeenCalled()

      await wrapper.find('#confirm-password').setValue('hunter2')
      await wrapper.find('.password-form').trigger('submit.prevent')
      await flushPromises()

      expect(api.post).toHaveBeenCalledWith('/children/7/transfer-ownership', {
        user_id: 3,
        current_password: 'hunter2',
      })
      expect(childStore.children[0]?.role).toBe('admin')
      assertNoCrypto()
    })

    it('shows a wrong-password error and keeps ownership', async () => {
      const { wrapper, childStore } = await mountView('owner', allMembers)
      vi.mocked(api.post).mockRejectedValue(
        Object.assign(new Error('x'), {
          isAxiosError: true,
          response: {
            status: 422,
            data: { errors: { current_password: ['The current password is incorrect.'] } },
          },
        }),
      )

      await openMember(wrapper, 'Max')
      await button(wrapper, 'Transfer ownership').trigger('click')
      await flushPromises()
      await wrapper.find('#confirm-password').setValue('bad')
      await wrapper.find('.password-form').trigger('submit.prevent')
      await flushPromises()

      expect(toast.error).toHaveBeenCalled()
      expect(childStore.children[0]?.role).toBe('owner')
    })

    it('leave cancel makes no call', async () => {
      vi.stubGlobal(
        'confirm',
        vi.fn<(message?: string) => boolean>(() => false),
      )
      const { wrapper } = await mountView('member', [])

      await button(wrapper, 'Leave this kid').trigger('click')
      await flushPromises()

      expect(confirm).toHaveBeenCalledTimes(1)
      expect(api.post).not.toHaveBeenCalled()
    })

    it('leave states access is revoked in the app, not re-encrypted, then navigates away', async () => {
      const { wrapper, router, childStore } = await mountView('member', [])
      vi.mocked(api.post).mockResolvedValue(ok({ message: 'ok' }))

      await button(wrapper, 'Leave this kid').trigger('click')
      await flushPromises()

      const message = vi.mocked(confirm).mock.calls[0]?.[0] ?? ''
      expect(message).toContain('lose access')
      expect(message).toContain('not re-encrypted')
      expect(api.post).toHaveBeenCalledWith('/children/7/leave')
      expect(childStore.children).toEqual([])
      expect(clearDecryptedCache).toHaveBeenCalled()
      expect(router.currentRoute.value.path).toBe('/settings/children')
      assertNoCrypto()
    })

    it('surfaces a leave error via toast and stays on the page', async () => {
      const { wrapper, router } = await mountView('admin', [member(ME, 'Me', 'admin')])
      vi.mocked(api.post).mockRejectedValue(new Error('boom'))

      await button(wrapper, 'Leave this kid').trigger('click')
      await flushPromises()

      expect(toast.error).toHaveBeenCalled()
      expect(router.currentRoute.value.path).toBe('/settings/children/7')
    })
  })

  describe('touch targets', () => {
    it('every sheet action and the Leave button is a UiButton (48px min)', async () => {
      const { wrapper } = await mountView('owner', allMembers)
      await openMember(wrapper, 'Max')
      const sheet = wrapper.find('.member-sheet')
      expect(sheet.findAll('button').length).toBe(sheet.findAllComponents(UiButton).length)

      const plain = await mountView('member', [])
      const leave = plain.wrapper
        .findAllComponents(UiButton)
        .find((b) => b.text() === 'Leave this kid')
      expect(leave).toBeDefined()
    })

    it('tappable member rows declare the --target-min minimum height', async () => {
      const source = (await import('@/views/ChildSettingsView.vue?raw')).default as string
      const rule = source.match(/\.child-settings__member \{[^}]*\}/)?.[0] ?? ''
      expect(rule).toContain('min-height: var(--target-min)')
    })
  })
})
