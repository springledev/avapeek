import { ref, watch } from 'vue'

/** Feed tile density. '2' is the hero default; '3' is a compact option in Settings. */
export type FeedDensity = '2' | '3'

const STORAGE_KEY = 'avapeek-feed-density'

const density = ref<FeedDensity>('2')

function applyDensity(value: FeedDensity) {
  density.value = value
  document.documentElement.dataset.feedDensity = value
}

function setDensity(value: FeedDensity) {
  applyDensity(value)
  localStorage.setItem(STORAGE_KEY, value)
}

function initDensity() {
  const stored = localStorage.getItem(STORAGE_KEY) as FeedDensity | null
  applyDensity(stored === '3' ? '3' : '2')
}

watch(density, (value) => {
  document.documentElement.dataset.feedDensity = value
})

export function useGridDensity() {
  return { density, setDensity, initDensity }
}
