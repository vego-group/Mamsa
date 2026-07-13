<template>
  <div class="min-h-screen bg-[#F7F7F4] flex flex-col" dir="rtl">
    <PublicHeader />

    <div class="flex-1 max-w-6xl w-full mx-auto px-4 py-6">
      <!-- Breadcrumb -->
      <nav class="flex items-center gap-1.5 text-body-sm text-on-surface-variant mb-5">
        <RouterLink :to="{ name: 'account' }" class="hover:text-primary">حجوزاتي</RouterLink>
        <span class="material-symbols-outlined text-[16px]">chevron_left</span>
        <span class="text-on-surface">تفاصيل الحجز</span>
      </nav>

      <!-- Loading -->
      <div v-if="loading" class="flex items-center justify-center py-24 text-on-surface-variant">
        <span class="material-symbols-outlined animate-spin text-3xl">progress_activity</span>
      </div>

      <!-- Not found -->
      <div v-else-if="!booking" class="text-center py-20 bg-white rounded-2xl border border-outline-variant">
        <span class="material-symbols-outlined text-5xl mb-3 block text-on-surface-variant">error</span>
        <p class="font-title-sm text-title-sm text-on-surface mb-1">تعذّر العثور على الحجز</p>
        <RouterLink :to="{ name: 'account' }" class="text-primary font-bold text-body-sm">العودة إلى حجوزاتي</RouterLink>
      </div>

      <template v-else>
        <!-- Cancelled banner -->
        <div
          v-if="st.key === 'cancelled'"
          class="mb-5 rounded-2xl bg-error-container/40 border border-error/30 px-5 py-4"
        >
          <p class="flex items-center gap-2 font-bold text-error mb-1">
            <span class="material-symbols-outlined text-[20px]">cancel</span>
            تم إلغاء هذا الحجز
          </p>
          <div class="text-body-sm text-on-surface-variant space-y-0.5">
            <p v-if="booking.cancellation?.cancelled_by_label">تم الإلغاء بواسطة: {{ booking.cancellation.cancelled_by_label }}</p>
            <p v-if="booking.cancellation?.reason">السبب: {{ booking.cancellation.reason }}</p>
            <p v-if="booking.cancelled_at">تاريخ الإلغاء: {{ hijri(booking.cancelled_at) }}</p>
            <p v-if="Number(refunded) > 0" class="flex items-center gap-1 text-emerald-600 font-medium">
              <span class="material-symbols-outlined text-[16px]">check_circle</span>
              تم استرداد {{ formatMoney(refunded) }} ر.س
            </p>
          </div>
        </div>

        <div class="grid lg:grid-cols-3 gap-6">
          <!-- Main column -->
          <div class="lg:col-span-2 space-y-5">
            <!-- Unit summary -->
            <section class="bg-white rounded-2xl border border-outline-variant overflow-hidden">
              <div class="relative h-56 sm:h-64 bg-surface-container">
                <img v-if="unitImage" :src="unitImage" :alt="unit?.name" class="w-full h-full object-cover" />
                <div v-else class="w-full h-full flex items-center justify-center">
                  <span class="material-symbols-outlined text-5xl text-on-surface-variant">apartment</span>
                </div>
                <span class="absolute top-3 right-3 inline-flex items-center gap-1 px-3 py-1 rounded-full text-[12px] font-bold" :class="st.chip">
                  <span class="material-symbols-outlined text-[15px]" style="font-variation-settings:'FILL' 1">{{ st.icon }}</span>
                  {{ st.label }}
                </span>
              </div>
              <div class="p-5 text-right">
                <div class="flex items-start justify-between gap-3 mb-1">
                  <h1 class="font-title-lg text-title-lg text-on-surface">{{ unit?.name || 'وحدة' }}</h1>
                  <span v-if="unit?.avg_rating" class="inline-flex items-center gap-1 text-body-sm font-bold text-amber-600 whitespace-nowrap">
                    <span class="material-symbols-outlined text-[18px]" style="font-variation-settings:'FILL' 1">star</span>
                    {{ unit.avg_rating }}
                  </span>
                </div>
                <p class="text-body-sm text-on-surface-variant mb-3">
                  <span class="material-symbols-outlined text-[16px] align-middle">location_on</span>
                  {{ unit?.city }}{{ unit?.district ? `، ${unit.district}` : '' }} — المملكة العربية السعودية
                </p>
                <div class="flex flex-wrap gap-x-6 gap-y-2 text-body-sm text-on-surface-variant">
                  <span class="inline-flex items-center gap-1"><span class="material-symbols-outlined text-[18px]">group</span>{{ unit?.capacity }} ضيوف</span>
                  <span class="inline-flex items-center gap-1"><span class="material-symbols-outlined text-[18px]">bed</span>{{ unit?.bedrooms }} غرف</span>
                  <span v-if="unit?.bathrooms" class="inline-flex items-center gap-1"><span class="material-symbols-outlined text-[18px]">bathtub</span>{{ unit.bathrooms }} حمامات</span>
                  <span v-if="unit?.area" class="inline-flex items-center gap-1"><span class="material-symbols-outlined text-[18px]">square_foot</span>{{ unit.area }} م²</span>
                </div>
              </div>
            </section>

            <!-- Booking details -->
            <section class="bg-white rounded-2xl border border-outline-variant p-5">
              <h2 class="font-title-sm text-title-sm text-on-surface mb-4 text-right">تفاصيل الحجز</h2>
              <dl class="divide-y divide-outline-variant/60">
                <Row icon="login" label="تاريخ الوصول" :value="hijri(booking.start_date)" />
                <Row icon="logout" label="تاريخ المغادرة" :value="hijri(booking.end_date)" />
                <Row icon="dark_mode" label="عدد الليالي" :value="`${booking.nights} ليالٍ`" />
                <Row icon="group" label="الضيوف" :value="`${booking.guests} ضيوف`" />
                <Row icon="confirmation_number" label="رمز الحجز" :value="booking.reference" mono />
                <Row icon="event" label="تاريخ الحجز" :value="hijri(booking.created_at)" />
              </dl>
            </section>

            <!-- Payment method -->
            <section v-if="booking.payment" class="bg-white rounded-2xl border border-outline-variant p-5">
              <h2 class="font-title-sm text-title-sm text-on-surface mb-4 text-right">طريقة الدفع</h2>
              <div class="flex items-center justify-between">
                <span class="inline-flex items-center gap-2 text-body-sm text-on-surface">
                  <span class="material-symbols-outlined text-[22px] text-primary">credit_card</span>
                  {{ paymentLabel }}
                </span>
                <span class="text-body-sm font-bold" :class="booking.payment.payment_status === 'paid' ? 'text-emerald-600' : 'text-amber-600'">
                  {{ booking.payment.payment_status === 'paid' ? 'مدفوع' : 'غير مدفوع' }}
                </span>
              </div>
            </section>

            <!-- House rules -->
            <section class="bg-white rounded-2xl border border-outline-variant p-5">
              <h2 class="font-title-sm text-title-sm text-on-surface mb-4 text-right">قواعد المنزل</h2>
              <ul class="space-y-2 text-body-sm text-on-surface-variant">
                <li v-if="unit?.checkin_time" class="flex items-center gap-2">
                  <span class="material-symbols-outlined text-[18px] text-primary">schedule</span>
                  تسجيل الوصول من {{ unit.checkin_time }} — المغادرة قبل {{ unit.checkout_time }}
                </li>
                <li class="flex items-center gap-2"><span class="material-symbols-outlined text-[18px] text-primary">smoke_free</span>ممنوع التدخين داخل الوحدة</li>
                <li class="flex items-center gap-2"><span class="material-symbols-outlined text-[18px] text-primary">celebration</span>الحفلات والمناسبات تتطلب موافقة مسبقة</li>
                <!-- FR-036: the policy frozen at payment time, never the unit's live one. -->
                <li v-if="booking.policy_snapshot?.tiers?.length" class="flex items-start gap-2">
                  <span class="material-symbols-outlined text-[18px] text-primary">policy</span>
                  <CancellationPolicyTiers :policy="booking.policy_snapshot" />
                </li>
                <li v-else-if="unit?.cancellation_policy" class="flex items-center gap-2">
                  <span class="material-symbols-outlined text-[18px] text-primary">policy</span>
                  سياسة الإلغاء: {{ cancellationText(unit.cancellation_policy) }}
                </li>
              </ul>
            </section>
          </div>

          <!-- Sidebar: price summary + actions -->
          <aside class="lg:col-span-1">
            <div class="bg-white rounded-2xl border border-outline-variant p-5 lg:sticky lg:top-6">
              <h2 class="font-title-sm text-title-sm text-on-surface mb-4 text-right">ملخص السعر</h2>
              <dl class="space-y-2.5 text-body-sm">
                <div class="flex items-center justify-between">
                  <dt class="text-on-surface-variant">{{ formatMoney(pricing.nightly_rate) }} ر.س × {{ pricing.nights }} ليالٍ</dt>
                  <dd class="text-on-surface font-medium">{{ formatMoney(pricing.subtotal) }} ر.س</dd>
                </div>
                <div class="flex items-center justify-between">
                  <dt class="text-on-surface-variant">رسوم الخدمة</dt>
                  <dd class="text-on-surface font-medium">{{ formatMoney(pricing.service_fee) }} ر.س</dd>
                </div>
                <div class="flex items-center justify-between">
                  <dt class="text-on-surface-variant">رسوم التنظيف</dt>
                  <dd class="text-on-surface font-medium">{{ formatMoney(pricing.cleaning_fee) }} ر.س</dd>
                </div>
                <div class="flex items-center justify-between">
                  <dt class="text-on-surface-variant">الضرائب</dt>
                  <dd class="text-on-surface font-medium">{{ formatMoney(pricing.taxes) }} ر.س</dd>
                </div>
              </dl>
              <div class="border-t border-outline-variant mt-4 pt-4 flex items-center justify-between">
                <span class="font-bold text-on-surface">المجموع الكلي</span>
                <span class="font-bold text-primary text-[22px] font-numeric-data">{{ formatMoney(pricing.total) }} <span class="text-body-sm font-normal">ر.س</span></span>
              </div>

              <!-- Actions -->
              <div class="mt-5 space-y-2.5">
                <RouterLink
                  v-if="st.key === 'new' && booking.payment?.payment_status !== 'paid'"
                  :to="{ name: 'payment', params: { id: booking.id } }"
                  class="w-full h-11 flex items-center justify-center gap-2 bg-primary text-on-primary rounded-xl font-bold hover:bg-primary-container transition-colors"
                >
                  <span class="material-symbols-outlined text-[20px]">payments</span>
                  أكمل الدفع
                </RouterLink>

                <button
                  v-if="['new', 'active'].includes(st.key)"
                  class="w-full h-11 flex items-center justify-center gap-2 bg-primary text-on-primary rounded-xl font-bold hover:bg-primary-container transition-colors"
                  @click="downloadConfirmation"
                >
                  <span class="material-symbols-outlined text-[20px]">download</span>
                  تحميل تأكيد الحجز
                </button>

                <button
                  v-if="st.key === 'ended' && !booking.review"
                  class="w-full h-11 flex items-center justify-center gap-2 bg-primary text-on-primary rounded-xl font-bold hover:bg-primary-container transition-colors"
                  @click="openReview"
                >
                  <span class="material-symbols-outlined text-[20px]">rate_review</span>
                  أضف تقييماً
                </button>
                <div v-else-if="st.key === 'ended' && booking.review" class="w-full h-11 flex items-center justify-center gap-1 text-emerald-600 font-bold border border-emerald-600/30 rounded-xl">
                  <span class="material-symbols-outlined text-[20px]">check_circle</span>
                  تم التقييم
                </div>

                <RouterLink
                  v-if="['ended', 'cancelled'].includes(st.key)"
                  :to="{ name: 'unit-detail', params: { id: unit?.id } }"
                  class="w-full h-11 flex items-center justify-center gap-2 bg-primary text-on-primary rounded-xl font-bold hover:bg-primary-container transition-colors"
                >
                  <span class="material-symbols-outlined text-[20px]">event_repeat</span>
                  احجز مرة أخرى
                </RouterLink>

                <button
                  v-if="st.key === 'active' && hostPhone"
                  class="w-full h-11 flex items-center justify-center gap-2 bg-white border border-outline-variant text-on-surface rounded-xl font-bold hover:bg-surface-container transition-colors"
                  @click="contactHost"
                >
                  <span class="material-symbols-outlined text-[20px]">call</span>
                  تواصل مع المضيف
                </button>

                <button
                  class="w-full h-11 flex items-center justify-center gap-2 bg-white border border-outline-variant text-on-surface rounded-xl font-bold hover:bg-surface-container transition-colors"
                  @click="shareDetails"
                >
                  <span class="material-symbols-outlined text-[20px]">share</span>
                  مشاركة التفاصيل
                </button>

                <button
                  v-if="['new', 'active'].includes(st.key)"
                  class="w-full h-11 flex items-center justify-center gap-2 bg-white border border-error text-error rounded-xl font-bold hover:bg-error-container transition-colors disabled:opacity-50"
                  :disabled="cancelBusy"
                  @click="cancelOpen = true"
                >
                  <span class="material-symbols-outlined text-[20px]">cancel</span>
                  إلغاء الحجز
                </button>
              </div>
            </div>
          </aside>
        </div>
      </template>
    </div>

    <PublicFooter />

    <!-- Cancel modal -->
    <Transition name="fade">
      <div v-if="cancelOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4" @click.self="cancelOpen = false">
        <div class="bg-white rounded-2xl w-full max-w-md p-6" dir="rtl">
          <div class="flex items-center justify-between mb-2">
            <h3 class="font-title-sm text-title-sm text-on-surface">إلغاء الحجز</h3>
            <button class="text-on-surface-variant hover:text-on-surface" @click="cancelOpen = false">
              <span class="material-symbols-outlined">close</span>
            </button>
          </div>
          <p class="text-body-sm text-on-surface-variant mb-4">
            سيتم إلغاء حجز <span class="font-bold text-on-surface">{{ unit?.name }}</span>.
            قد تختلف قيمة الاسترداد حسب سياسة الإلغاء.
          </p>
          <!-- FR-036: the frozen snapshot — matches exactly what the refund engine applies. -->
          <div v-if="booking?.policy_snapshot?.tiers?.length" class="bg-surface-container-low rounded-xl p-3.5 mb-4">
            <CancellationPolicyTiers :policy="booking.policy_snapshot" />
          </div>
          <p class="text-body-sm text-on-surface mb-2">سبب الإلغاء (اختياري)</p>
          <div class="flex flex-wrap gap-2 mb-3">
            <button
              v-for="r in cancelReasons" :key="r" type="button"
              class="px-3 py-1.5 rounded-full border text-[13px] transition-colors"
              :class="cancelReason === r ? 'bg-primary text-on-primary border-primary' : 'bg-white text-on-surface-variant border-outline-variant hover:border-primary'"
              @click="cancelReason = r"
            >{{ r }}</button>
          </div>
          <textarea
            v-model="cancelReason" rows="3" maxlength="500" placeholder="أو اكتب سبباً آخر…"
            class="w-full p-3 rounded-xl border border-outline-variant bg-white outline-none focus:border-primary focus:ring-1 focus:ring-primary/20 text-body-sm mb-4 resize-none"
          ></textarea>
          <div class="flex gap-2">
            <button class="flex-1 h-11 bg-white border border-outline-variant text-on-surface rounded-xl font-bold hover:bg-surface-container transition-colors" @click="cancelOpen = false">تراجع</button>
            <button
              class="flex-1 h-11 bg-error text-white rounded-xl font-bold hover:bg-error/90 transition-colors disabled:opacity-50 flex items-center justify-center gap-2"
              :disabled="cancelBusy" @click="confirmCancel"
            >
              <span v-if="cancelBusy" class="material-symbols-outlined animate-spin text-[20px]">progress_activity</span>
              تأكيد الإلغاء
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Review modal -->
    <Transition name="fade">
      <div v-if="reviewOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4" @click.self="reviewOpen = false">
        <div class="bg-white rounded-2xl w-full max-w-md p-6" dir="rtl">
          <div class="flex items-center justify-between mb-4">
            <h3 class="font-title-sm text-title-sm text-on-surface">تقييم {{ unit?.name }}</h3>
            <button class="text-on-surface-variant hover:text-on-surface" @click="reviewOpen = false">
              <span class="material-symbols-outlined">close</span>
            </button>
          </div>
          <div class="flex items-center justify-center gap-1 mb-4">
            <button v-for="n in 5" :key="n" @click="reviewRating = n" class="text-amber-500">
              <span class="material-symbols-outlined text-[34px]" :style="n <= reviewRating ? `font-variation-settings:'FILL' 1` : ''">star</span>
            </button>
          </div>
          <textarea
            v-model="reviewComment" rows="4" placeholder="شاركنا تجربتك (اختياري)"
            class="w-full p-3 rounded-xl border border-outline-variant bg-white outline-none focus:border-primary focus:ring-1 focus:ring-primary/20 text-body-sm mb-4 resize-none"
          ></textarea>
          <button
            class="w-full h-11 bg-primary text-on-primary rounded-xl font-bold hover:bg-primary-container transition-colors disabled:opacity-50 flex items-center justify-center gap-2"
            :disabled="reviewBusy || reviewRating === 0" @click="submitReview"
          >
            <span v-if="reviewBusy" class="material-symbols-outlined animate-spin text-[20px]">progress_activity</span>
            إرسال التقييم
          </button>
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
import { ref, computed, onMounted, h } from 'vue'
import { useRoute } from 'vue-router'
import PublicHeader from '@/components/public/PublicHeader.vue'
import PublicFooter from '@/components/public/PublicFooter.vue'
import CancellationPolicyTiers from '@/components/public/CancellationPolicyTiers.vue'
import { userApi } from '@/api/user'

