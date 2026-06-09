import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import LoginView from '@/views/LoginView.vue'
import RegisterView from '@/views/RegisterView.vue'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
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
      path: '/',
      name: 'home',
      component: () => import('@/views/HomeView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/child/create',
      name: 'child-create',
      component: () => import('@/views/CreateChildView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/child/:id',
      name: 'child-dashboard',
      component: () => import('@/views/ChildDashboardView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/child/:id/members',
      name: 'child-members',
      component: () => import('@/views/MembersView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/invite/:token',
      name: 'invite-accept',
      component: () => import('@/views/InviteAcceptView.vue'),
    },
  ],
})

router.beforeEach((to, from, next) => {
  const authStore = useAuthStore()

  if (to.meta.requiresAuth && !authStore.isAuthenticated) {
    next({ name: 'login', query: { redirect: to.fullPath } })
    return
  }

  if (to.meta.requiresAuth && authStore.needsReauth) {
    next({ name: 'login', query: { redirect: to.fullPath, reauth: '1' } })
    return
  }

  if (to.meta.requiresGuest && authStore.isAuthenticated) {
    next('/')
    return
  }

  next()
})

export default router