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
 *
 * Each concern is its own handler function so more events (push,
 * notificationclick, pushsubscriptionchange) can be added without touching
 * the others. Pure logic lives in `lib/sw-routes.ts`.
 */
import {
  PRECACHE_PREFIX,
  SHELL_URL,
  isPrecachedPathIn,
  isShareTargetRequest,
  precacheCacheName,
  shouldServeShell,
  toPrecachePath,
  type PrecacheEntry,
} from './lib/sw-routes'
import { purgeExpiredShares } from './lib/share-inbox'
import { handleShareTarget } from './lib/share-target'

declare const self: ServiceWorkerGlobalScope & { __WB_MANIFEST: PrecacheEntry[] }

const manifest: PrecacheEntry[] = self.__WB_MANIFEST
const CACHE_NAME = precacheCacheName(manifest)
const precachedPaths = new Set(manifest.map((entry) => toPrecachePath(entry.url)))

async function precacheAssets(): Promise<void> {
  const cache = await caches.open(CACHE_NAME)
  await cache.addAll(
    manifest.map((entry) => new Request(toPrecachePath(entry.url), { cache: 'reload' })),
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

self.addEventListener('install', onInstall)
self.addEventListener('activate', onActivate)
self.addEventListener('fetch', onFetch)
self.addEventListener('message', onMessage)
