import { randomUUID } from 'node:crypto';
import type { Db } from '../db';
import type { Notification } from '@shared/contracts';

export interface Ctx {
  db: Db;
  actor: string;
  filesDir: string;
  /** Wysyła powiadomienie do renderera i systemu (ustawiane w index.ts). */
  emit: (n: Notification) => void;
}

export const uuid = (): string => randomUUID();

export function audit(ctx: Ctx, entity: string, entityId: string, action: string, detail?: unknown): void {
  ctx.db
    .prepare('INSERT INTO audit(id, entity, entity_id, actor, action, detail) VALUES (?,?,?,?,?,?)')
    .run(uuid(), entity, entityId, ctx.actor, action, detail === undefined ? null : JSON.stringify(detail));
}

/** Operacje, które serwer potrafi przyjąć (przez crm.php). Reszta danych lokalnych zostaje tylko lokalnie. */
export const SYNCABLE_OPS = new Set(['crm.customer.upsert', 'crm.note']);

/** Operacja do wysłania na serwer (id = klucz idempotencji w kolejce). */
export function enqueue(ctx: Ctx, op: string, payload: unknown): void {
  if (!SYNCABLE_OPS.has(op)) return;
  ctx.db.prepare('INSERT INTO outbox(id, op, payload) VALUES (?,?,?)').run(uuid(), op, JSON.stringify(payload));
}

export function notify(ctx: Ctx, title: string, body: string, link: string | null = null): void {
  const id = uuid();
  ctx.db.prepare('INSERT INTO notification(id, title, body, link) VALUES (?,?,?,?)').run(id, title, body, link);
  ctx.emit({ id, title, body, link, read: false, at: new Date().toISOString() });
}
