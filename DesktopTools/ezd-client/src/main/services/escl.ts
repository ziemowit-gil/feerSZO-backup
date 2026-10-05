import http from 'node:http';
import https from 'node:https';
import { Bonjour } from 'bonjour-service';
import { XMLParser } from 'fast-xml-parser';

/** Klient eSCL (AirScan) — protokół skanowania po sieci bez sterowników (PWG 5100.17). */

export interface Scanner { id: string; name: string; host: string; port: number; secure: boolean; root: string }
export interface Caps { sources: ('Platen' | 'Feeder')[]; duplex: boolean; colors: string[]; resolutions: number[]; maxW: number; maxH: number }
export interface ScanOpts { source: 'Platen' | 'Feeder'; duplex: boolean; color: 'RGB24' | 'Grayscale8'; dpi: number }
export interface Page { data: Buffer; mime: string }

const xml = new XMLParser({ removeNSPrefix: true, ignoreAttributes: true });
const arr = <T>(v: T | T[] | undefined): T[] => (v === undefined ? [] : Array.isArray(v) ? v : [v]);

interface Res { status: number; headers: http.IncomingHttpHeaders; body: Buffer }

/** Skanery w LAN mają zwykle samopodpisane certyfikaty (_uscans) — weryfikację wyłączamy TYLKO dla adresu skanera. */
function request(url: URL, method: string, body?: string, timeoutMs = 30_000): Promise<Res> {
  return new Promise((resolve, reject) => {
    const mod = url.protocol === 'https:' ? https : http;
    const req = mod.request(url, {
      method, rejectUnauthorized: false,
      headers: body ? { 'content-type': 'text/xml', 'content-length': Buffer.byteLength(body) } : {}
    }, (res) => {
      const chunks: Buffer[] = [];
      res.on('data', (c: Buffer) => chunks.push(c));
      res.on('end', () => resolve({ status: res.statusCode ?? 0, headers: res.headers, body: Buffer.concat(chunks) }));
    });
    req.setTimeout(timeoutMs, () => req.destroy(new Error('Przekroczono czas oczekiwania na skaner')));
    req.on('error', reject);
    if (body) req.write(body);
    req.end();
  });
}

const base = (s: Scanner) => new URL(`${s.secure ? 'https' : 'http'}://${s.host}:${s.port}/${s.root}/`);

export function discover(timeoutMs = 5000): Promise<Scanner[]> {
  return new Promise((resolve) => {
    const bj = new Bonjour();
    const found = new Map<string, Scanner>();
    const add = (secure: boolean) => (svc: { name: string; host: string; port: number; addresses?: string[]; txt?: Record<string, unknown> }) => {
      const addr = svc.addresses?.find((a) => /^\d+\.\d+\.\d+\.\d+$/.test(a)) ?? svc.host;
      const root = String(svc.txt?.['rs'] ?? 'eSCL').replace(/^\/|\/$/g, '');
      // jeden fizyczny skaner ogłasza się często przez uscan i uscans — zostaw jeden wpis, preferując zwykły HTTP
      const prev = found.get(addr);
      if (!prev || (prev.secure && !secure)) found.set(addr, { id: `${addr}:${svc.port}`, name: svc.name, host: addr, port: svc.port, secure, root });
    };
    const b1 = bj.find({ type: 'uscan' }, add(false));
    const b2 = bj.find({ type: 'uscans' }, add(true));
    setTimeout(() => { b1.stop(); b2.stop(); bj.destroy(); resolve([...found.values()]); }, timeoutMs);
  });
}

