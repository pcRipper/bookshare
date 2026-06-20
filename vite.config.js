import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig(({ command }) => ({
  plugins: [vue()],
  root: '.',
  publicDir: false,
  base: command === 'build' ? '/build/' : '/',
  build: {
    outDir: 'public/build',
    emptyOutDir: true,
  },
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./assets/src', import.meta.url)),
    },
  },
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: 'http://localhost',   // Docker Nginx HTTP (HTTP_PORT=80)
        changeOrigin: true,
      },
    },
  },
}))
