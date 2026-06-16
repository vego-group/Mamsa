<template>
  <AdminLayout>
    <div class="mb-8 flex items-end justify-between">
      <div>
        <h1 class="font-display-lg text-display-lg text-primary mb-1">إدارة المستخدمين</h1>
        <p class="text-on-surface-variant text-body-md">إدارة حسابات المستخدمين والمدراء في المنصة</p>
      </div>
      <button
        class="flex items-center gap-2 px-5 py-3 bg-primary text-on-primary rounded-xl font-bold shadow-sm hover:bg-primary-container transition-colors"
        @click="showAddAdminModal = true"
      >
        <span class="material-symbols-outlined text-[18px]">add</span>
        إضافة مدير
      </button>
    </div>

    <!-- Tabs -->
    <div class="flex gap-1 border-b border-outline-variant mb-6">
      <button
        v-for="tab in tabs"
        :key="tab.key"
        class="px-6 py-3 font-title-sm text-title-sm transition-all border-b-4"
        :class="activeTab === tab.key
          ? 'text-primary border-primary'
          : 'text-on-surface-variant border-transparent hover:text-primary'"
        @click="activeTab = tab.key"
      >
        {{ tab.label }}
        <span class="mr-2 px-2 py-0.5 rounded-full text-label-caps" :class="activeTab === tab.key ? 'bg-primary text-on-primary' : 'bg-surface-container text-on-surface-variant'">
          {{ tab.count }}
        </span>
      </button>
    </div>

    <!-- Search + filter row -->
    <div class="flex gap-3 mb-6">
      <div class="relative flex-1">
        <span class="material-symbols-outlined absolute right-4 top-1/2 -translate-y-1/2 text-on-surface-variant">search</span>
        <input
          v-model="search"
          class="w-full pr-12 pl-4 py-2.5 bg-white border border-outline-variant rounded-xl text-body-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none transition-all"
          placeholder="ابحث بالاسم أو رقم الجوال..."
        />
      </div>
      <select class="px-4 py-2.5 bg-white border border-outline-variant rounded-xl text-body-sm focus:ring-2 focus:ring-primary/20 outline-none">
        <option>جميع الحالات</option>
        <option>نشط</option>
        <option>موقوف</option>
      </select>
    </div>

    <!-- Users table -->
    <div class="bg-white rounded-2xl border border-outline-variant shadow-sm overflow-hidden">
      <table class="w-full">
        <thead>
          <tr class="bg-surface-container-low border-b border-outline-variant">
            <th class="text-right py-3 px-4 font-label-caps text-label-caps text-on-surface-variant">المستخدم</th>
            <th class="text-right py-3 px-4 font-label-caps text-label-caps text-on-surface-variant hidden md:table-cell">رقم الجوال</th>
            <th class="text-right py-3 px-4 font-label-caps text-label-caps text-on-surface-variant hidden lg:table-cell">الدور</th>
            <th class="text-right py-3 px-4 font-label-caps text-label-caps text-on-surface-variant">الحالة</th>
            <th class="py-3 px-4"></th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="user in filteredUsers"
            :key="user.id"
            class="border-b border-outline-variant/50 last:border-0 hover:bg-surface-container-low/50 transition-colors"
          >
            <td class="py-3 px-4">
              <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-full bg-secondary-container flex items-center justify-center text-primary font-bold text-sm flex-shrink-0">
                  {{ initials(user.name) }}
                </div>
                <div>
                  <p class="font-body-md font-semibold text-on-surface leading-tight">{{ user.name }}</p>
                  <p class="text-body-sm text-on-surface-variant">{{ user.email || '—' }}</p>
                </div>
              </div>
            </td>
            <td class="py-3 px-4 hidden md:table-cell">
              <span class="font-numeric-data text-body-sm text-on-surface" dir="ltr">{{ user.phone }}</span>
            </td>
            <td class="py-3 px-4 hidden lg:table-cell">
              <span class="px-2.5 py-1 rounded-full text-[12px] font-bold" :class="roleClass(user.role)">{{ user.roleLabel }}</span>
            </td>
            <td class="py-3 px-4">
              <span class="px-2.5 py-1 rounded-full text-[12px] font-bold" :class="user.active ? 'bg-emerald-100 text-emerald-700' : 'bg-surface-container text-on-surface-variant'">
                {{ user.active ? 'نشط' : 'موقوف' }}
              </span>
            </td>
            <td class="py-3 px-4">
              <div class="flex items-center gap-2 justify-end">
                <button class="p-1.5 rounded-lg hover:bg-surface-container text-on-surface-variant hover:text-primary transition-colors">
                  <span class="material-symbols-outlined text-[18px]">edit</span>
                </button>
                <button class="p-1.5 rounded-lg hover:bg-error-container text-on-surface-variant hover:text-error transition-colors" @click="confirmDelete(user)">
                  <span class="material-symbols-outlined text-[18px]">delete</span>
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Add Admin Modal -->
    <Teleport to="body">
      <div v-if="showAddAdminModal" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl border border-outline-variant shadow-xl w-full max-w-md p-6" dir="rtl">
          <div class="flex items-center justify-between mb-6">
            <h2 class="font-headline-md text-headline-md text-primary">إضافة مدير جديد</h2>
            <button class="p-2 hover:bg-surface-container rounded-lg" @click="showAddAdminModal = false">
              <span class="material-symbols-outlined">close</span>
            </button>
          </div>
          <form class="space-y-4" @submit.prevent="addAdmin">
            <div>
              <label class="block text-body-sm font-bold text-on-surface mb-1.5">الاسم الكامل</label>
              <input v-model="newAdmin.name" class="w-full px-4 py-2.5 bg-surface-container-low border border-outline-variant rounded-xl text-body-md focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none" placeholder="اسم المدير" required />
            </div>
            <div>
              <label class="block text-body-sm font-bold text-on-surface mb-1.5">رقم الجوال</label>
              <input v-model="newAdmin.phone" class="w-full px-4 py-2.5 bg-surface-container-low border border-outline-variant rounded-xl text-body-md focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none" placeholder="+966XXXXXXXXX" dir="ltr" required />
            </div>
            <div>
              <label class="block text-body-sm font-bold text-on-surface mb-1.5">الصلاحية</label>
              <select v-model="newAdmin.role" class="w-full px-4 py-2.5 bg-surface-container-low border border-outline-variant rounded-xl text-body-md focus:ring-2 focus:ring-primary/20 outline-none">
                <option value="Admin">مدير</option>
                <option value="SuperAdmin">مدير عام</option>
              </select>
            </div>
            <div class="flex gap-3 pt-2">
              <button type="submit" class="flex-1 py-3 bg-primary text-on-primary rounded-xl font-bold hover:bg-primary-container transition-colors">إضافة</button>
              <button type="button" class="flex-1 py-3 border border-outline-variant rounded-xl font-bold text-on-surface hover:bg-surface-container transition-colors" @click="showAddAdminModal = false">إلغاء</button>
            </div>
          </form>
        </div>
      </div>
    </Teleport>

    <!-- Delete Confirm Modal -->
    <Teleport to="body">
      <div v-if="deleteTarget" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl border border-outline-variant shadow-xl w-full max-w-sm p-6 text-center" dir="rtl">
          <div class="w-16 h-16 bg-error-container rounded-full flex items-center justify-center mx-auto mb-4">
            <span class="material-symbols-outlined text-error text-3xl">delete_forever</span>
          </div>
          <h2 class="font-headline-md text-headline-md text-on-surface mb-2">تأكيد الحذف</h2>
          <p class="text-body-md text-on-surface-variant mb-6">هل أنت متأكد من حذف حساب <strong>{{ deleteTarget.name }}</strong>؟ لا يمكن التراجع عن هذا الإجراء.</p>
          <div class="flex gap-3">
            <button class="flex-1 py-3 bg-error text-on-error rounded-xl font-bold hover:opacity-90 transition-opacity" @click="deleteUser">حذف</button>
            <button class="flex-1 py-3 border border-outline-variant rounded-xl font-bold text-on-surface hover:bg-surface-container transition-colors" @click="deleteTarget = null">إلغاء</button>
          </div>
        </div>
      </div>
    </Teleport>
  </AdminLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import AdminLayout from '@/layouts/AdminLayout.vue'

