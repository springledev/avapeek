/**
 * Pure Web Push helpers for the service worker (`src/sw.ts`).
 * Free of `self`/DOM globals so they can be unit tested.
 *
 * The push payload is only `{ type, url }`. Notification text is chosen here,
 * never taken from the payload, so nothing user-supplied (and nothing
 * decrypted) can end up in the OS notification history. The payload `url` is
 * only honoured when it matches a strict allowlist of in-app paths.
 */

export type PushType = 'photos' | 'comments' | 'admin_comments' | 'joins' | 'access_requested' | 'access_granted'

/** Default click target: the unseen feed. */
export const PUSH_CLICK_URL = '/recents'

/** In-app paths a push may deep-link to (numeric ids only, no other query or host). */
const ALLOWED_CLICK_URLS: readonly RegExp[] = [
  /^\/recents$/,
  /^\/feed$/,
  /^\/recents\?photo=\d{1,12}$/,
  /^\/settings\/children$/,
  /^\/settings\/children\/\d{1,12}$/,
]

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
  admin_comments: {
    title: 'New comment on a photo',
    body: 'A family member commented on a photo. Tap to open it.',
    tag: 'avapeek-admin-comments',
  },
  joins: {
    title: 'Someone joined your family',
    body: 'Open Avapeek to give them access to the photos.',
    tag: 'avapeek-joins',
  },
  access_requested: {
    title: 'Someone is waiting for access',
    body: "A family member can't see photos yet. Open Avapeek to share access.",
    tag: 'avapeek-access-requested',
  },
  access_granted: {
    title: 'You can see the photos now',
    body: 'An admin shared access with you. Tap to open your feed.',
    tag: 'avapeek-access-granted',
  },
}

function isPushType(value: unknown): value is PushType {
  return (
    value === 'photos' ||
    value === 'comments' ||
    value === 'admin_comments' ||
    value === 'joins' ||
    value === 'access_requested' ||
    value === 'access_granted'
  )
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

/** Returns the payload `url` only if it is an allowlisted in-app path, else null. */
export function parsePushUrl(raw: string | null | undefined): string | null {
  if (!raw) return null
  try {
    const url = (JSON.parse(raw) as { url?: unknown } | null)?.url
    return typeof url === 'string' && ALLOWED_CLICK_URLS.some((re) => re.test(url)) ? url : null
  } catch {
    return null
  }
}

export function notificationForType(type: PushType, url?: string | null): NotificationSpec {
  const copy = COPY[type]
  return {
    title: copy.title,
    options: {
      body: copy.body,
      tag: copy.tag,
      icon: NOTIFICATION_ICON,
      badge: NOTIFICATION_ICON,
      renotify: false,
      data: { url: url ?? PUSH_CLICK_URL },
    },
  }
}

/**
 * Click target. Only an allowlisted in-app path is used; anything else
 * (including external URLs) falls back to `/recents`.
 */
export function resolveClickUrl(url?: string | null): string {
  return typeof url === 'string' && ALLOWED_CLICK_URLS.some((re) => re.test(url))
    ? url
    : PUSH_CLICK_URL
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
  /** Allowlisted url from the notification's data; defaults to `/recents`. */
  url?: string | null
  openWindow: (url: string) => Promise<unknown>
}

/** Focuses an existing client and navigates it to the target, else opens a window. */
export async function routeNotificationClick(deps: ClickDeps): Promise<void> {
  const target = resolveClickUrl(deps.url)
  const targetPath = target.split('?')[0]!
  const client = pickClient(deps.clients, deps.origin, targetPath)
  if (!client) {
    await deps.openWindow(target)
    return
  }
  if (new URL(client.url).pathname + new URL(client.url).search !== target && client.navigate) {
    await client.navigate(target)
  }
  await client.focus()
}
