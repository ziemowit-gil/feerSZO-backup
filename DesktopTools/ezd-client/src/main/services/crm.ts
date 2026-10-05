import type { Customer, Deal, HistoryItem, In } from '@shared/contracts';
import { audit, enqueue, notify, uuid, type Ctx } from './context';

interface CRow { id: string; kind: 'b2b' | 'b2c'; name: string; nip: string | null; email: string | null; phone: string | null; updated_at: string }
const toCustomer = (r: CRow): Customer => ({
  id: r.id, kind: r.kind, name: r.name, nip: r.nip, email: r.email, phone: r.phone, updatedAt: r.updated_at
});

interface DRow { id: string; customer_id: string; customer_name: string; title: string; stage: Deal['stage']; value_pln: number; updated_at: string }
const toDeal = (r: DRow): Deal => ({
  id: r.id, customerId: r.customer_id, customerName: r.customer_name, title: r.title,
  stage: r.stage, valuePln: r.value_pln, updatedAt: r.updated_at
});
const DEAL_SQL = 'SELECT d.*, c.name AS customer_name FROM deal d JOIN customer c ON c.id = d.customer_id';

export function customers(ctx: Ctx, a: In<'crm:customers'>): Customer[] {
  const q = `%${(a.q ?? '').trim().replace(/[%_]/g, '\\$&')}%`;
  return (ctx.db.prepare(
    "SELECT * FROM customer WHERE name LIKE ? ESCAPE '\\' OR nip LIKE ? ESCAPE '\\' OR email LIKE ? ESCAPE '\\' ORDER BY name LIMIT 500"
  ).all(q, q, q) as CRow[]).map(toCustomer);
}

export function upsertCustomer(ctx: Ctx, a: In<'crm:upsertCustomer'>): Customer {
  const id = a.id ?? uuid();
  ctx.db.transaction(() => {
    ctx.db.prepare(
      `INSERT INTO customer(id, kind, name, nip, email, phone) VALUES (?,?,?,?,?,?)
       ON CONFLICT(id) DO UPDATE SET kind=excluded.kind, name=excluded.name, nip=excluded.nip,
         email=excluded.email, phone=excluded.phone, updated_at=datetime('now')`
    ).run(id, a.kind, a.name, a.nip ?? null, a.email ?? null, a.phone ?? null);
    audit(ctx, 'customer', id, a.id ? 'update' : 'create', { name: a.name });
    enqueue(ctx, 'crm.customer.upsert', { ...a, id });
  })();
  return toCustomer(ctx.db.prepare('SELECT * FROM customer WHERE id=?').get(id) as CRow);
}

export function deals(ctx: Ctx, a: In<'crm:deals'>): Deal[] {
  const rows = a.customerId
    ? ctx.db.prepare(`${DEAL_SQL} WHERE d.customer_id=? ORDER BY d.updated_at DESC`).all(a.customerId)
    : ctx.db.prepare(`${DEAL_SQL} ORDER BY d.updated_at DESC LIMIT 1000`).all();
  return (rows as DRow[]).map(toDeal);
}

export function addDeal(ctx: Ctx, a: In<'crm:addDeal'>): Deal {
  const id = uuid();
  ctx.db.transaction(() => {
    ctx.db.prepare('INSERT INTO deal(id, customer_id, title, value_pln) VALUES (?,?,?,?)')
      .run(id, a.customerId, a.title, a.valuePln ?? 0);
    audit(ctx, 'deal', id, 'create', { title: a.title });
    enqueue(ctx, 'crm.deal.create', { id, ...a });
  })();
  return toDeal(ctx.db.prepare(`${DEAL_SQL} WHERE d.id=?`).get(id) as DRow);
}

export function moveDeal(ctx: Ctx, a: In<'crm:moveDeal'>): Deal {
  const before = ctx.db.prepare('SELECT stage, title FROM deal WHERE id=?').get(a.id) as { stage: string; title: string } | undefined;
  if (!before) throw new Error('Nie znaleziono sprawy');
  ctx.db.transaction(() => {
    ctx.db.prepare("UPDATE deal SET stage=?, updated_at=datetime('now') WHERE id=?").run(a.stage, a.id);
    audit(ctx, 'deal', a.id, 'stage', { from: before.stage, to: a.stage });
    enqueue(ctx, 'crm.deal.stage', { id: a.id, stage: a.stage });
  })();
  if (a.stage === 'won' || a.stage === 'lost') {
    notify(ctx, a.stage === 'won' ? 'Sprawa wygrana' : 'Sprawa przegrana', before.title, `deal:${a.id}`);
  }
  return toDeal(ctx.db.prepare(`${DEAL_SQL} WHERE d.id=?`).get(a.id) as DRow);
}

export function linkDocument(ctx: Ctx, a: In<'crm:linkDocument'>): void {
  ctx.db.transaction(() => {
    const r = ctx.db.prepare("UPDATE document SET customer_id=?, updated_at=datetime('now') WHERE id=?").run(a.customerId, a.documentId);
    if (r.changes === 0) throw new Error('Nie znaleziono dokumentu');
    audit(ctx, 'document', a.documentId, 'link_customer', { customerId: a.customerId });
    enqueue(ctx, 'link.document', a);
  })();
}

export function addNote(ctx: Ctx, a: In<'crm:addNote'>): void {
  const id = uuid();
  ctx.db.prepare('INSERT INTO note(id, customer_id, body) VALUES (?,?,?)').run(id, a.customerId, a.body);
  audit(ctx, 'customer', a.customerId, 'note');
  enqueue(ctx, 'crm.note', { id, ...a });
}

/** Oś czasu kontaktu: notatki, dokumenty EZD, sprawy i maile klienta. */
export function history(ctx: Ctx, a: In<'crm:history'>): HistoryItem[] {
  const q = (sql: string) => ctx.db.prepare(sql).all(a.customerId) as { at: string; text: string }[];
  const items: HistoryItem[] = [
    ...q('SELECT at, body AS text FROM note WHERE customer_id=?').map((r) => ({ ...r, type: 'note' as const })),
    ...q("SELECT updated_at AS at, coalesce(reg_no,'') || ' — ' || title AS text FROM document WHERE customer_id=?").map((r) => ({ ...r, type: 'document' as const })),
    ...q("SELECT updated_at AS at, title || ' (' || stage || ')' AS text FROM deal WHERE customer_id=?").map((r) => ({ ...r, type: 'deal' as const })),
    ...q("SELECT coalesce(at,'') AS at, coalesce(subject,'') AS text FROM mail_message WHERE customer_id=?").map((r) => ({ ...r, type: 'mail' as const }))
  ];
  return items.sort((x, y) => y.at.localeCompare(x.at));
}
