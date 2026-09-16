import { computed, ref } from 'vue'

/**
 * Motion may fill latency, never add it (amendment 2).
 *
 * call start() when an image request begins, and resolve() when the asset
 * arrives. resolve() returns the animation class the element should use:
 *   'photo-develop' — the request actually took >=150ms; the Polaroid
 *                     develop animation merely occupies that wait.
 *   'photo-fade'    — arrival was fast/cached; short fade only.
 *   '' (none)       — reduced-motion active; swap instantly.
 *
 * Reduced-motion is checked live so the class outcome flips with the OS setting.
 */

export type PhotoMotion = 'photo-develop' | 'photo-fade' | ''

const DEVELOP_THRESHOLD_MS = 150

const reducedMotionQuery =
  typeof window !== 'undefined' && typeof window.matchMedia === 'function'
    ? window.matchMedia('(prefers-reduced-motion: reduce)')
    : null

const reducedMotion = ref(reducedMotionQuery?.matches === true)

reducedMotionQuery?.addEventListener?.('change', (event: MediaQueryListEvent) => {
  reducedMotion.value = event.matches
})

export function useMotionPolicy() {
  const reduced = computed(() => reducedMotion.value)

  function resolve(startNanos?: number): PhotoMotion {
    if (reduced.value) return ''
    const start = startNanos ?? performance.now()
    const elapsed = performance.now() - start
    return elapsed >= DEVELOP_THRESHOLD_MS ? 'photo-develop' : 'photo-fade'
  }

  return { reducedMotion: reduced, resolve }
}
