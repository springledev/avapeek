<script setup lang="ts">
import { User, Users, ChevronRight } from '@lucide/vue'
</script>

<template>
  <div class="mx-auto max-w-2xl">
    <h1 class="mb-6 text-2xl font-bold text-slate-900 dark:text-white">Settings</h1>

    <div class="space-y-3">
      <router-link
        to="/settings/profile"
        class="flex items-center gap-4 rounded-xl border border-gray-200 bg-white p-4 transition-colors hover:bg-gray-50 dark:border-slate-600 dark:bg-slate-800 dark:hover:bg-slate-700/50"
      >
        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-100 dark:bg-indigo-900/30">
          <User class="h-5 w-5 text-indigo-600 dark:text-indigo-400" />
        </div>
        <div class="flex-1">
          <p class="font-medium text-slate-900 dark:text-white">Profile</p>
          <p class="text-sm text-slate-500 dark:text-slate-400">Name, email, password</p>
        </div>
        <ChevronRight class="h-5 w-5 text-slate-400" />
      </router-link>

      <router-link
        to="/settings/children"
        class="flex items-center gap-4 rounded-xl border border-gray-200 bg-white p-4 transition-colors hover:bg-gray-50 dark:border-slate-600 dark:bg-slate-800 dark:hover:bg-slate-700/50"
      >
        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-green-100 dark:bg-green-900/30">
          <Users class="h-5 w-5 text-green-600 dark:text-green-400" />
        </div>
        <div class="flex-1">
          <p class="font-medium text-slate-900 dark:text-white">Children</p>
          <p class="text-sm text-slate-500 dark:text-slate-400">Manage profiles, members, invitations</p>
        </div>
        <ChevronRight class="h-5 w-5 text-slate-400" />
      </router-link>
    </div>

    <router-view />
  </div>
</template>
