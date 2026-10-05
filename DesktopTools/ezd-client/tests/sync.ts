// Sync outboxa z fałszywym serwerem crm.php + adres otwierany po wgraniu.
import assert from 'node:assert/strict';
import http from 'node:http';
import { mkdtempSync } from 'node:fs';
import type { AddressInfo } from 'node:net';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { openDb } from '../src/main/db';
import * as crm from '../src/main/services/crm';
import { flushOutbox } from '../src/main/services/sync';
import { recordUrl } from '../src/main/services/scans';
import type { Ctx } from '../src/main/services/context';

const calls: string[] = [];
let failNotes = false;
const srv = http.createServer((req, res) => {
  let body = '';
  req.on('data', (c) => (body += c));
  req.on('end', () => {
    calls.push(`${req.method} ${req.url} ${body}`);
    if (req.headers.authorization !== 'Bearer tok') { res.writeHead(401).end('{"error":"Unauthorized"}'); return; }
    if (req.method === 'POST' && req.url === '/api/v1/crm.php') { res.writeHead(201, { 'content-type': 'application/json' }).end('{"data":{"id":77}}'); return; }
    if (req.method === 'PATCH') { res.writeHead(200).end('{"data":{"id":77}}'); return; }
    if (req.method === 'POST' && req.url?.includes('resource=notes')) {
      if (failNotes) { res.writeHead(503).end('{"error":"busy"}'); return; }
      res.writeHead(201).end('{"ok":true}'); return;
    }
    res.writeHead(404).end('{"error":"x"}');
  });
});

srv.listen(0, '127.0.0.1', async () => {
  const baseUrl = `http://127.0.0.1:${(srv.address() as AddressInfo).port}`;
  try {
    const db = openDb(join(mkdtempSync(join(tmpdir(), 'sync-')), 't.db'), 'ab'.repeat(32));
    const ctx: Ctx = { db, actor: 't', filesDir: tmpdir(), emit: () => undefined };
    const c = crm.upsertCustomer(ctx, { kind: 'b2b', name: 'ACME', email: 'a@acme.pl' });
    crm.addNote(ctx, { customerId: c.id, body: 'Notatka 1' });
    crm.addDeal(ctx, { customerId: c.id, title: 'Sprawa', valuePln: 1 }); // tylko lokalnie
    assert.equal((db.prepare('SELECT count(*) n FROM outbox').get() as { n: number }).n, 2, 'deal nie trafia do kolejki');

    assert.deepEqual(await flushOutbox(ctx, { baseUrl, token: 'zly' }), { sent: 0, dropped: 0, error: 'Unauthorized' });
    failNotes = true;
    const r1 = await flushOutbox(ctx, { baseUrl, token: 'tok' });
    assert.equal(r1.sent, 1, 'kontakt poszedł, notatka czeka (503)');
    assert.equal((db.prepare('SELECT count(*) n FROM outbox').get() as { n: number }).n, 1);
    failNotes = false;
    const r2 = await flushOutbox(ctx, { baseUrl, token: 'tok' });
    assert.equal(r2.sent, 1);
    assert.ok(calls.some((l) => l.startsWith('POST /api/v1/crm.php?id=77&resource=notes')), 'notatka na zmapowane id');

    crm.upsertCustomer(ctx, { id: c.id, kind: 'b2b', name: 'ACME 2' }); // znany id → PATCH
    await flushOutbox(ctx, { baseUrl, token: 'tok' });
    assert.ok(calls.some((l) => l.startsWith('PATCH /api/v1/crm.php?id=77')), 'aktualizacja = PATCH');

    assert.equal(recordUrl('https://szo.feer.org.pl', { type: 'sprawa', sprawaId: 5 }), 'https://szo.feer.org.pl/ezd/sprawy/view.php?id=5');
    assert.equal(recordUrl('https://szo.feer.org.pl/', { type: 'rpw', rpwId: 9 }), 'https://szo.feer.org.pl/ezd/rpw/view.php?id=9');
    assert.equal(recordUrl('javascript:alert(1)', { type: 'rpw', rpwId: 9 }), null);
    assert.equal(recordUrl('file:///etc/passwd', { type: 'rpw', rpwId: 9 }), null);
    console.log('SYNC OK');
  } catch (e) { console.error(e); process.exitCode = 1; } finally { srv.close(); }
});
