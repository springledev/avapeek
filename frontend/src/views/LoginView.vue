<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { Shield, Eye, EyeOff, Mail, Lock, ArrowLeft } from '@lucide/vue'
import { useAuthStore, isMfaRequired, type MfaRequiredResponse } from '@/stores/auth'
import { getPendingInviteToken } from '@/lib/auth-storage'
import { api } from '@/services/api'
import UiButton from '@/components/ui/UiButton.vue'
import AuthCard from '@/components/AuthCard.vue'

const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()

const step = ref<1 | 2>(1)
const email = ref('')
const password = ref('')
const staySignedIn = ref(true)
const showPassword = ref(false)
const serverError = ref<string | null>(null)
const registrationMode = ref<'open' | 'invite_only' | null>(null)

const mfaState = ref<MfaRequiredResponse | null>(null)
const mfaCode = ref('')
const mfaMethod = ref<'totp' | 'recovery'>('totp')

const inviteToken = computed(() => getPendingInviteToken() || (route.query.invite_token as string))
const showRegisterLink = computed(
  () => registrationMode.value === 'open' || Boolean(inviteToken.value),
)

const registerLink = computed(() => {
  const query: Record<string, string> = {}
  const token = inviteToken.value
  if (token) query.invite_token = token
  const redirect = route.query.redirect as string
  if (redirect) query.redirect = redirect
  return { path: '/register', query }
})

onMounted(async () => {
  try {
    const { data } = await api.get<{ mode: 'open' | 'invite_only' }>('/auth/registration-mode')
    registrationMode.value = data.mode
  } catch {
    registrationMode.value = 'invite_only'
  }
})

function clearServerError() {
  serverError.value = null
  authStore.clearError()
}

async function submitStep1() {
  clearServerError()

  if (!email.value || !password.value) {
    serverError.value = 'Please enter your email and password.'
    return
  }

  try {
    const result = await authStore.login(email.value, password.value, staySignedIn.value)
    if (isMfaRequired(result)) {
      mfaState.value = result
      mfaCode.value = ''
      mfaMethod.value = 'totp'
      step.value = 2
      return
    }
    const redirect = (route.query.redirect as string) || '/'
    await router.push(redirect)
  } catch {
    serverError.value = authStore.error
  }
}

async function submitMfa() {
  clearServerError()

  if (!mfaState.value) {
    serverError.value = 'MFA session expired. Please sign in again.'
    step.value = 1
    return
  }

  if (mfaMethod.value === 'totp' && mfaCode.value.length !== 6) {
    serverError.value = 'Please enter the 6-digit code.'
    return
  }
  if (mfaMethod.value === 'recovery' && !mfaCode.value.trim()) {
    serverError.value = 'Please enter a recovery code.'
    return
  }

  try {
    await authStore.mfaChallenge(
      mfaState.value.mfa_token,
      mfaMethod.value,
      mfaCode.value,
      undefined,
      password.value,
    )
    const redirect = (route.query.redirect as string) || '/'
    await router.push(redirect)
  } catch {
    serverError.value = authStore.error
  }
}

function goBack() {
  step.value = 1
  mfaState.value = null
  mfaCode.value = ''
  clearServerError()
}

function sanitizeTotp(event: Event) {
  const input = event.target as HTMLInputElement
  const cleaned = input.value.replace(/\D/g, '').slice(0, 6)
  mfaCode.value = cleaned
}
</script>

