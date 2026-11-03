/// <reference lib="webworker" />
/**
 * Avapeek service worker (vite-plugin-pwa `injectManifest`).
 *
 * Deliberately small and dependency-free:
 * - Precaches static build assets only (injected as `self.__WB_MANIFEST`).
 * - Answers navigations with the precached shell (denylist: /api/, /photos/, /share-target).
 * - NO runtime caching: /api/*, /photos/* and any storage origin go straight to the
 *   network. Responses there are ciphertext or plaintext metadata and presigned
 *   URLs expire, so nothing from them may be stored in Cache Storage.
 * - Receives Web Share Target POSTs and stages the files in IndexedDB.
 * - Shows generic Web Push notifications (text chosen here from the payload
 *   `type`, never from payload content) and deep-links clicks to an allowlisted in-app path (default /recents).
 *
 * Each concern is its own handler function so more events can be added
 * without touching the others. Pure logic lives in `lib/sw-routes.ts` and
 * `lib/push-sw.ts`.
 */
import {
  PRECACHE_PREFIX,
  SHELL_URL,
  isPrecachedPathIn,
  isShareTargetRequest,
  precacheCacheName,
  shouldServeShell,
  toPrecachePath,
  uniquePrecachePaths,
  type PrecacheEntry,
} from './lib/sw-routes'
import { notificationForType, parsePushType, parsePushUrl, routeNotificationClick } from './lib/push-sw'
import { purgeExpiredShares } from './lib/share-inbox'
import { handleShareTarget } from './lib/share-target'

declare const self: ServiceWorkerGlobalScope & { __WB_MANIFEST: PrecacheEntry[] }

const manifest: PrecacheEntry[] = self.__WB_MANIFEST
const CACHE_NAME = precacheCacheName(manifest)
const precachedPaths = new Set(manifest.map((entry) => toPrecachePath(entry.url)))

async function precacheAssets(): Promise<void> {
  const cache = await caches.open(CACHE_NAME)
  await cache.addAll(
    uniquePrecachePaths(manifest).map((path) => new Request(path, { cache: 'reload' })),
  )
}

async function removeOldPrecaches(): Promise<void> {
  const names = await caches.keys()
  await Promise.all(
    names
      .filter((name) => name.startsWith(PRECACHE_PREFIX) && name !== CACHE_NAME)
      .map((name) => caches.delete(name)),
  )
}

async function matchPrecached(path: string): Promise<Response | undefined> {
  const cache = await caches.open(CACHE_NAME)
  return cache.match(path)
}

function onInstall(event: ExtendableEvent): void {
  event.waitUntil(precacheAssets())
}

function onActivate(event: ExtendableEvent): void {
  event.waitUntil(
    Promise.all([
      removeOldPrecaches(),
      purgeExpiredShares().catch(() => 0),
      self.clients.claim(),
    ]),
  )
}

function onFetch(event: FetchEvent): void {
  const { request } = event
  const url = new URL(request.url)
  if (url.origin !== self.location.origin) {
    return
  }

  if (isShareTargetRequest(request.method, url.pathname)) {
    event.respondWith(handleShareTarget(request, self.location.origin))
    return
  }

  if (shouldServeShell(request, url.pathname)) {
    event.respondWith(matchPrecached(SHELL_URL).then((shell) => shell ?? fetch(request)))
    return
  }

  if (request.method === 'GET' && isPrecachedPathIn(url.pathname, precachedPaths)) {
    event.respondWith(matchPrecached(url.pathname).then((hit) => hit ?? fetch(request)))
  }
}

function onMessage(event: ExtendableMessageEvent): void {
  if (event.data?.type === 'SKIP_WAITING') {
    void self.skipWaiting()
  }
}

function onPush(event: PushEvent): void {
  let raw: string | null = null
  try {
    raw = event.data ? event.data.text() : null
  } catch {
    raw = null
  }
  const { title, options } = notificationForType(parsePushType(raw), parsePushUrl(raw))
  event.waitUntil(self.registration.showNotification(title, options))
}

function onNotificationClick(event: NotificationEvent): void {
  event.notification.close()
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windowClients) =>
      routeNotificationClick({
        clients: windowClients as unknown as WindowClient[],
        origin: self.location.origin,
        url: (event.notification.data as { url?: unknown } | null)?.url as string | undefined,
        openWindow: (url) => self.clients.openWindow(url),
      }),
    ),
  )
}

/**
 * The worker never holds the bearer token, so it cannot tell the server about
 * a replaced subscription. It re-subscribes with the same VAPID key and the
 * app re-registers the new subscription at its next launch (see
 * `resyncPushSubscription` in `services/push.ts`).
 */
function onPushSubscriptionChange(event: Event): void {
  const change = event as ExtendableEvent & { oldSubscription?: PushSubscription | null }
  const key = change.oldSubscription?.options?.applicationServerKey
  if (!key) return
  change.waitUntil(
    self.registration.pushManager
      .subscribe({ userVisibleOnly: true, applicationServerKey: key })
      .catch(() => undefined),
  )
}

self.addEventListener('install', onInstall)
self.addEventListener('activate', onActivate)
self.addEventListener('fetch', onFetch)
self.addEventListener('message', onMessage)
self.addEventListener('push', onPush)
self.addEventListener('notificationclick', onNotificationClick)
self.addEventListener('pushsubscriptionchange', onPushSubscriptionChange)
