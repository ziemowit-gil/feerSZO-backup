import type { Notification } from '@shared/contracts';
import type { Ctx } from './context';

export function list(ctx: Ctx): Notification[] {
  const rows = ctx.db.prepare('SELECT id, at, title, body, link, read FROM notification ORDER BY at DESC, rowid DESC LIMIT 200')
    .all() as (Omit<Notification, 'read'> & { read: number })[];
  return rows.map((r) => ({ ...r, read: r.read === 1 }));
}

export function markRead(ctx: Ctx, id?: string): void {
  if (id) ctx.db.prepare('UPDATE notification SET read=1 WHERE id=?').run(id);
  else ctx.db.prepare('UPDATE notification SET read=1').run();
}
