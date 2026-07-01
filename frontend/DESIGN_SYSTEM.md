# Mamsa — Dark & Gold Design System

> **Style:** SaaS dashboard · **Theme:** Dark + Gold accent · **Approach:** Mobile-first · **Stack:** Tailwind CSS 3.4 · Vue 3 · RTL (Arabic-first)

Built on the same **semantic-token architecture** as the existing theme, but driven by **CSS variables** (RGB channels) so every token supports Tailwind opacity utilities (`bg-gold/10`, `text-text/60`) and a future light mode is a one-block swap.

---

## 1. Design Principles

1. **Mobile-first** — every component is styled for the smallest screen, then enhanced with `sm: md: lg:`. The default has no breakpoint prefix.
2. **Surface elevation, not shadows** — in dark UIs, depth comes from *lighter* surfaces (`surface` → `surface-2` → `surface-3`) and hairline borders, not heavy drop shadows.
3. **Gold is a scalpel, not a brush** — gold marks the *one* primary action / active state per view. Overuse kills the premium feel. Body UI stays neutral.
4. **Logical RTL** — use logical spacing (`ps-/pe-/ms-/me-/start-/end-`) so the same markup mirrors correctly under `dir="rtl"`.
5. **Contrast floor** — body text ≥ 7:1 on canvas, secondary text ≥ 4.5:1. Never put light gold text on dark for long copy.

---

## 2. Color System

### 2.1 Tokens (CSS variables — paste into `src/assets/main.css`)

```css
@layer base {
  :root {
    /* ── Surfaces (dark, layered) ────────────────── */
    --canvas:        11 12 15;    /* #0B0C0F  app background        */
    --surface:       20 22 27;    /* #14161B  cards, sidebar        */
    --surface-2:     27 30 37;    /* #1B1E25  raised / hover        */
    --surface-3:     35 39 48;    /* #232730  popovers, active row  */
    --hairline:      42 47 58;    /* #2A2F3A  borders, dividers     */

    /* ── Gold (brand accent ramp) ────────────────── */
    --gold-300:      245 212 131; /* #F5D483  light / hover text    */
    --gold-400:      232 194 90;  /* #E8C25A  interactive hover     */
    --gold:          212 175 55;  /* #D4AF37  BRAND / primary       */
    --gold-600:      184 149 42;  /* #B8952A  pressed               */
    --gold-700:      146 117 33;  /* #927521  borders on gold fills */
    --on-gold:       26 20 6;     /* #1A1406  text/icons on gold    */

    /* ── Text ────────────────────────────────────── */
    --text:          240 241 243; /* #F0F1F3  primary               */
    --text-muted:    161 167 179; /* #A1A7B3  secondary             */
    --text-subtle:   112 118 132; /* #707684  placeholder, captions */

    /* ── Semantic ────────────────────────────────── */
    --success:       52 211 153;  /* #34D399 */
    --warning:       245 166 35;  /* #F5A623 */
    --danger:        239 83 107;  /* #EF536B */
    --info:          79 156 249;  /* #4F9CF9 */

    /* ── Effects ─────────────────────────────────── */
    --ring:          212 175 55;  /* focus ring = gold              */
    --scrim:         0 0 0;       /* modal/overlay backdrop         */
  }
}
```

### 2.2 Palette reference

