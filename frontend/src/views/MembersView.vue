<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useChildStore } from '@/stores/child'
import type { ChildDetail, InviteResponse } from '@/types/child'
import AppHeader from '@/components/AppHeader.vue'

const route = useRoute()
const router = useRouter()
const childStore = useChildStore()

const childSlug = computed(() => route.params.slug as string)
const childId = ref(0)
const child = computed(() => {
  const detail = childStore.currentChildDetail
  if (detail && detail.id === childId.value) return detail
  return null
})
const hasLoaded = ref(false)

const showInviteModal = ref(false)
const inviteRole = ref('member')
const inviteRelationship = ref('')

const inviteResponse = ref<InviteResponse | null>(null)
const inviteGrantPhase = ref<boolean>(false)
const copyButtonText = ref('Copy link')
const grantError = ref<string | null>(null)
const isGranting = ref(false)

const relationshipOptions = ['Parent', 'Grandparent', 'Aunt/Uncle', 'Godparent', 'Other']

onMounted(async () => {
  const slug = childSlug.value

  // Resolve slug to numeric ID
  if (childStore.children.length === 0) {
    await childStore.fetchChildren()
  }
  const found = childStore.children.find((c) => c.slug === slug)
  if (!found) {
    router.push('/')
    return
  }
  childId.value = found.id

  childStore.setCurrentChild(childId.value)

  if (child.value) {
    if (child.value.role !== 'owner' && child.value.role !== 'admin') {
      router.push(`/child/${slug}`)
    }
    hasLoaded.value = true
    return
  }

  try {
    await childStore.fetchChildDetail(childId.value)
    const detail = childStore.currentChildDetail
    if (detail && detail.role !== 'owner' && detail.role !== 'admin') {
      router.push(`/child/${slug}`)
    }
  } catch {
    // handled by store
  } finally {
    hasLoaded.value = true
  }
})

function goBack() {
  router.push(`/child/${childSlug.value}`)
}

function openInviteModal() {
  inviteResponse.value = null
  inviteGrantPhase.value = false
  grantError.value = null
  showInviteModal.value = true
}

function closeInviteModal() {
  showInviteModal.value = false
  inviteResponse.value = null
  inviteGrantPhase.value = false
  grantError.value = null
}

async function createInvite() {
  try {
    inviteResponse.value = await childStore.createInvite(
      childId.value,
      inviteRole.value,
      inviteRelationship.value || null,
    )
  } catch {
    // handled by store
  }
}

async function copyInviteUrl() {
  if (!inviteResponse.value) return
  try {
    await navigator.clipboard.writeText(inviteResponse.value.url)
    copyButtonText.value = 'Copied!'
    setTimeout(() => {
      copyButtonText.value = 'Copy link'
    }, 2000)
  } catch {
    copyButtonText.value = 'Copy link'
  }
}

async function handleGrantAccess() {
  if (!child.value) return
  grantError.value = null
  isGranting.value = true

  try {
    const latestMember = child.value.members[child.value.members.length - 1]
    const grantToUserId = latestMember?.id

    if (!grantToUserId) {
      grantError.value = 'No member found to grant access to.'
      return
    }

    await childStore.grantAccess(childId.value, grantToUserId)
    inviteGrantPhase.value = false
    closeInviteModal()
    await childStore.fetchChildDetail(childId.value)
  } catch (err: any) {
    grantError.value = err?.message || 'Failed to grant access.'
  } finally {
    isGranting.value = false
  }
}

function moveToGrantPhase() {
  inviteGrantPhase.value = true
  copyButtonText.value = 'Copy link'
}

function formatRole(role: string): string {
  return role.charAt(0).toUpperCase() + role.slice(1)
}

function formatJoinedAt(dateStr: string): string {
  const date = new Date(dateStr)
  return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
}
</script>

