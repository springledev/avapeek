<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { toast } from 'vue-sonner'
import { useChildStore } from '@/stores/child'
import { usePushNotifications } from '@/composables/usePushNotifications'
import { getPreferences, setPreferences, type ChildPushPreference } from '@/services/push'

const childStore = useChildStore()
const push = usePushNotifications()

const preferences = ref<Map<number, ChildPushPreference>>(new Map())
const savingChildId = ref<number | null>(null)

const rows = computed(() =>
  childStore.children.map((child) => ({
    id: child.id,
    name: child.name,
    notify_photos: preferences.value.get(child.id)?.notify_photos ?? true,
    notify_comments: preferences.value.get(child.id)?.notify_comments ?? true,
    notify_joins: preferences.value.get(child.id)?.notify_joins ?? true,
    notify_admin_comments: preferences.value.get(child.id)?.notify_admin_comments ?? true,
    notify_access_requests: preferences.value.get(child.id)?.notify_access_requests ?? true,
    notify_access_granted: preferences.value.get(child.id)?.notify_access_granted ?? true,
    isAdmin: child.role === 'owner' || child.role === 'admin',
  })),
)

async function onToggleDevice() {
  await push.toggle()
}

type PreferenceKey = Exclude<keyof ChildPushPreference, 'child_id'>

async function onToggleType(childId: number, key: PreferenceKey) {
  const row = rows.value.find((r) => r.id === childId)
  if (!row || savingChildId.value !== null) return

  const next = {
    notify_photos: row.notify_photos,
    notify_comments: row.notify_comments,
    notify_joins: row.notify_joins,
    notify_admin_comments: row.notify_admin_comments,
    notify_access_requests: row.notify_access_requests,
    notify_access_granted: row.notify_access_granted,
    [key]: !row[key],
  }
  savingChildId.value = childId
  try {
    const saved = await setPreferences(childId, next)
    preferences.value = new Map(preferences.value).set(childId, saved)
  } catch {
    toast.error("Couldn't save that change — try again")
  } finally {
    savingChildId.value = null
  }
}

onMounted(async () => {
  if (childStore.children.length === 0) {
    await childStore.fetchChildren()
  }
  await push.init()
  if (push.available.value) {
    try {
      const loaded = await getPreferences()
      preferences.value = new Map(loaded.map((p) => [p.child_id, p]))
    } catch {
      /* defaults (everything on) stay in place */
    }
  }
})
</script>

