<template>
  <div class="min-h-screen bg-[#F7F7F4]" dir="rtl">
    <PublicHeader />

    <div v-if="loading" class="flex items-center justify-center py-32 text-on-surface-variant">
      <span class="material-symbols-outlined animate-spin text-3xl">progress_activity</span>
    </div>

    <div v-else-if="!unit" class="text-center py-32 text-on-surface-variant">
      <span class="material-symbols-outlined text-5xl mb-3 block">error_outline</span>
      <p class="font-title-sm text-title-sm mb-4">الوحدة غير متاحة</p>
      <RouterLink :to="{ name: 'home' }" class="text-primary font-bold underline">العودة للرئيسية</RouterLink>
    </div>

    <template v-else>
      <div class="max-w-6xl mx-auto px-4 py-6">
        <!-- Breadcrumb -->
        <nav class="flex items-center gap-2 text-body-sm text-on-surface-variant mb-4">
          <RouterLink :to="{ name: 'home' }" class="hover:text-primary">الرئيسية</RouterLink>
          <span class="material-symbols-outlined text-[16px]">chevron_left</span>
          <RouterLink :to="{ name: 'explore' }" class="hover:text-primary">العقارات</RouterLink>
          <span class="material-symbols-outlined text-[16px]">chevron_left</span>
          <span class="text-primary font-bold truncate">{{ unit.name }}</span>
        </nav>

        <!-- Title row -->
        <div class="flex items-start justify-between gap-4 mb-4">
          <div class="text-right min-w-0">
            <h1 class="font-display-lg text-[24px] sm:text-[28px] text-primary mb-1.5">{{ unit.name }}</h1>
            <div class="flex items-center flex-wrap gap-x-3 gap-y-1 text-body-sm text-on-surface-variant">
              <span class="flex items-center gap-1 text-on-surface">
                <span class="material-symbols-outlined text-[16px] text-amber-500" style="font-variation-settings:'FILL' 1">star</span>
                <span class="font-bold">{{ unit.avg_rating || '—' }}</span>
                <span class="text-on-surface-variant">· {{ unit.reviews_count || 0 }} تقييم</span>
              </span>
              <span class="flex items-center gap-1">
                <span class="material-symbols-outlined text-[16px]">location_on</span>
                {{ unit.city }}، المملكة العربية السعودية
              </span>
            </div>
          </div>
          <button
            class="flex items-center gap-1.5 px-4 py-2 rounded-full border border-outline-variant text-body-sm font-bold transition-colors shrink-0"
            :class="saved ? 'text-error border-error/40 bg-error/5' : 'text-on-surface hover:bg-surface-container'"
            @click="saved = !saved"
          >
            <span class="material-symbols-outlined text-[18px]" :style="saved ? `font-variation-settings:'FILL' 1` : ''">favorite</span>
            حفظ
          </button>
        </div>

        <!-- Gallery -->
        <div class="relative grid grid-cols-1 md:grid-cols-2 gap-3 mb-8">
          <button class="rounded-2xl overflow-hidden h-72 md:h-[420px] bg-surface-container group" @click="openLightbox(0)">
            <img v-if="images[0]" :src="images[0]" :alt="unit.name" class="w-full h-full object-cover group-hover:scale-[1.02] transition-transform duration-300" />
            <div v-else class="w-full h-full flex items-center justify-center"><span class="material-symbols-outlined text-5xl text-on-surface-variant">apartment</span></div>
          </button>
          <div class="grid grid-cols-2 gap-3">
            <button
              v-for="i in 4"
              :key="i"
              class="rounded-xl overflow-hidden h-32 md:h-[204px] bg-surface-container group"
              @click="openLightbox(i)"
            >
              <img v-if="images[i]" :src="images[i]" :alt="`صورة ${i + 1}`" class="w-full h-full object-cover group-hover:scale-[1.03] transition-transform duration-300" />
              <div v-else class="w-full h-full flex items-center justify-center"><span class="material-symbols-outlined text-2xl text-on-surface-variant/50">image</span></div>
            </button>
          </div>
          <button
            v-if="images.length > 1"
            class="absolute bottom-4 left-4 flex items-center gap-1.5 px-4 py-2 rounded-xl bg-white/95 text-on-surface text-body-sm font-bold shadow-card hover:bg-white transition-colors"
            @click="openLightbox(0)"
          >
            <span class="material-symbols-outlined text-[18px]">grid_view</span>
            عرض جميع الصور
          </button>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
          <!-- Details (right in RTL) -->
          <div class="lg:col-span-2 space-y-8 order-2 lg:order-1">
            <!-- About -->
            <section v-if="unit.description">
              <h2 class="font-title-sm text-title-sm text-primary mb-3">حول هذا المسكن</h2>
              <p class="text-body-md text-on-surface-variant leading-loose">{{ unit.description }}</p>
            </section>

            <!-- Specs -->
            <section class="flex items-center flex-wrap gap-x-5 gap-y-3 py-5 border-y border-outline-variant">
              <span class="flex items-center gap-2 text-body-sm text-on-surface"><span class="material-symbols-outlined text-[20px] text-primary">group</span>{{ unit.capacity }} ضيوف</span>
              <span class="text-outline-variant">·</span>
              <span class="flex items-center gap-2 text-body-sm text-on-surface"><span class="material-symbols-outlined text-[20px] text-primary">bed</span>{{ unit.bedrooms }} غرف نوم</span>
              <span class="text-outline-variant">·</span>
              <span class="flex items-center gap-2 text-body-sm text-on-surface"><span class="material-symbols-outlined text-[20px] text-primary">bathtub</span>{{ unit.bathrooms || 1 }} حمامات</span>
              <template v-if="unit.area">
                <span class="text-outline-variant">·</span>
                <span class="flex items-center gap-2 text-body-sm text-on-surface"><span class="material-symbols-outlined text-[20px] text-primary">square_foot</span>{{ unit.area }} م²</span>
              </template>
            </section>

            <!-- Amenities -->
            <section v-if="unit.features?.length">
              <h2 class="font-title-sm text-title-sm text-primary mb-5">ما يقدمه هذا المسكن</h2>
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-4">
                <div v-for="f in unit.features" :key="f" class="flex items-center gap-3 text-body-md text-on-surface">
                  <span class="material-symbols-outlined text-[22px] text-primary">{{ amenityIcon(f) }}</span>
                  {{ f }}
                </div>
              </div>
            </section>

            <!-- Things to know -->
            <section class="pt-2">
              <h2 class="font-title-sm text-title-sm text-primary mb-5">أشياء يجب معرفتها</h2>
              <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
                <div>
                  <div class="flex items-center gap-2 mb-2"><span class="material-symbols-outlined text-[20px] text-primary">schedule</span><h3 class="font-bold text-on-surface text-body-md">قواعد البيت</h3></div>
                  <p class="text-body-sm text-on-surface-variant leading-relaxed">تسجيل الوصول بعد {{ (unit.checkin_time || '15:00').slice(0,5) }}<br />تسجيل المغادرة قبل {{ (unit.checkout_time || '12:00').slice(0,5) }}</p>
                </div>
                <div>
                  <div class="flex items-center gap-2 mb-2"><span class="material-symbols-outlined text-[20px] text-primary">shield</span><h3 class="font-bold text-on-surface text-body-md">السلامة والأمان</h3></div>
                  <p class="text-body-sm text-on-surface-variant leading-relaxed">يتوفر جهاز للكشف عن أول أكسيد الكربون وطفاية حريق.</p>
                </div>
                <div>
                  <div class="flex items-center gap-2 mb-2"><span class="material-symbols-outlined text-[20px] text-primary">event_available</span><h3 class="font-bold text-on-surface text-body-md">سياسة الإلغاء</h3></div>
                  <CancellationPolicyTiers
                    v-if="unit.cancellation_policy_details?.tiers?.length"
                    :policy="unit.cancellation_policy_details"
                  />
                  <p v-else class="text-body-sm text-on-surface-variant leading-relaxed">{{ cancellationText(unit.cancellation_policy) }}</p>
                </div>
              </div>
            </section>

            <!-- Reviews summary -->
            <section class="pt-6 border-t border-outline-variant">
              <div class="flex items-center gap-2 mb-4">
                <span class="material-symbols-outlined text-[22px] text-amber-500" style="font-variation-settings:'FILL' 1">star</span>
                <h2 class="font-title-sm text-title-sm text-on-surface">{{ unit.avg_rating || '—' }} · {{ unit.reviews_count || 0 }} تقييماً</h2>
              </div>
              <p v-if="!unit.reviews_count" class="text-body-sm text-on-surface-variant">كن أول من يقيّم هذه الوحدة بعد إقامتك.</p>
            </section>

            <!-- Map -->
            <section class="pt-2">
              <h2 class="font-title-sm text-title-sm text-primary mb-1">أين ستكون</h2>
              <p class="text-body-sm text-on-surface-variant mb-4">{{ unit.city }}، المملكة العربية السعودية</p>
              <div class="rounded-2xl overflow-hidden border border-outline-variant h-72 bg-surface-container">
                <iframe
                  v-if="unit.lat && unit.lng"
                  :src="mapSrc"
                  class="w-full h-full"
                  style="border:0"
                  loading="lazy"
                  referrerpolicy="no-referrer-when-downgrade"
                  title="موقع الوحدة"
                ></iframe>
                <img
                  v-else
                  src="/decor/location.jpg"
                  alt="خريطة الموقع"
                  class="w-full h-full object-cover"
                  loading="lazy"
                />
              </div>
            </section>
          </div>

          <!-- Booking widget (left in RTL) -->
          <div class="lg:col-span-1 order-1 lg:order-2">
            <div class="bg-white rounded-2xl border border-outline-variant p-6 shadow-card lg:sticky lg:top-20">
              <div class="flex items-baseline gap-1 mb-5">
                <span class="font-display-lg text-[26px] text-primary font-numeric-data">{{ formatMoney(unit.price) }}</span>
                <span class="text-on-surface-variant text-body-sm">ر.س / ليلة</span>
              </div>

              <div class="space-y-3 mb-4">
                <div class="grid grid-cols-2 gap-2">
                  <div>
                    <label class="block text-[12px] font-bold text-on-surface-variant mb-1.5">تسجيل الوصول</label>
                    <input v-model="booking.start_date" type="date" :min="today" class="field" dir="ltr" />
                  </div>
                  <div>
                    <label class="block text-[12px] font-bold text-on-surface-variant mb-1.5">تسجيل المغادرة</label>
                    <input v-model="booking.end_date" type="date" :min="booking.start_date || today" class="field" dir="ltr" />
                  </div>
                </div>
                <div>
                  <label class="block text-[12px] font-bold text-on-surface-variant mb-1.5">الضيوف</label>
                  <input v-model.number="booking.guests" type="number" min="1" :max="unit.capacity" placeholder="أدخل العدد" class="field" dir="rtl" />
                </div>
              </div>

              <!-- Price summary -->
              <div v-if="nights > 0" class="py-3 border-t border-outline-variant space-y-2 mb-4">
                <div class="flex justify-between text-body-sm text-on-surface-variant">
                  <span class="font-numeric-data">{{ formatMoney(unit.price) }} × {{ nights }} ليالٍ</span>
                  <span class="font-numeric-data">{{ formatMoney(totalAmount) }} ر.س</span>
                </div>
                <div class="flex justify-between font-bold text-on-surface pt-2 border-t border-outline-variant">
                  <span>الإجمالي</span>
                  <span class="font-numeric-data text-primary">{{ formatMoney(totalAmount) }} ر.س</span>
                </div>
              </div>

              <button
                class="w-full py-3 bg-primary text-on-primary rounded-xl font-bold hover:bg-primary-container transition-colors flex items-center justify-center gap-2 disabled:opacity-50"
                :disabled="booking_busy"
                @click="handleBook"
              >
                <span v-if="booking_busy" class="material-symbols-outlined animate-spin text-[18px]">progress_activity</span>
                {{ auth.isAuthenticated ? 'احجز' : 'سجّل الدخول للحجز' }}
              </button>

              <p v-if="bookMsg" class="text-center text-body-sm mt-3" :class="bookError ? 'text-error' : 'text-emerald-600'">
                {{ bookMsg }}
              </p>
              <p v-else class="text-center text-body-sm text-on-surface-variant mt-3">لن يتم خصم أي مبلغ بعد</p>
            </div>
          </div>
        </div>
      </div>

      <PublicFooter />
    </template>

    <!-- Lightbox -->
    <Transition name="fade">
      <div v-if="lightbox" class="fixed inset-0 z-50 bg-black/90 flex items-center justify-center p-4" @click="lightbox = false">
        <button class="absolute top-4 left-4 grid w-11 h-11 place-items-center rounded-full bg-white/10 text-white hover:bg-white/20 transition-colors" @click.stop="lightbox = false">
          <span class="material-symbols-outlined">close</span>
        </button>
        <button v-if="images.length > 1" class="absolute right-4 grid w-11 h-11 place-items-center rounded-full bg-white/10 text-white hover:bg-white/20 transition-colors" @click.stop="prevImg">
          <span class="material-symbols-outlined">chevron_right</span>
        </button>
        <img :src="images[activeImg]" :alt="unit.name" class="max-w-full max-h-[85vh] rounded-lg object-contain" @click.stop />
        <button v-if="images.length > 1" class="absolute left-4 grid w-11 h-11 place-items-center rounded-full bg-white/10 text-white hover:bg-white/20 transition-colors" @click.stop="nextImg">
          <span class="material-symbols-outlined">chevron_left</span>
        </button>
        <span class="absolute bottom-5 text-white/80 text-body-sm font-numeric-data">{{ activeImg + 1 }} / {{ images.length }}</span>
      </div>
    </Transition>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PublicHeader from '@/components/public/PublicHeader.vue'
