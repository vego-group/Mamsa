<template>
  <div class="min-h-screen bg-[#F7F7F4]" dir="rtl">
    <PublicHeader />

    <div class="max-w-4xl mx-auto px-4 py-8">
      <div class="mb-6">
        <h1 class="font-display-lg text-display-lg text-primary mb-1">مرحباً، {{ auth.user?.name || 'ضيفنا' }}</h1>
        <p class="text-on-surface-variant text-body-md">تابع حجوزاتك وأدِر حسابك</p>
      </div>

      <AccountNav />

      <!-- Summary cards -->
      <div v-if="!loading" class="grid grid-cols-3 gap-3 mb-6">
        <div v-for="s in summary" :key="s.label" class="p-4 bg-white rounded-2xl border border-outline-variant text-center">
          <p class="font-numeric-data text-2xl font-bold" :class="s.color">{{ s.value }}</p>
          <p class="font-label-caps text-label-caps text-on-surface-variant mt-1">{{ s.label }}</p>
        </div>
      </div>

      <!-- Loading -->
      <div v-if="loading" class="flex items-center justify-center py-20 text-on-surface-variant">
        <span class="material-symbols-outlined animate-spin text-3xl">progress_activity</span>
      </div>

      <!-- Empty -->
      <div v-else-if="bookings.length === 0" class="text-center py-16 bg-white rounded-2xl border border-outline-variant">
        <span class="material-symbols-outlined text-5xl mb-3 block text-on-surface-variant">luggage</span>
        <p class="font-title-sm text-title-sm text-on-surface mb-1">لا توجد حجوزات بعد</p>
        <p class="text-body-sm text-on-surface-variant mb-5">ابدأ باستكشاف الوحدات المتاحة</p>
        <RouterLink :to="{ name: 'home' }" class="inline-flex items-center gap-2 px-5 py-2.5 bg-primary text-on-primary rounded-xl font-bold">
          <span class="material-symbols-outlined text-[18px]">search</span>
          تصفح الوحدات
        </RouterLink>
      </div>

      <!-- Bookings -->
      <div v-else class="space-y-4">
        <div v-for="b in bookings" :key="b.id" class="bg-white rounded-2xl border border-outline-variant overflow-hidden">
          <div class="flex flex-col sm:flex-row">
            <!-- Image -->
            <div class="sm:w-40 h-40 sm:h-auto bg-surface-container flex-shrink-0">
              <img v-if="image(b)" :src="image(b)" :alt="b.unit?.name" class="w-full h-full object-cover" />
              <div v-else class="w-full h-full flex items-center justify-center">
                <span class="material-symbols-outlined text-3xl text-on-surface-variant">apartment</span>
              </div>
            </div>

            <!-- Body -->
            <div class="flex-1 p-4">
              <div class="flex items-start justify-between gap-2 mb-2">
                <div>
                  <h3 class="font-title-sm text-title-sm text-on-surface">{{ b.unit?.name || 'وحدة' }}</h3>
                  <p class="text-body-sm text-on-surface-variant">{{ b.unit?.city }}</p>
                </div>
                <span class="px-2.5 py-1 rounded-full text-[12px] font-bold whitespace-nowrap" :class="statusClass(b.status)">{{ b.status_label }}</span>
              </div>

              <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-body-sm mb-3">
                <div>
                  <p class="text-on-surface-variant text-[11px]">الوصول</p>
                  <p class="font-numeric-data text-on-surface" dir="ltr">{{ b.start_date }}</p>
                </div>
                <div>
                  <p class="text-on-surface-variant text-[11px]">المغادرة</p>
                  <p class="font-numeric-data text-on-surface" dir="ltr">{{ b.end_date }}</p>
                </div>
                <div>
                  <p class="text-on-surface-variant text-[11px]">الليالي</p>
                  <p class="font-numeric-data text-on-surface">{{ b.nights }}</p>
                </div>
                <div>
                  <p class="text-on-surface-variant text-[11px]">الإجمالي</p>
                  <p class="font-numeric-data font-bold text-primary">{{ formatMoney(b.total_amount) }} ر.س</p>
                </div>
              </div>

              <!-- Payment + actions -->
              <div class="flex items-center justify-between pt-3 border-t border-outline-variant/50">
                <div class="flex items-center gap-1.5 text-body-sm">
                  <span class="material-symbols-outlined text-[16px]" :class="paidClass(b)">{{ b.payment?.payment_status === 'paid' ? 'check_circle' : 'schedule' }}</span>
                  <span :class="paidClass(b)">{{ paymentLabel(b) }}</span>
                </div>
                <div class="flex gap-2">
                  <RouterLink
                    v-if="b.status === 'pending' && b.payment?.payment_status !== 'paid'"
                    :to="{ name: 'payment', params: { id: b.id } }"
                    class="px-4 py-2 bg-primary text-on-primary rounded-lg text-body-sm font-bold hover:bg-primary-container transition-colors"
                  >
                    أكمل الدفع
                  </RouterLink>
                  <button
                    v-if="b.status === 'pending'"
                    class="px-4 py-2 border border-error text-error rounded-lg text-body-sm font-bold hover:bg-error-container transition-colors disabled:opacity-50"
                    :disabled="busyId === b.id"
                    @click="cancel(b)"
                  >
                    إلغاء
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

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
import AccountNav from '@/components/user/AccountNav.vue'
import { userApi } from '@/api/user'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const loading = ref(true)
const busyId = ref(null)
const bookings = ref([])
const toast = ref(null)

const summary = computed(() => [
  { label: 'الكل',     value: bookings.value.length, color: 'text-on-surface' },
  { label: 'مؤكدة',    value: bookings.value.filter((b) => b.status === 'confirmed').length, color: 'text-emerald-600' },
  { label: 'منتظرة',   value: bookings.value.filter((b) => b.status === 'pending').length, color: 'text-amber-600' },
])

function image(b) {
  const imgs = b.unit?.images || []
  return (imgs.find((i) => i.is_main) || imgs[0])?.url || null
}
function formatMoney(v) {
  return new Intl.NumberFormat('en-US').format(Number(v) || 0)
}
function statusClass(s) {
  return {
    confirmed: 'bg-emerald-100 text-emerald-700',
    pending:   'bg-amber-100 text-amber-700',
    cancelled: 'bg-red-100 text-red-700',
  }[s] || 'bg-surface-container text-on-surface-variant'
}
function paymentLabel(b) {
  if (b.payment?.payment_status === 'paid') return 'مدفوع'
  if (b.status === 'cancelled') return 'ملغى'
  return 'بانتظار الدفع'
}
function paidClass(b) {
  return b.payment?.payment_status === 'paid' ? 'text-emerald-600' : 'text-amber-600'
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
  } catch (e) {
    showToast('تعذّر تحميل الحجوزات', 'error')
  } finally {
    loading.value = false
  }
}

async function cancel(b) {
  busyId.value = b.id
  try {
    await userApi.cancelBooking(b.id)
    b.status = 'cancelled'
    b.status_label = 'ملغى'
    showToast('تم إلغاء الحجز')
  } catch (e) {
    showToast(e.response?.data?.message || 'تعذّر الإلغاء', 'error')
  } finally {
    busyId.value = null
  }
}

onMounted(load)
</script>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.25s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
