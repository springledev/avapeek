<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useChildStore } from '@/stores/child'
import { useRecentsStore } from '@/stores/recents'
import { useFeedStore } from '@/stores/feed'
import { Image, Clock, Settings, Upload, LogOut, User, Plus, ChevronRight, X } from '@lucide/vue'
import ThemeToggle from '@/components/ThemeToggle.vue'
import UploadModal from '@/components/UploadModal.vue'

const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()
const childStore = useChildStore()
const recentsStore = useRecentsStore()
const feedStore = useFeedStore()

const showUploadModal = ref(false)
const showUserMenu = ref(false)

const showShell = computed(() => {
  const authPaths = ['/login', '/register', '/invite/', '/child/create']
  return !authPaths.some(p => route.path.startsWith(p))
})

const activeTab = computed(() => {
  if (route.path.startsWith('/feed')) return 'feed'
  if (route.path.startsWith('/recents')) return 'recents'
  if (route.path.startsWith('/settings')) return 'settings'
  return ''
})

const canAddChild = computed(() =>
  childStore.children.some(c => c.role === 'owner' || c.role === 'admin')
)

function navigate(path: string) {
  router.push(path)
}

async function handleLogout() {
  showUserMenu.value = false
  await authStore.logout()
  childStore.clearDEKCache()
  router.push('/login')
}

function onUploadComplete() {
  showUploadModal.value = false
  feedStore.fetchPhotos()
}

onMounted(async () => {
  if (showShell.value) {
    await childStore.fetchChildren()
    recentsStore.fetchUnseenCount()
    feedStore.fetchPhotos()
  }
})
</script>

<template>
  <template v-if="showShell">
    <div class="flex min-h-screen flex-col bg-gray-50 dark:bg-slate-900">
      <header class="sticky top-0 z-40 border-b border-gray-200 bg-white/95 backdrop-blur supports-[backdrop-filter]:bg-white/80 dark:border-slate-700 dark:bg-slate-800/95 dark:supports-[backdrop-filter]:bg-slate-800/80">
        <div class="mx-auto flex h-14 max-w-7xl items-center justify-between px-4">
          <div class="flex items-center gap-6">
            <router-link to="/feed" class="flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white">
              <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-600 text-sm font-bold text-white">A</span>
              Avapeek
            </router-link>
            <nav class="hidden md:flex items-center gap-1">
              <router-link
                to="/feed"
                class="rounded-lg px-3 py-1.5 text-sm font-medium transition-colors"
                :class="activeTab === 'feed' ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-700'"
              >
                Feed
              </router-link>
              <router-link
                to="/recents"
                class="relative rounded-lg px-3 py-1.5 text-sm font-medium transition-colors"
                :class="activeTab === 'recents' ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-700'"
              >
                Recents
                <span
                  v-if="recentsStore.unseenCount > 0"
                  class="ml-1.5 inline-flex h-4 min-w-[16px] items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white"
                >
                  {{ recentsStore.unseenCount > 99 ? '99+' : recentsStore.unseenCount }}
                </span>
              </router-link>
            </nav>
          </div>

          <div class="flex items-center gap-2">
            <button
              v-if="canAddChild"
              @click="showUploadModal = true"
              class="hidden md:inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white transition-colors hover:bg-indigo-700 dark:bg-indigo-500 dark:hover:bg-indigo-600"
            >
              <Upload class="h-4 w-4" />
              Upload
            </button>

            <ThemeToggle />

            <div class="relative">
              <button
                @click="showUserMenu = !showUserMenu"
                class="flex h-8 w-8 items-center justify-center rounded-full bg-slate-200 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-300 dark:bg-slate-600 dark:text-slate-200 dark:hover:bg-slate-500"
              >
                {{ authStore.user?.display_name?.charAt(0)?.toUpperCase() || '?' }}
              </button>

              <div
                v-if="showUserMenu"
                class="absolute right-0 top-10 z-50 w-56 rounded-lg border border-gray-200 bg-white py-1 shadow-lg dark:border-slate-600 dark:bg-slate-800"
                @click.outside="showUserMenu = false"
              >
                <div class="border-b border-gray-100 px-4 py-2 dark:border-slate-600">
                  <p class="text-sm font-medium text-slate-900 dark:text-white">{{ authStore.user?.display_name }}</p>
                  <p class="text-xs text-slate-500 dark:text-slate-400">{{ authStore.user?.email }}</p>
                </div>
                <button
                  @click="navigate('/settings'); showUserMenu = false"
                  class="w-full px-4 py-2 text-left text-sm text-slate-700 hover:bg-gray-100 dark:text-slate-200 dark:hover:bg-slate-700"
                >
                  Settings
                </button>
                <button
                  v-if="canAddChild"
                  @click="navigate('/child/create'); showUserMenu = false"
                  class="w-full px-4 py-2 text-left text-sm text-slate-700 hover:bg-gray-100 dark:text-slate-200 dark:hover:bg-slate-700"
                >
                  Add Child
                </button>
                <div class="border-t border-gray-100 dark:border-slate-600">
                  <button
                    @click="handleLogout"
                    class="w-full px-4 py-2 text-left text-sm text-red-600 hover:bg-gray-100 dark:text-red-400 dark:hover:bg-slate-700"
                  >
                    Log out
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </header>

      <main class="mx-auto w-full max-w-7xl flex-1 px-4 py-6">
        <router-view />
      </main>

      <nav class="fixed bottom-0 left-0 right-0 z-40 border-t border-gray-200 bg-white/95 backdrop-blur supports-[backdrop-filter]:bg-white/80 dark:border-slate-700 dark:bg-slate-800/95 dark:supports-[backdrop-filter]:bg-slate-800/80 md:hidden">
        <div class="mx-auto flex max-w-lg items-center justify-around py-1">
          <router-link
            to="/feed"
            class="flex flex-col items-center gap-0.5 px-4 py-1.5 text-xs font-medium"
            :class="activeTab === 'feed' ? 'text-indigo-600 dark:text-indigo-400' : 'text-slate-500 dark:text-slate-400'"
          >
            <Image class="h-5 w-5" />
            <span>Feed</span>
          </router-link>

          <router-link
            to="/recents"
            class="relative flex flex-col items-center gap-0.5 px-4 py-1.5 text-xs font-medium"
            :class="activeTab === 'recents' ? 'text-indigo-600 dark:text-indigo-400' : 'text-slate-500 dark:text-slate-400'"
          >
            <Clock class="h-5 w-5" />
            <span>Recents</span>
            <span
              v-if="recentsStore.unseenCount > 0"
              class="absolute -right-1 -top-0.5 flex h-4 min-w-[16px] items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white"
            >
              {{ recentsStore.unseenCount > 99 ? '99+' : recentsStore.unseenCount }}
            </span>
          </router-link>

          <button
            v-if="canAddChild"
            @click="showUploadModal = true"
            class="flex flex-col items-center gap-0.5 px-4 py-1.5 text-xs font-medium text-slate-500 dark:text-slate-400"
          >
            <Upload class="h-5 w-5" />
            <span>Upload</span>
          </button>

          <router-link
            to="/settings"
            class="flex flex-col items-center gap-0.5 px-4 py-1.5 text-xs font-medium text-slate-500 dark:text-slate-400"
          >
            <Settings class="h-5 w-5" />
            <span>Settings</span>
          </router-link>
        </div>
      </nav>

      <div class="h-16 md:hidden" />

      <UploadModal v-if="showUploadModal" @close="showUploadModal = false" @upload-complete="onUploadComplete" />
    </div>
  </template>
  <template v-else>
    <router-view />
  </template>
</template>