import PublicFooter from '@/components/public/PublicFooter.vue'
import CancellationPolicyTiers from '@/components/public/CancellationPolicyTiers.vue'
import { publicApi, bookingApi } from '@/api/public'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const loading = ref(true)
const unit = ref(null)
const activeImg = ref(0)
const lightbox = ref(false)
const saved = ref(false)
const booking_busy = ref(false)
const bookMsg = ref('')
const bookError = ref(false)

const today = new Date().toISOString().slice(0, 10)

const booking = reactive({ start_date: '', end_date: '', guests: 1 })

const images = computed(() => (unit.value?.images || []).map((i) => i.url))

const nights = computed(() => {
  if (!booking.start_date || !booking.end_date) return 0
  const diff = (new Date(booking.end_date) - new Date(booking.start_date)) / 86400000
  return diff > 0 ? Math.round(diff) : 0
})
const totalAmount = computed(() => nights.value * (unit.value?.price || 0))

// OpenStreetMap embed centred on the unit's coordinates (when available).
const mapSrc = computed(() => {
  const { lat, lng } = unit.value || {}
  if (!lat || !lng) return ''
  const d = 0.01
  const bbox = [Number(lng) - d, Number(lat) - d, Number(lng) + d, Number(lat) + d].join('%2C')
  return `https://www.openstreetmap.org/export/embed.html?bbox=${bbox}&layer=mapnik&marker=${lat}%2C${lng}`
})

