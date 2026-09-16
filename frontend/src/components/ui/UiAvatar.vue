<script setup lang="ts">
import { computed } from 'vue'
import { getInitials } from '@/utils/childHelpers'
import { kidPairFor } from '@/utils/kidColors'

/**
 * Child avatar: initials on a verified pastel/deep-ink kid pair.
 * The raw API avatar_color is only a seed — output color is always a
 * normalized pair from tokens.css.
 */
const props = withDefaults(
  defineProps<{
    id: number
    name: string
    colorHex?: string | null
    size?: number
    inlineStyle?: boolean
  }>(),
  { colorHex: undefined, size: 40, inlineStyle: true },
)

defineOptions({ inheritAttrs: false })

const pair = computed(() => kidPairFor(props.id, props.colorHex))
const initials = computed(() => getInitials(props.name))

const style = computed(() => {
  if (!props.inlineStyle) return undefined
  return {
    backgroundColor: pair.value.bg,
    color: pair.value.ink,
    width: `${props.size}px`,
    height: `${props.size}px`,
  }
})
</script>

<template>
  <span
    class="avatar"
    :style="style"
    :data-kid="pair.name"
    role="img"
    :aria-label="`Photo of ${name}`"
  >
    <span aria-hidden="true">{{ initials }}</span>
  </span>
</template>

<style scoped>
.avatar {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  font-weight: 800;
  font-size: var(--text-body);
  flex-shrink: 0;
}
</style>
