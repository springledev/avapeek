<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import {
  ShieldCheck,
  Smartphone,
  KeyRound,
  RefreshCw,
  Copy,
  Download,
  Check,
  Trash2,
} from '@lucide/vue'
import QRCode from 'qrcode'
import { startRegistration } from '@simplewebauthn/browser'
import type { PublicKeyCredentialCreationOptionsJSON } from '@simplewebauthn/types'
import { api } from '@/services/api'
import { toast } from 'vue-sonner'
import { apiErrorMessage } from '@/lib/util'
import UiButton from '@/components/ui/UiButton.vue'
import UiSheet from '@/components/ui/UiSheet.vue'
import PasswordConfirmSheet from '@/components/PasswordConfirmSheet.vue'
import type { MfaMethodsResponse } from '@/types/auth'

type TotpStep = 'setup' | 'codes' | null
type PasswordAction =
  | { type: 'setup-totp' }
  | { type: 'add-passkey' }
  | { type: 'disable-totp' }
  | { type: 'remove-passkey'; id: string }
  | { type: 'regenerate-recovery' }

const methods = ref<MfaMethodsResponse | null>(null)

const totpSheetOpen = ref(false)
const totpStep = ref<TotpStep>(null)
const qrDataUrl = ref('')
const totpSecret = ref('')
const confirmCode = ref('')
const totpLoading = ref(false)

const recoverySheetOpen = ref(false)
/** 'replaced' when earlier codes were invalidated, 'new' for a first set. */
const recoverySheetReason = ref<'new' | 'replaced'>('new')
const recoveryCodes = ref<string[]>([])

const passkeyName = ref('')
const registeringPasskey = ref(false)
const passkeySheetOpen = ref(false)
const passkeyOptions = ref<PublicKeyCredentialCreationOptionsJSON | null>(null)

const passwordAction = ref<PasswordAction | null>(null)
const passwordLoading = ref(false)
const passwordError = ref<string | null>(null)

const mfaEnabled = computed(() => (methods.value?.methods.length ?? 0) > 0)
const hasTotp = computed(() => methods.value?.methods.includes('totp') ?? false)
const recoveryCodesRemaining = computed(() => methods.value?.recovery_codes_remaining ?? 0)
const recoveryDescription = computed(() => {
  if (!mfaEnabled.value) return 'Single-use codes to get back in if you lose your device.'
  const count = recoveryCodesRemaining.value
  if (count === 0) return 'You have no recovery codes left. Generate a new set.'
  return `${count} single-use ${count === 1 ? 'code' : 'codes'} left to get back in.`
})
const passkeys = computed(() => methods.value?.passkeys ?? [])

const passwordOpen = computed(() => passwordAction.value !== null)

/** Wording and tone for the password prompt of each action. */
const passwordPrompt = computed(() => {
  switch (passwordAction.value?.type) {
    case 'setup-totp':
      return { title: 'Set up authenticator', confirmLabel: 'Continue', destructive: false, description: '' }
    case 'add-passkey':
      return { title: 'Add a passkey', confirmLabel: 'Continue', destructive: false, description: '' }
    case 'disable-totp':
      return {
        title: 'Turn off authenticator',
        confirmLabel: 'Turn off',
        destructive: true,
        description: 'Signing in will no longer ask for an authenticator code.',
      }
    case 'remove-passkey':
      return {
        title: 'Remove passkey',
        confirmLabel: 'Remove',
        destructive: true,
        description: "You won't be able to sign in with this passkey any more.",
      }
    case 'regenerate-recovery':
      return {
        title: 'Get new recovery codes',
        confirmLabel: 'Get new codes',
        destructive: false,
        description: 'Your current recovery codes will stop working.',
      }
    default:
      return { title: 'Confirm password', confirmLabel: 'Confirm', destructive: false, description: '' }
  }
})

/** Recovery codes are shown in groups of four so they're easier to copy by hand. */
function formatRecoveryCode(code: string): string {
  return code.match(/.{1,4}/g)?.join('-') ?? code
}

const formattedRecoveryCodes = computed(() => recoveryCodes.value.map(formatRecoveryCode))

async function fetchMethods() {
  try {
    const { data } = await api.get<MfaMethodsResponse>('/auth/mfa/methods')
    methods.value = data
  } catch (err: unknown) {
    toast.error(apiErrorMessage(err, 'Failed to load security settings.'))
  }
}

