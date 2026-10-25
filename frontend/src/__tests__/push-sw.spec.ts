import { describe, it, expect, vi } from 'vitest'
import {
  notificationForType,
  parsePushType,
  pickClient,
  resolveClickUrl,
  routeNotificationClick,
  type WindowClientLike,
} from '@/lib/push-sw'

describe('push service worker helpers', () => {
  it('builds generic text from the type only', () => {
    const photos = notificationForType('photos')
    const comments = notificationForType('comments')

    expect(photos.title).toBe('New photos in Avapeek')
    expect(photos.options.tag).toBe('avapeek-photos')
    expect(comments.title).toBe('New comments in Avapeek')
    expect(comments.options.tag).toBe('avapeek-comments')
    expect(photos.options.renotify).toBe(false)
    expect(photos.options.icon).toBe('/icons/icon-192.png')
    expect(photos.options.data).toEqual({ url: '/recents' })
  })

  it('ignores unknown payload fields and never copies payload text', () => {
    const raw = JSON.stringify({
      type: 'comments',
      url: 'https://evil.example/phish',
      title: 'Alice commented',
      body: 'secret',
      child: 'Mia',
    })

    const spec = notificationForType(parsePushType(raw))

    expect(JSON.stringify(spec)).not.toMatch(/Alice|secret|Mia|evil/)
    expect(spec.options.tag).toBe('avapeek-comments')
  })

  it('falls back to photos for missing, malformed or unknown payloads', () => {
    expect(parsePushType(null)).toBe('photos')
    expect(parsePushType('')).toBe('photos')
    expect(parsePushType('not json')).toBe('photos')
    expect(parsePushType('{"type":"nope"}')).toBe('photos')
    expect(parsePushType('null')).toBe('photos')
  })

  it('always routes clicks to /recents', () => {
    expect(resolveClickUrl()).toBe('/recents')
  })

  it('prefers a client already on /recents, then any same-origin client', () => {
    const clients = [
      { url: 'https://other.example/recents' },
      { url: 'https://app.example/feed' },
      { url: 'https://app.example/recents' },
    ]
    expect(pickClient(clients, 'https://app.example', '/recents')).toBe(clients[2])
    expect(pickClient(clients.slice(0, 2), 'https://app.example', '/recents')).toBe(clients[1])
    expect(pickClient([clients[0]!], 'https://app.example', '/recents')).toBeUndefined()
  })

  it('focuses and navigates an existing client', async () => {
    const client: WindowClientLike = {
      url: 'https://app.example/feed',
      focus: vi.fn<() => Promise<unknown>>().mockResolvedValue(undefined),
      navigate: vi.fn<(url: string) => Promise<unknown>>().mockResolvedValue(undefined),
    }
    const openWindow = vi.fn<(url: string) => Promise<unknown>>()

    await routeNotificationClick({ clients: [client], origin: 'https://app.example', openWindow })

    expect(client.navigate).toHaveBeenCalledWith('/recents')
    expect(client.focus).toHaveBeenCalled()
    expect(openWindow).not.toHaveBeenCalled()
  })

  it('only focuses a client already on /recents', async () => {
    const client: WindowClientLike = {
      url: 'https://app.example/recents',
      focus: vi.fn<() => Promise<unknown>>().mockResolvedValue(undefined),
      navigate: vi.fn<(url: string) => Promise<unknown>>(),
    }

    await routeNotificationClick({
      clients: [client],
      origin: 'https://app.example',
      openWindow: vi.fn<(url: string) => Promise<unknown>>(),
    })

    expect(client.navigate).not.toHaveBeenCalled()
    expect(client.focus).toHaveBeenCalled()
  })

  it('opens a window when no client exists', async () => {
    const openWindow = vi.fn<(url: string) => Promise<unknown>>().mockResolvedValue(undefined)

    await routeNotificationClick({ clients: [], origin: 'https://app.example', openWindow })

    expect(openWindow).toHaveBeenCalledWith('/recents')
  })
})
