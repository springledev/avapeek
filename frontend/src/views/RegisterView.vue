<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { Shield, Eye, EyeOff, Mail, Lock, User } from '@lucide/vue'
import { useAuthStore } from '@/stores/auth'
import { getPendingInviteToken, removePendingInviteToken } from '@/lib/auth-storage'
import { api } from '@/services/api'
import UiButton from '@/components/ui/UiButton.vue'
import AuthCard from '@/components/AuthCard.vue'

const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()

const registrationMode = ref<'open' | 'invite_only' | null>(null)
const invitePreview = ref<{ child_name: string; role: string; inviter_name: string } | null>(null)
const invitePreviewError = ref(false)

const inviteToken = computed(
  () => (route.query.invite_token as string) || getPendingInviteToken() || undefined,
)

const displayName = ref('')
const email = ref('')
const password = ref('')
const staySignedIn = ref(true)
const showPassword = ref(false)
const serverError = ref<string | null>(null)
const fieldErrors = ref<Record<string, string>>({})

onMounted(async () => {
  try {
    const { data } = await api.get<{ mode: 'open' | 'invite_only' }>('/auth/registration-mode')
    registrationMode.value = data.mode
  } catch {
    registrationMode.value = 'invite_only'
  }

  if (inviteToken.value) {
    try {
      const { data } = await api.get<{
        child_name: string
        role: string
        inviter_name: string
      }>(`/invites/${inviteToken.value}`)
      invitePreview.value = data
    } catch {
      invitePreviewError.value = true
    }
  }
})

const isGated = computed(() => registrationMode.value === 'invite_only' && !inviteToken.value)

const heading = computed(() => (inviteToken.value ? "You're invited" : 'Create your account'))

const passwordStrength = computed(() => {
  const pwd = password.value
  if (!pwd) return { level: 0, text: '', color: '', width: '0%' }

  let strength = 0
  if (pwd.length >= 8) strength++
  if (pwd.length >= 12) strength++
  if (/[a-z]/.test(pwd) && /[A-Z]/.test(pwd)) strength++
  if (/\d/.test(pwd)) strength++
  if (/[^a-zA-Z0-9]/.test(pwd)) strength++

  if (strength <= 2) {
    return { level: strength, text: 'Weak', color: 'var(--color-danger)', width: `${(strength / 5) * 100}%` }
  }
  if (strength === 3) {
    return { level: strength, text: 'Fair', color: 'var(--color-accent)', width: `${(strength / 5) * 100}%` }
  }
  if (strength === 4) {
    return { level: strength, text: 'Good', color: 'var(--color-accent)', width: `${(strength / 5) * 100}%` }
  }
  return { level: strength, text: 'Strong', color: 'var(--color-success)', width: `${(strength / 5) * 100}%` }
})

function validateForm(): boolean {
  fieldErrors.value = {}

  if (!displayName.value.trim()) {
    fieldErrors.value.displayName = 'Display name is required'
  }

  if (!email.value) {
    fieldErrors.value.email = 'Email is required'
  } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value)) {
    fieldErrors.value.email = 'Please enter a valid email address'
  }

  if (!password.value) {
    fieldErrors.value.password = 'Password is required'
  } else if (password.value.length < 8) {
    fieldErrors.value.password = 'Password must be at least 8 characters long'
  }

  return Object.keys(fieldErrors.value).length === 0
}

async function handleSubmit() {
  serverError.value = null
  authStore.clearError()

  if (!validateForm()) {
    return
  }

  try {
    await authStore.register(
      email.value,
      password.value,
      displayName.value,
      inviteToken.value,
      staySignedIn.value,
    )
    removePendingInviteToken()
    const redirect = (route.query.redirect as string) || '/'
    await router.push(redirect)
  } catch {
    serverError.value = authStore.error
  }
}
</script>

