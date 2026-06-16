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
          <span class="text-primary font-bold">{{ unit.name }}</span>
        </nav>

        <!-- Gallery -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-6">
          <div class="rounded-2xl overflow-hidden h-72 md:h-96 bg-surface-container">
            <img v-if="images[activeImg]" :src="images[activeImg]" :alt="unit.name" class="w-full h-full object-cover" />
            <div v-else class="w-full h-full flex items-center justify-center">
              <span class="material-symbols-outlined text-5xl text-on-surface-variant">apartment</span>
            </div>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <button
              v-for="(img, i) in images.slice(0, 4)"
              :key="i"
              class="rounded-xl overflow-hidden h-[calc(50%-0.375rem)] md:h-44 bg-surface-container border-2 transition-all"
              :class="activeImg === i ? 'border-primary' : 'border-transparent'"
              @click="activeImg = i"
            >
              <img :src="img" :alt="`صورة ${i + 1}`" class="w-full h-full object-cover" />
            </button>
          </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <!-- Details -->
          <div class="lg:col-span-2 space-y-6">
            <div class="bg-white rounded-2xl border border-outline-variant p-6">
              <div class="flex items-start justify-between gap-4 mb-4">
                <div>
                  <h1 class="font-display-lg text-[28px] text-primary mb-1">{{ unit.name }}</h1>
                  <div class="flex items-center gap-1.5 text-on-surface-variant">
                    <span class="material-symbols-outlined text-[18px]">location_on</span>
                    <span class="text-body-md">{{ unit.city }}{{ unit.district ? ` - ${unit.district}` : '' }}</span>
                  </div>
                </div>
                <span class="px-3 py-1 rounded-full bg-surface-container text-primary text-body-sm font-bold whitespace-nowrap">
                  {{ typeLabel(unit.type) }}
                </span>
              </div>

              <!-- Quick facts -->
              <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 py-4 border-y border-outline-variant">
                <div class="text-center">
                  <span class="material-symbols-outlined text-primary mb-1">group</span>
                  <p class="text-body-sm text-on-surface-variant">السعة</p>
                  <p class="font-bold text-on-surface">{{ unit.capacity }} أشخاص</p>
                </div>
                <div class="text-center">
                  <span class="material-symbols-outlined text-primary mb-1">bed</span>
                  <p class="text-body-sm text-on-surface-variant">الغرف</p>
                  <p class="font-bold text-on-surface">{{ unit.bedrooms }}</p>
                </div>
                <div class="text-center">
                  <span class="material-symbols-outlined text-primary mb-1">login</span>
                  <p class="text-body-sm text-on-surface-variant">الدخول</p>
                  <p class="font-bold text-on-surface" dir="ltr">{{ (unit.checkin_time || '15:00').slice(0,5) }}</p>
                </div>
                <div class="text-center">
                  <span class="material-symbols-outlined text-primary mb-1">logout</span>
                  <p class="text-body-sm text-on-surface-variant">الخروج</p>
                  <p class="font-bold text-on-surface" dir="ltr">{{ (unit.checkout_time || '12:00').slice(0,5) }}</p>
                </div>
              </div>

              <p v-if="unit.description" class="text-body-md text-on-surface leading-relaxed mt-4">{{ unit.description }}</p>
            </div>

            <!-- Features -->
            <div v-if="unit.features?.length" class="bg-white rounded-2xl border border-outline-variant p-6">
              <h2 class="font-title-sm text-title-sm text-primary mb-4">المميزات</h2>
              <div class="flex flex-wrap gap-2">
                <span v-for="f in unit.features" :key="f" class="px-3 py-1.5 bg-surface-container rounded-lg text-body-sm text-on-surface flex items-center gap-1.5">
                  <span class="material-symbols-outlined text-[16px] text-primary">check_circle</span>
                  {{ f }}
                </span>
              </div>
            </div>
          </div>

          <!-- Booking widget -->
          <div class="lg:col-span-1">
            <div class="bg-white rounded-2xl border border-outline-variant p-6 sticky top-20">
              <div class="flex items-baseline gap-1 mb-5">
                <span class="font-display-lg text-[26px] text-primary">{{ formatMoney(unit.price) }}</span>
                <span class="text-on-surface-variant text-body-sm">ر.س / ليلة</span>
              </div>

              <div class="space-y-3 mb-4">
                <div>
                  <label class="block text-body-sm font-bold text-on-surface mb-1.5">تاريخ الوصول</label>
                  <input v-model="booking.start_date" type="date" :min="today" class="field" dir="ltr" />
                </div>
                <div>
                  <label class="block text-body-sm font-bold text-on-surface mb-1.5">تاريخ المغادرة</label>
                  <input v-model="booking.end_date" type="date" :min="booking.start_date || today" class="field" dir="ltr" />
                </div>
                <div>
                  <label class="block text-body-sm font-bold text-on-surface mb-1.5">عدد الضيوف</label>
                  <input v-model.number="booking.guests" type="number" min="1" :max="unit.capacity" class="field" dir="ltr" />
                </div>
              </div>

              <!-- Price summary -->
              <div v-if="nights > 0" class="py-3 border-t border-outline-variant space-y-2 mb-4">
                <div class="flex justify-between text-body-sm text-on-surface-variant">
                  <span>{{ formatMoney(unit.price) }} × {{ nights }} ليالٍ</span>
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
                {{ auth.isAuthenticated ? 'احجز الآن' : 'سجّل الدخول للحجز' }}
              </button>

              <p v-if="bookMsg" class="text-center text-body-sm mt-3" :class="bookError ? 'text-error' : 'text-emerald-600'">
                {{ bookMsg }}
              </p>

              <p v-if="!auth.isAuthenticated" class="text-center text-body-sm text-on-surface-variant mt-3">
                يجب تسجيل الدخول لإتمام الحجز
              </p>
            </div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PublicHeader from '@/components/public/PublicHeader.vue'
import { publicApi, bookingApi } from '@/api/public'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const loading = ref(true)
const unit = ref(null)
const activeImg = ref(0)
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

function formatMoney(v) {
  return new Intl.NumberFormat('en-US').format(Number(v) || 0)
}
function typeLabel(t) {
  return { apartment: 'شقة', studio: 'استوديو', villa: 'فيلا' }[t] || t
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
  @apply w-full px-4 py-2.5 bg-surface-container-low border border-outline-variant rounded-xl text-body-md
         focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none transition-all;
}
</style>
