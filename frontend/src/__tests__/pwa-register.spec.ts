import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

type Listener = () => void
const listeners = new Map<string, Listener>()
const register = vi.fn<() => Promise<unknown>>(async () => undefined)
const messageSkipWaiting = vi.fn<() => void>()
const ctor = vi.fn<(url: string, options?: unknown) => void>()

vi.mock('workbox-window', () => ({
  Workbox: class {
    constructor(url: string, options?: unknown) {
      ctor(url, options)
    }
    addEventListener(name: string, fn: Listener) {
      listeners.set(name, fn)
    }
    register = register
    messageSkipWaiting = messageSkipWaiting
  },
}))

import { registerServiceWorker } from '@/lib/pwa-register'

describe('registerServiceWorker', () => {
  beforeEach(() => {
    listeners.clear()
    vi.clearAllMocks()
    vi.stubGlobal('navigator', { serviceWorker: {} })
  })

  afterEach(() => {
    vi.unstubAllEnvs()
    vi.unstubAllGlobals()
  })

  it('does nothing outside production builds', () => {
    vi.stubEnv('PROD', false)
    registerServiceWorker(vi.fn<() => void>())
    expect(register).not.toHaveBeenCalled()
  })

  it('does nothing without service worker support', () => {
    vi.stubEnv('PROD', true)
    vi.stubGlobal('navigator', {})
    registerServiceWorker(vi.fn<() => void>())
    expect(register).not.toHaveBeenCalled()
  })

  it('registers /sw.js, reports a waiting worker, skips waiting and reloads on control', () => {
    vi.stubEnv('PROD', true)
    const reload = vi.fn<() => void>()
    vi.stubGlobal('location', { reload })
    const onNeedRefresh = vi.fn<(registration: { applyUpdate: () => void }) => void>()

    registerServiceWorker(onNeedRefresh)

    expect(ctor).toHaveBeenCalledWith('/sw.js', { scope: '/' })
    expect(register).toHaveBeenCalled()

    listeners.get('waiting')!()
    expect(onNeedRefresh).toHaveBeenCalledTimes(1)

    onNeedRefresh.mock.calls[0]![0].applyUpdate()
    expect(messageSkipWaiting).toHaveBeenCalled()
    expect(reload).not.toHaveBeenCalled()

    listeners.get('controlling')!()
    expect(reload).toHaveBeenCalled()
  })
})
