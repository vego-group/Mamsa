<template>
  <div class="min-h-screen bg-[#F7F7F4]" dir="rtl">
    <PublicHeader />

    <!-- Hero -->
    <section class="relative">
      <div class="absolute inset-0 bg-primary"></div>
      <div class="relative max-w-6xl mx-auto px-4 py-16 sm:py-20 text-center text-on-primary">
        <h1 class="font-display-lg text-[28px] sm:text-[36px] leading-[1.3] font-bold">تواصل معنا</h1>
        <p class="text-on-primary/85 text-body-md max-w-xl mx-auto mt-3">
          عندك سؤال أو اقتراح؟ فريقنا جاهز لمساعدتك — أرسل رسالتك وسنرد عليك في أقرب وقت
        </p>
      </div>
    </section>

    <div class="max-w-6xl mx-auto px-4 py-12 grid grid-cols-1 lg:grid-cols-3 gap-6">
      <!-- Contact channels -->
      <div class="space-y-4">
        <div v-for="c in channels" :key="c.label" class="bg-white rounded-2xl border border-outline-variant p-5 flex items-center gap-4">
          <span class="grid w-11 h-11 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary">
            <span class="material-symbols-outlined text-[22px]">{{ c.icon }}</span>
          </span>
          <div class="text-right min-w-0">
            <p class="font-title-sm text-title-sm text-on-surface">{{ c.label }}</p>
            <p class="text-body-sm text-on-surface-variant truncate" dir="ltr">{{ c.value }}</p>
          </div>
        </div>
      </div>

      <!-- Form -->
      <div class="lg:col-span-2 bg-white rounded-2xl border border-outline-variant p-6 sm:p-8">
        <!-- Success state replaces the form so a double submit isn't possible -->
        <div v-if="sent" class="text-center py-12">
          <span class="grid w-16 h-16 mx-auto place-items-center rounded-full bg-primary/10 text-primary mb-4">
            <span class="material-symbols-outlined text-[32px]">mark_email_read</span>
          </span>
          <h2 class="font-headline-md text-headline-md text-primary">تم استلام رسالتك</h2>
          <p class="text-body-md text-on-surface-variant mt-2">{{ sentMessage }}</p>
          <button type="button" class="mt-6 px-6 py-3 rounded-xl border border-outline-variant text-body-sm font-bold text-on-surface hover:bg-surface-container transition-colors" @click="resetForm">
            إرسال رسالة أخرى
          </button>
        </div>

        <form v-else novalidate @submit.prevent="submit">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div>
              <label for="contact-name" class="block text-body-sm font-bold text-on-surface mb-1.5">الاسم الكامل</label>
              <input id="contact-name" v-model.trim="form.name" class="field" :class="{ 'field-error': errors.name }" placeholder="محمد عبدالله" autocomplete="name" />
              <p v-if="errors.name" class="text-error text-[12px] mt-1">{{ errors.name }}</p>
            </div>
            <div>
              <label for="contact-phone" class="block text-body-sm font-bold text-on-surface mb-1.5">رقم الجوال</label>
              <input id="contact-phone" v-model.trim="form.phone" class="field" :class="{ 'field-error': errors.phone }" dir="ltr" inputmode="numeric" maxlength="10" placeholder="05XXXXXXXX" autocomplete="tel-national" />
              <p v-if="errors.phone" class="text-error text-[12px] mt-1">{{ errors.phone }}</p>
            </div>
            <div class="sm:col-span-2">
              <label for="contact-email" class="block text-body-sm font-bold text-on-surface mb-1.5">البريد الإلكتروني</label>
              <input id="contact-email" v-model.trim="form.email" type="email" class="field" :class="{ 'field-error': errors.email }" dir="ltr" placeholder="example@email.com" autocomplete="email" />
              <p v-if="errors.email" class="text-error text-[12px] mt-1">{{ errors.email }}</p>
            </div>
            <div class="sm:col-span-2">
              <label for="contact-message" class="block text-body-sm font-bold text-on-surface mb-1.5">رسالتك</label>
              <textarea id="contact-message" v-model.trim="form.message" rows="5" class="field resize-y" :class="{ 'field-error': errors.message }" maxlength="2000" placeholder="اكتب رسالتك هنا (10 أحرف على الأقل)…"></textarea>
              <div class="flex items-center justify-between mt-1">
                <p v-if="errors.message" class="text-error text-[12px]">{{ errors.message }}</p>
                <p class="text-[11px] text-on-surface-variant mr-auto font-numeric-data" dir="ltr">{{ form.message.length }}/2000</p>
              </div>
            </div>
          </div>

          <button type="submit" class="mt-6 w-full sm:w-auto px-8 py-3 bg-primary text-on-primary rounded-xl font-bold hover:bg-primary-container transition-colors flex items-center justify-center gap-2 disabled:opacity-50" :disabled="sending">
            <span v-if="sending" class="material-symbols-outlined animate-spin text-[18px]">progress_activity</span>
            <span v-else class="material-symbols-outlined text-[18px]">send</span>
            إرسال الرسالة
          </button>
        </form>
      </div>
    </div>

    <PublicFooter />

    <Transition name="fade">
      <div v-if="toast" class="fixed bottom-6 left-1/2 -translate-x-1/2 z-50 px-5 py-3 rounded-xl shadow-lg text-white font-bold text-body-sm" :class="toast.type === 'error' ? 'bg-error' : 'bg-primary'">
        {{ toast.msg }}
      </div>
    </Transition>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import PublicHeader from '@/components/public/PublicHeader.vue'
