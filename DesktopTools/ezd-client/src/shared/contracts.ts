import { z } from 'zod';

export const DocKind = z.enum(['incoming', 'outgoing', 'internal']);
export const Stage = z.enum(['registered', 'dispatched', 'in_review', 'approved', 'signed', 'archived']);
export const DealStage = z.enum(['lead', 'qualified', 'proposal', 'negotiation', 'won', 'lost']);
export const Theme = z.enum(['system', 'light', 'dark']);

export type DocKind = z.infer<typeof DocKind>;
export type Stage = z.infer<typeof Stage>;
export type DealStage = z.infer<typeof DealStage>;

/** Walidacja wejścia każdego kanału IPC (po stronie main — renderer jest niezaufany). */
export const schemas = {
  'ezd:list': z.object({ q: z.string().max(200).optional(), kind: DocKind.optional() }),
  'ezd:register': z.object({
    kind: DocKind,
    title: z.string().trim().min(1).max(300),
    jrwa: z.string().max(20).optional(),
    customerId: z.string().uuid().optional(),
    attach: z.boolean().default(false) // plik wybiera main przez natywny dialog
  }),
  'ezd:advance': z.object({ id: z.string().uuid(), to: Stage }),
  'ezd:audit': z.object({ entityId: z.string().uuid() }),
  'ezd:versions': z.object({ id: z.string().uuid() }),
  'ezd:sign': z.object({ id: z.string().uuid(), signer: z.enum(['pkcs11', 'trusted_profile', 'edowod']) }),
  'crm:customers': z.object({ q: z.string().max(200).optional() }),
  'crm:upsertCustomer': z.object({
    id: z.string().uuid().optional(),
    kind: z.enum(['b2b', 'b2c']),
    name: z.string().trim().min(1).max(200),
    nip: z.string().regex(/^\d{10}$/).optional(),
    email: z.string().email().optional(),
    phone: z.string().max(30).optional()
  }),
  'crm:deals': z.object({ customerId: z.string().uuid().optional() }),
  'crm:addDeal': z.object({
    customerId: z.string().uuid(),
    title: z.string().trim().min(1).max(200),
    valuePln: z.number().int().min(0).default(0)
  }),
  'crm:moveDeal': z.object({ id: z.string().uuid(), stage: DealStage }),
  'crm:linkDocument': z.object({ documentId: z.string().uuid(), customerId: z.string().uuid() }),
  'crm:addNote': z.object({ customerId: z.string().uuid(), body: z.string().trim().min(1).max(5000) }),
  'crm:history': z.object({ customerId: z.string().uuid() }),
  'scanner:list': z.object({}),
  'scanner:caps': z.object({ id: z.string().min(1) }),
  'scan:start': z.object({
    scannerId: z.string().min(1), source: z.enum(['Platen', 'Feeder']), duplex: z.boolean().default(false),
    color: z.enum(['RGB24', 'Grayscale8']).default('RGB24'), dpi: z.number().int().min(75).max(1200).default(300),
    name: z.string().max(120).optional()
  }),
  'scan:import': z.object({}),
  'scan:list': z.object({}),
  'scan:delete': z.object({ id: z.string().uuid() }),
  'scan:rename': z.object({ id: z.string().uuid(), name: z.string().trim().min(1).max(150) }),
  'scan:open': z.object({ id: z.string().uuid() }),
  'scan:upload': z.object({
    id: z.string().uuid(),
    target: z.discriminatedUnion('type', [
      z.object({ type: z.literal('sprawa'), sprawaId: z.number().int().positive(), pismoId: z.number().int().positive().optional() }),
      z.object({ type: z.literal('newSprawa'), teczkaId: z.number().int().positive(), title: z.string().trim().min(1).max(300) }),
      z.object({ type: z.literal('rpw'), rpwId: z.number().int().positive() }),
      z.object({
        type: z.literal('newRpw'),
        typ: z.enum(['list', 'polecony', 'paczka', 'email', 'epuap', 'fax', 'osobiscie', 'inne']).default('list'),
        nadawca: z.string().trim().max(200).default(''), opis: z.string().trim().max(500).default('')
      })
    ])
  }),
  'auth:login': z.object({}),
  'auth:cancel': z.object({}),
  'auth:logout': z.object({}),
  'server:status': z.object({}),
  'server:setToken': z.object({ token: z.string().trim().min(10).max(200) }),
  'server:sprawy': z.object({ q: z.string().max(100).default('') }),
  'server:teczki': z.object({ q: z.string().max(100).default('') }),
  'server:rpw': z.object({ q: z.string().max(100).default('') }),
  'server:contacts': z.object({ q: z.string().max(100).default('') }),
  'server:info': z.object({}),
  'server:pisma': z.object({ sprawaId: z.number().int().positive() }),
  'mail:sync': z.object({}),
  'notif:list': z.object({}),
  'notif:markRead': z.object({ id: z.string().uuid().optional() }),
  'settings:get': z.object({}),
  'settings:set': z.object({
    theme: Theme.optional(),
    fontScale: z.number().min(0.8).max(2).optional(),
    density: z.enum(['comfortable', 'compact']).optional(),
    serverUrl: z.string().url().optional(),
    openAfterUpload: z.boolean().optional()
  })
} as const;

