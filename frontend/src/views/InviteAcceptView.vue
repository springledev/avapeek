<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { api } from '@/services/api'
import type { InvitePreview } from '@/types/child'
import { apiErrorMessage } from '@/lib/util'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()

const token = route.params.token as string
const invite = ref<InvitePreview | null>(null)
const isLoading = ref(true)
const isAccepting = ref(false)
const error = ref<string | null>(null)

async function loadPreview() {
  isLoading.value = true
  error.value = null

  try {
    const response = await api.get<{ data: InvitePreview }>(`/invites/${token}`)
    invite.value = response.data.data
    localStorage.setItem('pending_invite_token', token)
  } catch (err) {
    const data = (
      err as {
        response?: { data?: { message?: string; already_accepted?: { child_slug?: string } } }
      }
    ).response?.data
    if (data?.already_accepted?.child_slug) {
      router.push(`/child/${data.already_accepted.child_slug}`)
      return
    }
    localStorage.removeItem('pending_invite_token')
    error.value = data?.message || 'This invite is invalid or has expired.'
  } finally {
    isLoading.value = false
  }
}

async function acceptInvite() {
  if (!authStore.isAuthenticated) {
    router.push({ name: 'login', query: { redirect: `/invite/${token}` } })
    return
  }

  isAccepting.value = true
  error.value = null

  try {
    await api.post<{ data: { child_id: number; child_slug: string; child_name: string } }>(`/invites/${token}/accept`)

    localStorage.removeItem('pending_invite_token')
    router.push('/welcome')
  } catch (err) {
    error.value = apiErrorMessage(err, 'Failed to accept invite.')
    isAccepting.value = false
  }
}

function goToLogin() {
  router.push({ name: 'login', query: { redirect: `/invite/${token}` } })
}

function goToRegister() {
  router.push({ name: 'register', query: { invite_token: token, redirect: `/invite/${token}` } })
}

onMounted(() => {
  loadPreview()
})
</script>

<template>
  <div class="min-h-screen bg-slate-50 dark:bg-slate-950">
    <div class="auth-container">
      <div class="auth-card">
        <div class="text-center mb-6">
          <div class="w-16 h-16 mx-auto mb-4 bg-blue-100 rounded-full flex items-center justify-center dark:bg-blue-900/50">
            <svg class="w-8 h-8 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
            </svg>
          </div>

          <div v-if="isLoading" class="py-8">
            <span class="spinner-large"></span>
          </div>

          <div v-else-if="error" class="py-8">
            <div class="alert-error mb-6">{{ error }}</div>
            <button @click="router.push('/login')" class="btn-secondary">Go to Avapeek</button>
          </div>

          <template v-else-if="invite">
            <h2 class="auth-title mb-2">
              You've been invited!
            </h2>
            <p class="auth-subtitle">
              {{ invite.inviter_name }} has invited you to see photos of
              <strong class="text-slate-900 dark:text-slate-100">{{ invite.child_name }}</strong>
              on Avapeek
              <span v-if="invite.relationship"> as their {{ invite.relationship.toLowerCase() }}</span>.
            </p>

            <div v-if="invite.role" class="mt-4 text-sm text-slate-500 dark:text-slate-400">
              Role: <span class="font-medium text-slate-700 dark:text-slate-200">{{ invite.role.charAt(0).toUpperCase() + invite.role.slice(1) }}</span>
            </div>

            <div v-if="!authStore.isAuthenticated" class="mt-8 space-y-3">
              <p class="text-sm text-slate-500 mb-2 dark:text-slate-400">You need an account to accept this invite.</p>
              <button @click="goToRegister" class="btn-primary">
                Create an account
              </button>
              <button @click="goToLogin" class="btn-secondary">
                I already have an account
              </button>
            </div>

            <div v-else class="mt-8">
              <p class="text-sm text-slate-500 mb-4 dark:text-slate-400">
                You're logged in as <strong class="dark:text-slate-200">{{ authStore.currentUser?.display_name }}</strong>.
              </p>
              <button
                @click="acceptInvite"
                :disabled="isAccepting"
                class="btn-primary"
              >
                <span v-if="isAccepting" class="spinner mr-2"></span>
                {{ isAccepting ? 'Accepting...' : 'Accept invite' }}
              </button>

              <p class="text-xs text-slate-400 mt-3 dark:text-slate-500">
                By accepting, you'll be able to view encrypted photos of {{ invite.child_name }}.
                The owner will need to grant you access to the encryption key after you accept.
              </p>
            </div>
          </template>
        </div>
      </div>
    </div>
  </div>
</template>