async function startTotpSetup(password: string) {
  const { data } = await api.post<{ secret: string; otpauth_uri: string }>('/auth/mfa/totp/setup', {
    current_password: password,
  })
  totpSecret.value = data.secret
  qrDataUrl.value = await QRCode.toDataURL(data.otpauth_uri)
  confirmCode.value = ''
  recoveryCodes.value = []
  totpStep.value = 'setup'
  totpSheetOpen.value = true
}

watch(confirmCode, (value) => {
  const digits = value.replace(/\D/g, '').slice(0, 6)
  if (digits !== value) confirmCode.value = digits
})

async function confirmTotp() {
  if (confirmCode.value.length !== 6) {
    toast.error('Enter the 6-digit code from your authenticator app.')
    return
  }
  totpLoading.value = true
  try {
    const { data } = await api.post<{ recovery_codes: string[] | null }>(
      '/auth/mfa/totp/confirm',
      { code: confirmCode.value },
    )
    await fetchMethods()
    if (data.recovery_codes) {
      recoveryCodes.value = data.recovery_codes
      totpStep.value = 'codes'
    } else {
      toast.success('Authenticator turned on. Your existing recovery codes still work.')
      closeTotpSheet()
    }
  } catch (err: unknown) {
    toast.error(apiErrorMessage(err, "That code didn't work. Try the newest code in your app."))
  } finally {
    totpLoading.value = false
  }
}

/** Closing is blocked while recovery codes are showing, so they can't be lost by a stray tap. */
function onTotpSheetClose() {
  if (totpStep.value !== 'codes') closeTotpSheet()
}

function closeTotpSheet() {
  totpSheetOpen.value = false
  totpStep.value = null
  qrDataUrl.value = ''
  totpSecret.value = ''
  confirmCode.value = ''
  recoveryCodes.value = []
}

function openSetupTotp() {
  passwordAction.value = { type: 'setup-totp' }
}

function openDisableTotp() {
  passwordAction.value = { type: 'disable-totp' }
}

function openAddPasskey() {
  passwordAction.value = { type: 'add-passkey' }
}

/**
 * Runs from the "Create passkey" tap so the WebAuthn prompt keeps its user
 * gesture; the password was already checked when the options were issued.
 */
async function createPasskey() {
  if (!passkeyOptions.value) return
  registeringPasskey.value = true
  try {
    const response = await startRegistration({ optionsJSON: passkeyOptions.value })
    const { data: verifyData } = await api.post<{
      passkey_id: string
      recovery_codes?: string[] | null
    }>('/auth/mfa/passkeys/register/verify', {
      response,
      user_chosen_name: passkeyName.value.trim() || null,
    })
    toast.success('Passkey added.')
    closePasskeySheet()
    if (verifyData.recovery_codes) {
      recoveryCodes.value = verifyData.recovery_codes
      recoverySheetReason.value = 'new'
      recoverySheetOpen.value = true
    }
    await fetchMethods()
  } catch (err: unknown) {
    if (err instanceof Error && err.name === 'NotAllowedError') {
      toast.error('Passkey setup was cancelled.')
    } else {
      toast.error(apiErrorMessage(err, 'Failed to add passkey.'))
    }
  } finally {
    registeringPasskey.value = false
  }
}

function closePasskeySheet() {
  passkeySheetOpen.value = false
  passkeyOptions.value = null
  passkeyName.value = ''
}

function openRemovePasskey(id: string) {
  passwordAction.value = { type: 'remove-passkey', id }
}

function openRegenerateRecovery() {
  passwordAction.value = { type: 'regenerate-recovery' }
}

watch(passwordAction, () => {
  passwordError.value = null
})

/** Pulls the "current password is incorrect" message out of a 422 so it can show inline. */
function currentPasswordError(err: unknown): string | null {
  const errors = (err as { response?: { data?: { errors?: Record<string, string[]> } } } | null)
    ?.response?.data?.errors
  return errors?.current_password?.[0] ?? null
}

async function onPasswordConfirm(password: string) {
  const action = passwordAction.value
  if (!action) return
  passwordLoading.value = true
  passwordError.value = null
  try {
    switch (action.type) {
      case 'setup-totp':
        await startTotpSetup(password)
        break
      case 'add-passkey':
        {
          const { data } = await api.post<{ options: PublicKeyCredentialCreationOptionsJSON }>(
            '/auth/mfa/passkeys/register/options',
            { current_password: password },
          )
          passkeyOptions.value = data.options
          passkeySheetOpen.value = true
        }
        break
      case 'disable-totp':
        await api.delete('/auth/mfa/totp', { data: { current_password: password } })
        toast.success('Authenticator turned off.')
        break
      case 'remove-passkey':
        await api.delete(`/auth/mfa/passkeys/${action.id}`, {
          data: { current_password: password },
        })
        toast.success('Passkey removed.')
        break
      case 'regenerate-recovery':
        {
          const { data } = await api.post<{ recovery_codes: string[] }>('/auth/mfa/recovery-codes', {
            current_password: password,
          })
          recoveryCodes.value = data.recovery_codes
          recoverySheetReason.value = 'replaced'
          recoverySheetOpen.value = true
        }
        break
    }
    passwordAction.value = null
    await fetchMethods()
  } catch (err: unknown) {
    const inlineError = currentPasswordError(err)
    if (inlineError) {
      passwordError.value = inlineError
    } else {
      toast.error(apiErrorMessage(err, 'Something went wrong. Please try again.'))
    }
  } finally {
    passwordLoading.value = false
  }
}

