import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '@/services/api'
import { encryptString, decryptBlob } from '@/crypto/encrypt'
import { base64ToArrayBuffer } from '@/lib/util'
import { useAuthStore } from '@/stores/auth'
import { MSG } from '@/lib/messages'
import type { CommentListResponse, DecryptedComment, PhotoComment } from '@/types/photo'

export const MAX_COMMENT_LENGTH = 500
const DECRYPT_CONCURRENCY = 4

let tempIdCounter = -1

/**
 * Maps items through an async function keeping at most `limit` calls
 * in flight at any moment (small local promise pool, no deps).
 */
async function mapWithConcurrency<T, R>(
  items: T[],
  limit: number,
  mapper: (item: T) => Promise<R>,
): Promise<R[]> {
  const results: R[] = []
  let cursor = 0

  async function worker(): Promise<void> {
    while (cursor < items.length) {
      const index = cursor++
      results[index] = await mapper(items[index]!)
    }
  }

  const workers = Array.from({ length: Math.min(limit, items.length) }, () => worker())
  await Promise.all(workers)
  return results
}

/**
 * Decrypts one comment ciphertext into plaintext.
 * Uses the real string-capable helpers from @/crypto/encrypt:
 * decryptBlob() takes an ArrayBuffer + hex IV, so the base64 body is
 * converted first and the result decoded back to text.
 */
async function decryptComment(comment: PhotoComment, pek: CryptoKey): Promise<DecryptedComment> {
  const decrypted = await decryptBlob(base64ToArrayBuffer(comment.body_encrypted), pek, comment.iv_body)
  return {
    id: comment.id,
    user_id: comment.user_id,
    author_name: comment.author_name,
    created_at: comment.created_at,
    text: new TextDecoder().decode(decrypted),
  }
}

export const useCommentsStore = defineStore('comments', () => {
  const comments = ref<Map<number, DecryptedComment[]>>(new Map())
  const pendingCount = ref<Map<number, number>>(new Map())
  const isLoading = ref(false)
  const error = ref<string | null>(null)

  function commentsFor(photoId: number): DecryptedComment[] {
    return comments.value.get(photoId) ?? []
  }

  function getCommentCount(photoId: number): number {
    return commentsFor(photoId).length
  }

  function isPending(photoId: number): boolean {
    return (pendingCount.value.get(photoId) ?? 0) > 0
  }

  function addLocal(photoId: number, comment: DecryptedComment): void {
    const list = comments.value.get(photoId)
    if (list) {
      list.push(comment)
    } else {
      comments.value.set(photoId, [comment])
    }
  }

  function removeLocal(photoId: number, commentId: number): void {
    const list = comments.value.get(photoId)
    if (!list) return
    comments.value.set(
      photoId,
      list.filter((c) => c.id !== commentId),
    )
  }

  async function fetchComments(photoId: number, pek: CryptoKey): Promise<void> {
    isLoading.value = true
    error.value = null

    try {
      const response = await api.get<CommentListResponse>(`/photos/${photoId}/comments`)
      const decrypted = await mapWithConcurrency(
        response.data.data,
        DECRYPT_CONCURRENCY,
        async (comment) => {
          try {
            return await decryptComment(comment, pek)
          } catch {
            console.error(`Failed to decrypt comment ${comment.id}`)
            return null
          }
        },
      )
      const serverComments = decrypted
        .filter((c): c is DecryptedComment => c !== null)
        .sort((a, b) => a.created_at.localeCompare(b.created_at))

      // Merge instead of clobber: an in-flight optimistic comment (negative
      // temp id) posted after the fetch started must survive the set below.
      const inFlightPending = (comments.value.get(photoId) ?? []).filter((c) => c.id < 0)
      const serverIds = new Set(serverComments.map((c) => c.id))
      const keptPending = inFlightPending.filter((c) => !serverIds.has(c.id))

      comments.value.set(photoId, [...serverComments, ...keptPending])
    } catch (err) {
      console.error('Failed to fetch comments:', err)
      error.value = MSG.commentsLoadFailed
    } finally {
      isLoading.value = false
    }
  }

  async function postComment(
    photoId: number,
    text: string,
    pek: CryptoKey,
  ): Promise<DecryptedComment | null> {
    const trimmed = text.trim()
    if (!trimmed) return null
    if (trimmed.length > MAX_COMMENT_LENGTH) {
      error.value = MSG.commentTooLong
      return null
    }

    error.value = null
    const authStore = useAuthStore()
    const tempId = tempIdCounter--
    const optimistic: DecryptedComment = {
      id: tempId,
      user_id: authStore.user?.id ?? -1,
      author_name: authStore.user?.display_name ?? 'You',
      text: trimmed,
      created_at: new Date().toISOString(),
    }

    addLocal(photoId, optimistic)
    pendingCount.value.set(photoId, (pendingCount.value.get(photoId) ?? 0) + 1)

    try {
      const { encrypted, iv } = await encryptString(trimmed, pek)
      const response = await api.post<{ data: PhotoComment }>(`/photos/${photoId}/comments`, {
        body_encrypted: encrypted,
        iv_body: iv,
      })

      const serverComment = response.data.data
      let reconciled: DecryptedComment
      try {
        reconciled = await decryptComment(serverComment, pek)
      } catch {
        reconciled = { ...optimistic, id: serverComment.id, created_at: serverComment.created_at }
      }

      const list = comments.value.get(photoId) ?? []
      comments.value.set(photoId, list.map((c) => (c.id === tempId ? reconciled : c)))
      return reconciled
    } catch (err) {
      console.error('Failed to post comment:', err)
      removeLocal(photoId, tempId)
      error.value = MSG.commentPostFailed
      return null
    } finally {
      pendingCount.value.set(photoId, Math.max(0, (pendingCount.value.get(photoId) ?? 1) - 1))
    }
  }

  async function deleteComment(photoId: number, commentId: number): Promise<boolean> {
    error.value = null

    try {
      await api.delete(`/photos/${photoId}/comments/${commentId}`)
      removeLocal(photoId, commentId)
      return true
    } catch (err) {
      console.error('Failed to delete comment:', err)
      error.value = MSG.commentDeleteFailed
      return false
    }
  }

  function clearError(): void {
    error.value = null
  }

  return {
    comments,
    pendingCount,
    isLoading,
    error,
    commentsFor,
    getCommentCount,
    isPending,
    fetchComments,
    postComment,
    deleteComment,
    clearError,
  }
})
