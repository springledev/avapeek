<script setup lang="ts">
import { Plus, ChevronRight } from '@lucide/vue'
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useChildStore } from '@/stores/child'
import { useAuthStore } from '@/stores/auth'
import { generateDEK, wrapDEK, exportKEK, importKEK } from '@/crypto/keychain'

const router = useRouter()
const childStore = useChildStore()
const authStore = useAuthStore()

onMounted(async () => {
  if (childStore.children.length === 0) {
    await childStore.fetchChildren()
  }
})

async function createChild() {
  router.push('/child/create')
}
</script>

<template>
  <div class="mt-8">
    <div class="mb-4 flex items-center justify-between">
      <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Children</h2>
      <button
        @click="createChild"
        class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700"
      >
        <Plus class="h-4 w-4" />
        Add Child
      </button>
    </div>

    <div v-if="childStore.children.length === 0" class="rounded-xl border border-gray-200 bg-white p-8 text-center dark:border-slate-600 dark:bg-slate-800">
      <p class="text-slate-500 dark:text-slate-400">No children yet.</p>
      <button
        @click="createChild"
        class="mt-3 text-sm font-medium text-indigo-600 hover:text-indigo-700 dark:text-indigo-400"
      >
        Create your first child profile
      </button>
    </div>

    <div v-else class="space-y-3">
      <router-link
        v-for="child in childStore.children"
        :key="child.id"
        :to="`/settings/children/${child.id}`"
        class="flex items-center gap-3 rounded-xl border border-gray-200 bg-white p-4 transition-colors hover:bg-gray-50 dark:border-slate-600 dark:bg-slate-800 dark:hover:bg-slate-700/50"
      >
        <div
          class="flex h-10 w-10 items-center justify-center rounded-full text-sm font-bold text-white"
          :style="{ backgroundColor: child.avatar_color || '#6366f1' }"
        >
          {{ child.name.charAt(0).toUpperCase() }}
        </div>
        <div class="flex-1 min-w-0">
          <p class="font-medium text-slate-900 dark:text-white">{{ child.name }}</p>
          <p class="text-sm text-slate-500 dark:text-slate-400">
            {{ child.age }}
            <span v-if="child.member_count"> &middot; {{ child.member_count }} {{ child.member_count === 1 ? 'member' : 'members' }}</span>
            <span v-if="child.photo_count !== undefined"> &middot; {{ child.photo_count }} {{ child.photo_count === 1 ? 'photo' : 'photos' }}</span>
          </p>
        </div>
        <ChevronRight class="h-5 w-5 text-slate-400" />
      </router-link>
    </div>
  </div>
</template>
