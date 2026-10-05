import { app, dialog, shell } from 'electron';
import { copyFileSync, mkdirSync, readFileSync, statSync, unlinkSync, writeFileSync } from 'node:fs';
import { basename, extname, join } from 'node:path';
import { PDFDocument } from 'pdf-lib';
import type { ContactHit, In, PismoHit, RpwHit, ScanRec, ScannerCaps, ScannerInfo, ServerInfo, ServerStatus, SprawaHit, TeczkaHit } from '@shared/contracts';
import { clearApiToken, loadApiToken, saveApiToken } from '../security/keystore';
import { audit, notify, uuid, type Ctx } from './context';
import { capabilities, discover, scan as eSclScan, type Page, type Scanner } from './escl';
import { getAccount } from './auth';
import { getSettings } from './settings';

const known = new Map<string, Scanner>();
const capsCache = new Map<string, ScannerCaps & { maxW: number; maxH: number }>();

export async function scanners(): Promise<ScannerInfo[]> {
  for (const s of await discover()) known.set(s.id, s);
  return [...known.values()].map(({ id, name, host, port, secure }) => ({ id, name, host, port, secure }));
}

export async function caps(a: In<'scanner:caps'>): Promise<ScannerCaps> {
  const s = known.get(a.id);
  if (!s) throw new Error('Nieznany skaner — odśwież listę.');
  const c = await capabilities(s);
  capsCache.set(a.id, c);
  return { sources: c.sources, duplex: c.duplex, colors: c.colors, resolutions: c.resolutions };
}

interface Row { id: string; name: string; pages: number; size: number; status: ScanRec['status']; error: string | null; server_ref: string | null; created_at: string; uploaded_at: string | null }
const toRec = (r: Row): ScanRec => ({
  id: r.id, name: r.name, pages: r.pages, size: r.size, status: r.status, error: r.error,
  serverRef: r.server_ref, createdAt: r.created_at, uploadedAt: r.uploaded_at
});
const get = (ctx: Ctx, id: string): Row & { file_path: string } => {
  const r = ctx.db.prepare('SELECT * FROM scan WHERE id=?').get(id) as (Row & { file_path: string }) | undefined;
  if (!r) throw new Error('Nie znaleziono skanu.');
  return r;
};
const scansDir = () => { const d = join(app.getPath('userData'), 'scans'); mkdirSync(d, { recursive: true }); return d; };

/** Strony JPEG → jeden PDF (rozmiar strony = rozmiar obrazu przy zadanym DPI). */
export async function pagesToPdf(pages: Page[], dpi: number): Promise<Buffer> {
  const pdf = await PDFDocument.create();
  for (const p of pages) {
    // kopia do własnego Uint8Array: pdf-lib czyta data.buffer bez byteOffset, a bufory z puli Node'a go mają
    const bytes = new Uint8Array(p.data);
    const img = p.mime.includes('png') ? await pdf.embedPng(bytes) : await pdf.embedJpg(bytes);
    const w = (img.width / dpi) * 72, h = (img.height / dpi) * 72;
    pdf.addPage([w, h]).drawImage(img, { x: 0, y: 0, width: w, height: h });
  }
  return Buffer.from(await pdf.save());
}

const stamp = () => new Date().toISOString().slice(0, 16).replace(/[-:T]/g, '');

function insert(ctx: Ctx, name: string, file: string, pages: number): ScanRec {
  const id = uuid();
  ctx.db.prepare('INSERT INTO scan(id, name, file_path, pages, size) VALUES (?,?,?,?,?)')
    .run(id, name, file, pages, statSync(file).size);
  audit(ctx, 'scan', id, 'create', { name, pages });
  return toRec(get(ctx, id));
}

export async function start(ctx: Ctx, a: In<'scan:start'>): Promise<ScanRec> {
  const s = known.get(a.scannerId);
  if (!s) throw new Error('Nieznany skaner — odśwież listę.');
  const c = capsCache.get(a.scannerId) ?? (await capabilities(s));
  const dpi = a.dpi ?? 300;
  const pages = await eSclScan(s, { source: a.source, duplex: a.duplex ?? false, color: a.color ?? 'RGB24', dpi }, c);
  const id = uuid();
  const pdf = await pagesToPdf(pages, dpi);
  const name = `${(a.name?.trim() || `Skan ${stamp()}`)}.pdf`;
  const file = join(scansDir(), `${id}.pdf`);
  writeFileSync(file, pdf);
  return insert(ctx, name, file, pages.length);
}

