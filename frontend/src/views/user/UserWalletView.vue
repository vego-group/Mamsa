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

          <!-- Manual add: tokenised client-side at Moyasar, only the token id reaches our API. -->
          <div class="mt-5 pt-4 border-t border-outline-variant">
            <button
              v-if="!showAddForm"
              class="w-full py-2.5 border border-primary text-primary rounded-xl font-bold text-body-sm hover:bg-primary/5 transition-colors flex items-center justify-center gap-1.5"
              @click="showAddForm = true"
            >
              <span class="material-symbols-outlined text-[18px]">add_card</span>
              إضافة بطاقة
            </button>

            <form v-else class="space-y-3" @submit.prevent="addCard">
              <input
                v-model.trim="newCard.name"
                type="text"
                placeholder="الاسم على البطاقة"
                autocomplete="cc-name"
                required
                class="w-full border border-outline-variant rounded-xl px-3 py-2.5 text-body-sm focus:border-primary outline-none"
              />
              <input
                v-model="newCard.number"
                dir="ltr"
                inputmode="numeric"
                placeholder="رقم البطاقة"
                autocomplete="cc-number"
                maxlength="19"
                required
                class="w-full border border-outline-variant rounded-xl px-3 py-2.5 text-center font-data focus:border-primary outline-none"
              />
              <div class="flex gap-2" dir="ltr">
                <input
                  v-model="newCard.month"
                  inputmode="numeric"
                  placeholder="MM"
                  autocomplete="cc-exp-month"
                  maxlength="2"
                  required
                  class="w-1/4 border border-outline-variant rounded-xl px-2 py-2.5 text-center font-data focus:border-primary outline-none"
                />
                <input
                  v-model="newCard.year"
                  inputmode="numeric"
                  placeholder="YYYY"
                  autocomplete="cc-exp-year"
                  maxlength="4"
                  required
                  class="w-1/3 border border-outline-variant rounded-xl px-2 py-2.5 text-center font-data focus:border-primary outline-none"
                />
                <input
                  v-model="newCard.cvc"
                  inputmode="numeric"
                  placeholder="CVC"
                  autocomplete="cc-csc"
                  maxlength="4"
                  required
                  class="flex-1 border border-outline-variant rounded-xl px-2 py-2.5 text-center font-data focus:border-primary outline-none"
                />
              </div>

              <button
                type="submit"
                :disabled="savingCard"
                class="w-full py-2.5 bg-primary text-on-primary rounded-xl font-bold text-body-sm hover:bg-primary-container transition-colors flex items-center justify-center gap-1.5 disabled:opacity-50"
              >
                <span v-if="savingCard" class="material-symbols-outlined animate-spin text-[18px]">progress_activity</span>
                <span v-else class="material-symbols-outlined text-[18px]">lock</span>
                حفظ البطاقة
              </button>
              <button type="button" class="w-full text-[12px] text-on-surface-variant hover:underline" @click="cancelAddCard">إلغاء</button>

              <p v-if="cardError" class="text-error text-[12px] text-center">{{ cardError }}</p>
              <p v-if="gatewayConfig?.test_mode || isTestKey" class="text-[11px] text-amber-700 text-center">
                وضع التجربة — بطاقة تجريبية: <span class="font-data" dir="ltr">4111 1111 1111 1111</span>
              </p>
            </form>
          </div>

          <p class="mt-4 text-[12px] text-on-surface-variant leading-relaxed flex items-start gap-1.5">
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
import { ref, computed, onMounted } from 'vue'
import PublicHeader from '@/components/public/PublicHeader.vue'
import PublicFooter from '@/components/public/PublicFooter.vue'
import AccountNav from '@/components/user/AccountNav.vue'
import { userApi } from '@/api/user'
import { paymentApi } from '@/api/public'

const loading = ref(true)
const transactions = ref([])
const cards = ref([])

// ── Manual add-card (tokenised at Moyasar; PAN never reaches our API) ──
const showAddForm = ref(false)
const savingCard = ref(false)
const cardError = ref('')
const gatewayConfig = ref(null)
const newCard = ref({ name: '', number: '', month: '', year: '', cvc: '' })
const isTestKey = computed(() => (gatewayConfig.value?.publishable_key ?? '').startsWith('pk_test'))

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

function cancelAddCard() {
  showAddForm.value = false
  cardError.value = ''
  newCard.value = { name: '', number: '', month: '', year: '', cvc: '' }
}

// Detect the brand locally only for simulate mode; live mode trusts Moyasar.
function detectBrand(number) {
  if (/^4/.test(number)) return 'visa'
  if (/^5[1-5]/.test(number) || /^2[2-7]/.test(number)) return 'mastercard'
  return 'mada'
}

async function addCard() {
  cardError.value = ''
  savingCard.value = true
  try {
    const digits = newCard.value.number.replace(/\D/g, '')
    let payload

    if (gatewayConfig.value?.test_mode) {
      // No gateway keys — backend stores metadata with a fake token.
      payload = {
        brand: detectBrand(digits),
        last4: digits.slice(-4),
        exp_month: Number(newCard.value.month),
        exp_year: Number(newCard.value.year),
      }
    } else {
      // Tokenise client-side with the publishable key: the PAN goes straight
      // to Moyasar, only the returned token id is sent to our backend.
      const res = await fetch('https://api.moyasar.com/v1/tokens', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Authorization: 'Basic ' + btoa(`${gatewayConfig.value.publishable_key}:`),
        },
        body: JSON.stringify({
          name: newCard.value.name,
          number: digits,
          cvc: newCard.value.cvc,
          month: newCard.value.month,
          year: newCard.value.year,
          // Required by Moyasar: where the optional 3-DS card verification
          // returns. Charges 3-DS anyway, so we don't force it at save time.
          callback_url: `${window.location.origin}/account/wallet`,
        }),
      })
      const tok = await res.json()
      if (!res.ok || !tok.id) {
        cardError.value = tok.message || 'بيانات البطاقة غير صحيحة'
        return
      }
      payload = { token: tok.id }
    }

    await userApi.saveCardFromToken(payload)
    const { data } = await userApi.cards()
    cards.value = data.data ?? data ?? []
    cancelAddCard()
  } catch (e) {
    cardError.value = e.response?.data?.message || 'تعذّر حفظ البطاقة'
  } finally {
    savingCard.value = false
  }
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
  const [tx, cd, cfg] = await Promise.allSettled([
    userApi.transactions(),
    userApi.cards(),
    paymentApi.config(),
  ])
  transactions.value = tx.status === 'fulfilled' ? (tx.value.data.data ?? tx.value.data ?? []) : []
  cards.value = cd.status === 'fulfilled' ? (cd.value.data.data ?? cd.value.data ?? []) : []
  gatewayConfig.value = cfg.status === 'fulfilled' ? (cfg.value.data.data ?? cfg.value.data ?? null) : null
  loading.value = false
})
</script>
