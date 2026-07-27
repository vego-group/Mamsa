<template>
  <AdminLayout>
    <!-- Title row -->
    <div class="flex flex-wrap items-start justify-between gap-3 mb-6">
      <div>
        <h1 class="text-[24px] font-bold text-gray-900 leading-tight">{{ t('notifs.title') }}</h1>
        <p class="text-gray-500 text-[14px] mt-0.5">{{ t('notifs.unread', { n: unreadCount }) }}</p>
      </div>
      <button v-if="unreadCount > 0" class="inline-flex items-center gap-1.5 h-9 px-4 rounded-lg border border-gray-200 bg-white text-[13px] font-semibold text-gray-600 hover:bg-gray-50 transition-colors" @click="markAll">
        <span class="material-symbols-outlined text-[18px]">done_all</span>{{ t('notifs.markAll') }}
      </button>
    </div>

    <!-- Tabs -->
    <div class="flex items-center gap-1 bg-gray-100 rounded-lg p-1 w-fit mb-4">
      <button v-for="tb in ['all', 'unread']" :key="tb" class="px-3 py-1.5 rounded-md text-[13px] font-semibold transition-colors flex items-center gap-1.5" :class="tab === tb ? 'bg-white text-primary shadow-sm' : 'text-gray-500 hover:text-gray-700'" @click="tab = tb">
        {{ tb === 'all' ? t('notifs.tabAll') : t('notifs.tabUnread') }}
        <span class="text-[11px] px-1.5 rounded-full" :class="tab === tb ? 'bg-primary/10 text-primary' : 'bg-gray-200 text-gray-500'">{{ tb === 'all' ? items.length : unreadCount }}</span>
      </button>
    </div>

    <!-- Category chips -->
    <div class="flex flex-wrap gap-2 mb-6">
      <button v-for="c in categories" :key="c.key" class="inline-flex items-center gap-1.5 h-8 px-3 rounded-lg border text-[12px] font-semibold transition-colors" :class="activeCat === c.key ? 'border-primary bg-primary/5 text-primary' : 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50'" @click="activeCat = activeCat === c.key ? '' : c.key">
        <span class="material-symbols-outlined text-[16px]">{{ c.icon }}</span>{{ t(`notifs.${c.label}`) }}
      </button>
    </div>

    <div v-if="loading" class="space-y-3">
      <div v-for="i in 5" :key="i" class="h-20 bg-white rounded-2xl border border-gray-200 animate-pulse"></div>
    </div>

    <div v-else-if="filtered.length === 0" class="py-16 text-center text-gray-400 text-[13px] bg-white rounded-2xl border border-gray-200">
      <span class="material-symbols-outlined text-4xl opacity-40 block mb-2">notifications_off</span>{{ t('notifs.empty') }}
    </div>

    <div v-else class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
      <button
        v-for="n in filtered" :key="n.id"
        class="w-full flex items-start gap-3 px-5 py-4 border-b border-gray-50 last:border-0 text-start transition-colors hover:bg-gray-50"
        :class="!n.read ? 'bg-primary/[0.03]' : ''"
        @click="open(n)"
      >
        <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0" :class="catMeta(n._cat).bg">
          <span class="material-symbols-outlined text-[18px]" :class="catMeta(n._cat).color">{{ catMeta(n._cat).icon }}</span>
        </div>
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2">
            <p class="text-[14px] font-semibold text-gray-900 truncate flex-1">{{ n.title }}</p>
            <span class="text-[11px] text-gray-400 whitespace-nowrap tabular-nums">{{ timeLabel(n.created_at) }}</span>
            <span v-if="!n.read" class="w-2 h-2 rounded-full bg-primary flex-shrink-0"></span>
          </div>
          <p class="text-[13px] text-gray-500 line-clamp-2 mt-0.5">{{ n.message }}</p>
          <span class="inline-block mt-1.5 px-2 py-0.5 rounded-md bg-gray-100 text-gray-500 text-[11px] font-medium">{{ t(`notifs.${catMeta(n._cat).label}`) }}</span>
        </div>
      </button>
    </div>
  </AdminLayout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import AdminLayout from '@/layouts/AdminLayout.vue'
