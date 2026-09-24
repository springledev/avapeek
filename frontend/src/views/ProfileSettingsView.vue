<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { toast } from 'vue-sonner'
import UiButton from '@/components/ui/UiButton.vue'

const MIN_PASSWORD_LENGTH = 8

const authStore = useAuthStore()

const displayName = ref('')
const email = ref('')
const savingProfile = ref(false)

const showPasswordSection = ref(false)
const currentPassword = ref('')
const newPassword = ref('')
const newPasswordConfirm = ref('')
const changingPassword = ref(false)
const passwordError = ref<string | null>(null)

onMounted(() => {
  if (authStore.user) {
    displayName.value = authStore.user.display_name
    email.value = authStore.user.email
  }
})

/** Joins Laravel validation messages, falling back to the top-level message. */
function errorMessage(err: unknown, fallback: string): string {
  const data = (
    err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } } | null
  )?.response?.data
  if (data?.errors) return Object.values(data.errors).flat().join(' ')
  if (data?.message) return data.message
  return err instanceof Error && err.name === 'WrongPasswordError' ? err.message : fallback
}

function togglePasswordSection() {
  showPasswordSection.value = !showPasswordSection.value
  currentPassword.value = ''
  newPassword.value = ''
  newPasswordConfirm.value = ''
  passwordError.value = null
}

async function saveProfile() {
  const payload: { display_name?: string; email?: string } = {}
  if (displayName.value !== authStore.user?.display_name) {
    payload.display_name = displayName.value
  }
  if (email.value !== authStore.user?.email) {
    payload.email = email.value
  }
  if (Object.keys(payload).length === 0) {
    toast.info('No changes to save.')
    return
  }

  savingProfile.value = true
  try {
    await authStore.updateProfile(payload)
    toast.success('Profile updated.')
  } catch (err: unknown) {
    toast.error(errorMessage(err, 'Failed to update profile.'))
  } finally {
    savingProfile.value = false
  }
}

async function changePassword() {
  passwordError.value = null

  if (!currentPassword.value) {
    passwordError.value = 'Enter your current password.'
    return
  }
  if (newPassword.value.length < MIN_PASSWORD_LENGTH) {
    passwordError.value = `Your new password must be at least ${MIN_PASSWORD_LENGTH} characters long.`
    return
  }
  if (newPassword.value !== newPasswordConfirm.value) {
    passwordError.value = "The new passwords don't match."
    return
  }

  changingPassword.value = true
  try {
    await authStore.changePassword({
      currentPassword: currentPassword.value,
      newPassword: newPassword.value,
      newPasswordConfirmation: newPasswordConfirm.value,
    })
    toast.success('Password changed. Other devices have been signed out.')
    togglePasswordSection()
  } catch (err: unknown) {
    passwordError.value = errorMessage(err, 'Failed to change password.')
  } finally {
    changingPassword.value = false
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

      <UiButton variant="accent" :loading="savingProfile" @click="saveProfile">Save</UiButton>

      <div class="profile__divider">
        <button type="button" class="profile__toggle" @click="togglePasswordSection">
          {{ showPasswordSection ? 'Cancel password change' : 'Change password' }}
        </button>

        <form
          v-if="showPasswordSection"
          class="profile__password-fields"
          @submit.prevent="changePassword"
        >
          <p class="profile__hint">
            Your photos stay readable — their keys are re-locked with your new password on this
            device. Other devices will be signed out. We can't reset a forgotten password.
          </p>
          <div class="profile__field">
            <label class="profile__label" for="current-password">Current password</label>
            <input
              id="current-password"
              v-model="currentPassword"
              type="password"
              autocomplete="current-password"
              class="profile__input"
              :disabled="changingPassword"
            />
          </div>
          <div class="profile__field">
            <label class="profile__label" for="new-password">New password</label>
            <input
              id="new-password"
              v-model="newPassword"
              type="password"
              autocomplete="new-password"
              class="profile__input"
              :disabled="changingPassword"
            />
          </div>
          <div class="profile__field">
            <label class="profile__label" for="new-password-confirm">Confirm new password</label>
            <input
              id="new-password-confirm"
              v-model="newPasswordConfirm"
              type="password"
              autocomplete="new-password"
              class="profile__input"
              :disabled="changingPassword"
            />
          </div>
          <p v-if="passwordError" class="profile__error" role="alert">{{ passwordError }}</p>
          <UiButton variant="accent" type="submit" :loading="changingPassword">
            Change password
          </UiButton>
        </form>
      </div>
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

.profile__hint {
  margin: 0;
  color: var(--color-ink-soft);
  font-size: var(--text-body);
  line-height: var(--lh-body);
}

.profile__error {
  margin: 0;
  color: var(--color-danger);
  font-size: var(--text-body);
}
</style>
