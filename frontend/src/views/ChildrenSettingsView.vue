<script setup lang="ts">
import { Plus, ChevronRight } from '@lucide/vue'
import { ref, onMounted, computed } from 'vue'
import { useChildStore } from '@/stores/child'
import { useAuthStore } from '@/stores/auth'
import UiAvatar from '@/components/ui/UiAvatar.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiEmptyState from '@/components/ui/UiEmptyState.vue'
import UiSheet from '@/components/ui/UiSheet.vue'

const childStore = useChildStore()
const authStore = useAuthStore()

const canAddChild = computed(() =>
  childStore.children.some((c) => c.role === 'owner' || c.role === 'admin'),
)

const showAddSheet = ref(false)
const newName = ref('')
const newBirthDate = ref('')
const isSubmitting = ref(false)
const formError = ref('')

onMounted(async () => {
  if (childStore.children.length === 0) {
    await childStore.fetchChildren()
  }
})

function openAddSheet() {
  newName.value = ''
  newBirthDate.value = ''
  formError.value = ''
  showAddSheet.value = true
}

function closeAddSheet() {
  showAddSheet.value = false
}

async function handleSubmit() {
  if (!newName.value.trim()) {
    formError.value = 'Name is required'
    return
  }
  if (!authStore.kek) {
    formError.value = 'Your session has expired. Please log in again.'
    return
  }

  isSubmitting.value = true
  formError.value = ''

  try {
    await childStore.createChild(newName.value.trim(), newBirthDate.value || null)
    closeAddSheet()
  } catch (err: unknown) {
    const errObj = err as { response?: { data?: { errors?: Record<string, string[]> } } }
    if (errObj.response?.data?.errors) {
      const firstKey = Object.keys(errObj.response.data.errors)[0]
      formError.value = firstKey ? (errObj.response.data.errors[firstKey]?.[0] ?? '') : ''
    } else {
      formError.value = childStore.error || 'Failed to create child profile.'
    }
  } finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <div class="kids">
    <div class="kids__header">
      <h2 class="kids__title">Kids</h2>
      <UiButton v-if="canAddChild" variant="accent" size="md" @click="openAddSheet">
        <Plus :size="16" />
        Add kid
      </UiButton>
    </div>

    <UiEmptyState
      v-if="childStore.children.length === 0"
      title="No kids yet"
      message="Create a profile to start building their photo album."
    >
      <template #action>
        <UiButton variant="accent" @click="openAddSheet">Create first profile</UiButton>
      </template>
    </UiEmptyState>

    <div v-else class="kids__list">
      <router-link
        v-for="child in childStore.children"
        :key="child.id"
        :to="`/settings/children/${child.id}`"
        class="kids__row"
      >
        <UiAvatar :id="child.id" :name="child.name" :color-hex="child.avatar_color" :size="40" />
        <div class="kids__info">
          <p class="kids__name">{{ child.name }}</p>
          <p class="kids__meta">
            {{ child.age }}
            <span v-if="child.member_count">
              · {{ child.member_count }} {{ child.member_count === 1 ? 'member' : 'members' }}</span
            >
            <span v-if="child.photo_count !== undefined">
              · {{ child.photo_count }} {{ child.photo_count === 1 ? 'photo' : 'photos' }}</span
            >
          </p>
        </div>
        <ChevronRight :size="20" class="kids__chevron" />
      </router-link>
    </div>
  </div>

  <UiSheet :open="showAddSheet" title="Add a kid" @close="closeAddSheet">
    <div v-if="formError" class="add-form__error">{{ formError }}</div>

    <form class="add-form" @submit.prevent="handleSubmit">
      <div class="add-form__field">
        <label class="add-form__label" for="add-name">Name</label>
        <input
          id="add-name"
          v-model="newName"
          type="text"
          autocomplete="off"
          class="add-form__input"
          :disabled="isSubmitting"
          placeholder="e.g. Emma"
        />
      </div>

      <div class="add-form__field">
        <label class="add-form__label" for="add-birthdate">
          Birth date <span class="add-form__optional">(optional)</span>
        </label>
        <input
          id="add-birthdate"
          v-model="newBirthDate"
          type="date"
          class="add-form__input"
          :disabled="isSubmitting"
          :max="new Date().toISOString().split('T')[0]"
        />
      </div>

      <UiButton type="submit" variant="accent" :loading="isSubmitting"> Create profile </UiButton>
    </form>
  </UiSheet>
</template>

<style scoped>
.kids {
  margin-top: var(--space-6);
}

.kids__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: var(--space-4);
}

.kids__title {
  font-size: var(--text-title);
  font-weight: 800;
  line-height: var(--lh-title);
  color: var(--color-ink);
  margin: 0;
}

.kids__list {
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
}

.kids__row {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-4);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-card);
  background: var(--color-surface);
  text-decoration: none;
  color: var(--color-ink);
  transition: background var(--dur-micro) var(--ease-soft);
}

.kids__row:hover {
  background: var(--color-surface-2);
}

.kids__info {
  flex: 1;
  min-width: 0;
}

.kids__name {
  font-size: var(--text-body);
  font-weight: 700;
  color: var(--color-ink);
  margin: 0;
}

.kids__meta {
  font-size: var(--text-meta);
  color: var(--color-ink-soft);
  margin: 0;
}

.kids__chevron {
  color: var(--color-ink-soft);
  flex-shrink: 0;
}

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
