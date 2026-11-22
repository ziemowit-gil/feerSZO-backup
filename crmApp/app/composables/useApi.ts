/**
 * Klient API CRM w SZO (api/v1/crmApp.php?_route=…). Każde żądanie niesie token użytkownika (Bearer).
 * 401 → ponowne logowanie kontem SZO; błędy walidacji 422 → ApiError.fields (komunikaty przy polach formularza).
 */
export class ApiError extends Error {
  constructor(message: string, public status: number, public fields: Record<string, string> = {}) {
    super(message)
  }
}

type Query = Record<string, string | number | boolean | null | undefined>

function clean(q: Query = {}): Record<string, string | number> {
  const out: Record<string, string | number> = {}
  for (const [k, v] of Object.entries(q)) {
    if (v === null || v === undefined || v === '' || v === false) continue
    out[k] = v === true ? 1 : v
  }
  return out
}

export function useApi() {
  const auth = useAuth()
  const route = useRoute()

  async function request<T>(method: 'GET' | 'POST', path: string, query?: Query, body?: unknown): Promise<T> {
    const token = auth.token()
    if (!token) {
      await auth.login(route.fullPath)
      throw new ApiError('Trwa logowanie…', 401)
    }
    try {
      return await $fetch<T>(`${runtimeConfig().szoUrl}/api/v1/crmApp.php`, {
        method,
        query: { _route: path, ...clean(query) },
        body: method === 'POST' ? (body ?? {}) : undefined,
        headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
      })
    } catch (e: unknown) {
      const err = e as { status?: number, statusCode?: number, data?: { error?: string, message?: string, fields?: Record<string, string> } }
      const status = err.status ?? err.statusCode ?? 0
      if (status === 401) {
        await auth.relogin(route.fullPath)
        throw new ApiError('Sesja wygasła. Przekierowujemy do logowania SZO.', 401)
      }
      const msg = err.data?.error || err.data?.message
        || (status === 0 ? 'Brak połączenia z SZO. Sprawdź internet i spróbuj ponownie.' : `Nie udało się wykonać operacji (błąd ${status}).`)
      throw new ApiError(msg, status, err.data?.fields ?? {})
    }
  }

  return {
    get: <T>(path: string, query?: Query) => request<T>('GET', path, query),
    post: <T>(path: string, body?: unknown, query?: Query) => request<T>('POST', path, query, body),
  }
}
