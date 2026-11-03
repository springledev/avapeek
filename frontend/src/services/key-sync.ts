import { toast } from 'vue-sonner'
import { api } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { rewrapSharedDEK, unwrapPrivateKey } from '@/crypto/keychain'
import type { EncryptionKey } from '@/types/auth'

let inFlight: Promise<void> | null = null

/**
 * Re-wraps keys another member shared to this user's public key with the
 * user's KEK, so the album opens like any other.
 *
 * This never shares a key with anyone else: that takes an admin's click on
 * Approve (see `usePendingGrants`).
 *
 * Safe to call often: runs one sync at a time and never throws.
 */
export function syncEncryptionKeys(): Promise<void> {
  inFlight ??= runSync().finally(() => {
    inFlight = null
  })
  return inFlight
}

async function runSync(): Promise<void> {
  const authStore = useAuthStore()
  if (!authStore.kek || !authStore.user) return

  try {
    await acceptSharedKeys()
  } catch (err) {
    console.error('Failed to accept shared album keys:', err)
  }
}

async function acceptSharedKeys(): Promise<void> {
  const authStore = useAuthStore()
  const { data } = await api.get<{ data: EncryptionKey[] }>('/encryption-keys')
  const keys = data.data
  const shared = keys.filter((key) => key.wrap_type === 'public_key')
  const wrappedPrivateKey = authStore.user?.encrypted_private_key

  if (shared.length === 0 || !wrappedPrivateKey || !authStore.kek) {
    authStore.setEncryptionKeys(keys)
    return
  }

  const kek = authStore.kek
  const privateKey = await unwrapPrivateKey(wrappedPrivateKey, kek)
  const unlocked: string[] = []

  for (const key of shared) {
    try {
      const wrappedDek = await rewrapSharedDEK(key.encrypted_dek, privateKey, kek)
      await api.post('/encryption-keys', { child_id: key.child_id, wrapped_dek: wrappedDek })
      key.encrypted_dek = wrappedDek
      key.wrap_type = 'kek'
      unlocked.push(key.child_name ?? 'a new album')
    } catch (err) {
      console.error(`Failed to unlock shared key for child ${key.child_id}:`, err)
    }
  }

  authStore.setEncryptionKeys(keys)

  if (unlocked.length > 0) {
    toast.success(`You can now see photos of ${unlocked.join(', ')}.`, {
      action: { label: 'Refresh', onClick: () => window.location.reload() },
    })
  }
}
