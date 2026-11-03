import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import type { AxiosResponse } from 'axios'

vi.mock('@/services/api', () => ({
  api: {
    get: vi.fn<(url: string) => Promise<unknown>>(),
    post: vi.fn<(url: string, data?: unknown) => Promise<unknown>>(),
  },
}))

vi.mock('@/crypto/keychain', () => ({
  importPublicKey: vi.fn<() => Promise<CryptoKey>>(async () => ({}) as CryptoKey),
  rewrapDEKForPublicKey: vi.fn<() => Promise<string>>(async () => 'wrapped'),
}))

vi.mock('@/services/key-sync', () => ({
  syncEncryptionKeys: vi.fn<() => Promise<{ granted: number; failed: number }>>(async () => ({
    granted: 0,
    failed: 0,
  })),
}))

import { api } from '@/services/api'
import { syncEncryptionKeys } from '@/services/key-sync'
import {
  fetchPendingGrants,
  invalidatePendingGrants,
  PENDING_GRANTS_TTL_MS,
} from '@/services/pending-grants'
import { usePendingGrants } from '@/composables/usePendingGrants'
import AdminPendingAccessBanner from '@/components/AdminPendingAccessBanner.vue'
import { useAuthStore } from '@/stores/auth'
import { useChildStore } from '@/stores/child'
import type { Child } from '@/types/child'

function ok<T>(data: T): AxiosResponse<T> {
  return { data, status: 200, statusText: 'OK', headers: {}, config: {} as never }
}

const grant = { child_id: 1, user_id: 9, public_key: 'jwk' }

describe('pending grants requests', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    invalidatePendingGrants()
    vi.mocked(api.get).mockReset()
    vi.mocked(api.get).mockImplementation(async () => ok({ data: [grant] }))
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('shares one request between callers that ask together', async () => {
    const [a, b] = await Promise.all([fetchPendingGrants(), fetchPendingGrants()])

    expect(api.get).toHaveBeenCalledTimes(1)
    expect(a).toEqual([grant])
    expect(b).toEqual([grant])
  })

  it('reuses the list until the time is up', async () => {
    await fetchPendingGrants()
    await fetchPendingGrants()
    expect(api.get).toHaveBeenCalledTimes(1)

    vi.advanceTimersByTime(PENDING_GRANTS_TTL_MS + 1)
    await fetchPendingGrants()
    expect(api.get).toHaveBeenCalledTimes(2)
  })

  it('asks again after an invalidation', async () => {
    await fetchPendingGrants()
    invalidatePendingGrants()
    vi.mocked(api.get).mockImplementation(async () => ok({ data: [] }))

    expect(await fetchPendingGrants()).toEqual([])
    expect(api.get).toHaveBeenCalledTimes(2)
  })

  it('asks again when forced', async () => {
    await fetchPendingGrants()
    await fetchPendingGrants({ force: true })
    expect(api.get).toHaveBeenCalledTimes(2)
  })

  it('does not keep a list that was requested before an invalidation', async () => {
    let release: (value: AxiosResponse) => void = () => undefined
    vi.mocked(api.get).mockImplementationOnce(
      () => new Promise<AxiosResponse>((resolve) => (release = resolve)),
    )
    const stale = fetchPendingGrants()
    invalidatePendingGrants()
    release(ok({ data: [grant] }))
    await stale

    vi.mocked(api.get).mockImplementation(async () => ok({ data: [] }))
    expect(await fetchPendingGrants()).toEqual([])
  })

  it('does not keep a failed request', async () => {
    vi.mocked(api.get).mockRejectedValueOnce(new Error('offline'))
    await expect(fetchPendingGrants()).rejects.toThrow('offline')

    expect(await fetchPendingGrants()).toEqual([grant])
  })
})

describe('admin pending access banner remounts', () => {
  function child(id: number, role: string): Child {
    return { id, slug: `c${id}`, name: `Kid ${id}`, role } as Child
  }

  beforeEach(() => {
    setActivePinia(createPinia())
    useAuthStore().kek = {} as CryptoKey
    useChildStore().children = [child(1, 'owner')]
    usePendingGrants().reset()
    vi.mocked(api.get).mockReset()
    vi.mocked(api.get).mockImplementation(async () => ok({ data: [grant] }))
    vi.mocked(syncEncryptionKeys).mockClear()
  })

  it('does not sync or fetch again when navigating back within the reuse window', async () => {
    const first = mount(AdminPendingAccessBanner)
    await flushPromises()
    first.unmount()
    const second = mount(AdminPendingAccessBanner)
    await flushPromises()

    expect(syncEncryptionKeys).toHaveBeenCalledTimes(1)
    expect(api.get).toHaveBeenCalledTimes(1)
    expect(second.text()).toContain('1 family member waiting for access')
  })

  it('looks again when a person taps share access now', async () => {
    const wrapper = mount(AdminPendingAccessBanner)
    await flushPromises()
    vi.mocked(api.get).mockImplementation(async () => ok({ data: [] }))
    await wrapper.get('[data-testid="pending-access-share"]').trigger('click')
    await flushPromises()

    expect(api.get).toHaveBeenCalledTimes(2)
    expect(wrapper.find('[data-testid="pending-access-banner"]').exists()).toBe(false)
  })
})

describe('sharing a key', () => {
  it('drops the reused list so the next read shows who is still waiting', async () => {
    setActivePinia(createPinia())
    invalidatePendingGrants()
    vi.mocked(api.get).mockReset()
    vi.mocked(api.post).mockReset()
    vi.mocked(api.post).mockResolvedValue(ok({}))
    vi.mocked(api.get).mockImplementation(async () => ok({ data: [grant] }))
    const auth = useAuthStore()
    auth.kek = {} as CryptoKey
    auth.setEncryptionKeys([
      { child_id: 1, encrypted_dek: 'dek', wrap_type: 'kek', key_version: 1 },
    ] as never)
    await fetchPendingGrants()

    await useChildStore().grantAccess(1, 9, 'jwk')
    vi.mocked(api.get).mockImplementation(async () => ok({ data: [] }))

    expect(await fetchPendingGrants()).toEqual([])
  })
})
