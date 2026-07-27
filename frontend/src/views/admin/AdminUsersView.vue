<template>
  <AdminLayout>
    <!-- Title row -->
    <div class="flex flex-wrap items-start justify-between gap-3 mb-6">
      <div>
        <h1 class="text-[24px] font-bold text-gray-900 leading-tight">{{ t('users.title') }}</h1>
        <p class="text-gray-500 text-[14px] mt-0.5">{{ t('users.subtitle', { count: stats.total }) }}</p>
      </div>
      <div class="flex items-center gap-2">
        <button class="inline-flex items-center gap-1.5 h-9 px-4 rounded-lg border border-gray-200 bg-white text-[13px] font-semibold text-gray-600 hover:bg-gray-50 transition-colors" @click="exportCsv">
          <span class="material-symbols-outlined text-[18px]">download</span>{{ t('users.exportCsv') }}
        </button>
        <button class="inline-flex items-center gap-1.5 h-9 px-4 rounded-lg bg-primary text-white text-[13px] font-semibold hover:bg-primary-container transition-colors" @click="openInvite">
          <span class="material-symbols-outlined text-[18px]">add</span>{{ t('users.invite') }}
        </button>
      </div>
    </div>

    <!-- Stat cards -->
    <section class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
      <div v-for="s in statCards" :key="s.key" class="bg-white rounded-2xl border border-gray-200 p-5">
        <div class="w-10 h-10 rounded-xl bg-gray-50 border border-gray-100 flex items-center justify-center mb-4">
          <span class="material-symbols-outlined text-[20px] text-primary">{{ s.icon }}</span>
        </div>
        <p class="text-[11px] font-bold uppercase tracking-wide text-gray-400 mb-1">{{ s.label }}</p>
        <p class="text-[24px] font-bold text-gray-900 leading-none tabular-nums">{{ s.value }}</p>
      </div>
    </section>

    <!-- Table card -->
    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
      <!-- Tabs + search -->
      <div class="flex flex-wrap items-center gap-3 justify-between px-4 py-3 border-b border-gray-100">
        <div class="flex items-center gap-1 bg-gray-50 rounded-lg p-1">
          <button
            v-for="tab in tabs" :key="tab.key"
            class="px-3 py-1.5 rounded-md text-[13px] font-semibold transition-colors flex items-center gap-1.5"
            :class="activeTab === tab.key ? 'bg-white text-primary shadow-sm' : 'text-gray-500 hover:text-gray-700'"
            @click="changeTab(tab.key)"
          >
            {{ tab.label }}
            <span class="text-[11px] px-1.5 rounded-full" :class="activeTab === tab.key ? 'bg-primary/10 text-primary' : 'bg-gray-200 text-gray-500'">{{ tab.count }}</span>
          </button>
        </div>
        <div class="relative w-full sm:w-64">
          <span class="material-symbols-outlined absolute top-1/2 -translate-y-1/2 text-gray-400 text-[18px]" :class="dir === 'rtl' ? 'right-3' : 'left-3'">search</span>
          <input
            v-model="search" @keyup.enter="reload"
            class="w-full h-9 bg-gray-50 border border-gray-200 rounded-lg text-[13px] outline-none focus:ring-2 focus:ring-primary/15 focus:border-primary/40 transition-all"
            :class="dir === 'rtl' ? 'pr-9 pl-3' : 'pl-9 pr-3'"
            :placeholder="t('users.search')"
          />
        </div>
      </div>

      <!-- Loading -->
      <div v-if="loading" class="p-4 space-y-3">
        <div v-for="i in 6" :key="i" class="animate-pulse flex items-center gap-3">
          <div class="w-9 h-9 rounded-full bg-gray-100"></div>
          <div class="flex-1 space-y-2"><div class="h-3 bg-gray-100 rounded w-1/3"></div><div class="h-2 bg-gray-100 rounded w-1/4"></div></div>
        </div>
      </div>

      <!-- Empty -->
      <div v-else-if="users.length === 0" class="py-16 text-center text-gray-400 text-[13px]">
        <span class="material-symbols-outlined text-4xl opacity-40 block mb-2">group_off</span>{{ t('users.empty') }}
      </div>

      <!-- Table -->
      <div v-else class="overflow-x-auto">
        <table class="w-full text-[13px]">
          <thead>
            <tr class="text-gray-400 text-[11px] font-bold uppercase tracking-wide border-b border-gray-100">
              <th class="text-start font-bold px-5 py-3">{{ t('users.colUser') }}</th>
              <th class="text-start font-bold px-5 py-3 hidden md:table-cell">{{ t('users.colMobile') }}</th>
              <th class="text-start font-bold px-5 py-3 hidden lg:table-cell">{{ t('users.colCity') }}</th>
              <th class="text-start font-bold px-5 py-3">{{ t('users.colBookings') }}</th>
              <th class="text-start font-bold px-5 py-3 hidden sm:table-cell">{{ t('users.colSpent') }}</th>
              <th class="text-start font-bold px-5 py-3 hidden lg:table-cell">{{ t('users.colJoined') }}</th>
              <th class="text-start font-bold px-5 py-3">{{ t('users.colStatus') }}</th>
              <th class="px-5 py-3"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="u in users" :key="u.id" class="border-b border-gray-50 last:border-0 hover:bg-gray-50 transition-colors cursor-pointer" @click="openDrawer(u)">
              <td class="px-5 py-3">
                <div class="flex items-center gap-3">
                  <div class="w-9 h-9 rounded-full bg-primary/85 flex items-center justify-center text-white font-bold text-[12px] flex-shrink-0">{{ initials(u.name) }}</div>
                  <div class="min-w-0">
                    <p class="font-semibold text-gray-900 leading-tight truncate">{{ u.name || '—' }}</p>
                    <p class="text-[11px] text-gray-400 tabular-nums">{{ u.code }}</p>
                  </div>
                </div>
              </td>
              <td class="px-5 py-3 hidden md:table-cell text-gray-600 tabular-nums" dir="ltr">{{ u.phone || '—' }}</td>
              <td class="px-5 py-3 hidden lg:table-cell">
                <span v-if="u.city" class="inline-block px-2.5 py-0.5 rounded-full bg-gray-100 text-gray-600 text-[12px]">{{ u.city }}</span>
                <span v-else class="text-gray-300">—</span>
              </td>
              <td class="px-5 py-3 font-semibold text-gray-900 tabular-nums">{{ u.bookings_count }}</td>
              <td class="px-5 py-3 hidden sm:table-cell font-semibold text-gray-900 tabular-nums">{{ sar(u.total_spent) }}</td>
              <td class="px-5 py-3 hidden lg:table-cell text-gray-400 tabular-nums">{{ date(u.created_at) }}</td>
              <td class="px-5 py-3">
                <span class="inline-flex items-center gap-1.5 text-[12px] font-semibold" :class="statusMeta(u.status).text">
                  <span class="w-1.5 h-1.5 rounded-full" :class="statusMeta(u.status).dot"></span>{{ statusMeta(u.status).label }}
                </span>
              </td>
              <td class="px-5 py-3" @click.stop>
                <div class="flex items-center gap-1 justify-end text-gray-400">
                  <button class="p-1.5 rounded-lg hover:bg-gray-100 hover:text-gray-700 transition-colors" :title="t('users.view')" @click="openDrawer(u)">
                    <span class="material-symbols-outlined text-[18px]">visibility</span>
                  </button>
                  <button class="p-1.5 rounded-lg hover:bg-gray-100 transition-colors disabled:opacity-40" :class="u.is_active ? 'hover:text-amber-600' : 'hover:text-emerald-600'" :title="u.is_active ? t('users.disable') : t('users.enable')" :disabled="busyId === u.id" @click="toggleStatus(u)">
                    <span class="material-symbols-outlined text-[18px]">{{ u.is_active ? 'block' : 'check_circle' }}</span>
                  </button>
                  <button class="p-1.5 rounded-lg hover:bg-red-50 hover:text-red-600 transition-colors disabled:opacity-40" :title="t('users.delete')" :disabled="busyId === u.id" @click="confirmDelete(u)">
                    <span class="material-symbols-outlined text-[18px]">delete</span>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="users.length" class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 border-t border-gray-100">
        <p class="text-[12px] text-gray-400 tabular-nums">{{ t('users.showing', { from: showingFrom, to: showingTo, total: meta.total }) }}</p>
        <div class="flex items-center gap-1">
          <button class="w-8 h-8 rounded-lg border border-gray-200 text-gray-500 disabled:opacity-40 hover:bg-gray-50 grid place-items-center" :disabled="page <= 1" @click="goPage(page - 1)">
            <span class="material-symbols-outlined text-[18px]">{{ dir === 'rtl' ? 'chevron_right' : 'chevron_left' }}</span>
          </button>
          <button
            v-for="p in pageNumbers" :key="p"
            class="min-w-8 h-8 px-2 rounded-lg text-[13px] font-semibold grid place-items-center transition-colors"
            :class="p === page ? 'bg-primary text-white' : 'border border-gray-200 text-gray-600 hover:bg-gray-50'"
            @click="goPage(p)"
          >{{ p }}</button>
          <button class="w-8 h-8 rounded-lg border border-gray-200 text-gray-500 disabled:opacity-40 hover:bg-gray-50 grid place-items-center" :disabled="page >= meta.last_page" @click="goPage(page + 1)">
            <span class="material-symbols-outlined text-[18px]">{{ dir === 'rtl' ? 'chevron_left' : 'chevron_right' }}</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Detail drawer -->
    <UserDetailDrawer
      :open="drawerOpen" :user-id="drawerUserId"
      @close="drawerOpen = false"
      @changed="onDrawerChanged"
      @deleted="onDrawerDeleted"
      @error="showToast(t('users.actionError'), 'error')"
    />

    <!-- Invite modal -->
    <Teleport to="body">
      <div v-if="showInvite" class="fixed inset-0 bg-black/40 z-[70] flex items-center justify-center p-4" :dir="dir">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-md p-6">
          <div class="flex items-center justify-between mb-5">
            <h2 class="text-[18px] font-bold text-gray-900">{{ t('users.inviteTitle') }}</h2>
            <button class="p-2 -m-2 text-gray-400 hover:text-gray-700" @click="showInvite = false"><span class="material-symbols-outlined">close</span></button>
          </div>
          <form class="space-y-4" @submit.prevent="createUser">
            <div>
              <label class="block text-[13px] font-semibold text-gray-700 mb-1.5">{{ t('users.fName') }}</label>
              <input v-model="form.name" class="ifield" :class="{ 'border-red-400': errors.name }" required />
              <p v-if="errors.name" class="text-red-600 text-[12px] mt-1">{{ errors.name }}</p>
            </div>
            <div>
              <label class="block text-[13px] font-semibold text-gray-700 mb-1.5">{{ t('users.fMobile') }}</label>
              <input v-model="form.phone" class="ifield" :class="{ 'border-red-400': errors.phone }" placeholder="+9665XXXXXXXX" dir="ltr" required />
              <p v-if="errors.phone" class="text-red-600 text-[12px] mt-1">{{ errors.phone }}</p>
            </div>
            <div>
              <label class="block text-[13px] font-semibold text-gray-700 mb-1.5">{{ t('users.fEmail') }}</label>
              <input v-model="form.email" type="email" class="ifield" :class="{ 'border-red-400': errors.email }" dir="ltr" />
              <p v-if="errors.email" class="text-red-600 text-[12px] mt-1">{{ errors.email }}</p>
            </div>
            <div>
              <label class="block text-[13px] font-semibold text-gray-700 mb-1.5">{{ t('users.fRole') }}</label>
              <select v-model="form.role" class="ifield">
                <option value="User">{{ t('users.roleUser') }}</option>
                <option value="Individual">{{ t('users.rolePartnerInd') }}</option>
                <option value="Company">{{ t('users.rolePartnerCo') }}</option>
                <option value="Admin">{{ t('users.roleAdmin') }}</option>
                <option value="SuperAdmin">{{ t('users.roleSuperAdmin') }}</option>
              </select>
            </div>
            <div class="flex gap-3 pt-1">
              <button type="button" class="flex-1 h-11 rounded-xl border border-gray-200 text-[13px] font-semibold text-gray-700 hover:bg-gray-50" @click="showInvite = false">{{ t('users.cancel') }}</button>
              <button type="submit" class="flex-1 h-11 rounded-xl bg-primary text-white text-[13px] font-semibold hover:bg-primary-container disabled:opacity-50" :disabled="saving">{{ saving ? t('users.saving') : t('users.save') }}</button>
            </div>
          </form>
        </div>
      </div>
    </Teleport>

    <!-- Delete confirm (from row action) -->
    <Teleport to="body">
      <div v-if="deleteTarget" class="fixed inset-0 bg-black/40 z-[70] flex items-center justify-center p-4" :dir="dir">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm p-6 text-center">
          <div class="w-14 h-14 bg-red-50 rounded-full flex items-center justify-center mx-auto mb-4"><span class="material-symbols-outlined text-red-600 text-3xl">delete_forever</span></div>
          <h3 class="text-[17px] font-bold text-gray-900 mb-1.5">{{ t('users.deleteTitle') }}</h3>
          <p class="text-[13px] text-gray-500 mb-5">{{ t('users.deleteMsg', { name: deleteTarget.name }) }}</p>
          <div class="flex gap-3">
            <button class="flex-1 h-11 rounded-xl border border-gray-200 text-[13px] font-semibold text-gray-700 hover:bg-gray-50" @click="deleteTarget = null">{{ t('users.cancel') }}</button>
            <button class="flex-1 h-11 rounded-xl bg-red-600 text-white text-[13px] font-semibold hover:bg-red-700 disabled:opacity-50" :disabled="busyId === deleteTarget.id" @click="doDelete">{{ t('users.delete') }}</button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Toast -->
    <transition name="fade">
      <div v-if="toast" class="fixed bottom-6 left-1/2 -translate-x-1/2 z-[80] px-5 py-3 rounded-xl shadow-lg text-white font-semibold text-[13px]" :class="toast.type === 'error' ? 'bg-red-600' : 'bg-primary'">{{ toast.msg }}</div>
    </transition>
  </AdminLayout>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import AdminLayout from '@/layouts/AdminLayout.vue'
