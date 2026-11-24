// SZO CRM — Nuxt 4, tryb SPA (bez SSR). Wynik `nuxt generate` to statyczne pliki:
// na serwerze SZO lądują w /crm2/ (scripts/publishToSzo.mjs), a osobno da się je wystawić
// z dowolnego serwera WWW (Dockerfile + nginx.conf). Adres API SZO czyta się w czasie
// działania z public/config.json — ten sam build działa pod /crm2/ i na osobnej domenie.
export default defineNuxtConfig({
  compatibilityDate: '2026-11-01',
  ssr: false,
  modules: ['@nuxt/ui'],
  css: ['~/assets/css/main.css'],
  devtools: { enabled: false },
  telemetry: false,

  app: {
    // Ścieżka bazowa: /crm2/ w SZO; przy osobnym wdrożeniu np. NUXT_APP_BASE_URL=/ przy budowaniu.
    baseURL: process.env.NUXT_APP_BASE_URL || '/crm2/',
    head: {
      htmlAttrs: { lang: 'pl' },
      title: 'CRM SZO',
      meta: [
        { name: 'viewport', content: 'width=device-width, initial-scale=1' },
        { name: 'robots', content: 'noindex, nofollow' },
        { name: 'referrer', content: 'no-referrer' },
        { name: 'color-scheme', content: 'light dark' },
      ],
    },
  },

  ui: {
    // Bez pobierania czcionek z Google (RODO, intranet) — stos systemowy w main.css.
    fonts: false,
    theme: { colors: ['primary', 'secondary', 'success', 'info', 'warning', 'error', 'neutral'] },
  },

  // Ikony wkompilowane w aplikację — bez zapytań do api.iconify.design w czasie działania.
  icon: {
    provider: 'none',
    clientBundle: { scan: true, sizeLimitKb: 512 },
  },

  colorMode: { preference: 'system', fallback: 'light', storageKey: 'szo-crm-color-mode' },

  typescript: { strict: true },
})