<template>
  <AuthCard>
    <div v-if="step === 1" class="login__step">
      <div class="login__header">
        <h1 class="login__title">Welcome back</h1>
        <p class="login__subtitle">Sign in to your family album</p>
      </div>

      <div class="login__security">
        <Shield :size="20" aria-hidden="true" />
        <span>Your photos are locked on your device. We can never see them.</span>
      </div>

      <div v-if="route.query.reauth === '1'" class="login__warning" role="status">
        Your session expired. Please sign in again to access your photos.
      </div>

      <div v-if="serverError" class="login__error" role="alert">
        {{ serverError }}
      </div>

      <form class="login__form" @submit.prevent="submitStep1">
        <div class="login__field">
          <label class="login__label" for="login-email">Email</label>
          <div class="login__input-wrap">
            <Mail :size="18" class="login__input-icon" aria-hidden="true" />
            <input
              id="login-email"
              v-model="email"
              type="email"
              autocomplete="email"
              class="login__input login__input--with-icon"
              placeholder="you@example.com"
              :disabled="authStore.isLoading"
            />
          </div>
        </div>

        <div class="login__field">
          <label class="login__label" for="login-password">Password</label>
          <div class="login__input-wrap">
            <Lock :size="18" class="login__input-icon" aria-hidden="true" />
            <input
              id="login-password"
              v-model="password"
              :type="showPassword ? 'text' : 'password'"
              autocomplete="current-password"
              class="login__input login__input--with-icon login__input--padded-right"
              placeholder="••••••••"
              :disabled="authStore.isLoading"
            />
            <button
              type="button"
              class="login__visibility"
              :aria-label="showPassword ? 'Hide password' : 'Show password'"
              :disabled="authStore.isLoading"
              @click="showPassword = !showPassword"
            >
              <Eye v-if="!showPassword" :size="18" aria-hidden="true" />
              <EyeOff v-else :size="18" aria-hidden="true" />
            </button>
          </div>
        </div>

        <label class="login__checkbox">
          <input v-model="staySignedIn" type="checkbox" :disabled="authStore.isLoading" />
          <span>Keep me signed in</span>
        </label>

        <UiButton
          variant="accent"
          size="lg"
          type="submit"
          :loading="authStore.isLoading"
          class="login__submit"
        >
          Sign in
        </UiButton>
      </form>

      <div v-if="showRegisterLink" class="login__footer">
        <span class="login__footer-text">New here?</span>
        <router-link :to="registerLink" class="login__link">Create an account</router-link>
      </div>
    </div>

    <div v-else class="login__step">
      <button type="button" class="login__back" :disabled="authStore.isLoading" @click="goBack">
        <ArrowLeft :size="18" aria-hidden="true" />
        Back
      </button>

      <div class="login__header">
        <h1 class="login__title">Verify it's you</h1>
        <p class="login__subtitle">Enter the code from your authenticator app.</p>
      </div>

      <div v-if="serverError" class="login__error" role="alert">
        {{ serverError }}
      </div>

      <form class="login__form" @submit.prevent="submitMfa">
        <div v-if="mfaMethod === 'totp'" class="login__field">
          <label class="login__label" for="mfa-code">6-digit code</label>
          <input
            id="mfa-code"
            :value="mfaCode"
            type="text"
            inputmode="numeric"
            pattern="[0-9]*"
            autocomplete="one-time-code"
            class="login__input login__input--center"
            placeholder="000000"
            maxlength="6"
            autofocus
            :disabled="authStore.isLoading"
            @input="sanitizeTotp"
          />
        </div>

        <div v-else class="login__field">
          <label class="login__label" for="recovery-code">Recovery code</label>
          <input
            id="recovery-code"
            v-model="mfaCode"
            type="text"
            autocomplete="off"
            class="login__input"
            placeholder="Enter a recovery code"
            :disabled="authStore.isLoading"
          />
        </div>

        <UiButton
          variant="accent"
          size="lg"
          type="submit"
          :loading="authStore.isLoading"
          class="login__submit"
        >
          Verify
        </UiButton>
      </form>

      <div class="login__options">
        <button
          v-if="mfaState?.mfa_methods.includes('passkey')"
          type="button"
          class="login__option"
          :disabled="authStore.isLoading"
          @click="serverError = 'Passkey sign-in coming soon.'"
        >
          Use a passkey
        </button>
        <button
          type="button"
          class="login__option"
          :disabled="authStore.isLoading"
          @click="mfaMethod = mfaMethod === 'totp' ? 'recovery' : 'totp'"
        >
          {{ mfaMethod === 'totp' ? 'Use a recovery code' : 'Use authenticator code' }}
        </button>
      </div>
    </div>
  </AuthCard>