| Role | Token | Hex | Use |
|---|---|---|---|
| App background | `canvas` | `#0B0C0F` | The page behind everything |
| Card / sidebar | `surface` | `#14161B` | Default container fill |
| Raised / hover | `surface-2` | `#1B1E25` | Hover rows, inputs, nested cards |
| Popover / active | `surface-3` | `#232730` | Dropdowns, active nav, selected row |
| Border | `hairline` | `#2A2F3A` | Dividers, card borders, input borders |
| **Brand / primary** | `gold` | `#D4AF37` | Primary buttons, active accents, focus |
| Gold hover | `gold-400` | `#E8C25A` | Hover state of gold elements |
| On-gold | `on-gold` | `#1A1406` | Text/icons sitting on a gold fill |
| Text primary | `text` | `#F0F1F3` | Headings, body |
| Text secondary | `text-muted` | `#A1A7B3` | Labels, meta, secondary copy |
| Text subtle | `text-subtle` | `#707684` | Placeholders, captions, disabled |
| Success | `success` | `#34D399` | Positive deltas, confirmed states |
| Warning | `warning` | `#F5A623` | Pending, attention |
| Danger | `danger` | `#EF536B` | Destructive, errors, negative deltas |
| Info | `info` | `#4F9CF9` | Neutral informational |

---

## 3. Typography

Keep the existing dual-family setup — **IBM Plex Sans Arabic** for all UI/Arabic text, **Inter** for numerals, data, and Latin labels (tabular figures read better in tables/stat cards).

### 3.1 Scale (mobile-first; values bump up at `md:` where noted)

| Token | Class | Size / Line | Weight | Use |
|---|---|---|---|---|
| Display | `text-display` | 28→36px / 1.15 | 700 | Page hero, empty-state numbers |
| H1 | `text-h1` | 24→28px / 1.25 | 700 | Page title |
| H2 | `text-h2` | 20→22px / 1.3 | 600 | Section title |
| H3 | `text-h3` | 17→18px / 1.4 | 600 | Card title |
| Body | `text-body` | 15px / 1.6 | 400 | Default paragraph |
| Body-sm | `text-body-sm` | 13px / 1.5 | 400 | Secondary copy, table cells |
| Label | `text-label` | 13px / 1.2 | 500 | Form labels, buttons |
| Caption | `text-caption` | 11px / 1.3 | 500 | Meta, timestamps |
| Overline | `text-overline` | 11px / 1.2 | 700 · `0.06em` · UPPER | Section eyebrows, stat labels |
| Numeric | `font-data tabular-nums` | — | 500 | KPI values, money, counts |

### 3.2 Recipes

```html
<!-- Page header -->
<h1 class="text-h1 font-bold text-text">لوحة التحكم</h1>
<p class="text-body-sm text-text-muted">نظرة عامة على أداء المنصة</p>

<!-- KPI value (Inter, tabular) -->
<span class="font-data tabular-nums text-display text-text">12,480</span>

<!-- Stat label -->
<span class="text-overline text-text-subtle">إجمالي الحجوزات</span>
```

---

## 4. Tailwind Config

Drop this into `tailwind.config.js` (`theme.extend`). Colors reference the CSS vars via `rgb(var(--x) / <alpha-value>)`, which is what unlocks `bg-gold/10` etc.

