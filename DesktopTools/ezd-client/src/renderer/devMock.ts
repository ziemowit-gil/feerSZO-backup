/**
 * Atrapa window.api — TYLKO do podglądu UI w zwykłej przeglądarce (npm run preview) i testów a11y.
 * Ładowana wyłącznie w trybie DEV, gdy brak prawdziwego preloadu Electrona.
 */
import type { Api, Customer, Deal, Doc, Notification, ScanRec, Settings } from '@shared/contracts';

const now = '2026-10-05 21:30';
let settings: Settings = { theme: 'system', fontScale: 1, density: 'comfortable', serverUrl: 'https://szo.feer.org.pl', user: 'jan', openAfterUpload: true };
const wait = <T,>(v: T, ms = 120): Promise<T> => new Promise((r) => setTimeout(() => r(v), ms));

const docs: Doc[] = [
  { id: 'd1', kind: 'incoming', regNo: 'PRZ/2026/1', title: 'Zapytanie ofertowe ACME', stage: 'in_review', jrwa: null, customerId: 'c1', version: 1, updatedAt: now },
  { id: 'd2', kind: 'outgoing', regNo: 'WYC/2026/1', title: 'Odpowiedź na reklamację', stage: 'registered', jrwa: null, customerId: null, version: 1, updatedAt: now },
  { id: 'd3', kind: 'internal', regNo: 'WEW/2026/1', title: 'Notatka służbowa — wdrożenie', stage: 'signed', jrwa: null, customerId: null, version: 2, updatedAt: now }
];
const customers: Customer[] = [
  { id: 'c1', kind: 'b2b', name: 'ACME Sp. z o.o.', nip: '1234567890', email: 'biuro@acme.pl', phone: '+48 22 100 20 30', updatedAt: now },
  { id: 'c2', kind: 'b2c', name: 'Jan Kowalski', nip: null, email: 'jan@example.pl', phone: null, updatedAt: now }
];
const deals: Deal[] = [
  { id: 'dl1', customerId: 'c1', customerName: 'ACME Sp. z o.o.', title: 'Wdrożenie EZD', stage: 'proposal', valuePln: 48000, updatedAt: now },
  { id: 'dl2', customerId: 'c1', customerName: 'ACME Sp. z o.o.', title: 'Szkolenie', stage: 'lead', valuePln: 6000, updatedAt: now },
  { id: 'dl3', customerId: 'c2', customerName: 'Jan Kowalski', title: 'Konsultacja', stage: 'won', valuePln: 900, updatedAt: now }
];
let scans: ScanRec[] = [
  { id: 's1', name: 'Skan 202610052012.pdf', pages: 3, size: 482_000, status: 'new', error: null, serverRef: null, createdAt: now, uploadedAt: null },
  { id: 's2', name: 'Umowa najmu.pdf', pages: 12, size: 3_200_000, status: 'error', error: 'Serwer: HTTP 503', serverRef: null, createdAt: now, uploadedAt: null },
  { id: 's3', name: 'Faktura 4-2026.pdf', pages: 1, size: 182_000, status: 'uploaded', error: null, serverRef: 'sprawa:5/zal:9', createdAt: now, uploadedAt: now }
];
let notifs: Notification[] = [
  { id: 'n1', at: now, title: 'Dokument do akceptacji', body: 'PRZ/2026/1: Zapytanie ofertowe ACME', read: false, link: null },
  { id: 'n2', at: now, title: 'Skan wgrany do EZD', body: 'Faktura 4-2026.pdf → koszulka #5', read: true, link: null }
];
const listeners = new Set<(n: Notification) => void>();