import UserDetailDrawer from '@/components/admin/UserDetailDrawer.vue'
import { adminApi } from '@/api/admin'
import { useAdminI18n } from '@/i18n/admin'
import { useAdminFormat } from '@/composables/useAdminFormat'
import { useAdminBadges } from '@/composables/useAdminBadges'

const { t, dir } = useAdminI18n()
const { int, sar, sarCompact, date } = useAdminFormat()

const loading = ref(true)
const saving = ref(false)
const busyId = ref(null)
const users = ref([])
const stats = ref({ active: 0, inactive: 0, disabled: 0, total: 0, avg_spend: 0 })
const meta = ref({ current_page: 1, last_page: 1, total: 0 })
const page = ref(1)
const activeTab = ref('all')
const search = ref('')
const toast = ref(null)

const drawerOpen = ref(false)
const drawerUserId = ref(null)
const showInvite = ref(false)
const deleteTarget = ref(null)
const errors = reactive({})
const form = reactive({ name: '', phone: '', email: '', role: 'User' })

const tabs = computed(() => [
  { key: 'all',      label: t('users.tabAll'),      count: stats.value.total },
  { key: 'active',   label: t('users.tabActive'),   count: stats.value.active },
  { key: 'inactive', label: t('users.tabInactive'), count: stats.value.inactive },
  { key: 'disabled', label: t('users.tabDisabled'), count: stats.value.disabled },
])

