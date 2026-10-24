/**
 * Pure routing helpers for the service worker (`src/sw.ts`).
 * Kept free of `self`/DOM globals so they can be unit tested.
 *
 * The service worker never caches /api/*, /photos/* or any storage origin:
 * only static build assets are precached.
 */

export const SHARE_TARGET_PATH = '/share-target'
export const SHARE_REDIRECT_PATH = '/upload?shared=1'
export const PRECACHE_PREFIX = 'avapeek-precache-'
export const SHELL_URL = '/index.html'

/** Navigations to these paths must never be answered with the app shell. */
const NAVIGATION_DENYLIST: RegExp[] = [/^\/api\//, /^\/photos\//, /^\/share-target(?:\/|$)/]

export interface PrecacheEntry {
  url: string
  revision?: string | null
}

export function isNavigationDenied(pathname: string): boolean {
  return NAVIGATION_DENYLIST.some((pattern) => pattern.test(pathname))
}

/** True when a navigation request should be answered with the precached shell. */
export function shouldServeShell(
  request: { mode: string; method: string },
  pathname: string,
): boolean {
  return request.mode === 'navigate' && request.method === 'GET' && !isNavigationDenied(pathname)
}

export function isShareTargetRequest(method: string, pathname: string): boolean {
  return method === 'POST' && pathname === SHARE_TARGET_PATH
}

/** Normalises a manifest url ("assets/x.js") into an absolute path ("/assets/x.js"). */
export function toPrecachePath(url: string): string {
  return url.startsWith('/') ? url : `/${url}`
}

/** Only requests whose path is in the precache manifest are served from cache. */
export function isPrecachedPathIn(pathname: string, precachedPaths: ReadonlySet<string>): boolean {
  return precachedPaths.has(pathname)
}

/** Stable cache name for a precache manifest, so each build gets its own cache. */
export function precacheCacheName(entries: PrecacheEntry[]): string {
  const signature = entries
    .map((entry) => `${toPrecachePath(entry.url)}@${entry.revision ?? ''}`)
    .sort()
    .join('|')
  let hash = 5381
  for (let i = 0; i < signature.length; i++) {
    hash = ((hash << 5) + hash + signature.charCodeAt(i)) >>> 0
  }
  return `${PRECACHE_PREFIX}${hash.toString(16)}`
}
