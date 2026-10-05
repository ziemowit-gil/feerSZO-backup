import { createHash } from 'node:crypto';
import { copyFileSync, mkdirSync, readFileSync } from 'node:fs';
import { basename, join } from 'node:path';
import { dialog } from 'electron';
import type { AuditEntry, Doc, DocVersion, In, Stage } from '@shared/contracts';
import { audit, enqueue, notify, uuid, type Ctx } from './context';
import { getSigner } from './sign';

/** Dozwolone przejścia obiegu dokumentu (workflow). */
const FLOW: Record<Stage, Stage[]> = {
  registered: ['dispatched', 'archived'],
  dispatched: ['in_review', 'registered'],
  in_review: ['approved', 'dispatched'],
  approved: ['signed', 'in_review'],
  signed: ['archived'],
  archived: []
};

interface Row {
  id: string; kind: Doc['kind']; reg_no: string | null; title: string; stage: Stage;
  jrwa: string | null; customer_id: string | null; version: number; updated_at: string;
}
const toDoc = (r: Row): Doc => ({
  id: r.id, kind: r.kind, regNo: r.reg_no, title: r.title, stage: r.stage, jrwa: r.jrwa,
  customerId: r.customer_id, version: r.version, updatedAt: r.updated_at
});

const get = (ctx: Ctx, id: string): Doc => {
  const r = ctx.db.prepare('SELECT * FROM document WHERE id = ?').get(id) as Row | undefined;
  if (!r) throw new Error('Nie znaleziono dokumentu');
  return toDoc(r);
};

/** Numer w rejestrze kancelaryjnym: PRZ|WYC|WEW/rok/n (lokalnie; serwer nadaje numer ostateczny). */
function nextRegNo(ctx: Ctx, kind: Doc['kind']): string {
  const year = new Date().getFullYear();
  ctx.db.prepare(
    'INSERT INTO reg_counter(year, kind, n) VALUES (?,?,1) ON CONFLICT(year, kind) DO UPDATE SET n = n + 1'
  ).run(year, kind);
  const { n } = ctx.db.prepare('SELECT n FROM reg_counter WHERE year=? AND kind=?').get(year, kind) as { n: number };
  const prefix = { incoming: 'PRZ', outgoing: 'WYC', internal: 'WEW' }[kind];
  return `${prefix}/${year}/${n}`;
}

export function list(ctx: Ctx, a: In<'ezd:list'>): Doc[] {
  const params: unknown[] = [];
  let sql = 'SELECT d.* FROM document d';
  const where: string[] = [];
  if (a.q?.trim()) {
    // FTS5: każde słowo jako prefiks, cudzysłowy chronią przed składnią zapytań
    const match = a.q.trim().split(/\s+/).map((w) => `"${w.replace(/"/g, '')}"*`).join(' ');
    sql += ' JOIN document_fts f ON f.rowid = d.rowid';
    where.push('document_fts MATCH ?');
    params.push(match);
  }
  if (a.kind) { where.push('d.kind = ?'); params.push(a.kind); }
  if (where.length) sql += ' WHERE ' + where.join(' AND ');
  sql += ' ORDER BY d.updated_at DESC LIMIT 500';
  return (ctx.db.prepare(sql).all(...params) as Row[]).map(toDoc);
}

export async function register(ctx: Ctx, a: In<'ezd:register'> & { attach?: boolean }): Promise<Doc> {
  let picked: string | undefined;
  if (a.attach) {
    const r = await dialog.showOpenDialog({ properties: ['openFile'] });
    if (r.canceled || !r.filePaths[0]) throw new Error('Anulowano wybór pliku');
    picked = r.filePaths[0];
  }
  const id = uuid();
  const tx = ctx.db.transaction(() => {
    const regNo = nextRegNo(ctx, a.kind);
    ctx.db.prepare(
      'INSERT INTO document(id, kind, reg_no, title, jrwa, customer_id) VALUES (?,?,?,?,?,?)'
    ).run(id, a.kind, regNo, a.title, a.jrwa ?? null, a.customerId ?? null);
    if (picked) addVersion(ctx, id, 1, picked);
    audit(ctx, 'document', id, 'register', { regNo, title: a.title, kind: a.kind });
    enqueue(ctx, 'ezd.register', { id, regNo, kind: a.kind, title: a.title, jrwa: a.jrwa ?? null });
  });
  tx();
  return get(ctx, id);
}

