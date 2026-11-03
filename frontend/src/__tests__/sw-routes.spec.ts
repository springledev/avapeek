import { describe, it, expect } from 'vitest'
import {
  isNavigationDenied,
  isPrecachedPathIn,
  isShareTargetRequest,
  precacheCacheName,
  shouldServeShell,
  toPrecachePath,
  uniquePrecachePaths,
} from '@/lib/sw-routes'

describe('service worker routes', () => {
  it('lists each precache path once, since Cache.addAll() rejects duplicates', () => {
    const manifest = [
      { url: 'index.html', revision: 'a' },
      { url: 'icons/icon-192.png', revision: 'b' },
      { url: 'assets/app.js', revision: null },
      { url: 'icons/icon-192.png', revision: 'b' },
      { url: '/icons/icon-192.png', revision: 'b' },
    ]

    expect(uniquePrecachePaths(manifest)).toEqual(['/index.html', '/icons/icon-192.png', '/assets/app.js'])
  })

  it('denies shell navigation for /api/, /photos/ and /share-target', () => {
    expect(isNavigationDenied('/api/photos')).toBe(true)
    expect(isNavigationDenied('/photos/abc?X-Amz-Signature=1')).toBe(true)
    expect(isNavigationDenied('/share-target')).toBe(true)
    expect(isNavigationDenied('/feed')).toBe(false)
    expect(isNavigationDenied('/upload')).toBe(false)
    expect(isNavigationDenied('/photographs')).toBe(false)
  })

  it('serves the shell only for GET navigations that are not denied', () => {
    expect(shouldServeShell({ mode: 'navigate', method: 'GET' }, '/feed')).toBe(true)
    expect(shouldServeShell({ mode: 'navigate', method: 'GET' }, '/api/photos')).toBe(false)
    expect(shouldServeShell({ mode: 'navigate', method: 'POST' }, '/feed')).toBe(false)
    expect(shouldServeShell({ mode: 'cors', method: 'GET' }, '/feed')).toBe(false)
  })

  it('recognises only POST /share-target as the share target', () => {
    expect(isShareTargetRequest('POST', '/share-target')).toBe(true)
    expect(isShareTargetRequest('GET', '/share-target')).toBe(false)
    expect(isShareTargetRequest('POST', '/upload')).toBe(false)
  })

  it('only treats manifest paths as precached, never API or storage paths', () => {
    const precached = new Set(['/index.html', '/assets/app-abc.js'].map(toPrecachePath))
    expect(isPrecachedPathIn('/assets/app-abc.js', precached)).toBe(true)
    expect(isPrecachedPathIn('/api/photos', precached)).toBe(false)
    expect(isPrecachedPathIn('/photos/x', precached)).toBe(false)
  })

  it('names the precache after its manifest so a new build gets a new cache', () => {
    const a = precacheCacheName([{ url: 'index.html', revision: '1' }])
    expect(a).toBe(precacheCacheName([{ url: '/index.html', revision: '1' }]))
    expect(a).not.toBe(precacheCacheName([{ url: 'index.html', revision: '2' }]))
    expect(a.startsWith('avapeek-precache-')).toBe(true)
  })
})
