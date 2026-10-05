import { contextBridge, ipcRenderer } from 'electron';
import type { Api, Channel, Notification } from '@shared/contracts';

const call = <T>(ch: Channel, arg: unknown = {}): Promise<T> => ipcRenderer.invoke(ch, arg);

const api: Api = {
  ezd: {
    list: (a) => call('ezd:list', a), register: (a) => call('ezd:register', a),
    advance: (a) => call('ezd:advance', a), audit: (a) => call('ezd:audit', a),
    versions: (a) => call('ezd:versions', a), sign: (a) => call('ezd:sign', a)
  },
  crm: {
    customers: (a) => call('crm:customers', a), upsertCustomer: (a) => call('crm:upsertCustomer', a),
    deals: (a) => call('crm:deals', a), addDeal: (a) => call('crm:addDeal', a),
    moveDeal: (a) => call('crm:moveDeal', a), linkDocument: (a) => call('crm:linkDocument', a),
    addNote: (a) => call('crm:addNote', a), history: (a) => call('crm:history', a)
  },
  scan: {
    scanners: () => call('scanner:list'), caps: (a) => call('scanner:caps', a), start: (a) => call('scan:start', a),
    importFiles: () => call('scan:import'), list: () => call('scan:list'), remove: (a) => call('scan:delete', a),
    rename: (a) => call('scan:rename', a), open: (a) => call('scan:open', a), upload: (a) => call('scan:upload', a)
  },
  auth: { login: () => call('auth:login'), cancel: () => call('auth:cancel'), logout: () => call('auth:logout') },
  server: {
    status: () => call('server:status'), setToken: (a) => call('server:setToken', a),
    sprawy: (a) => call('server:sprawy', a), teczki: (a) => call('server:teczki', a), contacts: (a) => call('server:contacts', a), info: () => call('server:info'),
    rpw: (a) => call('server:rpw', a), pisma: (a) => call('server:pisma', a)
  },
  mail: { sync: () => call('mail:sync') },
  notif: {
    list: () => call('notif:list'),
    markRead: (id) => call('notif:markRead', { id }),
    onNew: (cb) => {
      const h = (_: unknown, n: Notification) => cb(n);
      ipcRenderer.on('notif:new', h);
      return () => ipcRenderer.removeListener('notif:new', h);
    }
  },
  nav: {
    on: (cb) => {
      const h = (_: unknown, r: string) => cb(r);
      ipcRenderer.on('nav', h);
      return () => ipcRenderer.removeListener('nav', h);
    }
  },
  settings: { get: () => call('settings:get'), set: (a) => call('settings:set', a) }
};

contextBridge.exposeInMainWorld('api', api);
