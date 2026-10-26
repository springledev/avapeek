<script setup lang="ts">
import { MSG } from '@/lib/messages'
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import {
  User,
  Users,
  ChevronRight,
  ChevronLeft,
  LayoutGrid,
  Sun,
  Moon,
  ShieldCheck,
  Bell,
  LogOut,
} from '@lucide/vue'
import { api } from '@/services/api'
import { toast } from 'vue-sonner'
import { signOut } from '@/lib/sign-out'
import { useChildStore } from '@/stores/child'
import { useGridDensity, type FeedDensity } from '@/composables/useGridDensity'
import { useDarkMode } from '@/composables/useDarkMode'
import { usePushNotifications } from '@/composables/usePushNotifications'

const route = useRoute()
const childStore = useChildStore()
const { density, setDensity } = useGridDensity()
const { isDark, toggle: toggleDark } = useDarkMode()
const push = usePushNotifications()

const hasSubRoute = computed(() => route.matched.length > 1)
const backTarget = computed(() => {
  if (route.name === 'settings-child') return { to: '/settings/children', label: 'Kids' }
  return { to: '/settings', label: 'Settings' }
})

const isAdmin = computed(() =>
  childStore.children.some((c) => c.role === 'owner' || c.role === 'admin'),
)

const pendingInvites = ref<
  Array<{
    id: number
    child_name: string
    role: string
    relationship: string | null
    token: string
    expires_at: string
    created_at: string
  }>
>([])

async function fetchPendingInvites() {
  try {
    const { data } = await api.get('/invites/pending')
    pendingInvites.value = data.data
  } catch {
    /**/
  }
}

async function revokeInvite(id: number) {
  try {
    await api.delete(`/invites/${id}`)
    pendingInvites.value = pendingInvites.value.filter((i) => i.id !== id)
    toast.success(MSG.inviteRevoked)
  } catch (err: unknown) {
    const message =
      err instanceof Error && 'response' in err
        ? (err as { response?: { data?: { message?: string } } }).response?.data?.message
        : undefined
    toast.error(message || MSG.inviteRevokeFailed)
  }
}

function onDensityChange(value: FeedDensity) {
  setDensity(value)
}

async function handleLogout() {
  await signOut()
}

onMounted(() => {
  void fetchPendingInvites()
  void push.init()
})
</script>

<template>
  <div class="settings">
    <div v-if="hasSubRoute" class="settings__back">
      <router-link :to="backTarget.to" class="settings__back-link">
        <ChevronLeft :size="16" />
        {{ backTarget.label }}
      </router-link>
    </div>

    <h1 v-if="!hasSubRoute" class="settings__title">Settings</h1>

    <div v-if="!hasSubRoute" class="settings__sections">
      <router-link to="/settings/profile" class="settings__row">
        <div class="settings__icon settings__icon--accent">
          <User :size="20" />
        </div>
        <div class="settings__text">
          <p class="settings__label">Profile</p>
          <p class="settings__desc">Name, email, password</p>
        </div>
        <ChevronRight :size="20" class="settings__chevron" />
      </router-link>

      <router-link to="/settings/security" class="settings__row">
        <div class="settings__icon settings__icon--accent">
          <ShieldCheck :size="20" />
        </div>
        <div class="settings__text">
          <p class="settings__label">Security</p>
          <p class="settings__desc">Extra lock, authenticator, recovery codes</p>
        </div>
        <ChevronRight :size="20" class="settings__chevron" />
      </router-link>

      <router-link
        v-if="push.available.value"
        to="/settings/notifications"
        class="settings__row"
        data-testid="settings-notifications-link"
      >
        <div class="settings__icon settings__icon--accent">
          <Bell :size="20" />
        </div>
        <div class="settings__text">
          <p class="settings__label">Notifications</p>
          <p class="settings__desc">Alerts for new photos and comments</p>
        </div>
        <ChevronRight :size="20" class="settings__chevron" />
      </router-link>

      <div class="settings__row settings__row--inline">
        <div class="settings__icon settings__icon--accent">
          <LayoutGrid :size="20" />
        </div>
        <div class="settings__text">
          <p class="settings__label">Grid density</p>
          <p class="settings__desc">{{ density === '2' ? 'Comfortable' : 'Compact' }}</p>
        </div>
        <div class="settings__toggle-group">
          <button
            type="button"
            class="settings__toggle-btn"
            :class="{ 'settings__toggle-btn--active': density === '2' }"
            @click="onDensityChange('2')"
          >
            2
          </button>
          <button
            type="button"
            class="settings__toggle-btn"
            :class="{ 'settings__toggle-btn--active': density === '3' }"
            @click="onDensityChange('3')"
          >
            3
          </button>
        </div>
      </div>

      <div class="settings__row settings__row--inline">
        <div class="settings__icon settings__icon--accent">
          <component :is="isDark ? Moon : Sun" :size="20" />
        </div>
        <div class="settings__text">
          <p class="settings__label">Theme</p>
          <p class="settings__desc">{{ isDark ? 'Dark' : 'Light' }}</p>
        </div>
        <button
          type="button"
          class="settings__switch"
          :class="{ 'settings__switch--on': isDark }"
          @click="toggleDark"
        >
          <span class="settings__switch-knob" />
        </button>
      </div>

      <template v-if="isAdmin">
        <router-link to="/settings/children" class="settings__row">
          <div class="settings__icon settings__icon--accent">
            <Users :size="20" />
          </div>
          <div class="settings__text">
            <p class="settings__label">Kids</p>
            <p class="settings__desc">Manage profiles and access</p>
          </div>
          <ChevronRight :size="20" class="settings__chevron" />
        </router-link>
      </template>

      <button type="button" class="settings__row settings__row--danger" @click="handleLogout">
        <div class="settings__icon settings__icon--danger">
          <LogOut :size="20" />
        </div>
        <div class="settings__text">
          <p class="settings__label">Log out</p>
        </div>
      </button>
    </div>

    <div v-if="!hasSubRoute && isAdmin && pendingInvites.length > 0" class="settings__invites">
      <h3 class="settings__invites-title">Pending invites</h3>
      <div v-for="inv in pendingInvites" :key="inv.id" class="settings__invite-row">
        <div>
          <span class="settings__invite-name">{{ inv.child_name }}</span>
          <span class="settings__invite-meta"
            >{{ inv.role }}<span v-if="inv.relationship"> · {{ inv.relationship }}</span></span
          >
        </div>
        <div class="settings__invite-actions">
          <span class="settings__invite-expires"
            >Expires {{ new Date(inv.expires_at).toLocaleDateString() }}</span
          >
          <button type="button" class="settings__revoke" @click="revokeInvite(inv.id)">
            Revoke
          </button>
        </div>
      </div>
    </div>

    <router-view />
  </div>