/** Import istniejących plików (PDF/obraz) do kolejki skanów — np. z „Skanuj do folderu”. */
export async function importFiles(ctx: Ctx): Promise<ScanRec[]> {
  const r = await dialog.showOpenDialog({
    properties: ['openFile', 'multiSelections'],
    filters: [{ name: 'Skany', extensions: ['pdf', 'jpg', 'jpeg', 'png'] }]
  });
  if (r.canceled) return [];
  const out: ScanRec[] = [];
  for (const src of r.filePaths) {
    const ext = extname(src).toLowerCase();
    const file = join(scansDir(), `${uuid()}${ext}`);
    copyFileSync(src, file);
    let pages = 1;
    if (ext === '.pdf') pages = (await PDFDocument.load(readFileSync(file), { ignoreEncryption: true })).getPageCount();
    out.push(insert(ctx, basename(src), file, pages));
  }
  return out;
}

export const list = (ctx: Ctx): ScanRec[] =>
  (ctx.db.prepare('SELECT * FROM scan ORDER BY created_at DESC, rowid DESC LIMIT 200').all() as Row[]).map(toRec);

export function remove(ctx: Ctx, a: In<'scan:delete'>): void {
  const r = get(ctx, a.id);
  try { unlinkSync(r.file_path); } catch { /* plik mógł zniknąć */ }
  ctx.db.prepare('DELETE FROM scan WHERE id=?').run(a.id);
  audit(ctx, 'scan', a.id, 'delete', { name: r.name });
}

export function rename(ctx: Ctx, a: In<'scan:rename'>): ScanRec {
  const r = get(ctx, a.id);
  const ext = extname(r.file_path);
  const name = a.name.toLowerCase().endsWith(ext) ? a.name : a.name + ext;
  ctx.db.prepare('UPDATE scan SET name=? WHERE id=?').run(name, a.id);
  return toRec(get(ctx, a.id));
}

export async function open(ctx: Ctx, a: In<'scan:open'>): Promise<void> {
  const err = await shell.openPath(get(ctx, a.id).file_path);
  if (err) throw new Error(err);
}

// ── Serwer EZD ───────────────────────────────────────────────────────────────

export function serverStatus(ctx: Ctx): ServerStatus {
  return { url: getSettings(ctx).serverUrl, hasToken: loadApiToken() !== null, account: getAccount(ctx) };
}
/** Zapisuje klucz tylko jeśli serwer go przyjmie — zły klucz nie zostaje (konfiguracja jednorazowa musi być poprawna). */
export async function setToken(ctx: Ctx, a: In<'server:setToken'>): Promise<ServerInfo> {
  const previous = loadApiToken();
  saveApiToken(a.token);
  try {
    const info = await serverInfo(ctx);
    ctx.db.prepare("DELETE FROM setting WHERE key='account'").run(); // klucz API zamiast logowania kontem
    return info;
  } catch (e) {
    if (previous) saveApiToken(previous); else clearApiToken();
    throw e;
  }
}

function server(ctx: Ctx): { base: string; token: string } {
  const url = getSettings(ctx).serverUrl;
  const token = loadApiToken();
  if (!url || !token) throw new Error('Skonfiguruj adres serwera i klucz API (Ustawienia serwera).');
  return { base: url.replace(/\/$/, ''), token };
}

async function apiGet<T>(ctx: Ctx, path: string): Promise<T> {
  const { base, token } = server(ctx);
  const res = await fetch(`${base}/api/v1/${path}`, { headers: { authorization: `Bearer ${token}` }, signal: AbortSignal.timeout(15_000) });
  if (res.status === 401) throw new Error('Serwer odrzucił klucz API (401) — sprawdź klucz i uprawnienia ezd:read / ezd:upload.');
  if (!res.ok) throw new Error(`Serwer: HTTP ${res.status}`);
  return (await res.json()) as T;
}

export async function sprawy(ctx: Ctx, a: In<'server:sprawy'>): Promise<SprawaHit[]> {
  const qs = new URLSearchParams({ resource: 'sprawy', per_page: '25' });
  const q = (a.q ?? '').trim();
  if (q) qs.set('q', q);
  const r = await apiGet<{ data: { id: number; znak_sprawy: string; title: string; status: string }[] }>(ctx, `ezd.php?${qs}`);
  return r.data.filter((s) => s.status !== 'closed').map((s) => ({ id: s.id, znak: s.znak_sprawy, title: s.title }));
}

export async function pisma(ctx: Ctx, a: In<'server:pisma'>): Promise<PismoHit[]> {
  const r = await apiGet<{ data: { id: number; sygnatura: string; title: string }[] }>(ctx, `ezd.php?resource=pisma&sprawa_id=${a.sprawaId}&per_page=100`);
  return r.data.map((p) => ({ id: p.id, sygnatura: p.sygnatura, title: p.title }));
}

