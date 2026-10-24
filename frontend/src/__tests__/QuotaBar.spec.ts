import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

vi.mock('@/services/api', () => ({
  api: {
    get: vi.fn<(url: string) => Promise<unknown>>(),
  },
}))

import { api } from '@/services/api'
import QuotaBar from '@/components/QuotaBar.vue'

function mockQuota(data: { used_bytes: number; limit_bytes: number | null; percentage: number | null }) {
  vi.mocked(api.get).mockResolvedValue({ data: { data } })
}

describe('QuotaBar', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('shows used / limit, free space and the bar for numeric limits', async () => {
    mockQuota({ used_bytes: 1024 * 1024 * 512, limit_bytes: 1024 * 1024 * 1024, percentage: 50 })
    const wrapper = mount(QuotaBar)
    await flushPromises()

    expect(wrapper.text()).toContain('512.0 MB / 1.0 GB')
    expect(wrapper.text()).toContain('free')
    expect(wrapper.find('.h-1\\.5').exists()).toBe(true)
    expect(wrapper.find('[data-testid="quota-limit-unknown"]').exists()).toBe(false)
  })

  it('renders a graceful unknown-limit state when limit_bytes is null', async () => {
    mockQuota({ used_bytes: 1024 * 1024 * 3, limit_bytes: null, percentage: null })
    const wrapper = mount(QuotaBar)
    await flushPromises()

    expect(wrapper.text()).toContain('3.0 MB used')
    expect(wrapper.find('[data-testid="quota-limit-unknown"]').text()).toBe('Limit unknown')
    expect(wrapper.text()).not.toContain('NaN')
    expect(wrapper.text()).not.toContain('free')
    expect(wrapper.find('.h-1\\.5').exists()).toBe(false)
  })
})
