import { ref, onMounted, onBeforeUnmount } from 'vue'
import { toast } from 'vue-sonner'

const isOnline = ref(true)

export function useNetwork() {
  function handleOnline() {
    isOnline.value = true
    toast.success('Connection restored.')
  }

  function handleOffline() {
    isOnline.value = false
    toast.warning("You're offline. Changes won't be saved.")
  }

  function initNetwork() {
    isOnline.value = navigator.onLine
    window.addEventListener('online', handleOnline)
    window.addEventListener('offline', handleOffline)
  }

  function cleanup() {
    window.removeEventListener('online', handleOnline)
    window.removeEventListener('offline', handleOffline)
  }

  return { isOnline, initNetwork, cleanup }
}

export function useNetworkDetection() {
  const { isOnline, initNetwork, cleanup } = useNetwork()

  onMounted(initNetwork)
  onBeforeUnmount(cleanup)

  return { isOnline }
}
