import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

vi.mock('@/services/api', () => ({
  api: {
    get: vi.fn<(url: string) => Promise<unknown>>(),
    post: vi.fn<(url: string, data?: unknown) => Promise<unknown>>(),
    put: vi.fn<(url: string, data?: unknown) => Promise<unknown>>(),
    delete: vi.fn<(url: string, config?: unknown) => Promise<unknown>>(),
  },
}))

import { api } from '@/services/api'
import {
  getPreferences,
  getPushConfig,
  isPushEnabledOnDevice,
  resyncPushSubscription,
  setPreferences,
  subscribe,
  teardownPushForSignOut,
  unsubscribe,
  urlBase64ToUint8Array,
} from '@/services/push'

const mocked = vi.mocked(api)

function fakeSubscription(endpoint = 'https://push.example/abc') {
  return {
    endpoint,
    toJSON: () => ({ endpoint, keys: { p256dh: 'P256', auth: 'AUTH' } }),
    unsubscribe: vi.fn<() => Promise<boolean>>().mockResolvedValue(true),
  }
}

function installBrowser(
  existing: ReturnType<typeof fakeSubscription> | null,
  permission = 'granted',
) {
  const created = fakeSubscription('https://push.example/new')
  const pushManager = {
    getSubscription: vi.fn<() => Promise<unknown>>().mockResolvedValue(existing),
    subscribe: vi.fn<(options: unknown) => Promise<unknown>>().mockResolvedValue(created),
  }
  Object.defineProperty(navigator, 'serviceWorker', {
    configurable: true,
    value: { ready: Promise.resolve({ pushManager }) },
  })
  vi.stubGlobal('PushManager', class {})
  vi.stubGlobal('Notification', {
    permission,
    requestPermission: vi.fn<() => Promise<string>>().mockResolvedValue(permission),
  })
  return { pushManager, created }
}

describe('services/push', () => {
  beforeEach(() => {
    localStorage.clear()
    vi.clearAllMocks()
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    Reflect.deleteProperty(navigator, 'serviceWorker')
  })

  it('decodes a base64url VAPID key', () => {
    expect(Array.from(urlBase64ToUint8Array('AQID'))).toEqual([1, 2, 3])
    expect(Array.from(urlBase64ToUint8Array('-_8'))).toEqual([251, 255])
  })

  it('returns the public key, or null when push is off (404)', async () => {
    mocked.get.mockResolvedValueOnce({ data: { data: { public_key: 'KEY' } } })
    expect(await getPushConfig()).toBe('KEY')

    mocked.get.mockRejectedValueOnce({ response: { status: 404 } })
    expect(await getPushConfig()).toBeNull()
    expect(mocked.get).toHaveBeenCalledWith('/push/config')
  })

  it('subscribes with userVisibleOnly and posts the subscription shape', async () => {
    const { pushManager } = installBrowser(null)

    await subscribe('AQID')

    expect(pushManager.subscribe).toHaveBeenCalledWith({
      userVisibleOnly: true,
      applicationServerKey: new Uint8Array([1, 2, 3]),
    })
    expect(mocked.post).toHaveBeenCalledWith('/push/subscriptions', {
      endpoint: 'https://push.example/new',
      keys: { p256dh: 'P256', auth: 'AUTH' },
    })
    expect(isPushEnabledOnDevice()).toBe(true)
  })

  it('does not subscribe when permission is denied', async () => {
    const { pushManager } = installBrowser(null, 'denied')

    await expect(subscribe('AQID')).rejects.toThrow('permission-denied')

    expect(pushManager.subscribe).not.toHaveBeenCalled()
    expect(mocked.post).not.toHaveBeenCalled()
    expect(isPushEnabledOnDevice()).toBe(false)
  })

  it('unsubscribe deletes server-side by endpoint and in the browser', async () => {
    const existing = fakeSubscription()
    installBrowser(existing)
    localStorage.setItem('avapeek-push-enabled', '1')

    await unsubscribe()

    expect(mocked.delete).toHaveBeenCalledWith('/push/subscriptions', {
      data: { endpoint: 'https://push.example/abc' },
    })
    expect(existing.unsubscribe).toHaveBeenCalled()
    expect(isPushEnabledOnDevice()).toBe(false)
  })

  it('still unsubscribes in the browser when the server DELETE fails', async () => {
    const existing = fakeSubscription()
    installBrowser(existing)
    mocked.delete.mockRejectedValueOnce({ response: { status: 500 } })

    await expect(unsubscribe()).rejects.toBeDefined()

    expect(existing.unsubscribe).toHaveBeenCalled()
  })

  it('treats a 404 on server unsubscribe as already removed', async () => {
    const existing = fakeSubscription()
    installBrowser(existing)
    mocked.delete.mockRejectedValueOnce({ response: { status: 404 } })

    await unsubscribe()

    expect(existing.unsubscribe).toHaveBeenCalled()
  })

  it('resync re-registers only for devices that opted in and never prompts', async () => {
    const existing = fakeSubscription()
    const { pushManager } = installBrowser(existing)
    mocked.get.mockResolvedValue({ data: { data: { public_key: 'AQID' } } })

    await resyncPushSubscription()
    expect(mocked.post).not.toHaveBeenCalled()

    localStorage.setItem('avapeek-push-enabled', '1')
    await resyncPushSubscription()
    expect(mocked.post).toHaveBeenCalledTimes(1)
    expect(pushManager.subscribe).not.toHaveBeenCalled()
  })

  it('resync creates a new subscription when the browser lost it', async () => {
    const { pushManager } = installBrowser(null)
    mocked.get.mockResolvedValue({ data: { data: { public_key: 'AQID' } } })
    localStorage.setItem('avapeek-push-enabled', '1')

    await resyncPushSubscription()

    expect(pushManager.subscribe).toHaveBeenCalled()
    expect(mocked.post).toHaveBeenCalledWith(
      '/push/subscriptions',
      expect.objectContaining({ endpoint: 'https://push.example/new' }),
    )
  })

  it('resync is silent when permission is not granted', async () => {
    installBrowser(null, 'default')
    localStorage.setItem('avapeek-push-enabled', '1')

    await resyncPushSubscription()

    expect(mocked.get).not.toHaveBeenCalled()
  })

  it('sign-out teardown never throws, even when the server call fails', async () => {
    const existing = fakeSubscription()
    installBrowser(existing)
    localStorage.setItem('avapeek-push-enabled', '1')
    mocked.delete.mockRejectedValueOnce(new Error('network'))

    await expect(teardownPushForSignOut()).resolves.toBeUndefined()
    expect(isPushEnabledOnDevice()).toBe(false)
  })

  it('reads and writes per-child preferences', async () => {
    mocked.get.mockResolvedValueOnce({
      data: { data: [{ child_id: 1, notify_photos: true, notify_comments: false }] },
    })
    expect(await getPreferences()).toEqual([
      { child_id: 1, notify_photos: true, notify_comments: false },
    ])

    mocked.put.mockResolvedValueOnce({
      data: { data: { child_id: 1, notify_photos: false, notify_comments: false } },
    })
    await setPreferences(1, { notify_photos: false, notify_comments: false })
    expect(mocked.put).toHaveBeenCalledWith('/push/preferences/1', {
      notify_photos: false,
      notify_comments: false,
    })
  })
})