export const mockApi: Api = {
  ezd: {
    list: async (a) => wait(docs.filter((d) => (!a.kind || d.kind === a.kind) && (!a.q || d.title.toLowerCase().includes(a.q.toLowerCase())))),
    register: async (a) => wait({ ...docs[0]!, id: 'dn', title: a.title, kind: a.kind }),
    advance: async (a) => wait({ ...docs[0]!, stage: a.to }),
    audit: async () => wait([{ id: 'a1', at: now, actor: 'jan', action: 'stage', detail: '{"to":"in_review"}' }, { id: 'a0', at: now, actor: 'jan', action: 'register', detail: null }]),
    versions: async () => wait([{ version: 1, sha256: 'ab12cd34ef56ab12cd34', fileName: 'zapytanie.pdf', at: now }]),
    sign: async () => { throw new Error('Podpis „pkcs11” nie jest jeszcze skonfigurowany.'); }
  },
  crm: {
    customers: async (a) => wait(customers.filter((c) => !a.q || c.name.toLowerCase().includes(a.q.toLowerCase()))),
    upsertCustomer: async (a) => wait({ ...customers[0]!, ...a, id: a.id ?? 'cn', nip: a.nip ?? null, email: a.email ?? null, phone: a.phone ?? null }),
    deals: async () => wait(deals),
    addDeal: async (a) => wait({ ...deals[0]!, title: a.title, id: 'dln' }),
    moveDeal: async (a) => wait({ ...deals[0]!, id: a.id, stage: a.stage }),
    linkDocument: async () => wait(undefined),
    addNote: async () => wait(undefined),
    history: async () => wait([{ at: now, type: 'note' as const, text: 'Rozmowa telefoniczna, prośba o wycenę.' }, { at: now, type: 'document' as const, text: 'PRZ/2026/1 — Zapytanie ofertowe ACME' }])
  },
  scan: {
    scanners: async () => wait([{ id: '192.168.88.246:80', name: 'Brother MFC-B7810DW', host: '192.168.88.246', port: 80, secure: false }], 300),
    caps: async () => wait({ sources: ['Platen', 'Feeder'] as ('Platen' | 'Feeder')[], duplex: true, colors: ['RGB24', 'Grayscale8'], resolutions: [100, 200, 300, 600] }),
    start: async () => { const r: ScanRec = { id: `s${scans.length + 1}`, name: 'Skan nowy.pdf', pages: 2, size: 300_000, status: 'new', error: null, serverRef: null, createdAt: now, uploadedAt: null }; scans = [r, ...scans]; return wait(r, 800); },
    importFiles: async () => wait([]),
    list: async () => wait(scans),
    remove: async (a) => { scans = scans.filter((s) => s.id !== a.id); return wait(undefined); },
    rename: async (a) => wait({ ...scans[0]!, name: a.name }),
    open: async () => wait(undefined),
    upload: async (a) => { scans = scans.map((s) => (s.id === a.id ? { ...s, status: 'uploaded' as const, error: null } : s)); return wait(scans.find((s) => s.id === a.id)!, 500); }
  },
  auth: { login: async () => wait({ name: 'Jan Kowalski', email: 'jan@feer.org.pl', expiresAt: '2026-11-04 12:00:00', scopes: ['ezd:read'] }, 800), cancel: async () => undefined, logout: async () => undefined },
  server: {
    status: async () => wait({ url: settings.serverUrl, hasToken: !new URLSearchParams(location.search).has('setup'), account: { name: 'Jan Kowalski', email: 'jan@feer.org.pl', expiresAt: '2026-11-04 12:00:00', scopes: ['ezd:read', 'ezd:upload', 'ezd:write', 'crm:read'] } }),
    setToken: async () => wait({ version: '15.0a', org: 'FEER', keyName: 'klucz', owner: 'Jan Kowalski', permissions: ['ezd:read'], expiresAt: null, maxUploadBytes: 26_214_400 }),
    contacts: async (a) => wait([
      { id: 1, type: 'organizacja', name: 'ACME Sp. z o.o.', org: 'ACME Sp. z o.o.', email: 'biuro@acme.pl', phone: '+48 22 100 20 30', position: '' },
      { id: 2, type: 'osoba', name: 'Anna Nowak', org: 'Fundacja Dostępni', email: 'anna@dostepni.pl', phone: '', position: 'Koordynatorka' }
    ].filter((c) => !a.q || c.name.toLowerCase().includes(a.q.toLowerCase()))),
    info: async () => wait({ version: '15.0a', org: 'FEER', keyName: 'EZD Client — Jan Kowalski', owner: 'Jan Kowalski', permissions: ['ezd:read', 'ezd:upload'], expiresAt: null, maxUploadBytes: 26_214_400 }),
    teczki: async () => wait([{ id: 1, symbol: '142', title: 'Korespondencja ogólna', rok: 2026 }, { id: 2, symbol: '210', title: 'Umowy i porozumienia', rok: 2026 }]),
    rpw: async () => wait([{ id: 7, label: 'RPW 7/2026 (List polecony)', nadawca: 'Urząd Miasta', opis: 'Wezwanie do uzupełnienia', status: 'nowa' }]),
    sprawy: async () => wait([{ id: 5, znak: '142.5.2026', title: 'Reklamacja usługi' }, { id: 6, znak: '210.2.2026', title: 'Umowa najmu lokalu' }]),
    pisma: async () => wait([{ id: 11, sygnatura: '142.5.2026.P1', title: 'Pismo reklamacyjne' }])
  },
  mail: { sync: async () => ({ fetched: 0 }) },
  notif: {
    list: async () => wait(notifs),
    markRead: async (id) => { notifs = notifs.map((n) => (!id || n.id === id ? { ...n, read: true } : n)); return wait(undefined); },
    onNew: (cb) => { listeners.add(cb); return () => listeners.delete(cb); }
  },
  nav: { on: () => () => undefined },
  settings: {
    get: async () => wait(settings),
    set: async (a) => { settings = { ...settings, ...Object.fromEntries(Object.entries(a).filter(([, v]) => v !== undefined)) } as Settings; return wait(settings, 20); }
  }
};

(window as unknown as { api: Api }).api = mockApi;
