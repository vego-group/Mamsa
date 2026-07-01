<template>
  <div class="min-h-screen bg-[#F7F7F4]" dir="rtl">
    <PublicHeader />

    <!-- Hero -->
    <section class="relative">
      <!-- Background -->
      <div class="absolute inset-0 bg-primary">
        <img
          src="https://images.unsplash.com/photo-1613490493576-7fde63acd811?auto=format&fit=crop&w=1600&q=80"
          alt=""
          class="w-full h-full object-cover opacity-40"
          loading="eager"
        />
        <div class="absolute inset-0 bg-gradient-to-t from-primary/95 via-primary/70 to-primary/50"></div>
      </div>

      <div class="relative max-w-6xl mx-auto px-4 pt-16 pb-8 sm:pt-20">
        <div class="text-center text-on-primary mb-8">
          <h1 class="font-display-lg text-[30px] sm:text-[44px] leading-[1.25] font-bold mb-4">
            منصة ممسى العقارية ابحث عن<br class="hidden sm:block" />
            عقارك المثالي لقضاء عطلتك
          </h1>
          <p class="text-on-primary/85 text-body-md max-w-2xl mx-auto">
            تصفّح آلاف العقارات الموثّقة في السعودية — احجز بثقة وأمان على منصة ممسى العقارية
          </p>
        </div>

        <!-- Search card -->
        <div class="bg-white rounded-2xl shadow-card p-3 sm:p-4 max-w-5xl mx-auto">
          <div class="grid grid-cols-1 md:grid-cols-12 gap-3">
            <!-- Location -->
            <div class="md:col-span-4">
              <label class="block text-[11px] font-bold text-on-surface-variant mb-1.5">المكان</label>
              <div class="relative">
                <span class="material-symbols-outlined absolute right-3 top-1/2 -translate-y-1/2 text-on-surface-variant text-[20px]">location_on</span>
                <input
                  v-model="filters.q"
                  type="text"
                  placeholder="ابحث عن وحدتك"
                  class="w-full h-12 pr-10 pl-3 rounded-xl bg-surface-container-low border border-transparent text-on-surface text-body-sm focus:border-primary focus:ring-2 focus:ring-primary/15 outline-none transition"
                  @keyup.enter="search"
                />
              </div>
            </div>

            <!-- Check-in -->
            <div class="md:col-span-3">
              <label class="block text-[11px] font-bold text-on-surface-variant mb-1.5">تاريخ الوصول</label>
              <input
                v-model="filters.checkin"
                type="date"
                class="w-full h-12 px-3 rounded-xl bg-surface-container-low border border-transparent text-on-surface text-body-sm focus:border-primary focus:ring-2 focus:ring-primary/15 outline-none transition"
                dir="ltr"
              />
            </div>

            <!-- Check-out -->
            <div class="md:col-span-3">
              <label class="block text-[11px] font-bold text-on-surface-variant mb-1.5">تاريخ المغادرة</label>
              <input
                v-model="filters.checkout"
                type="date"
                :min="filters.checkin || undefined"
                class="w-full h-12 px-3 rounded-xl bg-surface-container-low border border-transparent text-on-surface text-body-sm focus:border-primary focus:ring-2 focus:ring-primary/15 outline-none transition"
                dir="ltr"
              />
            </div>

            <!-- Guests -->
            <div class="md:col-span-2">
              <label class="block text-[11px] font-bold text-on-surface-variant mb-1.5">عدد الضيوف</label>
              <div class="relative">
                <span class="material-symbols-outlined absolute right-3 top-1/2 -translate-y-1/2 text-on-surface-variant text-[20px]">group</span>
                <select
                  v-model.number="filters.capacity"
                  class="w-full h-12 pr-10 pl-2 rounded-xl bg-surface-container-low border border-transparent text-on-surface text-body-sm focus:border-primary focus:ring-2 focus:ring-primary/15 outline-none transition appearance-none"
                >
                  <option v-for="n in 10" :key="n" :value="n">{{ n }} ضيف</option>
                </select>
              </div>
            </div>
          </div>

          <button
            class="mt-3 w-full md:w-auto md:min-w-[160px] h-12 px-8 bg-primary text-on-primary rounded-xl font-bold hover:bg-primary-container transition-colors flex items-center justify-center gap-2"
            @click="search"
          >
            <span class="material-symbols-outlined text-[20px]">search</span>
            إبدأ البحث
          </button>
        </div>

        <!-- Category chips -->
        <div class="flex items-center flex-wrap gap-2 max-w-5xl mx-auto mt-4 justify-center md:justify-start">
          <span class="text-on-primary/90 text-body-sm font-bold ml-1">فلتر:</span>
          <button
            v-for="cat in categories"
            :key="cat.value"
            class="px-4 py-2 rounded-full text-body-sm font-bold transition-colors border"
            :class="filters.category === cat.value
              ? 'bg-on-primary text-primary border-on-primary'
              : 'bg-white/10 text-on-primary border-white/30 hover:bg-white/20'"
            @click="toggleCategory(cat)"
          >
            {{ cat.label }}
          </button>
        </div>
      </div>
    </section>

    <!-- Discover destinations -->
    <section class="max-w-6xl mx-auto px-4 pt-12">
      <div class="flex items-start justify-between gap-4 mb-6">
        <div class="text-right">
          <h2 class="font-headline-md text-headline-md text-primary">اكتشف وجهتك</h2>
          <p class="text-body-sm text-on-surface-variant mt-1">استكشف أفضل الوجهات والإقامات المميزة</p>
        </div>
        <button class="flex items-center gap-1.5 px-4 py-2 rounded-full border border-outline-variant text-body-sm font-bold text-on-surface hover:bg-surface-container transition-colors" @click="resetAndScroll">
          عرض الكل
          <span class="material-symbols-outlined text-[18px]">chevron_left</span>
        </button>
      </div>

      <div class="grid grid-cols-2 lg:grid-cols-3 gap-4">
        <button
          v-for="d in destinations"
          :key="d.key"
          class="relative h-44 rounded-2xl overflow-hidden group text-right"
          @click="selectDestination(d)"
        >
          <img :src="d.img" :alt="d.label" class="absolute inset-0 w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" loading="lazy" />
          <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-black/20 to-transparent"></div>
          <div class="absolute bottom-0 inset-x-0 p-4 flex items-end justify-between">
            <span class="grid w-9 h-9 place-items-center rounded-lg bg-white/90 text-primary shrink-0">
              <span class="material-symbols-outlined text-[20px]">{{ d.icon }}</span>
            </span>
            <div class="text-white">
              <p class="font-title-sm text-title-sm leading-tight">{{ d.label }}</p>
              <p class="text-[12px] text-white/80 font-numeric-data">{{ formatMoney(d.count) }} وحدة</p>
            </div>
          </div>
        </button>
      </div>
    </section>

    <!-- Seasonal offers -->
    <section v-if="offers.length" class="max-w-6xl mx-auto px-4 pt-12">
      <div class="flex items-start justify-between gap-4 mb-6">
        <div class="text-right">
          <h2 class="font-headline-md text-headline-md text-primary">العروض الموسمية</h2>
          <p class="text-body-sm text-on-surface-variant mt-1">استكشف أفضل العروض الموسمية</p>
        </div>
        <button class="flex items-center gap-1.5 px-4 py-2 rounded-full border border-outline-variant text-body-sm font-bold text-on-surface hover:bg-surface-container transition-colors" @click="resetAndScroll">
          عرض الكل
          <span class="material-symbols-outlined text-[18px]">chevron_left</span>
        </button>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
        <button
          v-for="o in offers"
          :key="o.id"
          class="relative h-44 rounded-2xl overflow-hidden group text-right"
          @click="resetAndScroll"
        >
          <img :src="o.image_url" :alt="o.title" class="absolute inset-0 w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" loading="lazy" />
          <div class="absolute inset-0 bg-gradient-to-l from-black/70 via-black/30 to-transparent"></div>
          <span class="absolute top-4 left-4 px-3 py-1 rounded-full bg-primary text-on-primary text-[12px] font-bold">
            خصم {{ o.discount_percent }}%
          </span>
          <div class="absolute bottom-0 inset-x-0 p-5 text-white">
            <p class="font-title-sm text-title-sm mb-0.5">{{ o.title }}</p>
            <p class="text-body-sm text-white/85">{{ o.subtitle }}</p>
            <p v-if="o.valid_until_label" class="text-[12px] text-white/70 mt-1">{{ o.valid_until_label }}</p>
          </div>
        </button>
      </div>
    </section>

    <!-- By budget -->
    <section v-if="budgets.length" class="max-w-6xl mx-auto px-4 pt-12">
      <div class="flex items-start justify-between gap-4 mb-6">
        <div class="text-right">
          <h2 class="font-headline-md text-headline-md text-primary">حسب الميزانية</h2>
          <p class="text-body-sm text-on-surface-variant mt-1">أسعار تنافس احتياجاتك</p>
        </div>
        <button class="flex items-center gap-1.5 px-4 py-2 rounded-full border border-outline-variant text-body-sm font-bold text-on-surface hover:bg-surface-container transition-colors" @click="resetAndScroll">
          عرض الكل
          <span class="material-symbols-outlined text-[18px]">chevron_left</span>
        </button>
      </div>

      <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <button
          v-for="b in budgets"
          :key="b.key"
          class="relative h-72 rounded-2xl overflow-hidden group text-right"
          @click="selectBudget(b)"
        >
          <img :src="budgetImage(b.key)" :alt="b.label" class="absolute inset-0 w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" loading="lazy" />
          <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/25 to-transparent"></div>
          <div class="absolute bottom-0 inset-x-0 p-5 text-white">
            <p class="font-title-sm text-title-sm mb-0.5">{{ b.label }}</p>
            <p class="text-[12px] text-white/80 font-numeric-data">{{ formatMoney(b.count) }} وحدة متاحة</p>
          </div>
        </button>
      </div>
    </section>

    <!-- Most requested -->
    <section v-if="popularLoading || popular.length" class="max-w-6xl mx-auto px-4 pt-12">
      <div class="flex items-start justify-between gap-4 mb-6">
        <div class="text-right">
          <h2 class="font-headline-md text-headline-md text-primary">الأكثر طلباً</h2>
          <p class="text-body-sm text-on-surface-variant mt-1">وحدات مختارة بعناية خصيصاً لك</p>
        </div>
        <button class="flex items-center gap-1.5 px-4 py-2 rounded-full border border-outline-variant text-body-sm font-bold text-on-surface hover:bg-surface-container transition-colors" @click="resetAndScroll">
          عرض الكل
          <span class="material-symbols-outlined text-[18px]">chevron_left</span>
        </button>
      </div>

      <!-- Loading -->
      <div v-if="popularLoading" class="flex gap-4 overflow-hidden">
        <div v-for="i in 4" :key="i" class="w-[260px] shrink-0 bg-white rounded-2xl border border-outline-variant overflow-hidden animate-pulse">
          <div class="h-40 bg-surface-container"></div>
          <div class="p-4 space-y-3">
            <div class="h-4 bg-surface-container rounded w-3/4"></div>
            <div class="h-3 bg-surface-container rounded w-1/2"></div>
          </div>
        </div>
      </div>

      <!-- Rail -->
      <div v-else class="flex gap-4 overflow-x-auto pb-2 snap-x snap-mandatory -mx-4 px-4">
        <UnitRailCard
          v-for="unit in popular"
          :key="unit.id"
          :unit="unit"
          :badge="badge(unit)"
          :favorited="favorites.has(unit.id)"
          @favorite="toggleFavorite(unit.id)"
        />
      </div>
    </section>

    <!-- Search by location -->
    <section v-if="cities.length" class="max-w-6xl mx-auto px-4 pt-12">
      <div class="flex items-start justify-between gap-4 mb-6">
        <div class="text-right">
          <h2 class="font-headline-md text-headline-md text-primary">البحث حسب الموقع</h2>
          <p class="text-body-sm text-on-surface-variant mt-1">اختر وجهتك واكتشف الوحدات المتاحة فيها</p>
        </div>
        <button class="flex items-center gap-1.5 px-4 py-2 rounded-full border border-outline-variant text-body-sm font-bold text-on-surface hover:bg-surface-container transition-colors" @click="resetAndScroll">
          عرض الكل
          <span class="material-symbols-outlined text-[18px]">chevron_left</span>
        </button>
      </div>

      <div class="relative rounded-2xl overflow-hidden border border-outline-variant min-h-[320px]">
        <img
          src="https://images.unsplash.com/photo-1524661135-423995f22d0b?auto=format&fit=crop&w=1600&q=70"
          alt="خريطة"
          class="absolute inset-0 w-full h-full object-cover"
          loading="lazy"
        />
        <div class="absolute inset-0 bg-gradient-to-l from-primary/90 via-primary/60 to-primary/30"></div>

        <div class="relative p-6 sm:p-8 flex flex-col justify-between min-h-[320px]">
          <div class="text-right text-on-primary max-w-sm mr-0 ml-auto">
            <p class="font-title-sm text-title-sm mb-1">وجهات سياحية مميزة</p>
            <p class="text-body-sm text-on-primary/80">تصفّح الوحدات حسب المدينة واحجز أقرب وجهة إليك</p>
          </div>

          <div class="flex flex-wrap gap-2.5 justify-end mt-8">
            <button
              v-for="c in cities"
              :key="c.city"
              class="flex items-center gap-2 px-4 py-2.5 rounded-xl bg-white/95 hover:bg-white text-on-surface text-body-sm font-bold transition-colors shadow-sm"
              @click="selectCity(c)"
            >
              <span class="material-symbols-outlined text-[18px] text-primary">location_on</span>
              {{ c.city }}
              <span class="grid place-items-center min-w-[22px] h-[22px] px-1 rounded-full bg-primary text-on-primary text-[11px] font-numeric-data">{{ c.count }}</span>
            </button>
          </div>
        </div>
      </div>
    </section>

    <!-- Listing -->
    <section id="listing" ref="listingSection" class="max-w-6xl mx-auto px-4 py-10">
      <div class="flex items-center justify-between mb-6">
        <h2 class="font-headline-md text-headline-md text-primary">الوحدات المتاحة</h2>
        <span v-if="!loading" class="text-body-sm text-on-surface-variant">{{ units.length }} وحدة</span>
      </div>

      <!-- Loading -->
      <div v-if="loading" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
        <div v-for="i in 6" :key="i" class="bg-white rounded-2xl border border-outline-variant overflow-hidden animate-pulse">
          <div class="h-48 bg-surface-container"></div>
          <div class="p-4 space-y-3">
            <div class="h-4 bg-surface-container rounded w-3/4"></div>
            <div class="h-3 bg-surface-container rounded w-1/2"></div>
          </div>
        </div>
      </div>

      <!-- Empty -->
      <div v-else-if="units.length === 0" class="text-center py-16 text-on-surface-variant">
        <span class="material-symbols-outlined text-5xl mb-3 block">search_off</span>
        <p class="font-title-sm text-title-sm">لا توجد وحدات مطابقة لبحثك</p>
      </div>

      <!-- Grid -->
      <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
        <RouterLink
          v-for="unit in units"
          :key="unit.id"
          :to="{ name: 'unit-detail', params: { id: unit.id } }"
          class="bg-white rounded-2xl border border-outline-variant overflow-hidden hover:shadow-card transition-all group"
        >
          <div class="relative h-48 bg-surface-container overflow-hidden">
            <img
              v-if="mainImage(unit)"
              :src="mainImage(unit)"
              :alt="unit.name"
              class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
              loading="lazy"
            />
            <div v-else class="w-full h-full flex items-center justify-center">
              <span class="material-symbols-outlined text-4xl text-on-surface-variant">apartment</span>
            </div>
            <span class="absolute top-3 right-3 px-3 py-1 rounded-full bg-white/90 text-primary text-[12px] font-bold">
              {{ typeLabel(unit.type) }}
            </span>
          </div>
          <div class="p-4">
            <h3 class="font-title-sm text-title-sm text-on-surface mb-1 truncate">{{ unit.name }}</h3>
            <div class="flex items-center gap-1.5 text-on-surface-variant mb-3">
              <span class="material-symbols-outlined text-[16px]">location_on</span>
              <span class="text-body-sm">{{ unit.city }}{{ unit.district ? ` - ${unit.district}` : '' }}</span>
            </div>
            <div class="flex items-center justify-between">
              <div>
                <span class="font-bold text-primary text-title-sm">{{ formatMoney(unit.price) }}</span>
                <span class="text-on-surface-variant text-body-sm"> ر.س / ليلة</span>
              </div>
              <div v-if="unit.avg_rating" class="flex items-center gap-1 text-amber-500">
                <span class="material-symbols-outlined text-[16px]" style="font-variation-settings:'FILL' 1">star</span>
                <span class="text-body-sm font-bold text-on-surface">{{ unit.avg_rating }}</span>
              </div>
            </div>
            <div class="flex items-center gap-4 mt-3 pt-3 border-t border-outline-variant/50 text-on-surface-variant text-body-sm">
              <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">group</span>{{ unit.capacity }}</span>
              <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">bed</span>{{ unit.bedrooms }}</span>
            </div>
          </div>
        </RouterLink>
      </div>
    </section>

    <!-- Picks for you (مختارات لك) — curated rail with category chips -->
    <section class="max-w-6xl mx-auto px-4 pt-12">
      <div class="flex items-start justify-between gap-4 mb-5">
        <div class="text-right">
          <h2 class="font-headline-md text-headline-md text-primary">مختارات لك</h2>
          <p class="text-body-sm text-on-surface-variant mt-1">وحدات مختارة بعناية حسب اهتماماتك</p>
        </div>
        <button class="flex items-center gap-1.5 px-4 py-2 rounded-full border border-outline-variant text-body-sm font-bold text-on-surface hover:bg-surface-container transition-colors" @click="resetAndScroll">
          عرض الكل
          <span class="material-symbols-outlined text-[18px]">chevron_left</span>
        </button>
      </div>

      <!-- Category chips -->
      <div class="flex items-center flex-wrap gap-2 mb-5">
        <button
          v-for="cat in categories"
          :key="cat.value"
          class="px-4 py-2 rounded-full text-body-sm font-bold border transition-colors"
          :class="picksCategory === cat.value
            ? 'bg-primary text-on-primary border-primary'
            : 'bg-white text-on-surface-variant border-outline-variant hover:border-primary hover:text-primary'"
          @click="setPicksCategory(cat.value)"
        >
          {{ cat.label }}
        </button>
      </div>

      <!-- Loading -->
      <div v-if="picksLoading" class="flex gap-4 overflow-hidden">
        <div v-for="i in 4" :key="i" class="w-[260px] shrink-0 bg-white rounded-2xl border border-outline-variant overflow-hidden animate-pulse">
          <div class="h-40 bg-surface-container"></div>
          <div class="p-4 space-y-3">
            <div class="h-4 bg-surface-container rounded w-3/4"></div>
            <div class="h-3 bg-surface-container rounded w-1/2"></div>
          </div>
        </div>
      </div>

      <!-- Rail -->
      <div v-else-if="picks.length" class="flex gap-4 overflow-x-auto pb-2 snap-x snap-mandatory -mx-4 px-4">
        <UnitRailCard
          v-for="unit in picks"
          :key="unit.id"
          :unit="unit"
          :favorited="favorites.has(unit.id)"
          @favorite="toggleFavorite(unit.id)"
        />
      </div>

      <!-- Empty -->
      <div v-else class="text-center py-10 text-on-surface-variant text-body-sm">
        لا توجد وحدات في هذه الفئة حالياً
      </div>
    </section>

    <!-- How it works (كيف نعمل) -->
    <section class="bg-[#EFEEE8] mt-12">
      <div class="max-w-6xl mx-auto px-4 py-14">
        <div class="text-center mb-10">
          <h2 class="font-headline-md text-headline-md text-primary">كيف نعمل</h2>
          <p class="text-body-sm text-on-surface-variant mt-2 max-w-xl mx-auto">ثلاث خطوات بسيطة تفصلك عن وحدتك المثالية</p>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
          <div
            v-for="step in steps"
            :key="step.n"
            class="bg-white rounded-2xl border border-outline-variant p-6 text-right"
          >
            <div class="flex items-center justify-between mb-4">
              <span class="grid w-11 h-11 place-items-center rounded-xl bg-primary/10 text-primary">
                <span class="material-symbols-outlined text-[22px]">{{ step.icon }}</span>
              </span>
              <span class="font-numeric-data text-[34px] font-bold text-outline-variant leading-none">{{ step.n }}</span>
            </div>
            <h3 class="font-title-sm text-title-sm text-primary mb-2">{{ step.title }}</h3>
            <p class="text-body-sm text-on-surface-variant leading-relaxed">{{ step.desc }}</p>
          </div>
        </div>
      </div>
    </section>

    <!-- Why Mamsa (لماذا ممسى) — trust + testimonial -->
    <section class="max-w-6xl mx-auto px-4 py-12">
      <div class="bg-[#13251A] rounded-[28px] p-7 sm:p-10 lg:p-14">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-10 lg:gap-14 items-center">
          <!-- Copy + features -->
          <div class="text-right">
            <p class="text-[11px] font-bold tracking-[0.2em] text-primary-fixed-dim mb-3">لماذا ممسى</p>
            <h2 class="text-on-primary text-[26px] sm:text-[30px] font-bold leading-tight mb-4">
              ثقة بنيناها عبر نتائج استثنائية
            </h2>
            <p class="text-on-primary/70 text-body-sm leading-relaxed mb-8 max-w-lg ms-auto">
              منذ عام ١٩٩٨ ونحن نعمل عند تقاطع السرية والخبرة — نخدم عملاء يرفضون أي تنازل عن أعلى معايير التمثيل العقاري.
            </p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-6">
              <div v-for="f in features" :key="f.title" class="flex items-start gap-3 text-right">
                <span class="grid w-10 h-10 shrink-0 place-items-center rounded-lg bg-primary-fixed-dim/15 text-primary-fixed-dim">
                  <span class="material-symbols-outlined text-[20px]">{{ f.icon }}</span>
                </span>
                <div>
                  <h4 class="text-on-primary font-bold text-body-md mb-1">{{ f.title }}</h4>
                  <p class="text-on-primary/60 text-[13px] leading-relaxed">{{ f.desc }}</p>
                </div>
              </div>
            </div>
          </div>

          <!-- Testimonial -->
          <div v-if="testimonials.length" class="bg-white/[0.04] border border-white/10 rounded-2xl p-6 sm:p-8">
            <span class="material-symbols-outlined text-primary-fixed-dim/40 text-[40px] leading-none" style="font-variation-settings:'FILL' 1">format_quote</span>
            <p class="text-on-primary/85 text-body-md leading-loose mt-2 mb-8 text-right">{{ activeT.quote }}</p>

            <div class="flex items-center gap-1 text-amber-400 mb-5">
              <span v-for="i in (activeT.rating || 5)" :key="i" class="material-symbols-outlined text-[18px]" style="font-variation-settings:'FILL' 1">star</span>
            </div>

            <div class="flex items-center gap-3">
              <img :src="activeT.avatar_url" class="w-12 h-12 rounded-full object-cover shrink-0" alt="" loading="lazy" />
              <div class="text-right">
                <p class="text-on-primary font-bold text-body-sm">{{ activeT.name }}</p>
                <p class="text-on-primary/55 text-[12px]">{{ activeT.role }}</p>
              </div>
            </div>

            <div class="mt-6 pt-5 border-t border-white/10 flex items-center justify-end gap-2 text-on-primary/55 text-[12px]">
              <span>{{ activeT.deal }}</span>
              <span class="material-symbols-outlined text-[16px] text-primary-fixed-dim" style="font-variation-settings:'FILL' 1">verified</span>
            </div>

            <!-- Dots -->
            <div class="flex items-center gap-2 mt-6">
              <button
                v-for="(t, i) in testimonials"
                :key="i"
                class="h-1.5 rounded-full transition-all"
                :class="activeTestimonial === i ? 'w-6 bg-primary-fixed-dim' : 'w-1.5 bg-white/20 hover:bg-white/40'"
                :aria-label="`رأي ${i + 1}`"
                @click="activeTestimonial = i"
              ></button>
            </div>
          </div>
        </div>
      </div>
    </section>

    <PublicFooter />
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import PublicHeader from '@/components/public/PublicHeader.vue'
import PublicFooter from '@/components/public/PublicFooter.vue'
import UnitRailCard from '@/components/public/UnitRailCard.vue'
import { publicApi } from '@/api/public'

