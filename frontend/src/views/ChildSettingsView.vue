<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRoute } from 'vue-router'
import { useChildStore } from '@/stores/child'
import { useAuthStore } from '@/stores/auth'
import { api } from '@/services/api'
import { toast } from 'vue-sonner'
import UiAvatar from '@/components/ui/UiAvatar.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiSheet from '@/components/ui/UiSheet.vue'
import type { ChildMember, InviteResponse } from '@/types/child'

const route = useRoute()
const childStore = useChildStore()
const authStore = useAuthStore()

const currentUser = computed(() => authStore.user)

const childId = computed(() => Number(route.params.id))
const child = computed(() => childStore.children.find((c) => c.id === childId.value) || null)

const showInviteSheet = ref(false)
const inviteRole = ref('member')
const inviteRelationship = ref('')
const inviteResult = ref<InviteResponse | null>(null)

const members = ref<ChildMember[]>([])
const pendingChildInvites = ref<
  Array<{ id: number; role: string; relationship: string | null; expires_at: string }>
>([])

const currentUserRole = computed(() => {
  if (!child.value) return null
  return child.value.role
})

const canManageMembers = computed(() => {
  return currentUserRole.value === 'owner' || currentUserRole.value === 'admin'
})

onMounted(async () => {
  if (!child.value) {
    await childStore.fetchChildren()
  }
  await Promise.all([fetchMembers(), fetchPendingInvites()])
})

async function fetchMembers() {
  try {
    const response = await api.get<{ data: { members: ChildMember[] } }>(
      `/children/${childId.value}`,
    )
    members.value = response.data.data.members || []
  } catch (err) {
    console.error('Failed to fetch members:', err)
  }
}

async function fetchPendingInvites() {
  try {
    const { data } = await api.get('/invites/pending')
    pendingChildInvites.value = data.data.filter(
      (i: { child_name: string }) => i.child_name === child.value?.name,
    )
  } catch {
    /**/
  }
}

async function createInvite() {
  try {
    const response = await childStore.createInvite(
      childId.value,
      inviteRole.value,
      inviteRelationship.value || null,
    )
    inviteResult.value = response
    toast.success('Invite link created!')
    await fetchPendingInvites()
  } catch (err: unknown) {
    const message =
      err instanceof Error && 'response' in err
        ? (err as { response?: { data?: { message?: string } } }).response?.data?.message
        : undefined
    toast.error(message || 'Failed to create invite.')
  }
}

async function revokeInvite(inviteId: number) {
  try {
    await api.delete(`/invites/${inviteId}`)
    pendingChildInvites.value = pendingChildInvites.value.filter((i) => i.id !== inviteId)
    toast.success('Invite revoked.')
  } catch (err: unknown) {
    const message =
      err instanceof Error && 'response' in err
        ? (err as { response?: { data?: { message?: string } } }).response?.data?.message
        : undefined
    toast.error(message || 'Failed to revoke invite.')
  }
}

async function revokeMemberAccess(userId: number, userName: string) {
  if (
    !confirm(
      `Remove ${userName} from ${child.value?.name}? They will no longer have access to photos.`,
    )
  ) {
    return
  }

  try {
    await api.delete(`/children/${childId.value}/members/${userId}`)
    members.value = members.value.filter((m) => m.id !== userId)
    toast.success(`${userName} has been removed.`)
  } catch (err: unknown) {
    const message =
      err instanceof Error && 'response' in err
        ? (err as { response?: { data?: { message?: string } } }).response?.data?.message
        : undefined
    toast.error(message || 'Failed to remove member.')
  }
}

function copyInviteLink() {
  if (inviteResult.value?.url) {
    navigator.clipboard.writeText(inviteResult.value.url)
    toast.success('Link copied to clipboard!')
  }
}

function closeInviteSheet() {
  showInviteSheet.value = false
  inviteResult.value = null
  inviteRole.value = 'member'
  inviteRelationship.value = ''
}

function formatLastActive(dateStr: string | null): string {
  if (!dateStr) return 'Never'
  const date = new Date(dateStr)
  const now = new Date()
  const diffMs = now.getTime() - date.getTime()
  const diffMins = Math.floor(diffMs / 60000)
  const diffHours = Math.floor(diffMs / 3600000)
  const diffDays = Math.floor(diffMs / 86400000)

  if (diffMins < 1) return 'Just now'
  if (diffMins < 60) return `${diffMins}m ago`
  if (diffHours < 24) return `${diffHours}h ago`
  if (diffDays < 7) return `${diffDays}d ago`
  return date.toLocaleDateString()
}
</script>

