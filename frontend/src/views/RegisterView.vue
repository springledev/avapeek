<script setup lang="ts">
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const authStore = useAuthStore()

const email = ref('')
const password = ref('')
const confirmPassword = ref('')
const displayName = ref('')
const formErrors = ref<Record<string, string>>({})

const passwordStrength = computed(() => {
  const pwd = password.value
  if (!pwd) return { level: 0, text: '', color: '' }

  let strength = 0
  if (pwd.length >= 8) strength++
  if (pwd.length >= 12) strength++
  if (/[a-z]/.test(pwd) && /[A-Z]/.test(pwd)) strength++
  if (/\d/.test(pwd)) strength++
  if (/[^a-zA-Z0-9]/.test(pwd)) strength++

  if (strength <= 2) return { level: strength, text: 'Weak', color: 'text-red-600' }
  if (strength <= 3) return { level: strength, text: 'Fair', color: 'text-yellow-600' }
  if (strength <= 4) return { level: strength, text: 'Good', color: 'text-blue-600' }
  return { level: strength, text: 'Strong', color: 'text-green-600' }
})

function validateForm(): boolean {
  formErrors.value = {}

  if (!displayName.value) {
    formErrors.value.displayName = 'Display name is required'
  }

  if (!email.value) {
    formErrors.value.email = 'Email is required'
  } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value)) {
    formErrors.value.email = 'Please enter a valid email address'
  }

  if (!password.value) {
    formErrors.value.password = 'Password is required'
  } else if (password.value.length < 8) {
    formErrors.value.password = 'Password must be at least 8 characters long'
  }

  if (!confirmPassword.value) {
    formErrors.value.confirmPassword = 'Please confirm your password'
  } else if (password.value !== confirmPassword.value) {
    formErrors.value.confirmPassword = 'Passwords do not match'
  }

  return Object.keys(formErrors.value).length === 0
}

async function handleSubmit() {
  // Clear previous errors
  authStore.clearError()

  // Validate form
  if (!validateForm()) {
    return
  }

  try {
    await authStore.register(email.value, password.value, displayName.value)
    // Redirect to dashboard after successful registration
    router.push('/')
  } catch (error: any) {
    // Handle validation errors from API
    if (error.response?.data?.errors) {
      formErrors.value = Object.entries(error.response.data.errors).reduce(
        (acc, [key, messages]) => {
          acc[key] = (messages as string[])[0]
          return acc
        },
        {} as Record<string, string>,
      )
    }
  }
}
</script>

<template>
  <div class="auth-container">
    <div class="auth-card">
      <!-- Header -->
      <div>
        <h1 class="auth-title">Create Your Account</h1>
        <p class="auth-subtitle">Join Avapeek and start sharing photos securely</p>
      </div>

      <!-- Security Badge -->
      <div class="security-badge">
        <svg
          class="w-5 h-5 text-blue-600"
          fill="none"
          stroke="currentColor"
          viewBox="0 0 24 24"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"
          />
        </svg>
        <span class="security-badge-text"
          >Your encryption keys are generated locally and never leave your device.</span
        >
      </div>

      <!-- Error Alert -->
      <div v-if="authStore.error" class="alert-error">
        {{ authStore.error }}
      </div>

      <!-- Registration Form -->
      <form @submit.prevent="handleSubmit" class="space-y-4">
        <!-- Display Name Field -->
        <div class="form-group">
          <label for="displayName" class="form-label">Display Name</label>
          <input
            id="displayName"
            v-model="displayName"
            type="text"
            autocomplete="name"
            class="form-input"
            :class="{ 'border-red-500': formErrors.displayName }"
            :disabled="authStore.isLoading"
            placeholder="John Doe"
          />
          <p v-if="formErrors.displayName" class="error-message">
            {{ formErrors.displayName }}
          </p>
        </div>

        <!-- Email Field -->
        <div class="form-group">
          <label for="email" class="form-label">Email Address</label>
          <input
            id="email"
            v-model="email"
            type="email"
            autocomplete="email"
            class="form-input"
            :class="{ 'border-red-500': formErrors.email }"
            :disabled="authStore.isLoading"
            placeholder="you@example.com"
          />
          <p v-if="formErrors.email" class="error-message">{{ formErrors.email }}</p>
        </div>

        <!-- Password Field -->
        <div class="form-group">
          <label for="password" class="form-label">Password</label>
          <input
            id="password"
            v-model="password"
            type="password"
            autocomplete="new-password"
            class="form-input"
            :class="{ 'border-red-500': formErrors.password }"
            :disabled="authStore.isLoading"
            placeholder="••••••••"
          />
          <div v-if="password" class="flex items-center gap-2 mt-1">
            <div class="flex-1 bg-slate-200 rounded-full h-2">
              <div
                class="h-2 rounded-full transition-all duration-300"
                :class="{
                  'bg-red-500': passwordStrength.level <= 2,
                  'bg-yellow-500': passwordStrength.level === 3,
                  'bg-blue-500': passwordStrength.level === 4,
                  'bg-green-500': passwordStrength.level >= 5,
                }"
                :style="{ width: `${(passwordStrength.level / 5) * 100}%` }"
              ></div>
            </div>
            <span class="text-xs font-medium" :class="passwordStrength.color">{{
              passwordStrength.text
            }}</span>
          </div>
          <p v-if="formErrors.password" class="error-message">{{ formErrors.password }}</p>
        </div>

        <!-- Confirm Password Field -->
        <div class="form-group">
          <label for="confirmPassword" class="form-label">Confirm Password</label>
          <input
            id="confirmPassword"
            v-model="confirmPassword"
            type="password"
            autocomplete="new-password"
            class="form-input"
            :class="{ 'border-red-500': formErrors.confirmPassword }"
            :disabled="authStore.isLoading"
            placeholder="••••••••"
          />
          <p v-if="formErrors.confirmPassword" class="error-message">
            {{ formErrors.confirmPassword }}
          </p>
        </div>

        <!-- Submit Button -->
        <button type="submit" class="btn-primary" :disabled="authStore.isLoading">
          <span v-if="authStore.isLoading" class="flex items-center justify-center gap-2">
            <span class="spinner"></span>
            Creating Account...
          </span>
          <span v-else>Create Account</span>
        </button>
      </form>

      <!-- Divider -->
      <div class="divider">
        <span class="divider-text">Already have an account?</span>
      </div>

      <!-- Login Link -->
      <div class="text-center">
        <router-link to="/login" class="link-primary">Sign in instead</router-link>
      </div>
    </div>
  </div>
</template>
