<template>
  <div class="min-h-screen bg-[#F7F7F4]" dir="rtl">
    <PublicHeader />

    <div class="max-w-6xl mx-auto px-4 py-8">
      <div class="mb-6">
        <h1 class="font-display-lg text-display-lg text-primary mb-1">المحفظة ووسائل الدفع</h1>
        <p class="text-on-surface-variant text-body-md">إدارة وسائل الدفع ومعاملاتك المالية</p>
      </div>

      <AccountNav />

      <div v-if="loading" class="flex items-center justify-center py-20 text-on-surface-variant">
        <span class="material-symbols-outlined animate-spin text-3xl">progress_activity</span>
      </div>

      <div v-else class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
        <!-- Transactions ledger (سجل المعاملات) -->
        <section class="lg:col-span-2 bg-white rounded-2xl border border-outline-variant p-6">
          <h2 class="font-title-sm text-title-sm text-primary mb-5">سجل المعاملات</h2>

          <div v-if="transactions.length === 0" class="text-center py-14 text-on-surface-variant">
            <span class="material-symbols-outlined text-5xl mb-3 block">receipt_long</span>
            <p class="font-title-sm text-title-sm mb-1">لا توجد معاملات بعد</p>
            <p class="text-body-sm">ستظهر مدفوعاتك واستردادتك هنا</p>
          </div>

          <ul v-else class="space-y-3">
            <li
              v-for="t in transactions"
              :key="t.id"
              class="flex items-center gap-4 border border-outline-variant rounded-xl px-4 py-3.5"
            >
              <!-- Direction icon: green for incoming (+), red for outgoing (−) -->
              <span
                class="grid w-10 h-10 shrink-0 place-items-center rounded-full"
                :class="t.amount >= 0 ? 'bg-emerald-100 text-emerald-600' : 'bg-error-container text-error'"
              >
                <span class="material-symbols-outlined text-[20px]">
                  {{ t.amount >= 0 ? 'south_west' : 'north_east' }}
                </span>
              </span>

              <div class="min-w-0 flex-1 text-right">
                <p class="text-body-md font-semibold text-on-surface truncate">{{ t.description || typeLabel(t.type) }}</p>
                <p class="text-[12px] text-on-surface-variant mt-0.5">
                  <span v-if="t.ref_code" class="font-data" dir="ltr">{{ t.ref_code }}</span>
                  <span v-if="t.ref_code"> · </span>
                  {{ formatDate(t.date) }}
                </p>
              </div>

              <div class="text-left shrink-0">
                <p class="font-numeric-data font-bold" :class="t.amount >= 0 ? 'text-emerald-600' : 'text-error'" dir="ltr">
                  {{ t.amount >= 0 ? '+' : '−' }}{{ formatMoney(Math.abs(t.amount)) }} ر.س
                </p>
                <span class="inline-block mt-1 px-2 py-0.5 rounded-full text-[11px] font-bold" :class="statusChip(t.status).cls">
                  {{ statusChip(t.status).label }}
                </span>
              </div>
            </li>
          </ul>
        </section>

        <!-- Payment methods (طرق الدفع) -->
        <aside class="bg-white rounded-2xl border border-outline-variant p-6">
          <h2 class="font-title-sm text-title-sm text-primary mb-5">طرق الدفع</h2>

          <div v-if="cards.length === 0" class="text-center py-8 text-on-surface-variant">
            <span class="material-symbols-outlined text-4xl mb-2 block">credit_card</span>
            <p class="text-body-sm">لا توجد بطاقات محفوظة</p>
          </div>

          <ul v-else class="space-y-3">
            <li
              v-for="card in cards"
              :key="card.id"
              class="border border-outline-variant rounded-xl px-4 py-3"
            >
              <div class="flex items-center gap-3">
                <span class="material-symbols-outlined text-on-surface-variant">credit_card</span>
                <div class="min-w-0 flex-1 text-right">
                  <p class="text-body-md font-semibold text-on-surface" dir="ltr">•••• {{ card.last4 }}</p>
                  <p class="text-[12px] text-on-surface-variant uppercase">
                    {{ card.brand }}
                    <template v-if="card.exp_month"> · تنتهي في {{ card.exp_month }}/{{ String(card.exp_year).slice(-2) }}</template>
                  </p>
                </div>
                <span v-if="card.is_default" class="px-2 py-0.5 rounded-full bg-primary/10 text-primary text-[11px] font-bold shrink-0">افتراضية</span>
              </div>
              <div class="flex items-center justify-end gap-4 mt-2 text-[12px] font-bold">
                <button v-if="!card.is_default" class="text-primary hover:underline" @click="makeDefault(card)">تعيين كافتراضية</button>
                <button class="text-error hover:underline" @click="removeCard(card)">حذف</button>
              </div>
            </li>
          </ul>

          <!-- Cards are tokenised by Moyasar during checkout — there is no manual add form. -->
          <p class="mt-5 pt-4 border-t border-outline-variant text-[12px] text-on-surface-variant leading-relaxed flex items-start gap-1.5">
            <span class="material-symbols-outlined text-[16px] shrink-0">info</span>
            تُحفظ بطاقتك تلقائياً عند إتمام أي عملية دفع مع تفعيل خيار «حفظ البطاقة».
          </p>
        </aside>
      </div>
    </div>

    <PublicFooter />
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import PublicHeader from '@/components/public/PublicHeader.vue'
import PublicFooter from '@/components/public/PublicFooter.vue'
import AccountNav from '@/components/user/AccountNav.vue'
import { userApi } from '@/api/user'

const loading = ref(true)
const transactions = ref([])
const cards = ref([])

const typeLabels = {
  payment: 'دفع حجز',
  refund: 'استرداد',
  topup: 'إضافة رصيد',
  reward: 'مكافأة',
}
const typeLabel = (t) => typeLabels[t] ?? 'معاملة'

const statusChips = {
  completed: { label: 'مكتمل', cls: 'bg-emerald-100 text-emerald-700' },
  pending: { label: 'قيد المعالجة', cls: 'bg-amber-100 text-amber-700' },
  failed: { label: 'فاشلة', cls: 'bg-error-container text-error' },
}
const statusChip = (s) => statusChips[s] ?? statusChips.completed

function formatMoney(v) {
  return new Intl.NumberFormat('en-US', { minimumFractionDigits: 2 }).format(Number(v) || 0)
}

// Hijri date, consistent with the bookings pages.
function formatDate(iso) {
  if (!iso) return ''
  return new Date(iso).toLocaleDateString('ar-SA', { day: 'numeric', month: 'long', year: 'numeric' })
}

async function makeDefault(card) {
  await userApi.setDefaultCard(card.id)
  cards.value = cards.value.map((c) => ({ ...c, is_default: c.id === card.id }))
}

async function removeCard(card) {
  if (!window.confirm(`حذف البطاقة •••• ${card.last4}؟`)) return
  await userApi.deleteCard(card.id)
  cards.value = cards.value.filter((c) => c.id !== card.id)
  // Server promotes a new default — refetch to stay accurate.
  try {
    const { data } = await userApi.cards()
    cards.value = data.data ?? data ?? []
  } catch { /* keep the local list */ }
}

onMounted(async () => {
  const [tx, cd] = await Promise.allSettled([userApi.transactions(), userApi.cards()])
  transactions.value = tx.status === 'fulfilled' ? (tx.value.data.data ?? tx.value.data ?? []) : []
  cards.value = cd.status === 'fulfilled' ? (cd.value.data.data ?? cd.value.data ?? []) : []
  loading.value = false
})
</script>
