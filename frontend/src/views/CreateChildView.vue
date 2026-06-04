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
    <nav class="bg-white shadow-sm border-b border-slate-200">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center h-16">
          <div class="flex items-center gap-3">
            <router-link to="/" class="text-slate-400 hover:text-slate-600 transition-colors">
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

    <main class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
      <div class="bg-white rounded-2xl shadow-xl p-8">
        <div class="mb-8">
          <h2 class="text-2xl font-bold text-slate-900">Add a Child</h2>
          <p class="text-slate-600 mt-1">Create a new profile to start building their photo album.</p>
        </div>

        <div v-if="formErrors.general" class="mb-6 p-4 rounded-lg bg-red-50 border border-red-200 text-red-800 text-sm">
          {{ formErrors.general }}
        </div>

        <form @submit.prevent="handleSubmit" class="space-y-6">
          <div class="space-y-2">
            <label for="name" class="block text-sm font-medium text-slate-700">Name</label>
            <input
              id="name"
              v-model="name"
              type="text"
              autocomplete="off"
              class="w-full px-4 py-3 rounded-lg border border-slate-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 outline-none transition-all duration-200"
              :class="{ 'border-red-500': formErrors.name }"
              :disabled="isSubmitting"
              placeholder="e.g. Emma"
            />
            <p v-if="formErrors.name" class="text-sm text-red-600 mt-1">{{ formErrors.name }}</p>
          </div>

          <div class="space-y-2">
            <label for="birthDate" class="block text-sm font-medium text-slate-700">Birth Date <span class="text-slate-400 font-normal">(optional)</span></label>
            <input
              id="birthDate"
              v-model="birthDate"
              type="date"
              class="w-full px-4 py-3 rounded-lg border border-slate-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 outline-none transition-all duration-200"
              :disabled="isSubmitting"
              :max="new Date().toISOString().split('T')[0]"
            />
          </div>

          <button
            type="submit"
            class="w-full py-3 px-4 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors duration-200 disabled:opacity-50 disabled:cursor-not-allowed"
            :disabled="isSubmitting"
          >
            <span v-if="isSubmitting" class="flex items-center justify-center gap-2">
              <span class="inline-block w-5 h-5 border-2 border-white border-t-transparent rounded-full animate-spin"></span>
              Creating Profile...
            </span>
            <span v-else>Create Child Profile</span>
          </button>
        </form>
      </div>
    </main>
  </div>
</template>