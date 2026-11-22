import type { LocationQuery } from 'vue-router'

/** Zapytanie z adresu (?a=1&b=2) jako płaskie pary tekstowe — do przekazania dalej do API. */
export function flatQuery(q: LocationQuery): Record<string, string> {
  const out: Record<string, string> = {}
  for (const [k, v] of Object.entries(q)) {
    const val = Array.isArray(v) ? v[0] : v
    if (val !== null && val !== undefined && val !== '') out[k] = String(val)
  }
  return out
}
