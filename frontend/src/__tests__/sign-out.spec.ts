import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

const logout = vi.fn<() => Promise<void>>().mockResolvedValue(undefined)

vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ logout }) }))
vi.mock('@/services/push', () => ({
  teardownPushForSignOut: vi.fn<() => Promise<void>>(),
  setPushEnabledOnDevice: vi.fn<(enabled: boolean) => void>(),
}))

import { setPushEnabledOnDevice, teardownPushForSignOut } from '@/services/push'
import { PUSH_TEARDOWN_TIMEOUT_MS, signOut } from '@/lib/sign-out'

describe('signOut', () => {
  const assign = vi.fn<(url: string) => void>()

  beforeEach(() => {
    vi.useFakeTimers()
    vi.clearAllMocks()
    vi.stubGlobal('location', { assign })
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  it('logs out after push teardown completes', async () => {
    vi.mocked(teardownPushForSignOut).mockResolvedValue(undefined)

    await signOut()

    expect(logout).toHaveBeenCalled()
    expect(assign).toHaveBeenCalledWith('/login')
  })

  it('still logs out when push teardown hangs, after the timeout', async () => {
    vi.mocked(teardownPushForSignOut).mockReturnValue(new Promise<void>(() => {}))

    const pending = signOut()
    await vi.advanceTimersByTimeAsync(PUSH_TEARDOWN_TIMEOUT_MS - 1)
    expect(logout).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(1)
    await pending

    expect(logout).toHaveBeenCalled()
    expect(assign).toHaveBeenCalledWith('/login')
  })

  it('clears the device opt-in flag even when teardown hangs', async () => {
    vi.mocked(teardownPushForSignOut).mockReturnValue(new Promise<void>(() => {}))

    const pending = signOut()
    await vi.advanceTimersByTimeAsync(PUSH_TEARDOWN_TIMEOUT_MS)
    await pending

    expect(setPushEnabledOnDevice).toHaveBeenCalledWith(false)
  })

  it('clears the timeout timer when teardown finishes first', async () => {
    vi.mocked(teardownPushForSignOut).mockResolvedValue(undefined)

    await signOut()

    expect(vi.getTimerCount()).toBe(0)
  })
})
