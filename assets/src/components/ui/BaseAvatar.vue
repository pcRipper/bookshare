<script setup>
import { computed } from 'vue'

const props = defineProps({
  src:  { type: String, default: null },
  name: { type: String, default: '' },
  size: {
    type: String,
    default: 'md',
    validator: v => ['sm', 'md', 'lg', 'xl'].includes(v),
  },
  alt: { type: String, default: null },
})

const initials = computed(() => {
  if (!props.name) return '?'
  return props.name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map(w => w[0].toUpperCase())
    .join('')
})

const altText = computed(() => props.alt ?? props.name ?? 'User avatar')

const sizeMap = { sm: '32px', md: '40px', lg: '80px', xl: '96px' }
const fontSize = { sm: '12px', md: '14px', lg: '24px', xl: '28px' }
</script>

<template>
  <div
    class="base-avatar"
    :class="`base-avatar--${size}`"
    :style="{ width: sizeMap[size], height: sizeMap[size] }"
    role="img"
    :aria-label="altText"
  >
    <img v-if="src" :src="src" :alt="altText" class="base-avatar__img" />
    <span v-else class="base-avatar__initials" :style="{ fontSize: fontSize[size] }">
      {{ initials }}
    </span>
  </div>
</template>

<style scoped>
.base-avatar {
  border-radius: var(--radius-full);
  border: 1px solid var(--color-outline-variant);
  overflow: hidden;
  background: var(--color-secondary-container);
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
}

.base-avatar__img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.base-avatar__initials {
  font-family: var(--font-body);
  font-weight: 600;
  color: var(--color-on-secondary-fixed-variant);
  line-height: 1;
  user-select: none;
}
</style>
