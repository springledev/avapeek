import { api } from '@/services/api'

/**
 * Web Push client. No crypto is involved here: the push payload is a generic
 * `{ type, url }` and its transport encryption (RFC 8291) is handled by the
 * browser and the server library, independent of the E2E key hierarchy.
 */

export interface ChildPushPreference {
  child_id: number
  notify_photos: boolean
  notify_comments: boolean
}

const ENABLED_KEY = 'avapeek-push-enabled'

export function urlBase64ToUint8Array(value: string): Uint8Array<ArrayBuffer> {
  const padding = '='.repeat((4 - (value.length % 4)) % 4)
  const base64 = (value + padding).replace(/-/g, '+').replace(/_/g, '/')
  const raw = atob(base64)
  const bytes = new Uint8Array(new ArrayBuffer(raw.length))
  for (let i = 0; i < raw.length; i++) {
    bytes[i] = raw.charCodeAt(i)
  }
  return bytes
}

export function isPushSupported(): boolean {
  return (
    typeof navigator !== 'undefined' &&
    'serviceWorker' in navigator &&
    typeof window !== 'undefined' &&
    'PushManager' in window &&
    'Notification' in window
  )
}

export function isIos(): boolean {
  if (typeof navigator === 'undefined') return false
  const ua = navigator.userAgent
  return (
    /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
  )
}

export function isStandalone(): boolean {
  if (typeof window === 'undefined') return false
  const iosStandalone = (navigator as Navigator & { standalone?: boolean }).standalone === true
  return iosStandalone || window.matchMedia?.('(display-mode: standalone)').matches === true
}

/** Per-device opt-in marker; set only from the settings toggle, never on page load. */
export function isPushEnabledOnDevice(): boolean {
  try {
    return localStorage.getItem(ENABLED_KEY) === '1'
  } catch {
    return false
  }
}

export function setPushEnabledOnDevice(enabled: boolean): void {
  try {
    if (enabled) localStorage.setItem(ENABLED_KEY, '1')
    else localStorage.removeItem(ENABLED_KEY)
  } catch {
    /* storage unavailable: the toggle still works for this session */
  }
}

/** Returns the VAPID public key, or null when push is not configured on the server. */
export async function getPushConfig(): Promise<string | null> {
  try {
    const { data } = await api.get<{ data: { public_key: string } }>('/push/config')
    return data.data.public_key || null
  } catch {
    return null
  }
}

async function registerOnServer(subscription: PushSubscription): Promise<void> {
  const json = subscription.toJSON()
  await api.post('/push/subscriptions', {
    endpoint: json.endpoint,
    keys: { p256dh: json.keys?.p256dh, auth: json.keys?.auth },
  })
}

async function currentSubscription(): Promise<PushSubscription | null> {
  const registration = await navigator.serviceWorker.ready
  return registration.pushManager.getSubscription()
}

/**
 * Asks for permission (call only from a user gesture), subscribes this device
 * and registers the subscription. Throws `Error('permission-denied')` when the
 * user declines.
 */
export async function subscribe(publicKey: string): Promise<void> {
  const permission = await Notification.requestPermission()
  if (permission !== 'granted') {
    throw new Error('permission-denied')
  }

  const registration = await navigator.serviceWorker.ready
  const subscription =
    (await registration.pushManager.getSubscription()) ??
    (await registration.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: urlBase64ToUint8Array(publicKey),
    }))

  await registerOnServer(subscription)
  setPushEnabledOnDevice(true)
}

/** Removes this device's subscription from the server and the browser. */
export async function unsubscribe(): Promise<void> {
  const subscription = await currentSubscription()
  setPushEnabledOnDevice(false)
  if (!subscription) return

  try {
    await api.delete('/push/subscriptions', { data: { endpoint: subscription.endpoint } })
  } catch (err) {
    const status = (err as { response?: { status?: number } }).response?.status
    if (status !== 404) throw err
  } finally {
    // Always drop the browser subscription, even when the server call failed.
    await subscription.unsubscribe().catch(() => false)
  }
}

/** True when this browser already holds a push subscription. */
export async function hasSubscription(): Promise<boolean> {
  if (!isPushSupported()) return false
  try {
    return (await currentSubscription()) !== null
  } catch {
    return false
  }
}

/**
 * Re-registers this device after launch when the user opted in earlier. Covers
 * `pushsubscriptionchange` (the service worker cannot authenticate) and logins
 * on a browser whose subscription was re-created. Silent: never prompts.
 */
export async function resyncPushSubscription(): Promise<void> {
  if (!isPushSupported() || !isPushEnabledOnDevice() || Notification.permission !== 'granted') {
    return
  }

  const publicKey = await getPushConfig()
  if (!publicKey) return

  const registration = await navigator.serviceWorker.ready
  const subscription =
    (await registration.pushManager.getSubscription()) ??
    (await registration.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: urlBase64ToUint8Array(publicKey),
    }))
  await registerOnServer(subscription)
}

/** Best-effort cleanup before logging out so the next user on this browser is not notified for this account. */
export async function teardownPushForSignOut(): Promise<void> {
  try {
    if (!isPushSupported() || !isPushEnabledOnDevice()) return
    await unsubscribe()
  } catch {
    setPushEnabledOnDevice(false)
  }
}

export async function getPreferences(): Promise<ChildPushPreference[]> {
  const { data } = await api.get<{ data: ChildPushPreference[] }>('/push/preferences')
  return data.data
}

export async function setPreferences(
  childId: number,
  preferences: { notify_photos: boolean; notify_comments: boolean },
): Promise<ChildPushPreference> {
  const { data } = await api.put<{ data: ChildPushPreference }>(
    `/push/preferences/${childId}`,
    preferences,
  )
  return data.data
}
