<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Images } from '@lucide/vue'
import { useAuthStore } from '@/stores/auth'
import { useChildStore } from '@/stores/child'
import { syncEncryptionKeys } from '@/services/key-sync'
import { api } from '@/services/api'
import { setPendingInviteToken, removePendingInviteToken } from '@/lib/auth-storage'
import type { InvitePreview } from '@/types/child'
import { apiErrorMessage } from '@/lib/util'
import UiButton from '@/components/ui/UiButton.vue'
import AuthCard from '@/components/AuthCard.vue'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const childStore = useChildStore()

const token = route.params.token as string
const invite = ref<InvitePreview | null>(null)
const isLoading = ref(true)
const isAccepting = ref(false)
const error = ref<string | null>(null)

const roleWithArticle = computed(() => {
  const role = invite.value?.role ?? ''
  return `${/^[aeiou]/i.test(role) ? 'an' : 'a'} ${role}`
})

async function loadPreview() {
  isLoading.value = true
  error.value = null

  try {
    const response = await api.get<{ data: InvitePreview }>(`/invites/${token}`)
    invite.value = response.data.data
    setPendingInviteToken(token)
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
    removePendingInviteToken()
    error.value = data?.message || 'This invite is invalid or has expired.'
  } finally {
    isLoading.value = false
  }
}

async function acceptInvite() {
  if (!authStore.isAuthenticated) {
    goToLogin()
    return
  }

  isAccepting.value = true
  error.value = null

  try {
    await api.post<{ data: { child_id: number; child_slug: string; child_name: string } }>(
      `/invites/${token}/accept`,
    )

    removePendingInviteToken()
    // Show the new album and pick up its key right away if an admin has already shared it.
    void childStore
      .fetchChildren({ force: true })
      .catch(() => undefined)
      .then(() => syncEncryptionKeys())
    router.push('/welcome')
  } catch (err) {
    error.value = apiErrorMessage(err, 'Failed to accept invite.')
    isAccepting.value = false
  }
}

/** After signing in, come back and accept straight away: the user already chose to join. */
function goToLogin() {
  router.push({ name: 'login', query: { redirect: `/invite/${token}?accept=1` } })
}

function goToRegister() {
  router.push({ name: 'register', query: { invite_token: token, redirect: `/invite/${token}` } })
}

function leave() {
  router.push(authStore.isAuthenticated ? '/' : '/login')
}

onMounted(async () => {
  await loadPreview()
  if (invite.value && authStore.isAuthenticated && route.query.accept === '1') {
    await acceptInvite()
  }
})
</script>

<template>
  <AuthCard>
    <div v-if="isLoading" class="invite__loading" aria-busy="true" aria-label="Loading invite" />

    <div v-else-if="error && !invite" class="invite__step">
      <div class="invite__header">
        <h1 class="invite__title">This invite can't be used</h1>
        <p class="invite__subtitle">{{ error }} Ask a family member to send you a new link.</p>
      </div>
      <UiButton variant="subtle" size="lg" class="invite__action" @click="leave">
        Go to Avapeek
      </UiButton>
    </div>

    <div v-else-if="invite" class="invite__step">
      <div class="invite__icon" aria-hidden="true">
        <Images :size="28" />
      </div>

      <div class="invite__header">
        <h1 class="invite__title">You're invited</h1>
        <p class="invite__subtitle">
          {{ invite.inviter_name }} invited you to see photos of
          <strong>{{ invite.child_name }}</strong>
          <span v-if="invite.relationship"> as their {{ invite.relationship.toLowerCase() }}</span>.
        </p>
      </div>

      <div v-if="invite.role" class="invite__card">
        You'll join {{ invite.child_name }}'s album as {{ roleWithArticle }}<template
          v-if="invite.role === 'member' && invite.can_upload === false"
        >
          who can view and comment</template
        >.
      </div>

      <div v-if="error" class="invite__error" role="alert">{{ error }}</div>

      <template v-if="!authStore.isAuthenticated">
        <p class="invite__hint">You need an account to accept this invite.</p>
        <div class="invite__actions">
          <UiButton variant="accent" size="lg" class="invite__action" @click="goToRegister">
            Create an account
          </UiButton>
          <UiButton variant="subtle" size="lg" class="invite__action" @click="goToLogin">
            I already have an account
          </UiButton>
        </div>
      </template>

      <template v-else>
        <p class="invite__hint">
          Signed in as <strong>{{ authStore.currentUser?.display_name }}</strong>.
        </p>
        <UiButton
          variant="accent"
          size="lg"
          class="invite__action"
          :loading="isAccepting"
          @click="acceptInvite"
        >
          Accept invite
        </UiButton>
        <p class="invite__fineprint">
          Photos stay encrypted. They appear once an album admin opens Avapeek and shares the key
          with you — until then the album will look empty. We'll notify you as soon as you have
          access.
        </p>
      </template>
    </div>
  </AuthCard>
</template>

<style scoped>
.invite__loading {
  min-height: 16rem;
}

.invite__step {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.invite__icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 3.5rem;
  height: 3.5rem;
  margin: 0 auto;
  border-radius: 50%;
  background: var(--color-accent-soft);
  color: var(--color-accent-ink);
}

.invite__header {
  text-align: center;
}

.invite__title {
  margin: 0;
  font-size: var(--text-title);
  font-weight: 800;
  line-height: var(--lh-title);
  color: var(--color-ink);
}

.invite__subtitle {
  margin: var(--space-1) 0 0;
  color: var(--color-ink-soft);
  font-size: var(--text-body);
  line-height: var(--lh-body);
}

.invite__subtitle strong,
.invite__hint strong {
  color: var(--color-ink);
}

.invite__card {
  padding: var(--space-3);
  border-radius: var(--radius-card);
  background: var(--color-accent-soft);
  color: var(--color-accent-ink);
  font-size: var(--text-body);
  line-height: var(--lh-body);
}

.invite__error {
  padding: var(--space-3);
  border: 1px solid var(--color-danger);
  border-radius: var(--radius-card);
  background: var(--color-surface);
  color: var(--color-danger);
  font-size: var(--text-body);
  line-height: var(--lh-body);
}

.invite__hint {
  margin: 0;
  text-align: center;
  color: var(--color-ink-soft);
  font-size: var(--text-body);
  line-height: var(--lh-body);
}

.invite__actions {
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
}

.invite__action {
  width: 100%;
}

.invite__fineprint {
  margin: 0;
  text-align: center;
  color: var(--color-ink-soft);
  font-size: var(--text-body);
  line-height: var(--lh-body);
}
</style>
