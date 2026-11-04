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
    expect(wrapper.find('[data-testid="quota-track"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="quota-limit-unknown"]').exists()).toBe(false)
  })

  it.each([
    [50, 'safe'],
    [80, 'warning'],
    [95, 'danger'],
  ])('styles %i%% usage as the %s level using theme classes', async (percentage, level) => {
    mockQuota({ used_bytes: percentage, limit_bytes: 100, percentage })
    const wrapper = mount(QuotaBar)
    await flushPromises()

    expect(wrapper.find('[data-testid="quota-fill"]').classes()).toContain(`quota__fill--${level}`)
    expect(wrapper.find('[data-testid="quota-free"]').classes()).toContain(`quota__free--${level}`)
    expect(wrapper.html()).not.toMatch(/slate-|gray-|blue-|amber-|red-/)
  })

  it('renders a graceful unknown-limit state when limit_bytes is null', async () => {
    mockQuota({ used_bytes: 1024 * 1024 * 3, limit_bytes: null, percentage: null })
    const wrapper = mount(QuotaBar)
    await flushPromises()

    expect(wrapper.text()).toContain('3.0 MB used')
    expect(wrapper.find('[data-testid="quota-limit-unknown"]').text()).toBe('Limit unknown')
    expect(wrapper.text()).not.toContain('NaN')
    expect(wrapper.text()).not.toContain('free')
    expect(wrapper.find('[data-testid="quota-track"]').exists()).toBe(false)
  })

  it('requests the system-wide /quota endpoint', async () => {
    mockQuota({ used_bytes: 0, limit_bytes: null, percentage: null })
    mount(QuotaBar)
    await flushPromises()

    expect(api.get).toHaveBeenCalledWith('/quota')
  })

  it('shows an unavailable message, not numbers, when the fetch fails, and retries', async () => {
    vi.mocked(api.get).mockRejectedValueOnce(new Error('boom'))
    const wrapper = mount(QuotaBar)
    await flushPromises()

    expect(wrapper.find('[data-testid="quota-error"]').text()).toContain('Storage usage unavailable')
    expect(wrapper.text()).not.toContain('0.0 KB')

    mockQuota({ used_bytes: 1024 * 1024, limit_bytes: null, percentage: null })
    await wrapper.find('[data-testid="quota-error"] button').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="quota-error"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('1.0 MB used')
  })

  it('treats a malformed response as a failure', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: {} })
    const wrapper = mount(QuotaBar)
    await flushPromises()

    expect(wrapper.find('[data-testid="quota-error"]').exists()).toBe(true)
  })
})
