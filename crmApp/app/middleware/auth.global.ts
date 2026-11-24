// Każda strona poza /auth/* wymaga tokenu SZO — bez niego od razu logowanie kontem SZO.
export default defineNuxtRouteMiddleware(async (to) => {
  if (to.path.startsWith('/auth/')) return
  const auth = useAuth()
  if (!auth.isLoggedIn()) {
    await auth.login(to.fullPath)
    // Przeglądarka właśnie przechodzi na logowanie SZO — nie kończymy nawigacji (abort = błąd startu aplikacji).
    return new Promise<void>(() => {})
  }
})
