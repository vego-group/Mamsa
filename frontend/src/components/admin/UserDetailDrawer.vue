<template>
  <Teleport to="body">
    <transition name="drawer-fade">
      <div v-if="open" class="fixed inset-0 z-[70]" :dir="dir">
        <!-- Overlay -->
        <div class="absolute inset-0 bg-black/40" @click="$emit('close')" />

        <!-- Panel -->
        <div
          class="absolute top-0 bottom-0 w-full max-w-[440px] bg-white shadow-2xl flex flex-col"
          :class="dir === 'rtl' ? 'left-0 drawer-ltr' : 'right-0 drawer-rtl'"
        >
          <!-- Header -->
          <div class="h-[60px] px-5 flex items-center justify-between border-b border-gray-100 flex-shrink-0">
            <h2 class="text-[16px] font-bold text-gray-900 truncate">{{ detail?.name || '—' }}</h2>
            <button class="p-2 -m-2 text-gray-400 hover:text-gray-700" @click="$emit('close')">
              <span class="material-symbols-outlined text-[22px]">close</span>
            </button>
          </div>

          <div v-if="loading" class="flex-1 flex items-center justify-center text-gray-400">
            <span class="material-symbols-outlined animate-spin text-2xl">progress_activity</span>
          </div>

          <div v-else-if="detail" class="flex-1 overflow-y-auto">
            <!-- Profile strip -->
            <div class="bg-gray-50 px-5 py-5 flex items-center gap-4 border-b border-gray-100">
              <div class="flex-1 min-w-0 text-end">
                <p class="text-[16px] font-bold text-gray-900 truncate">{{ detail.name }}</p>
                <p class="text-[12px] text-gray-400 tabular-nums">{{ detail.code }}</p>
                <span class="inline-flex items-center gap-1 mt-1 text-[12px] font-semibold" :class="statusMeta.text">
                  <span class="w-1.5 h-1.5 rounded-full" :class="statusMeta.dot"></span>{{ statusMeta.label }}
                </span>
              </div>
              <div class="w-14 h-14 rounded-full bg-primary/85 flex items-center justify-center text-white font-bold text-[16px] flex-shrink-0">
                {{ initials }}
              </div>
            </div>

            <!-- Contact -->
            <section class="px-5 py-4 border-b border-gray-100">
              <p class="text-[11px] font-bold uppercase tracking-wide text-gray-400 mb-3">{{ t('users.contactInfo') }}</p>
              <ul class="space-y-2.5 text-[13px] text-gray-700">
                <li class="flex items-center gap-2.5"><span class="material-symbols-outlined text-[18px] text-gray-400">mail</span><span dir="ltr" class="truncate">{{ detail.email || '—' }}</span></li>
                <li class="flex items-center gap-2.5"><span class="material-symbols-outlined text-[18px] text-gray-400">call</span><span dir="ltr">{{ detail.phone || '—' }}</span></li>
                <li class="flex items-center gap-2.5"><span class="material-symbols-outlined text-[18px] text-gray-400">location_on</span><span>{{ detail.city || '—' }}</span></li>
                <li class="flex items-center gap-2.5"><span class="material-symbols-outlined text-[18px] text-gray-400">calendar_today</span><span>{{ t('users.joinedOn', { date: date(detail.created_at) }) }}</span></li>
              </ul>
            </section>

            <!-- Booking stats -->
            <section class="px-5 py-4 border-b border-gray-100">
              <p class="text-[11px] font-bold uppercase tracking-wide text-gray-400 mb-3">{{ t('users.bookingStats') }}</p>
              <dl class="space-y-3">
                <div class="flex items-center justify-between"><dt class="text-[13px] text-gray-500">{{ t('users.totalBookings') }}</dt><dd class="text-[14px] font-bold text-gray-900 tabular-nums">{{ int(detail.stats.total_bookings) }}</dd></div>
                <div class="flex items-center justify-between border-t border-gray-50 pt-3"><dt class="text-[13px] text-gray-500">{{ t('users.totalSpent') }}</dt><dd class="text-[14px] font-bold text-gray-900 tabular-nums">{{ sar(detail.stats.total_spent) }}</dd></div>
                <div class="flex items-center justify-between border-t border-gray-50 pt-3"><dt class="text-[13px] text-gray-500">{{ t('users.avgBookingValue') }}</dt><dd class="text-[14px] font-bold text-gray-900 tabular-nums">{{ sar(detail.stats.avg_booking_value) }}</dd></div>
              </dl>
            </section>

            <!-- Activity -->
            <section v-if="detail.activity?.length" class="px-5 py-4">
              <p class="text-[11px] font-bold uppercase tracking-wide text-gray-400 mb-3">{{ t('users.recentActivity') }}</p>
              <ul class="space-y-3">
                <li v-for="(a, i) in detail.activity" :key="i">
                  <p class="text-[13px] font-semibold text-gray-800">{{ activityLabel(a.type) }}</p>
                  <p class="text-[11px] text-gray-400 tabular-nums">{{ date(a.date) }}</p>
                </li>
              </ul>
            </section>
          </div>

          <!-- Footer actions -->
          <div v-if="detail" class="p-4 border-t border-gray-100 flex gap-3 flex-shrink-0">
            <button
              class="flex-1 h-11 rounded-xl border border-gray-200 text-[13px] font-semibold text-gray-700 hover:bg-gray-50 transition-colors disabled:opacity-50"
              :disabled="busy"
              @click="toggleStatus"
            >
              {{ detail.is_active ? t('users.disableAccount') : t('users.enableAccount') }}
            </button>
            <button
              class="flex-1 h-11 rounded-xl bg-red-600 text-white text-[13px] font-semibold hover:bg-red-700 transition-colors disabled:opacity-50"
              :disabled="busy"
              @click="confirmDelete = true"
            >
              {{ t('users.deleteUser') }}
            </button>
          </div>
        </div>

        <!-- Delete confirm -->
        <div v-if="confirmDelete" class="absolute inset-0 z-10 flex items-center justify-center p-4 bg-black/40">
          <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm p-6 text-center">
            <div class="w-14 h-14 bg-red-50 rounded-full flex items-center justify-center mx-auto mb-4">
              <span class="material-symbols-outlined text-red-600 text-3xl">delete_forever</span>
            </div>
            <h3 class="text-[17px] font-bold text-gray-900 mb-1.5">{{ t('users.deleteTitle') }}</h3>
            <p class="text-[13px] text-gray-500 mb-5">{{ t('users.deleteMsg', { name: detail?.name }) }}</p>
            <div class="flex gap-3">
              <button class="flex-1 h-11 rounded-xl border border-gray-200 text-[13px] font-semibold text-gray-700 hover:bg-gray-50" @click="confirmDelete = false">{{ t('users.cancel') }}</button>
              <button class="flex-1 h-11 rounded-xl bg-red-600 text-white text-[13px] font-semibold hover:bg-red-700 disabled:opacity-50" :disabled="busy" @click="doDelete">{{ t('users.delete') }}</button>
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
  userId: { type: [Number, String], default: null },
})
const emit = defineEmits(['close', 'changed', 'deleted', 'error'])

