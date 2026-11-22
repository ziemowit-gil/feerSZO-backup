// Formatowanie po polsku. Daty z SZO przychodzą jako „RRRR-MM-DD GG:MM:SS” w czasie lokalnym (Europe/Warsaw).
function parse(v: string | null | undefined): Date | null {
  if (!v) return null
  const d = new Date(v.length <= 10 ? `${v}T00:00:00` : v.replace(' ', 'T'))
  return Number.isNaN(d.getTime()) ? null : d
}

export function fmtDate(v: string | null | undefined): string {
  const d = parse(v)
  return d ? d.toLocaleDateString('pl-PL', { day: 'numeric', month: 'short', year: 'numeric' }) : '—'
}

export function fmtDateTime(v: string | null | undefined): string {
  const d = parse(v)
  return d ? d.toLocaleString('pl-PL', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—'
}

/** „dziś”, „wczoraj”, „3 dni temu”, dalej data. */
export function fmtRelative(v: string | null | undefined): string {
  const d = parse(v)
  if (!d) return '—'
  const days = Math.floor((new Date().setHours(0, 0, 0, 0) - new Date(d).setHours(0, 0, 0, 0)) / 86400000)
  if (days === 0) return 'dziś'
  if (days === 1) return 'wczoraj'
  if (days > 1 && days < 7) return `${days} ${plural(days, 'dzień', 'dni', 'dni')} temu`
  return fmtDate(v)
}

/** Polska odmiana: 1 kontakt, 2 kontakty, 5 kontaktów. */
export function plural(n: number, one: string, few: string, many: string): string {
  const a = Math.abs(n)
  if (a === 1) return one
  const d = a % 10, h = a % 100
  return d >= 2 && d <= 4 && (h < 12 || h > 14) ? few : many
}

export function fmtMoney(v: number | null | undefined): string {
  return (v ?? 0).toLocaleString('pl-PL', { style: 'currency', currency: 'PLN', maximumFractionDigits: 0 })
}

export function initials(name: string | null | undefined): string {
  return (name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0]!.toUpperCase()).join('') || '?'
}
