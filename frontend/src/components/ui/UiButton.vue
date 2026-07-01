<script setup>
/**
 * UiButton — enterprise button primitive (ywsel navy/amber dark theme).
 * Variants: primary | secondary | ghost | danger.  Sizes: sm | md.
 * Accessible: real <button>, visible focus ring, disabled + loading states.
 */
import { computed } from 'vue'

const props = defineProps({
  variant: { type: String, default: 'primary' }, // primary|secondary|ghost|danger
  size:    { type: String, default: 'md' },       // sm|md
  type:    { type: String, default: 'button' },
  icon:    { type: String, default: '' },          // Material Symbols name
  block:   { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
  disabled:{ type: Boolean, default: false },
})

const variants = {
  primary:   'bg-brand text-on-brand font-semibold hover:bg-brand-400 active:bg-brand-600 shadow-ink-card',
  secondary: 'bg-ink-850 text-fg border border-ink-700 hover:bg-ink-800 hover:border-brand/40',
  ghost:     'text-fg-muted hover:bg-ink-850 hover:text-fg',
  danger:    'bg-danger/15 text-danger border border-danger/30 hover:bg-danger/25',
}
const sizes = {
  sm: 'h-8 px-3 text-[13px] gap-1.5 rounded-lg',
  md: 'h-10 px-4 text-sm gap-2 rounded-lg',
}

const classes = computed(() => [
  'inline-flex items-center justify-center font-arabic select-none whitespace-nowrap',
  'transition-all duration-150 active:scale-[0.98]',
  'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55 focus-visible:ring-offset-2 focus-visible:ring-offset-ink-950',
  'disabled:opacity-50 disabled:pointer-events-none',
  variants[props.variant],
  sizes[props.size],
  props.block && 'w-full',
])
const iconSize = computed(() => (props.size === 'sm' ? 'text-[16px]' : 'text-[18px]'))
</script>

<template>
  <button :type="type" :class="classes" :disabled="disabled || loading" :aria-busy="loading">
    <span v-if="loading" class="material-symbols-outlined animate-spin" :class="iconSize">progress_activity</span>
    <span v-else-if="icon" class="material-symbols-outlined" :class="iconSize">{{ icon }}</span>
    <slot />
  </button>
</template>
