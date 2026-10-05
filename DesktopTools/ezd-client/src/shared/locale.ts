/**
 * Lokalizacja: jeden punkt prawdy dla języka i formatów (pl-PL).
 * Daty z serwera przychodzą jako „RRRR-MM-DD GG:MM[:SS]” (czas lokalny serwera) lub ISO.
 */
export const LOCALE = 'pl-PL';
export const LANG = 'pl';

const dtf = new Intl.DateTimeFormat(LOCALE, { dateStyle: 'medium', timeStyle: 'short' });
const df = new Intl.DateTimeFormat(LOCALE, { dateStyle: 'medium' });
const nf = new Intl.NumberFormat(LOCALE);
const pln = new Intl.NumberFormat(LOCALE, { style: 'currency', currency: 'PLN', maximumFractionDigits: 0 });
const mb = new Intl.NumberFormat(LOCALE, { maximumFractionDigits: 1 });

/** „2026-10-05 21:30” albo ISO → Date (null, gdy nie da się sparsować). */
export function parseServerDate(s: string | null | undefined): Date | null {
  if (!s) return null;
  const d = new Date(s.includes('T') ? s : s.replace(' ', 'T'));
  return Number.isNaN(d.getTime()) ? null : d;
}
export const fmtDateTime = (s: string | null | undefined): string => { const d = parseServerDate(s); return d ? dtf.format(d) : (s ?? ''); };
export const fmtDate = (s: string | null | undefined): string => { const d = parseServerDate(s); return d ? df.format(d) : (s ?? ''); };
/** Wartość atrybutu <time dateTime> (maszynowa, dla technologii asystujących). */
export const isoOf = (s: string | null | undefined): string | undefined => parseServerDate(s)?.toISOString();
export const fmtNumber = (n: number): string => nf.format(n);
export const fmtPln = (n: number): string => pln.format(n);
export const fmtBytes = (n: number): string => (n >= 1048576 ? `${mb.format(n / 1048576)} MB` : `${nf.format(Math.max(1, Math.round(n / 1024)))} KB`);