<template>
  <AuthCard>
    <div v-if="isGated" class="register__step">
      <div class="register__header">
        <h1 class="register__title">You need an invitation</h1>
        <p class="register__subtitle">
          Avapeek is a private family album. Ask a family member to send you an invite link.
        </p>
      </div>
      <div class="register__footer">
        <router-link to="/login" class="register__link">I have an account</router-link>
      </div>
    </div>
    <div v-else class="register__step">
      <div class="register__header">
        <h1 class="register__title">{{ heading }}</h1>
        <p class="register__subtitle">Join Avapeek and start sharing photos securely</p>
      </div>
      <div v-if="inviteToken && invitePreview" class="register__invite">
        You're joining <strong>{{ invitePreview.child_name }}'s album</strong> as a
        {{ invitePreview.role }}, invited by {{ invitePreview.inviter_name }}.
      </div>
      <div v-if="inviteToken && invitePreviewError" class="register__warning" role="status">
        We couldn't load the invitation details, but you can still create your account.
      </div>
      <div class="register__security">
        <Shield :size="20" aria-hidden="true" />
        <span>Your encryption keys are generated locally and never leave your device.</span>
      </div>
      <div v-if="serverError" class="register__error" role="alert">{{ serverError }}</div>
      <form class="register__form" @submit.prevent="handleSubmit">
        <div class="register__field">
          <label class="register__label" for="register-name">Display name</label>
          <div class="register__input-wrap">
            <User :size="18" class="register__input-icon" aria-hidden="true" />
            <input
              id="register-name"
              v-model="displayName"
              type="text"
              autocomplete="name"
              class="register__input register__input--with-icon"
              :class="{ 'register__input--invalid': fieldErrors.displayName }"
              placeholder="John Doe"
              :disabled="authStore.isLoading"
            />
          </div>
          <p v-if="fieldErrors.displayName" class="register__field-error">
            {{ fieldErrors.displayName }}
          </p>
        </div>

        <div class="register__field">
          <label class="register__label" for="register-email">Email</label>
          <div class="register__input-wrap">
            <Mail :size="18" class="register__input-icon" aria-hidden="true" />
            <input
              id="register-email"
              v-model="email"
              type="email"
              autocomplete="email"
              class="register__input register__input--with-icon"
              :class="{ 'register__input--invalid': fieldErrors.email }"
              placeholder="you@example.com"
              :disabled="authStore.isLoading"
            />
          </div>
          <p v-if="fieldErrors.email" class="register__field-error">{{ fieldErrors.email }}</p>
        </div>

        <div class="register__field">
          <label class="register__label" for="register-password">Password</label>
          <div class="register__input-wrap">
            <Lock :size="18" class="register__input-icon" aria-hidden="true" />
            <input
              id="register-password"
              v-model="password"
              :type="showPassword ? 'text' : 'password'"
              autocomplete="new-password"
              class="register__input register__input--with-icon register__input--padded-right"
              :class="{ 'register__input--invalid': fieldErrors.password }"
              placeholder="••••••••"
              :disabled="authStore.isLoading"
            />
            <button
              type="button"
              class="register__visibility"
              :aria-label="showPassword ? 'Hide password' : 'Show password'"
              :disabled="authStore.isLoading"
              @click="showPassword = !showPassword"
            >
              <Eye v-if="!showPassword" :size="18" aria-hidden="true" />
              <EyeOff v-else :size="18" aria-hidden="true" />
            </button>
          </div>
          <div v-if="password" class="register__strength">
            <div class="register__strength-track">
              <div
                class="register__strength-bar"
                :style="{ width: passwordStrength.width, background: passwordStrength.color }"
              />
            </div>
            <span class="register__strength-text" :style="{ color: passwordStrength.color }">
              {{ passwordStrength.text }}
            </span>
          </div>
          <p v-if="fieldErrors.password" class="register__field-error">{{ fieldErrors.password }}</p>
        </div>

        <label class="register__checkbox">
          <input v-model="staySignedIn" type="checkbox" :disabled="authStore.isLoading" />
          <span>Keep me signed in</span>
        </label>

        <UiButton
          variant="accent"
          size="lg"
          type="submit"
          :loading="authStore.isLoading"
          class="register__submit"
        >
          Create account
        </UiButton>
      </form>
      <div class="register__footer">
        <span class="register__footer-text">Already have an account?</span>
        <router-link to="/login" class="register__link">Sign in</router-link>
      </div>
    </div>
  </AuthCard>
</template>

