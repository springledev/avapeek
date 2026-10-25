import { describe, it, expect, vi, beforeEach } from 'vitest'

vi.mock('@/services/push', () => ({
  getPushConfig: vi.fn<() => Promise<string | null>>(),
  hasSubscription: vi.fn<() => Promise<boolean>>(),
  isIos: vi.fn<() => boolean>(),
  isPushSupported: vi.fn<() => boolean>(),
  isStandalone: vi.fn<() => boolean>(),
  subscribe: vi.fn<(key: string) => Promise<void>>(),
  unsubscribe: vi.fn<() => Promise<void>>(),
}))

import * as service from '@/services/push'
import { usePushNotifications } from '@/composables/usePushNotifications'

const mocked = vi.mocked(service)

function setPermission(value: NotificationPermission) {
  vi.stubGlobal('Notification', { permission: value })
}

describe('usePushNotifications', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mocked.isPushSupported.mockReturnValue(true)
    mocked.isIos.mockReturnValue(false)
    mocked.isStandalone.mockReturnValue(false)
    mocked.getPushConfig.mockResolvedValue('KEY')
    mocked.hasSubscription.mockResolvedValue(false)
    setPermission('default')
  })

  it('reports an unsupported browser without calling the server', async () => {
    mocked.isPushSupported.mockReturnValue(false)
    const push = usePushNotifications()

    await push.init()

    expect(push.supported.value).toBe(false)
    expect(push.available.value).toBe(false)
    expect(mocked.getPushConfig).not.toHaveBeenCalled()
  })

  it('is unavailable when the server has no VAPID keys', async () => {
    mocked.getPushConfig.mockResolvedValue(null)
    const push = usePushNotifications()

    await push.init()

    expect(push.available.value).toBe(false)
    expect(push.subscribed.value).toBe(false)
  })

  it('asks to install first on iOS when not standalone', async () => {
    mocked.isIos.mockReturnValue(true)
    const push = usePushNotifications()
    await push.init()
    expect(push.needsInstall.value).toBe(true)

    mocked.isStandalone.mockReturnValue(true)
    await push.init()
    expect(push.needsInstall.value).toBe(false)
  })

  it('never asks for permission during init', async () => {
    const push = usePushNotifications()

    await push.init()

    expect(mocked.subscribe).not.toHaveBeenCalled()
    expect(push.available.value).toBe(true)
  })

  it('enables on toggle and marks the device subscribed', async () => {
    mocked.subscribe.mockResolvedValue(undefined)
    setPermission('granted')
    const push = usePushNotifications()
    await push.init()

    await push.toggle()

    expect(mocked.subscribe).toHaveBeenCalledWith('KEY')
    expect(push.subscribed.value).toBe(true)
    expect(push.error.value).toBeNull()
  })

  it('explains a denied permission', async () => {
    mocked.subscribe.mockRejectedValue(new Error('permission-denied'))
    const push = usePushNotifications()
    await push.init()
    setPermission('denied')

    await push.enable()

    expect(push.subscribed.value).toBe(false)
    expect(push.blocked.value).toBe(true)
    expect(push.error.value).toMatch(/blocked/i)
    expect(push.busy.value).toBe(false)
  })

  it('shows a generic error when subscribing fails', async () => {
    mocked.subscribe.mockRejectedValue(new Error('boom'))
    const push = usePushNotifications()
    await push.init()

    await push.enable()

    expect(push.subscribed.value).toBe(false)
    expect(push.error.value).toMatch(/try again/i)
  })

  it('disables an existing subscription on toggle', async () => {
    mocked.hasSubscription.mockResolvedValue(true)
    mocked.unsubscribe.mockResolvedValue(undefined)
    const push = usePushNotifications()
    await push.init()
    expect(push.subscribed.value).toBe(true)

    await push.toggle()

    expect(mocked.unsubscribe).toHaveBeenCalled()
    expect(push.subscribed.value).toBe(false)
  })
})
