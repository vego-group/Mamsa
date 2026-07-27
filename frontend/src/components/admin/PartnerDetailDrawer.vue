<template>
  <Teleport to="body">
    <transition name="drawer-fade">
      <div v-if="open" class="fixed inset-0 z-[70]" :dir="dir">
        <div class="absolute inset-0 bg-black/40" @click="$emit('close')" />

        <div
          class="absolute top-0 bottom-0 w-full max-w-[480px] bg-white shadow-2xl flex flex-col"
          :class="dir === 'rtl' ? 'left-0 drawer-ltr' : 'right-0 drawer-rtl'"
        >
          <!-- Header -->
          <div class="h-[60px] px-5 flex items-center justify-between border-b border-gray-100 flex-shrink-0">
            <h2 class="text-[16px] font-bold text-gray-900">{{ t('partners.profileTitle') }}</h2>
            <button class="p-2 -m-2 text-gray-400 hover:text-gray-700" @click="$emit('close')">
              <span class="material-symbols-outlined text-[22px]">close</span>
            </button>
          </div>

          <div v-if="loading" class="flex-1 flex items-center justify-center text-gray-400">
            <span class="material-symbols-outlined animate-spin text-2xl">progress_activity</span>
          </div>

          <div v-else-if="p" class="flex-1 overflow-y-auto">
            <!-- Profile strip -->
            <div class="bg-gray-50 px-5 py-5 flex items-center gap-4 border-b border-gray-100">
              <div class="flex-1 min-w-0 text-end">
                <p class="text-[16px] font-bold text-gray-900 truncate">{{ p.name }}</p>
                <p class="text-[12px] text-gray-400 tabular-nums">{{ p.code }} · {{ p.type === 'company' ? t('partners.typeCompany') : t('partners.typeIndividual') }}</p>
                <div class="flex items-center gap-3 mt-1 justify-end text-[12px]">
                  <span v-if="p.rating != null" class="flex items-center gap-0.5 text-amber-500 font-semibold"><span class="material-symbols-outlined text-[15px]" style="font-variation-settings:'FILL' 1">star</span>{{ p.rating }}</span>
                  <span class="flex items-center gap-1 font-semibold" :class="statusMeta.text"><span class="w-1.5 h-1.5 rounded-full" :class="statusMeta.dot"></span>{{ statusMeta.label }}</span>
                  <span class="flex items-center gap-1 font-semibold" :class="p.verified ? 'text-emerald-600' : 'text-amber-600'"><span class="material-symbols-outlined text-[14px]">{{ p.verified ? 'verified' : 'gpp_maybe' }}</span>{{ p.verified ? t('partners.verified') : t('partners.unverified') }}</span>
                </div>
              </div>
              <div class="w-14 h-14 rounded-full bg-primary/85 flex items-center justify-center text-white font-bold text-[16px] flex-shrink-0">{{ initials }}</div>
            </div>

            <!-- Contact -->
            <section class="px-5 py-4 border-b border-gray-100">
              <p class="text-[11px] font-bold uppercase tracking-wide text-gray-400 mb-3">{{ t('partners.contact') }}</p>
              <ul class="space-y-2.5 text-[13px] text-gray-700">
                <li class="flex items-center gap-2.5"><span class="material-symbols-outlined text-[18px] text-gray-400">mail</span><span dir="ltr" class="truncate">{{ p.email || '—' }}</span></li>
                <li class="flex items-center gap-2.5"><span class="material-symbols-outlined text-[18px] text-gray-400">call</span><span dir="ltr">{{ p.phone || '—' }}</span></li>
                <li class="flex items-center gap-2.5"><span class="material-symbols-outlined text-[18px] text-gray-400">location_on</span><span>{{ p.city || '—' }}</span></li>
                <li class="flex items-center gap-2.5"><span class="material-symbols-outlined text-[18px] text-gray-400">calendar_today</span><span>{{ t('partners.joinedOn', { date: date(p.created_at) }) }}</span></li>
              </ul>
            </section>

            <!-- Financial summary -->
            <section class="px-5 py-4 border-b border-gray-100">
              <p class="text-[11px] font-bold uppercase tracking-wide text-gray-400 mb-3">{{ t('partners.financial') }}</p>
              <div class="grid grid-cols-2 gap-3">
                <div v-for="f in financials" :key="f.key" class="bg-gray-50 rounded-xl p-3">
                  <p class="text-[11px] text-gray-400 mb-1">{{ f.label }}</p>
                  <p class="text-[14px] font-bold text-gray-900 tabular-nums">{{ f.value }}</p>
                </div>
              </div>
            </section>

            <!-- Performance -->
            <section class="px-5 py-4 border-b border-gray-100">
              <p class="text-[11px] font-bold uppercase tracking-wide text-gray-400 mb-3">{{ t('partners.performance') }}</p>
              <dl class="space-y-3 text-[13px]">
                <div class="flex items-center justify-between"><dt class="text-gray-500">{{ t('partners.totalUnits') }}</dt><dd class="font-bold text-gray-900 tabular-nums">{{ p.performance.total_units }}</dd></div>
                <div class="flex items-center justify-between border-t border-gray-50 pt-3"><dt class="text-gray-500">{{ t('partners.totalBookings') }}</dt><dd class="font-bold text-gray-900 tabular-nums">{{ int(p.performance.total_bookings) }}</dd></div>
                <div class="flex items-center justify-between border-t border-gray-50 pt-3"><dt class="text-gray-500">{{ t('partners.cancellations') }}</dt><dd class="font-bold text-gray-900 tabular-nums">{{ p.performance.cancellations }}</dd></div>
                <div class="flex items-center justify-between border-t border-gray-50 pt-3">
                  <dt class="text-gray-500">{{ t('partners.cancellationRate') }}</dt>
                  <dd class="flex items-center gap-2">
                    <span class="w-16 h-1.5 rounded-full bg-gray-100 overflow-hidden" dir="ltr"><span class="block h-full rounded-full" :class="p.performance.cancellation_rate >= 15 ? 'bg-red-500' : 'bg-emerald-500'" :style="`width:${Math.min(100, p.performance.cancellation_rate)}%`"></span></span>
                    <span class="font-bold text-gray-900 tabular-nums">{{ p.performance.cancellation_rate }}%</span>
                  </dd>
                </div>
              </dl>
            </section>

            <!-- Documents -->
            <section class="px-5 py-4">
              <p class="text-[11px] font-bold uppercase tracking-wide text-gray-400 mb-3">{{ t('partners.documents') }}</p>
              <ul class="space-y-2">
                <li v-for="doc in p.documents" :key="doc.key" class="flex items-center gap-3 bg-gray-50 rounded-xl px-3 py-2.5">
                  <span class="material-symbols-outlined text-[18px] text-gray-400">description</span>
                  <span class="flex-1 text-[13px] text-gray-700">{{ docLabel(doc.key) }}</span>
                  <span class="text-[12px] font-semibold" :class="docMeta(doc.status).cls">{{ docMeta(doc.status).label }}</span>
                </li>
              </ul>
            </section>
          </div>

          <!-- Footer actions -->
          <div v-if="p" class="p-4 border-t border-gray-100 flex gap-3 flex-shrink-0">
            <template v-if="p.application_status === 'pending'">
              <button class="flex-1 h-11 rounded-xl border border-gray-200 text-[13px] font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50" :disabled="busy" @click="showReject = true">{{ t('partners.reject') }}</button>
              <button class="flex-1 h-11 rounded-xl bg-primary text-white text-[13px] font-semibold hover:bg-primary-container disabled:opacity-50" :disabled="busy" @click="approve">{{ t('partners.approve') }}</button>
            </template>
            <template v-else>
              <button class="flex-1 h-11 rounded-xl border border-gray-200 text-[13px] font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50" :disabled="busy" @click="revoke">{{ t('partners.revokeVerification') }}</button>
              <button class="flex-1 h-11 rounded-xl text-[13px] font-semibold text-white disabled:opacity-50" :class="p.is_active ? 'bg-red-600 hover:bg-red-700' : 'bg-emerald-600 hover:bg-emerald-700'" :disabled="busy" @click="toggleActive">
                {{ p.is_active ? t('partners.disablePartner') : t('partners.enablePartner') }}
              </button>
            </template>
          </div>
        </div>

        <!-- Reject reason -->
        <div v-if="showReject" class="absolute inset-0 z-10 flex items-center justify-center p-4 bg-black/40">
          <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm p-6">
            <h3 class="text-[17px] font-bold text-gray-900 mb-3">{{ t('partners.rejectTitle') }}</h3>
            <label class="block text-[13px] font-semibold text-gray-700 mb-1.5">{{ t('partners.rejectReason') }}</label>
            <textarea v-model="rejectReason" rows="3" class="w-full p-3 bg-gray-50 border border-gray-200 rounded-xl text-[14px] outline-none focus:ring-2 focus:ring-primary/15 focus:border-primary/40" :placeholder="t('partners.rejectPlaceholder')"></textarea>
            <div class="flex gap-3 mt-4">
              <button class="flex-1 h-11 rounded-xl border border-gray-200 text-[13px] font-semibold text-gray-700 hover:bg-gray-50" @click="showReject = false">{{ t('partners.cancel') }}</button>
              <button class="flex-1 h-11 rounded-xl bg-red-600 text-white text-[13px] font-semibold hover:bg-red-700 disabled:opacity-50" :disabled="busy || !rejectReason.trim()" @click="reject">{{ t('partners.reject') }}</button>
            </div>
          </div>
        </div>
      </div>
    </transition>
  </Teleport>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { adminApi } from '@/api/admin'
