import axios from 'axios'

const api = axios.create({ baseURL: '/api' })

/**
 * The signed-out share pages. They must behave identically whether or not the
 * browser happens to hold credentials: no Authorization sent, and a failure
 * never treated as "your session died".
 */
const isPublic = url => (url ?? '').startsWith('/public')

api.interceptors.request.use(config => {
  const token = localStorage.getItem('token')
  // Deliberately not sent on public routes. The server ignores it there
  // (that path sits behind a `security: false` firewall), but leaving it off
  // keeps the response genuinely viewer-independent — and therefore cacheable.
  if (token && !isPublic(config.url)) {
    config.headers.Authorization = `Bearer ${token}`
  }
  // The API translates its error messages off `Accept-Language`. Reading the
  // persisted locale (written by `i18n/index.js`'s `setLocale`) keeps this
  // interceptor free of an import cycle: `i18n` pulls in nothing from here.
  const locale = localStorage.getItem('locale')
  if (locale) {
    config.headers['Accept-Language'] = locale
  }
  return config
})

// A 401 means our token is missing/expired/invalid. Drop the stale credentials
// and bounce to login rather than leaving the user on a page that can't load.
api.interceptors.response.use(
  response => response,
  error => {
    // A 401 from a public route can't mean "your token expired" — we never sent
    // one. Bouncing on it would throw a signed-out visitor at a login screen
    // they don't need, and wipe a signed-in one's credentials for reading
    // someone else's shared shelf.
    if (error.response?.status === 401 && !isPublic(error.config?.url)) {
      localStorage.removeItem('token')
      localStorage.removeItem('user')
      if (window.location.pathname !== '/login') {
        window.location.assign('/login')
      }
    }
    return Promise.reject(error)
  },
)

export default api
