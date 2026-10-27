import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

vi.mock('vue-sonner', () => ({
  toast: { success: vi.fn<(m: string) => void>(), error: vi.fn<(m: string) => void>() },
}))

vi.mock('@/services/push', () => ({
  getPushConfig: vi.fn<() => Promise<string | null>>(),
  hasSubscription: vi.fn<() => Promise<boolean>>(),
  isIos: vi.fn<() => boolean>(),
  isPushSupported: vi.fn<() => boolean>(),
  isStandalone: vi.fn<() => boolean>(),
  subscribe: vi.fn<(key: string) => Promise<void>>(),
  unsubscribe: vi.fn<() => Promise<void>>(),
  getPreferences: vi.fn<() => Promise<unknown[]>>(),
  setPreferences: vi.fn<(id: number, p: unknown) => Promise<unknown>>(),
}))

import * as service from '@/services/push'
import { useChildStore } from '@/stores/child'
import NotificationSettingsView from '@/views/NotificationSettingsView.vue'
import type { Child } from '@/types/child'

const mocked = vi.mocked(service)

function child(id: number, name: string, role = 'owner'): Child {
  return { id, name, role } as Child
}

async function mountView() {
  const pinia = createPinia()
  setActivePinia(pinia)
  const store = useChildStore()
  store.children = [child(1, 'Mia'), child(2, 'Leo', 'member')]
  const wrapper = mount(NotificationSettingsView, { global: { plugins: [pinia] } })
  await flushPromises()
  return wrapper
}

describe('NotificationSettingsView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.stubGlobal('Notification', { permission: 'default' })
    mocked.isPushSupported.mockReturnValue(true)
    mocked.isIos.mockReturnValue(false)
    mocked.isStandalone.mockReturnValue(false)
    mocked.getPushConfig.mockResolvedValue('KEY')
    mocked.hasSubscription.mockResolvedValue(false)
    mocked.getPreferences.mockResolvedValue([
      {
        child_id: 1,
        notify_photos: true,
        notify_comments: false,
        notify_joins: false,
        notify_admin_comments: true,
      },
    ])
  })

  it('hides the controls when push is not configured', async () => {
    mocked.getPushConfig.mockResolvedValue(null)

    const wrapper = await mountView()

    expect(wrapper.find('[data-testid="push-unavailable"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="push-device-toggle"]').exists()).toBe(false)
  })

  it('shows the Add to Home Screen hint instead of the toggle on iOS in a tab', async () => {
    mocked.isIos.mockReturnValue(true)

    const wrapper = await mountView()

    expect(wrapper.find('[data-testid="push-ios-hint"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="push-device-toggle"]').exists()).toBe(false)
  })

  it('renders the device toggle and a row per child with saved states', async () => {
    const wrapper = await mountView()

    const rows = wrapper.findAll('[data-testid="push-child-row"]')
    expect(rows).toHaveLength(2)
    expect(rows[0]!.text()).toContain('Mia')
    expect(rows[0]!.find('[data-testid="push-photos-toggle"]').attributes('aria-checked')).toBe('true')
    expect(rows[0]!.find('[data-testid="push-comments-toggle"]').attributes('aria-checked')).toBe('false')
    expect(rows[1]!.find('[data-testid="push-comments-toggle"]').attributes('aria-checked')).toBe('true')
    expect(wrapper.find('[data-testid="push-device-toggle"]').attributes('aria-checked')).toBe('false')
  })

  it('shows joins and member-comment toggles only for owners and admins', async () => {
    const wrapper = await mountView()

    const rows = wrapper.findAll('[data-testid="push-child-row"]')
    expect(rows[0]!.find('[data-testid="push-joins-toggle"]').attributes('aria-checked')).toBe('false')
    expect(
      rows[0]!.find('[data-testid="push-admin-comments-toggle"]').attributes('aria-checked'),
    ).toBe('true')
    expect(rows[1]!.find('[data-testid="push-joins-toggle"]').exists()).toBe(false)
    expect(rows[1]!.find('[data-testid="push-admin-comments-toggle"]').exists()).toBe(false)
  })

  it('saves the joins toggle', async () => {
    mocked.setPreferences.mockResolvedValue({
      child_id: 1,
      notify_photos: true,
      notify_comments: false,
      notify_joins: true,
      notify_admin_comments: true,
    })
    const wrapper = await mountView()

    await wrapper
      .findAll('[data-testid="push-child-row"]')[0]!
      .find('[data-testid="push-joins-toggle"]')
      .trigger('click')
    await flushPromises()

    expect(mocked.setPreferences).toHaveBeenCalledWith(1, {
      notify_photos: true,
      notify_comments: false,
      notify_joins: true,
      notify_admin_comments: true,
    })
  })

  it('subscribes only when the device toggle is tapped', async () => {
    mocked.subscribe.mockResolvedValue(undefined)
    const wrapper = await mountView()
    expect(mocked.subscribe).not.toHaveBeenCalled()

    await wrapper.find('[data-testid="push-device-toggle"]').trigger('click')
    await flushPromises()

    expect(mocked.subscribe).toHaveBeenCalledWith('KEY')
    expect(wrapper.find('[data-testid="push-device-toggle"]').attributes('aria-checked')).toBe('true')
  })

  it('saves a per-child switch change', async () => {
    mocked.setPreferences.mockResolvedValue({
      child_id: 2,
      notify_photos: false,
      notify_comments: true,
      notify_joins: true,
      notify_admin_comments: true,
    })
    const wrapper = await mountView()

    await wrapper
      .findAll('[data-testid="push-child-row"]')[1]!
      .find('[data-testid="push-photos-toggle"]')
      .trigger('click')
    await flushPromises()

    expect(mocked.setPreferences).toHaveBeenCalledWith(2, {
      notify_photos: false,
      notify_comments: true,
      notify_joins: true,
      notify_admin_comments: true,
    })
    expect(
      wrapper
        .findAll('[data-testid="push-child-row"]')[1]!
        .find('[data-testid="push-photos-toggle"]')
        .attributes('aria-checked'),
    ).toBe('false')
  })

  it('shows a blocked message and disables the toggle when permission is denied', async () => {
    vi.stubGlobal('Notification', { permission: 'denied' })

    const wrapper = await mountView()

    expect(wrapper.find('[data-testid="push-blocked"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="push-device-toggle"]').attributes('disabled')).toBeDefined()
  })
})
