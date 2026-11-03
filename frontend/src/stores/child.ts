import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { api } from '@/services/api'
import { apiErrorMessage, safeErrorSummary } from '@/lib/util'
import { useAuthStore } from '@/stores/auth'
import { useFeedStore } from '@/stores/feed'
import { useRecentsStore } from '@/stores/recents'
import { invalidatePendingGrants } from '@/services/pending-grants'
import { clearDecryptedCache } from '@/lib/cache/decrypted-cache'
import {
  generateDEK,
  wrapDEK,
  unwrapDEK,
  importPublicKey,
  rewrapDEKForPublicKey,
} from '@/crypto/keychain'
import type { Child, ChildDetail, ChildMember, InviteResponse } from '@/types/child'
import type { EncryptionKey } from '@/types/auth'

export type AccessReminderResult =
  | { status: 'sent' }
  | { status: 'already_sent'; retryAfter: number | null }
  | { status: 'error'; message: string }

export const useChildStore = defineStore('child', () => {
  const children = ref<Child[]>([])
  const currentChildId = ref<number | null>(null)
  const currentChildDetail = ref<ChildDetail | null>(null)
  const deks = ref<Map<number, CryptoKey>>(new Map())
  const isLoading = ref(false)
  const error = ref<string | null>(null)
  const childrenLoaded = ref(false)
  /** A child created on the server whose encryption key POST failed. */
  const keyPendingChild = ref<Child | null>(null)

  const currentChild = computed(() => {
    if (!currentChildId.value) return null
    return children.value.find((c) => c.id === currentChildId.value) ?? null
  })

  const hasChildren = computed(() => children.value.length > 0)

  function getDEK(childId: number): CryptoKey | undefined {
    return deks.value.get(childId)
  }

  function setDEK(childId: number, dek: CryptoKey): void {
    deks.value.set(childId, dek)
  }

  /**
   * The child's DEK wrapped with the user's KEK. Keys still wrapped with the
   * user's public key can't be opened until key sync re-wraps them.
   */
  function findOwnKey(childId: number): EncryptionKey | undefined {
    return useAuthStore().encryptionKeys.find(
      (k) => k.child_id === childId && (k.wrap_type ?? 'kek') === 'kek',
    )
  }

  function hasOwnKey(childId: number): boolean {
    return findOwnKey(childId) !== undefined
  }

  /**
   * Children the user can actually upload to: those with a KEK-wrapped key.
   * Keyless children (key setup failed or abandoned) are excluded.
   */
  const uploadableChildren = computed(() =>
    children.value.filter((c) => hasOwnKey(c.id) && canUploadTo(c.id)),
  )

  /**
   * Albums the user joined but cannot open yet: not the owner, and no KEK-wrapped key.
   * An album admin has to share the key. Same definition as the key-wait poll in App.vue.
   */
  const lockedChildren = computed(() =>
    children.value.filter((c) => c.role !== 'owner' && !hasOwnKey(c.id)),
  )

  function isChildLocked(childId: number): boolean {
    return lockedChildren.value.some((c) => c.id === childId)
  }

  /** Whether none of the photo's albums can be opened yet (all of them are locked). */
  function isPhotoLocked(photo: { children: { id: number }[] }): boolean {
    return photo.children.length > 0 && photo.children.every((c) => isChildLocked(c.id))
  }

  /** Asks the album's admins to share the key. Never throws. */
  async function sendAccessReminder(childId: number): Promise<AccessReminderResult> {
    try {
      await api.post(`/children/${childId}/access-reminder`)
      return { status: 'sent' }
    } catch (err) {
      const response = (
        err as { response?: { status?: number; data?: { retry_after?: number; message?: string } } }
      ).response
      if (response?.status === 429) {
        return { status: 'already_sent', retryAfter: response.data?.retry_after ?? null }
      }
      return { status: 'error', message: apiErrorMessage(err, 'Could not send the reminder.') }
    }
  }

  /** Whether the user may upload to and manage tags in this child (owners and admins always). */
  function canUploadTo(childId: number): boolean {
    const child = children.value.find((c) => c.id === childId)
    if (!child) return false
    return child.role === 'owner' || child.role === 'admin' || child.can_upload !== false
  }

  /** Whether the user may upload to (and so add, share or manage) at least one child. */
  const canUploadAnywhere = computed(() => children.value.some((c) => canUploadTo(c.id)))

  /** Whether the user may manage tags in at least one of their children. */
  const canManageTags = computed(() => children.value.some((c) => canUploadTo(c.id)))

  async function ensureDEK(childId: number): Promise<CryptoKey> {
    const existing = deks.value.get(childId)
    if (existing) return existing

    const authStore = useAuthStore()
    if (!authStore.kek) {
      throw new Error('Session expired. Please log in again.')
    }

    const ek = findOwnKey(childId)
    if (!ek) {
      throw new Error('No encryption key found for this child.')
    }

    const dek = await unwrapDEK(ek.encrypted_dek, authStore.kek)
    deks.value.set(childId, dek)
    return dek
  }

  async function tryGetChildDEK(childId: number): Promise<CryptoKey | null> {
    const existing = deks.value.get(childId)
    if (existing) return existing

    const authStore = useAuthStore()
    if (!authStore.kek) return null

    const ek = findOwnKey(childId)
    if (!ek) return null

    try {
      const dek = await unwrapDEK(ek.encrypted_dek, authStore.kek)
      deks.value.set(childId, dek)
      return dek
    } catch {
      return null
    }
  }

  async function fetchChildren(): Promise<void> {
    isLoading.value = true
    error.value = null

    try {
      const response = await api.get<{ data: Child[] }>('/children')
      children.value = response.data.data
      childrenLoaded.value = true
    } catch (err) {
      console.error('Failed to fetch children:', err)
      error.value = apiErrorMessage(err, 'Failed to load children.')
      throw err
    } finally {
      isLoading.value = false
    }
  }

  async function fetchChildDetail(childId: number): Promise<ChildDetail> {
    isLoading.value = true
    error.value = null

    try {
      const response = await api.get<{ data: ChildDetail }>(`/children/${childId}`)
      currentChildDetail.value = response.data.data
      return response.data.data
    } catch (err) {
      console.error('Failed to fetch child detail:', err)
      error.value = apiErrorMessage(err, 'Failed to load child details.')
      throw err
    } finally {
      isLoading.value = false
    }
  }

  /**
   * Creates and registers the DEK for a child that already exists on the server.
   * Kept separate so a failed key POST can be retried without creating a second child.
   */
  async function createKeyForChild(child: Child): Promise<void> {
    const authStore = useAuthStore()
    if (!authStore.kek) {
      throw new Error('KEK not available. Please log in again.')
    }

    const dek = await generateDEK()
    const wrappedDek = await wrapDEK(dek, authStore.kek)

    await api.post('/encryption-keys', {
      child_id: child.id,
      wrapped_dek: wrappedDek,
    })

    authStore.setEncryptionKeys([
      ...authStore.encryptionKeys,
      {
        child_id: child.id,
        child_name: child.name,
        encrypted_dek: wrappedDek,
        wrap_type: 'kek',
        key_version: 1,
      },
    ])

    deks.value.set(child.id, dek)
  }

  async function createChild(name: string, birthDate: string | null): Promise<Child> {
    const authStore = useAuthStore()
    if (!authStore.kek) {
      throw new Error('KEK not available. Please log in again.')
    }

    isLoading.value = true
    error.value = null
    keyPendingChild.value = null

    try {
      const response = await api.post<{ data: Child }>('/children', {
        name,
        birth_date: birthDate,
      })

      const child: Child = {
        ...response.data.data,
        role: response.data.data.role || 'owner',
        relationship: response.data.data.relationship || 'parent',
      }

      children.value.push(child)

      try {
        await createKeyForChild(child)
      } catch (keyErr) {
        // The child exists server-side without a key; remember it so the caller can
        // retry only the key step instead of creating a duplicate child.
        keyPendingChild.value = child
        console.error('Failed to create encryption key for child:', keyErr)
        error.value = apiErrorMessage(
          keyErr,
          'The profile was created but its encryption key could not be saved. Please retry.',
        )
        throw keyErr
      }

      return child
    } catch (err) {
      if (!keyPendingChild.value) {
        console.error('Failed to create child:', err)
        error.value = apiErrorMessage(err, 'Failed to create child profile.')
      }
      throw err
    } finally {
      isLoading.value = false
    }
  }

  /**
   * Re-runs only the key creation for a child whose key POST failed.
   */
  async function retryKeySetup(): Promise<Child> {
    const child = keyPendingChild.value
    if (!child) {
      throw new Error('No child is waiting for key setup.')
    }

    isLoading.value = true
    error.value = null

    try {
      await createKeyForChild(child)
      keyPendingChild.value = null
      return child
    } catch (err) {
      console.error('Failed to create encryption key for child:', err)
      error.value = apiErrorMessage(
        err,
        'The profile was created but its encryption key could not be saved. Please retry.',
      )
      throw err
    } finally {
      isLoading.value = false
    }
  }

  /**
   * Finishes key setup for an existing child that has no key (e.g. after a reload).
   * Only the owner may create the album key; no child is created.
   */
  async function finishKeySetup(childId: number): Promise<Child> {
    const child = children.value.find((c) => c.id === childId)
    if (!child) throw new Error('Child not found.')
    if (child.role !== 'owner') throw new Error('Only the owner can set up this key.')

    isLoading.value = true
    error.value = null

    try {
      await createKeyForChild(child)
      if (keyPendingChild.value?.id === childId) keyPendingChild.value = null
      return child
    } catch (err) {
      console.error('Failed to create encryption key for child:', err)
      error.value = apiErrorMessage(err, 'Failed to finish setting up this kid. Please retry.')
      throw err
    } finally {
      isLoading.value = false
    }
  }

  async function updateChild(
    childId: number,
    payload: { name: string; birthDate: string | null },
  ): Promise<Child> {
    isLoading.value = true
    error.value = null

    try {
      const response = await api.put<{ data: Child }>(`/children/${childId}`, {
        name: payload.name,
        birth_date: payload.birthDate,
      })

      const idx = children.value.findIndex((c) => c.id === childId)
      const existing = idx >= 0 ? children.value[idx] : undefined
      const updated: Child = existing ? { ...existing, ...response.data.data } : response.data.data
      if (idx >= 0) children.value[idx] = updated

      return updated
    } catch (err) {
      console.error('Failed to update child:', err)
      error.value = apiErrorMessage(err, 'Failed to update child profile.')
      throw err
    } finally {
      isLoading.value = false
    }
  }

  async function deleteChild(childId: number): Promise<void> {
    isLoading.value = true
    error.value = null

    try {
      await api.delete(`/children/${childId}`)

      const authStore = useAuthStore()
      authStore.setEncryptionKeys(authStore.encryptionKeys.filter((k) => k.child_id !== childId))
      deks.value.delete(childId)
      children.value = children.value.filter((c) => c.id !== childId)
      if (currentChildId.value === childId) currentChildId.value = null
      if (currentChildDetail.value?.id === childId) currentChildDetail.value = null
      if (keyPendingChild.value?.id === childId) keyPendingChild.value = null

      // Drop the deleted child's photos from the visible lists right away.
      const feedStore = useFeedStore()
      const recentsStore = useRecentsStore()
      feedStore.filters.childIds = feedStore.filters.childIds.filter((id) => id !== childId)
      feedStore.fetchPhotos().catch(() => {})
      recentsStore.fetchRecents().catch(() => {})
      recentsStore.fetchUnseenCount().catch(() => {})
    } catch (err) {
      console.error('Failed to delete child:', err)
      error.value = apiErrorMessage(err, 'Failed to delete child.')
      throw err
    } finally {
      isLoading.value = false
    }
  }

  async function createInvite(
    childId: number,
    role: string,
    relationship: string | null,
    canUpload = true,
  ): Promise<InviteResponse> {
    isLoading.value = true
    error.value = null

    try {
      const response = await api.post<{ data: InviteResponse }>(`/children/${childId}/invite`, {
        role,
        relationship,
        can_upload: canUpload,
      })

      return response.data.data
    } catch (err) {
      console.error('Failed to create invite:', err)
      error.value = apiErrorMessage(err, 'Failed to create invite.')
      throw err
    } finally {
      isLoading.value = false
    }
  }

  /**
   * Shares a child's DEK with a member by wrapping it with their public key.
   * They re-wrap it with their own KEK the next time they open the app.
   */
  async function grantAccess(
    childId: number,
    inviteeUserId: number,
    publicKeyJwk: string,
  ): Promise<void> {
    const authStore = useAuthStore()
    if (!authStore.kek) {
      throw new Error('KEK not available. Please log in again.')
    }

    const ek = findOwnKey(childId)
    if (!ek) {
      throw new Error('No encryption key found for this child.')
    }

    const publicKey = await importPublicKey(publicKeyJwk)
    const wrappedDek = await rewrapDEKForPublicKey(ek.encrypted_dek, authStore.kek, publicKey)

    await api.post('/encryption-keys/wrap-for-invitee', {
      child_id: childId,
      user_id: inviteeUserId,
      wrapped_dek: wrappedDek,
    })
    invalidatePendingGrants()
  }

  /**
   * Owner only: set a member's role. Plain API call, no key material involved.
   */
  async function setMemberRole(
    childId: number,
    userId: number,
    role: 'admin' | 'member',
  ): Promise<Pick<ChildMember, 'id' | 'display_name' | 'role' | 'relationship'>> {
    try {
      const response = await api.patch<{
        data: Pick<ChildMember, 'id' | 'display_name' | 'role' | 'relationship'>
      }>(`/children/${childId}/members/${userId}`, { role })
      return response.data.data
    } catch (err) {
      console.error('Failed to change member role:', err)
      error.value = apiErrorMessage(err, 'Failed to change role.')
      throw err
    }
  }

  /**
   * Owner or admin: allow or stop a plain member from uploading and managing tags.
   */
  async function setMemberUpload(
    childId: number,
    userId: number,
    canUpload: boolean,
  ): Promise<Pick<ChildMember, 'id' | 'can_upload'>> {
    try {
      const response = await api.patch<{ data: Pick<ChildMember, 'id' | 'can_upload'> }>(
        `/children/${childId}/members/${userId}/permissions`,
        { can_upload: canUpload },
      )
      return response.data.data
    } catch (err) {
      console.error('Failed to change upload permission:', err)
      error.value = apiErrorMessage(err, 'Failed to change permission.')
      throw err
    }
  }

  /**
   * Owner only: hand ownership to another member. The caller becomes an admin.
   * Needs the account password, like other sensitive changes.
   */
  async function transferOwnership(
    childId: number,
    userId: number,
    currentPassword: string,
  ): Promise<void> {
    try {
      await useAuthStore().withPasswordProof(currentPassword, (proof) =>
        api.post(`/children/${childId}/transfer-ownership`, { user_id: userId, ...proof }),
      )
      const idx = children.value.findIndex((c) => c.id === childId)
      const existing = idx >= 0 ? children.value[idx] : undefined
      if (idx >= 0 && existing) children.value[idx] = { ...existing, role: 'admin' }
    } catch (err) {
      console.error('Failed to transfer ownership:', safeErrorSummary(err))
      error.value = apiErrorMessage(err, 'Failed to transfer ownership.')
      throw err
    }
  }

  /**
   * Leave a child. The server drops this user's wrapped keys; the browser drops
   * its in-memory keys and decrypted cache. The DEK itself is not rotated.
   */
  async function leaveChild(childId: number): Promise<void> {
    try {
      await api.post(`/children/${childId}/leave`)
    } catch (err) {
      console.error('Failed to leave child:', err)
      error.value = apiErrorMessage(err, 'Failed to leave.')
      throw err
    }

    const authStore = useAuthStore()
    authStore.setEncryptionKeys(authStore.encryptionKeys.filter((k) => k.child_id !== childId))
    clearDEKCache()
    clearDecryptedCache()
    children.value = children.value.filter((c) => c.id !== childId)
    if (currentChildId.value === childId) currentChildId.value = null
    if (currentChildDetail.value?.id === childId) currentChildDetail.value = null
    if (keyPendingChild.value?.id === childId) keyPendingChild.value = null

    const feedStore = useFeedStore()
    const recentsStore = useRecentsStore()
    feedStore.filters.childIds = feedStore.filters.childIds.filter((id) => id !== childId)
    feedStore.fetchPhotos().catch(() => {})
    recentsStore.fetchRecents().catch(() => {})
    recentsStore.fetchUnseenCount().catch(() => {})
  }

  function setCurrentChild(childId: number | null): void {
    currentChildId.value = childId
  }

  function clearDEKCache(): void {
    deks.value.clear()
  }

  function clearError(): void {
    error.value = null
  }

  return {
    children,
    currentChildId,
    currentChildDetail,
    deks,
    isLoading,
    error,
    keyPendingChild,
    childrenLoaded,
    currentChild,
    hasChildren,
    getDEK,
    setDEK,
    ensureDEK,
    tryGetChildDEK,
    fetchChildren,
    fetchChildDetail,
    createChild,
    retryKeySetup,
    finishKeySetup,
    hasOwnKey,
    lockedChildren,
    isChildLocked,
    isPhotoLocked,
    sendAccessReminder,
    uploadableChildren,
    canUploadTo,
    canManageTags,
    canUploadAnywhere,
    setMemberUpload,
    updateChild,
    deleteChild,
    createInvite,
    grantAccess,
    setMemberRole,
    transferOwnership,
    leaveChild,
    setCurrentChild,
    clearDEKCache,
    clearError,
  }
})