const statCards = computed(() => [
  { key: 'active',   icon: 'group',       label: t('users.activeUsers'),   value: int(stats.value.active) },
  { key: 'inactive', icon: 'person_off',  label: t('users.inactiveUsers'), value: int(stats.value.inactive) },
  { key: 'avg',      icon: 'trending_up', label: t('users.avgSpend'),      value: sarCompact(stats.value.avg_spend) },
])

const showingFrom = computed(() => (users.value.length ? (page.value - 1) * 20 + 1 : 0))
const showingTo = computed(() => (page.value - 1) * 20 + users.value.length)
const pageNumbers = computed(() => {
  const last = meta.value.last_page || 1
  const out = []
  for (let p = Math.max(1, page.value - 1); p <= Math.min(last, page.value + 2); p++) out.push(p)
  if (!out.includes(1)) out.unshift(1)
  return [...new Set(out)].sort((a, b) => a - b)
})

function initials(name) {
  return (name || '?').split(' ').slice(0, 2).map((w) => w[0]).join('').toUpperCase() || '?'
}
function statusMeta(s) {
  if (s === 'disabled') return { label: t('users.statusDisabled'), text: 'text-red-600', dot: 'bg-red-500' }
  if (s === 'inactive') return { label: t('users.statusInactive'), text: 'text-gray-500', dot: 'bg-gray-400' }
  return { label: t('users.statusActive'), text: 'text-emerald-600', dot: 'bg-emerald-500' }
}
function showToast(msg, type = 'success') {
  toast.value = { msg, type }
  setTimeout(() => (toast.value = null), 2600)
}