export type Channel = keyof typeof schemas;
export type In<C extends Channel> = z.input<(typeof schemas)[C]>;

export interface Doc {
  id: string; kind: DocKind; regNo: string | null; title: string; stage: Stage;
  jrwa: string | null; customerId: string | null; version: number; updatedAt: string;
}
export interface Customer {
  id: string; kind: 'b2b' | 'b2c'; name: string; nip: string | null;
  email: string | null; phone: string | null; updatedAt: string;
}
export interface Deal {
  id: string; customerId: string; customerName: string; title: string;
  stage: DealStage; valuePln: number; updatedAt: string;
}
export interface AuditEntry { id: string; at: string; actor: string; action: string; detail: string | null }
export interface DocVersion { version: number; sha256: string; fileName: string; at: string }
export interface Notification { id: string; at: string; title: string; body: string; read: boolean; link: string | null }
export interface HistoryItem { at: string; type: 'note' | 'document' | 'deal' | 'mail'; text: string }
export interface ScannerInfo { id: string; name: string; host: string; port: number; secure: boolean }
export interface ScannerCaps { sources: ('Platen' | 'Feeder')[]; duplex: boolean; colors: string[]; resolutions: number[] }
export interface ScanRec {
  id: string; name: string; pages: number; size: number; status: 'new' | 'uploading' | 'uploaded' | 'error';
  error: string | null; serverRef: string | null; createdAt: string; uploadedAt: string | null;
}
export interface Account { name: string; email: string; expiresAt: string; scopes: string[] }
export interface ServerStatus { url: string; hasToken: boolean; account: Account | null }
export interface SprawaHit { id: number; znak: string; title: string }
export interface ContactHit { id: number; type: string; name: string; org: string; email: string; phone: string; position: string }
export interface ServerInfo { version: string | null; org: string | null; keyName: string; owner: string | null; permissions: string[]; expiresAt: string | null; maxUploadBytes: number }
export interface TeczkaHit { id: number; symbol: string; title: string; rok: number }
export interface RpwHit { id: number; label: string; nadawca: string; opis: string; status: string }
export interface PismoHit { id: number; sygnatura: string; title: string }
export interface Settings {
  theme: z.infer<typeof Theme>; fontScale: number; density: 'comfortable' | 'compact'; serverUrl: string; user: string; openAfterUpload: boolean;
}

export interface Api {
  ezd: {
    list(a: In<'ezd:list'>): Promise<Doc[]>;
    register(a: In<'ezd:register'>): Promise<Doc>;
    advance(a: In<'ezd:advance'>): Promise<Doc>;
    audit(a: In<'ezd:audit'>): Promise<AuditEntry[]>;
    versions(a: In<'ezd:versions'>): Promise<DocVersion[]>;
    sign(a: In<'ezd:sign'>): Promise<Doc>;
  };
  crm: {
    customers(a: In<'crm:customers'>): Promise<Customer[]>;
    upsertCustomer(a: In<'crm:upsertCustomer'>): Promise<Customer>;
    deals(a: In<'crm:deals'>): Promise<Deal[]>;
    addDeal(a: In<'crm:addDeal'>): Promise<Deal>;
    moveDeal(a: In<'crm:moveDeal'>): Promise<Deal>;
    linkDocument(a: In<'crm:linkDocument'>): Promise<void>;
    addNote(a: In<'crm:addNote'>): Promise<void>;
    history(a: In<'crm:history'>): Promise<HistoryItem[]>;
  };
  scan: {
    scanners(): Promise<ScannerInfo[]>;
    caps(a: In<'scanner:caps'>): Promise<ScannerCaps>;
    start(a: In<'scan:start'>): Promise<ScanRec>;
    importFiles(): Promise<ScanRec[]>;
    list(): Promise<ScanRec[]>;
    remove(a: In<'scan:delete'>): Promise<void>;
    rename(a: In<'scan:rename'>): Promise<ScanRec>;
    open(a: In<'scan:open'>): Promise<void>;
    upload(a: In<'scan:upload'>): Promise<ScanRec>;
  };
  auth: { login(): Promise<Account>; cancel(): Promise<void>; logout(): Promise<void> };
  server: {
    status(): Promise<ServerStatus>;
    setToken(a: In<'server:setToken'>): Promise<ServerInfo>;
    sprawy(a: In<'server:sprawy'>): Promise<SprawaHit[]>;
    contacts(a: In<'server:contacts'>): Promise<ContactHit[]>;
    info(): Promise<ServerInfo>;
    teczki(a: In<'server:teczki'>): Promise<TeczkaHit[]>;
    rpw(a: In<'server:rpw'>): Promise<RpwHit[]>;
    pisma(a: In<'server:pisma'>): Promise<PismoHit[]>;
  };
  mail: { sync(): Promise<{ fetched: number }> };
  notif: { list(): Promise<Notification[]>; markRead(id?: string): Promise<void>; onNew(cb: (n: Notification) => void): () => void };
  nav: { on(cb: (route: string) => void): () => void };
  settings: { get(): Promise<Settings>; set(a: In<'settings:set'>): Promise<Settings> };
}