</template>

<style scoped>
.login__step {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.login__header {
  text-align: center;
}

.login__title {
  margin: 0;
  font-size: var(--text-title);
  font-weight: 800;
  line-height: var(--lh-title);
  color: var(--color-ink);
}

.login__subtitle {
  margin: var(--space-1) 0 0;
  color: var(--color-ink-soft);
  font-size: var(--text-body);
  line-height: var(--lh-body);
}

.login__security {
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

.login__security svg {
  flex-shrink: 0;
  margin-top: 0.125rem;
}

.login__warning,
.login__error {
  padding: var(--space-3);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-card);
  font-size: var(--text-body);
  line-height: var(--lh-body);
}

.login__warning {
  background: var(--color-accent-soft);
  color: var(--color-accent-ink);
}

.login__error {
  background: var(--color-surface);
  border-color: var(--color-danger);
  color: var(--color-danger);
}

.login__form {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.login__field {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.login__label {
  font-size: var(--text-body);
  font-weight: 600;
  color: var(--color-ink);
}

.login__input-wrap {
  position: relative;
  display: flex;
  align-items: center;
}

.login__input-icon {
  position: absolute;
  left: var(--space-3);
  color: var(--color-ink-soft);
  pointer-events: none;
}

.login__input {
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

.login__input--with-icon {
  padding-left: calc(var(--space-3) + 1.5rem);
}

.login__input--padded-right {
  padding-right: calc(var(--space-3) + 1.5rem);
}

.login__input--center {
  text-align: center;
  letter-spacing: 0.25em;
  font-variant-numeric: tabular-nums;
}

.login__input::placeholder {
  color: var(--color-ink-soft);
  opacity: 0.7;
}

.login__input:focus {
  outline: none;
  border-color: var(--color-accent);
  box-shadow: 0 0 0 3px var(--color-accent-soft);
}

.login__input:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.login__visibility {
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

.login__visibility:hover:not(:disabled) {
  color: var(--color-ink);
  background: var(--color-surface-2);
}

.login__visibility:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.login__checkbox {
  display: inline-flex;
  align-items: center;
  gap: var(--space-2);
  min-height: var(--target-min);
  color: var(--color-ink);
  font-size: var(--text-body);
  cursor: pointer;
}

.login__checkbox input {
  width: 1.25rem;
  height: 1.25rem;
  accent-color: var(--color-accent);
  cursor: pointer;
}

.login__checkbox input:disabled {
  cursor: not-allowed;
}

.login__submit {
  width: 100%;
}

.login__footer {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-1);
  font-size: var(--text-body);
}

.login__footer-text {
  color: var(--color-ink-soft);
}

.login__link {
  color: var(--color-accent-ink);
  font-weight: 700;
  text-decoration: none;
}

.login__link:hover {
  text-decoration: underline;
}

.login__back {
  display: inline-flex;
  align-items: center;
  gap: var(--space-1);
  align-self: flex-start;
  padding: var(--space-2) var(--space-2) var(--space-2) 0;
  border: 0;
  background: transparent;
  color: var(--color-ink-soft);
  font-family: inherit;
  font-size: var(--text-body);
  font-weight: 600;
  cursor: pointer;
}

.login__back:hover:not(:disabled) {
  color: var(--color-ink);
}

.login__back:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.login__options {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  align-items: center;
}

.login__option {
  border: 0;
  background: transparent;
  color: var(--color-accent-ink);
  font-family: inherit;
  font-size: var(--text-body);
  font-weight: 600;
  cursor: pointer;
  padding: var(--space-2);
  min-height: var(--target-min);
}

.login__option:hover:not(:disabled) {
  text-decoration: underline;
}

.login__option:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
</style>
