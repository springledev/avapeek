import { describe, it, expect } from 'vitest'
import { isPwaEnabled, pwaManifest } from '../../pwa.config'

describe('pwa config', () => {
  it('is disabled under Vitest (vite.config.ts gates the plugin on it)', () => {
    expect(process.env.VITEST).toBeTruthy()
    expect(isPwaEnabled()).toBe(false)
    expect(isPwaEnabled({})).toBe(true)

  })

  it('declares an installable manifest with a share target', () => {
    expect(pwaManifest.start_url).toBe('/feed')
    expect(pwaManifest.display).toBe('standalone')
    expect(pwaManifest.icons?.map((icon) => icon.purpose)).toContain('maskable')
    expect(pwaManifest.share_target).toMatchObject({
      action: '/share-target',
      method: 'POST',
      enctype: 'multipart/form-data',
    })
  })
})