import { adminApi } from '@/api/admin'
import { useAdminI18n } from '@/i18n/admin'
import { useAdminBadges } from '@/composables/useAdminBadges'

const router = useRouter()
const { t, locale } = useAdminI18n()
const { setNotifications } = useAdminBadges()

const loading = ref(true)
const items = ref([])
const unreadCount = ref(0)
const tab = ref('all')
const activeCat = ref('')

const categories = [
  { key: 'approval', label: 'catApproval', icon: 'fact_check' },
  { key: 'booking', label: 'catBooking', icon: 'calendar_month' },
  { key: 'cancellation', label: 'catCancellation', icon: 'cancel' },
  { key: 'partner', label: 'catPartner', icon: 'handshake' },
  { key: 'system', label: 'catSystem', icon: 'settings' },
  { key: 'payment', label: 'catPayment', icon: 'payments' },
]

// Notification payloads carry no explicit category → infer from data/icon/url.
function inferCat(n) {
  const explicit = (n.category || n.type || '').toLowerCase()
  for (const c of categories) if (explicit.includes(c.key)) return c.key
  const hay = `${n.icon || ''} ${n.action_url || ''}`.toLowerCase()
  if (/fact_check|task_alt|approv|request|verified|check_circle/.test(hay)) return 'approval'
  if (/calendar|event|booking/.test(hay)) return 'booking'
  if (/cancel|block|refund|close/.test(hay)) return 'cancellation'
  if (/handshake|partner|store/.test(hay)) return 'partner'
  if (/payment|paid|money|wallet|card/.test(hay)) return 'payment'
  return 'system'
}

const catByKey = Object.fromEntries(categories.map((c) => [c.key, c]))
const catColors = {
  approval: { bg: 'bg-emerald-50', color: 'text-emerald-600' },
  booking: { bg: 'bg-blue-50', color: 'text-blue-600' },
  cancellation: { bg: 'bg-red-50', color: 'text-red-600' },
  partner: { bg: 'bg-violet-50', color: 'text-violet-600' },
  payment: { bg: 'bg-amber-50', color: 'text-amber-600' },
  system: { bg: 'bg-gray-100', color: 'text-gray-500' },
}
function catMeta(key) {
  const c = catByKey[key] || catByKey.system
  return { ...c, ...(catColors[key] || catColors.system) }
}

const filtered = computed(() => items.value.filter((n) => {
  if (tab.value === 'unread' && n.read) return false
  if (activeCat.value && n._cat !== activeCat.value) return false
  return true
}))

function timeLabel(iso) {
  if (!iso) return ''
  const d = new Date(iso)
  const sameDay = d.toDateString() === new Date().toDateString()
  return new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar' : 'en',
    sameDay ? { hour: '2-digit', minute: '2-digit' } : { day: '2-digit', month: 'short' }).format(d)
}

async function load() {
  loading.value = true
  try {
    const { data } = await adminApi.listNotifications()
    const d = data.data ?? data
    items.value = (d.items ?? []).map((n) => ({ ...n, _cat: inferCat(n) }))
    unreadCount.value = d.unread_count ?? 0
  } catch {
    /* transient */
  } finally {
    loading.value = false
  }
}

async function markAll() {
  try {
    await adminApi.markAllNotificationsRead()
    items.value = items.value.map((n) => ({ ...n, read: true }))
    unreadCount.value = 0
    setNotifications(0)
  } catch { /* noop */ }
}

async function open(n) {
  if (!n.read) {
    try {
      await adminApi.markNotificationRead(n.id)
      n.read = true
      unreadCount.value = Math.max(0, unreadCount.value - 1)
      setNotifications(unreadCount.value)
    } catch { /* noop */ }
  }
  if (n.action_url) router.push(n.action_url)
}

onMounted(load)
</script>
