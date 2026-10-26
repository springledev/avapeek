import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import AppLogo from '@/components/ui/AppLogo.vue'
import AuthCard from '@/components/AuthCard.vue'

describe('AppLogo', () => {
  it('renders the mark alone with an accessible name', () => {
    const wrapper = mount(AppLogo)
    const img = wrapper.get('img')

    expect(img.attributes('alt')).toBe('Avapeek')
    expect(img.attributes('width')).toBe('40')
    expect(wrapper.find('.app-logo__wordmark').exists()).toBe(false)
  })

  it('marks the image decorative when the wordmark carries the name', () => {
    const wrapper = mount(AppLogo, { props: { size: 32, wordmark: true } })

    expect(wrapper.get('img').attributes('alt')).toBe('')
    expect(wrapper.get('img').attributes('height')).toBe('32')
    expect(wrapper.get('.app-logo__wordmark').text()).toBe('Avapeek')
  })

  it('is shown above every auth card', () => {
    const wrapper = mount(AuthCard, { slots: { default: '<p>content</p>' } })

    expect(wrapper.findComponent(AppLogo).exists()).toBe(true)
    expect(wrapper.text()).toContain('Avapeek')
  })
})
