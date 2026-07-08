<template>
  <div class="border border-outline-variant rounded-xl p-4">
    <!-- Month navigation -->
    <div class="flex items-center justify-between mb-3">
      <button
        type="button"
        class="p-1.5 rounded-lg text-primary hover:bg-surface-container transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
        :disabled="isCurrentMonth"
        title="الشهر السابق"
        @click="shiftMonth(-1)"
      >
        <span class="material-symbols-outlined text-[20px]">chevron_right</span>
      </button>
      <p class="text-body-md font-bold text-on-surface">{{ monthTitle }}</p>
      <button
        type="button"
        class="p-1.5 rounded-lg text-primary hover:bg-surface-container transition-colors"
        title="الشهر التالي"
        @click="shiftMonth(1)"
      >
        <span class="material-symbols-outlined text-[20px]">chevron_left</span>
      </button>
    </div>

    <!-- Weekday headers (Saturday-first) -->
    <div class="grid grid-cols-7 mb-1">
      <span v-for="w in weekdays" :key="w" class="text-center text-[11px] font-bold text-on-surface-variant py-1">{{ w }}</span>
    </div>

    <!-- Day grid -->
    <div class="grid grid-cols-7 gap-1">
      <span v-for="n in leadingBlanks" :key="`blank-${n}`" />
      <button
        v-for="day in days"
        :key="day.iso"
        type="button"
        class="relative aspect-square rounded-lg text-body-sm font-data flex flex-col items-center justify-center transition-all outline-none"
        :class="dayClasses(day)"
        :disabled="day.state === 'past'"
        :title="day.title"
        @click="onDayClick(day)"
      >
        {{ day.num }}
        <span
          v-if="day.icon"
          class="material-symbols-outlined text-[11px] leading-none absolute bottom-0.5"
        >{{ day.icon }}</span>
      </button>
    </div>

    <!-- Selection hint -->
    <p v-if="selStart && !selEnd" class="text-body-sm text-primary mt-3 flex items-center gap-1.5">
      <span class="material-symbols-outlined text-[16px]">touch_app</span>
      اختر يوم النهاية لتحديد فترة الإغلاق — أو اضغط اليوم نفسه لإغلاق ليلة واحدة
    </p>
    <p v-else-if="selError" class="text-error text-body-sm mt-3">{{ selError }}</p>

    <!-- Legend -->
    <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 mt-4 pt-3 border-t border-outline-variant">
      <span v-for="l in legend" :key="l.label" class="flex items-center gap-1.5 text-[12px] text-on-surface-variant">
        <span class="w-3.5 h-3.5 rounded" :class="l.swatch" />
        {{ l.label }}
      </span>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'

const props = defineProps({
  blockedDates: { type: Array, default: () => [] }, // {id, start_date, end_date, source, note}
  booked: { type: Array, default: () => [] },       // {id, start_date, end_date, status}
})

const emit = defineEmits(['select', 'remove-block'])

const today = localIso(new Date())
const view = ref(startOfMonth(new Date()))
const selStart = ref('')
const selEnd = ref('')
const selError = ref('')

const weekdays = ['سبت', 'أحد', 'اثنين', 'ثلاثاء', 'أربعاء', 'خميس', 'جمعة']

const legend = [
  { label: 'متاح', swatch: 'bg-white border border-outline-variant' },
  { label: 'حجز ممسى', swatch: 'bg-primary' },
  { label: 'قيد الدفع', swatch: 'bg-primary/20 border border-primary/40' },
  { label: 'إغلاق يدوي', swatch: 'bg-on-surface/15' },
  { label: 'مستورد iCal', swatch: 'bg-blue-100 border border-blue-300' },
]

const monthFmt = new Intl.DateTimeFormat('ar-SA-u-ca-gregory-nu-latn', { month: 'long', year: 'numeric' })
const monthTitle = computed(() => monthFmt.format(view.value))

const isCurrentMonth = computed(() => localIso(view.value).slice(0, 7) <= today.slice(0, 7))