const router = useRouter()

const loading = ref(true)
const units = ref([])
const listingSection = ref(null)

const popular = ref([])
const popularLoading = ref(true)
const favorites = ref(new Set())

// مختارات لك — curated rail filtered by category chip.
const picks = ref([])
const picksLoading = ref(true)
const picksCategory = ref('chalet')

// كيف نعمل — static three-step explainer.
const steps = [
  { n: '٠١', icon: 'search', title: 'اكتشف', desc: 'تصفّح مجموعتنا المختارة من العقارات الفاخرة الموثّقة عبر فلاتر ذكية وخريطة تفاعلية. كل إدراج خضع للتحقق الشخصي من فريقنا المتخصص.' },
  { n: '٠٢', icon: 'visibility', title: 'استكشف', desc: 'احجز معاينة ميدانية أو جولة افتراضية ثلاثية الأبعاد مع مستشارك المخصّص. استعرض تحليلات الحي المفصّلة وبيانات السوق.' },
  { n: '٠٣', icon: 'account_balance', title: 'اقتنِ', desc: 'يتولّى مستشارك كافة جوانب الصفقة بسرية تامة — المراجعة القانونية، والتفاوض، وإتمام العقود بإدارة متكاملة وراقية.' },
]

// لماذا ممسى — trust pillars + rotating testimonials.
const features = [
  { icon: 'emoji_events',  title: 'جائزة أفضل وكالة', desc: 'وكالة العقارات الفاخرة الأولى بالخليج لسبع سنوات متتالية.' },
  { icon: 'verified_user', title: '١٠٠٪ موثّق',       desc: 'كل إدراج يخضع لمراجعة قانونية وتفتيش ميداني قبل النشر.' },
  { icon: 'trending_up',   title: 'ذكاء السوق',        desc: 'تحليلات أسعار لحظية مدعومة بالذكاء الاصطناعي.' },
  { icon: 'diversity_3',   title: 'خدمة راقية',        desc: 'مستشار شخصي مخصّص يرافقك في كل خطوة من رحلتك.' },
]