async function load() {
  loading.value = true
  try {
    const params = { page: page.value, role: 'users' } // Users screen = customers
    if (activeTab.value !== 'all') params.status = activeTab.value
    if (search.value) params.search = search.value
    const { data } = await adminApi.listUsers(params)
    users.value = data.data ?? []
    stats.value = data.stats ?? stats.value
    meta.value = data.meta ?? meta.value
  } catch {
    showToast(t('users.loadError'), 'error')
  } finally {
    loading.value = false
  }
}
function reload() { page.value = 1; load() }
function changeTab(key) { activeTab.value = key; page.value = 1; load() }
function goPage(p) { if (p < 1 || p > meta.value.last_page) return; page.value = p; load() }

function openDrawer(u) { drawerUserId.value = u.id; drawerOpen.value = true }
function onDrawerChanged() { load() }
function onDrawerDeleted() { showToast(t('users.userDeleted')); load() }

async function toggleStatus(u) {
  busyId.value = u.id
  try {
    const { data } = await adminApi.updateUserStatus(u.id, !u.is_active)
    u.is_active = (data.data ?? data).is_active
    showToast(t('users.statusUpdated'))
    load()
  } catch {
    showToast(t('users.actionError'), 'error')
  } finally {
    busyId.value = null
  }
}

function confirmDelete(u) { deleteTarget.value = u }
async function doDelete() {
  const u = deleteTarget.value
  busyId.value = u.id
  try {
    await adminApi.deleteUser(u.id)
    deleteTarget.value = null
    showToast(t('users.userDeleted'))
    load()
  } catch {
    showToast(t('users.actionError'), 'error')
  } finally {
    busyId.value = null
  }
}

