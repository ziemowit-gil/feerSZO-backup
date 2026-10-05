import os from 'node:os';
import { DEFAULT_SERVER_URL } from '@shared/config';
import type { In, Settings } from '@shared/contracts';
import type { Ctx } from './context';

const DEFAULTS: Settings = {
  theme: 'system', fontScale: 1, density: 'comfortable', serverUrl: DEFAULT_SERVER_URL, user: os.userInfo().username, openAfterUpload: true
};

export function getSettings(ctx: Ctx): Settings {
  const rows = ctx.db.prepare('SELECT key, value FROM setting').all() as { key: string; value: string }[];
  const out: Record<string, unknown> = { ...DEFAULTS };
  for (const r of rows) {
    try { out[r.key] = JSON.parse(r.value); } catch { /* pomiń uszkodzony wpis */ }
  }
  return out as unknown as Settings;
}

export function setSettings(ctx: Ctx, a: In<'settings:set'>): Settings {
  const stmt = ctx.db.prepare('INSERT INTO setting(key, value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value');
  ctx.db.transaction(() => {
    for (const [k, v] of Object.entries(a)) if (v !== undefined) stmt.run(k, JSON.stringify(v));
  })();
  return getSettings(ctx);
}
