<template>
  <footer class="border-t border-outline-variant bg-white" dir="rtl">
    <div class="max-w-6xl mx-auto px-4 py-12">
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-10 text-right">
        <!-- Quick links -->
        <div>
          <h3 class="font-title-sm text-title-sm text-primary mb-4">الروابط السريعة</h3>
          <ul class="space-y-2.5 text-body-sm text-on-surface-variant">
            <li><RouterLink :to="{ name: 'home' }" class="hover:text-primary transition-colors">الرئيسية</RouterLink></li>
            <li><RouterLink :to="{ name: 'home' }" class="hover:text-primary transition-colors">الأقسام</RouterLink></li>
            <li><RouterLink :to="{ name: 'account' }" class="hover:text-primary transition-colors">الحجوزات</RouterLink></li>
            <li><RouterLink :to="{ name: 'home' }" class="hover:text-primary transition-colors">البحث</RouterLink></li>
          </ul>
        </div>

        <!-- Support -->
        <div>
          <h3 class="font-title-sm text-title-sm text-primary mb-4">الدعم والمساعدة</h3>
          <ul class="space-y-2.5 text-body-sm text-on-surface-variant">
            <li><RouterLink :to="{ name: 'contact' }" class="hover:text-primary transition-colors">تواصل معنا</RouterLink></li>
            <li><a href="#" class="hover:text-primary transition-colors">الأسئلة الشائعة</a></li>
          </ul>
        </div>

        <!-- Contact + social -->
        <div>
          <h3 class="font-title-sm text-title-sm text-primary mb-4">التواصل والسوشيال</h3>
          <ul class="space-y-2.5 text-body-sm text-on-surface-variant mb-4">
            <li class="flex items-center gap-2 justify-end">
              <span dir="ltr">+966 50 000 0000</span>
              <span class="material-symbols-outlined text-[18px]">call</span>
            </li>
            <li class="flex items-center gap-2 justify-end">
              <span dir="ltr">info@bookedin.sa</span>
              <span class="material-symbols-outlined text-[18px]">mail</span>
            </li>
          </ul>
          <div class="flex items-center gap-3 justify-end text-on-surface-variant">
            <a v-for="s in socials" :key="s.label" :href="s.href" :title="s.label" class="grid w-9 h-9 place-items-center rounded-full bg-surface-container hover:bg-primary hover:text-on-primary transition-colors">
              <span class="material-symbols-outlined text-[18px]">{{ s.icon }}</span>
            </a>
          </div>
        </div>

        <!-- Brand blurb -->
        <div class="flex flex-col items-end text-right">
          <div class="flex items-center gap-2 mb-3">
            <span class="font-display-lg text-[20px] text-primary">ممسى</span>
            <div class="w-9 h-9 bg-primary rounded-xl grid place-items-center">
              <span class="material-symbols-outlined text-on-primary text-xl" style="font-variation-settings:'FILL' 1">apartment</span>
            </div>
          </div>
          <p class="text-body-sm text-on-surface-variant leading-relaxed max-w-[240px]">
            منصة رائدة في مجال حجز أماكن الإقامة الفريدة حول العالم
          </p>
        </div>
      </div>

      <!-- Bottom bar -->
      <div class="mt-10 pt-6 border-t border-outline-variant flex flex-col sm:flex-row items-center justify-between gap-3 text-body-sm text-on-surface-variant">
        <span class="px-3 py-1.5 rounded-lg bg-surface-container">مرخّص من وزارة السياحة ترخيص رقم (00000)</span>
        <span>© {{ year }} ممسى — جميع الحقوق محفوظة</span>
      </div>
    </div>
  </footer>
</template>

<script setup>
const year = new Date().getFullYear()

const socials = [
  { label: 'LinkedIn',  icon: 'work',       href: '#' },
  { label: 'Instagram', icon: 'photo_camera', href: '#' },
  { label: 'X',         icon: 'tag',        href: '#' },
  { label: 'Facebook',  icon: 'thumb_up',   href: '#' },
]
</script>
