import axios from 'axios'

const api = axios.create({ baseURL: '/api' })

api.interceptors.request.use(config => {
  const token = localStorage.getItem('token')
  if (token) {
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
    if (error.response?.status === 401) {
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
