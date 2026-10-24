import { useAuthStore } from '@/stores/auth'
import { teardownPushForSignOut } from '@/services/push'

/**
 * Signs out and reloads the app on the sign-in page.
 *
 * The full page load is deliberate: stores such as child (unwrapped DEKs),
 * feed and comments hold decrypted data in memory, and a reload is the only
 * way to be sure none of it is left for the next person on this device.
 */
export const PUSH_TEARDOWN_TIMEOUT_MS = 3000

export async function signOut(): Promise<void> {
  // Needs the bearer token, so it must run before the session is cleared.
  // Raced against a timeout so a stalled request can never block logout.
  await Promise.race([
    teardownPushForSignOut(),
    new Promise<void>((resolve) => setTimeout(resolve, PUSH_TEARDOWN_TIMEOUT_MS)),
  ])
  await useAuthStore().logout()
  window.location.assign('/login')
}
