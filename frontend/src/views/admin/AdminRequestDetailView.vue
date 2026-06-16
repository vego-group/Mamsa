<template>
  <AdminLayout>
    <!-- Breadcrumb -->
    <nav class="flex items-center gap-2 text-body-sm text-on-surface-variant mb-6">
      <RouterLink :to="{ name: 'admin-requests' }" class="hover:text-primary transition-colors">الطلبات</RouterLink>
      <span class="material-symbols-outlined text-[16px]">chevron_left</span>
      <span class="text-primary font-bold">{{ request.code }}</span>
    </nav>

    <!-- Header -->
    <div class="mb-8 flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
      <div>
        <div class="flex items-center gap-3 mb-2">
          <h1 class="font-display-lg text-display-lg text-primary">{{ request.unitName }}</h1>
          <span class="px-3 py-1 rounded-full text-sm font-bold" :class="statusClass(request.status)">{{ request.statusLabel }}</span>
        </div>
        <p class="text-on-surface-variant text-body-md">مقدم الطلب: <strong>{{ request.ownerName }}</strong> • {{ request.ownerType === 'Company' ? 'شركة' : 'فرد' }}</p>
      </div>
      <div v-if="request.status === 'pending'" class="flex gap-3">
        <button class="px-6 py-3 bg-emerald-600 text-white rounded-xl font-bold hover:bg-emerald-700 transition-colors flex items-center gap-2" @click="approve">
          <span class="material-symbols-outlined text-[18px]">check_circle</span>
          موافقة
        </button>
        <button class="px-6 py-3 border-2 border-error text-error rounded-xl font-bold hover:bg-error-container transition-colors flex items-center gap-2" @click="showRejectModal = true">
          <span class="material-symbols-outlined text-[18px]">cancel</span>
          رفض
        </button>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <!-- Main info -->
      <div class="lg:col-span-2 space-y-6">
        <!-- Unit details card -->
        <div class="bg-white rounded-2xl border border-outline-variant shadow-sm p-6">
          <h2 class="font-title-sm text-title-sm text-primary mb-4 pb-3 border-b border-outline-variant">تفاصيل الوحدة</h2>
          <div class="grid grid-cols-2 gap-4">
            <div v-for="detail in unitDetails" :key="detail.label">
              <p class="font-label-caps text-label-caps text-on-surface-variant mb-1">{{ detail.label }}</p>
              <p class="font-body-md text-on-surface">{{ detail.value }}</p>
            </div>
          </div>
        </div>

        <!-- Images placeholder -->
        <div class="bg-white rounded-2xl border border-outline-variant shadow-sm p-6">
          <h2 class="font-title-sm text-title-sm text-primary mb-4 pb-3 border-b border-outline-variant">صور الوحدة</h2>
          <div class="grid grid-cols-3 gap-3">
            <div v-for="i in 6" :key="i" class="aspect-square rounded-xl bg-surface-container flex items-center justify-center">
              <span class="material-symbols-outlined text-3xl text-on-surface-variant">image</span>
            </div>
          </div>
        </div>

        <!-- Description -->
        <div class="bg-white rounded-2xl border border-outline-variant shadow-sm p-6">
          <h2 class="font-title-sm text-title-sm text-primary mb-4 pb-3 border-b border-outline-variant">وصف الوحدة</h2>
          <p class="text-body-md text-on-surface leading-relaxed">{{ request.description }}</p>
        </div>

        <!-- Features -->
        <div class="bg-white rounded-2xl border border-outline-variant shadow-sm p-6">
          <h2 class="font-title-sm text-title-sm text-primary mb-4 pb-3 border-b border-outline-variant">المميزات</h2>
          <div class="flex flex-wrap gap-2">
            <span v-for="feat in request.features" :key="feat" class="px-3 py-1.5 bg-surface-container rounded-lg text-body-sm text-on-surface font-medium flex items-center gap-1.5">
              <span class="material-symbols-outlined text-[14px] text-primary">check_circle</span>
              {{ feat }}
            </span>
          </div>
        </div>
      </div>

      <!-- Sidebar info -->
      <div class="space-y-6">
        <!-- Owner card -->
        <div class="bg-white rounded-2xl border border-outline-variant shadow-sm p-6">
          <h2 class="font-title-sm text-title-sm text-primary mb-4 pb-3 border-b border-outline-variant">
            {{ request.ownerType === 'Company' ? 'بيانات الشركة' : 'بيانات المالك' }}
          </h2>
          <div class="flex items-center gap-3 mb-4">
            <div class="w-12 h-12 rounded-xl bg-secondary-container flex items-center justify-center text-primary font-bold">
              {{ request.ownerType === 'Company' ? '🏢' : initials(request.ownerName) }}
            </div>
            <div>
              <p class="font-title-sm text-title-sm text-on-surface">{{ request.ownerName }}</p>
              <p class="text-body-sm text-on-surface-variant">{{ request.ownerType === 'Company' ? 'شركة' : 'فرد' }}</p>
            </div>
          </div>
          <div class="space-y-3">
            <div v-for="info in ownerInfo" :key="info.label" class="flex items-center gap-3">
              <span class="material-symbols-outlined text-[18px] text-on-surface-variant flex-shrink-0">{{ info.icon }}</span>
              <div>
                <p class="font-label-caps text-[11px] text-on-surface-variant">{{ info.label }}</p>
                <p class="text-body-sm text-on-surface" :dir="info.ltr ? 'ltr' : 'rtl'">{{ info.value }}</p>
              </div>
            </div>
          </div>
        </div>

        <!-- Request metadata -->
        <div class="bg-white rounded-2xl border border-outline-variant shadow-sm p-6">
          <h2 class="font-title-sm text-title-sm text-primary mb-4 pb-3 border-b border-outline-variant">معلومات الطلب</h2>
          <div class="space-y-3">
            <div class="flex justify-between">
              <span class="text-body-sm text-on-surface-variant">كود الطلب</span>
              <span class="font-numeric-data text-body-sm font-bold text-primary">{{ request.code }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-body-sm text-on-surface-variant">تاريخ التقديم</span>
              <span class="font-numeric-data text-body-sm text-on-surface" dir="ltr">{{ request.submittedAt }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-body-sm text-on-surface-variant">نوع الطلب</span>
              <span class="px-2 py-0.5 rounded-full text-[11px] font-bold" :class="request.ownerType === 'Company' ? 'bg-blue-100 text-blue-700' : 'bg-secondary-container text-on-secondary-container'">
                {{ request.ownerType === 'Company' ? 'شركة' : 'فرد' }}
              </span>
            </div>
            <div class="flex justify-between">
              <span class="text-body-sm text-on-surface-variant">السعر / ليلة</span>
              <span class="font-numeric-data text-body-sm font-bold text-on-surface">{{ request.price }} ر.س</span>
            </div>
          </div>
        </div>

        <!-- Admin notes -->
        <div class="bg-white rounded-2xl border border-outline-variant shadow-sm p-6">
          <h2 class="font-title-sm text-title-sm text-primary mb-4">ملاحظات المراجعة</h2>
          <textarea
            v-model="adminNote"
            class="w-full px-4 py-3 bg-surface-container-low border border-outline-variant rounded-xl text-body-sm resize-none focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none transition-all"
            rows="4"
            placeholder="اكتب ملاحظاتك هنا..."
          />
        </div>
      </div>
    </div>

    <!-- Reject Modal -->
    <Teleport to="body">
      <div v-if="showRejectModal" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl border border-outline-variant shadow-xl w-full max-w-md p-6" dir="rtl">
          <h2 class="font-headline-md text-headline-md text-on-surface mb-2">سبب الرفض</h2>
          <p class="text-body-sm text-on-surface-variant mb-4">سيتم إرسال هذا السبب للشريك عبر الإشعارات.</p>
          <textarea
            v-model="rejectReason"
            class="w-full px-4 py-3 bg-surface-container-low border border-outline-variant rounded-xl text-body-sm resize-none focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none mb-4"
            rows="4"
            placeholder="اذكر سبب الرفض..."
          />
          <div class="flex gap-3">
            <button class="flex-1 py-3 bg-error text-on-error rounded-xl font-bold hover:opacity-90 transition-opacity" @click="reject">رفض الطلب</button>
            <button class="flex-1 py-3 border border-outline-variant rounded-xl font-bold text-on-surface hover:bg-surface-container transition-colors" @click="showRejectModal = false">إلغاء</button>
          </div>
        </div>
      </div>
    </Teleport>
  </AdminLayout>
</template>

<script setup>
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AdminLayout from '@/layouts/AdminLayout.vue'

const route = useRoute()
const router = useRouter()

const showRejectModal = ref(false)
const adminNote = ref('')
const rejectReason = ref('')

// Mock data — in production this would be fetched via API using route.params.id
const request = ref({
  id: Number(route.params.id),
  code: 'C7HKHYA4',
  unitName: 'فيلا الياسمين - النموذج أ',
  ownerName: 'محمد الفهد',
  ownerType: 'Individual',
  status: 'pending',
  statusLabel: 'قيد المراجعة',
  price: '4,500.00',
  submittedAt: '2026-06-15',
  description: 'فيلا فاخرة تقع في حي الملقا بالرياض، تتميز بتصميم عصري وإطلالة رائعة على الحديقة الداخلية. مجهزة بالكامل بأثاث فاخر وتضم 4 غرف نوم وصالة كبيرة ومسبح خاص.',
  features: ['مسبح خاص', 'واي فاي', 'موقف سيارات', 'مطبخ كامل', 'غرفة تجميز', 'شاشة ذكية', 'مكيف مركزي', 'باحة خارجية'],
})

const unitDetails = [
  { label: 'نوع الوحدة',    value: 'فيلا' },
  { label: 'المدينة',       value: 'الرياض' },
  { label: 'الحي',          value: 'حي الملقا' },
  { label: 'عدد الغرف',     value: '4 غرف نوم' },
  { label: 'السعة',         value: '10 أشخاص' },
  { label: 'الحد الأدنى',   value: 'ليلتان' },
]

const ownerInfo = [
  { label: 'رقم الجوال',    icon: 'phone',     value: '+966501234567', ltr: true },
  { label: 'رقم الهوية',    icon: 'badge',     value: '1023456789',    ltr: true },
  { label: 'تاريخ التسجيل', icon: 'calendar_today', value: '2026-05-20', ltr: true },
]

function initials(name) {
  return name.split(' ').slice(0, 2).map(w => w[0]).join('')
}

function statusClass(status) {
  return {
    pending:  'bg-amber-100 text-amber-700',
    approved: 'bg-emerald-100 text-emerald-700',
    rejected: 'bg-red-100 text-red-700',
  }[status]
}

function approve() {
  request.value.status = 'approved'
  request.value.statusLabel = 'مقبول'
}

function reject() {
  request.value.status = 'rejected'
  request.value.statusLabel = 'مرفوض'
  showRejectModal.value = false
}
</script>
