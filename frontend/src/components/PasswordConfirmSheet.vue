<script setup lang="ts">
import { ref, watch } from 'vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiSheet from '@/components/ui/UiSheet.vue'

const props = defineProps<{
  open: boolean
  title: string
  loading?: boolean
}>()

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
      <label class="password-form__label" for="confirm-password">Current password</label>
      <input
        id="confirm-password"
        v-model="password"
        type="password"
        class="password-form__input"
        autocomplete="current-password"
        :disabled="loading"
      />

      <div class="password-form__actions">
        <UiButton variant="ghost" type="button" :disabled="loading" @click="emit('close')">
          Cancel
        </UiButton>
        <UiButton variant="danger" type="submit" :loading="loading"> Confirm </UiButton>
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

.password-form__actions {
  display: flex;
  justify-content: flex-end;
  gap: var(--space-3);
}
</style>
