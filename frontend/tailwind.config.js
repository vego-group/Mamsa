/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{vue,js}'],
  theme: {
    extend: {
      colors: {
        primary:               '#163c24',
        'primary-container':   '#2e5339',
        'on-primary':          '#ffffff',
        'on-primary-fixed':    '#00210e',
        'on-primary-container':'#9cc5a4',
        'primary-fixed':       '#c2edca',
        'primary-fixed-dim':   '#a7d1af',
        'secondary-fixed-dim': '#bbcbb1',
        surface:               '#f0fdf3',
        'surface-container-lowest': '#ffffff',
        'surface-container-low':    '#eaf7ed',
        'surface-container':        '#e4f1e7',
        'surface-container-high':   '#dfebe2',
        'on-surface':          '#131e18',
        'on-surface-variant':  '#424842',
        'outline-variant':     '#c1c8c0',
        outline:               '#727971',
        error:                 '#ba1a1a',
        'error-container':     '#ffdad6',
        'on-error':            '#ffffff',
        'on-error-container':  '#93000a',
        secondary:             '#54634d',
        'on-secondary':        '#ffffff',
        'secondary-container': '#d7e8cc',
        'on-secondary-container': '#5a6952',
        'secondary-fixed':     '#d7e8cc',
        'on-secondary-fixed':  '#121f0e',
        'on-secondary-fixed-variant': '#3d4b36',
        tertiary:              '#333534',
        'on-tertiary':         '#ffffff',
        'tertiary-container':  '#4a4c4a',
        'on-tertiary-container': '#bbbcb9',
        'tertiary-fixed':      '#e2e3e0',
        'tertiary-fixed-dim':  '#c6c7c4',
        'on-tertiary-fixed':   '#1a1c1b',
        'on-tertiary-fixed-variant': '#454745',
        'surface-tint':        '#41674b',
        'surface-dim':         '#d0ddd4',
        'surface-bright':      '#f0fdf3',
        'surface-variant':     '#d9e6dc',
        'surface-container-highest': '#d9e6dc',
        'on-background':       '#131e18',
        background:            '#f0fdf3',
        'inverse-surface':     '#28332d',
        'inverse-on-surface':  '#e7f4ea',
        'inverse-primary':     '#a7d1af',
        'on-primary-fixed-variant': '#294e35',

        /* ── ywsel brand — Navy + Amber dark theme (additive, no collisions) ── */
        ink: {
          950: '#0A0F1E', // app canvas
          900: '#111729', // card / sidebar surface
          850: '#161D33', // hover / raised
          800: '#1E2740', // popover / active row
          700: '#2A3450', // hairline border / divider
        },
        brand: {
          DEFAULT: '#F5A623', // logo amber — primary accent
          300:     '#FBC56B', // light text on dark
          400:     '#F8B13E', // hover
          600:     '#DC8E12', // pressed
          700:     '#B0710C', // border on amber fills
        },
        'on-brand': '#1A1206', // navy-black text/icons on amber
        navy:        '#1B2A57', // logo navy (for light-on-navy badges, charts)
        fg: {
          DEFAULT: '#EAEEF7', // primary text
          muted:   '#9AA6C0', // secondary text
          subtle:  '#6A769A', // captions / placeholders / disabled
        },
        ok:     '#34D399', // delivered / positive
        info:   '#5B9DF9', // in-transit / neutral
        warn:   '#FBBF24', // pending / attention
        danger: '#F2546A', // failed / cancelled / error
      },
      fontFamily: {
        arabic:        ['IBM Plex Sans Arabic', 'sans-serif'],
        data:          ['Inter', 'sans-serif'],
        'display-lg':  ['IBM Plex Sans Arabic', 'sans-serif'],
        'headline-md': ['IBM Plex Sans Arabic', 'sans-serif'],
        'title-sm':    ['IBM Plex Sans Arabic', 'sans-serif'],
        'label-caps':  ['IBM Plex Sans Arabic', 'sans-serif'],
        'body-md':     ['Inter', 'sans-serif'],
        'body-sm':     ['Inter', 'sans-serif'],
        'numeric-data':['Inter', 'sans-serif'],
      },
      fontSize: {
        'display-lg':   ['32px', { lineHeight: '40px', fontWeight: '700' }],
        'headline-md':  ['24px', { lineHeight: '32px', fontWeight: '600' }],
        'title-sm':     ['18px', { lineHeight: '24px', fontWeight: '600' }],
        'body-md':      ['16px', { lineHeight: '24px', fontWeight: '400' }],
        'body-sm':      ['14px', { lineHeight: '20px', fontWeight: '400' }],
        'label-caps':   ['12px', { lineHeight: '16px', fontWeight: '700', letterSpacing: '0.05em' }],
        'numeric-data': ['15px', { lineHeight: '20px', fontWeight: '500' }],
      },
      spacing: {
        'sidebar-width': '260px',
        'container-padding': '2rem',
        'gutter': '1.5rem',
        'stack-sm': '0.5rem',
        'stack-md': '1rem',
        'stack-lg': '2rem',
      },
      borderRadius: {
        DEFAULT: '0.25rem',
        lg: '0.5rem',
        xl: '0.75rem',
        '2xl': '1rem',
        full: '9999px',
      },
      boxShadow: {
        card: '0px 4px 12px rgba(31, 42, 36, 0.08)',
        // dark-tuned: depth from surfaces, low-blur shadows for popovers only
        'ink-pop':  '0 8px 28px rgba(0, 0, 0, 0.5)',
        'ink-card': '0 1px 2px rgba(0, 0, 0, 0.4)',
        'brand-glow': '0 0 0 1px rgba(245,166,35,0.35), 0 6px 24px rgba(245,166,35,0.12)',
      },
    },
  },
  plugins: [],
}
