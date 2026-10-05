import { ImapFlow } from 'imapflow';
import { loadSecret } from '../security/keystore';
import { notify, uuid, type Ctx } from './context';

interface ImapCfg { host: string; port: number; user: string }

/** Konfiguracja IMAP: setting `imap` = {host,port,user}; hasło w sejfie pod nazwą `imap`. */
export async function sync(ctx: Ctx): Promise<{ fetched: number }> {
  const raw = ctx.db.prepare("SELECT value FROM setting WHERE key='imap'").get() as { value: string } | undefined;
  const pass = loadSecret('imap');
  if (!raw || !pass) return { fetched: 0 };
  const cfg = JSON.parse(raw.value) as ImapCfg;
  const client = new ImapFlow({ host: cfg.host, port: cfg.port, secure: true, auth: { user: cfg.user, pass }, logger: false });
  let fetched = 0;
  await client.connect();
  try {
    const lock = await client.getMailboxLock('INBOX');
    try {
      const last = (ctx.db.prepare('SELECT coalesce(max(uid),0) AS u FROM mail_message').get() as { u: number }).u;
      const find = ctx.db.prepare('SELECT id FROM customer WHERE lower(email)=lower(?)');
      const ins = ctx.db.prepare('INSERT INTO mail_message(id, customer_id, uid, from_addr, subject, at) VALUES (?,?,?,?,?,?)');
      for await (const m of client.fetch(`${last + 1}:*`, { uid: true, envelope: true }, { uid: true })) {
        if (m.uid <= last) continue;
        const from = m.envelope?.from?.[0]?.address ?? '';
        const cust = from ? (find.get(from) as { id: string } | undefined) : undefined;
        ins.run(uuid(), cust?.id ?? null, m.uid, from, m.envelope?.subject ?? '', (m.envelope?.date ?? new Date()).toISOString());
        fetched++;
        if (cust) notify(ctx, 'Nowa wiadomość od klienta', m.envelope?.subject ?? '(bez tematu)', `customer:${cust.id}`);
      }
    } finally {
      lock.release();
    }
  } finally {
    await client.logout();
  }
  return { fetched };
}
