import { describe, it, expect, vi, beforeEach } from 'vitest'
import { defineComponent, h, onMounted, ref } from 'vue'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

vi.mock('@/lib/cache/decrypted-cache', () => ({
  getThumbnail: vi.fn<(id: number, load: () => Promise<Blob>) => Promise<string>>(async (_id, load) => {
    await load()
    return 'blob:decrypted'
  }),
  clearDecryptedCache: vi.fn<() => void>(),
  setDecryptedCacheUser: vi.fn<(userId: number | null) => void>(),
}))

vi.mock('@/services/storage', () => ({
  getPEKForPhoto: vi.fn<() => Promise<CryptoKey | null>>(),
  fetchAndDecrypt: vi.fn<() => Promise<ArrayBuffer>>(async () => new ArrayBuffer(1)),
}))

vi.mock('@/services/api', () => ({ api: { get: vi.fn<() => Promise<unknown>>() } }))

import { getPEKForPhoto } from '@/services/storage'
import { useAuthStore } from '@/stores/auth'
import { useLazyDecrypt } from '@/composables/useLazyDecrypt'
import type { Photo } from '@/types/photo'

let triggerIntersect: (el: Element) => void

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(getPEKForPhoto).mockReset()
  vi.spyOn(console, 'warn').mockImplementation(() => undefined)
  vi.stubGlobal(
    'IntersectionObserver',
    class {
      constructor(private cb: (entries: unknown[]) => void) {
        triggerIntersect = (el) => this.cb([{ isIntersecting: true, target: el }])
      }
      observe() {}
      unobserve() {}
      disconnect() {}
    },
  )
})

const photo = {
  id: 5,
  children: [{ id: 1, encrypted_pek: 'a', iv_pek: 'b' }],
  iv_thumbnail: 'iv',
  thumbnail_url: 'https://storage.test/t',
} as unknown as Photo

function mountTile(onUrl: (url: string) => void) {
  const Tile = defineComponent({
    setup() {
      const el = ref<HTMLElement | null>(null)
      const { observePhoto } = useLazyDecrypt()
      onMounted(() => observePhoto(el.value!, photo, onUrl))
      return () => h('div', { ref: el })
    },
  })
  return mount(Tile)
}

describe('useLazyDecrypt', () => {
  it('retries a tile whose album key had not arrived once the keys change', async () => {
    const onUrl = vi.fn<(url: string) => void>()
    vi.mocked(getPEKForPhoto).mockResolvedValueOnce(null)
    const wrapper = mountTile(onUrl)

    triggerIntersect(wrapper.element)
    await flushPromises()
    expect(onUrl).not.toHaveBeenCalled()

    vi.mocked(getPEKForPhoto).mockResolvedValue({} as CryptoKey)
    useAuthStore().setEncryptionKeys([
      { child_id: 1, child_name: 'Ana', encrypted_dek: 'dek', wrap_type: 'kek', key_version: 1 },
    ])
    await flushPromises()

    expect(onUrl).toHaveBeenCalledWith('blob:decrypted')
  })

  it('does not retry on its own when the keys do not change', async () => {
    const onUrl = vi.fn<(url: string) => void>()
    vi.mocked(getPEKForPhoto).mockResolvedValue(null)
    const wrapper = mountTile(onUrl)

    triggerIntersect(wrapper.element)
    await flushPromises()
    await flushPromises()

    expect(getPEKForPhoto).toHaveBeenCalledTimes(1)
  })
})
