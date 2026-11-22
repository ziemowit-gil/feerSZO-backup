/**
 * Logowanie kontem SZO: OAuth 2.0 Authorization Code + PKCE (S256), ten sam przepływ co EZD Client i MSUES
 * (SZO: auth/desktop_login.php → api/v1/desktop_token.php). Aplikacja prosi wyłącznie o crm:read crm:write,
 * więc SZO stosuje bramę IKA i wydaje krótki token (do końca ważności IKA albo 8 h).
 * Token trzymamy w sessionStorage: znika po zamknięciu karty, nie trafia do ciasteczek.
 */
const TOKEN_KEY = 'szo-crm-token'
const PKCE_KEY = 'szo-crm-pkce'
const RETRY_KEY = 'szo-crm-login-retry'

interface StoredToken { token: string, expiresAt: string, scopes: string[] }
interface PkceState { verifier: string, state: string, returnTo: string }

function b64url(bytes: Uint8Array): string {
  let s = ''
  bytes.forEach(b => { s += String.fromCharCode(b) })
  return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

function randomString(len: number): string {
  const a = new Uint8Array(len)
  crypto.getRandomValues(a)
  return b64url(a)
}

async function sha256(text: string): Promise<Uint8Array> {
  return new Uint8Array(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(text)))
}

function read<T>(key: string): T | null {
  try {
    const v = sessionStorage.getItem(key)
    return v ? JSON.parse(v) as T : null
  } catch {
    return null
  }
}

function write(key: string, value: unknown): void {
  try {
    if (value === null) sessionStorage.removeItem(key)
    else sessionStorage.setItem(key, JSON.stringify(value))
  } catch {
    // tryb prywatny / zablokowana pamięć — zostaje token tylko w pamięci karty
  }
}

const memoryToken = ref<StoredToken | null>(null)

export function useAuth() {
  const appBase = useRuntimeConfig().app.baseURL

  function callbackUrl(): string {
    return `${window.location.origin}${appBase}auth/callback`
  }

  function token(): string | null {
    const t = memoryToken.value ?? read<StoredToken>(TOKEN_KEY)
    if (!t) return null
    memoryToken.value = t
    return t.token
  }

  function scopes(): string[] {
    return (memoryToken.value ?? read<StoredToken>(TOKEN_KEY))?.scopes ?? []
  }

  /** Przekierowanie na logowanie SZO. returnTo = ścieżka w aplikacji, na którą wrócimy. */
  async function login(returnTo = '/'): Promise<void> {
    const { szoUrl } = runtimeConfig()
    const verifier = randomString(64)
    const state = randomString(24)
    write(PKCE_KEY, { verifier, state, returnTo } satisfies PkceState)
    const challenge = b64url(await sha256(verifier))
    const q = new URLSearchParams({
      redirect_uri: callbackUrl(),
      state,
      code_challenge: challenge,
      device: 'CRM SZO',
      scope: 'crm:read crm:write',
    })
    window.location.assign(`${szoUrl}/auth/desktop_login.php?${q.toString()}`)
  }

  /** Wymiana kodu z SZO na token (strona /auth/callback). Zwraca ścieżkę powrotu. */
  async function finishLogin(code: string, state: string): Promise<string> {
    const pkce = read<PkceState>(PKCE_KEY)
    write(PKCE_KEY, null)
    if (!pkce || pkce.state !== state) throw new Error('Sesja logowania wygasła albo pochodzi z innej karty. Zaloguj się ponownie.')
    const { szoUrl } = runtimeConfig()
    const res = await $fetch<{ access_token: string, expires_at: string, scopes: string[] }>(`${szoUrl}/api/v1/desktop_token.php`, {
      method: 'POST',
      body: { grant_type: 'authorization_code', code, code_verifier: pkce.verifier },
    })
    const stored: StoredToken = { token: res.access_token, expiresAt: res.expires_at, scopes: res.scopes }
    memoryToken.value = stored
    write(TOKEN_KEY, stored)
    return pkce.returnTo || '/'
  }

  /** Token odrzucony (wygasł, IKA) — czyścimy i logujemy od nowa; ochrona przed pętlą przekierowań. */
  async function relogin(returnTo: string): Promise<boolean> {
    forget()
    const retry = Number(sessionStorage.getItem(RETRY_KEY) || '0')
    if (Date.now() - retry < 15000) return false
    sessionStorage.setItem(RETRY_KEY, String(Date.now()))
    await login(returnTo)
    return true
  }

  function forget(): void {
    memoryToken.value = null
    write(TOKEN_KEY, null)
  }

  /** Wylogowanie z aplikacji: unieważnia token w SZO (sesja SZO w przeglądarce zostaje). */
  async function logout(): Promise<void> {
    const t = token()
    forget()
    if (!t) return
    try {
      await $fetch(`${runtimeConfig().szoUrl}/api/v1/desktop_token.php`, {
        method: 'POST', query: { action: 'logout' }, headers: { Authorization: `Bearer ${t}` },
      })
    } catch {
      // token i tak jest już zapomniany po stronie aplikacji
    }
  }

  return { token, scopes, login, finishLogin, relogin, forget, logout, isLoggedIn: () => !!token() }
}
