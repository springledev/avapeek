import { ref, onMounted, onBeforeUnmount } from 'vue'
import { getThumbnail } from '@/lib/cache/decrypted-cache'
import { getPEKForPhoto, fetchAndDecrypt, fetchEncryptedBlob } from '@/services/storage'
import { api } from '@/services/api'
import type { Photo } from '@/types/photo'

export function useLazyDecrypt() {
  const observer = ref<IntersectionObserver | null>(null)
  const pendingDecrypts = new Map<Element, AbortController>()

  function observePhoto(
    el: HTMLElement,
    photo: Photo,
    onUrl: (url: string) => void,
  ) {
    if (!observer.value) return
    ;(el as any)._lazyPhoto = { photo, onUrl }
    observer.value.observe(el)
  }

  function unobserve(el: HTMLElement) {
    observer.value?.unobserve(el)
    const ctrl = pendingDecrypts.get(el)
    if (ctrl) {
      ctrl.abort()
      pendingDecrypts.delete(el)
    }
  }

  onMounted(() => {
    observer.value = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          if (entry.isIntersecting) {
            const { photo, onUrl } = (entry.target as any)._lazyPhoto || {}
            if (photo && onUrl) {
              decryptAndSet(entry.target as HTMLElement, photo, onUrl)
              observer.value?.unobserve(entry.target)
            }
          }
        }
      },
      {
        rootMargin: '300px 0px',
        threshold: 0,
      },
    )
  })

  onBeforeUnmount(() => {
    observer.value?.disconnect()
    for (const ctrl of pendingDecrypts.values()) ctrl.abort()
    pendingDecrypts.clear()
  })

  async function decryptAndSet(
    el: HTMLElement,
    photo: Photo,
    onUrl: (url: string) => void,
  ) {
    const abortController = new AbortController()
    pendingDecrypts.set(el, abortController)

    const url = await getThumbnail(photo.id, async () => {
      const pek = await getPEKForPhoto(photo.children)
      if (!pek) throw new Error('No PEK available')
      let presignedUrl = photo.thumbnail_url
      if (!presignedUrl) {
        const response = await api.get(`/photos/${photo.id}/thumbnail`)
        presignedUrl = response.data.data.presigned_url as string
      }
      const decrypted = await fetchAndDecrypt(presignedUrl, pek, photo.iv_thumbnail)
      return new Blob([decrypted], { type: 'image/webp' })
    })

    if (!abortController.signal.aborted) {
      pendingDecrypts.delete(el)
      onUrl(url)
    }
  }

  return { observePhoto, unobserve }
}
