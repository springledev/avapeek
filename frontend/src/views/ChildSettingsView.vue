<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useChildStore } from '@/stores/child'
import { useAuthStore } from '@/stores/auth'
import { api } from '@/services/api'
import { toast } from 'vue-sonner'
import UiAvatar from '@/components/ui/UiAvatar.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiSheet from '@/components/ui/UiSheet.vue'
import ChildExportSheet from '@/components/ChildExportSheet.vue'
import PasswordConfirmSheet from '@/components/PasswordConfirmSheet.vue'
import { apiErrorMessage, firstValidationError } from '@/lib/util'
import type { ChildMember, InviteResponse } from '@/types/child'

const route = useRoute()
const router = useRouter()
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

const canEdit = computed(() => canManageMembers.value)
const canDelete = computed(() => currentUserRole.value === 'owner')

const showEditSheet = ref(false)
const editName = ref('')
const editBirthDate = ref('')
const editError = ref('')
const isSaving = ref(false)
const today = new Date().toISOString().split('T')[0]

const showExportSheet = ref(false)

const showDeleteSheet = ref(false)
const deleteConfirmName = ref('')
const deleteError = ref('')
const isDeleting = ref(false)

const deleteNameMatches = computed(
  () => !!child.value && deleteConfirmName.value.trim() === child.value.name,
)

function openEditSheet() {
  if (!child.value) return
  editName.value = child.value.name
  editBirthDate.value = child.value.birth_date ?? ''
  editError.value = ''
  showEditSheet.value = true
}

async function saveEdit() {
  if (!editName.value.trim()) {
    editError.value = 'Name is required'
    return
  }
  isSaving.value = true
  editError.value = ''
  try {
    await childStore.updateChild(childId.value, {
      name: editName.value.trim(),
      birthDate: editBirthDate.value || null,
    })
    showEditSheet.value = false
    toast.success('Profile updated.')
  } catch (err: unknown) {
    editError.value = firstValidationError(err) || apiErrorMessage(err, 'Failed to update profile.')
  } finally {
    isSaving.value = false
  }
}

function openDeleteSheet() {
  deleteConfirmName.value = ''
  deleteError.value = ''
  showDeleteSheet.value = true
}

async function confirmDelete() {
  if (!deleteNameMatches.value) return
  isDeleting.value = true
  deleteError.value = ''
  try {
    await childStore.deleteChild(childId.value)
    showDeleteSheet.value = false
    toast.success('Kid deleted.')
    router.push('/settings/children')
  } catch (err: unknown) {
    deleteError.value = apiErrorMessage(err, 'Failed to delete kid.')
  } finally {
    isDeleting.value = false
  }
}

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

const selectedMember = ref<ChildMember | null>(null)
const showTransferSheet = ref(false)
const transferError = ref<string | null>(null)
const isTransferring = ref(false)

const isSelf = (member: ChildMember) => member.id === currentUser.value?.id

/** Whether tapping this row opens any action. */
function memberHasActions(member: ChildMember): boolean {
  if (isSelf(member)) return currentUserRole.value === 'admin'
  if (member.role === 'owner') return false
  if (currentUserRole.value === 'owner') return true
  return currentUserRole.value === 'admin' && member.role === 'member'
}

const selectedIsSelf = computed(() => !!selectedMember.value && isSelf(selectedMember.value))
const canSetRole = computed(
  () => currentUserRole.value === 'owner' && !!selectedMember.value && !selectedIsSelf.value,
)
const canRemoveSelected = computed(
  () =>
    !!selectedMember.value &&
    !selectedIsSelf.value &&
    selectedMember.value.role !== 'owner' &&
    (currentUserRole.value === 'owner' || selectedMember.value.role === 'member'),
)

function openMemberSheet(member: ChildMember) {
  if (!memberHasActions(member)) return
  selectedMember.value = member
}

function closeMemberSheet() {
  selectedMember.value = null
}

async function removeSelectedMember() {
  const member = selectedMember.value
  if (!member) return
  if (
    !confirm(
      `Remove ${member.display_name} from ${child.value?.name}? They will lose access in the app. Photos are not re-encrypted.`,
    )
  ) {
    return
  }

  try {
    await api.delete(`/children/${childId.value}/members/${member.id}`)
    members.value = members.value.filter((m) => m.id !== member.id)
    closeMemberSheet()
    toast.success(`${member.display_name} has been removed.`)
  } catch (err: unknown) {
    toast.error(apiErrorMessage(err, 'Failed to remove member.'))
  }
}

