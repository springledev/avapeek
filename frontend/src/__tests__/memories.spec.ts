import { describe, it, expect, vi, beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent } from 'vue'
import type { Photo } from '@/types/photo'

const getMock = vi.hoisted(() => vi.fn<(url: string, config?: unknown) => Promise<unknown>>())

vi.mock('@/services/api', () => ({ api: { get: getMock } }))

import { useMemories, localDateString } from '@/composables/useMemories'
import { useMemoriesStore } from '@/stores/memories'
import OnThisDayCard from '@/components/feed/OnThisDayCard.vue'

function photo(id: number, takenAt: string | null): Photo {
  return {
    id,
    uuid: `u${id}`,
    uploaded_by: 1,
    original_path: '',
    thumbnail_path: '',
    iv_original: '',
    iv_thumbnail: '',
    original_mime: 'image/jpeg',
    size_bytes: 1,
    width: 1,
    height: 1,
    exif_encrypted: null,
    iv_exif: null,
    taken_at: takenAt,
    created_at: '2025-01-01T00:00:00Z',
    tags: [],
    children: [],
  }
}

function respond(photos: Photo[]) {
  getMock.mockResolvedValue({ data: { data: photos, meta: { total: photos.length } } })
}

describe('useMemories', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    getMock.mockReset()
  })

  it('formats the local date', () => {
    expect(localDateString(new Date(2025, 0, 5, 23, 59))).toBe('2025-01-05')
  })

  it('sends the local date and time zone', async () => {
    respond([])
    await useMemories().load()
    const [url, config] = getMock.mock.calls[0]!
    expect(url).toBe('/memories')
    expect(config).toEqual({
      params: {
        date: localDateString(),
        tz: Intl.DateTimeFormat().resolvedOptions().timeZone,
      },
    })
  })

  it('is hidden when empty', async () => {
    respond([])
    const m = useMemories()
    await m.load()
    expect(m.isVisible.value).toBe(false)
  })

  it('is hidden on error', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    getMock.mockRejectedValue(new Error('boom'))
    const m = useMemories()
    await m.load()
    expect(m.isVisible.value).toBe(false)
    expect(useMemoriesStore().hasError).toBe(true)
  })

  it('groups by year, newest first', async () => {
    respond([
      photo(1, '2022-06-15T12:00:00'),
      photo(2, '2024-06-15T12:00:00'),
      photo(3, '2022-06-15T14:00:00'),
      photo(4, null),
    ])
    const m = useMemories()
    await m.load()
    expect(m.isVisible.value).toBe(true)
    expect(m.groups.value.map((g) => g.year)).toEqual([2024, 2022])
    expect(m.groups.value[1]!.photos.map((p) => p.id)).toEqual([1, 3])
  })
})

describe('OnThisDayCard', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    getMock.mockReset()
  })

  const PhotoTileStub = defineComponent({ props: ['photo'], template: '<button class="tile" />' })

  it('renders nothing for empty data', async () => {
    respond([])
    const wrapper = mount(OnThisDayCard, { global: { stubs: { PhotoTile: PhotoTileStub } } })
    await flushPromises()
    expect(wrapper.find('section').exists()).toBe(false)
  })

  it('renders a tile per photo grouped by year', async () => {
    respond([photo(1, '2024-06-15T12:00:00'), photo(2, '2023-06-15T12:00:00')])
    const wrapper = mount(OnThisDayCard, { global: { stubs: { PhotoTile: PhotoTileStub } } })
    await flushPromises()
    expect(wrapper.findAll('.tile')).toHaveLength(2)
    expect(wrapper.text()).toContain('On this day')
  })

  it('keeps every group in one scrolling strip of fixed-size, touch-sized tiles', async () => {
    respond([photo(1, '2024-06-15T12:00:00'), photo(2, '2023-06-15T12:00:00')])
    const wrapper = mount(OnThisDayCard, { global: { stubs: { PhotoTile: PhotoTileStub } } })
    await flushPromises()
    expect(wrapper.findAll('.otd__strip')).toHaveLength(1)
    expect(wrapper.findAll('.otd__strip .otd__group')).toHaveLength(2)
    expect(wrapper.findAll('.otd__year').map((y) => y.text())).toEqual([
      expect.stringContaining('2024'),
      expect.stringContaining('2023'),
    ])
  })
})