<template>
  <div v-if="child" class="child-settings">
    <div class="child-settings__header">
      <UiAvatar :id="child.id" :name="child.name" :color-hex="child.avatar_color" :size="40" />
      <div>
        <h2 class="child-settings__name">{{ child.name }}</h2>
        <p class="child-settings__age">{{ child.age }}</p>
      </div>
    </div>

    <div class="child-settings__sections">
      <div class="child-settings__section">
        <h3 class="child-settings__section-title">Family</h3>
        <div v-if="members.length > 0" class="child-settings__members">
          <div v-for="member in members" :key="member.id" class="child-settings__member">
            <div class="child-settings__member-avatar">
              {{ member.display_name.charAt(0).toUpperCase() }}
            </div>
            <div class="child-settings__member-info">
              <p class="child-settings__member-name">
                {{ member.display_name }}
                <span v-if="member.id === currentUser?.id" class="child-settings__you">(you)</span>
              </p>
              <p class="child-settings__member-role">
                {{ member.role
                }}<span v-if="member.relationship"> · {{ member.relationship }}</span>
                <span v-if="member.last_active" class="child-settings__last-active">
                  · {{ formatLastActive(member.last_active) }}</span
                >
              </p>
            </div>
            <button
              v-if="canManageMembers && member.role !== 'owner' && member.id !== currentUser?.id"
              type="button"
              class="child-settings__revoke"
              @click="revokeMemberAccess(member.id, member.display_name)"
            >
              Remove
            </button>
          </div>
        </div>
        <p v-else class="child-settings__empty">No family members yet.</p>
      </div>

      <div class="child-settings__section">
        <h3 class="child-settings__section-title">Invite</h3>
        <UiButton variant="accent" @click="showInviteSheet = true"> Generate invite link </UiButton>
      </div>

      <div v-if="pendingChildInvites.length > 0" class="child-settings__section">
        <h3 class="child-settings__section-title">Pending invites</h3>
        <div class="child-settings__pending">
          <div v-for="inv in pendingChildInvites" :key="inv.id" class="child-settings__pending-row">
            <span class="child-settings__pending-role">
              {{ inv.role }}<span v-if="inv.relationship"> · {{ inv.relationship }}</span>
            </span>
            <div class="child-settings__pending-actions">
              <span class="child-settings__pending-expires"
                >Expires {{ new Date(inv.expires_at).toLocaleDateString() }}</span
              >
              <button type="button" class="child-settings__revoke" @click="revokeInvite(inv.id)">
                Revoke
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div v-else class="child-settings__not-found">Child not found.</div>

  <UiSheet :open="showInviteSheet" title="Create invite" @close="closeInviteSheet">
    <div class="invite-sheet">
      <div v-if="!inviteResult" class="invite-sheet__form">
        <div class="invite-sheet__field">
          <label class="invite-sheet__label" for="invite-role">Role</label>
          <select id="invite-role" v-model="inviteRole" class="invite-sheet__select">
            <option value="member">Member</option>
            <option value="admin">Admin</option>
          </select>
        </div>
        <div class="invite-sheet__field">
          <label class="invite-sheet__label" for="invite-relationship"
            >Relationship (optional)</label
          >
          <input
            id="invite-relationship"
            v-model="inviteRelationship"
            type="text"
            placeholder="e.g. grandparent"
            class="invite-sheet__input"
          />
        </div>
        <UiButton variant="accent" @click="createInvite"> Generate link </UiButton>
      </div>

      <div v-else class="invite-sheet__result">
        <div class="invite-sheet__link-row">
          <input :value="inviteResult.url" readonly class="invite-sheet__link-input" />
          <UiButton variant="subtle" @click="copyInviteLink"> Copy </UiButton>
        </div>
        <p class="invite-sheet__hint">This link expires in 7 days.</p>
      </div>
    </div>
  </UiSheet>
</template>

<style scoped>
.child-settings {
  margin-top: var(--space-6);
}

