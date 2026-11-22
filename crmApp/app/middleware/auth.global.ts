// Każda strona poza /auth/* wymaga tokenu SZO — bez niego od razu logowanie kontem SZO.
export default defineNuxtRouteMiddleware(async (to) => {
  if (to.path.startsWith('/auth/')) return
  const auth = useAuth()
  if (!auth.isLoggedIn()) {
    await auth.login(to.fullPath)
    return abortNavigation()
  }
})
