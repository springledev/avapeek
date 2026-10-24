<script setup lang="ts">
import { ref, watch } from 'vue'
import { useChildStore } from '@/stores/child'
import { useAuthStore } from '@/stores/auth'
import { apiErrorMessage, firstValidationError } from '@/lib/util'
import UiButton from '@/components/ui/UiButton.vue'
import UiSheet from '@/components/ui/UiSheet.vue'
import type { Child } from '@/types/child'

const props = defineProps<{
  open: boolean
}>()

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'created', child: Child): void
}>()

const childStore = useChildStore()
const authStore = useAuthStore()

const name = ref('')
const birthDate = ref('')
const isSubmitting = ref(false)
const formError = ref('')

const today = new Date().toISOString().split('T')[0]

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) {
      formError.value = ''
      if (!childStore.keyPendingChild) {
        name.value = ''
        birthDate.value = ''
      }
    }
  },
)

async function handleSubmit() {
  formError.value = ''

  // A previous attempt created the child but not its key: retry only the key.
  const isRetry = childStore.keyPendingChild !== null

  if (!isRetry && !name.value.trim()) {
    formError.value = 'Name is required'
    return
  }
  if (!authStore.kek) {
    formError.value = 'Your session has expired. Please log in again.'
    return
  }

  isSubmitting.value = true

  try {
    const child = isRetry
      ? await childStore.retryKeySetup()
      : await childStore.createChild(name.value.trim(), birthDate.value || null)
    emit('created', child)
    emit('close')
  } catch (err: unknown) {
    formError.value =
      firstValidationError(err) ||
      childStore.error ||
      apiErrorMessage(err, 'Failed to create child profile.')
  } finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <UiSheet :open="open" title="Add a kid" @close="emit('close')">
    <div v-if="formError" class="add-form__error" role="alert">{{ formError }}</div>

    <form class="add-form" @submit.prevent="handleSubmit">
      <div class="add-form__field">
        <label class="add-form__label" for="add-name">Name</label>
        <input
          id="add-name"
          v-model="name"
          type="text"
          autocomplete="off"
          class="add-form__input"
          :disabled="isSubmitting || childStore.keyPendingChild !== null"
          placeholder="e.g. Emma"
        />
      </div>

      <div class="add-form__field">
        <label class="add-form__label" for="add-birthdate">
          Birth date <span class="add-form__optional">(optional)</span>
        </label>
        <input
          id="add-birthdate"
          v-model="birthDate"
          type="date"
          class="add-form__input"
          :disabled="isSubmitting || childStore.keyPendingChild !== null"
          :max="today"
        />
      </div>

      <UiButton type="submit" variant="accent" :loading="isSubmitting">
        {{ childStore.keyPendingChild ? 'Retry key setup' : 'Create profile' }}
      </UiButton>
    </form>
  </UiSheet>
</template>

<style scoped>
.add-form {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.add-form__error {
  padding: var(--space-3);
  border-radius: var(--radius-card);
  background: var(--color-danger);
  color: var(--color-on-danger);
  font-size: var(--text-body);
  margin-bottom: var(--space-4);
}

.add-form__field {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.add-form__label {
  font-size: var(--text-body);
  font-weight: 600;
  color: var(--color-ink);
}

.add-form__optional {
  font-weight: 400;
  color: var(--color-ink-soft);
}

.add-form__input {
  padding: var(--space-3);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-card);
  background: var(--color-surface);
  color: var(--color-ink);
  font-family: inherit;
  font-size: var(--text-body);
  min-height: var(--target-min);
}

.add-form__input:focus {
  outline: none;
  border-color: var(--color-accent);
  box-shadow: 0 0 0 2px var(--color-accent-soft);
}
</style>