// Testimonials come from the API (GET /testimonials), seeded server-side.
const testimonials = ref([])
const activeTestimonial = ref(0)
const activeT = computed(() => testimonials.value[activeTestimonial.value] || {})

// Destination categories (اكتشف وجهتك) — labels, icons and live counts come
// from GET /units/categories. Imagery is decorative, mapped by category key.
const destinations = ref([])
const offers = ref([])
const cities = ref([])
const budgets = ref([])

// Decorative imagery per budget bucket key.
const BUDGET_IMAGES = {
  '2000_3000': 'https://images.unsplash.com/photo-1613490493576-7fde63acd811?auto=format&fit=crop&w=700&q=70',
  '1000_2000': 'https://images.unsplash.com/photo-1512917774080-9991f1c4c750?auto=format&fit=crop&w=700&q=70',
  '500_1000':  'https://images.unsplash.com/photo-1600585154340-be6161a56a0c?auto=format&fit=crop&w=700&q=70',
  'under_500': 'https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?auto=format&fit=crop&w=700&q=70',
}
function budgetImage(key) {
  return BUDGET_IMAGES[key] || BUDGET_IMAGES.under_500
}

const CATEGORY_IMAGES = {
  villa:     'https://images.unsplash.com/photo-1613977257363-707ba9348227?auto=format&fit=crop&w=800&q=70',
  rest:      'https://images.unsplash.com/photo-1568605114967-8130f3a36994?auto=format&fit=crop&w=800&q=70',
  chalet:    'https://images.unsplash.com/photo-1502672260266-1c1ef2d93688?auto=format&fit=crop&w=800&q=70',
  resort:    'https://images.unsplash.com/photo-1571896349842-33c89424de2d?auto=format&fit=crop&w=800&q=70',
  apartment: 'https://images.unsplash.com/photo-1545324418-cc1a3fa10c00?auto=format&fit=crop&w=800&q=70',
  camp:      'https://images.unsplash.com/photo-1504280390367-361c6d9f38f4?auto=format&fit=crop&w=800&q=70',
}

