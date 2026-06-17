<script setup lang="ts">
import { ref, computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useChildStore } from '@/stores/child'
import ThemeToggle from '@/components/ThemeToggle.vue'

const authStore = useAuthStore()
const childStore = useChildStore()
const router = useRouter()
const route = useRoute()

const showChildrenDropdown = ref(false)
const showUserMenu = ref(false)

const isOnChildPage = computed(() => route.path.startsWith('/child/') && !route.path.endsWith('/create'))
const currentChild = computed(() => childStore.currentChild)

function navigateToChild(childId: number, slug: string) {
  childStore.setCurrentChild(childId)
  router.push(`/child/${slug}`)
  showChildrenDropdown.value = false
}

function goHome() {
  router.push('/')
  showChildrenDropdown.value = false
}

function goToCreateChild() {
  router.push('/child/create')
  showChildrenDropdown.value = false
}

async function handleLogout() {
  showUserMenu.value = false
  await authStore.logout()
  router.push('/login')
}
</script>

<template>
  <nav class="nav-bar">
    <div class="nav-container">
      <div class="nav-content">
        <div class="flex items-center gap-2">
          <div v-if="isOnChildPage" class="relative">
            <button
              @click="showChildrenDropdown = !showChildrenDropdown"
              class="flex items-center gap-2 px-2 py-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
            >
              <div
                class="w-3 h-3 rounded-full shrink-0"
                :style="{ backgroundColor: currentChild?.avatar_color || '#94A3B8' }"
              />
              <span class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ currentChild?.name || childStore.children[0]?.name || 'Avapeek' }}</span>
              <svg class="w-4 h-4 text-slate-400" :class="{ 'rotate-180': showChildrenDropdown }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
              </svg>
            </button>

            <div
              v-if="showChildrenDropdown"
              class="absolute left-0 top-full mt-1 w-56 bg-white dark:bg-slate-800 rounded-xl shadow-lg border border-slate-200 dark:border-slate-700 overflow-hidden z-50"
            >
              <div class="p-2">
                <button
                  @click="goHome"
                  class="w-full text-left px-3 py-2 rounded-lg text-sm text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700 transition-colors"
                >
                  <div class="flex items-center gap-2">
                    <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2 0v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                    </svg>
                    Home
                  </div>
                </button>
              </div>

              <div v-if="childStore.hasChildren" class="border-t border-slate-100 dark:border-slate-700">
                <div class="px-3 py-1.5 text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide">Children</div>
                <div class="pb-2">
                  <button
                    v-for="child in childStore.children"
                    :key="child.id"
                    @click="navigateToChild(child.id, child.slug)"
                    class="w-full text-left px-3 py-2 rounded-lg text-sm text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700 transition-colors flex items-center gap-2"
                    :class="{ 'bg-blue-50 dark:bg-blue-900/30 font-medium': child.id === currentChild?.id }"
                  >
                    <div class="w-2.5 h-2.5 rounded-full shrink-0" :style="{ backgroundColor: child.avatar_color || '#94A3B8' }" />
                    <span class="truncate">{{ child.name }}</span>
                  </button>
                </div>
              </div>

              <div class="border-t border-slate-100 dark:border-slate-700 p-2">
                <button
                  @click="goToCreateChild"
                  class="w-full text-left px-3 py-2 rounded-lg text-sm text-blue-600 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/30 transition-colors flex items-center gap-2"
                >
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                  </svg>
                  Add child
                </button>
              </div>
            </div>
          </div>

          <button v-else @click="goHome" class="flex items-center gap-2">
            <svg class="w-8 h-8 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
            </svg>
            <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">Avapeek</h1>
          </button>
        </div>

        <slot name="center" />

        <div class="flex items-center gap-2">
          <ThemeToggle />

          <div class="relative">
            <button
              @click="showUserMenu = !showUserMenu"
              class="flex items-center gap-2 px-2 py-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
            >
              <div class="w-7 h-7 rounded-full bg-blue-500 flex items-center justify-center text-white text-xs font-bold shrink-0">
                {{ authStore.currentUser?.display_name?.charAt(0)?.toUpperCase() || '?' }}
              </div>
              <span class="text-sm text-slate-600 dark:text-slate-400 hidden sm:inline">{{ authStore.currentUser?.display_name }}</span>
            </button>

            <div
              v-if="showUserMenu"
              class="absolute right-0 top-full mt-1 w-56 bg-white dark:bg-slate-800 rounded-xl shadow-lg border border-slate-200 dark:border-slate-700 overflow-hidden z-50"
              @click.self
            >
              <div class="px-4 py-3 border-b border-slate-100 dark:border-slate-700">
                <p class="text-sm font-medium text-slate-900 dark:text-slate-100 truncate">{{ authStore.currentUser?.display_name }}</p>
                <p class="text-xs text-slate-500 dark:text-slate-400 truncate">{{ authStore.currentUser?.email }}</p>
              </div>
              <div class="p-2">
                <button
                  @click="handleLogout"
                  class="w-full text-left px-3 py-2 rounded-lg text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30 transition-colors flex items-center gap-2"
                >
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                  </svg>
                  Logout
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </nav>
</template>

<style scoped>
.rotate-180 {
  transform: rotate(180deg);
}
</style>
