import { app, Menu, type BrowserWindow, type MenuItemConstructorOptions } from 'electron';

/** Natywne menu po polsku (role Electrona używają języka systemu, więc etykiety podajemy jawnie) + skróty nawigacji. */
export function installMenu(win: () => BrowserWindow | null): void {
  const go = (route: string) => () => win()?.webContents.send('nav', route);
  const isMac = process.platform === 'darwin';
  const template: MenuItemConstructorOptions[] = [
    ...(isMac ? [{
      label: app.name,
      submenu: [
        { role: 'about', label: `O aplikacji ${app.name}` },
        { type: 'separator' },
        { role: 'services', label: 'Usługi' },
        { type: 'separator' },
        { role: 'hide', label: `Ukryj ${app.name}` },
        { role: 'hideOthers', label: 'Ukryj pozostałe' },
        { role: 'unhide', label: 'Pokaż wszystkie' },
        { type: 'separator' },
        { role: 'quit', label: `Zakończ ${app.name}` }
      ]
    } as MenuItemConstructorOptions] : []),
    { label: 'Plik', submenu: [isMac ? { role: 'close', label: 'Zamknij okno' } : { role: 'quit', label: 'Zakończ' }] },
    {
      label: 'Edycja',
      submenu: [
        { role: 'undo', label: 'Cofnij' }, { role: 'redo', label: 'Ponów' }, { type: 'separator' },
        { role: 'cut', label: 'Wytnij' }, { role: 'copy', label: 'Kopiuj' }, { role: 'paste', label: 'Wklej' },
        { role: 'selectAll', label: 'Zaznacz wszystko' }
      ]
    },
    {
      label: 'Przejdź',
      submenu: [
        { label: 'Pulpit', accelerator: 'CmdOrCtrl+1', click: go('/') },
        { label: 'Skanowanie do EZD', accelerator: 'CmdOrCtrl+Shift+S', click: go('/scan') },
        { label: 'Rejestr dokumentów', accelerator: 'CmdOrCtrl+2', click: go('/ezd') },
        { label: 'Szybka lista kontaktów', accelerator: 'CmdOrCtrl+Shift+K', click: go('/contacts') },
        { label: 'Kontrahenci', accelerator: 'CmdOrCtrl+3', click: go('/crm') },
        { label: 'Sprawy', accelerator: 'CmdOrCtrl+4', click: go('/deals') },
        { label: 'Powiadomienia', accelerator: 'CmdOrCtrl+Shift+N', click: go('/notifications') }
      ]
    },
    {
      label: 'Widok',
      submenu: [
        { role: 'resetZoom', label: 'Rzeczywisty rozmiar' }, { role: 'zoomIn', label: 'Powiększ' }, { role: 'zoomOut', label: 'Pomniejsz' },
        { type: 'separator' }, { role: 'togglefullscreen', label: 'Pełny ekran' },
        ...(app.isPackaged ? [] : [{ type: 'separator' } as MenuItemConstructorOptions, { role: 'toggleDevTools', label: 'Narzędzia deweloperskie' } as MenuItemConstructorOptions])
      ]
    },
    {
      label: 'Okno',
      submenu: [
        { role: 'minimize', label: 'Zminimalizuj' }, { role: 'zoom', label: 'Powiększ okno' },
        ...(isMac ? [{ type: 'separator' } as MenuItemConstructorOptions, { role: 'front', label: 'Przesuń wszystko na wierzch' } as MenuItemConstructorOptions] : [{ role: 'close', label: 'Zamknij' } as MenuItemConstructorOptions])
      ]
    }
  ];
  Menu.setApplicationMenu(Menu.buildFromTemplate(template));
}
