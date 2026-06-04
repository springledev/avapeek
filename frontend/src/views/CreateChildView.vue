<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useChildStore } from '@/stores/child'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const childStore = useChildStore()
const authStore = useAuthStore()

const name = ref('')
const birthDate = ref('')
const formErrors = ref<Record<string, string>>({})
const isSubmitting = ref(false)

function validateForm(): boolean {
  formErrors.value = {}

  if (!name.value.trim()) {
    formErrors.value.name = 'Name is required'
    return false
  }

  return true
}

async function handleSubmit() {
  childStore.clearError()
  formErrors.value = {}

  if (!validateForm()) return

  if (!authStore.kek) {
    formErrors.value.general = 'Your session has expired. Please log in again.'
    return
  }

  isSubmitting.value = true

  try {
    const child = await childStore.createChild(
      name.value.trim(),
      birthDate.value || null,
    )
    router.push(`/child/${child.id}`)
  } catch (err: any) {
    if (err.response?.data?.errors) {
      formErrors.value = Object.entries(err.response.data.errors).reduce(
        (acc, [key, messages]) => {
          acc[key] = (messages as string[])[0]
          return acc
        },
        {} as Record<string, string>,
      )
    } else {
      formErrors.value.general = childStore.error || 'Failed to create child profile.'
    }
  } finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <div class="min-h-screen bg-slate-50">
    <nav class="nav-bar">
      <div class="nav-container">
        <div class="nav-content">
          <div class="flex items-center gap-3">
            <router-link to="/" class="nav-back-link">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
              </svg>
            </router-link>
            <div class="flex items-center gap-2">
              <svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
              </svg>
              <h1 class="text-xl font-bold text-slate-900">Avapeek</h1>
            </div>
          </div>
        </div>
      </div>
    </nav>

    <main class="main-container-narrow">
      <div class="card-xl">
        <div class="mb-8">
          <h2 class="text-2xl font-bold text-slate-900">Add a Child</h2>
          <p class="text-slate-600 mt-1">Create a new profile to start building their photo album.</p>
        </div>

        <div v-if="formErrors.general" class="alert-error mb-6">
          {{ formErrors.general }}
        </div>

        <form @submit.prevent="handleSubmit" class="space-y-6">
          <div class="form-group">
            <label for="name" class="form-label">Name</label>
            <input
              id="name"
              v-model="name"
              type="text"
              autocomplete="off"
              class="form-input"
              :class="{ 'border-red-500': formErrors.name }"
              :disabled="isSubmitting"
              placeholder="e.g. Emma"
            />
            <p v-if="formErrors.name" class="error-message">{{ formErrors.name }}</p>
          </div>

          <div class="form-group">
            <label for="birthDate" class="form-label">Birth Date <span class="text-slate-400 font-normal">(optional)</span></label>
            <input
              id="birthDate"
              v-model="birthDate"
              type="date"
              class="form-input"
              :disabled="isSubmitting"
              :max="new Date().toISOString().split('T')[0]"
            />
          </div>

          <button
            type="submit"
            class="btn-primary"
            :disabled="isSubmitting"
          >
            <span v-if="isSubmitting" class="flex items-center justify-center gap-2">
              <span class="spinner"></span>
              Creating Profile...
            </span>
            <span v-else>Create Child Profile</span>
          </button>
        </form>
      </div>
    </main>
  </div>
</template>