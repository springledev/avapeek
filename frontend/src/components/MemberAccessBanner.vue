<script setup lang="ts">
import { ref, watch } from 'vue'
import { Lock } from '@lucide/vue'
import UiButton from '@/components/ui/UiButton.vue'
import { useChildStore } from '@/stores/child'
import { MSG } from '@/lib/messages'

const props = defineProps<{
  childId: number
  childName: string
}>()

type ReminderState = 'idle' | 'sending' | 'sent' | 'already-sent' | 'error'

const childStore = useChildStore()
const state = ref<ReminderState>('idle')

// A new album (or a recheck after an error) starts from a clean state.
watch(
  () => props.childId,
  () => {
    state.value = 'idle'
  },
)

async function remind() {
  if (state.value === 'sending') return
  state.value = 'sending'
  const result = await childStore.sendAccessReminder(props.childId)
  state.value =
    result.status === 'sent' ? 'sent' : result.status === 'already_sent' ? 'already-sent' : 'error'
}
</script>

<template>
  <section class="access" data-test="member-access-banner" aria-live="polite">
    <div class="access__icon" aria-hidden="true"><Lock :size="22" /></div>
    <div class="access__text">
      <h2 class="access__title">{{ MSG.accessWaitingTitle(childName) }}</h2>
      <p class="access__body">{{ MSG.accessWaitingBody }}</p>
      <p v-if="state === 'sent'" class="access__status" data-test="access-status-sent">
        {{ MSG.accessReminded }}
      </p>
      <p
        v-else-if="state === 'already-sent'"
        class="access__status"
        data-test="access-status-already"
      >
        {{ MSG.accessRemindedAlready }}
      </p>
      <p
        v-else-if="state === 'error'"
        class="access__status access__status--error"
        role="alert"
        data-test="access-status-error"
      >
        {{ MSG.accessRemindFailed }}
      </p>
      <UiButton
        v-if="state === 'idle' || state === 'sending' || state === 'error'"
        variant="subtle"
        class="access__button"
        data-test="remind-admins"
        :loading="state === 'sending'"
        @click="remind"
      >
        {{ state === 'sending' ? MSG.accessReminding : MSG.accessRemind }}
      </UiButton>
    </div>
  </section>
</template>

<style scoped>
.access {
  display: flex;
  gap: var(--space-3);
  margin: 0 0 var(--space-4);
  padding: var(--space-4);
  border-radius: var(--radius-card);
  background: var(--color-accent-soft);
  color: var(--color-accent-ink);
}

.access__icon {
  flex: none;
  padding-top: 2px;
}

.access__text {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: var(--space-2);
}

.access__title {
  margin: 0;
  font-size: var(--text-body);
  font-weight: 800;
  color: var(--color-ink);
}

.access__body,
.access__status {
  margin: 0;
  font-size: var(--text-body);
  line-height: var(--lh-body);
}

.access__status {
  font-weight: 700;
}

.access__status--error {
  color: var(--color-danger);
}

.access__button {
  min-height: var(--target-min);
}
</style>