import { useAdminI18n } from '@/i18n/admin'
import { useAdminFormat } from '@/composables/useAdminFormat'

const props = defineProps({
  open: { type: Boolean, default: false },
  partnerId: { type: [Number, String], default: null },
})
const emit = defineEmits(['close', 'changed', 'error'])

const { t, dir } = useAdminI18n()
const { int, sar, date } = useAdminFormat()

const loading = ref(false)
const busy = ref(false)
const p = ref(null)
const showReject = ref(false)
const rejectReason = ref('')

const initials = computed(() => (p.value?.name || '?').split(' ').slice(0, 2).map((w) => w[0]).join('').toUpperCase())

const statusMeta = computed(() => {
  const s = p.value?.status
  if (s === 'pending') return { label: t('partners.statusPending'), text: 'text-amber-600', dot: 'bg-amber-500' }
  if (s === 'inactive') return { label: t('partners.statusInactive'), text: 'text-gray-500', dot: 'bg-gray-400' }
  return { label: t('partners.statusActive'), text: 'text-emerald-600', dot: 'bg-emerald-500' }
})

const financials = computed(() => {
  const f = p.value?.financial || {}
  return [
    { key: 'rev', label: t('partners.finTotalRevenue'), value: sar(f.total_revenue) },
    { key: 'com', label: t('partners.commissionPaid'), value: sar(f.commission_paid) },
    { key: 'earn', label: t('partners.partnerEarning'), value: sar(f.partner_earning) },
    { key: 'avg', label: t('partners.avgBooking'), value: sar(f.avg_booking) },
  ]
})

