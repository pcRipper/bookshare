import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { compression, defineAlgorithm } from 'vite-plugin-compression2'
import { fileURLToPath, URL } from 'node:url'
import zlib from 'node:zlib'

export default defineConfig(({ command }) => ({
  plugins: [
    vue(),
    // Pre-compress build output so Nginx can serve .br/.gz directly
    // (brotli_static / gzip_static) — best ratio, zero runtime CPU cost.
    command === 'build' &&
      compression({
        threshold: 1024, // don't bother with files < 1 KB
        skipIfLargerOrEqual: true, // drop the artifact if it doesn't shrink
        deleteOriginalAssets: false, // keep originals for clients without br/gz
        algorithms: [
          defineAlgorithm('brotliCompress', {
            params: {
              [zlib.constants.BROTLI_PARAM_QUALITY]: 11, // max ratio
            },
          }),
          defineAlgorithm('gzip', { level: 9 }), // fallback for older clients
        ],
      }),
  ],
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
        target: 'https://localhost',
        changeOrigin: true,
        secure: false, // self-signed cert in dev
      },
      // Localized avatars live in public/uploads (Vite's publicDir is off), so
      // route them to the backend that serves the file off disk.
      '/uploads': {
        target: 'https://localhost',
        changeOrigin: true,
        secure: false,
      },
    },
  },
}))
