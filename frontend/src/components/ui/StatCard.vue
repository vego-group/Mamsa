<script setup>
/**
 * StatCard — KPI tile (ywsel). Mobile-first: full width, scales into grid columns.
 * Numerals use Inter + tabular-nums so values align in a row.
 */
import { computed } from 'vue'

const props = defineProps({
  label: { type: String, required: true },
  value: { type: [String, Number], required: true },
  delta: { type: String, default: '' },          // e.g. "+12%"
  trend: { type: String, default: 'flat' },        // up|down|flat
  icon:  { type: String, default: 'insights' },     // Material Symbols
})

const trendCls = computed(() => ({
  up:   'bg-ok/12 text-ok',
  down: 'bg-danger/12 text-danger',
  flat: 'bg-ink-800 text-fg-muted',
}[props.trend]))
const trendIcon = computed(() => ({ up: 'trending_up', down: 'trending_down', flat: 'trending_flat' }[props.trend]))
</script>

<template>
  <div class="bg-ink-900 border border-ink-700 rounded-2xl p-4 md:p-5 transition-colors hover:border-brand/25">
    <div class="flex items-start justify-between gap-2">
      <div class="flex items-center gap-2.5 min-w-0">
        <span class="grid place-items-center size-9 rounded-xl bg-brand/12 text-brand-300 shrink-0">
          <span class="material-symbols-outlined text-[20px]">{{ icon }}</span>
        </span>
        <span class="text-[11px] font-bold uppercase tracking-wider text-fg-subtle truncate font-arabic">
          {{ label }}
        </span>
      </div>
      <span v-if="delta"
            class="inline-flex items-center gap-0.5 h-6 ps-1.5 pe-2 rounded-full text-[12px] font-medium shrink-0"
            :class="trendCls">
        <span class="material-symbols-outlined text-[14px]">{{ trendIcon }}</span>{{ delta }}
      </span>
    </div>
    <p class="mt-3 font-data tabular-nums text-[28px] leading-none font-bold text-fg">{{ value }}</p>
  </div>
</template>
