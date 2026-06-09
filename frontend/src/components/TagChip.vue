<script setup lang="ts">
defineProps<{
  name: string
  clickable?: boolean
  removable?: boolean
}>()

const emit = defineEmits<{
  click: []
  remove: []
}>()
</script>

<template>
  <span
    class="tag-chip"
    :class="{ 'cursor-pointer': clickable }"
    @click="clickable && emit('click')"
  >
    {{ name }}
    <button
      v-if="removable"
      class="tag-chip-remove"
      @click.stop="emit('remove')"
    >
      &times;
    </button>
  </span>
</template>
