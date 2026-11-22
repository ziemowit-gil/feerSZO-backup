/**
 * Konfiguracja czytana w czasie działania z public/config.json (obok index.html), żeby ten sam build
 * działał pod /crm2/ w SZO i na osobnej domenie — wystarczy podmienić config.json, bez przebudowy.
 *   szoUrl — adres SZO (np. https://szo.feer.org.pl). Pusty = ta sama domena co aplikacja.
 */
export interface CrmRuntimeConfig {
  szoUrl: string
}

let cached: CrmRuntimeConfig | null = null

export async function loadRuntimeConfig(baseURL: string): Promise<CrmRuntimeConfig> {
  if (cached) return cached
  let raw: Partial<CrmRuntimeConfig> = {}
  try {
    raw = await $fetch<Partial<CrmRuntimeConfig>>(`${baseURL}config.json`, { cache: 'no-store' })
  } catch {
    // brak pliku = wartości domyślne (ta sama domena)
  }
  const szoUrl = String(raw.szoUrl || '').trim().replace(/\/+$/, '') || window.location.origin
  cached = { szoUrl }
  return cached
}

export function runtimeConfig(): CrmRuntimeConfig {
  if (!cached) throw new Error('Konfiguracja aplikacji nie jest jeszcze wczytana.')
  return cached
}
