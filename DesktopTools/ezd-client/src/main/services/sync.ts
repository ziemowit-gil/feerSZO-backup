import { loadApiToken } from '../security/keystore';
import { getSettings } from './settings';
import type { Ctx } from './context';

interface OutboxRow { id: string; op: string; payload: string; attempts: number }
export interface Creds { baseUrl: string; token: string }

const MAX_ATTEMPTS = 20;

/**
 * Synchronizacja kolejki zmian z serwerem przez ISTNIEJĄCE API CRM (api/v1/crm.php, crm:write):
 *   crm.customer.upsert → POST (nowy) / PATCH ?id= (znany z remote_map) kontakt
 *   crm.note            → POST ?id=&resource=notes
 * Mapowanie id lokalne→serwerowe w tabeli remote_map. Operacje idą po kolei; błąd zatrzymuje kolejkę
 * (zachowanie kolejności: notatka nie wyprzedzi kontaktu). Odrzucenie 4xx = operacja trwale zła, zdejmujemy ją.
 */
export async function flushOutbox(ctx: Ctx, creds: Creds | null): Promise<{ sent: number; dropped: number; error?: string }> {
  if (!creds) return { sent: 0, dropped: 0 };
  const rows = ctx.db.prepare('SELECT id, op, payload, attempts FROM outbox ORDER BY created_at, rowid LIMIT 100').all() as OutboxRow[];
  let sent = 0, dropped = 0;
  for (const r of rows) {
    try {
      await apply(ctx, creds, r.op, JSON.parse(r.payload) as Record<string, unknown>);
      ctx.db.prepare('DELETE FROM outbox WHERE id=?').run(r.id);
      sent++;
    } catch (e) {
      const err = e as Error & { permanent?: boolean };
      if (err.permanent || r.attempts + 1 >= MAX_ATTEMPTS) {
        ctx.db.prepare('DELETE FROM outbox WHERE id=?').run(r.id);
        dropped++;
        continue;
      }
      ctx.db.prepare('UPDATE outbox SET attempts = attempts + 1 WHERE id=?').run(r.id);
      return { sent, dropped, error: err.message };
    }
  }
  return { sent, dropped };
}

async function call(creds: Creds, method: string, path: string, body?: unknown): Promise<Record<string, unknown>> {
  const res = await fetch(`${creds.baseUrl.replace(/\/$/, '')}/api/v1/${path}`, {
    method,
    headers: { authorization: `Bearer ${creds.token}`, 'content-type': 'application/json' },
    body: body === undefined ? undefined : JSON.stringify(body),
    signal: AbortSignal.timeout(15_000)
  });
  const json = (await res.json().catch(() => ({}))) as Record<string, unknown>;
  if (!res.ok) {
    const err = new Error(String(json['error'] ?? `HTTP ${res.status}`)) as Error & { permanent?: boolean };
    err.permanent = res.status >= 400 && res.status < 500 && res.status !== 401 && res.status !== 408 && res.status !== 429;
    throw err;
  }
  return json;
}

const remoteId = (ctx: Ctx, kind: string, local: string): string | undefined =>
  (ctx.db.prepare('SELECT remote_id FROM remote_map WHERE kind=? AND local_id=?').get(kind, local) as { remote_id: string } | undefined)?.remote_id;

async function apply(ctx: Ctx, creds: Creds, op: string, p: Record<string, unknown>): Promise<void> {
  if (op === 'crm.customer.upsert') {
    const org = p['kind'] === 'b2b';
    const body = {
      type: org ? 'organizacja' : 'osoba', imie_nazwisko: p['name'], ...(org ? { organizacja: p['name'] } : {}),
      email: p['email'], telefon: p['phone'], nip: p['nip'], source: 'desktop'
    };
    const known = remoteId(ctx, 'customer', String(p['id']));
    if (known) { await call(creds, 'PATCH', `crm.php?id=${encodeURIComponent(known)}`, body); return; }
    const out = await call(creds, 'POST', 'crm.php', body);
    const id = (out['data'] as { id?: number } | undefined)?.id ?? (out['id'] as number | undefined);
    if (id === undefined) throw Object.assign(new Error('Serwer nie zwrócił id kontaktu'), { permanent: false });
    ctx.db.prepare('INSERT OR REPLACE INTO remote_map(kind, local_id, remote_id) VALUES (?,?,?)').run('customer', String(p['id']), String(id));
    return;
  }
  if (op === 'crm.note') {
    const rid = remoteId(ctx, 'customer', String(p['customerId']));
    if (!rid) throw new Error('Kontrahent jeszcze nie zsynchronizowany');
    await call(creds, 'POST', `crm.php?id=${encodeURIComponent(rid)}&resource=notes`, { note: p['body'] });
    return;
  }
  throw Object.assign(new Error(`Nieobsługiwana operacja: ${op}`), { permanent: true });
}

export function currentCreds(ctx: Ctx): Creds | null {
  const baseUrl = getSettings(ctx).serverUrl;
  const token = loadApiToken();
  return baseUrl && token ? { baseUrl, token } : null;
}

export function startSyncLoop(ctx: Ctx, everyMs = 60_000): () => void {
  const t = setInterval(() => { void flushOutbox(ctx, currentCreds(ctx)); }, everyMs);
  return () => clearInterval(t);
}
