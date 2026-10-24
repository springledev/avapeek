import { describe, it, expect, vi } from 'vitest'
import { handleShareTarget } from '@/lib/share-target'

function requestWith(entries: [string, File | string][]) {
  const formData = new FormData()
  for (const [name, value] of entries) formData.append(name, value)
  return { formData: async () => formData }
}

const image = (name: string) => new File(['x'], name, { type: 'image/png' })

describe('handleShareTarget', () => {
  it('stages image parts, ignores everything else and redirects with 303', async () => {
    const stage = vi.fn<(files: File[]) => Promise<void>>(async () => undefined)
    const request = requestWith([
      ['photos', image('a.png')],
      ['photos', new File(['x'], 'doc.pdf', { type: 'application/pdf' })],
      ['photos', 'not a file'],
      ['other', image('b.png')],
    ])

    const response = await handleShareTarget(request, 'https://app.example', stage)

    expect(stage).toHaveBeenCalledTimes(1)
    expect(stage.mock.calls[0]![0].map((f) => f.name)).toEqual(['a.png'])
    expect(response.status).toBe(303)
    expect(response.headers.get('Location')).toBe('https://app.example/upload?shared=1')
  })

  it('does not stage when there are no images', async () => {
    const stage = vi.fn<(files: File[]) => Promise<void>>(async () => undefined)
    const response = await handleShareTarget(requestWith([]), 'https://app.example', stage)
    expect(stage).not.toHaveBeenCalled()
    expect(response.status).toBe(303)
  })

  it('swallows staging and parsing errors and still redirects', async () => {
    vi.spyOn(console, 'warn').mockImplementation(() => undefined)
    const failing = vi.fn<(files: File[]) => Promise<void>>(async () => {
      throw new Error('quota')
    })
    const a = await handleShareTarget(requestWith([['photos', image('a.png')]]), 'https://app.example', failing)
    expect(a.status).toBe(303)

    const b = await handleShareTarget(
      {
        formData: async () => {
          throw new Error('bad body')
        },
      },
      'https://app.example',
      failing,
    )
    expect(b.status).toBe(303)
  })
})
