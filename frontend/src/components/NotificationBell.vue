<template>
  <div ref="root" class="relative">
    <!-- Bell trigger -->
    <button
      :class="variant === 'mobile'
        ? 'relative p-2 hover:bg-white/10 rounded-lg'
        : 'relative w-10 h-10 flex items-center justify-center rounded-full hover:bg-surface-container transition-colors'"
      @click="toggle"
    >
      <span
        class="material-symbols-outlined"
        :class="variant === 'mobile' ? '' : 'text-on-surface-variant'"
      >notifications</span>
      <span
        v-if="unreadCount > 0"
        class="absolute -top-0.5 -left-0.5 min-w-[18px] h-[18px] px-1 flex items-center justify-center bg-error text-white text-[10px] font-bold rounded-full border-2"
        :class="variant === 'mobile' ? 'border-primary' : 'border-white'"
      >{{ unreadCount > 99 ? '99+' : unreadCount }}</span>
    </button>

    <!-- Dropdown panel -->
    <transition name="fade">
      <div
        v-if="open"
        class="absolute left-0 mt-2 w-[340px] max-w-[90vw] bg-white rounded-2xl border border-outline-variant shadow-lg z-50 overflow-hidden"
        dir="rtl"
      >
        <div class="flex items-center justify-between px-4 py-3 border-b border-outline-variant">
          <h4 class="font-title-sm text-title-sm text-primary">الإشعارات</h4>
          <button
            v-if="unreadCount > 0"
            class="text-[12px] text-primary hover:underline"
            @click="markAll"
          >تعليم الكل كمقروء</button>
        </div>

        <div class="max-h-[380px] overflow-y-auto">
          <div v-if="loading" class="p-6 text-center text-on-surface-variant text-body-sm">جارٍ التحميل…</div>
          <div v-else-if="items.length === 0" class="p-8 text-center text-on-surface-variant text-body-sm">
            <span class="material-symbols-outlined text-3xl opacity-40 block mb-2">notifications_off</span>
            لا توجد إشعارات
          </div>
          <button
            v-for="n in items"
            :key="n.id"
            class="w-full text-right flex items-start gap-3 px-4 py-3 border-b border-outline-variant/50 hover:bg-surface-container-low/60 transition-colors"
            :class="!n.read ? 'bg-primary/5' : ''"
            @click="openItem(n)"
          >
            <span class="material-symbols-outlined text-primary mt-0.5 text-[20px]">{{ n.icon || 'notifications' }}</span>
            <div class="flex-1 min-w-0">
              <p class="text-body-sm font-semibold text-on-surface truncate">{{ n.title }}</p>
              <p class="text-[12px] text-on-surface-variant line-clamp-2">{{ n.message }}</p>
              <p class="text-[10px] text-on-surface-variant mt-1">{{ formatTime(n.created_at) }}</p>
            </div>
            <span v-if="!n.read" class="w-2 h-2 rounded-full bg-primary flex-shrink-0 mt-1.5"></span>
          </button>
        </div>
      </div>
    </transition>
  </div>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { useRouter } from 'vue-router'
import http from '@/api/http'

const props = defineProps({
  variant: { type: String, default: 'desktop' }, // 'desktop' | 'mobile'
  basePath: { type: String, default: '/admin/notifications' }, // role-scoped endpoint
})

const router = useRouter()
const root = ref(null)
const open = ref(false)
const loading = ref(false)
const items = ref([])
const unreadCount = ref(0)
let pollTimer = null

async function fetchUnread() {
  try {
    const res = await http.get(`${props.basePath}/unread-count`)
    unreadCount.value = (res.data.data ?? res.data).unread_count ?? 0
  } catch (e) {
    // ignore transient errors
  }
}

async function fetchList() {
  loading.value = true
  try {
    const res = await http.get(props.basePath)
    const data = res.data.data ?? res.data
    items.value = data.items ?? []
    unreadCount.value = data.unread_count ?? 0
  } catch (e) {
    items.value = []
  } finally {
    loading.value = false
  }
}

function toggle() {
  open.value = !open.value
  if (open.value) fetchList()
}

async function markAll() {
  try {
    await http.post(`${props.basePath}/read-all`)
    items.value = items.value.map((n) => ({ ...n, read: true }))
    unreadCount.value = 0
  } catch (e) { /* noop */ }
}

async function openItem(n) {
  if (!n.read) {
    try {
      await http.post(`${props.basePath}/${n.id}/read`)
      n.read = true
      unreadCount.value = Math.max(0, unreadCount.value - 1)
    } catch (e) { /* noop */ }
  }
  open.value = false
  if (n.action_url) router.push(n.action_url)
}

function onClickOutside(e) {
  if (open.value && root.value && !root.value.contains(e.target)) open.value = false
}

function formatTime(iso) {
  if (!iso) return ''
  const d = new Date(iso)
  const diff = (Date.now() - d.getTime()) / 1000
  if (diff < 60) return 'الآن'
  if (diff < 3600) return `منذ ${Math.floor(diff / 60)} دقيقة`
  if (diff < 86400) return `منذ ${Math.floor(diff / 3600)} ساعة`
  return d.toLocaleDateString('ar-SA')
}

onMounted(() => {
  fetchUnread()
  pollTimer = setInterval(fetchUnread, 30000) // poll unread badge every 30s
  document.addEventListener('click', onClickOutside)
})

onBeforeUnmount(() => {
  if (pollTimer) clearInterval(pollTimer)
  document.removeEventListener('click', onClickOutside)
})
</script>
