import Database from 'better-sqlite3-multiple-ciphers';
import { MIGRATIONS } from './migrations';

export type Db = Database.Database;

export function openDb(path: string, keyHex: string): Db {
  if (!/^[0-9a-f]{64}$/.test(keyHex)) throw new Error('Nieprawidłowy klucz bazy');
  const db = new Database(path);
  db.pragma("cipher='sqlcipher'");
  db.pragma('legacy=4');
  db.pragma(`key="x'${keyHex}'"`); // klucz surowy (bez KDF)
  try {
    db.prepare('SELECT count(*) FROM sqlite_master').get(); // weryfikacja klucza
  } catch {
    db.close();
    throw new Error('Nie można odszyfrować bazy (zły klucz lub uszkodzony plik).');
  }
  db.pragma('journal_mode = WAL');
  db.pragma('foreign_keys = ON');
  migrate(db);
  return db;
}

export function migrate(db: Db): void {
  const current = db.pragma('user_version', { simple: true }) as number;
  for (let i = current; i < MIGRATIONS.length; i++) {
    db.transaction(() => {
      db.exec(MIGRATIONS[i]!);
      db.pragma(`user_version = ${i + 1}`);
    })();
  }
}
