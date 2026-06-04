<script setup lang="ts">
import { ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()

const email = ref('')
const password = ref('')
const formErrors = ref<Record<string, string>>({})

async function handleSubmit() {
  // Clear previous errors
  formErrors.value = {}
  authStore.clearError()

  // Basic validation
  if (!email.value) {
    formErrors.value.email = 'Email is required'
    return
  }
  if (!password.value) {
    formErrors.value.password = 'Password is required'
    return
  }

  try {
    await authStore.login(email.value, password.value)
    const redirect = (route.query.redirect as string) || '/'
    router.push(redirect)
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
        <h1 class="auth-title">Welcome Back</h1>
        <p class="auth-subtitle">Sign in to access your encrypted photo albums</p>
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
            d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"
          />
        </svg>
        <span class="security-badge-text">End-to-end encrypted. Zero-knowledge privacy.</span>
      </div>

      <!-- Error Alert -->
      <div v-if="authStore.error" class="alert-error">
        {{ authStore.error }}
      </div>

      <!-- Login Form -->
      <form @submit.prevent="handleSubmit" class="space-y-4">
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
            autocomplete="current-password"
            class="form-input"
            :class="{ 'border-red-500': formErrors.password }"
            :disabled="authStore.isLoading"
            placeholder="••••••••"
          />
          <p v-if="formErrors.password" class="error-message">{{ formErrors.password }}</p>
        </div>

        <!-- Submit Button -->
        <button type="submit" class="btn-primary" :disabled="authStore.isLoading">
          <span v-if="authStore.isLoading" class="flex items-center justify-center gap-2">
            <span class="spinner"></span>
            Signing In...
          </span>
          <span v-else>Sign In</span>
        </button>
      </form>

      <!-- Divider -->
      <div class="divider">
        <span class="divider-text">New to Avapeek?</span>
      </div>

      <!-- Register Link -->
      <div class="text-center">
        <router-link to="/register" class="link-primary">Create an account</router-link>
      </div>
    </div>
  </div>
</template>
