import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import router from './router'
import { useAuthStore } from './stores/auth'
import './assets/main.css'
import { toast } from 'vue-sonner'
import { registerServiceWorker } from './lib/pwa-register'

const app = createApp(App)
const pinia = createPinia()

app.use(pinia)
app.use(router)

app.directive('click-outside', {
  mounted(el, binding) {
    el.__clickOutsideHandler = (event: Event) => {
      if (!(el === event.target || el.contains(event.target as Node))) {
        binding.value(event)
      }
    }
    document.addEventListener('click', el.__clickOutsideHandler)
  },
  unmounted(el) {
    document.removeEventListener('click', el.__clickOutsideHandler)
  },
})

useAuthStore().initializeAuth()

app.mount('#app')

registerServiceWorker(({ applyUpdate }) => {
  toast('Update available', {
    description: 'A new version of Avapeek is ready.',
    duration: Infinity,
    action: { label: 'Reload', onClick: applyUpdate },
    actionButtonStyle: { minHeight: '48px', minWidth: '48px', padding: '0 16px' },
  })
})
