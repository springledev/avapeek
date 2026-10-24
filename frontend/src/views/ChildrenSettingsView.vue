<script setup lang="ts">
import { Plus, ChevronRight } from '@lucide/vue'
import { ref, onMounted } from 'vue'
import { toast } from 'vue-sonner'
import { useChildStore } from '@/stores/child'
import CreateChildSheet from '@/components/CreateChildSheet.vue'
import UiAvatar from '@/components/ui/UiAvatar.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiEmptyState from '@/components/ui/UiEmptyState.vue'

const childStore = useChildStore()

const showAddSheet = ref(false)
const finishingId = ref<number | null>(null)

function needsKeySetup(child: { id: number; role: string }): boolean {
  return child.role === 'owner' && !childStore.hasOwnKey(child.id)
}

async function finishSetup(childId: number) {
  finishingId.value = childId
  try {
    await childStore.finishKeySetup(childId)
    toast.success('All set. You can now add photos.')
  } catch {
    toast.error(childStore.error || 'Could not finish setting up. Please try again.')
  } finally {
    finishingId.value = null
  }
}

onMounted(async () => {
  if (childStore.children.length === 0) {
    await childStore.fetchChildren()
  }
})
</script>

<template>
  <div class="kids">
    <div class="kids__header">
      <h2 class="kids__title">Kids</h2>
      <UiButton variant="accent" size="md" @click="showAddSheet = true">
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
        <UiButton variant="accent" @click="showAddSheet = true">Create first profile</UiButton>
      </template>
    </UiEmptyState>

    <div v-else class="kids__list">
      <div v-for="child in childStore.children" :key="child.id" class="kids__item">
        <router-link :to="`/settings/children/${child.id}`" class="kids__row">
          <UiAvatar :id="child.id" :name="child.name" :color-hex="child.avatar_color" :size="40" />
          <div class="kids__info">
            <p class="kids__name">{{ child.name }}</p>
            <p class="kids__meta">
              {{ child.age }}
              <span v-if="child.member_count">
                · {{ child.member_count }}
                {{ child.member_count === 1 ? 'member' : 'members' }}</span
              >
              <span v-if="child.photo_count !== undefined">
                · {{ child.photo_count }} {{ child.photo_count === 1 ? 'photo' : 'photos' }}</span
              >
            </p>
          </div>
          <ChevronRight :size="20" class="kids__chevron" />
        </router-link>
        <div v-if="needsKeySetup(child)" class="kids__setup">
          <p class="kids__setup-text">Setup isn't finished, so photos can't be added yet.</p>
          <UiButton
            variant="accent"
            size="md"
            :loading="finishingId === child.id"
            @click="finishSetup(child.id)"
          >
            Finish setting up
          </UiButton>
        </div>
      </div>
    </div>
  </div>

  <CreateChildSheet :open="showAddSheet" @close="showAddSheet = false" />
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

.kids__item {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.kids__setup {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-3);
  padding: var(--space-3) var(--space-4);
  border: 1px dashed var(--color-border);
  border-radius: var(--radius-card);
}

.kids__setup-text {
  margin: 0;
  font-size: var(--text-meta);
  color: var(--color-ink-soft);
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
</style>
