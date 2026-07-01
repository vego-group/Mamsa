<template>
  <div class="min-h-screen bg-[#F7F7F4] flex flex-col" dir="rtl">
    <PublicHeader />

    <div class="flex-1 max-w-5xl w-full mx-auto px-4 py-8">
      <h1 class="font-display-lg text-display-lg text-primary mb-6 text-right">حجوزاتي</h1>

      <!-- Status tabs -->
      <div class="flex items-center gap-6 border-b border-outline-variant mb-6 overflow-x-auto">
        <button
          v-for="t in tabs"
          :key="t.key"
          class="pb-3 -mb-px border-b-2 font-title-sm text-[15px] whitespace-nowrap transition-colors"
          :class="activeTab === t.key
            ? 'border-primary text-primary'
            : 'border-transparent text-on-surface-variant hover:text-primary'"
          @click="activeTab = t.key"
        >
          {{ t.label }} ({{ counts[t.key] }})
        </button>
      </div>

      <!-- Loading -->
      <div v-if="loading" class="flex items-center justify-center py-20 text-on-surface-variant">
        <span class="material-symbols-outlined animate-spin text-3xl">progress_activity</span>
      </div>

      <!-- Empty -->
      <div v-else-if="filtered.length === 0" class="text-center py-16 bg-white rounded-2xl border border-outline-variant">
        <span class="material-symbols-outlined text-5xl mb-3 block text-on-surface-variant">luggage</span>
        <p class="font-title-sm text-title-sm text-on-surface mb-1">لا توجد حجوزات في هذا القسم</p>
        <p class="text-body-sm text-on-surface-variant mb-5">ابدأ باستكشاف الوحدات المتاحة</p>
        <RouterLink :to="{ name: 'home' }" class="inline-flex items-center gap-2 px-5 py-2.5 bg-primary text-on-primary rounded-xl font-bold">
          <span class="material-symbols-outlined text-[18px]">search</span>
          تصفح الوحدات
        </RouterLink>
      </div>

      <!-- Bookings -->
      <div v-else class="space-y-4">
        <article
          v-for="b in filtered"
          :key="b.id"
          class="bg-white rounded-2xl border border-outline-variant p-4 sm:p-5"
        >
          <div class="flex flex-col lg:flex-row gap-5">
            <!-- Image (right in RTL) -->
            <div class="relative lg:w-48 h-44 lg:h-36 rounded-xl overflow-hidden bg-surface-container flex-shrink-0 order-1">
              <img v-if="image(b)" :src="image(b)" :alt="b.unit?.name" class="w-full h-full object-cover" />
              <div v-else class="w-full h-full flex items-center justify-center">
                <span class="material-symbols-outlined text-3xl text-on-surface-variant">apartment</span>
              </div>
              <span class="absolute top-2 right-2 px-2 py-0.5 rounded-md bg-white/90 text-primary text-[10px] font-bold">ممسى</span>
            </div>

            <!-- Details + actions (middle) -->
            <div class="flex-1 min-w-0 order-3 lg:order-2 text-right">
              <div class="flex items-center gap-2 mb-1">
                <h3 class="font-title-sm text-title-sm text-on-surface truncate">{{ b.unit?.name || 'وحدة' }}</h3>
                <span class="inline-flex items-center gap-1 text-[12px] font-bold whitespace-nowrap" :class="state(b).text">
                  <span class="material-symbols-outlined text-[15px]" style="font-variation-settings:'FILL' 1">{{ state(b).icon }}</span>
                  {{ state(b).label }}
                </span>
              </div>
              <p class="text-body-sm text-on-surface-variant mb-0.5">
                {{ b.unit?.city }}{{ b.unit?.district ? `، ${b.unit.district}` : '' }} — المملكة العربية السعودية
              </p>
              <p v-if="b.unit?.owner?.name" class="text-body-sm text-on-surface-variant mb-3">
                المضيف: {{ b.unit.owner.name }}
              </p>

              <div class="flex flex-wrap gap-x-8 gap-y-2 text-body-sm mb-4">
                <div>
                  <p class="text-on-surface-variant text-[11px] mb-0.5">تسجيل الوصول</p>
                  <p class="text-on-surface font-medium">{{ hijri(b.start_date) }}</p>
                </div>
                <div>
                  <p class="text-on-surface-variant text-[11px] mb-0.5">الضيوف</p>
                  <p class="text-on-surface font-medium">{{ b.guests }} ضيوف</p>
                </div>
              </div>

              <div class="flex flex-wrap gap-2">
                <!-- Continue payment (new + unpaid) -->
                <RouterLink
                  v-if="state(b).key === 'new' && b.payment?.payment_status !== 'paid'"
                  :to="{ name: 'payment', params: { id: b.id } }"
                  class="px-4 py-2 bg-primary text-on-primary rounded-lg text-body-sm font-bold hover:bg-primary-container transition-colors"
                >
                  أكمل الدفع
                </RouterLink>

                <!-- View booking details -->
                <RouterLink
                  :to="{ name: 'booking-detail', params: { id: b.id } }"
                  class="px-4 py-2 bg-primary text-on-primary rounded-lg text-body-sm font-bold hover:bg-primary-container transition-colors"
                  :class="{ '!bg-white !text-primary border border-outline-variant hover:!bg-surface-container': ['ended', 'cancelled'].includes(state(b).key) }"
                >
                  عرض التفاصيل
                </RouterLink>

                <!-- Book again (ended / cancelled) -->
                <RouterLink
                  v-if="['ended', 'cancelled'].includes(state(b).key)"
                  :to="{ name: 'unit-detail', params: { id: b.unit?.id } }"
                  class="px-4 py-2 bg-primary text-on-primary rounded-lg text-body-sm font-bold hover:bg-primary-container transition-colors"
                >
                  احجز مرة أخرى
                </RouterLink>

                <!-- Review (ended) -->
                <button
                  v-if="state(b).key === 'ended' && !b.review"
                  class="px-4 py-2 bg-white border border-outline-variant text-on-surface rounded-lg text-body-sm font-bold hover:bg-surface-container transition-colors"
                  @click="openReview(b)"
                >
                  كتابة تقييم
                </button>
                <span
                  v-else-if="state(b).key === 'ended' && b.review"
                  class="px-4 py-2 inline-flex items-center gap-1 text-emerald-600 text-body-sm font-bold"
                >
                  <span class="material-symbols-outlined text-[18px]">check_circle</span>
                  تم التقييم
                </span>

                <!-- Cancel (new / active) -->
                <button
                  v-if="['new', 'active'].includes(state(b).key)"
                  class="px-4 py-2 bg-white border border-error text-error rounded-lg text-body-sm font-bold hover:bg-error-container transition-colors disabled:opacity-50"
                  :disabled="busyId === b.id"
                  @click="openCancel(b)"
                >
                  إلغاء الحجز
                </button>
              </div>
            </div>

            <!-- Price + checkout + confirmation code (left in RTL) -->
            <div class="order-2 lg:order-3 lg:w-52 flex flex-row-reverse lg:flex-col items-start lg:items-start justify-between lg:justify-start gap-3 lg:gap-4 lg:border-r lg:pr-5 border-outline-variant/60">
              <div>
                <span class="font-bold text-primary text-[22px] font-numeric-data">{{ formatMoney(b.total_amount) }}</span>
                <span class="text-on-surface-variant text-body-sm"> ر.س</span>
              </div>
              <div class="text-right">
                <p class="text-on-surface-variant text-[11px] mb-0.5">تسجيل المغادرة</p>
                <p class="text-on-surface font-medium text-body-sm">{{ hijri(b.end_date) }}</p>
              </div>
              <div class="text-right">
                <p class="text-on-surface-variant text-[11px] mb-0.5">رمز التأكيد</p>
                <p class="text-on-surface font-medium font-numeric-data tracking-wider text-body-sm" dir="ltr">{{ b.reference }}</p>
              </div>
            </div>
          </div>

          <!-- Cancellation notice -->
          <div
            v-if="state(b).key === 'cancelled'"
            class="mt-4 rounded-xl bg-error-container/40 border border-error/30 px-4 py-3 text-body-sm"
          >
            <p class="flex items-center gap-1.5 font-bold text-error mb-1.5">
              <span class="material-symbols-outlined text-[18px]">cancel</span>
              تم إلغاء هذا الحجز
            </p>
            <div class="space-y-0.5 text-on-surface-variant">
              <p v-if="b.cancellation?.cancelled_by_label">تم الإلغاء بواسطة: {{ b.cancellation.cancelled_by_label }}</p>
              <p v-if="b.cancellation?.reason">السبب: {{ b.cancellation.reason }}</p>
              <p v-if="b.cancelled_at">تاريخ الإلغاء: {{ hijri(b.cancelled_at) }}</p>
              <p v-if="Number(refundedAmount(b)) > 0" class="flex items-center gap-1 text-emerald-600 font-medium">
                <span class="material-symbols-outlined text-[16px]">check_circle</span>
                تم استرداد {{ formatMoney(refundedAmount(b)) }} ر.س
              </p>
            </div>
          </div>
        </article>
      </div>
    </div>

    <PublicFooter />

    <!-- Review modal -->
    <Transition name="fade">
      <div v-if="reviewFor" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4" @click.self="reviewFor = null">
        <div class="bg-white rounded-2xl w-full max-w-md p-6" dir="rtl">
          <div class="flex items-center justify-between mb-4">
            <h3 class="font-title-sm text-title-sm text-on-surface">تقييم {{ reviewFor.unit?.name }}</h3>
            <button class="text-on-surface-variant hover:text-on-surface" @click="reviewFor = null">
              <span class="material-symbols-outlined">close</span>
            </button>
          </div>

          <div class="flex items-center justify-center gap-1 mb-4">
            <button v-for="n in 5" :key="n" @click="reviewRating = n" class="text-amber-500">
              <span class="material-symbols-outlined text-[34px]" :style="n <= reviewRating ? `font-variation-settings:'FILL' 1` : ''">star</span>
            </button>
          </div>

          <textarea
            v-model="reviewComment"
            rows="4"
            placeholder="شاركنا تجربتك (اختياري)"
            class="w-full p-3 rounded-xl border border-outline-variant bg-white outline-none focus:border-primary focus:ring-1 focus:ring-primary/20 text-body-sm mb-4 resize-none"
          ></textarea>

          <button
            class="w-full h-11 bg-primary text-on-primary rounded-xl font-bold hover:bg-primary-container transition-colors disabled:opacity-50 flex items-center justify-center gap-2"
            :disabled="reviewBusy || reviewRating === 0"
            @click="submitReview"
          >
            <span v-if="reviewBusy" class="material-symbols-outlined animate-spin text-[20px]">progress_activity</span>
            إرسال التقييم
          </button>
        </div>
      </div>
    </Transition>

    <!-- Cancel modal -->
    <Transition name="fade">
      <div v-if="cancelFor" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4" @click.self="cancelFor = null">
        <div class="bg-white rounded-2xl w-full max-w-md p-6" dir="rtl">
          <div class="flex items-center justify-between mb-2">
            <h3 class="font-title-sm text-title-sm text-on-surface">إلغاء الحجز</h3>
            <button class="text-on-surface-variant hover:text-on-surface" @click="cancelFor = null">
              <span class="material-symbols-outlined">close</span>
            </button>
          </div>
          <p class="text-body-sm text-on-surface-variant mb-4">
            سيتم إلغاء حجز <span class="font-bold text-on-surface">{{ cancelFor.unit?.name }}</span>.
            قد تختلف قيمة الاسترداد حسب سياسة الإلغاء.
          </p>

          <p class="text-body-sm text-on-surface mb-2">سبب الإلغاء (اختياري)</p>
          <div class="flex flex-wrap gap-2 mb-3">
            <button
              v-for="r in cancelReasons"
              :key="r"
              type="button"
              class="px-3 py-1.5 rounded-full border text-[13px] transition-colors"
              :class="cancelReason === r
                ? 'bg-primary text-on-primary border-primary'
                : 'bg-white text-on-surface-variant border-outline-variant hover:border-primary'"
              @click="cancelReason = r"
            >
              {{ r }}
            </button>
          </div>
          <textarea
            v-model="cancelReason"
            rows="3"
            placeholder="أو اكتب سبباً آخر…"
            maxlength="500"
            class="w-full p-3 rounded-xl border border-outline-variant bg-white outline-none focus:border-primary focus:ring-1 focus:ring-primary/20 text-body-sm mb-4 resize-none"
          ></textarea>

          <div class="flex gap-2">
            <button
              class="flex-1 h-11 bg-white border border-outline-variant text-on-surface rounded-xl font-bold hover:bg-surface-container transition-colors"
              @click="cancelFor = null"
            >
              تراجع
            </button>
            <button
              class="flex-1 h-11 bg-error text-white rounded-xl font-bold hover:bg-error/90 transition-colors disabled:opacity-50 flex items-center justify-center gap-2"
              :disabled="cancelBusy"
              @click="confirmCancel"
            >
              <span v-if="cancelBusy" class="material-symbols-outlined animate-spin text-[20px]">progress_activity</span>
              تأكيد الإلغاء
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <Transition name="fade">
      <div v-if="toast" class="fixed bottom-6 left-1/2 -translate-x-1/2 z-50 px-5 py-3 rounded-xl shadow-lg text-white font-bold text-body-sm" :class="toast.type === 'error' ? 'bg-error' : 'bg-primary'">
        {{ toast.msg }}
      </div>
    </Transition>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import PublicHeader from '@/components/public/PublicHeader.vue'
