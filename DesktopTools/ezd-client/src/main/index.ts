import { app, BrowserWindow, dialog, Notification as SysNotification, session, shell } from 'electron';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { openDb } from './db';
import { getDbKeyHex } from './security/keystore';
import { registerIpc } from './ipc';
import { installMenu } from './menu';
import { startSyncLoop } from './services/sync';
import { renewIfNeeded } from './services/auth';
import { getSettings } from './services/settings';
import type { Ctx } from './services/context';
import type { Notification } from '@shared/contracts';

const DEV_URL = process.env['ELECTRON_RENDERER_URL'];
let win: BrowserWindow | null = null;

if (!app.requestSingleInstanceLock()) app.quit();
app.on('second-instance', () => { win?.restore(); win?.focus(); });

function createWindow(): BrowserWindow {
  const w = new BrowserWindow({
    width: 1280, height: 820, minWidth: 900, minHeight: 600, show: false,
    title: 'EZD Client',
    webPreferences: {
      preload: join(__dirname, '../preload/index.js'),
      contextIsolation: true, sandbox: true, nodeIntegration: false, webSecurity: true
    }
  });
  w.once('ready-to-show', () => w.show());
  // Blokada nawigacji poza aplikację i nowych okien — linki zewnętrzne tylko https w przeglądarce systemowej.
  w.webContents.on('will-navigate', (e, url) => { if (!DEV_URL || !url.startsWith(DEV_URL)) e.preventDefault(); });
  w.webContents.setWindowOpenHandler(({ url }) => {
    if (url.startsWith('https://')) void shell.openExternal(url);
    return { action: 'deny' };
  });
  if (DEV_URL) void w.loadURL(DEV_URL);
  else void w.loadFile(join(__dirname, '../renderer/index.html'));
  return w;
}

app.whenReady().then(() => {
  const csp = [
    "default-src 'self'", `script-src 'self'${DEV_URL ? " 'unsafe-inline'" : ''}`, "style-src 'self' 'unsafe-inline'",
    "img-src 'self' data:", `connect-src 'self'${DEV_URL ? ' ws://localhost:*' : ''}`,
    "object-src 'none'", "base-uri 'none'", "frame-ancestors 'none'"
  ].join('; ');
  session.defaultSession.webRequest.onHeadersReceived((d, cb) =>
    cb({ responseHeaders: { ...d.responseHeaders, 'Content-Security-Policy': [csp] } }));
  session.defaultSession.setPermissionRequestHandler((_wc, _perm, cb) => cb(false));

  const userData = app.getPath('userData');
  const filesDir = join(userData, 'files');
  mkdirSync(filesDir, { recursive: true });
  const db = openDb(join(userData, 'ezd-client.db'), getDbKeyHex());

  const ctx: Ctx = {
    db, filesDir, actor: '',
    emit: (n: Notification) => {
      win?.webContents.send('notif:new', n);
      if (SysNotification.isSupported() && !win?.isFocused()) new SysNotification({ title: n.title, body: n.body }).show();
    }
  };
  ctx.actor = getSettings(ctx).user;

  win = createWindow();
  win.on('closed', () => { win = null; });
  registerIpc(ctx, (e) => e.sender === win?.webContents);
  installMenu(() => win);
  void renewIfNeeded(ctx);
  const stopSync = startSyncLoop(ctx);
  app.on('before-quit', () => { stopSync(); db.close(); });
}).catch((e: unknown) => {
  console.error('Błąd startu aplikacji:', e);
  dialog.showErrorBox('EZD Client — błąd uruchomienia', e instanceof Error ? e.message : String(e));
  app.quit();
});

app.on('window-all-closed', () => { if (process.platform !== 'darwin') app.quit(); });
app.on('activate', () => { if (BrowserWindow.getAllWindows().length === 0) win = createWindow(); });
