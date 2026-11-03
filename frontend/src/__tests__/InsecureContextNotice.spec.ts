import { describe, it, expect, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import InsecureContextNotice from '@/components/InsecureContextNotice.vue'

function setSecure(value: boolean | undefined) {
  Object.defineProperty(window, 'isSecureContext', { value, configurable: true })
}

describe('InsecureContextNotice', () => {
  afterEach(() => setSecure(true))

  it('explains the HTTPS requirement and links to the installation docs', () => {
    setSecure(false)
    const wrapper = mount(InsecureContextNotice)
    const notice = wrapper.get('[data-test="insecure-context"]')
    expect(notice.text()).toContain('This needs HTTPS')
    const link = notice.get('a')
    expect(link.attributes('href')).toBe(
      'https://avapeek.com/docs/installation#tls-and-reverse-proxies',
    )
  })

  it('renders nothing in a secure context', () => {
    setSecure(true)
    expect(mount(InsecureContextNotice).find('[data-test="insecure-context"]').exists()).toBe(false)
  })

  it('renders nothing when the browser does not report the flag', () => {
    setSecure(undefined)
    expect(mount(InsecureContextNotice).find('[data-test="insecure-context"]').exists()).toBe(false)
  })
})