async function changeSelectedRole(role: 'admin' | 'member') {
  const member = selectedMember.value
  if (!member) return
  if (
    role === 'member' &&
    !confirm(`Make ${member.display_name} a regular member? They lose admin rights.`)
  ) {
    return
  }

  try {
    const updated = await childStore.setMemberRole(childId.value, member.id, role)
    members.value = members.value.map((m) =>
      m.id === member.id ? { ...m, role: updated.role } : m,
    )
    closeMemberSheet()
    toast.success(
      `${member.display_name} is now ${updated.role === 'admin' ? 'an admin' : 'a member'}.`,
    )
  } catch (err: unknown) {
    toast.error(apiErrorMessage(err, 'Failed to change role.'))
  }
}

function startTransfer() {
  const member = selectedMember.value
  if (!member) return
  if (
    !confirm(
      `Make ${member.display_name} the owner of ${child.value?.name}? You will become an admin and only the new owner can undo this.`,
    )
  ) {
    return
  }
  transferError.value = null
  showTransferSheet.value = true
}

async function confirmTransfer(password: string) {
  const member = selectedMember.value
  if (!member) return
  isTransferring.value = true
  transferError.value = null
  try {
    await childStore.transferOwnership(childId.value, member.id, password)
    showTransferSheet.value = false
    closeMemberSheet()
    toast.success(`${member.display_name} is now the owner.`)
    await fetchMembers()
  } catch (err: unknown) {
    transferError.value =
      firstValidationError(err) || apiErrorMessage(err, 'Failed to transfer ownership.')
    toast.error(transferError.value)
  } finally {
    isTransferring.value = false
  }
}

