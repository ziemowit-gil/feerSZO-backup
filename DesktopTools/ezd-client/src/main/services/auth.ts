import { createHash, randomBytes } from 'node:crypto';
import http from 'node:http';
import os from 'node:os';
import type { AddressInfo } from 'node:net';
import { shell } from 'electron';
import type { Account } from '@shared/contracts';
import { clearApiToken, loadApiToken, saveApiToken } from '../security/keystore';
import type { Ctx } from './context';
import { getSettings } from './settings';

/**
 * Logowanie użytkownika kontem Microsoft 365 przez serwer (OAuth Authorization Code + PKCE, loopback):
 * przeglądarka systemowa → logowanie M365 na serwerze → zatwierdzenie → kod na 127.0.0.1 → wymiana na token.
 * Token to klucz API konta użytkownika (autor wpisów w EZD/CRM = zalogowana osoba).
 */

const b64url = (b: Buffer) => b.toString('base64url');
const SUCCESS = (msg: string, ok: boolean) => `<!doctype html><html lang="pl"><meta charset="utf-8"><title>EZD Client</title>
<body style="font-family:system-ui,sans-serif;display:grid;place-items:center;min-height:100vh;margin:0;background:#f6f7f9">
<main style="background:#fff;border:1px solid #c9d0d8;border-radius:10px;padding:28px;max-width:420px;text-align:center">
<h1 style="font-size:1.2rem;color:${ok ? '#0a6b3d' : '#b3261e'}">${msg}</h1><p>Możesz zamknąć tę kartę i wrócić do aplikacji EZD Client.</p></main></body></html>`;

export interface LoginResult { token: string; account: Account }
export interface LoginDeps { open: (url: string) => void | Promise<void>; timeoutMs?: number }

let cancelCurrent: (() => void) | null = null;
export const cancelLogin = (): void => cancelCurrent?.();

/** Czysty przepływ logowania (bez zależności od Electrona — testowalny). */
export async function performLogin(serverUrl: string, device: string, deps: LoginDeps): Promise<LoginResult> {
  const base = serverUrl.replace(/\/$/, '');
  const verifier = b64url(randomBytes(32));
  const challenge = b64url(createHash('sha256').update(verifier).digest());
  const state = b64url(randomBytes(16));

  const code = await new Promise<string>((resolve, reject) => {
    const srv = http.createServer((req, res) => {
      const u = new URL(req.url ?? '/', 'http://127.0.0.1');
      if (u.pathname !== '/callback') { res.writeHead(404).end(); return; }
      const fail = (m: string) => { res.writeHead(400, { 'content-type': 'text/html; charset=utf-8' }).end(SUCCESS(m, false)); finish(new Error(m)); };
      if (u.searchParams.get('state') !== state) return fail('Nieprawidłowy stan logowania.');
      if (u.searchParams.get('error')) return fail('Logowanie zostało anulowane lub odrzucone.');
      const c = u.searchParams.get('code');
      if (!c) return fail('Brak kodu logowania.');
      res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' }).end(SUCCESS('Zalogowano', true));
      finish(undefined, c);
    });
    const timer = setTimeout(() => finish(new Error('Przekroczono czas logowania (5 min).')), deps.timeoutMs ?? 300_000);
    const finish = (err?: Error, c?: string) => {
      clearTimeout(timer); cancelCurrent = null;
      srv.close(); srv.closeAllConnections?.();
      if (err) reject(err); else resolve(c as string);
    };
    cancelCurrent = () => finish(new Error('Logowanie anulowano.'));
    srv.on('error', (e) => finish(e));
    srv.listen(0, '127.0.0.1', () => {
      const port = (srv.address() as AddressInfo).port;
      const qs = new URLSearchParams({ redirect_uri: `http://127.0.0.1:${port}/callback`, state, code_challenge: challenge, device });
      Promise.resolve(deps.open(`${base}/auth/desktop_login.php?${qs}`)).catch((e) => finish(e as Error));
    });
  });

  const res = await fetch(`${base}/api/v1/desktop_token.php`, {
    method: 'POST', headers: { 'content-type': 'application/json' },
    body: JSON.stringify({ grant_type: 'authorization_code', code, code_verifier: verifier }),
    signal: AbortSignal.timeout(20_000)
  });
  const j = (await res.json().catch(() => ({}))) as {
    access_token?: string; expires_at?: string; scopes?: string[]; user?: { name: string; email: string }; error?: string;
  };
  if (!res.ok || !j.access_token || !j.user) throw new Error(j.error ?? `Serwer: HTTP ${res.status}`);
  return { token: j.access_token, account: { name: j.user.name, email: j.user.email, expiresAt: j.expires_at ?? '', scopes: j.scopes ?? [] } };
}

const saveAccount = (ctx: Ctx, a: Account | null): void => {
  if (a) ctx.db.prepare("INSERT INTO setting(key, value) VALUES ('account', ?) ON CONFLICT(key) DO UPDATE SET value=excluded.value").run(JSON.stringify(a));
  else ctx.db.prepare("DELETE FROM setting WHERE key='account'").run();
};
export function getAccount(ctx: Ctx): Account | null {
  if (!loadApiToken()) return null;
  const r = ctx.db.prepare("SELECT value FROM setting WHERE key='account'").get() as { value: string } | undefined;
  try { return r ? (JSON.parse(r.value) as Account) : null; } catch { return null; }
}

export async function login(ctx: Ctx): Promise<Account> {
  if (cancelCurrent) throw new Error('Logowanie już trwa — dokończ je w przeglądarce.');
  const { serverUrl } = getSettings(ctx);
  const r = await performLogin(serverUrl, os.hostname().replace(/\.local$/, ''), { open: (u) => shell.openExternal(u) });
  saveApiToken(r.token);
  saveAccount(ctx, r.account);
  return r.account;
}

export async function logout(ctx: Ctx): Promise<void> {
  const token = loadApiToken();
  const { serverUrl } = getSettings(ctx);
  if (token && getAccount(ctx)) { // cofnij token na serwerze (best effort)
    await fetch(`${serverUrl.replace(/\/$/, '')}/api/v1/desktop_token.php?action=logout`, {
      method: 'POST', headers: { authorization: `Bearer ${token}` }, signal: AbortSignal.timeout(8000)
    }).catch(() => undefined);
  }
  clearApiToken();
  saveAccount(ctx, null);
}

/** Przy starcie: odnawia token, gdy wygasa w ciągu 10 dni; odrzucony przez serwer (401) = wylogowanie. */
export async function renewIfNeeded(ctx: Ctx): Promise<void> {
  const acc = getAccount(ctx);
  const token = loadApiToken();
  if (!acc || !token) return;
  const left = new Date(acc.expiresAt.replace(' ', 'T')).getTime() - Date.now();
  if (Number.isFinite(left) && left > 10 * 86_400_000) return;
  try {
    const res = await fetch(`${getSettings(ctx).serverUrl.replace(/\/$/, '')}/api/v1/desktop_token.php?action=renew`, {
      method: 'POST', headers: { authorization: `Bearer ${token}` }, signal: AbortSignal.timeout(10_000)
    });
    if (res.status === 401) { clearApiToken(); saveAccount(ctx, null); return; }
    const j = (await res.json()) as { expires_at?: string; scopes?: string[] };
    if (res.ok && j.expires_at) saveAccount(ctx, { ...acc, expiresAt: j.expires_at, scopes: j.scopes ?? acc.scopes });
  } catch { /* offline — spróbujemy przy następnym starcie */ }
}
