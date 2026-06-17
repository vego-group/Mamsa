<template>
  <AdminLayout>
    <div class="mb-8 flex items-end justify-between">
      <div>
        <h1 class="font-display-lg text-display-lg text-primary mb-1">إدارة المستخدمين</h1>
        <p class="text-on-surface-variant text-body-md">إدارة حسابات المستخدمين والمدراء في المنصة</p>
      </div>
      <button
        class="flex items-center gap-2 px-5 py-3 bg-primary text-on-primary rounded-xl font-bold shadow-sm hover:bg-primary-container transition-colors"
        @click="openAddModal"
      >
        <span class="material-symbols-outlined text-[18px]">add</span>
        إضافة مستخدم
      </button>
    </div>

    <!-- Tabs -->
    <div class="flex gap-1 border-b border-outline-variant mb-6 overflow-x-auto">
      <button
        v-for="tab in tabs"
        :key="tab.key"
        class="px-6 py-3 font-title-sm text-title-sm transition-all border-b-4 whitespace-nowrap"
        :class="activeTab === tab.key ? 'text-primary border-primary' : 'text-on-surface-variant border-transparent hover:text-primary'"
        @click="changeTab(tab.key)"
      >
        {{ tab.label }}
        <span class="mr-2 px-2 py-0.5 rounded-full text-label-caps" :class="activeTab === tab.key ? 'bg-primary text-on-primary' : 'bg-surface-container text-on-surface-variant'">
          {{ counts[tab.key] ?? 0 }}
        </span>
      </button>
    </div>

    <!-- Search -->
    <div class="flex gap-3 mb-6">
      <div class="relative flex-1">
        <span class="material-symbols-outlined absolute right-4 top-1/2 -translate-y-1/2 text-on-surface-variant">search</span>
        <input
          v-model="search"
          @keyup.enter="load"
          class="w-full pr-12 pl-4 py-2.5 bg-white border border-outline-variant rounded-xl text-body-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none transition-all"
          placeholder="ابحث بالاسم أو الجوال أو البريد... (اضغط Enter)"
        />
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="bg-white rounded-2xl border border-outline-variant p-4 space-y-3">
      <div v-for="i in 5" :key="i" class="animate-pulse flex items-center gap-3">
        <div class="w-9 h-9 rounded-full bg-surface-container"></div>
        <div class="flex-1 space-y-2"><div class="h-3 bg-surface-container rounded w-1/3"></div><div class="h-2 bg-surface-container rounded w-1/4"></div></div>
      </div>
    </div>

    <!-- Empty -->
    <div v-else-if="users.length === 0" class="text-center py-16 text-on-surface-variant bg-white rounded-2xl border border-outline-variant">
      <span class="material-symbols-outlined text-5xl mb-3 block">group_off</span>
      <p class="font-title-sm text-title-sm">لا يوجد مستخدمون مطابقون</p>
    </div>

    <!-- Table -->
    <div v-else class="bg-white rounded-2xl border border-outline-variant shadow-sm overflow-x-auto">
      <table class="w-full min-w-[640px]">
        <thead>
          <tr class="bg-surface-container-low border-b border-outline-variant">
            <th class="text-right py-3 px-4 font-label-caps text-label-caps text-on-surface-variant">المستخدم</th>
            <th class="text-right py-3 px-4 font-label-caps text-label-caps text-on-surface-variant hidden md:table-cell">رقم الجوال</th>
            <th class="text-right py-3 px-4 font-label-caps text-label-caps text-on-surface-variant">الدور</th>
            <th class="text-right py-3 px-4 font-label-caps text-label-caps text-on-surface-variant">الحالة</th>
            <th class="py-3 px-4"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="user in users" :key="user.id" class="border-b border-outline-variant/50 last:border-0 hover:bg-surface-container-low/50 transition-colors">
            <td class="py-3 px-4">
              <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-full bg-secondary-container flex items-center justify-center text-primary font-bold text-sm flex-shrink-0">
                  {{ initials(user.name) }}
                </div>
                <div>
                  <p class="font-body-md font-semibold text-on-surface leading-tight">{{ user.name || '—' }}</p>
                  <p class="text-body-sm text-on-surface-variant">{{ user.email || '—' }}</p>
                </div>
              </div>
            </td>
            <td class="py-3 px-4 hidden md:table-cell"><span class="font-numeric-data text-body-sm text-on-surface" dir="ltr">{{ user.phone }}</span></td>
            <td class="py-3 px-4"><span class="px-2.5 py-1 rounded-full text-[12px] font-bold" :class="roleClass(user.role)">{{ roleLabel(user.role) }}</span></td>
            <td class="py-3 px-4">
              <button
                class="px-2.5 py-1 rounded-full text-[12px] font-bold transition-colors"
                :class="user.is_active ? 'bg-emerald-100 text-emerald-700 hover:bg-emerald-200' : 'bg-surface-container text-on-surface-variant hover:bg-outline-variant'"
                :disabled="busyId === user.id"
                @click="toggleStatus(user)"
              >
                {{ user.is_active ? 'نشط' : 'موقوف' }}
              </button>
            </td>
            <td class="py-3 px-4">
              <div class="flex items-center gap-2 justify-end">
                <button class="p-1.5 rounded-lg hover:bg-error-container text-on-surface-variant hover:text-error transition-colors disabled:opacity-40" :disabled="busyId === user.id" @click="confirmDelete(user)">
                  <span class="material-symbols-outlined text-[18px]">delete</span>
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <div v-if="meta.last_page > 1" class="flex items-center justify-center gap-2 mt-4">
      <button class="px-3 py-1.5 rounded-lg border border-outline-variant text-body-sm disabled:opacity-40" :disabled="page <= 1" @click="goPage(page - 1)">السابق</button>
      <span class="text-body-sm text-on-surface-variant">{{ page }} / {{ meta.last_page }}</span>
      <button class="px-3 py-1.5 rounded-lg border border-outline-variant text-body-sm disabled:opacity-40" :disabled="page >= meta.last_page" @click="goPage(page + 1)">التالي</button>
    </div>

    <!-- Add user modal -->
    <Teleport to="body">
      <div v-if="showAddModal" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl border border-outline-variant shadow-xl w-full max-w-md p-6" dir="rtl">
          <div class="flex items-center justify-between mb-6">
            <h2 class="font-headline-md text-headline-md text-primary">إضافة مستخدم جديد</h2>
            <button class="p-2 hover:bg-surface-container rounded-lg" @click="showAddModal = false"><span class="material-symbols-outlined">close</span></button>
          </div>
          <form class="space-y-4" @submit.prevent="createUser">
            <div>
              <label class="block text-body-sm font-bold text-on-surface mb-1.5">الاسم الكامل</label>
              <input v-model="form.name" class="field" :class="{ 'border-error': errors.name }" placeholder="اسم المستخدم" required />
              <p v-if="errors.name" class="text-error text-body-sm mt-1">{{ errors.name }}</p>
            </div>
            <div>
              <label class="block text-body-sm font-bold text-on-surface mb-1.5">رقم الجوال</label>
              <input v-model="form.phone" class="field" :class="{ 'border-error': errors.phone }" placeholder="+9665XXXXXXXX" dir="ltr" required />
              <p v-if="errors.phone" class="text-error text-body-sm mt-1">{{ errors.phone }}</p>
            </div>
            <div>
              <label class="block text-body-sm font-bold text-on-surface mb-1.5">البريد الإلكتروني (اختياري)</label>
              <input v-model="form.email" type="email" class="field" :class="{ 'border-error': errors.email }" dir="ltr" />
              <p v-if="errors.email" class="text-error text-body-sm mt-1">{{ errors.email }}</p>
            </div>
            <div>
              <label class="block text-body-sm font-bold text-on-surface mb-1.5">الدور</label>
              <select v-model="form.role" class="field">
                <option value="User">مستخدم</option>
                <option value="Individual">شريك فرد</option>
                <option value="Company">شريك شركة</option>
                <option value="Admin">مدير</option>
                <option value="SuperAdmin">مدير عام</option>
              </select>
            </div>
            <div class="flex gap-3 pt-2">
              <button type="submit" class="flex-1 py-3 bg-primary text-on-primary rounded-xl font-bold hover:bg-primary-container transition-colors disabled:opacity-50" :disabled="saving">
                {{ saving ? 'جارٍ الحفظ...' : 'إضافة' }}
              </button>
              <button type="button" class="flex-1 py-3 border border-outline-variant rounded-xl font-bold text-on-surface hover:bg-surface-container transition-colors" @click="showAddModal = false">إلغاء</button>
            </div>
          </form>
        </div>
      </div>
    </Teleport>

    <!-- Delete confirm -->
    <Teleport to="body">
      <div v-if="deleteTarget" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl border border-outline-variant shadow-xl w-full max-w-sm p-6 text-center" dir="rtl">
          <div class="w-16 h-16 bg-error-container rounded-full flex items-center justify-center mx-auto mb-4">
            <span class="material-symbols-outlined text-error text-3xl">delete_forever</span>
          </div>
          <h2 class="font-headline-md text-headline-md text-on-surface mb-2">تأكيد الحذف</h2>
          <p class="text-body-md text-on-surface-variant mb-6">حذف حساب <strong>{{ deleteTarget.name }}</strong>؟ لا يمكن التراجع.</p>
          <div class="flex gap-3">
            <button class="flex-1 py-3 bg-error text-on-error rounded-xl font-bold hover:opacity-90 transition-opacity disabled:opacity-50" :disabled="busyId === deleteTarget.id" @click="deleteUser">حذف</button>
            <button class="flex-1 py-3 border border-outline-variant rounded-xl font-bold text-on-surface hover:bg-surface-container transition-colors" @click="deleteTarget = null">إلغاء</button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Toast -->
    <Transition name="fade">
      <div v-if="toast" class="fixed bottom-6 left-1/2 -translate-x-1/2 z-50 px-5 py-3 rounded-xl shadow-lg text-white font-bold text-body-sm" :class="toast.type === 'error' ? 'bg-error' : 'bg-primary'">
        {{ toast.msg }}
      </div>
    </Transition>
  </AdminLayout>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import AdminLayout from '@/layouts/AdminLayout.vue'