</template>

<style scoped>
.settings {
  max-width: 32rem;
  margin: 0 auto;
}

.settings__back {
  margin-bottom: var(--space-4);
}

.settings__back-link {
  display: inline-flex;
  align-items: center;
  gap: var(--space-1);
  color: var(--color-ink-soft);
  font-size: var(--text-body);
  text-decoration: none;
}

.settings__back-link:hover {
  color: var(--color-ink);
}

.settings__title {
  font-size: var(--text-title);
  font-weight: 800;
  line-height: var(--lh-title);
  color: var(--color-ink);
  margin: 0 0 var(--space-6);
}

.settings__sections {
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
}

.settings__row {
  display: flex;
  align-items: center;
  gap: var(--space-4);
  padding: var(--space-4);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-card);
  background: var(--color-surface);
  text-decoration: none;
  color: var(--color-ink);
  transition: background var(--dur-micro) var(--ease-soft);
}

a.settings__row:hover {
  background: var(--color-surface-2);
}

.settings__row--inline {
  cursor: default;
}

.settings__icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  border-radius: var(--radius-card);
  flex-shrink: 0;
}

.settings__icon--accent {
  background: var(--color-accent-soft);
  color: var(--color-accent-ink);
}

.settings__icon--danger {
  background: var(--color-danger);
  color: var(--color-on-danger);
}

.settings__row--danger {
  cursor: pointer;
  border-color: var(--color-danger);
  text-align: start;
  width: 100%;
}

.settings__row--danger:hover {
  background: var(--color-surface-2);
}

.settings__text {
  flex: 1;
  min-width: 0;
}

.settings__label {
  font-size: var(--text-body);
  font-weight: 700;
  color: var(--color-ink);
  margin: 0;
}

.settings__desc {
  font-size: var(--text-meta);
  color: var(--color-ink-soft);
  margin: 0;
}

.settings__chevron {
  color: var(--color-ink-soft);
  flex-shrink: 0;
}

.settings__toggle-group {
  display: flex;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-pill);
  overflow: hidden;
}

.settings__toggle-btn {
  padding: var(--space-2) var(--space-3);
  border: 0;
  background: transparent;
  color: var(--color-ink-soft);
  font-family: inherit;
  font-size: var(--text-body);
  font-weight: 700;
  cursor: pointer;
  min-width: 40px;
  min-height: var(--target-min);
}

.settings__toggle-btn--active {
  background: var(--color-accent);
  color: var(--color-on-accent);
}

.settings__switch {
  position: relative;
  width: 48px;
  height: 28px;
  border: 0;
  border-radius: var(--radius-pill);
  background: var(--color-border);
  cursor: pointer;
  padding: 0;
  transition: background var(--dur-micro) var(--ease-soft);
}

.settings__switch--on {
  background: var(--color-accent);
}

.settings__switch-knob {
  position: absolute;
  top: 2px;
  left: 2px;
  width: 24px;
  height: 24px;
  border-radius: 50%;
  background: var(--color-surface);
  box-shadow: var(--shadow-1);
  transition: transform var(--dur-micro) var(--ease-soft);
}

.settings__switch--on .settings__switch-knob {
  transform: translateX(20px);
}

.settings__invites {
  margin-top: var(--space-6);
  padding: var(--space-4);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-card);
  background: var(--color-surface);
}

.settings__invites-title {
  font-size: var(--text-body);
  font-weight: 700;
  color: var(--color-ink);
  margin: 0 0 var(--space-3);
}

.settings__invite-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--space-2) 0;
  border-bottom: 1px solid var(--color-border);
}

.settings__invite-row:last-child {
  border-bottom: 0;
}

.settings__invite-name {
  font-weight: 600;
  color: var(--color-ink);
}

.settings__invite-meta {
  margin-left: var(--space-2);
  color: var(--color-ink-soft);
  font-size: var(--text-meta);
}

.settings__invite-actions {
  display: flex;
  align-items: center;
  gap: var(--space-3);
}

.settings__invite-expires {
  font-size: var(--text-meta);
  color: var(--color-ink-soft);
}

.settings__revoke {
  border: 0;
  background: transparent;
  color: var(--color-danger);
  font-family: inherit;
  font-size: var(--text-meta);
  font-weight: 600;
  cursor: pointer;
  padding: var(--space-1) var(--space-2);
  border-radius: var(--radius-card);
}

.settings__revoke:hover {
  background: var(--color-surface-2);
}
</style>