export async function teczki(ctx: Ctx, a: In<'server:teczki'>): Promise<TeczkaHit[]> {
  const r = await apiGet<{ data: { id: number; symbol: string; title: string; rok: number }[] }>(ctx, 'ezd.php?resource=teczki&status=open&per_page=100');
  const q = (a.q ?? '').trim().toLowerCase();
  return r.data
    .filter((t) => !q || `${t.symbol} ${t.title}`.toLowerCase().includes(q))
    .map((t) => ({ id: t.id, symbol: t.symbol, title: t.title, rok: t.rok }));
}

const RPW_TYP: Record<string, string> = { list: 'List', polecony: 'Polecony', paczka: 'Paczka', email: 'E-mail', epuap: 'e-Doręczenia', fax: 'Faks', osobiscie: 'Osobiście', inne: 'Inne' };

export async function rpw(ctx: Ctx, a: In<'server:rpw'>): Promise<RpwHit[]> {
  const qs = new URLSearchParams({ resource: 'rpw', per_page: '25' });
  const q = (a.q ?? '').trim();
  if (q) qs.set('q', q);
  const r = await apiGet<{ data: { id: number; rpw_nr: number; rok: number; typ: string; nadawca: string; opis: string; status: string }[] }>(ctx, `ezd_write.php?${qs}`);
  return r.data.map((x) => ({
    id: x.id, label: `RPW ${x.rpw_nr}/${x.rok} (${RPW_TYP[x.typ] ?? x.typ})`, nadawca: x.nadawca, opis: x.opis, status: x.status
  }));
}

/** Szybka lista kontaktów CRM z serwera (api/v1/crm.php, crm:read) — wyszukiwanie po nazwie, e-mailu, telefonie, NIP. */
export async function contacts(ctx: Ctx, a: In<'server:contacts'>): Promise<ContactHit[]> {
  const qs = new URLSearchParams({ per_page: '50' });
  const q = (a.q ?? '').trim();
  if (q) qs.set('q', q);
  const r = await apiGet<{ data: { id: number; type: string; imie_nazwisko: string; organizacja?: string; email?: string; telefon?: string; stanowisko?: string }[] }>(ctx, `crm.php?${qs}`);
  return r.data.map((c) => ({
    id: c.id, type: c.type, name: c.imie_nazwisko || c.organizacja || '(bez nazwy)', org: c.organizacja ?? '',
    email: c.email ?? '', phone: c.telefon ?? '', position: c.stanowisko ?? ''
  }));
}

/** Test połączenia: api/v1/info.php — weryfikuje klucz i pokazuje uprawnienia oraz dozwolone endpointy. */
export async function serverInfo(ctx: Ctx): Promise<ServerInfo> {
  let r: Awaited<ReturnType<typeof fetchInfo>>;
  try {
    r = await fetchInfo(ctx);
  } catch (e) {
    if (!(e instanceof Error) || !e.message.endsWith('HTTP 404')) throw e;
    // starszy serwer bez info.php — sprawdź sam klucz zwykłym odczytem EZD
    await apiGet(ctx, 'ezd.php?resource=teczki&per_page=1');
    return { version: null, org: null, keyName: '(klucz poprawny)', owner: null, permissions: ['ezd:read'], expiresAt: null, maxUploadBytes: 25 * 1048576 };
  }
  return {
    version: r.api.version, org: r.api.org, keyName: r.key.name, owner: r.key.owner,
    permissions: r.key.permissions, expiresAt: r.key.expires_at, maxUploadBytes: r.ezd.max_upload_bytes
  };
}

function fetchInfo(ctx: Ctx) {
  return apiGet<{
    api: { version: string | null; org: string | null }; key: { name: string; owner: string | null; permissions: string[]; expires_at: string | null };
    ezd: { max_upload_bytes: number };
  }>(ctx, 'info.php');
}

type Target = In<'scan:upload'>['target'];
type Resolved = { type: 'sprawa'; sprawaId: number; pismoId?: number } | { type: 'rpw'; rpwId: number };

async function postJson(base: string, token: string, path: string, body: unknown): Promise<Record<string, unknown>> {
  const res = await fetch(`${base}/api/v1/${path}`, {
    method: 'POST', headers: { authorization: `Bearer ${token}`, 'content-type': 'application/json' },
    body: JSON.stringify(body), signal: AbortSignal.timeout(30_000)
  });
  const json = (await res.json().catch(() => ({}))) as Record<string, unknown>;
  if (res.status === 401) throw new Error('Serwer odrzucił klucz API (401) — sprawdź uprawnienia ezd:write / ezd:upload.');
  if (!res.ok) throw new Error(String(json['error'] ?? `Serwer: HTTP ${res.status}`));
  return json;
}

