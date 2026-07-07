<template>
  <section class="bg-white rounded-2xl border border-outline-variant shadow-sm p-6">
    <div class="flex items-center justify-between mb-1">
      <h2 class="font-title-sm text-title-sm text-primary">التقويم وإدارة التوافر</h2>
      <span v-if="calendar?.ical_synced_at" class="text-[11px] text-on-surface-variant">
        آخر مزامنة: {{ formatDateTime(calendar.ical_synced_at) }}
      </span>
    </div>
    <p class="text-body-sm text-on-surface-variant mb-5">
      أغلق التواريخ المحجوزة خارجياً يدوياً، أو فعّل مزامنة iCal مع المنصات الأخرى (Booking، Airbnb…) لمنع الحجز المزدوج.
    </p>

    <div v-if="loading" class="flex items-center justify-center py-10 text-on-surface-variant">
      <span class="material-symbols-outlined animate-spin text-2xl">progress_activity</span>
    </div>

    <template v-else-if="calendar">
      <!-- Export URL (نصدّره للمنصات الأخرى) -->
      <div class="mb-5">
        <label class="block text-body-sm font-bold text-on-surface mb-1.5">رابط تقويم وحدتك (تصدير)</label>
        <p class="text-[12px] text-on-surface-variant mb-2">أضِف هذا الرابط في خانة «Import calendar» لدى المنصة الأخرى ليقفل حجوزاتك هناك تلقائياً.</p>
        <div class="flex gap-2">
          <input :value="calendar.export_url" readonly dir="ltr" class="flex-1 p-3 rounded-lg border border-outline-variant bg-surface-container-low text-body-sm font-data text-on-surface-variant outline-none" @focus="$event.target.select()" />
          <button type="button" class="shrink-0 px-4 rounded-lg border border-outline-variant text-body-sm font-bold text-primary hover:bg-surface-container transition-colors flex items-center gap-1.5" @click="copyExport">
            <span class="material-symbols-outlined text-[18px]">{{ copied ? 'check' : 'content_copy' }}</span>
            {{ copied ? 'تم النسخ' : 'نسخ' }}
          </button>
        </div>
      </div>

      <!-- Import URL (نستورد من المنصات الأخرى) -->
      <div class="mb-6 pb-6 border-b border-outline-variant">
        <label class="block text-body-sm font-bold text-on-surface mb-1.5">رابط تقويم خارجي (استيراد iCal)</label>
        <p class="text-[12px] text-on-surface-variant mb-2">الصق رابط الـ .ics من المنصة الأخرى — سنسحبه كل ١٥ دقيقة ونقفل التواريخ المحجوزة هناك تلقائياً.</p>
        <div class="flex gap-2">
          <input v-model="importUrl" dir="ltr" placeholder="https://admin.booking.com/calendar/ical/….ics" class="input-field flex-1 text-body-sm font-data" />
          <button type="button" class="shrink-0 px-4 rounded-lg bg-primary text-on-primary text-body-sm font-bold hover:bg-primary-container transition-colors disabled:opacity-50 flex items-center gap-1.5" :disabled="savingUrl" @click="saveImportUrl">
            <span v-if="savingUrl" class="material-symbols-outlined animate-spin text-[18px]">progress_activity</span>
            حفظ ومزامنة
          </button>
        </div>
        <p v-if="urlError" class="text-error text-body-sm mt-2">{{ urlError }}</p>
        <p v-else-if="urlSaved" class="text-emerald-600 text-body-sm mt-2 flex items-center gap-1">
          <span class="material-symbols-outlined text-[16px]">check_circle</span>
          {{ importUrl ? 'تمت المزامنة بنجاح' : 'تم إيقاف المزامنة' }}
        </p>
      </div>

      <!-- Manual block form -->
      <div class="mb-4">
        <label class="block text-body-sm font-bold text-on-surface mb-2">إغلاق تواريخ يدوياً</label>
        <div class="grid grid-cols-1 sm:grid-cols-[1fr_1fr_1.2fr_auto] gap-2">
          <input v-model="block.start_date" type="date" dir="ltr" :min="today" class="input-field text-body-sm" />
          <input v-model="block.end_date" type="date" dir="ltr" :min="block.start_date || today" class="input-field text-body-sm" />
          <input v-model="block.note" type="text" placeholder="السبب (اختياري) — صيانة، حجز خارجي…" class="input-field text-body-sm" />
          <button type="button" class="px-4 rounded-lg bg-primary text-on-primary text-body-sm font-bold hover:bg-primary-container transition-colors disabled:opacity-50 h-full min-h-[46px]" :disabled="!block.start_date || !block.end_date || addingBlock" @click="addBlock">
            إغلاق
          </button>
        </div>
        <p v-if="blockError" class="text-error text-body-sm mt-2">{{ blockError }}</p>
        <p class="text-[12px] text-on-surface-variant mt-1.5">تاريخ النهاية = يوم المغادرة (غير مشمول في الإغلاق).</p>
      </div>

      <!-- Blocked + booked ranges -->
      <div v-if="ranges.length" class="space-y-2">
        <div v-for="r in ranges" :key="r.key" class="flex items-center gap-3 border border-outline-variant rounded-xl px-4 py-2.5">
          <span class="material-symbols-outlined text-[20px]" :class="r.iconCls">{{ r.icon }}</span>
          <div class="flex-1 min-w-0 text-right">
            <p class="text-body-sm font-semibold text-on-surface">{{ formatDate(r.start_date) }} ← {{ formatDate(r.end_date) }}</p>
            <p class="text-[12px] text-on-surface-variant truncate">{{ r.label }}</p>
          </div>
          <button v-if="r.removable" type="button" class="text-error hover:bg-error-container/50 rounded-lg p-1.5 transition-colors" title="فتح التواريخ" @click="removeBlock(r)">
            <span class="material-symbols-outlined text-[18px]">delete</span>
          </button>
        </div>
      </div>
      <p v-else class="text-center text-body-sm text-on-surface-variant py-6">لا توجد تواريخ مغلقة أو محجوزة قادمة</p>
    </template>
  </section>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { partnerApi } from '@/api/partner'

