import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const html = readFileSync(resolve(__dirname, '../../index.html'), 'utf8')

describe('safe area setup', () => {
  it('extends into the notch area so env(safe-area-inset-*) is populated in the installed app', () => {
    expect(html).toMatch(/<meta name="viewport"[^>]*viewport-fit=cover/)
  })

  it('declares the installed app capable with a non-overlaying status bar', () => {
    expect(html).toContain('name="apple-mobile-web-app-capable"')
    expect(html).toMatch(/apple-mobile-web-app-status-bar-style" content="default"/)
  })
})