import PublicFooter from '@/components/public/PublicFooter.vue'
import { userApi } from '@/api/user'

const loading = ref(true)
const busyId = ref(null)
const bookings = ref([])
const toast = ref(null)
const activeTab = ref('active')

const tabs = [
  { key: 'new',       label: 'جديدة' },
  { key: 'active',    label: 'نشطة' },
  { key: 'ended',     label: 'منتهية' },
  { key: 'cancelled', label: 'ملغاة' },
]

// Derive the design's 4 buckets from the backend status + end date.
function stateKey(b) {
  if (b.status === 'cancelled') return 'cancelled'
  if (b.status === 'pending') return 'new'
  if (b.status === 'confirmed') return isPast(b.end_date) ? 'ended' : 'active'
  return 'new'
}
function isPast(dateStr) {
  if (!dateStr) return false
  const end = new Date(dateStr)
  end.setHours(23, 59, 59, 999)
  return end < new Date()
}

const STATE_META = {
  new:       { label: 'في انتظار التأكيد', text: 'text-amber-600',   icon: 'schedule' },
  active:    { label: 'مؤكد',              text: 'text-emerald-600', icon: 'verified' },
  ended:     { label: 'مكتمل',             text: 'text-sky-600',     icon: 'task_alt' },
  cancelled: { label: 'ملغي',              text: 'text-red-600',     icon: 'cancel' },
}
function state(b) {
  const key = stateKey(b)
  return { key, ...STATE_META[key] }
}