.child-settings__header {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  margin-bottom: var(--space-6);
}

.child-settings__name {
  font-size: var(--text-title);
  font-weight: 800;
  line-height: var(--lh-title);
  color: var(--color-ink);
  margin: 0;
}

.child-settings__age {
  font-size: var(--text-meta);
  color: var(--color-ink-soft);
  margin: 0;
}

.child-settings__sections {
  display: flex;
  flex-direction: column;
  gap: var(--space-6);
}

.child-settings__section {
  padding: var(--space-4);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-card);
  background: var(--color-surface);
}

.child-settings__section-title {
  font-size: var(--text-body);
  font-weight: 700;
  color: var(--color-ink);
  margin: 0 0 var(--space-3);
}

.child-settings__members {
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
}

.child-settings__member {
  display: flex;
  align-items: center;
  gap: var(--space-3);
}

.child-settings__member-avatar {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: var(--color-surface-2);
  color: var(--color-ink);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: var(--text-meta);
  font-weight: 700;
  flex-shrink: 0;
}

.child-settings__member-info {
  flex: 1;
  min-width: 0;
}

.child-settings__member-name {
  font-size: var(--text-body);
  font-weight: 600;
  color: var(--color-ink);
  margin: 0;
}

.child-settings__you {
  font-size: var(--text-meta);
  font-weight: 400;
  color: var(--color-ink-soft);
}

.child-settings__last-active {
  font-size: var(--text-meta);
  color: var(--color-ink-soft);
}

.child-settings__member-role {
  font-size: var(--text-meta);
  color: var(--color-ink-soft);
  margin: 0;
}

.child-settings__revoke {
  border: 0;
  background: transparent;
  color: var(--color-danger);
  font-family: inherit;
  font-size: var(--text-meta);
  font-weight: 600;
  cursor: pointer;
  padding: var(--space-1) var(--space-2);
  border-radius: var(--radius-card);
  flex-shrink: 0;
}

.child-settings__revoke:hover {
  background: var(--color-surface-2);
}

.child-settings__empty {
  font-size: var(--text-body);
  color: var(--color-ink-soft);
  margin: 0;
}

.child-settings__pending {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.child-settings__pending-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--space-2) 0;
  border-bottom: 1px solid var(--color-border);
}

.child-settings__pending-row:last-child {
  border-bottom: 0;
}

.child-settings__pending-role {
  font-size: var(--text-body);
  color: var(--color-ink-soft);
}

.child-settings__pending-actions {
  display: flex;
  align-items: center;
  gap: var(--space-3);
}

.child-settings__pending-expires {
  font-size: var(--text-meta);
  color: var(--color-ink-soft);
}

.child-settings__revoke {
  border: 0;
  background: transparent;
  color: var(--color-danger);
  font-family: inherit;
  font-size: var(--text-meta);
  font-weight: 600;
  cursor: pointer;
  padding: var(--space-1) var(--space-2);
  border-radius: var(--radius-card);
}

.child-settings__revoke:hover {
  background: var(--color-surface-2);
}

.child-settings__not-found {
  margin-top: var(--space-6);
  text-align: center;
  color: var(--color-ink-soft);
}

.invite-sheet {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.invite-sheet__form {
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
}

.invite-sheet__field {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.invite-sheet__label {
  font-size: var(--text-body);
  font-weight: 600;
  color: var(--color-ink);
}

.invite-sheet__select,
.invite-sheet__input {
  padding: var(--space-3);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-card);
  background: var(--color-surface);
  color: var(--color-ink);
  font-family: inherit;
  font-size: var(--text-body);
  min-height: var(--target-min);
}

.invite-sheet__select:focus,
.invite-sheet__input:focus {
  outline: none;
  border-color: var(--color-accent);
  box-shadow: 0 0 0 2px var(--color-accent-soft);
}

.invite-sheet__result {
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
}

.invite-sheet__link-row {
  display: flex;
  gap: var(--space-2);
}

.invite-sheet__link-input {
  flex: 1;
  padding: var(--space-3);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-card);
  background: var(--color-surface-2);
  color: var(--color-ink);
  font-family: inherit;
  font-size: var(--text-body);
}

.invite-sheet__hint {
  font-size: var(--text-meta);
  color: var(--color-ink-soft);
  margin: 0;
}
</style>
