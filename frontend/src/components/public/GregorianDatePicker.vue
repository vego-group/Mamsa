<template>
  <div class="relative" ref="root">
    <!-- Trigger (looks like the old .field input) -->
    <button
      type="button"
      class="w-full px-3.5 py-2.5 bg-surface-container-low border border-outline-variant rounded-xl text-body-sm flex items-center justify-between gap-2 transition-all"
      :class="open ? 'ring-2 ring-primary/20 border-primary' : ''"
      dir="ltr"
      @click="toggle"
    >
      <span :class="modelValue ? 'text-on-surface font-numeric-data' : 'text-on-surface-variant'">
        {{ modelValue ? display(modelValue) : placeholder }}
      </span>
      <span class="material-symbols-outlined text-[18px] text-on-surface-variant">calendar_month</span>
    </button>

    <!-- Gregorian calendar popup (always Gregorian, our own render) -->
    <div
      v-if="open"
      class="absolute z-40 mt-1 w-[280px] bg-white border border-outline-variant rounded-xl shadow-lg p-3"
      dir="ltr"
    >
      <div class="flex items-center justify-between mb-2">
        <button type="button" class="w-8 h-8 grid place-items-center rounded-lg hover:bg-surface-container" @click="shift(-1)">
          <span class="material-symbols-outlined text-[18px]">chevron_left</span>
        </button>
        <span class="font-bold text-body-sm text-on-surface">{{ monthLabel }}</span>
        <button type="button" class="w-8 h-8 grid place-items-center rounded-lg hover:bg-surface-container" @click="shift(1)">
          <span class="material-symbols-outlined text-[18px]">chevron_right</span>
        </button>
      </div>

      <div class="grid grid-cols-7 mb-1">
        <span v-for="d in weekdays" :key="d" class="text-center text-[11px] text-on-surface-variant py-1">{{ d }}</span>
      </div>

      <div class="grid grid-cols-7 gap-0.5">
        <template v-for="(cell, i) in cells" :key="i">
          <button
            v-if="cell"
            type="button"
            class="h-9 rounded-lg text-body-sm font-numeric-data transition-colors"
            :class="cellClass(cell)"
            :disabled="cell.disabled"
            @click="select(cell)"
          >
            {{ cell.day }}
          </button>
          <span v-else></span>
        </template>
      </div>
    </div>
  </div>
</template>

<script setup>
/**
 * Self-rendered Gregorian date picker — the native <input type="date"> follows
 * the macOS calendar setting (Hijri for ar-SA users), which no attribute
 * reliably overrides. v-model is an ISO `YYYY-MM-DD` string (same as the API).
 */
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'

const props = defineProps({
  modelValue: { type: String, default: '' },
  min: { type: String, default: '' },          // YYYY-MM-DD; earlier days disabled
  placeholder: { type: String, default: 'اختر التاريخ' },
})
const emit = defineEmits(['update:modelValue'])

const root = ref(null)
const open = ref(false)

// Arabic Gregorian month name + Latin year → e.g. "يوليو 2026".
const monthFmt = new Intl.DateTimeFormat('ar-u-ca-gregory', { month: 'long' })
const weekdays = ['أحد', 'إثن', 'ثلا', 'أرب', 'خمي', 'جمع', 'سبت']

const parse = (s) => {
  if (!s) return null
  const [y, m, d] = s.split('-').map(Number)
  return { y, m: m - 1, d }
}
const iso = (y, m, d) => `${y}-${String(m + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`

// The month currently shown in the grid.
const view = ref((() => {
  const p = parse(props.modelValue) || parse(props.min) || (() => { const t = new Date(); return { y: t.getFullYear(), m: t.getMonth() } })()
  return { y: p.y, m: p.m }
})())

watch(() => props.modelValue, (v) => {
  const p = parse(v)
  if (p) view.value = { y: p.y, m: p.m }
})

const monthLabel = computed(() =>
  `${monthFmt.format(new Date(view.value.y, view.value.m, 1))} ${view.value.y}`,
)

const cells = computed(() => {
  const { y, m } = view.value
  const first = new Date(y, m, 1).getDay()          // 0 = Sunday
  const days = new Date(y, m + 1, 0).getDate()
  const out = []
  for (let i = 0; i < first; i++) out.push(null)
  for (let d = 1; d <= days; d++) {
    const value = iso(y, m, d)
    out.push({ day: d, value, disabled: props.min ? value < props.min : false })
  }
  return out
})

function cellClass(cell) {
  if (cell.value === props.modelValue) return 'bg-primary text-on-primary font-bold'
  if (cell.disabled) return 'text-on-surface-variant/40 cursor-not-allowed'
  return 'text-on-surface hover:bg-surface-container'
}

function shift(dir) {
  let { y, m } = view.value
  m += dir
  if (m < 0) { m = 11; y-- }
  if (m > 11) { m = 0; y++ }
  view.value = { y, m }
}

function select(cell) {
  if (cell.disabled) return
  emit('update:modelValue', cell.value)
  open.value = false
}

function toggle() { open.value = !open.value }

function display(s) {
  const p = parse(s)
  return p ? `${String(p.d).padStart(2, '0')}/${String(p.m + 1).padStart(2, '0')}/${p.y}` : ''
}

function onDocClick(e) {
  if (open.value && root.value && !root.value.contains(e.target)) open.value = false
}
onMounted(() => document.addEventListener('click', onDocClick))
onBeforeUnmount(() => document.removeEventListener('click', onDocClick))
</script>