function copyCodes() {
  const text = formattedRecoveryCodes.value.join('\n')
  navigator.clipboard
    .writeText(text)
    .then(() => toast.success('Recovery codes copied.'))
    .catch(() => toast.error('Failed to copy recovery codes.'))
}

function downloadCodes() {
  const text = `Avapeek recovery codes\nEach code works once.\n\n${formattedRecoveryCodes.value.join('\n')}\n`
  const blob = new Blob([text], { type: 'text/plain' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = 'avapeek-recovery-codes.txt'
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
  URL.revokeObjectURL(url)
}

function closeRecoverySheet() {
  recoverySheetOpen.value = false
  recoveryCodes.value = []
}

function lastUsedLabel(value: string | null): string {
  if (!value) return 'Not used yet'
  return `Last used ${new Date(value).toLocaleString()}`
}

onMounted(fetchMethods)
</script>

<template>
  <div class="security">
    <h2 class="security__title">Security</h2>

    <div class="security__section">
      <div class="security__status">
        <div class="security__status-icon">
          <ShieldCheck :size="20" />
        </div>
        <div class="security__status-text">
          <p class="security__status-title">
            {{ mfaEnabled ? 'Extra lock is on' : 'Add an extra lock on your account' }}
          </p>
          <p v-if="!mfaEnabled" class="security__status-desc">
            Turn on an authenticator app or a passkey to protect your photos.
          </p>
          <p v-else class="security__status-desc">
            Enabled methods:
            {{ methods?.methods.map((m) => (m === 'totp' ? 'authenticator' : m)).join(', ') }}
          </p>
        </div>
      </div>

    </div>

    <div class="security__section">
      <div class="security__section-header">
        <div class="security__section-icon">
          <Smartphone :size="20" />
        </div>
        <div>
          <h3 class="security__section-title">Authenticator app</h3>
          <p class="security__section-desc">Use a code from an app like Google Authenticator.</p>
        </div>
      </div>

      <div v-if="hasTotp" class="security__method-state">
        <span class="security__method-badge">Enabled</span>
        <UiButton variant="ghost" size="md" @click="openDisableTotp">Turn off</UiButton>
      </div>
      <UiButton v-else variant="accent" @click="openSetupTotp">Set up authenticator</UiButton>
    </div>

    <div class="security__section">
      <div class="security__section-header">
        <div class="security__section-icon">
          <KeyRound :size="20" />
        </div>
        <div>
          <h3 class="security__section-title">Passkeys</h3>
          <p class="security__section-desc">Sign in with your device’s fingerprint or face.</p>
        </div>
      </div>

      <UiButton variant="accent" :loading="registeringPasskey" @click="openAddPasskey">
        Add a passkey
      </UiButton>

      <ul v-if="passkeys.length > 0" class="security__passkey-list">
        <li v-for="passkey in passkeys" :key="passkey.id" class="security__passkey-item">
          <div class="security__passkey-info">
            <p class="security__passkey-name">{{ passkey.name || 'Unnamed passkey' }}</p>
            <p class="security__passkey-meta">{{ lastUsedLabel(passkey.last_used_at) }}</p>
          </div>
          <UiButton
            variant="ghost"
            size="md"
            :aria-label="`Remove ${passkey.name || 'passkey'}`"
            @click="openRemovePasskey(passkey.id)"
          >
            <Trash2 :size="18" />
          </UiButton>
        </li>
      </ul>
    </div>

    <div class="security__section">
      <div class="security__section-header">
        <div class="security__section-icon">
          <RefreshCw :size="20" />
        </div>
        <div>
          <h3 class="security__section-title">Recovery codes</h3>
          <p class="security__section-desc">
            {{ recoveryDescription }}
          </p>
        </div>
      </div>

      <UiButton v-if="mfaEnabled" variant="subtle" @click="openRegenerateRecovery">
        Get new codes
      </UiButton>
      <p v-else class="security__hint">Enable authenticator or a passkey first.</p>
    </div>

    <UiSheet
      :open="totpSheetOpen"
      :title="totpStep === 'codes' ? 'Save your recovery codes' : 'Set up authenticator'"
      @close="onTotpSheetClose"
    >
      <div v-if="totpStep === 'setup'" class="security__totp-setup">
        <p class="security__totp-instruction">
          Scan the QR code with your authenticator app, then enter the 6-digit code.
        </p>

        <img v-if="qrDataUrl" :src="qrDataUrl" alt="Authenticator QR code" class="security__qr" />

        <div class="security__secret">
          <p class="security__secret-label">Can't scan? Enter this code manually:</p>
          <code class="security__secret-value">{{ totpSecret }}</code>
        </div>

        <div class="security__field">
          <label class="security__label" for="totp-code">Verification code</label>
          <input
            id="totp-code"
            v-model="confirmCode"
            type="text"
            inputmode="numeric"
            autocomplete="one-time-code"
            class="security__input"
            placeholder="000000"
            @keydown.enter.prevent="confirmTotp"
          />
        </div>

        <div class="security__sheet-actions">
          <UiButton variant="ghost" @click="closeTotpSheet">Cancel</UiButton>
          <UiButton variant="accent" :loading="totpLoading" @click="confirmTotp">Confirm</UiButton>
        </div>
      </div>

      <div v-else-if="totpStep === 'codes'" class="security__codes">
        <p class="security__codes-desc">
          Save these codes somewhere safe. They won't be shown again.
        </p>
        <ul class="security__codes-list">
          <li v-for="(code, index) in formattedRecoveryCodes" :key="index" class="security__code">
            {{ code }}
          </li>
        </ul>
        <div class="security__sheet-actions">
          <UiButton variant="ghost" @click="copyCodes">
            <Copy :size="18" />
            Copy
          </UiButton>
          <UiButton variant="subtle" @click="downloadCodes">
            <Download :size="18" />
            Download
          </UiButton>
          <UiButton variant="accent" @click="closeTotpSheet">
            <Check :size="18" />
            I've saved them
          </UiButton>
        </div>
      </div>
    </UiSheet>

    <UiSheet
      :open="recoverySheetOpen"
      :title="recoverySheetReason === 'replaced' ? 'Your new recovery codes' : 'Save your recovery codes'"
    >
      <div class="security__codes">
        <p class="security__codes-desc">
          <template v-if="recoverySheetReason === 'replaced'">Your old codes no longer work. </template>
          Save these somewhere safe. If you lose your device, each code gets you in once. They
          won't be shown again.
        </p>
        <ul class="security__codes-list">
          <li v-for="(code, index) in formattedRecoveryCodes" :key="index" class="security__code">
            {{ code }}
          </li>
        </ul>
        <div class="security__sheet-actions">
          <UiButton variant="ghost" @click="copyCodes">
            <Copy :size="18" />
            Copy
          </UiButton>
          <UiButton variant="subtle" @click="downloadCodes">
            <Download :size="18" />
            Download
          </UiButton>
          <UiButton variant="accent" @click="closeRecoverySheet">
            <Check :size="18" />
            I've saved them
          </UiButton>
        </div>
      </div>
    </UiSheet>

    <UiSheet :open="passkeySheetOpen" title="Create a passkey" @close="closePasskeySheet">
      <form class="security__passkey-form" @submit.prevent="createPasskey">
        <p class="security__totp-instruction">
          Give this passkey a name so you can recognise it later, then follow your device's prompt.
        </p>
        <div class="security__field">
          <label class="security__label" for="passkey-name">Name (optional)</label>
          <input
            id="passkey-name"
            v-model="passkeyName"
            type="text"
            maxlength="100"
            class="security__passkey-input"
            placeholder="e.g. My phone"
            :disabled="registeringPasskey"
          />
        </div>
        <div class="security__sheet-actions">
          <UiButton variant="ghost" type="button" @click="closePasskeySheet">Cancel</UiButton>
          <UiButton variant="accent" type="submit" :loading="registeringPasskey">
            Create passkey
          </UiButton>
        </div>
      </form>
    </UiSheet>

    <PasswordConfirmSheet
      :open="passwordOpen"
      :title="passwordPrompt.title"
      :description="passwordPrompt.description"
      :confirm-label="passwordPrompt.confirmLabel"
      :destructive="passwordPrompt.destructive"
      :loading="passwordLoading"
      :error="passwordError"
      @confirm="onPasswordConfirm"
      @close="passwordAction = null"
    />
  </div>
</template>

<style scoped>
.security {
  margin-top: var(--space-6);
}

.security__title {
  font-size: var(--text-title);
  font-weight: 800;
  line-height: var(--lh-title);
  color: var(--color-ink);
  margin: 0 0 var(--space-4);
}

.security__section {
  padding: var(--space-6);
  margin-bottom: var(--space-4);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-card);
  background: var(--color-surface);
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.security__status {
  display: flex;
  align-items: flex-start;
  gap: var(--space-4);
}

.security__status-icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  border-radius: var(--radius-card);
  background: var(--color-accent-soft);
  color: var(--color-accent-ink);
  flex-shrink: 0;
}

.security__status-text {
  flex: 1;
  min-width: 0;
}

.security__status-title {
  font-size: var(--text-body);
  font-weight: 700;
  color: var(--color-ink);
  margin: 0;
}

.security__status-desc {
  font-size: var(--text-meta);
  color: var(--color-ink-soft);
  margin: var(--space-1) 0 0;
}

.security__section-header {
  display: flex;
  align-items: flex-start;
  gap: var(--space-4);
}

.security__section-icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  border-radius: var(--radius-card);
  background: var(--color-accent-soft);
  color: var(--color-accent-ink);
  flex-shrink: 0;
}