import { adminApi } from '@/api/admin'

const loading = ref(true)
const saving = ref(false)
const busyId = ref(null)
const users = ref([])
const counts = ref({})
const meta = ref({ last_page: 1 })
const page = ref(1)
const activeTab = ref('all')
const search = ref('')
const showAddModal = ref(false)
const deleteTarget = ref(null)
const toast = ref(null)
const errors = reactive({})

const form = reactive({ name: '', phone: '', email: '', role: 'Admin' })

const tabs = [
  { key: 'all',      label: 'الكل' },
  { key: 'admins',   label: 'المدراء' },
  { key: 'partners', label: 'الشركاء' },
  { key: 'users',    label: 'المستخدمون' },
]

function showToast(msg, type = 'success') {
  toast.value = { msg, type }
  setTimeout(() => (toast.value = null), 2800)
}
function initials(name) {
  return (name || '').split(' ').slice(0, 2).map((w) => w[0]).join('') || '؟'
}
function roleLabel(role) {
  return { SuperAdmin: 'مدير عام', Admin: 'مدير', Individual: 'فرد', Company: 'شركة', User: 'مستخدم' }[role] || role || '—'
}
function roleClass(role) {
  return {
    SuperAdmin: 'bg-purple-100 text-purple-700',
    Admin:      'bg-blue-100 text-blue-700',
    Individual: 'bg-secondary-container text-on-secondary-container',
    Company:    'bg-secondary-container text-on-secondary-container',
    User:       'bg-surface-container text-on-surface-variant',
  }[role] || 'bg-surface-container text-on-surface-variant'
}