<template>
  <div class="min-h-screen bg-slate-50 dark:bg-slate-950">
    <AppHeader />

    <main class="main-container">
      <div class="flex items-center gap-4 mb-8">
        <button @click="goBack" class="nav-back-link">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </button>
        <div>
          <h1 class="text-lg font-bold text-slate-900 dark:text-slate-100">Members</h1>
          <p class="text-sm text-slate-500 dark:text-slate-400">{{ child?.name }}</p>
        </div>
      </div>

      <div v-if="!hasLoaded" class="space-y-3">
        <div v-for="n in 3" :key="n" class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 flex items-center gap-4 dark:bg-slate-800 dark:border-slate-700">
          <div class="skeleton w-10 h-10 rounded-full shrink-0" />
          <div class="flex-1 space-y-2">
            <div class="skeleton h-4 w-40 rounded" />
            <div class="skeleton h-3 w-28 rounded" />
          </div>
          <div class="skeleton h-6 w-16 rounded-full" />
        </div>
      </div>

      <div v-else-if="!child" class="text-center py-20">
        <p class="text-slate-500 dark:text-slate-400">Failed to load members.</p>
      </div>

      <template v-else>
        <div class="flex items-center justify-between mb-8">
          <h2 class="text-xl font-semibold text-slate-900 dark:text-slate-100">
            {{ child.members.length }} {{ child.members.length === 1 ? 'Member' : 'Members' }}
          </h2>
          <button @click="openInviteModal" class="btn-action">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            Invite someone
          </button>
        </div>

        <div class="space-y-3">
          <div
            v-for="member in child.members"
            :key="member.id"
            class="member-card"
          >
            <div class="avatar-circle avatar-small" :style="{ backgroundColor: child.avatar_color || '#94A3B8' }">
              {{ member.display_name.charAt(0).toUpperCase() }}
            </div>
            <div class="flex-1 min-w-0">
              <p class="text-base font-medium text-slate-900 dark:text-slate-100">
                {{ member.display_name }}
                <span v-if="member.relationship" class="text-sm font-normal text-slate-500 dark:text-slate-400">
                  &mdash; {{ member.relationship }}
                </span>
              </p>
              <p class="text-sm text-slate-500 dark:text-slate-400">
                {{ formatRole(member.role) }} &middot; Joined {{ formatJoinedAt(member.joined_at) }}
              </p>
            </div>
            <span class="role-badge" :class="member.role === 'owner' ? 'role-owner' : member.role === 'admin' ? 'role-admin' : 'role-member'">
              {{ formatRole(member.role) }}
            </span>
          </div>
        </div>
      </template>

      <Teleport to="body">
        <div v-if="showInviteModal" class="modal-overlay" @click.self="closeInviteModal">
          <div class="modal-content" @click.stop>

            <template v-if="!inviteResponse">
              <div class="flex items-center justify-between mb-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Invite someone</h3>
                <button @click="closeInviteModal" class="text-slate-400 hover:text-slate-600 dark:text-slate-500 dark:hover:text-slate-300">
                  <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
              </div>

              <p class="text-sm text-slate-600 mb-6 dark:text-slate-400">
                Generate a shareable invite link. You can send it to your family member however you like &mdash; iMessage, WhatsApp, Signal, or in person.
              </p>

              <div class="form-group mb-4">
                <label class="form-label">Role</label>
                <select v-model="inviteRole" class="form-input">
                  <option value="member">Member</option>
                  <option value="admin">Admin</option>
                </select>
                <p class="text-xs text-slate-500 mt-1 dark:text-slate-400">
                  Members can view photos. Admins can also manage albums and invite others.
                </p>
              </div>

              <div class="form-group mb-6">
                <label class="form-label">Relationship (optional)</label>
                <select v-model="inviteRelationship" class="form-input">
                  <option value="">Select...</option>
                  <option v-for="rel in relationshipOptions" :key="rel" :value="rel">{{ rel }}</option>
                </select>
              </div>

              <button
                @click="createInvite"
                :disabled="childStore.isLoading"
                class="btn-primary"
              >
                <span v-if="childStore.isLoading" class="spinner mr-2"></span>
                {{ childStore.isLoading ? 'Generating...' : 'Generate invite link' }}
              </button>

              <p v-if="childStore.error" class="error-message mt-3">{{ childStore.error }}</p>
            </template>

            <template v-else-if="!inviteGrantPhase">
              <div class="flex items-center justify-between mb-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Share this link</h3>
                <button @click="closeInviteModal" class="text-slate-400 hover:text-slate-600 dark:text-slate-500 dark:hover:text-slate-300">
                  <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
              </div>

              <div class="alert-success mb-6">
                <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                <span>Invite link created!</span>
              </div>

              <p class="text-sm text-slate-600 mb-4 dark:text-slate-400">
                Share this link with your family member. Once they accept, you can grant them access to encrypted photos.
              </p>

              <div class="invite-url-box mb-4">
                <input
                  type="text"
                  readonly
                  :value="inviteResponse.url"
                  class="invite-url-input"
                  @focus="($event.target as HTMLInputElement)?.select()"
                />
                <button @click="copyInviteUrl" class="copy-button">
                  {{ copyButtonText }}
                </button>
              </div>

              <p class="text-xs text-amber-600 mb-6 dark:text-amber-400">
                This link expires on {{ new Date(inviteResponse.expires_at).toLocaleDateString(undefined, { month: 'long', day: 'numeric', year: 'numeric' }) }}.
              </p>

              <button @click="moveToGrantPhase" class="btn-secondary">
                Continue to grant access
              </button>
            </template>

            <template v-else>
              <div class="flex items-center justify-between mb-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Grant encrypted access</h3>
                <button @click="closeInviteModal" class="text-slate-400 hover:text-slate-600 dark:text-slate-500 dark:hover:text-slate-300">
                  <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
              </div>

              <div class="security-badge mb-6">
                <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                </svg>
                <span class="security-badge-text">
                  The invitee must accept the invite and create an account before you can grant them encrypted access to photos.
                </span>
              </div>

              <p class="text-sm text-slate-600 mb-6 dark:text-slate-400">
                This will wrap the encryption key for the most recent member of <strong class="dark:text-slate-200">{{ child?.name }}</strong>. Make sure they have accepted the invite before proceeding.
              </p>

              <p v-if="grantError" class="error-message mb-4">{{ grantError }}</p>

              <div class="flex gap-3">
                <button @click="handleGrantAccess" :disabled="isGranting" class="btn-action flex-1 justify-center">
                  <span v-if="isGranting" class="spinner mr-2"></span>
                  {{ isGranting ? 'Granting...' : 'Grant access' }}
                </button>
                <button @click="closeInviteModal" class="btn-logout">Cancel</button>
              </div>
            </template>

          </div>
        </div>
      </Teleport>
    </main>
  </div>
</template>