// Small presentational row for the "تفاصيل الحجز" list.
const Row = (props) =>
  h('div', { class: 'flex items-center justify-between py-3' }, [
    h('dt', { class: 'inline-flex items-center gap-2 text-body-sm text-on-surface-variant' }, [
      h('span', { class: 'material-symbols-outlined text-[18px] text-primary' }, props.icon),
      props.label,
    ]),
    h('dd', { class: `text-body-sm text-on-surface font-medium${props.mono ? ' font-numeric-data tracking-wider' : ''}`, dir: props.mono ? 'ltr' : undefined }, props.value),
  ])
Row.props = ['icon', 'label', 'value', 'mono']

const route = useRoute()
const loading = ref(true)
const booking = ref(null)
const toast = ref(null)

const unit = computed(() => booking.value?.unit || null)
const pricing = computed(() => booking.value?.pricing || { nightly_rate: 0, nights: 0, subtotal: 0, service_fee: 0, cleaning_fee: 0, taxes: 0, total: 0 })
const refunded = computed(() => booking.value?.cancellation?.refunded_amount ?? booking.value?.payment?.refunded_amount ?? 0)
const hostPhone = computed(() => unit.value?.owner?.phone || null)
const paymentLabel = computed(() => {
  const m = booking.value?.payment?.payment_method
  const map = { creditcard: 'بطاقة ائتمانية', applepay: 'Apple Pay', mada: 'مدى', stcpay: 'STC Pay' }
  return map[m] || m || 'بطاقة'
})

