import { ipcMain, type IpcMainInvokeEvent } from 'electron';
import { schemas, type Channel } from '@shared/contracts';
import type { Ctx } from './services/context';
import * as ezd from './services/ezd';
import * as crm from './services/crm';
import * as mail from './services/mail';
import * as scans from './services/scans';
import * as auth from './services/auth';
import * as notif from './services/notifications';
import { getSettings, setSettings } from './services/settings';

type Handler = (ctx: Ctx, arg: never) => unknown;

/** Każdy kanał: walidacja Zod → serwis. Renderer jest niezaufany. */
const handlers: Record<Channel, Handler> = {
  'ezd:list': ezd.list, 'ezd:register': ezd.register, 'ezd:advance': ezd.advance,
  'ezd:audit': ezd.auditLog, 'ezd:versions': ezd.versions, 'ezd:sign': ezd.sign,
  'crm:customers': crm.customers, 'crm:upsertCustomer': crm.upsertCustomer, 'crm:deals': crm.deals,
  'crm:addDeal': crm.addDeal, 'crm:moveDeal': crm.moveDeal, 'crm:linkDocument': crm.linkDocument,
  'crm:addNote': crm.addNote, 'crm:history': crm.history,
  'scanner:list': () => scans.scanners(), 'scanner:caps': (_c, a) => scans.caps(a as never),
  'scan:start': scans.start, 'scan:import': scans.importFiles, 'scan:list': scans.list,
  'scan:delete': scans.remove, 'scan:rename': scans.rename, 'scan:open': scans.open, 'scan:upload': scans.upload,
  'auth:login': auth.login as Handler, 'auth:cancel': (() => auth.cancelLogin()) as Handler, 'auth:logout': auth.logout as Handler,
  'server:status': scans.serverStatus, 'server:setToken': scans.setToken as Handler,
  'server:sprawy': scans.sprawy, 'server:teczki': scans.teczki, 'server:contacts': scans.contacts, 'server:info': (c: Ctx) => scans.serverInfo(c), 'server:rpw': scans.rpw, 'server:pisma': scans.pisma,
  'mail:sync': (ctx) => mail.sync(ctx),
  'notif:list': (ctx) => notif.list(ctx),
  'notif:markRead': ((ctx: Ctx, a: { id?: string }) => notif.markRead(ctx, a.id)) as Handler,
  'settings:get': (ctx) => getSettings(ctx),
  'settings:set': setSettings as Handler
};

export function registerIpc(ctx: Ctx, isTrusted: (e: IpcMainInvokeEvent) => boolean): void {
  for (const channel of Object.keys(handlers) as Channel[]) {
    ipcMain.handle(channel, async (e, raw) => {
      if (!isTrusted(e)) throw new Error('Niezaufane źródło wywołania IPC');
      const arg = schemas[channel].parse(raw ?? {});
      return (handlers[channel] as (c: Ctx, a: unknown) => unknown)(ctx, arg);
    });
  }
}