<template>
  <section class="notify" data-testid="notification-settings">
    <h2 class="notify__title">Notifications</h2>

    <p v-if="push.available.value === false" class="notify__note" data-testid="push-unavailable">
      {{
        push.supported.value || push.needsInstall.value
          ? 'Notifications are not turned on for this server.'
          : 'This browser does not support notifications.'
      }}
    </p>

    <template v-else-if="push.available.value">
      <p v-if="push.needsInstall.value" class="notify__note" data-testid="push-ios-hint">
        Add Avapeek to your Home Screen first, then open it from there to turn on notifications.
      </p>

      <template v-else>
        <div class="notify__row">
          <div class="notify__text">
            <p class="notify__label">Notify on this device</p>
            <p class="notify__desc">
              A short alert when someone adds photos, comments or joins. It never shows names or
              content, and tapping it opens the right screen. Your browser's push service (Google, Apple, Mozilla or Microsoft) can see
              that a message arrived and when.
            </p>
          </div>
          <button
            type="button"
            role="switch"
            class="notify__switch"
            :class="{ 'notify__switch--on': push.subscribed.value }"
            :aria-checked="push.subscribed.value"
            aria-label="Notify on this device"
            data-testid="push-device-toggle"
            :disabled="push.busy.value || push.blocked.value"
            @click="onToggleDevice"
          >
            <span class="notify__knob" />
          </button>
        </div>

        <p v-if="push.blocked.value" class="notify__note" data-testid="push-blocked">
          Notifications are blocked for this site. Allow them in your browser settings.
        </p>
        <p v-if="push.error.value" class="notify__error" role="alert" data-testid="push-error">
          {{ push.error.value }}
        </p>

        <h3 class="notify__subtitle">What to notify about</h3>
        <p v-if="rows.length === 0" class="notify__note">No kids yet.</p>
        <div v-for="row in rows" :key="row.id" class="notify__child" data-testid="push-child-row">
          <p class="notify__label">{{ row.name }}</p>
          <div class="notify__switches">
            <label class="notify__option">
              <span>Photos</span>
              <button
                type="button"
                role="switch"
                class="notify__switch"
                :class="{ 'notify__switch--on': row.notify_photos }"
                :aria-checked="row.notify_photos"
                :aria-label="`Photos for ${row.name}`"
                :disabled="savingChildId !== null"
                data-testid="push-photos-toggle"
                @click="onToggleType(row.id, 'notify_photos')"
              >
                <span class="notify__knob" />
              </button>
            </label>
            <label class="notify__option">
              <span>Comments</span>
              <button
                type="button"
                role="switch"
                class="notify__switch"
                :class="{ 'notify__switch--on': row.notify_comments }"
                :aria-checked="row.notify_comments"
                :aria-label="`Comments for ${row.name}`"
                :disabled="savingChildId !== null"
                data-testid="push-comments-toggle"
                @click="onToggleType(row.id, 'notify_comments')"
              >
                <span class="notify__knob" />
              </button>
            </label>
          </div>
          <div v-if="row.isAdmin" class="notify__switches">
            <label class="notify__option">
              <span>Someone joins</span>
              <button
                type="button"
                role="switch"
                class="notify__switch"
                :class="{ 'notify__switch--on': row.notify_joins }"
                :aria-checked="row.notify_joins"
                :aria-label="`Someone joins ${row.name}`"
                :disabled="savingChildId !== null"
                data-testid="push-joins-toggle"
                @click="onToggleType(row.id, 'notify_joins')"
              >
                <span class="notify__knob" />
              </button>
            </label>
            <label class="notify__option">
              <span>Member comments</span>
              <button
                type="button"
                role="switch"
                class="notify__switch"
                :class="{ 'notify__switch--on': row.notify_admin_comments }"
                :aria-checked="row.notify_admin_comments"
                :aria-label="`Member comments on ${row.name}`"
                :disabled="savingChildId !== null"
                data-testid="push-admin-comments-toggle"
                @click="onToggleType(row.id, 'notify_admin_comments')"
              >
                <span class="notify__knob" />
              </button>
            </label>
            <label class="notify__option">
              <span>Access requests</span>
              <button
                type="button"
                role="switch"
                class="notify__switch"
                :class="{ 'notify__switch--on': row.notify_access_requests }"
                :aria-checked="row.notify_access_requests"
                :aria-label="`Access requests for ${row.name}`"
                :disabled="savingChildId !== null"
                data-testid="push-access-requests-toggle"
                @click="onToggleType(row.id, 'notify_access_requests')"
              >
                <span class="notify__knob" />
              </button>
            </label>
          </div>
          <div class="notify__switches">
            <label class="notify__option">
              <span>Access shared with me</span>
              <button
                type="button"
                role="switch"
                class="notify__switch"
                :class="{ 'notify__switch--on': row.notify_access_granted }"
                :aria-checked="row.notify_access_granted"
                :aria-label="`Access shared with me for ${row.name}`"
                :disabled="savingChildId !== null"
                data-testid="push-access-granted-toggle"
                @click="onToggleType(row.id, 'notify_access_granted')"
              >
                <span class="notify__knob" />
              </button>
            </label>
          </div>
          <p v-if="row.isAdmin" class="notify__desc">
            As an admin you also hear when someone joins (see them under Settings, Kids, then
            {{ row.name }}) and when a member comments on a photo (tap to open it). You are also told when a member is waiting for access, so you can share it.
          </p>
        </div>
      </template>
    </template>
  </section>
</template>

<style scoped>
.notify {
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
}

.notify__title {
  font-size: var(--text-title);
  font-weight: 800;
  color: var(--color-ink);
  margin: 0;
}

.notify__subtitle {
  font-size: var(--text-body);
  font-weight: 700;
  color: var(--color-ink);
  margin: var(--space-3) 0 0;
}

.notify__row,
.notify__child {
  display: flex;
  gap: var(--space-4);
  padding: var(--space-4);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-card);
  background: var(--color-surface);
}

.notify__row {
  align-items: center;
}

.notify__child {
  flex-direction: column;
  gap: var(--space-2);
}

.notify__text {
  flex: 1;
  min-width: 0;
}

.notify__label {
  font-size: var(--text-body);
  font-weight: 700;
  color: var(--color-ink);
  margin: 0;
}

.notify__desc,
.notify__note {
  font-size: var(--text-meta);
  color: var(--color-ink-soft);
  margin: 0;
}

.notify__error {
  font-size: var(--text-meta);
  color: var(--color-danger);
  margin: 0;
}

.notify__switches {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2) var(--space-6);
}

.notify__option {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  min-height: var(--target-min);
  color: var(--color-ink);
  font-size: var(--text-body);
}

.notify__switch {
  position: relative;
  flex-shrink: 0;
  width: 48px;
  height: 48px;
  border: 0;
  background: transparent;
  cursor: pointer;
  padding: 0;
}

.notify__switch::before {
  content: '';
  position: absolute;
  top: 10px;
  left: 0;
  width: 48px;
  height: 28px;
  border-radius: var(--radius-pill);
  background: var(--color-border);
  transition: background var(--dur-micro) var(--ease-soft);
}

.notify__switch--on::before {
  background: var(--color-accent);
}

.notify__switch:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.notify__knob {
  position: absolute;
  top: 12px;
  left: 2px;
  width: 24px;
  height: 24px;
  border-radius: 50%;
  background: var(--color-surface);
  box-shadow: var(--shadow-1);
  transition: transform var(--dur-micro) var(--ease-soft);
}

.notify__switch--on .notify__knob {
  transform: translateX(20px);
}
</style>
