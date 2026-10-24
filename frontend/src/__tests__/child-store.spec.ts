import { describe, it, expect, vi, beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import type { AxiosResponse } from 'axios'

vi.mock('@/services/api', () => ({
  api: {
    get: vi.fn<(url: string) => Promise<unknown>>(),
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
  getSessionItem: vi.fn<(key: string) => string | null>(() => null),
  setSessionItem: vi.fn<(key: string, value: string) => void>(),
  removeSessionItem: vi.fn<(key: string) => void>(),
  clearSession: vi.fn<() => void>(),
  removePendingInviteToken: vi.fn<() => void>(),
  setStaySignedIn: vi.fn<(value: boolean) => void>(),
}))

vi.mock('@/crypto/keychain', () => ({
  generateDEK: vi.fn<() => Promise<CryptoKey>>(
    async () => ({ type: 'dek' }) as unknown as CryptoKey,
  ),
  wrapDEK: vi.fn<(dek: CryptoKey, kek: CryptoKey) => Promise<string>>(async () => 'wrapped-dek'),
  unwrapDEK: vi.fn<(wrapped: string, kek: CryptoKey) => Promise<CryptoKey>>(
    async () => ({ type: 'dek' }) as unknown as CryptoKey,
  ),
  importPublicKey: vi.fn<(jwk: string) => Promise<CryptoKey>>(),
  rewrapDEKForPublicKey: vi.fn<() => Promise<string>>(),
}))

import { api } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useChildStore } from '@/stores/child'
import type { Child } from '@/types/child'

function ok<T>(data: T): AxiosResponse<T> {
  return { data, status: 200, statusText: 'OK', headers: {}, config: {} as never }
}

function makeChild(overrides: Partial<Child> = {}): Child {
  return {
    id: 7,
    slug: 'emma',
    name: 'Emma',
    birth_date: null,
    avatar_color: '#FF6B6B',
    age: null,
    role: 'owner',
    relationship: 'parent',
    storage_used: 0,
    storage_limit_bytes: null,
    storage_percentage: 0,
    created_at: '2026-01-01T00:00:00Z',
    ...overrides,
  }
}

function setup() {
  const auth = useAuthStore()
  auth.kek = { type: 'kek' } as unknown as CryptoKey
  return { auth, store: useChildStore() }
}

describe('child store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(api.get).mockReset()
    vi.mocked(api.post).mockReset()
    vi.mocked(api.put).mockReset()
    vi.mocked(api.delete).mockReset()
    vi.spyOn(console, 'error').mockImplementation(() => {})
  })

  describe('createChild', () => {
    it('creates the child, then its key', async () => {
      const { auth, store } = setup()
      vi.mocked(api.post).mockImplementation(async (url: string) =>
        url === '/children' ? ok({ data: makeChild() }) : ok({}),
      )

      const child = await store.createChild('Emma', '2024-01-01')

      expect(api.post).toHaveBeenNthCalledWith(1, '/children', {
        name: 'Emma',
        birth_date: '2024-01-01',
      })
      expect(api.post).toHaveBeenNthCalledWith(2, '/encryption-keys', {
        child_id: 7,
        wrapped_dek: 'wrapped-dek',
      })
      expect(child.id).toBe(7)
      expect(store.children).toHaveLength(1)
      expect(store.getDEK(7)).toBeDefined()
      expect(auth.encryptionKeys.map((k) => k.child_id)).toEqual([7])
      expect(store.keyPendingChild).toBeNull()
    })

    it('does not add a child when the create call fails', async () => {
      const { store } = setup()
      vi.mocked(api.post).mockRejectedValueOnce(new Error('boom'))

      await expect(store.createChild('Emma', null)).rejects.toThrow('boom')

      expect(store.children).toHaveLength(0)
      expect(store.keyPendingChild).toBeNull()
      expect(store.error).toBeTruthy()
    })

    it('surfaces a key failure and retries only the key step', async () => {
      const { auth, store } = setup()
      let keyCalls = 0
      vi.mocked(api.post).mockImplementation(async (url: string) => {
        if (url === '/children') return ok({ data: makeChild() })
        keyCalls++
        if (keyCalls === 1) throw new Error('network down')
        return ok({})
      })

      await expect(store.createChild('Emma', null)).rejects.toThrow('network down')

      expect(store.keyPendingChild?.id).toBe(7)
      expect(store.error).toMatch(/encryption key/i)
      expect(auth.encryptionKeys).toHaveLength(0)

      const child = await store.retryKeySetup()

      // create child, failed key, retried key: the child is never created twice
      expect(api.post).toHaveBeenCalledTimes(3)
      expect(api.post).toHaveBeenLastCalledWith('/encryption-keys', {
        child_id: 7,
        wrapped_dek: 'wrapped-dek',
      })
      expect(child.id).toBe(7)
      expect(store.keyPendingChild).toBeNull()
      expect(auth.encryptionKeys.map((k) => k.child_id)).toEqual([7])
      expect(store.children.filter((c) => c.id === 7)).toHaveLength(1)
      expect(store.getDEK(7)).toBeDefined()
    })

    it('keeps the pending child when the retry fails too', async () => {
      const { store } = setup()
      vi.mocked(api.post).mockImplementation(async (url: string) => {
        if (url === '/children') return ok({ data: makeChild() })
        throw new Error('still down')
      })

      await expect(store.createChild('Emma', null)).rejects.toThrow('still down')
      await expect(store.retryKeySetup()).rejects.toThrow('still down')

      expect(store.keyPendingChild?.id).toBe(7)
    })
  })

  describe('updateChild', () => {
    it('PUTs name and birth date and merges the response into state', async () => {
      const { store } = setup()
      store.children = [makeChild({ photo_count: 3 })]
      vi.mocked(api.put).mockResolvedValue(
        ok({ data: makeChild({ name: 'Olivia', birth_date: '2023-02-03', age: '3 years old' }) }),
      )

      const updated = await store.updateChild(7, { name: 'Olivia', birthDate: '2023-02-03' })

      expect(api.put).toHaveBeenCalledWith('/children/7', {
        name: 'Olivia',
        birth_date: '2023-02-03',
      })
      expect(updated.name).toBe('Olivia')
      expect(store.children[0]).toMatchObject({ name: 'Olivia', birth_date: '2023-02-03' })
    })

    it('records the error and rethrows on failure', async () => {
      const { store } = setup()
      store.children = [makeChild()]
      vi.mocked(api.put).mockRejectedValue({ response: { data: { message: 'Nope' } } })

      await expect(store.updateChild(7, { name: 'X', birthDate: null })).rejects.toBeDefined()

      expect(store.error).toBe('Nope')
      expect(store.children[0]!.name).toBe('Emma')
    })
  })

  describe('deleteChild', () => {
    it('removes the child, its DEK and its keys locally', async () => {
      const { auth, store } = setup()
      store.children = [makeChild(), makeChild({ id: 8, name: 'Noah' })]
      store.setDEK(7, {} as CryptoKey)
      store.setDEK(8, {} as CryptoKey)
      store.setCurrentChild(7)
      auth.setEncryptionKeys([
        { child_id: 7, child_name: 'Emma', encrypted_dek: 'a', wrap_type: 'kek', key_version: 1 },
        { child_id: 8, child_name: 'Noah', encrypted_dek: 'b', wrap_type: 'kek', key_version: 1 },
      ])
      vi.mocked(api.delete).mockResolvedValue(ok(null))

      await store.deleteChild(7)

      expect(api.delete).toHaveBeenCalledWith('/children/7')
      expect(store.children.map((c) => c.id)).toEqual([8])
      expect(store.getDEK(7)).toBeUndefined()
      expect(store.getDEK(8)).toBeDefined()
      expect(store.currentChildId).toBeNull()
      expect(auth.encryptionKeys.map((k) => k.child_id)).toEqual([8])
    })

    it('leaves local state alone when the server refuses', async () => {
      const { auth, store } = setup()
      store.children = [makeChild()]
      auth.setEncryptionKeys([
        { child_id: 7, child_name: 'Emma', encrypted_dek: 'a', wrap_type: 'kek', key_version: 1 },
      ])
      vi.mocked(api.delete).mockRejectedValue({ response: { data: { message: 'Forbidden' } } })

      await expect(store.deleteChild(7)).rejects.toBeDefined()

      expect(store.children).toHaveLength(1)
      expect(auth.encryptionKeys).toHaveLength(1)
      expect(store.error).toBe('Forbidden')
    })
  })
})
