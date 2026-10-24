import { computed, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useChildStore } from '@/stores/child'
import { useFeedStore } from '@/stores/feed'

export type SetupStep = 1 | 2 | 3

const DISMISS_KEY_PREFIX = 'avapeek.setup.dismissed.'

function dismissKey(userId: number | undefined): string {
  return `${DISMISS_KEY_PREFIX}${userId ?? 'anon'}`
}

function readDismissed(userId: number | undefined): boolean {
  try {
    return localStorage.getItem(dismissKey(userId)) === '1'
  } catch {
    return false
  }
}

function writeDismissed(userId: number | undefined): void {
  try {
    localStorage.setItem(dismissKey(userId), '1')
  } catch {
    // Storage unavailable: the card just comes back next visit.
  }
}

/**
 * First-run setup progress, derived from data (kids and photos). The only
 * stored state is a per-device "dismissed" flag for the feed card.
 */
export function useSetupState() {
  const authStore = useAuthStore()
  const childStore = useChildStore()
  const feedStore = useFeedStore()

  const hasKid = computed(() => childStore.children.length > 0)
  const hasPhotos = computed(
    () =>
      childStore.children.some((c) => (c.photo_count ?? 0) > 0) || feedStore.photos.length > 0,
  )
  const isComplete = computed(() => hasKid.value && hasPhotos.value)
  const firstIncompleteStep = computed<SetupStep>(() => {
    if (!hasKid.value) return 1
    if (!hasPhotos.value) return 2
    return 3
  })

  // In-memory mirror so dismissal works even when storage throws.
  const dismissedInSession = ref(false)
  const isDismissed = computed(
    () => dismissedInSession.value || readDismissed(authStore.currentUser?.id),
  )

  function dismiss(): void {
    dismissedInSession.value = true
    writeDismissed(authStore.currentUser?.id)
  }

  return { hasKid, hasPhotos, isComplete, firstIncompleteStep, isDismissed, dismiss }
}
