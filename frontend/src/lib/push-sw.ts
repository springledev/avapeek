/**
 * Pure Web Push helpers for the service worker (`src/sw.ts`).
 * Free of `self`/DOM globals so they can be unit tested.
 *
 * The push payload is only `{ type, url }`. Notification text is chosen here,
 * never taken from the payload, so nothing user-supplied (and nothing
 * decrypted) can end up in the OS notification history.
 */

export type PushType = 'photos' | 'comments'

/** The only place a notification may send the user: the unseen feed. */
export const PUSH_CLICK_URL = '/recents'

export const NOTIFICATION_ICON = '/icons/icon-192.png'

export interface NotificationSpec {
  title: string
  options: {
    body: string
    tag: string
    icon: string
    badge: string
    renotify: boolean
    data: { url: string }
  }
}

const COPY: Record<PushType, { title: string; body: string; tag: string }> = {
  photos: {
    title: 'New photos in Avapeek',
    body: 'Someone added photos.',
    tag: 'avapeek-photos',
  },
  comments: {
    title: 'New comments in Avapeek',
    body: 'Someone commented on a photo.',
    tag: 'avapeek-comments',
  },
}

function isPushType(value: unknown): value is PushType {
  return value === 'photos' || value === 'comments'
}

/**
 * Reads the push payload and returns its `type`. Unknown fields are ignored and
 * anything malformed falls back to `photos` so a notification is still shown
 * (browsers require a visible notification for every push).
 */
export function parsePushType(raw: string | null | undefined): PushType {
  if (!raw) return 'photos'
  try {
    const parsed: unknown = JSON.parse(raw)
    const type = (parsed as { type?: unknown } | null)?.type
    return isPushType(type) ? type : 'photos'
  } catch {
    return 'photos'
  }
}

export function notificationForType(type: PushType): NotificationSpec {
  const copy = COPY[type]
  return {
    title: copy.title,
    options: {
      body: copy.body,
      tag: copy.tag,
      icon: NOTIFICATION_ICON,
      badge: NOTIFICATION_ICON,
      renotify: false,
      data: { url: PUSH_CLICK_URL },
    },
  }
}

/**
 * Click target. Always `/recents`: the payload's `url` is deliberately not
 * trusted, so a push can never steer the worker to another path.
 */
export function resolveClickUrl(): string {
  return PUSH_CLICK_URL
}

export interface WindowClientLike {
  url: string
  focus: () => Promise<unknown>
  navigate?: (url: string) => Promise<unknown>
}

/** Prefer a client already on the target path, otherwise any same-origin client. */
export function pickClient<T extends { url: string }>(
  clients: readonly T[],
  origin: string,
  target: string,
): T | undefined {
  const sameOrigin = clients.filter((client) => client.url.startsWith(origin))
  return sameOrigin.find((client) => new URL(client.url).pathname === target) ?? sameOrigin[0]
}

export interface ClickDeps {
  clients: readonly WindowClientLike[]
  origin: string
  openWindow: (url: string) => Promise<unknown>
}

/** Focuses an existing client and navigates it to the target, else opens a window. */
export async function routeNotificationClick(deps: ClickDeps): Promise<void> {
  const target = resolveClickUrl()
  const client = pickClient(deps.clients, deps.origin, target)
  if (!client) {
    await deps.openWindow(target)
    return
  }
  if (new URL(client.url).pathname !== target && client.navigate) {
    await client.navigate(target)
  }
  await client.focus()
}
