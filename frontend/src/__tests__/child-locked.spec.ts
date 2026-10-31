import { describe, it, expect, vi, beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

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

import { api } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useChildStore } from '@/stores/child'
import type { Child } from '@/types/child'

function makeChild(overrides: Partial<Child> = {}): Child {
  return {
    id: 7,
    slug: 'emma',
    name: 'Emma',
    birth_date: null,
    avatar_color: '#FF6B6B',
    age: null,
    role: 'member',
    relationship: null,
    storage_used: 0,
    storage_limit_bytes: null,
    storage_percentage: 0,
    created_at: '2026-01-01T00:00:00Z',
    ...overrides,
  }
}

describe('child store locked albums', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(api.post).mockReset()
  })

  it('locks joined albums without an own key and unlocks reactively', () => {
    const auth = useAuthStore()
    const store = useChildStore()
    store.children = [
      makeChild({ id: 1, role: 'owner' }),
      makeChild({ id: 2, role: 'member' }),
      makeChild({ id: 3, role: 'admin' }),
    ]
    auth.setEncryptionKeys([
      { child_id: 3, child_name: 'C', encrypted_dek: 'x', wrap_type: 'kek', key_version: 1 },
      { child_id: 2, child_name: 'B', encrypted_dek: 'x', wrap_type: 'public', key_version: 1 },
    ])

    expect(store.lockedChildren.map((c) => c.id)).toEqual([2])
    expect(store.isChildLocked(2)).toBe(true)
    expect(store.isChildLocked(1)).toBe(false)
    expect(store.isChildLocked(3)).toBe(false)

    auth.setEncryptionKeys([
      ...auth.encryptionKeys,
      { child_id: 2, child_name: 'B', encrypted_dek: 'y', wrap_type: 'kek', key_version: 1 },
    ])
    expect(store.lockedChildren).toEqual([])
  })

  it('sends an access reminder', async () => {
    vi.mocked(api.post).mockResolvedValue({ status: 202, data: { sent: true } })
    const result = await useChildStore().sendAccessReminder(2)
    expect(api.post).toHaveBeenCalledWith('/children/2/access-reminder')
    expect(result).toEqual({ status: 'sent' })
  })

  it('reports an already sent reminder with retry-after', async () => {
    vi.mocked(api.post).mockRejectedValue({
      response: { status: 429, data: { retry_after: 3600 } },
    })
    expect(await useChildStore().sendAccessReminder(2)).toEqual({
      status: 'already_sent',
      retryAfter: 3600,
    })
  })

  it('reports other failures as an error', async () => {
    vi.mocked(api.post).mockRejectedValue({ response: { status: 500, data: {} } })
    const result = await useChildStore().sendAccessReminder(2)
    expect(result.status).toBe('error')
  })
})
