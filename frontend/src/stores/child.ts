import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { api } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { generateDEK, wrapDEK, unwrapDEK, importPublicKey, wrapDEKWithPublicKey } from '@/crypto/keychain'
import type { Child, ChildDetail, InviteResponse } from '@/types/child'

export const useChildStore = defineStore('child', () => {
  const children = ref<Child[]>([])
  const currentChildId = ref<number | null>(null)
  const currentChildDetail = ref<ChildDetail | null>(null)
  const deks = ref<Map<number, CryptoKey>>(new Map())
  const isLoading = ref(false)
  const error = ref<string | null>(null)

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

  async function ensureDEK(childId: number): Promise<CryptoKey> {
    const existing = deks.value.get(childId)
    if (existing) return existing

    const authStore = useAuthStore()
    if (!authStore.kek) {
      throw new Error('Session expired. Please log in again.')
    }

    const ek = authStore.encryptionKeys.find((k) => k.child_id === childId)
    if (!ek) {
      throw new Error('No encryption key found for this child.')
    }

    const dek = await unwrapDEK(ek.encrypted_dek, authStore.kek)
    deks.value.set(childId, dek)
    return dek
  }

  async function fetchChildren(): Promise<void> {
    isLoading.value = true
    error.value = null

    try {
      const response = await api.get<{ data: Child[] }>('/children')
      children.value = response.data.data
    } catch (err: any) {
      console.error('Failed to fetch children:', err)
      error.value = err.response?.data?.message || 'Failed to load children.'
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
    } catch (err: any) {
      console.error('Failed to fetch child detail:', err)
      error.value = err.response?.data?.message || 'Failed to load child details.'
      throw err
    } finally {
      isLoading.value = false
    }
  }

  async function createChild(name: string, birthDate: string | null): Promise<Child> {
    const authStore = useAuthStore()
    if (!authStore.kek) {
      throw new Error('KEK not available. Please log in again.')
    }

    isLoading.value = true
    error.value = null

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

      const dek = await generateDEK()
      const wrappedDek = await wrapDEK(dek, authStore.kek)

      await api.post('/encryption-keys', {
        child_id: child.id,
        wrapped_dek: wrappedDek,
      })

      authStore.encryptionKeys.push({
        child_id: child.id,
        child_name: child.name,
        encrypted_dek: wrappedDek,
        key_version: 1,
      })
      localStorage.setItem('encryption_keys', JSON.stringify(authStore.encryptionKeys))

      deks.value.set(child.id, dek)
      children.value.push(child)

      return child
    } catch (err: any) {
      console.error('Failed to create child:', err)
      error.value = err.response?.data?.message || 'Failed to create child profile.'
      throw err
    } finally {
      isLoading.value = false
    }
  }

  async function createInvite(childId: number, role: string, relationship: string | null): Promise<InviteResponse> {
    isLoading.value = true
    error.value = null

    try {
      const response = await api.post<{ data: InviteResponse }>(`/children/${childId}/invite`, {
        role,
        relationship,
      })

      return response.data.data
    } catch (err: any) {
      console.error('Failed to create invite:', err)
      error.value = err.response?.data?.message || 'Failed to create invite.'
      throw err
    } finally {
      isLoading.value = false
    }
  }

  async function grantAccess(childId: number, inviteeUserId: number): Promise<void> {
    const authStore = useAuthStore()
    if (!authStore.kek) {
      throw new Error('KEK not available. Please log in again.')
    }

    const dek = deks.value.get(childId)
    if (!dek) {
      throw new Error('DEK not available for this child.')
    }

    isLoading.value = true
    error.value = null

    try {
      const publicKeyResponse = await api.get<{ data: { public_key: string } }>(`/users/${inviteeUserId}/public-key`)
      const publicKeyJwk = publicKeyResponse.data.data.public_key

      const publicKey = await importPublicKey(publicKeyJwk)
      const wrappedDek = await wrapDEKWithPublicKey(dek, publicKey)

      await api.post('/encryption-keys/wrap-for-invitee', {
        child_id: childId,
        user_id: inviteeUserId,
        wrapped_dek: wrappedDek,
      })
    } catch (err: any) {
      console.error('Failed to grant access:', err)
      error.value = err.response?.data?.message || 'Failed to grant encrypted access.'
      throw err
    } finally {
      isLoading.value = false
    }
  }

  function setCurrentChild(childId: number | null): void {
    currentChildId.value = childId
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
    currentChild,
    hasChildren,
    getDEK,
    setDEK,
    ensureDEK,
    fetchChildren,
    fetchChildDetail,
    createChild,
    createInvite,
    grantAccess,
    setCurrentChild,
    clearError,
  }
})