// Hero quick-filter chips → backend category keys.
const categories = [
  { value: 'chalet',    label: 'شاليهات' },
  { value: 'apartment', label: 'شقق فندقية' },
  { value: 'resort',    label: 'منتجعات صحية' },
  { value: 'rest',      label: 'إستراحات' },
]

const filters = reactive({
  q: '',
  city: '',
  checkin: '',
  checkout: '',
  capacity: 2,
  category: '',
  minPrice: null,
  maxPrice: null,
})

function mainImage(unit) {
  const imgs = unit.images || []
  return (imgs.find((i) => i.is_main) || imgs[0])?.url || null
}
function formatMoney(v) {
  return new Intl.NumberFormat('en-US').format(Number(v) || 0)
}
function typeLabel(t) {
  return { apartment: 'شقة', studio: 'استوديو', villa: 'فيلا' }[t] || t
}

function toggleCategory(cat) {
  filters.category = filters.category === cat.value ? '' : cat.value
  load()
}

async function load() {
  loading.value = true
  try {
    const params = {}
    if (filters.q) params.q = filters.q
    if (filters.city) params.city = filters.city
    if (filters.category) params.category = filters.category
    if (filters.capacity) params.capacity = filters.capacity
    if (filters.minPrice != null) params.min_price = filters.minPrice
    if (filters.maxPrice != null) params.max_price = filters.maxPrice
    const { data } = await publicApi.listUnits(params)
    units.value = data.data ?? data ?? []
  } catch (e) {
    units.value = []
  } finally {
    loading.value = false
  }
}

