<template>
  <div class="min-h-screen bg-[#F7F7F4]" dir="rtl">
    <PublicHeader />

    <div class="max-w-4xl mx-auto px-4 py-8">
      <div class="mb-6">
        <h1 class="font-display-lg text-display-lg text-primary mb-1">حسابي</h1>
        <p class="text-on-surface-variant text-body-md">إدارة بياناتك الشخصية</p>
      </div>

      <AccountNav />

      <div v-if="loading" class="flex items-center justify-center py-20 text-on-surface-variant">
        <span class="material-symbols-outlined animate-spin text-3xl">progress_activity</span>
      </div>

      <form v-else class="max-w-xl space-y-6" @submit.prevent="save">
        <section class="bg-white rounded-2xl border border-outline-variant p-6">
          <div class="flex items-center gap-4 mb-6">
            <div class="w-16 h-16 rounded-full bg-secondary-container flex items-center justify-center text-primary text-2xl font-bold">
              {{ initials }}
            </div>
            <div>
              <p class="font-title-sm text-title-sm text-on-surface">{{ form.name || 'مستخدم' }}</p>
              <span class="inline-block mt-1 px-3 py-0.5 rounded-full bg-surface-container text-on-surface-variant text-[12px] font-bold">مستأجر</span>
            </div>
          </div>

          <div class="space-y-4">
            <div>
              <label class="block text-body-sm font-bold text-on-surface mb-1.5">الاسم الكامل</label>
              <input v-model="form.name" class="field" placeholder="محمد عبدالله" required />
            </div>
            <div>
              <div class="flex items-center justify-between mb-1.5">
                <label class="block text-body-sm font-bold text-on-surface">البريد الإلكتروني</label>
                <span
                  v-if="form.email"
                  class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold"
                  :class="emailVerified ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'"
                >
                  <span class="material-symbols-outlined text-[13px]">{{ emailVerified ? 'verified' : 'error' }}</span>
                  {{ emailVerified ? 'موثّق' : 'غير موثّق' }}
                </span>
              </div>
              <input v-model="form.email" type="email" class="field" dir="ltr" placeholder="example@email.com" />
              <button
                v-if="!emailVerified"
                type="button"
                class="mt-2 text-primary text-body-sm font-bold hover:underline flex items-center gap-1"
                @click="emailModalOpen = true"
              >
                <span class="material-symbols-outlined text-[16px]">mark_email_read</span>
                توثيق البريد الإلكتروني
              </button>
              <p v-else class="text-[11px] text-on-surface-variant mt-1">تغيير البريد يتطلب إعادة التوثيق</p>
            </div>
            <div>
              <label class="block text-body-sm font-bold text-on-surface mb-1.5">رقم الجوال</label>
              <input :value="auth.user?.phone" class="field bg-surface-container cursor-not-allowed" dir="ltr" disabled />
                <!-- This used to read "your number is tied to your account and
                     cannot be changed". POST /user/change-phone has existed all
                     along — the copy was simply wrong. -->
                <button
                  type="button"
                  class="mt-2 text-primary text-body-sm font-bold hover:underline flex items-center gap-1"
                  @click="openPhoneChange"
                >
                  <span class="material-symbols-outlined text-[16px]">edit</span>
                  تغيير رقم الجوال
                </button>
            </div>
          </div>
        </section>


          <!-- DELETE /user/account — irreversible: revokes every token, clears
               the profile and frees the phone number for re-registration. -->
          <section class="pt-4 border-t border-outline-variant">
            <button type="button" class="text-error text-body-sm font-bold hover:underline flex items-center gap-1.5" @click="deleteOpen = true">
              <span class="material-symbols-outlined text-[18px]">delete_forever</span>
              حذف الحساب نهائياً
            </button>
          </section>

        <div class="flex items-center justify-between">
          <button type="button" class="text-error text-body-sm font-bold hover:underline flex items-center gap-1.5" @click="logout">
            <span class="material-symbols-outlined text-[18px]">logout</span>
            تسجيل الخروج
          </button>
          <button type="submit" class="px-6 py-3 bg-primary text-on-primary rounded-xl font-bold hover:bg-primary-container transition-colors flex items-center gap-2 disabled:opacity-50" :disabled="saving">
            <span v-if="saving" class="material-symbols-outlined animate-spin text-[18px]">progress_activity</span>
            <span v-else class="material-symbols-outlined text-[18px]">save</span>
            حفظ التغييرات
          </button>
        </div>
      </form>
    </div>

    <Transition name="fade">
      <div v-if="toast" class="fixed bottom-6 left-1/2 -translate-x-1/2 z-50 px-5 py-3 rounded-xl shadow-lg text-white font-bold text-body-sm" :class="toast.type === 'error' ? 'bg-error' : 'bg-primary'">
        {{ toast.msg }}
      </div>
    </Transition>

    <!-- Change phone: OTP goes to the NEW number, and that number must be sent
         again on verify — the server does not hold it between the two calls. -->
    <Transition name="fade">
      <div v-if="phoneOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4" @click.self="phoneOpen = false">
        <div class="bg-white rounded-2xl w-full max-w-sm p-6" dir="rtl">
          <div class="flex items-center justify-between mb-4">
            <h3 class="font-title-sm text-title-sm text-on-surface">تغيير رقم الجوال</h3>
            <button class="text-on-surface-variant hover:text-on-surface" @click="phoneOpen = false">
              <span class="material-symbols-outlined">close</span>
            </button>
          </div>

          <template v-if="phoneStep === 'request'">
            <label class="block text-body-sm font-bold text-on-surface mb-1.5">الرقم الجديد</label>
            <input v-model="newPhone" class="field" dir="ltr" placeholder="05XXXXXXXX" inputmode="numeric" />
            <p class="text-[11px] text-on-surface-variant mt-1">سيصلك رمز تحقق على الرقم الجديد</p>
            <button class="mt-4 w-full h-11 bg-primary text-on-primary rounded-xl font-bold disabled:opacity-50" :disabled="phoneBusy" @click="requestPhoneOtp">
              {{ phoneBusy ? 'جارٍ الإرسال…' : 'إرسال الرمز' }}
            </button>
          </template>

          <template v-else>
            <label class="block text-body-sm font-bold text-on-surface mb-1.5">رمز التحقق</label>
            <input v-model="phoneCode" class="field text-center tracking-[0.4em]" dir="ltr" inputmode="numeric" maxlength="8" />
            <p class="text-[11px] text-on-surface-variant mt-1">أُرسل إلى {{ newPhone }}</p>
            <button class="mt-4 w-full h-11 bg-primary text-on-primary rounded-xl font-bold disabled:opacity-50" :disabled="phoneBusy || !phoneCode" @click="confirmPhoneChange">
              {{ phoneBusy ? 'جارٍ التحقق…' : 'تأكيد التغيير' }}
            </button>
            <button class="mt-2 w-full text-body-sm text-on-surface-variant hover:underline" @click="phoneStep = 'request'">تغيير الرقم</button>
          </template>
        </div>
      </div>
    </Transition>

    <Transition name="fade">
      <div v-if="deleteOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4" @click.self="deleteOpen = false">
        <div class="bg-white rounded-2xl w-full max-w-sm p-6" dir="rtl">
          <h3 class="font-title-sm text-title-sm text-on-surface mb-2">حذف الحساب نهائياً</h3>
          <p class="text-body-sm text-on-surface-variant mb-4">
            سيتم إلغاء جميع جلساتك ومسح بياناتك الشخصية. لا يمكن التراجع عن هذا الإجراء.
          </p>
          <div class="flex gap-2">
            <button class="flex-1 h-11 border border-outline-variant rounded-xl font-bold" @click="deleteOpen = false">إلغاء</button>
            <button class="flex-1 h-11 bg-error text-white rounded-xl font-bold disabled:opacity-50" :disabled="deleteBusy" @click="confirmDelete">
              {{ deleteBusy ? 'جارٍ الحذف…' : 'حذف نهائياً' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>


    <EmailVerifyModal :open="emailModalOpen" @close="emailModalOpen = false" @verified="onEmailVerified" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import PublicHeader from '@/components/public/PublicHeader.vue'
import AccountNav from '@/components/user/AccountNav.vue'
import EmailVerifyModal from '@/components/user/EmailVerifyModal.vue'
import { userApi } from '@/api/user'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const loading = ref(true)
const saving = ref(false)
const toast = ref(null)

const form = ref({ name: '', email: '' })
const emailVerified = ref(false)
const emailModalOpen = ref(false)

const phoneOpen = ref(false)
const phoneStep = ref('request')
const phoneBusy = ref(false)
const newPhone = ref('')
const phoneCode = ref('')
const deleteOpen = ref(false)
const deleteBusy = ref(false)

function openPhoneChange() {
  phoneStep.value = 'request'
  newPhone.value = ''
  phoneCode.value = ''
  phoneOpen.value = true
}

async function requestPhoneOtp() {
  phoneBusy.value = true
  try {
    await userApi.changePhone(newPhone.value)
    phoneStep.value = 'verify'
    showToast('تم إرسال الرمز إلى الرقم الجديد')
  } catch (e) {
    showToast(e.response?.data?.message || 'تعذّر إرسال الرمز', 'error')
  } finally {
    phoneBusy.value = false
  }
}

async function confirmPhoneChange() {
  phoneBusy.value = true
  try {
    // The new number goes with the code — verify does not read it from session.
    await userApi.verifyChangePhone(newPhone.value, phoneCode.value)
    phoneOpen.value = false
    showToast('تم تغيير رقم الجوال')
    await auth.fetchMe()
  } catch (e) {
    showToast(e.response?.data?.message || 'رمز غير صحيح', 'error')
  } finally {
    phoneBusy.value = false
  }
}

async function confirmDelete() {
  deleteBusy.value = true
  try {
    await userApi.deleteAccount()
    // Every token is already revoked server-side; clearing locally keeps the
    // client from retrying with a credential that no longer exists.
    await auth.logout()
    router.push({ name: 'home' })
  } catch (e) {
    showToast(e.response?.data?.message || 'تعذّر حذف الحساب', 'error')
    deleteBusy.value = false
  }
}


function onEmailVerified() {
  emailVerified.value = true
  form.value.email = auth.user?.email || form.value.email
  showToast('تم توثيق البريد الإلكتروني')
}

const initials = computed(() => {
  const n = form.value.name || ''
  return n.split(' ').slice(0, 2).map((w) => w[0]).join('') || 'م'
})

function showToast(msg, type = 'success') {
  toast.value = { msg, type }
  setTimeout(() => (toast.value = null), 2800)
}

onMounted(async () => {
  try {
    const { data } = await userApi.getProfile()
    const u = data.data ?? data
    form.value = { name: u.name || '', email: u.email || '' }
    emailVerified.value = !!(u.email_verified_at ?? u.email_verified)
  } catch (e) {
    // fall back to store data
    form.value = { name: auth.user?.name || '', email: auth.user?.email || '' }
    emailVerified.value = !!auth.user?.email_verified
  } finally {
    loading.value = false
  }
})

async function save() {
  saving.value = true
  try {
    const emailChanged = (form.value.email || '') !== (auth.user?.email || '')
    const { data } = await userApi.updateProfile({ name: form.value.name.trim(), email: form.value.email || undefined })
    const u = data.data ?? data
    auth.setUser({ ...auth.user, name: u.name, email: u.email })
    // Server rule: a changed email always drops back to unverified.
    if (emailChanged && form.value.email) {
      emailVerified.value = false
      showToast('تم الحفظ — وثّق بريدك الجديد')
      emailModalOpen.value = true
    } else {
      showToast('تم حفظ التغييرات')
    }
  } catch (e) {
    showToast(e.response?.data?.message || 'تعذّر الحفظ', 'error')
  } finally {
    saving.value = false
  }
}

async function logout() {
  await auth.logout()
  router.push({ name: 'home' })
}
</script>

<style scoped>
.field {
  @apply w-full px-4 py-2.5 bg-surface-container-low border border-outline-variant rounded-xl text-body-md
         focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none transition-all;
}
.fade-enter-active, .fade-leave-active { transition: opacity 0.25s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
