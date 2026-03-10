import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
  plugins: [
    laravel({
      input: ['resources/js/app.js'],
      refresh: true,
    }),
  ],
  server: {
    cors: true,
    watch: { ignored: ['**/storage/framework/views/**'] },
    // لو يزعجك Overlay أثناء التطوير:
    // hmr: { overlay: false },
  },
});