async function loadCategories() {
  try {
    const { data } = await publicApi.categories()
    destinations.value = (data.data ?? data ?? []).map((c) => ({
      ...c,
      img: CATEGORY_IMAGES[c.key] || CATEGORY_IMAGES.apartment,
    }))
  } catch (e) {
    destinations.value = []
  }
}

async function loadOffers() {
  try {
    const { data } = await publicApi.offers()
    offers.value = data.data ?? data ?? []
  } catch (e) {
    offers.value = []
  }
}

async function loadCities() {
  try {
    const { data } = await publicApi.cities()
    cities.value = data.data ?? data ?? []
  } catch (e) {
    cities.value = []
  }
}

async function loadBudgets() {
  try {
    const { data } = await publicApi.budgets()
    budgets.value = data.data ?? data ?? []
  } catch (e) {
    budgets.value = []
  }
}

async function loadTestimonials() {
  try {
    const { data } = await publicApi.testimonials()
    testimonials.value = data.data ?? data ?? []
  } catch (e) {
    testimonials.value = []
  }
}

// Hero search → the dedicated results page, carrying the current criteria.
function search() {
  const query = {}
  if (filters.q) query.q = filters.q
  if (filters.category) query.category = filters.category
  if (filters.capacity) query.capacity = filters.capacity
  router.push({ name: 'search', query })
}

