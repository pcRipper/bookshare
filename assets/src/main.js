import './styles/tokens.css'
import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import hscroll from './directives/hscroll'

const app = createApp(App)

app.use(createPinia())
app.use(router)
app.directive('hscroll', hscroll)

app.mount('#app')

// Register the service worker in production only. In dev the SPA is served by
// Vite (which doesn't serve `public/`), and a SW would interfere with HMR.
if ('serviceWorker' in navigator && import.meta.env.PROD) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').catch(() => {
      /* SW registration is a progressive enhancement — ignore failures. */
    })
  })
}