function addVersion(ctx: Ctx, docId: string, version: number, src: string): void {
  const sha256 = createHash('sha256').update(readFileSync(src)).digest('hex');
  const dir = join(ctx.filesDir, docId, `v${version}`);
  mkdirSync(dir, { recursive: true });
  const dst = join(dir, basename(src));
  copyFileSync(src, dst);
  ctx.db.prepare(
    'INSERT INTO document_version(document_id, version, sha256, file_name, stored_path) VALUES (?,?,?,?,?)'
  ).run(docId, version, sha256, basename(src), dst);
  audit(ctx, 'document', docId, 'version', { version, sha256, file: basename(src) });
}

export function advance(ctx: Ctx, a: In<'ezd:advance'>): Doc {
  const doc = get(ctx, a.id);
  if (!FLOW[doc.stage].includes(a.to)) {
    throw new Error(`Niedozwolone przejście: ${doc.stage} → ${a.to}`);
  }
  ctx.db.transaction(() => {
    ctx.db.prepare("UPDATE document SET stage=?, updated_at=datetime('now') WHERE id=?").run(a.to, a.id);
    audit(ctx, 'document', a.id, 'stage', { from: doc.stage, to: a.to });
    enqueue(ctx, 'ezd.stage', { id: a.id, to: a.to });
  })();
  if (a.to === 'in_review') notify(ctx, 'Dokument do akceptacji', `${doc.regNo}: ${doc.title}`, `ezd:${a.id}`);
  return get(ctx, a.id);
}

export async function sign(ctx: Ctx, a: In<'ezd:sign'>): Promise<Doc> {
  const doc = get(ctx, a.id);
  if (doc.stage !== 'approved') throw new Error('Podpisać można tylko dokument zaakceptowany');
  const ver = ctx.db.prepare(
    'SELECT stored_path, version FROM document_version WHERE document_id=? ORDER BY version DESC LIMIT 1'
  ).get(a.id) as { stored_path: string; version: number } | undefined;
  if (!ver) throw new Error('Dokument nie ma pliku do podpisania');
  const signed = await getSigner(a.signer).signPades(readFileSync(ver.stored_path));
  const out = join(ctx.filesDir, a.id, `v${ver.version + 1}`);
  mkdirSync(out, { recursive: true });
  const dst = join(out, 'podpisany.pdf');
  (await import('node:fs')).writeFileSync(dst, signed);
  const sha256 = createHash('sha256').update(signed).digest('hex');
  ctx.db.transaction(() => {
    ctx.db.prepare(
      'INSERT INTO document_version(document_id, version, sha256, file_name, stored_path) VALUES (?,?,?,?,?)'
    ).run(a.id, ver.version + 1, sha256, 'podpisany.pdf', dst);
    ctx.db.prepare("UPDATE document SET stage='signed', version=?, updated_at=datetime('now') WHERE id=?").run(ver.version + 1, a.id);
    audit(ctx, 'document', a.id, 'sign', { signer: a.signer, sha256 });
    enqueue(ctx, 'ezd.sign', { id: a.id, signer: a.signer, sha256 });
  })();
  return get(ctx, a.id);
}

export function auditLog(ctx: Ctx, a: In<'ezd:audit'>): AuditEntry[] {
  return (ctx.db.prepare('SELECT id, at, actor, action, detail FROM audit WHERE entity_id=? ORDER BY at DESC, rowid DESC').all(a.entityId) as AuditEntry[]);
}

export function versions(ctx: Ctx, a: In<'ezd:versions'>): DocVersion[] {
  return ctx.db.prepare(
    'SELECT version, sha256, file_name AS fileName, at FROM document_version WHERE document_id=? ORDER BY version DESC'
  ).all(a.id) as DocVersion[];
}
