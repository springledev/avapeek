<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { toast } from 'vue-sonner'
import UiButton from '@/components/ui/UiButton.vue'

const authStore = useAuthStore()

const displayName = ref('')
const email = ref('')
const showPasswordSection = ref(false)
const currentPassword = ref('')
const newPassword = ref('')
const newPasswordConfirm = ref('')
const saving = ref(false)

onMounted(() => {
  if (authStore.user) {
    displayName.value = authStore.user.display_name
    email.value = authStore.user.email
  }
})

function resetPasswordFields() {
  currentPassword.value = ''
  newPassword.value = ''
  newPasswordConfirm.value = ''
  showPasswordSection.value = false
}

async function saveProfile() {
  saving.value = true
  try {
    const payload: Record<string, string> = {}
    if (displayName.value !== authStore.user?.display_name) {
      payload.display_name = displayName.value
    }
    if (email.value !== authStore.user?.email) {
      payload.email = email.value
    }
    if (newPassword.value) {
      payload.current_password = currentPassword.value
      payload.new_password = newPassword.value
      payload.new_password_confirmation = newPasswordConfirm.value
    }
    if (Object.keys(payload).length === 0) {
      toast.info('No changes to save.')
      return
    }
    await authStore.updateProfile(payload)
    toast.success('Profile updated successfully!')
    resetPasswordFields()
  } catch (err: unknown) {
    const message =
      err instanceof Error && 'response' in err
        ? (err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })
            .response?.data
        : undefined
    const msg =
      message?.message ||
      (message?.errors
        ? Object.values(message.errors).flat().join(', ')
        : 'Failed to update profile.')
    toast.error(msg)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="profile">
    <h2 class="profile__title">Profile</h2>

    <div class="profile__form">
      <div class="profile__field">
        <label class="profile__label" for="display-name">Display Name</label>
        <input id="display-name" v-model="displayName" type="text" class="profile__input" />
      </div>

      <div class="profile__field">
        <label class="profile__label" for="email">Email</label>
        <input id="email" v-model="email" type="email" class="profile__input" />
      </div>

      <div class="profile__divider">
        <button
          type="button"
          class="profile__toggle"
          @click="showPasswordSection = !showPasswordSection"
        >
          {{ showPasswordSection ? 'Cancel password change' : 'Change password' }}
        </button>

        <div v-if="showPasswordSection" class="profile__password-fields">
          <div class="profile__field">
            <label class="profile__label" for="current-password">Current Password</label>
            <input
              id="current-password"
              v-model="currentPassword"
              type="password"
              class="profile__input"
            />
          </div>
          <div class="profile__field">
            <label class="profile__label" for="new-password">New Password</label>
            <input id="new-password" v-model="newPassword" type="password" class="profile__input" />
          </div>
          <div class="profile__field">
            <label class="profile__label" for="new-password-confirm">Confirm New Password</label>
            <input
              id="new-password-confirm"
              v-model="newPasswordConfirm"
              type="password"
              class="profile__input"
            />
          </div>
        </div>
      </div>

      <UiButton variant="accent" :loading="saving" @click="saveProfile"> Save </UiButton>
    </div>
  </div>
</template>

<style scoped>
.profile {
  margin-top: var(--space-6);
}

.profile__title {
  font-size: var(--text-title);
  font-weight: 800;
  line-height: var(--lh-title);
  color: var(--color-ink);
  margin: 0 0 var(--space-4);
}

.profile__form {
  padding: var(--space-6);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-card);
  background: var(--color-surface);
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.profile__field {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.profile__label {
  font-size: var(--text-body);
  font-weight: 600;
  color: var(--color-ink);
}

.profile__input {
  padding: var(--space-3);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-card);
  background: var(--color-surface);
  color: var(--color-ink);
  font-family: inherit;
  font-size: var(--text-body);
  min-height: var(--target-min);
}

.profile__input:focus {
  outline: none;
  border-color: var(--color-accent);
  box-shadow: 0 0 0 2px var(--color-accent-soft);
}

.profile__divider {
  border-top: 1px solid var(--color-border);
  padding-top: var(--space-4);
}

.profile__toggle {
  border: 0;
  background: transparent;
  color: var(--color-accent-ink);
  font-family: inherit;
  font-size: var(--text-body);
  font-weight: 600;
  cursor: pointer;
  padding: 0;
}

.profile__toggle:hover {
  text-decoration: underline;
}

.profile__password-fields {
  margin-top: var(--space-3);
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
}
</style>
