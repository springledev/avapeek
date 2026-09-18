import { ref, onMounted, onBeforeUnmount } from 'vue'
import { getThumbnail } from '@/lib/cache/decrypted-cache'
import { getPEKForPhoto, fetchAndDecrypt } from '@/services/storage'
import { api } from '@/services/api'
import type { Photo } from '@/types/photo'

interface LazyPhotoTarget {
  photo: Photo
  onUrl: (url: string) => void
}

export function useLazyDecrypt() {
  const observer = ref<IntersectionObserver | null>(null)
  const pendingDecrypts = new Map<Element, AbortController>()
  const lazyTargets = new WeakMap<HTMLElement, LazyPhotoTarget>()

  function observePhoto(
    el: HTMLElement,
    photo: Photo,
    onUrl: (url: string) => void,
  ) {
    if (!observer.value) return
    lazyTargets.set(el, { photo, onUrl })
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
            const target = entry.target as HTMLElement
            const { photo, onUrl } = lazyTargets.get(target) || {}
            if (photo && onUrl) {
              decryptAndSet(target, photo, onUrl)
              lazyTargets.delete(target)
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