// Map amenity (feature) names to Material Symbols icons; partial-match fallback.
const AMENITY_ICONS = {
  'واي فاي': 'wifi', 'مسبح': 'pool', 'مطبخ': 'kitchen', 'موقف سيارات': 'local_parking',
  'مكيف': 'ac_unit', 'تكييف': 'ac_unit', 'غسالة': 'local_laundry_service', 'شاشة ذكية': 'tv',
  'شواء': 'outdoor_grill', 'شواية': 'outdoor_grill', 'حديقة': 'yard', 'مصعد': 'elevator',
  'سبا': 'spa', 'مطعم': 'restaurant', 'ملعب': 'sports_soccer', 'جلسة نار': 'local_fire_department',
}
function amenityIcon(name) {
  if (AMENITY_ICONS[name]) return AMENITY_ICONS[name]
  for (const key in AMENITY_ICONS) if (name.includes(key)) return AMENITY_ICONS[key]
  return 'check_circle'
}

const CANCELLATION = {
  flexible: 'إلغاء مجاني حتى 24 ساعة قبل تسجيل الوصول، ثم تُحتسب الليلة الأولى.',
  '24_hours': 'إلغاء مجاني حتى 24 ساعة قبل تسجيل الوصول.',
  '48_hours': 'إلغاء مجاني حتى 48 ساعة قبل تسجيل الوصول.',
  '7_days': 'إلغاء مجاني حتى 7 أيام قبل تسجيل الوصول.',
  non_refundable: 'هذا الحجز غير قابل للاسترداد. لمزيد من المعلومات، راجع سياسة الإلغاء الكاملة.',
}
function cancellationText(p) {
  return CANCELLATION[p] || 'راجع سياسة الإلغاء الكاملة الخاصة بهذه الوحدة.'
}

