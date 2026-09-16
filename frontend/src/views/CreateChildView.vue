<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useChildStore } from '@/stores/child'
import { useAuthStore } from '@/stores/auth'
import UiButton from '@/components/ui/UiButton.vue'

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
    await childStore.createChild(name.value.trim(), birthDate.value || null)
    router.push('/settings/children')
  } catch (err: unknown) {
    const errObj = err as { response?: { data?: { errors?: Record<string, string[]> } } }
    if (errObj.response?.data?.errors) {
      formErrors.value = Object.entries(errObj.response.data.errors).reduce(
        (acc, [key, messages]) => {
          acc[key] = (messages as string[])[0] ?? ''
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
  <div class="create-child">
    <nav class="create-child__breadcrumb">
      <router-link to="/settings/children" class="create-child__back">Kids</router-link>
      <span class="create-child__sep">/</span>
      <span class="create-child__current">Add a kid</span>
    </nav>

    <div class="create-child__hero">
      <h2 class="create-child__title">Add a kid</h2>
      <p class="create-child__subtitle">
        Create a new profile to start building their photo album.
      </p>
    </div>

    <div class="create-child__card">
      <div v-if="formErrors.general" class="create-child__error">
        {{ formErrors.general }}
      </div>

      <form class="create-child__form" @submit.prevent="handleSubmit">
        <div class="create-child__field">
          <label class="create-child__label" for="name">Name</label>
          <input
            id="name"
            v-model="name"
            type="text"
            autocomplete="off"
            class="create-child__input"
            :class="{ 'create-child__input--error': formErrors.name }"
            :disabled="isSubmitting"
            placeholder="e.g. Emma"
          />
          <p v-if="formErrors.name" class="create-child__field-error">{{ formErrors.name }}</p>
        </div>

        <div class="create-child__field">
          <label class="create-child__label" for="birthDate">
            Birth Date <span class="create-child__optional">(optional)</span>
          </label>
          <input
            id="birthDate"
            v-model="birthDate"
            type="date"
            class="create-child__input"
            :disabled="isSubmitting"
            :max="new Date().toISOString().split('T')[0]"
          />
        </div>

        <UiButton type="submit" variant="accent" size="lg" :loading="isSubmitting">
          Create profile
        </UiButton>
      </form>
    </div>
  </div>
</template>

<style scoped>
.create-child {
  max-width: 32rem;
  margin: 0 auto;
}

.create-child__breadcrumb {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  font-size: var(--text-body);
  margin-bottom: var(--space-4);
}

.create-child__back {
  color: var(--color-ink-soft);
  text-decoration: none;
}

.create-child__back:hover {
  color: var(--color-ink);
}

.create-child__sep {
  color: var(--color-border);
}

.create-child__current {
  font-weight: 600;
  color: var(--color-ink);
}

.create-child__hero {
  margin-bottom: var(--space-6);
}

.create-child__title {
  font-size: var(--text-display);
  font-weight: 800;
  line-height: var(--lh-tight);
  color: var(--color-ink);
  margin: 0 0 var(--space-2);
}

.create-child__subtitle {
  font-size: var(--text-body);
  color: var(--color-ink-soft);
  margin: 0;
}

.create-child__card {
  padding: var(--space-6);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-card);
  background: var(--color-surface);
}

.create-child__error {
  padding: var(--space-3);
  border-radius: var(--radius-card);
  background: var(--color-danger);
  color: var(--color-on-danger);
  font-size: var(--text-body);
  margin-bottom: var(--space-4);
}

.create-child__form {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.create-child__field {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.create-child__label {
  font-size: var(--text-body);
  font-weight: 600;
  color: var(--color-ink);
}

.create-child__optional {
  font-weight: 400;
  color: var(--color-ink-soft);
}

.create-child__input {
  padding: var(--space-3);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-card);
  background: var(--color-surface);
  color: var(--color-ink);
  font-family: inherit;
  font-size: var(--text-body);
  min-height: var(--target-min);
}

.create-child__input:focus {
  outline: none;
  border-color: var(--color-accent);
  box-shadow: 0 0 0 2px var(--color-accent-soft);
}

.create-child__input--error {
  border-color: var(--color-danger);
}

.create-child__field-error {
  font-size: var(--text-meta);
  color: var(--color-danger);
  margin: 0;
}
</style>