export async function capabilities(s: Scanner): Promise<Caps> {
  const r = await request(new URL('ScannerCapabilities', base(s)), 'GET');
  if (r.status !== 200) throw new Error(`Skaner odpowiedział HTTP ${r.status}`);
  const c = xml.parse(r.body.toString('utf8'))?.ScannerCapabilities ?? {};
  const sources: Caps['sources'] = [];
  if (c.Platen) sources.push('Platen');
  if (c.Adf) sources.push('Feeder');
  const settingsOf = (n: 'Platen' | 'Adf') => c[n]?.[n === 'Platen' ? 'PlatenInputCaps' : 'AdfSimplexInputCaps'] ?? {};
  const first = settingsOf(sources[0] === 'Feeder' ? 'Adf' : 'Platen');
  const profile = arr(first.SettingProfiles?.SettingProfile)[0] ?? {};
  const colors = arr(profile.ColorModes?.ColorMode).map(String);
  const res = new Set<number>();
  const dr = profile.SupportedResolutions;
  arr(dr?.DiscreteResolutions?.DiscreteResolution).forEach((d: { XResolution: number }) => res.add(Number(d.XResolution)));
  if (res.size === 0 && dr?.ResolutionRange) {
    const { Min, Max } = dr.ResolutionRange.XResolutionRange ?? {};
    [150, 200, 300, 600].filter((v) => v >= Number(Min ?? 0) && v <= Number(Max ?? 9999)).forEach((v) => res.add(v));
  }
  return {
    sources,
    duplex: c.Adf?.AdfDuplexInputCaps !== undefined,
    colors: colors.length ? colors : ['RGB24', 'Grayscale8'],
    resolutions: [...res].sort((a, b) => a - b).length ? [...res].sort((a, b) => a - b) : [200, 300],
    maxW: Number(first.MaxWidth ?? 2550), maxH: Number(first.MaxHeight ?? 3507)
  };
}

const settingsXml = (o: ScanOpts, w: number, h: number) => `<?xml version="1.0" encoding="UTF-8"?>
<scan:ScanSettings xmlns:pwg="http://www.pwg.org/schemas/2010/12/sm" xmlns:scan="http://schemas.hp.com/imaging/escl/2011/05/03">
<pwg:Version>2.0</pwg:Version>
<pwg:ScanRegions><pwg:ScanRegion>
<pwg:ContentRegionUnits>escl:ThreeHundredthsOfInches</pwg:ContentRegionUnits>
<pwg:XOffset>0</pwg:XOffset><pwg:YOffset>0</pwg:YOffset><pwg:Width>${w}</pwg:Width><pwg:Height>${h}</pwg:Height>
</pwg:ScanRegion></pwg:ScanRegions>
<pwg:InputSource>${o.source}</pwg:InputSource>
<scan:ColorMode>${o.color}</scan:ColorMode>
<scan:XResolution>${o.dpi}</scan:XResolution><scan:YResolution>${o.dpi}</scan:YResolution>
<pwg:DocumentFormat>image/jpeg</pwg:DocumentFormat>
${o.source === 'Feeder' ? `<scan:Duplex>${o.duplex}</scan:Duplex>` : ''}
</scan:ScanSettings>`;

/** Skanuje i zwraca strony (JPEG). Z podajnika (Feeder) pobiera aż skaner zgłosi koniec (404). */
export async function scan(s: Scanner, o: ScanOpts, caps: Caps, onPage?: (n: number) => void): Promise<Page[]> {
  const w = Math.min(2480, caps.maxW), h = Math.min(3508, caps.maxH); // A4 @300 jednostek/cal
  const create = await request(new URL('ScanJobs', base(s)), 'POST', settingsXml(o, w, h));
  if (create.status === 503) throw new Error('Skaner jest zajęty — spróbuj za chwilę.');
  if (create.status !== 201 || !create.headers.location) throw new Error(`Skaner odrzucił zlecenie (HTTP ${create.status}).`);
  const job = new URL(create.headers.location, base(s));
  const pages: Page[] = [];
  try {
    for (let guard = 0; guard < 200; guard++) {
      const r = await request(new URL(`${job.pathname.replace(/\/$/, '')}/NextDocument`, job), 'GET', undefined, 120_000);
      if (r.status === 404) break;
      if (r.status === 503) { await new Promise((ok) => setTimeout(ok, 800)); continue; }
      if (r.status !== 200) throw new Error(`Błąd pobierania strony (HTTP ${r.status}).`);
      pages.push({ data: r.body, mime: String(r.headers['content-type'] ?? 'image/jpeg') });
      onPage?.(pages.length);
      if (o.source === 'Platen') break;
    }
  } finally {
    request(job, 'DELETE').catch(() => undefined); // sprzątanie zlecenia (best effort)
  }
  if (pages.length === 0) throw new Error('Skaner nie zwrócił żadnej strony (pusty podajnik?).');
  return pages;
}