/** Adres rekordu w przeglądarce — tylko http(s), na hoście serwera EZD. */
export function recordUrl(serverUrl: string, t: Resolved): string | null {
  try {
    const base = new URL(serverUrl);
    if (base.protocol !== 'https:' && base.protocol !== 'http:') return null;
    const path = t.type === 'sprawa' ? `/ezd/sprawy/view.php?id=${t.sprawaId}` : `/ezd/rpw/view.php?id=${t.rpwId}`;
    return new URL(path, base).toString();
  } catch { return null; }
}

/**
 * Wysyła skan do EZD (api/v1/ezd_upload.php → ezd_upload()). Cel może być nowy: wtedy najpierw
 * rejestruje koszulkę / wpis RPW (api/v1/ezd_write.php). Utworzony cel zapamiętuje w skanie,
 * więc ponowienie po błędzie wysyłki NIE tworzy duplikatu. Po sukcesie otwiera rekord w przeglądarce.
 */
export async function upload(ctx: Ctx, a: In<'scan:upload'>): Promise<ScanRec> {
  const r = get(ctx, a.id) as Row & { file_path: string; target: string | null };
  if (r.status === 'uploading') throw new Error('Wysyłanie już trwa.');
  const { base, token } = server(ctx);
  ctx.db.prepare("UPDATE scan SET status='uploading', error=NULL WHERE id=?").run(a.id);
  try {
    const t: Target = a.target;
    let resolved: Resolved;
    if (t.type === 'sprawa' || t.type === 'rpw') {
      resolved = t;
    } else if (r.target) {
      resolved = JSON.parse(r.target) as Resolved; // utworzone w poprzedniej, nieudanej próbie
    } else if (t.type === 'newSprawa') {
      const out = await postJson(base, token, 'ezd_write.php?resource=sprawy', { teczka_id: t.teczkaId, title: t.title });
      resolved = { type: 'sprawa', sprawaId: Number(out['id']) };
      ctx.db.prepare('UPDATE scan SET target=? WHERE id=?').run(JSON.stringify(resolved), a.id);
      audit(ctx, 'scan', a.id, 'create_sprawa', { id: out['id'], znak: out['znak_sprawy'] });
    } else {
      const out = await postJson(base, token, 'ezd_write.php?resource=rpw', { typ: t.typ, nadawca: t.nadawca, opis: t.opis });
      resolved = { type: 'rpw', rpwId: Number(out['id']) };
      ctx.db.prepare('UPDATE scan SET target=? WHERE id=?').run(JSON.stringify(resolved), a.id);
      audit(ctx, 'scan', a.id, 'create_rpw', { id: out['id'], nr: out['rpw_nr'], rok: out['rok'] });
    }

    const form = new FormData();
    if (resolved.type === 'sprawa') {
      form.set('target', 'sprawa'); form.set('sprawa_id', String(resolved.sprawaId));
      if (resolved.pismoId) form.set('pismo_id', String(resolved.pismoId));
    } else {
      form.set('target', 'rpw'); form.set('rpw_id', String(resolved.rpwId));
    }
    const mime = r.file_path.endsWith('.pdf') ? 'application/pdf' : r.file_path.endsWith('.png') ? 'image/png' : 'image/jpeg';
    form.set('file', new Blob([readFileSync(r.file_path)], { type: mime }), r.name);
    const res = await fetch(`${base}/api/v1/ezd_upload.php`, {
      method: 'POST', headers: { authorization: `Bearer ${token}` }, body: form, signal: AbortSignal.timeout(180_000)
    });
    const body = (await res.json().catch(() => ({}))) as { zalacznik_id?: number; error?: string };
    if (!res.ok) throw new Error(body.error ?? `Serwer: HTTP ${res.status}`);

    const ref = resolved.type === 'sprawa' ? `sprawa:${resolved.sprawaId}/zal:${body.zalacznik_id ?? '?'}` : `rpw:${resolved.rpwId}`;
    ctx.db.prepare("UPDATE scan SET status='uploaded', server_ref=?, uploaded_at=datetime('now') WHERE id=?").run(ref, a.id);
    audit(ctx, 'scan', a.id, 'upload', { target: resolved, zalacznikId: body.zalacznik_id });
    notify(ctx, 'Skan wgrany do EZD', `${r.name} → ${resolved.type === 'sprawa' ? `koszulka #${resolved.sprawaId}` : `RPW #${resolved.rpwId}`}`);

    const s = getSettings(ctx);
    const url = s.openAfterUpload ? recordUrl(s.serverUrl, resolved) : null;
    if (url) void shell.openExternal(url);
  } catch (e) {
    const msg = e instanceof Error ? e.message : String(e);
    ctx.db.prepare("UPDATE scan SET status='error', error=? WHERE id=?").run(msg, a.id);
    throw new Error(msg);
  }
  return toRec(get(ctx, a.id));
}
