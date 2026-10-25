import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: {} }),
  useRouter: () => ({ replace: vi.fn<(to: unknown) => void>() }),
}))

vi.mock('@/services/api', () => ({
  api: { get: vi.fn<(url: string) => Promise<unknown>>(), post: vi.fn<() => Promise<unknown>>() },
  default: {},
}))

vi.mock('@/crypto/encrypt', () => ({
  encryptString: vi.fn<() => Promise<unknown>>(),
  decryptBlob: vi.fn<() => Promise<unknown>>(),
}))

import FeedToolbar from '@/components/feed/FeedToolbar.vue'
import { useChildStore } from '@/stores/child'
import { useTagsStore } from '@/stores/tags'
import type { Child } from '@/types/child'
import type { DecryptedChildTag } from '@/types/child'

function seedTag(id: number, name: string): DecryptedChildTag {
  return {
    id,
    child_id: 1,
    name,
    normalized: name.toLowerCase(),
    name_encrypted: 'x',
    iv_name: 'y',
    photo_count: 1,
    created_at: '2026-10-24T10:00:00.000Z',
  } as DecryptedChildTag
}

describe('FeedToolbar', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    useChildStore().children = [{ id: 1, name: 'Mia', avatar_color: null, role: 'owner' } as Child]
  })

  it('hides the Tags chip when the kids have no tags', () => {
    const wrapper = mount(FeedToolbar, { attachTo: document.body })
    expect(wrapper.text()).not.toContain('Tags')
    wrapper.unmount()
  })

  it('shows the Tags chip and lists every tag in its sheet', async () => {
    useTagsStore().byChild.set(1, [seedTag(1, 'beach'), seedTag(2, 'first day at the big school')])
    const wrapper = mount(FeedToolbar, { attachTo: document.body })

    const tagsChip = wrapper.findAll('button').find((b) => b.text() === 'Tags')
    expect(tagsChip).toBeDefined()
    await tagsChip!.trigger('click')

    const items = document.body.querySelectorAll('.month-list__item')
    expect([...items].map((i) => i.textContent?.trim())).toEqual(
      expect.arrayContaining(['beach', 'first day at the big school']),
    )
    wrapper.unmount()
  })
})