const activeTab = ref('all')
const search = ref('')
const showAddAdminModal = ref(false)
const deleteTarget = ref(null)
const newAdmin = ref({ name: '', phone: '', role: 'Admin' })

const tabs = [
  { key: 'all',      label: 'الكل',              count: 1248 },
  { key: 'admins',   label: 'المدراء',            count: 5 },
  { key: 'partners', label: 'الشركاء',            count: 234 },
  { key: 'users',    label: 'المستخدمون العاديون', count: 1009 },
]

const users = ref([
  { id: 1, name: 'أحمد محمد العمري',  phone: '+966501234567', email: 'ahmed@example.com',  role: 'SuperAdmin', roleLabel: 'مدير عام',  active: true },
  { id: 2, name: 'سارة خالد الأحمد', phone: '+966509876543', email: 'sara@example.com',   role: 'Admin',      roleLabel: 'مدير',      active: true },
  { id: 3, name: 'محمد الفهد',        phone: '+966551234567', email: null,                  role: 'Individual', roleLabel: 'فرد',       active: true },
  { id: 4, name: 'نورة القحطاني',     phone: '+966561234567', email: null,                  role: 'Company',    roleLabel: 'شركة',      active: true },
  { id: 5, name: 'خالد الشمري',       phone: '+966571234567', email: null,                  role: 'User',       roleLabel: 'مستخدم',    active: false },
  { id: 6, name: 'هند العتيبي',       phone: '+966581234567', email: 'hind@example.com',   role: 'User',       roleLabel: 'مستخدم',    active: true },
])

const filteredUsers = computed(() => {
  return users.value.filter(u => {
    const matchSearch = !search.value || u.name.includes(search.value) || u.phone.includes(search.value)
    const matchTab = activeTab.value === 'all'
      || (activeTab.value === 'admins'   && ['Admin','SuperAdmin'].includes(u.role))
      || (activeTab.value === 'partners' && ['Individual','Company'].includes(u.role))
      || (activeTab.value === 'users'    && u.role === 'User')
    return matchSearch && matchTab
  })
})

function initials(name) {
  return name.split(' ').slice(0, 2).map(w => w[0]).join('')
}

function roleClass(role) {
  return {
    SuperAdmin: 'bg-purple-100 text-purple-700',
    Admin:      'bg-blue-100 text-blue-700',
    Individual: 'bg-secondary-container text-on-secondary-container',
    Company:    'bg-secondary-container text-on-secondary-container',
    User:       'bg-surface-container text-on-surface-variant',
  }[role]
}

function confirmDelete(user) {
  deleteTarget.value = user
}

function deleteUser() {
  users.value = users.value.filter(u => u.id !== deleteTarget.value.id)
  deleteTarget.value = null
}

function addAdmin() {
  users.value.unshift({
    id: Date.now(),
    ...newAdmin.value,
    roleLabel: newAdmin.value.role === 'SuperAdmin' ? 'مدير عام' : 'مدير',
    email: null,
    active: true,
  })
  showAddAdminModal.value = false
  newAdmin.value = { name: '', phone: '', role: 'Admin' }
}
</script>
