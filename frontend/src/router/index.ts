import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import LoginView from '@/views/LoginView.vue'
import RegisterView from '@/views/RegisterView.vue'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      redirect: '/feed',
    },
    {
      path: '/login',
      name: 'login',
      component: LoginView,
      meta: { requiresGuest: true },
    },
    {
      path: '/register',
      name: 'register',
      component: RegisterView,
      meta: { requiresGuest: true },
    },
    {
      path: '/feed',
      name: 'feed',
      component: () => import('@/views/FeedView.vue'),
      meta: { requiresAuth: true, layout: 'app-shell' },
    },
    {
      path: '/welcome',
      name: 'welcome',
      component: () => import('@/views/OnboardingView.vue'),
      meta: { requiresAuth: true, layout: 'app-shell' },
    },
    {
      path: '/setup',
      name: 'setup',
      component: () => import('@/views/SetupView.vue'),
      meta: { requiresAuth: true, layout: 'app-shell' },
    },
    {
      path: '/upload',
      name: 'upload',
      component: () => import('@/views/UploadView.vue'),
      meta: { requiresAuth: true, layout: 'app-shell' },
    },
    {
      path: '/recents',
      name: 'recents',
      component: () => import('@/views/RecentsView.vue'),
      meta: { requiresAuth: true, layout: 'app-shell' },
    },
    {
      path: '/settings',
      name: 'settings',
      component: () => import('@/views/SettingsView.vue'),
      meta: { requiresAuth: true, layout: 'app-shell' },
      children: [
        {
          path: 'children',
          name: 'settings-children',
          component: () => import('@/views/ChildrenSettingsView.vue'),
        },
        {
          path: 'children/:id',
          name: 'settings-child',
          component: () => import('@/views/ChildSettingsView.vue'),
          props: true,
        },
        {
          path: 'profile',
          name: 'settings-profile',
          component: () => import('@/views/ProfileSettingsView.vue'),
        },
        {
          path: 'security',
          name: 'settings-security',
          component: () => import('@/views/SecuritySettingsView.vue'),
        },
      ],
    },
    {
      path: '/child/create',
      name: 'child-create',
      redirect: '/settings/children',
      meta: { requiresAuth: true },
    },
    {
      path: '/invite/:token',
      name: 'invite-accept',
      component: () => import('@/views/InviteAcceptView.vue'),
    },
  ],
})

router.beforeEach(async (to, _from) => {
  const authStore = useAuthStore()

  if (to.meta.requiresAuth && !authStore.isAuthenticated) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  // The KEK is restored asynchronously after a reload; wait for it before
  // deciding the user has to sign in again.
  await authStore.restoreKEK()

  if (to.meta.requiresAuth && authStore.needsReauth) {
    return { name: 'login', query: { redirect: to.fullPath, reauth: '1' } }
  }

  if (to.meta.requiresGuest && authStore.isAuthenticated && !authStore.needsReauth) {
    return '/feed'
  }
})

export default router