const { t, dir } = useAdminI18n()
const { int, sar, date } = useAdminFormat()

const loading = ref(false)
const busy = ref(false)
const detail = ref(null)
const confirmDelete = ref(false)

const initials = computed(() => (detail.value?.name || '?').split(' ').slice(0, 2).map((w) => w[0]).join('').toUpperCase())

const statusMeta = computed(() => {
  const s = detail.value?.status
  if (s === 'disabled') return { label: t('users.statusDisabled'), text: 'text-red-600', dot: 'bg-red-500' }
  if (s === 'inactive') return { label: t('users.statusInactive'), text: 'text-gray-500', dot: 'bg-gray-400' }
  return { label: t('users.statusActive'), text: 'text-emerald-600', dot: 'bg-emerald-500' }
})

const activityLabels = {
  account_created: 'actAccountCreated', email_verified: 'actEmailVerified',
  first_booking: 'actFirstBooking', last_booking: 'actLastBooking',
}
const activityLabel = (type) => t(`users.${activityLabels[type] || 'actAccountCreated'}`)

watch(() => props.open, async (v) => {
  confirmDelete.value = false
  if (v && props.userId) {
    loading.value = true
    detail.value = null
    try {
      const { data } = await adminApi.getUser(props.userId)
      detail.value = data.data ?? data
    } catch {
      emit('error')
      emit('close')
    } finally {
      loading.value = false
    }
  }
})

async function toggleStatus() {
  if (!detail.value) return
  busy.value = true
  try {
    const next = !detail.value.is_active
    const { data } = await adminApi.updateUserStatus(detail.value.id, next)
    detail.value.is_active = (data.data ?? data).is_active
    detail.value.status = detail.value.is_active
      ? (detail.value.stats.total_bookings > 0 ? 'active' : 'inactive')
      : 'disabled'
    emit('changed')
  } catch {
    emit('error')
  } finally {
    busy.value = false
  }
}

async function doDelete() {
  if (!detail.value) return
  busy.value = true
  try {
    await adminApi.deleteUser(detail.value.id)
    confirmDelete.value = false
    emit('deleted')
    emit('close')
  } catch {
    emit('error')
  } finally {
    busy.value = false
  }
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