<style scoped>
.register__step {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.register__header {
  text-align: center;
}

.register__title {
  margin: 0;
  font-size: var(--text-title);
  font-weight: 800;
  line-height: var(--lh-title);
  color: var(--color-ink);
}

.register__subtitle {
  margin: var(--space-1) 0 0;
  color: var(--color-ink-soft);
  font-size: var(--text-body);
  line-height: var(--lh-body);
}

.register__invite {
  padding: var(--space-3);
  border-radius: var(--radius-card);
  background: var(--color-accent-soft);
  color: var(--color-accent-ink);
  font-size: var(--text-body);
  line-height: var(--lh-body);
}

.register__invite strong {
  color: var(--color-ink);
}

.register__warning {
  padding: var(--space-3);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-card);
  background: var(--color-accent-soft);
  color: var(--color-accent-ink);
  font-size: var(--text-body);
  line-height: var(--lh-body);
}

.register__security {
  display: flex;
  align-items: flex-start;
  gap: var(--space-2);
  padding: var(--space-3);
  border-radius: var(--radius-card);
  background: var(--color-accent-soft);
  color: var(--color-accent-ink);
  font-size: var(--text-body);
  line-height: var(--lh-body);
}

.register__security svg {
  flex-shrink: 0;
  margin-top: 0.125rem;
}

.register__error {
  padding: var(--space-3);
  border: 1px solid var(--color-danger);
  border-radius: var(--radius-card);
  background: var(--color-surface);
  color: var(--color-danger);
  font-size: var(--text-body);
  line-height: var(--lh-body);
}

.register__form {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.register__field {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.register__label {
  font-size: var(--text-body);
  font-weight: 600;
  color: var(--color-ink);
}

.register__input-wrap {
  position: relative;
  display: flex;
  align-items: center;
}

.register__input-icon {
  position: absolute;
  left: var(--space-3);
  color: var(--color-ink-soft);
  pointer-events: none;
}

.register__input {
  width: 100%;
  min-height: var(--target-min);
  padding: var(--space-3);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-card);
  background: var(--color-surface);
  color: var(--color-ink);
  font-family: inherit;
  font-size: var(--text-body);
  line-height: var(--lh-body);
}

.register__input--with-icon {
  padding-left: calc(var(--space-3) + 1.5rem);
}

.register__input--padded-right {
  padding-right: calc(var(--space-3) + 1.5rem);
}

.register__input::placeholder {
  color: var(--color-ink-soft);
  opacity: 0.7;
}

.register__input:focus {
  outline: none;
  border-color: var(--color-accent);
  box-shadow: 0 0 0 3px var(--color-accent-soft);
}

.register__input--invalid {
  border-color: var(--color-danger);
}

.register__input--invalid:focus {
  border-color: var(--color-danger);
  box-shadow: 0 0 0 3px var(--color-accent-soft);
}

.register__input:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.register__visibility {
  position: absolute;
  right: var(--space-1);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: var(--target-min);
  height: var(--target-min);
  border: 0;
  border-radius: var(--radius-card);
  background: transparent;
  color: var(--color-ink-soft);
  cursor: pointer;
}

.register__visibility:hover:not(:disabled) {
  color: var(--color-ink);
  background: var(--color-surface-2);
}

.register__visibility:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.register__field-error {
  margin: 0;
  color: var(--color-danger);
  font-size: var(--text-body);
  line-height: var(--lh-body);
}

.register__strength {
  display: flex;
  align-items: center;
  gap: var(--space-2);
}

.register__strength-track {
  flex: 1;
  height: 0.5rem;
  border-radius: var(--radius-pill);
  background: var(--color-surface-2);
  overflow: hidden;
}

.register__strength-bar {
  height: 100%;
  border-radius: var(--radius-pill);
  transition: width var(--dur-micro) var(--ease-soft);
}

.register__strength-text {
  font-size: var(--text-body);
  font-weight: 700;
}

.register__checkbox {
  display: inline-flex;
  align-items: center;
  gap: var(--space-2);
  min-height: var(--target-min);
  color: var(--color-ink);
  font-size: var(--text-body);
  cursor: pointer;
}

.register__checkbox input {
  width: 1.25rem;
  height: 1.25rem;
  accent-color: var(--color-accent);
  cursor: pointer;
}

.register__checkbox input:disabled {
  cursor: not-allowed;
}

.register__submit {
  width: 100%;
}

.register__footer {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-1);
  font-size: var(--text-body);
}

.register__footer-text {
  color: var(--color-ink-soft);
}

.register__link {
  color: var(--color-accent-ink);
  font-weight: 700;
  text-decoration: none;
}

.register__link:hover {
  text-decoration: underline;
}
</style>
