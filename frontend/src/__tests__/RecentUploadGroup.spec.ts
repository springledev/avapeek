import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('@/services/storage', () => ({
  fetchThumbnail: vi.fn<() => Promise<null>>().mockResolvedValue(null),
  cleanupComponent: vi.fn<() => void>(),
  getPEKForPhoto: vi.fn<() => Promise<null>>().mockResolvedValue(null),
}))

import RecentUploadGroup from '@/components/RecentUploadGroup.vue'
import { kidPairFor } from '@/utils/kidColors'

describe('RecentUploadGroup', () => {
  it('normalizes the raw avatar_color to a themed kid pair', () => {
    const rawColor = '#FF6B6B'
    const wrapper = mount(RecentUploadGroup, {
      props: {
        photos: [],
        uploaderName: 'Sam',
        childId: 7,
        childName: 'Robin',
        childColor: rawColor,
        relativeTime: 'Just now',
      },
    })

    const avatar = wrapper.find('.avatar')
    expect(avatar.attributes('data-kid')).toBe(kidPairFor(7, rawColor).name)
    expect(avatar.attributes('style')?.toLowerCase()).not.toContain('255, 107, 107')
    expect(avatar.attributes('style')?.toLowerCase()).not.toContain('#ff6b6b')
  })
})
