<script setup lang="ts">
import { ref, watch } from 'vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiSheet from '@/components/ui/UiSheet.vue'

const props = withDefaults(
  defineProps<{
    open: boolean
    title: string
    /** Extra context shown above the field, e.g. what the action will change. */
    description?: string
    confirmLabel?: string
    /** Use the danger button only for actions that remove protection. */
    destructive?: boolean
    loading?: boolean
    /** Inline error, usually "The current password is incorrect." */
    error?: string | null
  }>(),
  { description: '', confirmLabel: 'Confirm', destructive: false, loading: false, error: null },
)

const emit = defineEmits<{
  (e: 'confirm', password: string): void
  (e: 'close'): void
}>()

const password = ref('')

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) {
      password.value = ''
    }
  },
)

function onSubmit() {
  if (!password.value.trim()) return
  emit('confirm', password.value)
}
</script>

<template>
  <UiSheet :open="open" :title="title" @close="emit('close')">
    <form class="password-form" @submit.prevent="onSubmit">
      <p v-if="description" class="password-form__description">{{ description }}</p>
      <label class="password-form__label" for="confirm-password">Current password</label>
      <input
        id="confirm-password"
        v-model="password"
        type="password"
        class="password-form__input"
        :class="{ 'password-form__input--invalid': error }"
        autocomplete="current-password"
        :aria-invalid="error ? 'true' : undefined"
        :aria-describedby="error ? 'confirm-password-error' : undefined"
        :disabled="loading"
      />
      <p v-if="error" id="confirm-password-error" class="password-form__error" role="alert">
        {{ error }}
      </p>

      <div class="password-form__actions">
        <UiButton variant="ghost" type="button" :disabled="loading" @click="emit('close')">
          Cancel
        </UiButton>
        <UiButton :variant="destructive ? 'danger' : 'accent'" type="submit" :loading="loading">
          {{ confirmLabel }}
        </UiButton>
      </div>
    </form>
  </UiSheet>
</template>

<style scoped>
.password-form {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.password-form__description {
  margin: 0;
  color: var(--color-ink-soft);
  font-size: var(--text-body);
  line-height: var(--lh-body);
}

.password-form__label {
  font-size: var(--text-body);
  font-weight: 600;
  color: var(--color-ink);
}

.password-form__input {
  padding: var(--space-3);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-card);
  background: var(--color-surface);
  color: var(--color-ink);
  font-family: inherit;
  font-size: var(--text-body);
  min-height: var(--target-min);
}

.password-form__input:focus {
  outline: none;
  border-color: var(--color-accent);
  box-shadow: 0 0 0 2px var(--color-accent-soft);
}

.password-form__input--invalid {
  border-color: var(--color-danger);
}

.password-form__error {
  margin: calc(var(--space-2) * -1) 0 0;
  color: var(--color-danger);
  font-size: var(--text-body);
}

.password-form__actions {
  display: flex;
  justify-content: flex-end;
  gap: var(--space-3);
}
</style>
