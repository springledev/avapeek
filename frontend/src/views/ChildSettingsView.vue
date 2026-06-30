<script setup lang="ts">
import { ChevronLeft } from '@lucide/vue'
import { ref, onMounted, computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useChildStore } from '@/stores/child'
import { generateDEK, wrapDEK, exportKEK, importKEK } from '@/crypto/keychain'
import { toast } from 'vue-sonner'
import type { Child, ChildMember, InviteResponse } from '@/types/child'

const router = useRouter()
const route = useRoute()
const childStore = useChildStore()

const childId = computed(() => Number(route.params.id))
const child = computed(() => childStore.children.find(c => c.id === childId.value) || null)

const showInviteModal = ref(false)
const inviteRole = ref('member')
const inviteRelationship = ref('')
const inviteResult = ref<InviteResponse | null>(null)
const grantAccessUserId = ref<number | null>(null)
const isGranting = ref(false)
const isDeleting = ref(false)

const members = ref<ChildMember[]>([])

onMounted(async () => {
  if (!child.value) {
    await childStore.fetchChildren()
  }
  await fetchMembers()
})

async function fetchMembers() {
  try {
    const response = await (await import('@/services/api')).api.get<{ data: { members: ChildMember[] } }>(`/children/${childId.value}`)
    members.value = response.data.data.members || []
  } catch (err) {
    console.error('Failed to fetch members:', err)
  }
}

async function createInvite() {
  try {
    const response = await childStore.createInvite(childId.value, inviteRole.value, inviteRelationship.value || null)
    inviteResult.value = response
    toast.success('Invite link created!')
  } catch (err: any) {
    toast.error(err.response?.data?.message || 'Failed to create invite.')
  }
}

async function grantAccess() {
  if (!grantAccessUserId.value) return
  isGranting.value = true
  try {
    await childStore.grantAccess(childId.value, grantAccessUserId.value)
    toast.success('Encrypted access granted!')
    grantAccessUserId.value = null
  } catch (err: any) {
    toast.error(err.response?.data?.message || 'Failed to grant access.')
  } finally {
    isGranting.value = false
  }
}

function copyInviteLink() {
  if (inviteResult.value?.url) {
    navigator.clipboard.writeText(inviteResult.value.url)
    toast.success('Link copied to clipboard!')
  }
}
</script>

<template>
  <div v-if="child" class="mt-8">
    <div class="mb-6 flex items-center gap-3">
      <button @click="router.push('/settings/children')" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
        <ChevronLeft class="h-5 w-5" />
      </button>
      <div
        class="flex h-10 w-10 items-center justify-center rounded-full text-sm font-bold text-white"
        :style="{ backgroundColor: child.avatar_color || '#6366f1' }"
      >
        {{ child.name.charAt(0).toUpperCase() }}
      </div>
      <div>
        <h2 class="text-lg font-semibold text-slate-900 dark:text-white">{{ child.name }}</h2>
        <p class="text-sm text-slate-500 dark:text-slate-400">{{ child.age }}</p>
      </div>
    </div>

    <div class="space-y-6">
      <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-slate-600 dark:bg-slate-800">
        <h3 class="mb-3 text-sm font-semibold text-slate-900 dark:text-white">Members</h3>
        <div v-if="members.length > 0" class="space-y-2">
          <div v-for="member in members" :key="member.id" class="flex items-center gap-3 text-sm">
            <div class="flex h-8 w-8 items-center justify-center rounded-full bg-slate-200 text-xs font-medium text-slate-600 dark:bg-slate-600 dark:text-slate-200">
              {{ member.display_name.charAt(0).toUpperCase() }}
            </div>
            <div class="flex-1">
              <p class="font-medium text-slate-900 dark:text-white">{{ member.display_name }}</p>
              <p class="text-xs text-slate-500 dark:text-slate-400">{{ member.role }}<span v-if="member.relationship"> &middot; {{ member.relationship }}</span></p>
            </div>
          </div>
        </div>
        <p v-else class="text-sm text-slate-400">No members found.</p>
      </div>

      <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-slate-600 dark:bg-slate-800">
        <h3 class="mb-3 text-sm font-semibold text-slate-900 dark:text-white">Invite</h3>
        <div class="space-y-3">
          <div v-if="!inviteResult" class="flex flex-wrap gap-2">
            <select v-model="inviteRole" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200">
              <option value="member">Member</option>
              <option value="admin">Admin</option>
            </select>
            <input
              v-model="inviteRelationship"
              type="text"
              placeholder="Relationship (optional)"
              class="flex-1 min-w-[140px] rounded-lg border border-gray-300 px-3 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200"
            />
            <button
              @click="createInvite"
              class="rounded-lg bg-indigo-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-indigo-700"
            >
              Generate Link
            </button>
          </div>

          <div v-else class="space-y-3">
            <div class="flex items-center gap-2">
              <input
                :value="inviteResult.url"
                readonly
                class="flex-1 rounded-lg border border-gray-300 bg-gray-50 px-3 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200"
              />
              <button
                @click="copyInviteLink"
                class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700"
              >
                Copy
              </button>
            </div>
            <p class="text-xs text-slate-400">This link expires in 7 days.</p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div v-else class="mt-8 text-center text-slate-500 dark:text-slate-400">
    Child not found.
  </div>
</template>
