import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import { useChildStore } from '@/stores/child'
import AppTabBar from '@/components/ui/AppTabBar.vue'
import UploadView from '@/views/UploadView.vue'
import { toast } from 'vue-sonner'
import type { Child } from '@/types/child'

vi.mock('vue-sonner', () => ({
  toast: { error: vi.fn<(m: string) => void>(), success: vi.fn<(m: string) => void>(), info: vi.fn<(m: string) => void>() },
}))

vi.mock('@/lib/sign-out', () => ({ signOut: vi.fn<() => Promise<void>>() }))

function child(id: number, role: Child['role'], canUpload?: boolean): Child {
  return { id, name: `k${id}`, role, can_upload: canUpload } as unknown as Child
}

function router() {
  const view = { template: '<div />' }
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/feed', component: view },
      { path: '/recents', component: view },
      { path: '/upload', component: UploadView },
      { path: '/settings', component: view },
    ],
  })
}

async function mountTabBar() {
  const r = router()
  await r.push('/feed')
  return mount(AppTabBar, { global: { plugins: [r] } })
}

describe('view-only members', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('canUploadAnywhere is false when every membership is view only', () => {
    const store = useChildStore()
    store.children = [child(1, 'member', false), child(2, 'member', false)]
    expect(store.canUploadAnywhere).toBe(false)
    store.children = [child(1, 'member', false), child(2, 'admin')]
    expect(store.canUploadAnywhere).toBe(true)
  })

  it('hides the Upload tab before children load', async () => {
    const wrapper = await mountTabBar()
    expect(wrapper.text()).not.toContain('Upload')
  })

  it('hides the Upload tab for a view-only member but shows it to uploaders and newcomers', async () => {
    const store = useChildStore()
    store.childrenLoaded = true
    store.children = [child(1, 'member', false)]
    const viewOnly = await mountTabBar()
    expect(viewOnly.text()).not.toContain('Upload')

    store.children = [child(1, 'member', true)]
    const uploader = await mountTabBar()
    expect(uploader.text()).toContain('Upload')

    store.children = []
    const newcomer = await mountTabBar()
    expect(newcomer.text()).toContain('Upload')
  })

  it('redirects a view-only member away from /upload', async () => {
    const store = useChildStore()
    store.childrenLoaded = true
    store.children = [child(1, 'member', false)]
    const r = router()
    await r.push('/upload')
    const wrapper = mount(UploadView, { global: { plugins: [r] } })
    await flushPromises()

    expect(toast.info).toHaveBeenCalled()
    expect(r.currentRoute.value.path).toBe('/feed')
    expect(wrapper.text()).not.toContain('Upload photos')
  })
})
