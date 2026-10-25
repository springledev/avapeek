import { Workbox } from 'workbox-window'

export interface PwaRegistration {
  /** Activates the waiting service worker; the page reloads once it takes control. */
  applyUpdate: () => void
}

/**
 * Registers the service worker (production builds only; there is no dev SW).
 * `onNeedRefresh` fires when a new build is installed and waiting.
 */
export function registerServiceWorker(onNeedRefresh: (registration: PwaRegistration) => void): void {
  if (!import.meta.env.PROD || !('serviceWorker' in navigator)) {
    return
  }

  const workbox = new Workbox('/sw.js', { scope: '/' })

  workbox.addEventListener('waiting', () => {
    onNeedRefresh({
      applyUpdate: () => {
        workbox.addEventListener('controlling', () => window.location.reload())
        workbox.messageSkipWaiting()
      },
    })
  })

  workbox.register().catch((error: unknown) => {
    console.warn('Service worker registration failed', error)
  })
}
