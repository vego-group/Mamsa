/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './resources/**/*.blade.php',
    './resources/**/*.js',
    './resources/**/*.vue',
  ],
  theme: {
    extend: {
      colors: {
        brand: {
          50:  '#eef6f5',
          100: '#cfe6e3',
          200: '#a7d3cd',
          300: '#7fc0b7',
          400: '#58ada1',
          500: '#3e9387',
          600: '#2f736a',
          700: '#22554f',
          800: '#153834',
          900: '#0a2320',
        },
      },
    },
  },
  plugins: [
    require('@tailwindcss/forms'),
  ],
};