const docLabels = { identity: 'docIdentity', bank: 'docBank', ownership: 'docOwnership' }
const docLabel = (k) => t(`partners.${docLabels[k] || 'docIdentity'}`)
function docMeta(status) {
  if (status === 'verified') return { label: t('partners.docVerified'), cls: 'text-emerald-600' }
  if (status === 'pending') return { label: t('partners.docPending'), cls: 'text-amber-600' }
  return { label: t('partners.docMissing'), cls: 'text-gray-400' }
}

watch(() => props.open, async (v) => {
  showReject.value = false
  rejectReason.value = ''
  if (v && props.partnerId) {
    loading.value = true
    p.value = null
    try {
      const { data } = await adminApi.getPartner(props.partnerId)
      p.value = data.data ?? data
    } catch {
      emit('error'); emit('close')
    } finally {
      loading.value = false
    }
  }
})

async function run(fn, okKey) {
  busy.value = true
  try {
    await fn()
    emit('changed', okKey)
    emit('close')
  } catch {
    emit('error')
  } finally {
    busy.value = false
  }
}
const approve = () => run(() => adminApi.approvePartner(p.value.user_id), 'approved')
const revoke = () => run(() => adminApi.revokePartner(p.value.user_id), 'revoked')
const toggleActive = () => run(() => adminApi.setPartnerActive(p.value.user_id, !p.value.is_active), 'statusUpdated')
async function reject() {
  if (!rejectReason.value.trim()) return
  await run(() => adminApi.rejectPartner(p.value.user_id, rejectReason.value.trim()), 'rejected')
}
</script>

<style scoped>
.drawer-fade-enter-active, .drawer-fade-leave-active { transition: opacity 0.2s ease; }
.drawer-fade-enter-from, .drawer-fade-leave-to { opacity: 0; }
.drawer-fade-enter-active .drawer-rtl, .drawer-fade-leave-active .drawer-rtl { transition: transform 0.25s ease; }
.drawer-fade-enter-from .drawer-rtl, .drawer-fade-leave-to .drawer-rtl { transform: translateX(100%); }
.drawer-fade-enter-active .drawer-ltr, .drawer-fade-leave-active .drawer-ltr { transition: transform 0.25s ease; }
.drawer-fade-enter-from .drawer-ltr, .drawer-fade-leave-to .drawer-ltr { transform: translateX(-100%); }
</style>