import PublicFooter from '@/components/public/PublicFooter.vue'
import { publicApi } from '@/api/public'

const channels = [
  { label: 'اتصل بنا', value: '+966 50 000 0000', icon: 'call' },
  { label: 'البريد الإلكتروني', value: 'info@bookedin.sa', icon: 'mail' },
  { label: 'ساعات العمل', value: 'السبت – الخميس · 9ص – 6م', icon: 'schedule' },
]

const form = ref({ name: '', phone: '', email: '', message: '' })
const errors = ref({})
const sending = ref(false)
const sent = ref(false)
const sentMessage = ref('')
const toast = ref(null)

function showToast(msg, type = 'success') {
  toast.value = { msg, type }
  setTimeout(() => (toast.value = null), 2800)
}

// Mirrors the backend rules so most mistakes are caught before the request.
function validate() {
  const e = {}
  if (form.value.name.length < 2) e.name = 'الاسم مطلوب (حرفان على الأقل)'
  if (!/^05\d{8}$/.test(form.value.phone)) e.phone = 'رقم الجوال غير صحيح (يجب أن يبدأ بـ 05 ويتكون من 10 أرقام)'
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.value.email)) e.email = 'البريد الإلكتروني غير صحيح'
  if (form.value.message.length < 10) e.message = 'الرسالة قصيرة جداً (10 أحرف على الأقل)'
  errors.value = e
  return Object.keys(e).length === 0
}

async function submit() {
  if (!validate() || sending.value) return
  sending.value = true
  try {
    const { data } = await publicApi.contact({ ...form.value })
    sentMessage.value = data?.message || 'سنتواصل معك قريباً'
    sent.value = true
  } catch (err) {
    if (err.response?.status === 422 && err.response.data?.errors) {
      // Laravel validation errors → first message per field, under its input.
      errors.value = Object.fromEntries(
        Object.entries(err.response.data.errors).map(([k, v]) => [k, Array.isArray(v) ? v[0] : v]),
      )
    } else if (err.response?.status === 429) {
      showToast('محاولات كثيرة — انتظر دقيقة ثم أعد المحاولة', 'error')
    } else {
      showToast('تعذّر إرسال الرسالة، حاول مرة أخرى', 'error')
    }
  } finally {
    sending.value = false
  }
}

function resetForm() {
  form.value = { name: '', phone: '', email: '', message: '' }
  errors.value = {}
  sent.value = false
}
</script>

<style scoped>
.field {
  @apply w-full px-4 py-2.5 bg-surface-container-low border border-outline-variant rounded-xl text-body-md
         focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none transition-all;
}
.field-error {
  @apply border-error focus:border-error focus:ring-error/20;
}
.fade-enter-active, .fade-leave-active { transition: opacity 0.25s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