const props = defineProps({
  unitId: { type: [Number, String], required: true },
})

const loading = ref(true)
const calendar = ref(null)
const copied = ref(false)

const importUrl = ref('')
const savingUrl = ref(false)
const urlSaved = ref(false)
const urlError = ref('')

const today = new Date().toISOString().slice(0, 10)
const block = reactive({ start_date: '', end_date: '', note: '' })
const addingBlock = ref(false)
const blockError = ref('')

// Blocks + read-only booked ranges, merged and sorted for one list.
const ranges = computed(() => {
  if (!calendar.value) return []
  const blocks = (calendar.value.blocked_dates ?? []).map((b) => ({
    key: `b-${b.id}`,
    id: b.id,
    start_date: b.start_date,
    end_date: b.end_date,
    removable: b.source === 'manual',
    icon: b.source === 'manual' ? 'lock' : 'sync',
    iconCls: b.source === 'manual' ? 'text-on-surface-variant' : 'text-blue-600',
    label: b.source === 'manual' ? (b.note || 'إغلاق يدوي') : `مستورد من تقويم خارجي${b.note ? ` — ${b.note}` : ''}`,
  }))
  const booked = (calendar.value.booked ?? []).map((bk) => ({
    key: `k-${bk.id}`,
    start_date: bk.start_date,
    end_date: bk.end_date,
    removable: false,
    icon: 'event_available',
    iconCls: 'text-primary',
    label: bk.status === 'confirmed' ? 'حجز مؤكد عبر ممسى' : 'حجز قيد الدفع عبر ممسى',
  }))
  return [...blocks, ...booked].sort((a, c) => a.start_date.localeCompare(c.start_date))
})

function formatDate(iso) {
  return new Date(iso).toLocaleDateString('ar-SA', { day: 'numeric', month: 'long' })
}
function formatDateTime(iso) {
  return new Date(iso).toLocaleString('ar-SA', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })
}

async function copyExport() {
  try {
    await navigator.clipboard.writeText(calendar.value.export_url)
    copied.value = true
    setTimeout(() => (copied.value = false), 2000)
  } catch { /* HTTP context — the field is selectable as fallback */ }
}

async function saveImportUrl() {
  savingUrl.value = true
  urlError.value = ''
  urlSaved.value = false
  try {
    const { data } = await partnerApi.saveCalendarSettings(props.unitId, importUrl.value || null)
    calendar.value = data.data ?? data
    urlSaved.value = true
  } catch (e) {
    urlError.value = e.response?.data?.message || 'تعذّر حفظ الرابط'
  } finally {
    savingUrl.value = false
  }
}

async function addBlock() {
  addingBlock.value = true
  blockError.value = ''
  try {
    await partnerApi.addBlockedDates(props.unitId, { ...block, note: block.note || null })
    block.start_date = block.end_date = block.note = ''
    await load()
  } catch (e) {
    blockError.value = e.response?.data?.message || 'تعذّر إغلاق التواريخ'
  } finally {
    addingBlock.value = false
  }
}

async function removeBlock(r) {
  await partnerApi.removeBlockedDates(props.unitId, r.id)
  calendar.value.blocked_dates = calendar.value.blocked_dates.filter((b) => b.id !== r.id)
}

async function load() {
  try {
    const { data } = await partnerApi.getCalendar(props.unitId)
    calendar.value = data.data ?? data
    importUrl.value = calendar.value.ical_import_url || ''
  } catch {
    calendar.value = null
  }
  loading.value = false
}

onMounted(load)
</script>
