import { app, Menu, type BrowserWindow, type MenuItemConstructorOptions } from 'electron';

/** Natywne menu + skróty; nawigacja idzie do renderera kanałem `nav`. */
export function installMenu(win: () => BrowserWindow | null): void {
  const go = (route: string) => () => win()?.webContents.send('nav', route);
  const isMac = process.platform === 'darwin';
  const template: MenuItemConstructorOptions[] = [
    ...(isMac ? [{ role: 'appMenu' } as MenuItemConstructorOptions] : []),
    { role: 'fileMenu' },
    { role: 'editMenu' },
    {
      label: 'Przejdź',
      submenu: [
        { label: 'Pulpit', accelerator: 'CmdOrCtrl+1', click: go('/') },
        { label: 'Skanowanie do EZD', accelerator: 'CmdOrCtrl+Shift+S', click: go('/scan') },
        { label: 'EZD — rejestr', accelerator: 'CmdOrCtrl+2', click: go('/ezd') },
        { label: 'Szybka lista kontaktów', accelerator: 'CmdOrCtrl+Shift+K', click: go('/contacts') },
        { label: 'CRM — kontrahenci', accelerator: 'CmdOrCtrl+3', click: go('/crm') },
        { label: 'CRM — sprawy', accelerator: 'CmdOrCtrl+4', click: go('/deals') },
        { label: 'Powiadomienia', accelerator: 'CmdOrCtrl+Shift+N', click: go('/notifications') }
      ]
    },
    { role: 'viewMenu' },
    { role: 'windowMenu' }
  ];
  Menu.setApplicationMenu(Menu.buildFromTemplate(template));
  void app;
}
