import { useEffect, useRef, useState, type ReactNode } from 'react';
import type { Account, Settings } from '@shared/contracts';
import { Contacts } from './modules/Contacts';
import { Customers } from './modules/Customers';
import { Dashboard } from './modules/Dashboard';
import { Deals } from './modules/Deals';
import { Ezd } from './modules/Ezd';
import { Notifications } from './modules/Notifications';
import { Scanner } from './modules/Scanner';
import { Setup } from './modules/Setup';
import { Icon, useConfirm } from './ui';

interface Route { path: string; label: string; icon: string; group?: string; el: ReactNode }
const ROUTES: Route[] = [
  { path: '/', label: 'Pulpit', icon: 'home', el: <Dashboard /> },
  { path: '/scan', label: 'Skanowanie do EZD', icon: 'scan', group: 'EZD', el: <Scanner /> },
  { path: '/ezd', label: 'Rejestr dokumentów', icon: 'doc', el: <Ezd /> },
  { path: '/contacts', label: 'Szybka lista kontaktów', icon: 'search', group: 'CRM', el: <Contacts /> },
  { path: '/crm', label: 'Kontrahenci', icon: 'users', el: <Customers /> },
  { path: '/deals', label: 'Sprawy', icon: 'deal', el: <Deals /> },
  { path: '/notifications', label: 'Powiadomienia', icon: 'bell', group: 'Inne', el: <Notifications /> }
];

function applySettings(s: Settings): void {
  const root = document.documentElement;
  if (s.theme === 'system') root.removeAttribute('data-theme'); else root.dataset['theme'] = s.theme;
  root.dataset['density'] = s.density;
  root.style.setProperty('--font-scale', String(s.fontScale));
}

export function App() {
  const [route, setRoute] = useState('/');
  const [settings, setSettings] = useState<Settings>();
  const [unread, setUnread] = useState(0);
  const [announce, setAnnounce] = useState('');
  const [setup, setSetup] = useState<{ needed: boolean; url: string }>();
  const [account, setAccount] = useState<Account | null>(null);
  const main = useRef<HTMLElement>(null);
  const [confirm, confirmNode] = useConfirm();

  useEffect(() => {
    void window.api.settings.get().then((s) => { setSettings(s); applySettings(s); });
    void window.api.server.status().then((st) => { setSetup({ needed: !st.hasToken, url: st.url }); setAccount(st.account); });
    const refresh = () => window.api.notif.list().then((l) => setUnread(l.filter((n) => !n.read).length));
    void refresh();
    const offNav = window.api.nav.on(setRoute);
    const onHash = () => { const h = location.hash.slice(1); if (ROUTES.some((r) => r.path === h)) setRoute(h); };
    window.addEventListener('hashchange', onHash);
    const offNotif = window.api.notif.onNew((n) => { setAnnounce(`${n.title}. ${n.body}`); void refresh(); });
    return () => { offNav(); offNotif(); window.removeEventListener('hashchange', onHash); };
  }, []);

  // Po zmianie widoku fokus trafia na nagłówek h1 — czytnik ekranu ogłasza nowy widok, klawiatura startuje od treści.
  useEffect(() => {
    if (!setup || setup.needed) return;
    const t = setTimeout(() => (main.current?.querySelector<HTMLElement>('h1') ?? main.current)?.focus(), 30);
    return () => clearTimeout(t);
  }, [route, setup]);

  if (!setup) return null;
  if (setup.needed) return <Setup defaultUrl={setup.url} onDone={() => { setSetup({ ...setup, needed: false }); void window.api.server.status().then((st) => setAccount(st.account)); }} />;

  const update = async (patch: Partial<Settings>) => {
    const s = await window.api.settings.set(patch);
    setSettings(s); applySettings(s);
  };
  const logout = async () => {
    if (!(await confirm({ title: 'Wylogować z aplikacji?', message: 'Token dostępu zostanie unieważniony na serwerze. Skany w kolejce zostaną na tym komputerze.', confirmLabel: 'Wyloguj', danger: true }))) return;
    await window.api.auth.logout();
    setAccount(null); setSetup({ ...setup, needed: true });
  };
  const current = ROUTES.find((r) => r.path === route) ?? ROUTES[0]!;

  return (
    <div className="shell">
      <a className="skip" href="#main" onClick={(e) => { e.preventDefault(); main.current?.focus(); }}>Przejdź do treści</a>
      <aside className="sidebar" aria-label="Pasek boczny">
        <div className="brand"><span className="brand-mark"><Icon name="scan" size={18} /></span><span>EZD Client</span></div>
        <nav aria-label="Moduły">
          <ul>
            {ROUTES.map((r) => (
              <li key={r.path}>
                {r.group && <div className="nav-group" aria-hidden="true">{r.group}</div>}
                <a href={`#${r.path}`} aria-current={r.path === current.path ? 'page' : undefined}
                   onClick={(e) => { e.preventDefault(); setRoute(r.path); }}>
                  <Icon name={r.icon} /><span className="grow">{r.label}</span>
                  {r.path === '/notifications' && unread > 0 && <span className="count">{unread}<span className="sr-only"> nieprzeczytanych</span></span>}
                </a>
              </li>
            ))}
          </ul>
        </nav>
        <div className="sidebar-foot">
          <details className="prefs">
            <summary><Icon name="settings" />Wygląd</summary>
            <div className="field"><label htmlFor="pref-theme">Motyw</label>
              <select id="pref-theme" value={settings?.theme ?? 'system'} onChange={(e) => void update({ theme: e.target.value as Settings['theme'] })}>
                <option value="system">Zgodny z systemem</option><option value="light">Jasny</option><option value="dark">Ciemny</option>
              </select></div>
            <div className="field"><label htmlFor="pref-scale">Rozmiar tekstu</label>
              <select id="pref-scale" value={settings?.fontScale ?? 1} onChange={(e) => void update({ fontScale: Number(e.target.value) })}>
                <option value={1}>Standardowy (100%)</option><option value={1.15}>Większy (115%)</option><option value={1.3}>Duży (130%)</option><option value={1.5}>Bardzo duży (150%)</option>
              </select></div>
            <div className="field"><label htmlFor="pref-density">Gęstość</label>
              <select id="pref-density" value={settings?.density ?? 'comfortable'} onChange={(e) => void update({ density: e.target.value as Settings['density'] })}>
                <option value="comfortable">Standardowa</option><option value="compact">Zwarta</option>
              </select></div>
          </details>
          {account && <div className="account"><strong>{account.name}</strong><span>{account.email}</span></div>}
          <button onClick={() => void logout()}><Icon name="logout" />Wyloguj</button>
        </div>
      </aside>
      <main id="main" ref={main} tabIndex={-1} aria-label={current.label}><div className="page">{current.el}</div></main>
      <div role="status" aria-live="polite" className="sr-only">{announce}</div>
      {confirmNode}
    </div>
  );
}
