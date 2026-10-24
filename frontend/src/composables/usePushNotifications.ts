import { computed, ref } from 'vue'
import {
  getPushConfig,
  hasSubscription,
  isIos,
  isPushSupported,
  isStandalone,
  subscribe,
  unsubscribe,
} from '@/services/push'

type PermissionState = NotificationPermission | 'unsupported'

const available = ref<boolean | null>(null)
const supported = ref(false)
const permission = ref<PermissionState>('default')
const subscribed = ref(false)
const needsInstall = ref(false)
const busy = ref(false)
const error = ref<string | null>(null)
let publicKey: string | null = null

function readPermission(): PermissionState {
  return typeof Notification === 'undefined' ? 'unsupported' : Notification.permission
}

/**
 * Per-device push state. Module-level so the Settings row and the
 * notifications page share it (same style as `useGridDensity`).
 */
export function usePushNotifications() {
  /** Loads server config and browser state. Never prompts for permission. */
  async function init(): Promise<void> {
    supported.value = isPushSupported()
    permission.value = readPermission()
    needsInstall.value = isIos() && !isStandalone()

    if (!supported.value) {
      available.value = false
      return
    }

    publicKey = await getPushConfig()
    available.value = publicKey !== null
    subscribed.value = available.value && (await hasSubscription())
  }

  /** Called from the toggle tap only. */
  async function enable(): Promise<void> {
    if (busy.value || !publicKey) return
    busy.value = true
    error.value = null
    try {
      await subscribe(publicKey)
      subscribed.value = true
    } catch (err) {
      subscribed.value = false
      error.value =
        err instanceof Error && err.message === 'permission-denied'
          ? 'Notifications are blocked. Allow them in your browser settings to turn this on.'
          : 'Could not turn on notifications. Please try again.'
    } finally {
      permission.value = readPermission()
      busy.value = false
    }
  }

  async function disable(): Promise<void> {
    if (busy.value) return
    busy.value = true
    error.value = null
    try {
      await unsubscribe()
      subscribed.value = false
    } catch {
      error.value = 'Could not turn off notifications. Please try again.'
    } finally {
      busy.value = false
    }
  }

  async function toggle(): Promise<void> {
    if (subscribed.value) await disable()
    else await enable()
  }

  const blocked = computed(() => permission.value === 'denied')

  return {
    available,
    supported,
    permission,
    subscribed,
    needsInstall,
    blocked,
    busy,
    error,
    init,
    enable,
    disable,
    toggle,
  }
}