function openInvite() {
  Object.keys(errors).forEach((k) => delete errors[k])
  Object.assign(form, { name: '', phone: '', email: '', role: 'User' })
  showInvite.value = true
}
async function createUser() {
  Object.keys(errors).forEach((k) => delete errors[k])
  saving.value = true
  try {
    await adminApi.createUser({ ...form, email: form.email || undefined })
    showInvite.value = false
    showToast(t('users.created'))
    reload()
  } catch (e) {
    if (e.response?.status === 422 && e.response.data?.errors) {
      for (const [f, m] of Object.entries(e.response.data.errors)) errors[f] = m[0]
    } else {
      showToast(t('users.actionError'), 'error')
    }
  } finally {
    saving.value = false
  }
}

function exportCsv() {
  const head = [t('users.colUser'), 'Code', t('users.colMobile'), t('users.colCity'), t('users.colBookings'), t('users.colSpent'), t('users.colJoined'), t('users.colStatus')]
  const rows = users.value.map((u) => [u.name, u.code, u.phone, u.city || '', u.bookings_count, u.total_spent, u.created_at, u.status])
  const csv = [head, ...rows].map((r) => r.map((c) => `"${String(c ?? '').replace(/"/g, '""')}"`).join(',')).join('\n')
  const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' })
  const a = document.createElement('a')
  a.href = URL.createObjectURL(blob)
  a.download = 'users.csv'
  a.click()
  URL.revokeObjectURL(a.href)
}

onMounted(load)
</script>

<style scoped>
.ifield {
  @apply w-full h-11 px-3.5 bg-gray-50 border border-gray-200 rounded-xl text-[14px] text-gray-800
         focus:ring-2 focus:ring-primary/15 focus:border-primary/40 outline-none transition-all;
}
.fade-enter-active, .fade-leave-active { transition: opacity 0.25s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