const counts = computed(() =>
  tabs.reduce((acc, t) => {
    acc[t.key] = bookings.value.filter((b) => stateKey(b) === t.key).length
    return acc
  }, {})
)

const filtered = computed(() => bookings.value.filter((b) => stateKey(b) === activeTab.value))

function image(b) {
  const imgs = b.unit?.images || []
  return (imgs.find((i) => i.is_main) || imgs[0])?.url || null
}
function formatMoney(v) {
  return new Intl.NumberFormat('en-US').format(Number(v) || 0)
}
const hijriFmt = new Intl.DateTimeFormat('ar-SA-u-ca-islamic-umalqura', {
  weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
})
function hijri(dateStr) {
  if (!dateStr) return '—'
  try {
    return hijriFmt.format(new Date(dateStr))
  } catch {
    return dateStr
  }
}
function showToast(msg, type = 'success') {
  toast.value = { msg, type }
  setTimeout(() => (toast.value = null), 2800)
}

async function load() {
  loading.value = true
  try {
    const { data } = await userApi.bookings()
    bookings.value = data.data ?? data ?? []
    // Land on the first non-empty tab for a useful default.
    const firstWith = tabs.find((t) => bookings.value.some((b) => stateKey(b) === t.key))
    if (firstWith) activeTab.value = firstWith.key
  } catch (e) {
    showToast('تعذّر تحميل الحجوزات', 'error')
  } finally {
    loading.value = false
  }
}

