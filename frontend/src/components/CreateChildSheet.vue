<script setup lang="ts">
import UiSheet from '@/components/ui/UiSheet.vue'
import CreateChildForm from '@/components/CreateChildForm.vue'
import type { Child } from '@/types/child'

defineProps<{
  open: boolean
}>()

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'created', child: Child): void
}>()

function onCreated(child: Child) {
  emit('created', child)
  emit('close')
}
</script>

<template>
  <UiSheet :open="open" title="Add a kid" @close="emit('close')">
    <CreateChildForm @created="onCreated" />
  </UiSheet>
</template>
