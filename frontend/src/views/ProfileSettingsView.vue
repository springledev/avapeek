<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { toast } from 'vue-sonner'

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
  } catch (err: any) {
    const msg = err.response?.data?.message || err.response?.data?.errors
      ? Object.values(err.response.data.errors).flat().join(', ')
      : 'Failed to update profile.'
    toast.error(msg)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="mt-8 rounded-xl border border-gray-200 bg-white p-6 dark:border-slate-600 dark:bg-slate-800">
    <h2 class="mb-4 text-lg font-semibold text-slate-900 dark:text-white">Profile</h2>

    <div class="space-y-4">
      <div>
        <label class="text-sm font-medium text-slate-700 dark:text-slate-300" for="display-name">Display Name</label>
        <input
          id="display-name"
          v-model="displayName"
          type="text"
          class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-slate-900 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200"
        />
      </div>

      <div>
        <label class="text-sm font-medium text-slate-700 dark:text-slate-300" for="email">Email</label>
        <input
          id="email"
          v-model="email"
          type="email"
          class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-slate-900 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200"
        />
      </div>

      <div class="border-t border-gray-200 pt-4 dark:border-slate-600">
        <button
          @click="showPasswordSection = !showPasswordSection"
          class="text-sm font-medium text-indigo-600 hover:text-indigo-700 dark:text-indigo-400"
        >
          {{ showPasswordSection ? 'Cancel password change' : 'Change password' }}
        </button>

        <div v-if="showPasswordSection" class="mt-3 space-y-3">
          <div>
            <label class="text-sm font-medium text-slate-700 dark:text-slate-300" for="current-password">Current Password</label>
            <input
              id="current-password"
              v-model="currentPassword"
              type="password"
              class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-slate-900 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200"
            />
          </div>
          <div>
            <label class="text-sm font-medium text-slate-700 dark:text-slate-300" for="new-password">New Password</label>
            <input
              id="new-password"
              v-model="newPassword"
              type="password"
              class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-slate-900 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200"
            />
          </div>
          <div>
            <label class="text-sm font-medium text-slate-700 dark:text-slate-300" for="new-password-confirm">Confirm New Password</label>
            <input
              id="new-password-confirm"
              v-model="newPasswordConfirm"
              type="password"
              class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-slate-900 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200"
            />
          </div>
        </div>
      </div>

      <div class="pt-2">
        <button
          @click="saveProfile"
          :disabled="saving"
          class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
        >
          {{ saving ? 'Saving…' : 'Save' }}
        </button>
      </div>
    </div>
  </div>
</template>
