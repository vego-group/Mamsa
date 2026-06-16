import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    host: true, // listen on all interfaces so tunnels (ngrok) can reach it
    // Allow ngrok (and other tunnel) hosts through Vite's host check.
    // Leading dot = any subdomain; random ngrok URLs are covered.
    allowedHosts: ['.ngrok-free.app', '.ngrok.io', '.ngrok.app', '.trycloudflare.com'],
    // When tunneled over HTTPS, point the HMR websocket at the public port.
    // Set VITE_TUNNEL=1 in the env when running behind ngrok to enable this.
    ...(process.env.VITE_TUNNEL
      ? { hmr: { clientPort: 443, protocol: 'wss' } }
      : {}),
    proxy: {
      '/api': {
        target: 'http://localhost:8080',
        changeOrigin: true,
      },
      '/sanctum': {
        target: 'http://localhost:8080',
        changeOrigin: true,
      },
    },
  },
})
