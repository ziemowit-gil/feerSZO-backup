import { app, safeStorage } from 'electron';
import { randomBytes } from 'node:crypto';
import { existsSync, readFileSync, unlinkSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

/**
 * Klucz SQLCipher (256 bit) jest losowy i przechowywany zaszyfrowany przez safeStorage
 * (macOS Keychain / Windows DPAPI). Na dysku nigdy nie leży jawnie.
 */
export function getDbKeyHex(): string {
  if (!safeStorage.isEncryptionAvailable()) {
    throw new Error('Systemowy sejf kluczy jest niedostępny — nie można bezpiecznie otworzyć bazy.');
  }
  const file = join(app.getPath('userData'), 'db.key');
  if (existsSync(file)) return safeStorage.decryptString(readFileSync(file));
  const hex = randomBytes(32).toString('hex');
  writeFileSync(file, safeStorage.encryptString(hex), { mode: 0o600 });
  return hex;
}

const tokenFile = () => join(app.getPath('userData'), 'api.token');

export function saveApiToken(token: string): void {
  writeFileSync(tokenFile(), safeStorage.encryptString(token), { mode: 0o600 });
}

export function clearApiToken(): void {
  if (existsSync(tokenFile())) unlinkSync(tokenFile());
}

export function loadApiToken(): string | null {
  return existsSync(tokenFile()) ? safeStorage.decryptString(readFileSync(tokenFile())) : null;
}

const secretFile = (name: string) => join(app.getPath('userData'), `${name.replace(/[^a-z0-9_-]/gi, '')}.secret`);

export function saveSecret(name: string, value: string): void {
  writeFileSync(secretFile(name), safeStorage.encryptString(value), { mode: 0o600 });
}

export function loadSecret(name: string): string | null {
  return existsSync(secretFile(name)) ? safeStorage.decryptString(readFileSync(secretFile(name))) : null;
}
