// Uruchamiany pod Electronem jako Node (ABI natywnego modułu): npm run test:smoke
import assert from 'node:assert/strict';
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { openDb } from '../src/main/db';
import * as ezd from '../src/main/services/ezd';
import * as crm from '../src/main/services/crm';
import type { Ctx } from '../src/main/services/context';

const dir = mkdtempSync(join(tmpdir(), 'ezdcrm-'));
const key = 'ab'.repeat(32);
const db = openDb(join(dir, 't.db'), key);
const emitted: string[] = [];
const ctx: Ctx = { db, actor: 'test', filesDir: dir, emit: (n) => emitted.push(n.title) };

const run = async () => {
  const d = await ezd.register(ctx, { kind: 'incoming', title: 'Faktura żółć', attach: false });
  assert.match(d.regNo!, /^PRZ\/\d{4}\/1$/);
  assert.equal(ezd.list(ctx, { q: 'zołc' }).length, 1, 'FTS: ż ó ć są składane (ł nie — ograniczenie unicode61)');
  ezd.advance(ctx, { id: d.id, to: 'dispatched' });
  ezd.advance(ctx, { id: d.id, to: 'in_review' });
  assert.ok(emitted.includes('Dokument do akceptacji'));
  assert.throws(() => ezd.advance(ctx, { id: d.id, to: 'signed' }), /Niedozwolone/);
  assert.equal(ezd.auditLog(ctx, { entityId: d.id }).length, 3);
  const c = crm.upsertCustomer(ctx, { kind: 'b2b', name: 'ACME', nip: '1234567890' });
  crm.linkDocument(ctx, { documentId: d.id, customerId: c.id });
  const deal = crm.addDeal(ctx, { customerId: c.id, title: 'Wdrożenie', valuePln: 1000 });
  crm.moveDeal(ctx, { id: deal.id, stage: 'won' });
  assert.equal(crm.history(ctx, { customerId: c.id }).length, 2);
  assert.equal((db.prepare('SELECT count(*) n FROM outbox').get() as { n: number }).n, 1, 'tylko operacje CRM trafiają do kolejki sync');
  db.close();
  assert.throws(() => openDb(join(dir, 't.db'), 'cd'.repeat(32)), /odszyfrować/, 'zły klucz odrzucony');
  console.log('SMOKE OK');
};
run().catch((e) => { console.error(e); process.exit(1); });
