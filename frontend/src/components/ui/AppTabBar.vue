<script lang="ts" setup>
import { computed } from 'vue'
import { LayoutGrid, Plus, Settings, Clock, LogOut } from '@lucide/vue'
import { useAuthStore } from '@/stores/auth'
import { signOut } from '@/lib/sign-out'
import { useRecentsStore } from '@/stores/recents'

const authStore = useAuthStore()
const recentsStore = useRecentsStore()

const items = computed(() => [
  { to: '/feed', label: 'Photos', icon: LayoutGrid },
  { to: '/recents', label: 'Recents', icon: Clock, badge: recentsStore.unseenCount },
  { to: '/upload', label: 'Upload', icon: Plus },
  { to: '/settings', label: 'Settings', icon: Settings },
])

async function handleLogout() {
  await signOut()
}
</script>

<template>
  <nav class="tabbar" aria-label="Main">
    <ul class="tabbar__list">
      <li v-for="item in items" :key="item.to" class="tabbar__item">
        <RouterLink :to="item.to" class="tabbar__link u-focus-ring">
          <component :is="item.icon" class="tabbar__icon" aria-hidden="true" />
          <span class="tabbar__label">{{ item.label }}</span>
          <span v-if="item.badge && item.badge > 0" class="tabbar__badge" aria-label="new">
            {{ item.badge > 99 ? '99+' : item.badge }}
          </span>
        </RouterLink>
      </li>

      <li class="tabbar__user">
        <div class="tabbar__user-avatar" aria-hidden="true">
          {{ authStore.currentUser?.display_name?.charAt(0)?.toUpperCase() || '?' }}
        </div>
        <span class="tabbar__user-name">{{ authStore.currentUser?.display_name }}</span>
        <button
          type="button"
          class="tabbar__user-logout u-focus-ring"
          aria-label="Log out"
          @click="handleLogout"
        >
          <LogOut class="tabbar__user-logout-icon" aria-hidden="true" />
        </button>
      </li>
    </ul>
  </nav>
</template>

<style scoped>
.tabbar {
  position: fixed;
  inset-inline: 0;
  bottom: 0;
  z-index: var(--z-tabbar);
  background: var(--color-surface);
  border-top: 1px solid var(--color-border);
  padding-bottom: var(--safe-area-b);
  box-shadow: 0 -1px 4px rgb(46 32 22 / 0.06);
}

.tabbar__list {
  display: flex;
  margin: 0;
  padding: 0;
  list-style: none;
}

.tabbar__item {
  flex: 1;
}

.tabbar__link {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 2px;
  min-height: var(--tabbar-h);
  color: var(--color-ink-soft);
  text-decoration: none;
  transition: color var(--dur-micro) var(--ease-soft);
}

.tabbar__link.router-link-active {
  color: var(--color-accent-ink);
}

.tabbar__icon {
  width: 24px;
  height: 24px;
}

.tabbar__label {
  font-size: var(--text-meta);
  font-weight: 600;
}

.tabbar__badge {
  position: absolute;
  top: var(--space-2);
  right: calc(50% - 20px);
  min-width: 18px;
  height: 18px;
  padding: 0 4px;
  border-radius: var(--radius-pill);
  background: var(--color-danger);
  color: var(--color-on-danger);
  font-size: 10px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  line-height: 1;
}

.tabbar__user {
  display: none;
}

@media (min-width: 640px) {
  .tabbar {
    position: sticky;
    top: 0;
    bottom: auto;
    inset-inline: 0 auto;
    width: 200px;
    min-width: 200px;
    height: 100dvh;
    border-top: none;
    border-right: 1px solid var(--color-border);
    padding-bottom: 0;
    box-shadow: none;
  }

  .tabbar__list {
    flex-direction: column;
    height: 100%;
    padding: var(--space-4) 0;
  }

  .tabbar__item {
    flex: none;
  }

  .tabbar__link {
    flex-direction: row;
    gap: var(--space-3);
    min-height: var(--target-min);
    padding: 0 var(--space-4);
    justify-content: flex-start;
  }

  .tabbar__link.router-link-active {
    background: var(--color-accent-soft);
    color: var(--color-accent-ink);
    border-radius: 0 var(--radius-card) var(--radius-card) 0;
    margin-right: var(--space-2);
  }

  .tabbar__badge {
    position: static;
    margin-left: auto;
  }

  .tabbar__user {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    margin-top: auto;
    margin-right: var(--space-2);
    padding: var(--space-3) var(--space-4);
    min-height: var(--target-min);
    border-radius: 0 var(--radius-card) var(--radius-card) 0;
    background: var(--color-surface);
    color: var(--color-ink);
  }

  .tabbar__user-avatar {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--color-accent-soft);
    color: var(--color-accent-ink);
    font-size: var(--text-body);
    font-weight: 800;
    flex-shrink: 0;
  }

  .tabbar__user-name {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: var(--text-body);
    font-weight: 700;
  }

  .tabbar__user-logout {
    display: flex;
    align-items: center;
    justify-content: center;
    width: var(--target-min);
    height: var(--target-min);
    border: 0;
    border-radius: var(--radius-card);
    background: transparent;
    color: var(--color-ink-soft);
    cursor: pointer;
    flex-shrink: 0;
    transition:
      background-color var(--dur-micro) var(--ease-soft),
      color var(--dur-micro) var(--ease-soft);
  }

  .tabbar__user-logout:hover {
    background: var(--color-surface-2);
    color: var(--color-danger);
  }

  .tabbar__user-logout-icon {
    width: 20px;
    height: 20px;
  }
}
</style>