/* ---- Cancel modal ---- */
const cancelFor = ref(null)
const cancelReason = ref('')
const cancelBusy = ref(false)
const cancelReasons = [
  'تغيير في خطط السفر',
  'وجدت خياراً أفضل',
  'ظرف طارئ',
  'خطأ في الحجز',
]

// Refund shown on the ملغي card: prefer the cancellation block, fall back to payment.
function refundedAmount(b) {
  return b.cancellation?.refunded_amount ?? b.payment?.refunded_amount ?? 0
}

function openCancel(b) {
  cancelFor.value = b
  cancelReason.value = ''
}

async function confirmCancel() {
  const b = cancelFor.value
  if (!b) return
  cancelBusy.value = true
  busyId.value = b.id
  try {
    const reason = cancelReason.value.trim() || null
    const { data } = await userApi.cancelBooking(b.id, reason)
    const refund = Number(data?.data?.refund_amount) || 0

    // Optimistically reflect the cancellation so the card re-renders immediately;
    // the authoritative cancellation block arrives on the next load().
    b.status = 'cancelled'
    b.status_label = 'ملغى'
    b.cancelled_at = new Date().toISOString()
    b.cancellation = {
      reason,
      cancelled_by: 'customer',
      cancelled_by_label: 'العميل',
      cancelled_at: b.cancelled_at,
      refunded_amount: refund,
    }
    if (refund > 0) b.payment = { ...(b.payment || {}), refunded_amount: refund }

    cancelFor.value = null
    showToast(refund > 0 ? 'تم الإلغاء وسيتم رد المبلغ المستحق' : 'تم إلغاء الحجز')
  } catch (e) {
    showToast(e.response?.data?.message || 'تعذّر الإلغاء', 'error')
  } finally {
    cancelBusy.value = false
    busyId.value = null
  }
}

/* ---- Review modal ---- */
const reviewFor = ref(null)
const reviewRating = ref(0)
const reviewComment = ref('')
const reviewBusy = ref(false)

function openReview(b) {
  reviewFor.value = b
  reviewRating.value = 0
  reviewComment.value = ''
}
async function submitReview() {
  if (!reviewFor.value || reviewRating.value === 0) return
  reviewBusy.value = true
  try {
    await userApi.submitReview({
      booking_id: reviewFor.value.id,
      rating: reviewRating.value,
      comment: reviewComment.value || null,
    })
    reviewFor.value.review = { rating: reviewRating.value, comment: reviewComment.value }
    reviewFor.value = null
    showToast('شكراً لتقييمك')
  } catch (e) {
    showToast(e.response?.data?.message || 'تعذّر إرسال التقييم', 'error')
  } finally {
    reviewBusy.value = false
  }
}

onMounted(load)
</script>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.25s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