```js
// tailwind.config.js → theme.extend
const c = (v) => `rgb(var(${v}) / <alpha-value>)`;

export default {
  darkMode: 'class',
  content: ['./index.html', './src/**/*.{vue,js}'],
  theme: {
    extend: {
      colors: {
        canvas:      c('--canvas'),
        surface: {
          DEFAULT:   c('--surface'),
          2:         c('--surface-2'),
          3:         c('--surface-3'),
        },
        hairline:    c('--hairline'),
        gold: {
          DEFAULT:   c('--gold'),
          300:       c('--gold-300'),
          400:       c('--gold-400'),
          600:       c('--gold-600'),
          700:       c('--gold-700'),
        },
        'on-gold':   c('--on-gold'),
        text: {
          DEFAULT:   c('--text'),
          muted:     c('--text-muted'),
          subtle:    c('--text-subtle'),
        },
        success:     c('--success'),
        warning:     c('--warning'),
        danger:      c('--danger'),
        info:        c('--info'),
      },
      fontFamily: {
        sans:   ['IBM Plex Sans Arabic', 'sans-serif'], // default UI/Arabic
        data:   ['Inter', 'sans-serif'],                // numerals/Latin
      },
      fontSize: {
        display:  ['28px', { lineHeight: '1.15', fontWeight: '700' }],
        h1:       ['24px', { lineHeight: '1.25', fontWeight: '700' }],
        h2:       ['20px', { lineHeight: '1.3',  fontWeight: '600' }],
        h3:       ['17px', { lineHeight: '1.4',  fontWeight: '600' }],
        body:     ['15px', { lineHeight: '1.6',  fontWeight: '400' }],
        'body-sm':['13px', { lineHeight: '1.5',  fontWeight: '400' }],
        label:    ['13px', { lineHeight: '1.2',  fontWeight: '500' }],
        caption:  ['11px', { lineHeight: '1.3',  fontWeight: '500' }],
        overline: ['11px', { lineHeight: '1.2',  fontWeight: '700', letterSpacing: '0.06em' }],
      },
      borderRadius: { DEFAULT: '0.5rem', lg: '0.75rem', xl: '1rem', '2xl': '1.25rem' },
      boxShadow: {
        // dark-tuned: low-spread, deep — depth comes from surfaces, not blur
        card:    '0 1px 2px rgb(0 0 0 / 0.4)',
        pop:     '0 8px 24px rgb(0 0 0 / 0.5)',
        glow:    '0 0 0 1px rgb(var(--gold) / 0.4), 0 4px 20px rgb(var(--gold) / 0.15)',
      },
      ringColor:  { DEFAULT: 'rgb(var(--ring) / 0.5)' },
      spacing: { sidebar: '264px' },
      screens: { /* default Tailwind: sm 640 md 768 lg 1024 xl 1280 */ },
    },
  },
  plugins: [],
};
```

Add responsive type bumps in `main.css` if desired:

```css
@screen md {
  .text-display { font-size: 36px; }
  .text-h1 { font-size: 28px; }
  .text-h2 { font-size: 22px; }
  .text-h3 { font-size: 18px; }
}
```

---

## 5. Component Layer

Paste into `src/assets/main.css` under `@layer components`. These are the canonical class recipes — use the utility version inline when you need one-off variants.

```css
@layer base {
  html { font-family: 'IBM Plex Sans Arabic', sans-serif; }
  body { @apply bg-canvas text-text antialiased; }
  ::selection { @apply bg-gold/30 text-text; }
  /* dark scrollbar */
  ::-webkit-scrollbar { width: 10px; height: 10px; }
  ::-webkit-scrollbar-thumb { @apply bg-surface-3 rounded-full border-2 border-solid border-canvas; }
}

@layer components {

  /* ── Buttons ─────────────────────────────────── */
  .btn { @apply inline-flex items-center justify-center gap-2 font-sans text-label rounded-lg
         px-4 py-2.5 transition-all duration-150 select-none
         focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold/50
         disabled:opacity-50 disabled:pointer-events-none; }

  .btn-primary { @apply btn bg-gold text-on-gold font-semibold
                 hover:bg-gold-400 active:bg-gold-600 active:scale-[0.98] shadow-card; }

  .btn-secondary { @apply btn bg-surface-2 text-text border border-hairline
                   hover:bg-surface-3 hover:border-gold/40 active:scale-[0.98]; }

  .btn-ghost { @apply btn text-text-muted hover:bg-surface-2 hover:text-text; }

  .btn-danger { @apply btn bg-danger/15 text-danger border border-danger/30
                hover:bg-danger/25 active:scale-[0.98]; }

  .btn-sm { @apply px-3 py-1.5 text-caption rounded-md; }
  .btn-icon { @apply btn p-2 rounded-lg; } /* square icon button */

  /* ── Inputs ──────────────────────────────────── */
  .field { @apply w-full px-3.5 py-2.5 rounded-lg bg-surface-2 text-text text-body-sm
           border border-hairline placeholder:text-text-subtle outline-none transition-all
           focus:border-gold/60 focus:ring-2 focus:ring-gold/20
           disabled:opacity-50; }

  .field-label { @apply block text-label text-text-muted mb-1.5; }
  .field-error { @apply mt-1 text-caption text-danger; }

  /* ── Surfaces ────────────────────────────────── */
  .card { @apply bg-surface border border-hairline rounded-xl p-4 md:p-5; }
  .card-hover { @apply card transition-colors hover:border-gold/30 hover:bg-surface-2; }
  .divider { @apply h-px w-full bg-hairline; }

  /* ── Badges / chips ──────────────────────────── */
  .badge { @apply inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-caption font-medium; }
  .badge-gold    { @apply badge bg-gold/15 text-gold-300; }
  .badge-success { @apply badge bg-success/15 text-success; }
  .badge-warning { @apply badge bg-warning/15 text-warning; }
  .badge-danger  { @apply badge bg-danger/15 text-danger; }
  .badge-neutral { @apply badge bg-surface-3 text-text-muted; }

  /* ── Nav item (sidebar) ──────────────────────── */
  .nav-item { @apply flex items-center gap-3 px-3 py-2.5 rounded-lg text-label text-text-muted
              transition-colors hover:bg-surface-2 hover:text-text; }
  .nav-item-active { @apply nav-item bg-gold/10 text-gold-300 font-semibold
                     relative before:absolute before:inset-y-1.5 before:end-0
                     before:w-0.5 before:rounded-full before:bg-gold; }

  /* ── Table ───────────────────────────────────── */
  .table-head { @apply text-overline text-text-subtle text-start px-4 py-3; }
  .table-cell { @apply text-body-sm text-text px-4 py-3 border-t border-hairline; }
  .table-row  { @apply transition-colors hover:bg-surface-2; }
}
```