// Saturday-first column of the month's first day: JS Sunday=0…Saturday=6 → Sat=0…Fri=6.
const leadingBlanks = computed(() => (view.value.getDay() + 1) % 7)

// A day belongs to a range when start <= day < end (end_date = checkout, exclusive).
const days = computed(() => {
  const y = view.value.getFullYear()
  const m = view.value.getMonth()
  const count = new Date(y, m + 1, 0).getDate()
  const list = []
  for (let d = 1; d <= count; d++) {
    const iso = `${y}-${String(m + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`
    list.push({ iso, num: d, ...stateOf(iso) })
  }
  return list
})

function stateOf(iso) {
  if (iso < today) return { state: 'past', title: '' }
  const bk = props.booked.find((b) => b.start_date <= iso && iso < b.end_date)
  if (bk) {
    return bk.status === 'confirmed'
      ? { state: 'booked', title: 'حجز مؤكد عبر ممسى', icon: 'event_available' }
      : { state: 'pending', title: 'حجز قيد الدفع عبر ممسى', icon: 'schedule' }
  }
  const bl = props.blockedDates.find((b) => b.start_date <= iso && iso < b.end_date)
  if (bl) {
    return bl.source === 'manual'
      ? { state: 'manual', block: bl, title: `${bl.note || 'إغلاق يدوي'} — اضغط للفتح`, icon: 'lock' }
      : { state: 'ical', title: `مستورد من تقويم خارجي${bl.note ? ` — ${bl.note}` : ''}`, icon: 'sync' }
  }
  return { state: 'free', title: '' }
}

function dayClasses(day) {
  const inSel = selStart.value && day.iso >= selStart.value && (selEnd.value ? day.iso <= selEnd.value : day.iso === selStart.value)
  return {
    past:    'text-on-surface-variant/40 cursor-default',
    booked:  'bg-primary text-on-primary cursor-default',
    pending: 'bg-primary/20 text-primary border border-dashed border-primary/40 cursor-default',
    manual:  'bg-on-surface/15 text-on-surface line-through decoration-1 hover:ring-2 hover:ring-error/60',
    ical:    'bg-blue-100 text-blue-700 cursor-default',
    free:    inSel
      ? 'bg-primary/10 text-primary ring-2 ring-primary'
      : 'bg-white border border-outline-variant text-on-surface hover:border-primary hover:text-primary',
  }[day.state]
}

function shiftMonth(dir) {
  view.value = new Date(view.value.getFullYear(), view.value.getMonth() + dir, 1)
}

function onDayClick(day) {
  selError.value = ''

  // Manual block → offer to reopen it (parent confirms + deletes).
  if (day.state === 'manual') {
    emit('remove-block', day.block)
    return
  }
  if (day.state !== 'free') return

  // First click = range start; second = range end (any order).
  if (!selStart.value || selEnd.value) {
    selStart.value = day.iso
    selEnd.value = ''
    return
  }

  const [start, last] = [selStart.value, day.iso].sort()

  // Every night in the range must be free — no closing over bookings/blocks.
  const clash = eachIso(start, last).find((iso) => stateOf(iso).state !== 'free')
  if (clash) {
    selStart.value = selEnd.value = ''
    selError.value = 'الفترة المحددة تتضمن تواريخ محجوزة أو مغلقة — اختر فترة متاحة بالكامل'
    return
  }

  selStart.value = start
  selEnd.value = last
  // end_date is the checkout day (exclusive) → the day after the last closed night.
  emit('select', { start_date: start, end_date: addDays(last, 1) })
}

function clearSelection() {
  selStart.value = selEnd.value = selError.value = ''
}
defineExpose({ clearSelection })

/* ---- date utils (local time — never toISOString, it shifts across midnight UTC) ---- */
function localIso(d) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
function startOfMonth(d) {
  return new Date(d.getFullYear(), d.getMonth(), 1)
}
function addDays(iso, n) {
  const [y, m, d] = iso.split('-').map(Number)
  return localIso(new Date(y, m - 1, d + n))
}
function eachIso(start, end) {
  const out = []
  for (let iso = start; iso <= end; iso = addDays(iso, 1)) out.push(iso)
  return out
}
</script>
