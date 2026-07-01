<script setup>
/**
 * AreaChart — dependency-free SVG area/line chart (ywsel navy/amber).
 * Responsive via viewBox; mobile-first (fluid width, fixed aspect).
 * Accessible: role="img" + aria-label summary, plus an sr-only data table.
 */
import { computed } from 'vue'

const props = defineProps({
  data:   { type: Array, required: true },          // [number, ...]
  labels: { type: Array, default: () => [] },        // [string, ...] same length
  height: { type: Number, default: 200 },
  ariaLabel: { type: String, default: 'مخطط بياني' },
})

const W = 700                                  // viewBox width (scales to container)
const P = { t: 16, r: 12, b: 26, l: 12 }       // inner padding
const H = computed(() => props.height)

const max = computed(() => Math.max(...props.data, 1))
const min = computed(() => Math.min(...props.data, 0))

// Map each datum to an {x, y} point inside the padded plot area.
const points = computed(() => {
  const n = props.data.length
  const innerW = W - P.l - P.r
  const innerH = H.value - P.t - P.b
  const span = max.value - min.value || 1
  return props.data.map((v, i) => ({
    x: P.l + (n === 1 ? innerW / 2 : (i / (n - 1)) * innerW),
    y: P.t + innerH - ((v - min.value) / span) * innerH,
  }))
})

const linePath = computed(() => points.value.map((p, i) => `${i ? 'L' : 'M'}${p.x.toFixed(1)} ${p.y.toFixed(1)}`).join(' '))
const areaPath = computed(() => {
  const base = H.value - P.b
  const first = points.value[0], last = points.value[points.value.length - 1]
  return `${linePath.value} L${last.x.toFixed(1)} ${base} L${first.x.toFixed(1)} ${base} Z`
})
const last = computed(() => points.value[points.value.length - 1])
// Horizontal gridlines at 0 / 50 / 100% of the plot height.
const grid = computed(() => [0, 0.5, 1].map(t => P.t + (H.value - P.t - P.b) * t))
const uid = `ac-${Math.random().toString(36).slice(2, 8)}`
</script>

<template>
  <figure class="w-full" role="img" :aria-label="ariaLabel">
    <svg :viewBox="`0 0 ${W} ${H}`" class="w-full h-auto" preserveAspectRatio="none">
      <defs>
        <linearGradient :id="uid" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%"  stop-color="#F5A623" stop-opacity="0.28" />
          <stop offset="100%" stop-color="#F5A623" stop-opacity="0" />
        </linearGradient>
      </defs>

      <!-- gridlines -->
      <line v-for="(y, i) in grid" :key="i" :x1="P.l" :x2="W - P.r" :y1="y" :y2="y"
            stroke="#2A3450" stroke-width="1" stroke-dasharray="3 5" />

      <!-- area + line -->
      <path :d="areaPath" :fill="`url(#${uid})`" />
      <path :d="linePath" fill="none" stroke="#F5A623" stroke-width="2.5"
            stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />

      <!-- last point marker -->
      <circle :cx="last.x" :cy="last.y" r="4" fill="#F5A623" stroke="#0A0F1E" stroke-width="2.5" />
    </svg>

    <!-- x-axis labels (HTML, so they stay crisp & RTL-aware) -->
    <div v-if="labels.length" class="flex justify-between px-1 mt-1.5">
      <span v-for="(l, i) in labels" :key="i" class="text-[11px] text-fg-subtle font-data">{{ l }}</span>
    </div>

    <!-- sr-only data for screen readers -->
    <figcaption class="sr-only">
      <table>
        <tr v-for="(v, i) in data" :key="i"><th>{{ labels[i] ?? i + 1 }}</th><td>{{ v }}</td></tr>
      </table>
    </figcaption>
  </figure>
</template>