async function leaveFamily() {
  if (
    !confirm(
      `Leave ${child.value?.name}? You will lose access to its photos in the app. Photos are not re-encrypted, so anything already saved to your devices stays readable there.`,
    )
  ) {
    return
  }

  try {
    await childStore.leaveChild(childId.value)
    closeMemberSheet()
    toast.success('You have left.')
    router.push('/settings/children')
  } catch (err: unknown) {
    toast.error(apiErrorMessage(err, 'Failed to leave.'))
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
      <div v-if="canEdit" class="child-settings__section">
        <h3 class="child-settings__section-title">Profile</h3>
        <UiButton variant="subtle" @click="openEditSheet">Edit name and birth date</UiButton>
      </div>

      <div class="child-settings__section">
        <h3 class="child-settings__section-title">Family</h3>
        <div v-if="members.length > 0" class="child-settings__members">
          <component
            :is="memberHasActions(member) ? 'button' : 'div'"
            v-for="member in members"
            :key="member.id"
            :type="memberHasActions(member) ? 'button' : undefined"
            class="child-settings__member"
            :class="{ 'child-settings__member--tappable': memberHasActions(member) }"
            @click="openMemberSheet(member)"
          >
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
          </component>
        </div>
        <p v-else-if="canManageMembers" class="child-settings__empty">No family members yet.</p>
        <p v-else class="child-settings__empty">Only owners and admins can see the member list.</p>
      </div>

      <div v-if="currentUserRole && currentUserRole !== 'owner'" class="child-settings__section">
        <h3 class="child-settings__section-title">Leave</h3>
        <p class="invite-sheet__hint child-settings__leave-hint">
          You lose access to this kid's photos in the app. Photos are not re-encrypted.
        </p>
        <UiButton variant="danger" @click="leaveFamily">Leave this kid</UiButton>
      </div>

      <div class="child-settings__section">
        <h3 class="child-settings__section-title">Invite</h3>
        <UiButton variant="accent" @click="showInviteSheet = true"> Generate invite link </UiButton>
      </div>

      <div class="child-settings__section">
        <h3 class="child-settings__section-title">Export</h3>
        <UiButton variant="subtle" @click="showExportSheet = true">
          Download all photos
        </UiButton>
      </div>

      <div v-if="canDelete" class="child-settings__section">
        <h3 class="child-settings__section-title">Danger zone</h3>
        <UiButton variant="danger" @click="openDeleteSheet">Delete this kid</UiButton>
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

  <UiSheet
    :open="selectedMember !== null && !showTransferSheet"
    :title="selectedMember?.display_name ?? ''"
    @close="closeMemberSheet"
  >
    <div v-if="selectedMember" class="member-sheet">
      <p class="invite-sheet__hint">
        {{ selectedMember.role
        }}<span v-if="selectedMember.relationship"> · {{ selectedMember.relationship }}</span>
      </p>
      <template v-if="selectedIsSelf">
        <UiButton variant="danger" @click="leaveFamily">Leave this kid</UiButton>
      </template>
      <template v-else>
        <template v-if="canSetRole">
          <UiButton
            v-if="selectedMember.role === 'member'"
            variant="subtle"
            @click="changeSelectedRole('admin')"
          >
            Make admin
          </UiButton>
          <UiButton v-else variant="subtle" @click="changeSelectedRole('member')">
            Make member
          </UiButton>
          <p class="invite-sheet__hint">
            Admins can only share photo keys once they have opened the app after joining.
          </p>
          <UiButton variant="subtle" @click="startTransfer">Transfer ownership</UiButton>
        </template>
        <UiButton v-if="canRemoveSelected" variant="danger" @click="removeSelectedMember">
          Remove from family
        </UiButton>
      </template>
    </div>
  </UiSheet>

  <PasswordConfirmSheet
    :open="showTransferSheet"
    title="Transfer ownership"
    :description="`Enter your password to make ${selectedMember?.display_name ?? 'this member'} the owner. You will become an admin.`"
    confirm-label="Transfer"
    destructive
    :loading="isTransferring"
    :error="transferError"
    @confirm="confirmTransfer"
    @close="showTransferSheet = false"
  />

  <ChildExportSheet
    v-if="child"
    :open="showExportSheet"
    :child="{ id: child.id, name: child.name }"
    @close="showExportSheet = false"
  />

  <UiSheet :open="showEditSheet" title="Edit profile" @close="showEditSheet = false">
    <div v-if="editError" class="invite-sheet__error" role="alert">{{ editError }}</div>
    <form class="invite-sheet__form" @submit.prevent="saveEdit">
      <div class="invite-sheet__field">
        <label class="invite-sheet__label" for="edit-name">Name</label>
        <input
          id="edit-name"
          v-model="editName"
          type="text"
          autocomplete="off"
          class="invite-sheet__input"
          :disabled="isSaving"
        />
      </div>
      <div class="invite-sheet__field">
        <label class="invite-sheet__label" for="edit-birthdate">Birth date (optional)</label>
        <input
          id="edit-birthdate"
          v-model="editBirthDate"
          type="date"
          class="invite-sheet__input"
          :disabled="isSaving"
          :max="today"
        />
      </div>
      <UiButton type="submit" variant="accent" :loading="isSaving">Save</UiButton>
    </form>
  </UiSheet>

  <UiSheet :open="showDeleteSheet" title="Delete this kid?" @close="showDeleteSheet = false">
    <div v-if="deleteError" class="invite-sheet__error" role="alert">{{ deleteError }}</div>
    <form class="invite-sheet__form" @submit.prevent="confirmDelete">
      <p class="invite-sheet__hint">
        This removes {{ child?.name }}'s album for everyone. Photos that are only in this album are
        permanently deleted. Photos shared with other kids are kept for them. This cannot be undone.
      </p>
      <div class="invite-sheet__field">
        <label class="invite-sheet__label" for="delete-confirm">
          Type <strong>{{ child?.name }}</strong> to confirm
        </label>
        <input
          id="delete-confirm"
          v-model="deleteConfirmName"
          type="text"
          autocomplete="off"
          class="invite-sheet__input"
          :disabled="isDeleting"
        />
      </div>
      <UiButton type="submit" variant="danger" :disabled="!deleteNameMatches" :loading="isDeleting">
        Delete permanently
      </UiButton>
    </form>
  </UiSheet>

  <UiSheet :open="showInviteSheet" title="Create invite" @close="closeInviteSheet">
    <div class="invite-sheet">
      <div v-if="!inviteResult" class="invite-sheet__form">
        <div class="invite-sheet__field">
          <label class="invite-sheet__label" for="invite-role">Role</label>
          <select id="invite-role" v-model="inviteRole" class="invite-sheet__select">
            <option value="member">Member</option>
            <option v-if="currentUserRole === 'owner'" value="admin">Admin</option>
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
  min-height: var(--target-min);
  width: 100%;
  padding: 0;
  border: 0;
  background: transparent;
  font-family: inherit;
  text-align: left;
}

.child-settings__member--tappable {
  cursor: pointer;
}

.child-settings__member--tappable:hover {
  background: var(--color-surface-2);
}

.child-settings__leave-hint {
  margin-bottom: var(--space-3);
}

.member-sheet {
  display: flex;
  flex-direction: column;
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

.invite-sheet__error {
  padding: var(--space-3);
  border-radius: var(--radius-card);
  background: var(--color-danger);
  color: var(--color-on-danger);
  font-size: var(--text-body);
  margin-bottom: var(--space-4);
}

.invite-sheet__hint {
  font-size: var(--text-meta);
  color: var(--color-ink-soft);
  margin: 0;
}
</style>
