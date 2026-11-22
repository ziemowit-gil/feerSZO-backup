// Przed startem routera: wczytaj config.json (adres SZO). Middleware logowania już go potrzebuje.
export default defineNuxtPlugin({
  name: 'crm-runtime-config',
  enforce: 'pre',
  async setup() {
    await loadRuntimeConfig(useRuntimeConfig().app.baseURL)
  },
})