function formatMoney(v) {
  return new Intl.NumberFormat('en-US').format(Number(v) || 0)
}

function openLightbox(i) {
  activeImg.value = i
  if (images.value[i]) lightbox.value = true
}
function nextImg() {
  activeImg.value = (activeImg.value + 1) % images.value.length
}
function prevImg() {
  activeImg.value = (activeImg.value - 1 + images.value.length) % images.value.length
}

async function handleBook() {
  bookMsg.value = ''
  bookError.value = false

  // Guest → remember where to return, then send to login
  if (!auth.isAuthenticated) {
    localStorage.setItem('post_login_redirect', route.fullPath)
    router.push({ name: 'login', query: { redirect: route.fullPath } })
    return
  }

  if (!booking.start_date || !booking.end_date || nights.value <= 0) {
    bookError.value = true
    bookMsg.value = 'اختر تاريخي الوصول والمغادرة'
    return
  }
  if (booking.guests < 1 || booking.guests > unit.value.capacity) {
    bookError.value = true
    bookMsg.value = `عدد الضيوف يجب أن يكون بين 1 و ${unit.value.capacity}`
    return
  }

  booking_busy.value = true
  try {
    // Check availability first for a clear message
    const { data: avail } = await publicApi.checkAvailability(unit.value.id, booking.start_date, booking.end_date)
    if (!avail.available) {
      bookError.value = true
      bookMsg.value = 'الوحدة محجوزة في هذه الفترة، جرّب تواريخ أخرى'
      return
    }

    const { data } = await bookingApi.create({
      unit_id: unit.value.id,
      start_date: booking.start_date,
      end_date: booking.end_date,
      guests: booking.guests,
    })

    // Booking is created as 'pending' — send the user straight to payment.
    const created = data.data ?? data
    bookMsg.value = 'تم إنشاء الحجز! يتم تحويلك إلى الدفع...'
    setTimeout(() => router.push({ name: 'payment', params: { id: created.id } }), 800)
  } catch (e) {
    bookError.value = true
    bookMsg.value = e.response?.data?.message || 'تعذّر إتمام الحجز، حاول مجدداً'
  } finally {
    booking_busy.value = false
  }
}

onMounted(async () => {
  try {
    const { data } = await publicApi.getUnit(route.params.id)
    unit.value = data.data ?? data
    booking.guests = 1
  } catch (e) {
    unit.value = null
  } finally {
    loading.value = false
  }
})
</script>

<style scoped>
.field {
  @apply w-full px-3.5 py-2.5 bg-surface-container-low border border-outline-variant rounded-xl text-body-sm
         focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none transition-all;
}
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