.security__section-title {
  font-size: var(--text-body);
  font-weight: 700;
  color: var(--color-ink);
  margin: 0;
}

.security__section-desc {
  font-size: var(--text-meta);
  color: var(--color-ink-soft);
  margin: var(--space-1) 0 0;
}

.security__method-state {
  display: flex;
  align-items: center;
  gap: var(--space-4);
}

.security__method-badge {
  display: inline-flex;
  align-items: center;
  padding: var(--space-1) var(--space-3);
  border-radius: var(--radius-pill);
  background: var(--color-success);
  color: var(--color-surface);
  font-size: var(--text-meta);
  font-weight: 700;
}

.security__passkey-form {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.security__passkey-input {
  padding: var(--space-3);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-card);
  background: var(--color-surface);
  color: var(--color-ink);
  font-family: inherit;
  font-size: var(--text-body);
  min-height: var(--target-min);
}

.security__passkey-input:focus {
  outline: none;
  border-color: var(--color-accent);
  box-shadow: 0 0 0 2px var(--color-accent-soft);
}

.security__passkey-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
}

.security__passkey-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-3);
  padding: var(--space-3);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-card);
  background: var(--color-surface);
}

.security__passkey-info {
  min-width: 0;
}

.security__passkey-name {
  font-size: var(--text-body);
  font-weight: 700;
  color: var(--color-ink);
  margin: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.security__passkey-meta {
  font-size: var(--text-meta);
  color: var(--color-ink-soft);
  margin: var(--space-1) 0 0;
}

.security__hint {
  font-size: var(--text-meta);
  color: var(--color-ink-soft);
  margin: 0;
}

.security__totp-setup {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.security__totp-instruction {
  font-size: var(--text-body);
  color: var(--color-ink);
  margin: 0;
}

.security__qr {
  width: 200px;
  height: 200px;
  align-self: center;
  border-radius: var(--radius-card);
  border: 1px solid var(--color-border);
}

.security__secret {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.security__secret-label {
  font-size: var(--text-meta);
  color: var(--color-ink-soft);
  margin: 0;
}

.security__secret-value {
  padding: var(--space-3);
  border-radius: var(--radius-card);
  background: var(--color-surface-2);
  color: var(--color-ink);
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: var(--text-body);
  word-break: break-all;
}

.security__field {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.security__label {
  font-size: var(--text-body);
  font-weight: 600;
  color: var(--color-ink);
}

.security__input {
  padding: var(--space-3);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-card);
  background: var(--color-surface);
  color: var(--color-ink);
  font-family: inherit;
  font-size: var(--text-body);
  min-height: var(--target-min);
}

.security__input:focus {
  outline: none;
  border-color: var(--color-accent);
  box-shadow: 0 0 0 2px var(--color-accent-soft);
}

.security__sheet-actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-3);
}

.security__codes {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.security__codes-desc {
  font-size: var(--text-body);
  color: var(--color-ink);
  margin: 0;
}

.security__codes-list {
  list-style: none;
  margin: 0;
  padding: var(--space-4);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-card);
  background: var(--color-surface-2);
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(8rem, 1fr));
  gap: var(--space-3);
}

.security__code {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: var(--text-body);
  color: var(--color-ink);
  word-break: break-all;
}
</style>