async function load() {
  loading.value = true
  try {
    const params = { page: page.value }
    if (activeTab.value !== 'all') params.role = activeTab.value
    if (search.value) params.search = search.value
    const { data } = await adminApi.listUsers(params)
    users.value = data.data ?? []
    counts.value = data.counts ?? {}
    meta.value = data.meta ?? { last_page: 1 }
  } catch (e) {
    showToast('تعذّر تحميل المستخدمين', 'error')
  } finally {
    loading.value = false
  }
}

function changeTab(key) {
  activeTab.value = key
  page.value = 1
  load()
}
function goPage(p) {
  page.value = p
  load()
}

function openAddModal() {
  Object.keys(errors).forEach((k) => delete errors[k])
  Object.assign(form, { name: '', phone: '', email: '', role: 'Admin' })
  showAddModal.value = true
}

async function createUser() {
  Object.keys(errors).forEach((k) => delete errors[k])
  saving.value = true
  try {
    await adminApi.createUser({ ...form, email: form.email || undefined })
    showAddModal.value = false
    showToast('تم إضافة المستخدم')
    page.value = 1
    await load()
  } catch (e) {
    if (e.response?.status === 422 && e.response.data?.errors) {
      for (const [f, m] of Object.entries(e.response.data.errors)) errors[f] = m[0]
    } else {
      showToast(e.response?.data?.message || 'تعذّر الإضافة', 'error')
    }
  } finally {
    saving.value = false
  }
}

async function toggleStatus(user) {
  busyId.value = user.id
  try {
    const { data } = await adminApi.updateUserStatus(user.id, !user.is_active)
    user.is_active = (data.data ?? data).is_active
    showToast('تم تحديث الحالة')
  } catch (e) {
    showToast(e.response?.data?.message || 'تعذّر تحديث الحالة', 'error')
  } finally {
    busyId.value = null
  }
}

function confirmDelete(user) {
  deleteTarget.value = user
}
async function deleteUser() {
  const u = deleteTarget.value
  busyId.value = u.id
  try {
    await adminApi.deleteUser(u.id)
    showToast('تم حذف المستخدم')
    deleteTarget.value = null
    await load()
  } catch (e) {
    showToast(e.response?.data?.message || 'تعذّر الحذف', 'error')
  } finally {
    busyId.value = null
  }
}

onMounted(load)
</script>

<style scoped>
.field {
  @apply w-full px-4 py-2.5 bg-surface-container-low border border-outline-variant rounded-xl text-body-md
         focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none transition-all;
}
.fade-enter-active, .fade-leave-active { transition: opacity 0.25s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
