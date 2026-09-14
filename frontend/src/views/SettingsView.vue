<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { User, Users, ChevronRight, ChevronLeft } from '@lucide/vue'
import { api } from '@/services/api'
import { toast } from 'vue-sonner'

const route = useRoute()
const hasSubRoute = computed(() => route.matched.length > 1)
const backTarget = computed(() => {
  if (route.name === 'settings-child') return { to: '/settings/children', label: 'Children' }
  return { to: '/settings', label: 'Settings' }
})

const pendingInvites = ref<Array<{ id: number; child_name: string; role: string; relationship: string | null; token: string; expires_at: string; created_at: string }>>([])

async function fetchPendingInvites() {
  try {
    const { data } = await api.get('/invites/pending')
    pendingInvites.value = data.data
  } catch { /**/ }
}

async function revokeInvite(id: number) {
  try {
    await api.delete(`/invites/${id}`)
    pendingInvites.value = pendingInvites.value.filter(i => i.id !== id)
    toast.success('Invite revoked.')
  } catch (err: any) {
    toast.error(err.response?.data?.message || 'Failed to revoke invite.')
  }
}

onMounted(fetchPendingInvites)
</script>

<template>
  <div class="mx-auto max-w-2xl">
    <div v-if="hasSubRoute" class="mb-6">
      <router-link :to="backTarget.to" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">
        <ChevronLeft class="h-4 w-4" />
        {{ backTarget.label }}
      </router-link>
    </div>

    <h1 v-if="!hasSubRoute" class="mb-6 text-2xl font-bold text-slate-900 dark:text-white">Settings</h1>

    <div v-if="!hasSubRoute" class="space-y-3">
      <router-link
        to="/settings/profile"
        class="flex items-center gap-4 rounded-xl border border-gray-200 bg-white p-4 transition-colors hover:bg-gray-50 dark:border-slate-600 dark:bg-slate-800 dark:hover:bg-slate-700/50"
      >
        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-100 dark:bg-indigo-900/30">
          <User class="h-5 w-5 text-indigo-600 dark:text-indigo-400" />
        </div>
        <div class="flex-1">
          <p class="font-medium text-slate-900 dark:text-white">Profile</p>
          <p class="text-sm text-slate-500 dark:text-slate-400">Name, email, password</p>
        </div>
        <ChevronRight class="h-5 w-5 text-slate-400" />
      </router-link>

      <router-link
        to="/settings/children"
        class="flex items-center gap-4 rounded-xl border border-gray-200 bg-white p-4 transition-colors hover:bg-gray-50 dark:border-slate-600 dark:bg-slate-800 dark:hover:bg-slate-700/50"
      >
        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-green-100 dark:bg-green-900/30">
          <Users class="h-5 w-5 text-green-600 dark:text-green-400" />
        </div>
        <div class="flex-1">
          <p class="font-medium text-slate-900 dark:text-white">Children</p>
          <p class="text-sm text-slate-500 dark:text-slate-400">Manage profiles, members, invitations</p>
        </div>
        <ChevronRight class="h-5 w-5 text-slate-400" />
      </router-link>
    </div>

    <div v-if="!hasSubRoute && pendingInvites.length > 0" class="mt-6 rounded-xl border border-gray-200 bg-white p-5 dark:border-slate-600 dark:bg-slate-800">
      <h3 class="mb-3 text-sm font-semibold text-slate-900 dark:text-white">Pending Invites</h3>
      <div class="space-y-2">
        <div v-for="inv in pendingInvites" :key="inv.id" class="flex items-center justify-between text-sm">
          <div>
            <span class="font-medium text-slate-900 dark:text-white">{{ inv.child_name }}</span>
            <span class="text-slate-500 dark:text-slate-400 ml-2">{{ inv.role }}<span v-if="inv.relationship"> &middot; {{ inv.relationship }}</span></span>
          </div>
          <div class="flex items-center gap-3">
            <span class="text-xs text-slate-400">Expires {{ new Date(inv.expires_at).toLocaleDateString() }}</span>
            <button @click="revokeInvite(inv.id)" class="text-xs font-medium text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300">Revoke</button>
          </div>
        </div>
      </div>
    </div>

    <router-view />
  </div>
</template>
