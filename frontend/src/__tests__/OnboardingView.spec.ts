import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import OnboardingView from '@/views/OnboardingView.vue'

describe('OnboardingView', () => {
  it('tells the new member photos appear once an admin opens Avapeek', () => {
    const wrapper = mount(OnboardingView, {
      global: { mocks: { $router: { push: () => undefined } } },
    })
    expect(wrapper.text()).toContain('Photos appear once an album admin opens Avapeek')
    expect(wrapper.text()).toContain("We'll notify you")
  })
})