const unitImage = computed(() => {
  const imgs = unit.value?.images || []
  return (imgs.find((i) => i.is_main) || imgs[0])?.url || null
})

/* ---- Status derivation (mirrors UserBookingsView) ---- */
function isPast(dateStr) {
  if (!dateStr) return false
  const end = new Date(dateStr)
  end.setHours(23, 59, 59, 999)
  return end < new Date()
}
function stateKey(b) {
  if (!b) return 'new'
  if (b.status === 'cancelled') return 'cancelled'
  if (b.status === 'pending') return 'new'
  if (b.status === 'confirmed') return isPast(b.end_date) ? 'ended' : 'active'
  if (b.status === 'completed') return 'ended'
  return 'new'
}
const STATE_META = {
  new:       { label: 'في انتظار التأكيد', chip: 'bg-amber-50 text-amber-700',   icon: 'schedule' },
  active:    { label: 'مؤكد',              chip: 'bg-emerald-50 text-emerald-700', icon: 'verified' },
  ended:     { label: 'مكتمل',             chip: 'bg-sky-50 text-sky-700',         icon: 'task_alt' },
  cancelled: { label: 'ملغي',              chip: 'bg-red-50 text-red-700',         icon: 'cancel' },
}
const st = computed(() => {
  const key = stateKey(booking.value)
  return { key, ...STATE_META[key] }
})

