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
      },
      fontFamily: {
        arabic: ['IBM Plex Sans Arabic', 'sans-serif'],
        data:   ['Inter', 'sans-serif'],
      },
      fontSize: {
        'display-lg': ['32px', { lineHeight: '40px', fontWeight: '700' }],
        'headline-md': ['24px', { lineHeight: '32px', fontWeight: '600' }],
        'title-sm':  ['18px', { lineHeight: '24px', fontWeight: '600' }],
        'body-md':   ['16px', { lineHeight: '24px', fontWeight: '400' }],
        'body-sm':   ['14px', { lineHeight: '20px', fontWeight: '400' }],
        'label-caps':['12px', { lineHeight: '16px', fontWeight: '700', letterSpacing: '0.05em' }],
      },
      spacing: {
        'sidebar-width': '260px',
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
      },
    },
  },
  plugins: [],
}
