// Przepływ logowania (PKCE + loopback) z fałszywym serwerem: ekran zatwierdzenia i endpoint tokenu.
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import http from 'node:http';
import type { AddressInfo } from 'node:net';
import { cancelLogin, performLogin } from '../src/main/services/auth';

let challenge = '', usedCodes = new Set<string>(), mode: 'ok' | 'badstate' | 'deny' = 'ok', lastDevice = '';
const srv = http.createServer((req, res) => {
  const u = new URL(req.url ?? '/', 'http://x');
  if (u.pathname === '/auth/desktop_login.php') {
    challenge = u.searchParams.get('code_challenge') ?? '';
    lastDevice = u.searchParams.get('device') ?? '';
    const back = new URL(u.searchParams.get('redirect_uri') ?? '');
    if (mode === 'deny') back.searchParams.set('error', 'access_denied');
    else back.searchParams.set('code', 'a'.repeat(64));
    back.searchParams.set('state', mode === 'badstate' ? 'zly-stan' : (u.searchParams.get('state') ?? ''));
    res.writeHead(302, { location: back.toString() }).end();
    return;
  }
  if (u.pathname === '/api/v1/desktop_token.php' && req.method === 'POST') {
    let body = '';
    req.on('data', (c) => (body += c));
    req.on('end', () => {
      const j = JSON.parse(body) as { code: string; code_verifier: string; grant_type: string };
      const okPkce = createHash('sha256').update(j.code_verifier).digest('base64url') === challenge;
      if (usedCodes.has(j.code)) { res.writeHead(400).end('{"error":"Kod wygasł lub został już użyty."}'); return; }
      usedCodes.add(j.code);
      if (!okPkce) { res.writeHead(400).end('{"error":"Nieprawidłowy code_verifier."}'); return; }
      res.writeHead(200, { 'content-type': 'application/json' }).end(JSON.stringify({
        access_token: 'ezdc_test', expires_at: '2026-11-04 12:00:00', scopes: ['ezd:read', 'ezd:upload'], user: { name: 'Jan Kowalski', email: 'jan@feer.org.pl' }
      }));
    });
    return;
  }
  res.writeHead(404).end();
});

const browser = async (url: string) => { const r = await fetch(url); await r.text(); }; // „przeglądarka” podąża za przekierowaniem

srv.listen(0, '127.0.0.1', async () => {
  const base = `http://127.0.0.1:${(srv.address() as AddressInfo).port}`;
  try {
    const r = await performLogin(base, 'MacBook-Biuro', { open: browser });
    assert.equal(r.token, 'ezdc_test');
    assert.equal(r.account.name, 'Jan Kowalski');
    assert.deepEqual(r.account.scopes, ['ezd:read', 'ezd:upload']);
    assert.equal(lastDevice, 'MacBook-Biuro');
    assert.match(challenge, /^[A-Za-z0-9_-]{43}$/, 'challenge S256 base64url');

    mode = 'badstate';
    await assert.rejects(performLogin(base, 'x', { open: browser }), /Nieprawidłowy stan/, 'zły state odrzucony (CSRF)');
    mode = 'deny';
    await assert.rejects(performLogin(base, 'x', { open: browser }), /anulowane lub odrzucone/);
    mode = 'ok'; usedCodes = new Set(['a'.repeat(64)]);
    await assert.rejects(performLogin(base, 'x', { open: browser }), /już użyty/, 'ponowne użycie kodu odrzucone przez serwer');

    usedCodes = new Set();
    const pending = performLogin(base, 'x', { open: () => undefined });   // użytkownik nie kończy logowania
    setTimeout(cancelLogin, 100);
    await assert.rejects(pending, /anulowano/);
    await assert.rejects(performLogin(base, 'x', { open: () => undefined, timeoutMs: 150 }), /Przekroczono czas/);
    console.log('AUTH OK');
  } catch (e) { console.error(e); process.exitCode = 1; } finally { srv.close(); }
});
