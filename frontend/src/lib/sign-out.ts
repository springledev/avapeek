import { useAuthStore } from '@/stores/auth'

/**
 * Signs out and reloads the app on the sign-in page.
 *
 * The full page load is deliberate: stores such as child (unwrapped DEKs),
 * feed and comments hold decrypted data in memory, and a reload is the only
 * way to be sure none of it is left for the next person on this device.
 */
export async function signOut(): Promise<void> {
  await useAuthStore().logout()
  window.location.assign('/login')
}
