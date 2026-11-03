<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { toast } from 'vue-sonner'
import { useChildStore } from '@/stores/child'
import { useAuthStore } from '@/stores/auth'
import { usePendingGrants, type PendingGrant } from '@/composables/usePendingGrants'
import { apiErrorMessage } from '@/lib/util'
import UiButton from '@/components/ui/UiButton.vue'

/** Limits the list to one album; without it, every album the user administers is shown. */
const props = defineProps<{ childId?: number }>()
const emit = defineEmits<{ (e: 'changed'): void }>()

const childStore = useChildStore()
const authStore = useAuthStore()
const { grants, refresh, approve, decline } = usePendingGrants()

const busyGrant = ref<string | null>(null)
const busyAction = ref<'approve' | 'decline' | null>(null)

const administersAlbum = computed(() =>
  childStore.children.some((c) => c.role === 'owner' || c.role === 'admin'),
)

const visibleGrants = computed(() =>
  grants.value.filter((g) => props.childId === undefined || g.child_id === props.childId),
)

const message = computed(() => {
  const count = new Set(visibleGrants.value.map((g) => g.user_id)).size
  return count === 1
    ? '1 family member waiting for access'
    : `${count} family members waiting for access`
})

const grantKey = (grant: PendingGrant) => `${grant.child_id}:${grant.user_id}`

function albumName(grant: PendingGrant): string {
  return childStore.children.find((c) => c.id === grant.child_id)?.name ?? 'this album'
}

async function run(grant: PendingGrant, action: 'approve' | 'decline', task: () => Promise<void>) {
  if (busyGrant.value) return
  busyGrant.value = grantKey(grant)
  busyAction.value = action
  try {
    await task()
    emit('changed')
  } finally {
    busyGrant.value = null
    busyAction.value = null
  }
}

function onApprove(grant: PendingGrant) {
  return run(grant, 'approve', async () => {
    try {
      await approve(grant)
      toast.success(`${grant.display_name} can now see photos of ${albumName(grant)}.`)
    } catch (err) {
      toast.error(apiErrorMessage(err, "Couldn't share access — try again"))
    }
  })
}

function onDecline(grant: PendingGrant) {
  const confirmed = confirm(
    `Decline ${grant.display_name}? They will be removed from ${albumName(grant)} without getting access, and need a new invite to join again.`,
  )
  if (!confirmed) return
  return run(grant, 'decline', async () => {
    try {
      await decline(grant)
      toast.success(`${grant.display_name} was removed from ${albumName(grant)}.`)
    } catch (err) {
      toast.error(apiErrorMessage(err, "Couldn't decline — try again"))
    }
  })
}

let started = false
/** App.vue picks up this user's own keys; this only reads who is waiting, reusing a recent list. */
async function start() {
  if (started || !administersAlbum.value || !authStore.kek) return
  started = true
  await refresh()
}

onMounted(start)
watch([administersAlbum, () => authStore.kek], start)
</script>

<template>
  <div
    v-if="administersAlbum && visibleGrants.length > 0"
    data-testid="pending-access-banner"
    class="pending-access"
  >
    <p class="pending-access__text" role="status">{{ message }}</p>
    <p class="pending-access__hint">
      Only approve people you recognise. Approving shares the album's key with them. Declining
      removes them from the album.
    </p>
    <ul class="pending-access__list">
      <li
        v-for="grant in visibleGrants"
        :key="grantKey(grant)"
        class="pending-access__item"
        data-testid="pending-access-item"
      >
        <p class="pending-access__who">
          <strong>{{ grant.display_name }}</strong> wants to see photos of {{ albumName(grant) }}
        </p>
        <div class="pending-access__actions">
          <UiButton
            variant="accent"
            :loading="busyGrant === grantKey(grant) && busyAction === 'approve'"
            :disabled="busyGrant !== null"
            data-testid="pending-access-approve"
            @click="onApprove(grant)"
          >
            Approve
          </UiButton>
          <UiButton
            variant="subtle"
            :loading="busyGrant === grantKey(grant) && busyAction === 'decline'"
            :disabled="busyGrant !== null"
            data-testid="pending-access-decline"
            @click="onDecline(grant)"
          >
            Decline
          </UiButton>
        </div>
      </li>
    </ul>
  </div>
</template>

<style scoped>
.pending-access {
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
  padding: var(--space-3) var(--space-4);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-card);
  background: var(--color-surface-2);
  color: var(--color-ink);
}

.pending-access__text {
  margin: 0;
  font-size: var(--text-body);
  font-weight: 600;
}

.pending-access__hint {
  margin: 0;
  color: var(--color-ink-soft);
  font-size: var(--text-meta);
}

.pending-access__list {
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
  margin: 0;
  padding: 0;
  list-style: none;
}

.pending-access__item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: var(--space-2) var(--space-3);
}

.pending-access__who {
  margin: 0;
  overflow-wrap: anywhere;
  font-size: var(--text-body);
}

.pending-access__actions {
  display: flex;
  gap: var(--space-2);
}
</style>
