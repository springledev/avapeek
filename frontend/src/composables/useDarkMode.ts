import { ref, watch } from 'vue'

const STORAGE_KEY = 'avapeek-theme'

const isDark = ref(false)

function applyTheme(dark: boolean) {
  if (dark) {
    document.documentElement.classList.add('dark')
  } else {
    document.documentElement.classList.remove('dark')
  }
  isDark.value = dark
}

function toggle() {
  applyTheme(!isDark.value)
  localStorage.setItem(STORAGE_KEY, isDark.value ? 'dark' : 'light')
}

function initDarkMode() {
  const stored = localStorage.getItem(STORAGE_KEY)
  if (stored === 'dark') {
    applyTheme(true)
    return
  }
  if (stored === 'light') {
    applyTheme(false)
    return
  }
  const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches
  applyTheme(prefersDark)
}

export function useDarkMode() {
  return { isDark, toggle, initDarkMode }
}
