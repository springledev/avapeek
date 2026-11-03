import { toast } from 'vue-sonner'
import { api } from '@/services/api'
import { fetchPendingGrants } from '@/services/pending-grants'
import { useAuthStore } from '@/stores/auth'
import { useChildStore } from '@/stores/child'
import { rewrapSharedDEK, unwrapPrivateKey } from '@/crypto/keychain'
import type { EncryptionKey } from '@/types/auth'

/** Outcome of one sync: grants shared with members, and grants that failed. */
export interface KeySyncResult {
  granted: number
  failed: number
}

let inFlight: Promise<KeySyncResult> | null = null

/**
 * Keeps album keys flowing between family members, with no manual step:
 *
 * 1. Keys another member shared to this user's public key are re-wrapped
 *    with the user's KEK, so the album opens like any other.
 * 2. If this user administers an album, members who joined but have no key
 *    yet get it shared to their public key.
 *
 * Safe to call often: runs one sync at a time and never throws.
 */
export function syncEncryptionKeys(): Promise<KeySyncResult> {
  inFlight ??= runSync().finally(() => {
    inFlight = null
  })
  return inFlight
}

async function runSync(): Promise<KeySyncResult> {
  const authStore = useAuthStore()
  const result: KeySyncResult = { granted: 0, failed: 0 }
  if (!authStore.kek || !authStore.user) return result

  try {
    await acceptSharedKeys()
  } catch (err) {
    console.error('Failed to accept shared album keys:', err)
  }

  try {
    return await shareKeysWithNewMembers()
  } catch (err) {
    console.error('Failed to share album keys:', err)
    return { granted: 0, failed: 1 }
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

async function shareKeysWithNewMembers(): Promise<KeySyncResult> {
  const result: KeySyncResult = { granted: 0, failed: 0 }
  const grants = await fetchPendingGrants()
  if (grants.length === 0) return result

  const childStore = useChildStore()
  for (const grant of grants) {
    try {
      await childStore.grantAccess(grant.child_id, grant.user_id, grant.public_key)
      result.granted++
    } catch (err) {
      result.failed++
      console.error(`Failed to share key for child ${grant.child_id}:`, err)
    }
  }
  return result
}