/* ---- Formatters ---- */
function formatMoney(v) {
  return new Intl.NumberFormat('en-US').format(Number(v) || 0)
}
const hijriFmt = new Intl.DateTimeFormat('ar-SA-u-ca-islamic-umalqura', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
function hijri(dateStr) {
  if (!dateStr) return '—'
  try { return hijriFmt.format(new Date(dateStr)) } catch { return dateStr }
}
function cancellationText(policy) {
  const map = { flexible: 'مرنة', moderate: 'متوسطة', strict: 'صارمة' }
  return map[policy] || policy || '—'
}
function showToast(msg, type = 'success') {
  toast.value = { msg, type }
  setTimeout(() => (toast.value = null), 2800)
}

async function load() {
  loading.value = true
  try {
    const { data } = await userApi.getBooking(route.params.id)
    booking.value = data.data ?? data ?? null
  } catch (e) {
    booking.value = null
  } finally {
    loading.value = false
  }
}

/* ---- Detail actions ---- */
function downloadConfirmation() {
  // Browser print dialog → "save as PDF"; keeps it dependency-free.
  window.print()
}
async function shareDetails() {
  const url = window.location.href
  const title = `حجز ${unit.value?.name || ''} — ${booking.value?.reference || ''}`
  try {
    if (navigator.share) {
      await navigator.share({ title, url })
    } else {
      await navigator.clipboard.writeText(url)
      showToast('تم نسخ رابط الحجز')
    }
  } catch { /* user dismissed share sheet */ }
}
function contactHost() {
  if (hostPhone.value) window.location.href = `tel:${hostPhone.value}`
}

/* ---- Cancel ---- */
const cancelOpen = ref(false)
const cancelReason = ref('')
const cancelBusy = ref(false)
const cancelReasons = ['تغيير في خطط السفر', 'وجدت خياراً أفضل', 'ظرف طارئ', 'خطأ في الحجز']

async function confirmCancel() {
  cancelBusy.value = true
  try {
    const reason = cancelReason.value.trim() || null
    const { data } = await userApi.cancelBooking(booking.value.id, reason)
    const refund = Number(data?.data?.refund_amount) || 0
    booking.value.status = 'cancelled'
    booking.value.cancelled_at = new Date().toISOString()
    booking.value.cancellation = {
      reason, cancelled_by: 'customer', cancelled_by_label: 'العميل',
      cancelled_at: booking.value.cancelled_at, refunded_amount: refund,
    }
    if (refund > 0) booking.value.payment = { ...(booking.value.payment || {}), refunded_amount: refund }
    cancelOpen.value = false
    showToast(refund > 0 ? 'تم الإلغاء وسيتم رد المبلغ المستحق' : 'تم إلغاء الحجز')
  } catch (e) {
    showToast(e.response?.data?.message || 'تعذّر الإلغاء', 'error')
  } finally {
    cancelBusy.value = false
  }
}

/* ---- Review ---- */
const reviewOpen = ref(false)
const reviewRating = ref(0)
const reviewComment = ref('')
const reviewBusy = ref(false)
function openReview() {
  reviewRating.value = 0
  reviewComment.value = ''
  reviewOpen.value = true
}
async function submitReview() {
  if (reviewRating.value === 0) return
  reviewBusy.value = true
  try {
    await userApi.submitReview({ booking_id: booking.value.id, rating: reviewRating.value, comment: reviewComment.value || null })
    booking.value.review = { rating: reviewRating.value, comment: reviewComment.value }
    reviewOpen.value = false
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