### 5.1 Component gallery (markup)

**Stat / KPI card**
```html
<div class="card">
  <div class="flex items-center justify-between">
    <span class="text-overline text-text-subtle">إجمالي الحجوزات</span>
    <span class="badge-success"><span class="material-symbols-outlined text-[14px]">trending_up</span> 12%</span>
  </div>
  <p class="mt-2 font-data tabular-nums text-display text-text">12,480</p>
  <p class="mt-1 text-caption text-text-muted">+340 خلال آخر 7 أيام</p>
</div>
```

**Buttons**
```html
<button class="btn-primary"><span class="material-symbols-outlined text-[18px]">add</span> حجز جديد</button>
<button class="btn-secondary">تصدير</button>
<button class="btn-ghost">إلغاء</button>
<button class="btn-danger btn-sm">حذف</button>
<button class="btn-icon btn-secondary"><span class="material-symbols-outlined text-[18px]">more_horiz</span></button>
```

**Input + label**
```html
<div>
  <label class="field-label">البريد الإلكتروني</label>
  <input type="email" class="field" placeholder="name@example.com" />
  <p class="field-error">هذا الحقل مطلوب</p>
</div>
```

**Badges**
```html
<span class="badge-success">مؤكد</span>
<span class="badge-warning">قيد المراجعة</span>
<span class="badge-danger">مرفوض</span>
<span class="badge-gold">مميّز</span>
```

**Data table**
```html
<div class="card p-0 overflow-hidden">
  <table class="w-full border-collapse">
    <thead>
      <tr><th class="table-head">العميل</th><th class="table-head">الوحدة</th>
          <th class="table-head">الحالة</th><th class="table-head">المبلغ</th></tr>
    </thead>
    <tbody>
      <tr class="table-row">
        <td class="table-cell">أحمد العتيبي</td>
        <td class="table-cell text-text-muted">شاليه — A12</td>
        <td class="table-cell"><span class="badge-success">مؤكد</span></td>
        <td class="table-cell font-data tabular-nums">1,250 ر.س</td>
      </tr>
    </tbody>
  </table>
</div>
```

