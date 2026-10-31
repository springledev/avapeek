<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useChildStore } from '@/stores/child'
import { useAuthStore } from '@/stores/auth'
import { usePendingGrants } from '@/composables/usePendingGrants'
import { syncEncryptionKeys } from '@/services/key-sync'
import UiButton from '@/components/ui/UiButton.vue'

const childStore = useChildStore()
const authStore = useAuthStore()
const { pendingCount, refresh } = usePendingGrants()

const isSharing = ref(false)

const administersAlbum = computed(() =>
  childStore.children.some((c) => c.role === 'owner' || c.role === 'admin'),
)

const message = computed(() =>
  pendingCount.value === 1
    ? '1 family member waiting for access'
    : `${pendingCount.value} family members waiting for access`,
)

/** Tries to share first, then reads what is still pending. */
async function syncAndRefresh() {
  await syncEncryptionKeys()
  await refresh()
}

async function shareNow() {
  if (isSharing.value) return
  isSharing.value = true
  try {
    await syncAndRefresh()
  } finally {
    isSharing.value = false
  }
}

let started = false
async function start() {
  if (started || !administersAlbum.value || !authStore.kek) return
  started = true
  await syncAndRefresh()
}

onMounted(start)
watch([administersAlbum, () => authStore.kek], start)
</script>

<template>
  <div
    v-if="administersAlbum && pendingCount > 0"
    role="status"
    data-testid="pending-access-banner"
    class="pending-access"
  >
    <p class="pending-access__text">{{ message }}</p>
    <UiButton
      variant="subtle"
      class="pending-access__action"
      :loading="isSharing"
      data-testid="pending-access-share"
      @click="shareNow"
    >
      Share access now
    </UiButton>
  </div>
</template>

<style scoped>
.pending-access {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: var(--space-3);
  padding: var(--space-3) var(--space-4);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-card);
  background: var(--color-surface-2);
  color: var(--color-ink);
}

.pending-access__text {
  margin: 0;
  font-size: var(--text-body);
  font-weight: 600;
}

.pending-access__action {
  min-height: var(--target-min);
}
</style>
