import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import type { AxiosResponse } from 'axios'

vi.mock('@/services/api', () => ({
  api: {
    get: vi.fn<(url: string) => Promise<unknown>>(),
    post: vi.fn<(url: string, data?: unknown) => Promise<unknown>>(),
    delete: vi.fn<(url: string) => Promise<unknown>>(),
  },
}))

vi.mock('@/crypto/keychain', () => ({
  generateDEK: vi.fn<() => Promise<CryptoKey>>(),
  wrapDEK: vi.fn<() => Promise<string>>(),
  unwrapDEK: vi.fn<() => Promise<CryptoKey>>(),
  importPublicKey: vi.fn<() => Promise<CryptoKey>>(),
  rewrapDEKForPublicKey: vi.fn<() => Promise<string>>(),
}))

import { api } from '@/services/api'
import { createSharedRequest } from '@/lib/shared-request'
import { fetchPendingInvites, invalidatePendingInvites } from '@/services/pending-invites'
import { useChildStore } from '@/stores/child'
import { useFeedStore } from '@/stores/feed'
import { useRecentsStore } from '@/stores/recents'
import { useTagsStore } from '@/stores/tags'

function ok<T>(data: T): AxiosResponse<T> {
  return { data, status: 200, statusText: 'OK', headers: {}, config: {} as never }
}

const kid = { id: 1, name: 'Mia', role: 'owner' }

describe('shared request helper', () => {
  afterEach(() => {
    vi.useRealTimers()
  })

  it('does not remember a failed request', async () => {
    const load = vi.fn<() => Promise<string>>()
    load.mockRejectedValueOnce(new Error('offline')).mockResolvedValue('ok')
    const shared = createSharedRequest(load, 1000)

    await expect(shared.get()).rejects.toThrow('offline')
    expect(await shared.get()).toBe('ok')
    expect(load).toHaveBeenCalledTimes(2)
  })
})

describe('children list requests', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.useFakeTimers()
    vi.mocked(api.get).mockReset()
    vi.mocked(api.get).mockImplementation(async () => ok({ data: [kid] }))
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('shares one request between callers that load together', async () => {
    const store = useChildStore()
    await Promise.all([store.fetchChildren(), store.fetchChildren(), store.fetchChildren()])

    expect(api.get).toHaveBeenCalledTimes(1)
    expect(store.children).toHaveLength(1)
  })

  it('keeps a loaded list for a short while, then asks again', async () => {
    const store = useChildStore()
    await store.fetchChildren()
    await store.fetchChildren()
    expect(api.get).toHaveBeenCalledTimes(1)

    vi.advanceTimersByTime(31_000)
    await store.fetchChildren()
    expect(api.get).toHaveBeenCalledTimes(2)
  })

  it('shows fresh data when forced', async () => {
    const store = useChildStore()
    await store.fetchChildren()
    vi.mocked(api.get).mockImplementation(async () => ok({ data: [kid, { ...kid, id: 2 }] }))
    await store.fetchChildren({ force: true })

    expect(store.children).toHaveLength(2)
  })

  it('asks again after a kid is deleted', async () => {
    vi.mocked(api.delete).mockResolvedValue(ok({}))
    vi.spyOn(useFeedStore(), 'fetchPhotos').mockResolvedValue()
    vi.spyOn(useRecentsStore(), 'fetchRecents').mockResolvedValue()
    vi.spyOn(useRecentsStore(), 'fetchUnseenCount').mockResolvedValue()
    const store = useChildStore()
    await store.fetchChildren()
    await store.deleteChild(1)
    vi.mocked(api.get).mockImplementation(async () => ok({ data: [] }))
    await store.fetchChildren()

    expect(api.get).toHaveBeenCalledTimes(2)
    expect(store.children).toEqual([])
  })

  it('asks again after an invite is created, because the invite list changed', async () => {
    vi.mocked(api.post).mockResolvedValue(ok({ data: { token: 't' } }))
    vi.mocked(api.get).mockImplementation(async () => ok({ data: [] }))
    invalidatePendingInvites()
    await fetchPendingInvites()
    await fetchPendingInvites()
    expect(api.get).toHaveBeenCalledTimes(1)

    await useChildStore().createInvite(1, 'member', null, false)
    await fetchPendingInvites()
    expect(api.get).toHaveBeenCalledTimes(2)
  })
})

describe('pending invites requests', () => {
  beforeEach(() => {
    invalidatePendingInvites()
    vi.mocked(api.get).mockReset()
    vi.mocked(api.get).mockImplementation(async () => ok({ data: [{ id: 1 }] }))
  })

  it('serves settings and a kid page that open together from one request', async () => {
    const [a, b] = await Promise.all([fetchPendingInvites(), fetchPendingInvites()])

    expect(api.get).toHaveBeenCalledTimes(1)
    expect(a).toEqual(b)
  })

  it('asks again after a revoke', async () => {
    await fetchPendingInvites()
    invalidatePendingInvites()
    await fetchPendingInvites()

    expect(api.get).toHaveBeenCalledTimes(2)
  })
})

describe('tag list requests', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.useFakeTimers()
    vi.spyOn(console, 'error').mockImplementation(() => undefined)
    vi.mocked(api.get).mockReset()
    vi.spyOn(useChildStore(), 'tryGetChildDEK').mockResolvedValue({} as CryptoKey)
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('does not hammer a tag list that just failed to load', async () => {
    vi.mocked(api.get).mockRejectedValue(new Error('429'))
    const tags = useTagsStore()

    await tags.loadAll([1])
    await tags.loadAll([1])
    expect(api.get).toHaveBeenCalledTimes(1)

    vi.advanceTimersByTime(31_000)
    await tags.loadAll([1])
    expect(api.get).toHaveBeenCalledTimes(2)
  })

  it('loads each child once and keeps the result', async () => {
    vi.mocked(api.get).mockImplementation(async () => ok({ data: [] }))
    const tags = useTagsStore()

    await Promise.all([tags.loadAll([1, 2]), tags.loadAll([1, 2])])
    await tags.loadAll([1, 2])

    expect(api.get).toHaveBeenCalledTimes(2)
  })
})