// مختارات لك — load a short curated rail for the active category chip.
async function loadPicks() {
  picksLoading.value = true
  try {
    const { data } = await publicApi.listUnits({ category: picksCategory.value, capacity: 2 })
    picks.value = (data.data ?? data ?? []).slice(0, 8)
  } catch (e) {
    picks.value = []
  } finally {
    picksLoading.value = false
  }
}

function setPicksCategory(value) {
  if (picksCategory.value === value) return
  picksCategory.value = value
  loadPicks()
}

async function loadPopular() {
  popularLoading.value = true
  try {
    const { data } = await publicApi.popularUnits()
    popular.value = data.data ?? data ?? []
  } catch (e) {
    popular.value = []
  } finally {
    popularLoading.value = false
  }
}

// Highlight badge: top-rated units get "مفضل لدى الضيوف", otherwise "مميز".
function badge(unit) {
  if (Number(unit.avg_rating) >= 4.8) {
    return { label: 'مفضل لدى الضيوف', class: 'bg-white/90 text-primary' }
  }
  return { label: 'مميز', class: 'bg-black/60 text-white' }
}

function toggleFavorite(id) {
  const next = new Set(favorites.value)
  next.has(id) ? next.delete(id) : next.add(id)
  favorites.value = next
}

async function scrollToListing() {
  await nextTick()
  listingSection.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

// Reset the cross-section filters (search box, city, category, price range).
function clearFilters() {
  filters.q = ''
  filters.city = ''
  filters.category = ''
  filters.minPrice = null
  filters.maxPrice = null
}

// A destination card filters the list by its category, then jumps to the grid.
function selectDestination(d) {
  clearFilters()
  filters.category = d.key
  load()
  scrollToListing()
}

// A city pill filters the list by city, then jumps to the grid.
function selectCity(c) {
  clearFilters()
  filters.city = c.city
  load()
  scrollToListing()
}

// A budget card filters the list by price range, then jumps to the grid.
function selectBudget(b) {
  clearFilters()
  filters.minPrice = b.min
  filters.maxPrice = b.max
  load()
  scrollToListing()
}

// "عرض الكل" — clear filters and show everything.
function resetAndScroll() {
  clearFilters()
  filters.capacity = 2
  load()
  scrollToListing()
}

onMounted(() => {
  load()
  loadPopular()
  loadPicks()
  loadCategories()
  loadOffers()
  loadCities()
  loadBudgets()
  loadTestimonials()
})
</script>