**Modal**
```html
<div class="fixed inset-0 z-50 grid place-items-center p-4">
  <div class="absolute inset-0 bg-black/70 backdrop-blur-sm"></div>
  <div class="relative w-full max-w-md card shadow-pop">
    <h3 class="text-h3 text-text">تأكيد الحذف</h3>
    <p class="mt-2 text-body-sm text-text-muted">لا يمكن التراجع عن هذا الإجراء.</p>
    <div class="mt-5 flex justify-end gap-2">
      <button class="btn-ghost">إلغاء</button>
      <button class="btn-danger">حذف</button>
    </div>
  </div>
</div>
```

---

## 6. Dashboard Layout

**Mobile-first pattern** (matches the existing `AdminLayout.vue`):

- **< lg:** sticky top bar with a hamburger → slide-in sidebar over a scrim. A bottom tab bar is the SaaS-native alternative for ≤ 4 primary destinations.
- **≥ lg:** fixed sidebar pinned to the **end** (right, since RTL → `lg:me-[264px]` via logical margin), persistent top bar, fluid content.

```
┌───────── < lg (mobile) ─────────┐   ┌──────────── ≥ lg (desktop) ────────────┐
│ ▸ ☰  Title          🔔  (A)     │   │ ┌─ main ───────────────┐ ┌─ sidebar ─┐ │
│                                 │   │ │ search 🔔 ⚙ user      │ │  LOGO     │ │
│   ┌──────┐ ┌──────┐             │   │ ├──────────────────────┤ │  ▸ active │ │
│   │ KPI  │ │ KPI  │   1-col→2   │   │ │  KPI  KPI  KPI  KPI   │ │    item   │ │
│   └──────┘ └──────┘             │   │ │  ┌────────┐ ┌──────┐  │ │    item   │ │
│   ┌────────────────┐            │   │ │  │ chart  │ │ list │  │ │    item   │ │
│   │     chart      │            │   │ │  └────────┘ └──────┘  │ │ ───────── │ │
│   └────────────────┘            │   │ │  table…                │ │  logout   │ │
│ ─────────────────────────────  │   │ └──────────────────────┘ └───────────┘ │
│  🏠    📅    ➕    🔔    ☰     │   └─────────────────────────────────────────┘
└─ bottom tab bar (optional) ─────┘
```

### 6.1 Content grid

```html
<!-- KPI row: 1 → 2 → 4 columns -->
<section class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3 md:gap-4">…</section>

<!-- Main split: stacks on mobile, 2/3 + 1/3 on desktop -->
<section class="grid grid-cols-1 lg:grid-cols-3 gap-4 mt-4">
  <div class="lg:col-span-2 card">…chart…</div>
  <div class="card">…activity feed…</div>
</section>
```

A copy-paste Vue reference implementation ships at **`src/layouts/DashboardLayout.dark.vue`** (see file). It uses logical RTL margins (`lg:me-[264px]`), the scrim/slide-in pattern, and the component classes above.

---

## 7. Motion & Interaction

| Token | Value | Use |
|---|---|---|
| Fast | `duration-150` | Hover, color, focus |
| Base | `duration-200` | Slide-in panels, fades |
| Ease | `ease-out` | Default entrances |
| Press | `active:scale-[0.98]` | Buttons, tappable cards |
| Focus | `focus-visible:ring-2 ring-gold/50` | All interactive elements |

Respect `motion-reduce:` — wrap non-essential transitions so they disable under `prefers-reduced-motion`.

---

## 8. Accessibility Checklist

- [ ] Focus ring visible on every interactive element (gold ring, never `outline-none` alone).
- [ ] Gold (`#D4AF37`) on `canvas` passes AA for large text/icons; for small text on gold fills use `--on-gold` (dark) — never light gold on dark for body copy.
- [ ] Status is never color-only: pair badges with text/icon (`✓ مؤكد`).
- [ ] Touch targets ≥ 44×44px on mobile (`p-2.5`+ or `min-h-11`).
- [ ] `dir="rtl"` on root; use logical utilities (`ms/me/ps/pe/start/end`).
```
