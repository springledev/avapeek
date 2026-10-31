import { describe, it, expect, vi } from 'vitest'
import {
  notificationForType,
  parsePushType,
  parsePushUrl,
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

  it('builds text that says where to look for admin comments and joins', () => {
    const joins = notificationForType('joins', '/settings/children/4')
    const adminComments = notificationForType('admin_comments', '/recents?photo=9')

    expect(joins.options.body).toBe('Open Avapeek to give them access to the photos.')
    expect(joins.options.data).toEqual({ url: '/settings/children/4' })
    expect(adminComments.options.body).toContain('commented on a photo')
    expect(adminComments.options.data).toEqual({ url: '/recents?photo=9' })
    expect(parsePushType('{"type":"joins"}')).toBe('joins')
    expect(parsePushType('{"type":"admin_comments"}')).toBe('admin_comments')
  })

  it('only accepts allowlisted in-app urls from the payload', () => {
    expect(parsePushUrl('{"url":"/recents?photo=12"}')).toBe('/recents?photo=12')
    expect(parsePushUrl('{"url":"/settings/children/3"}')).toBe('/settings/children/3')
    expect(parsePushUrl('{"url":"https://evil.example/x"}')).toBeNull()
    expect(parsePushUrl('{"url":"//evil.example"}')).toBeNull()
    expect(parsePushUrl('{"url":"/recents?photo=1&x=2"}')).toBeNull()
    expect(parsePushUrl('{"url":"/settings/security"}')).toBeNull()
    expect(parsePushUrl('nope')).toBeNull()
    expect(resolveClickUrl('https://evil.example')).toBe('/recents')
    expect(resolveClickUrl('/settings/children/3')).toBe('/settings/children/3')
  })

  it('navigates an existing client to the deep link', async () => {
    const client: WindowClientLike = {
      url: 'https://app.example/recents',
      focus: vi.fn<() => Promise<unknown>>().mockResolvedValue(undefined),
      navigate: vi.fn<(url: string) => Promise<unknown>>().mockResolvedValue(undefined),
    }

    await routeNotificationClick({
      clients: [client],
      origin: 'https://app.example',
      url: '/recents?photo=7',
      openWindow: vi.fn<(url: string) => Promise<unknown>>(),
    })

    expect(client.navigate).toHaveBeenCalledWith('/recents?photo=7')
    expect(client.focus).toHaveBeenCalled()
  })

  it('opens a window at the deep link when no client exists', async () => {
    const openWindow = vi.fn<(url: string) => Promise<unknown>>().mockResolvedValue(undefined)

    await routeNotificationClick({
      clients: [],
      origin: 'https://app.example',
      url: '/settings/children/2',
      openWindow,
    })

    expect(openWindow).toHaveBeenCalledWith('/settings/children/2')
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

  it('has copy for access_requested and access_granted that names nobody', () => {
    const requested = notificationForType('access_requested', '/settings/children/4')
    const granted = notificationForType('access_granted', '/feed')

    expect(requested.title).toBe('Someone is waiting for access')
    expect(requested.options.body).toBe(
      "A family member can't see photos yet. Open Avapeek to share access.",
    )
    expect(requested.options.data).toEqual({ url: '/settings/children/4' })
    expect(granted.title).toBe('You can see the photos now')
    expect(granted.options.body).toBe('An admin shared access with you. Tap to open your feed.')
    expect(granted.options.data).toEqual({ url: '/feed' })
    expect(parsePushType('{"type":"access_requested"}')).toBe('access_requested')
    expect(parsePushType('{"type":"access_granted"}')).toBe('access_granted')
  })

  it('allows /feed as a click target but not look-alikes', () => {
    expect(parsePushUrl('{"url":"/feed"}')).toBe('/feed')
    expect(resolveClickUrl('/feed')).toBe('/feed')
    expect(parsePushUrl('{"url":"/feed/evil"}')).toBeNull()
    expect(parsePushUrl('{"url":"//feed"}')).toBeNull()
  })
})
