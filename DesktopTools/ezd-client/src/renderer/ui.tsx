import { useCallback, useEffect, useId, useRef, useState, type KeyboardEvent, type ReactNode } from 'react';

/* ── Ikony (dekoracyjne: aria-hidden; znaczenie zawsze niesie tekst obok) ─────────────────── */
const PATHS: Record<string, string> = {
  home: 'M3 11.5 12 4l9 7.5M5 10v10h5v-6h4v6h5V10',
  scan: 'M4 8V5a1 1 0 0 1 1-1h3M16 4h3a1 1 0 0 1 1 1v3M20 16v3a1 1 0 0 1-1 1h-3M8 20H5a1 1 0 0 1-1-1v-3M4 12h16',
  doc: 'M7 3h7l5 5v12a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1ZM14 3v5h5M9 13h6M9 17h6',
  users: 'M16 19v-1a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v1M10 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7ZM20 19v-1a4 4 0 0 0-3-3.9M15.5 4.2a3.5 3.5 0 0 1 0 6.6',
  deal: 'M3 7h18v12H3zM8 7V5a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M3 13h18',
  bell: 'M6 9a6 6 0 0 1 12 0c0 6 2 7 2 7H4s2-1 2-7ZM10 20a2 2 0 0 0 4 0',
  search: 'M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14ZM20 20l-4-4',
  logout: 'M9 4H5a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h4M16 8l4 4-4 4M20 12H9',
  check: 'm5 12.5 4.5 4.5L19 7.5',
  warn: 'M12 4 2.5 20h19L12 4ZM12 10v4.5M12 17.5v.01',
  x: 'M6 6l12 12M18 6 6 18',
  copy: 'M8 8V5a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1h-3M5 8h10a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1Z',
  upload: 'M12 16V4M7 9l5-5 5 5M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3',
  eye: 'M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12ZM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z',
  trash: 'M4 7h16M10 11v6M14 11v6M6 7l1 12a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-12M9 7V4h6v3',
  plus: 'M12 5v14M5 12h14',
  settings: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM4 12h2M18 12h2M12 4v2M12 18v2M6.3 6.3l1.4 1.4M16.3 16.3l1.4 1.4M17.7 6.3l-1.4 1.4M7.7 16.3l-1.4 1.4'
};
export function Icon({ name, size = 18 }: { name: string; size?: number }) {
  return (
    <svg className="icon" width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"
         strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false">
      <path d={PATHS[name] ?? ''} />
    </svg>
  );
}

export function Spinner() { return <span className="spinner" aria-hidden="true" />; }

/** Nagłówek strony: jedyny h1 widoku — dostaje fokus po zmianie widoku (czytnik ekranu ogłasza tytuł). */
export function PageHeader({ title, lead, actions }: { title: string; lead?: ReactNode; actions?: ReactNode }) {
  return (
    <header className="page-head">
      <div>
        <h1 tabIndex={-1}>{title}</h1>
        {lead && <p className="lead">{lead}</p>}
      </div>
      {actions && <div className="row">{actions}</div>}
    </header>
  );
}

export function Alert({ kind, children, live }: { kind: 'error' | 'ok' | 'info' | 'warn'; children: ReactNode; live?: boolean }) {
  const icon = kind === 'ok' ? 'check' : kind === 'info' ? 'eye' : 'warn';
  return (
    <div className={`alert ${kind}`} role={kind === 'error' ? 'alert' : live ? 'status' : undefined}>
      <Icon name={icon} /><div>{children}</div>
    </div>
  );
}

export function Badge({ tone = 'neutral', children }: { tone?: 'neutral' | 'ok' | 'warn' | 'danger' | 'info'; children: ReactNode }) {
  return <span className={`badge ${tone}`}>{children}</span>;
}

export function EmptyState({ icon, title, children }: { icon: string; title: string; children?: ReactNode }) {
  return (
    <div className="empty">
      <Icon name={icon} size={28} />
      <p className="empty-title">{title}</p>
      {children && <p>{children}</p>}
    </div>
  );
}

/* ── Zakładki (WAI-ARIA Tabs: strzałki, Home/End, roving tabindex) ───────────────────────── */
export function Tabs<T extends string>({ label, tabs, value, onChange, children }: {
  label: string; tabs: readonly (readonly [T, string])[]; value: T; onChange: (v: T) => void; children: ReactNode;
}) {
  const uid = useId();
  const refs = useRef<Record<string, HTMLButtonElement | null>>({});
  const onKey = (e: KeyboardEvent) => {
    const i = tabs.findIndex(([k]) => k === value);
    let n = i;
    if (e.key === 'ArrowRight') n = (i + 1) % tabs.length;
    else if (e.key === 'ArrowLeft') n = (i - 1 + tabs.length) % tabs.length;
    else if (e.key === 'Home') n = 0;
    else if (e.key === 'End') n = tabs.length - 1;
    else return;
    e.preventDefault();
    const k = tabs[n]![0];
    onChange(k);
    refs.current[k]?.focus();
  };
  return (
    <>
      <div role="tablist" aria-label={label} className="tabs" onKeyDown={onKey}>
        {tabs.map(([k, l]) => (
          <button key={k} role="tab" id={`${uid}-t-${k}`} aria-controls={`${uid}-p`} aria-selected={value === k}
                  tabIndex={value === k ? 0 : -1} ref={(el) => { refs.current[k] = el; }} onClick={() => onChange(k)}>{l}</button>
        ))}
      </div>
      <div role="tabpanel" id={`${uid}-p`} aria-labelledby={`${uid}-t-${value}`} tabIndex={0}>{children}</div>
    </>
  );
}

/* ── Lista wyboru (listbox): strzałki zmieniają aktywną pozycję, Enter/Spacja wybiera ───────── */
export function Listbox<T extends { id: number }>({ label, items, selected, onSelect, render, empty = 'Brak wyników.' }: {
  label: string; items: T[]; selected?: number; onSelect: (id: number) => void; render: (i: T) => ReactNode; empty?: string;
}) {
  const uid = useId();
  const [active, setActive] = useState(0);
  useEffect(() => { setActive(0); }, [items]);
  useEffect(() => { document.getElementById(`${uid}-${active}`)?.scrollIntoView?.({ block: 'nearest' }); }, [active, uid]);
  const onKey = (e: KeyboardEvent) => {
    if (!items.length) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); setActive((a) => Math.min(a + 1, items.length - 1)); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setActive((a) => Math.max(a - 1, 0)); }
    else if (e.key === 'Home') { e.preventDefault(); setActive(0); }
    else if (e.key === 'End') { e.preventDefault(); setActive(items.length - 1); }
    else if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onSelect(items[active]!.id); }
  };
  if (!items.length) return <div className="listbox"><div className="empty small" role="status">{empty}</div></div>;
  return (
    <div role="listbox" aria-label={label} tabIndex={0} className="listbox" onKeyDown={onKey} aria-activedescendant={`${uid}-${active}`}>
      {items.map((i, n) => (
        <div key={i.id} id={`${uid}-${n}`} role="option" aria-selected={selected === i.id}
             className={n === active ? 'active' : undefined} onClick={() => { setActive(n); onSelect(i.id); }}>
          {render(i)}{selected === i.id && <Icon name="check" size={16} />}
        </div>
      ))}
    </div>
  );
}

/* ── Potwierdzenie w natywnym <dialog> (pułapkę fokusu i Esc zapewnia przeglądarka) ───────── */
interface ConfirmOpts { title: string; message: string; confirmLabel?: string; danger?: boolean }
export function useConfirm(): [(o: ConfirmOpts) => Promise<boolean>, ReactNode] {
  const ref = useRef<HTMLDialogElement>(null);
  const [opts, setOpts] = useState<ConfirmOpts>();
  const resolver = useRef<(v: boolean) => void>();
  const ask = useCallback((o: ConfirmOpts) => new Promise<boolean>((res) => { resolver.current = res; setOpts(o); }), []);
  useEffect(() => { if (opts && !ref.current?.open) ref.current?.showModal(); }, [opts]);
  const close = (v: boolean) => { ref.current?.close(); setOpts(undefined); resolver.current?.(v); };
  const node = (
    <dialog ref={ref} className="dialog" aria-labelledby="dlg-t" aria-describedby="dlg-d" onCancel={(e) => { e.preventDefault(); close(false); }}>
      {opts && (
        <>
          <h2 id="dlg-t">{opts.title}</h2>
          <p id="dlg-d">{opts.message}</p>
          <div className="row end">
            <button onClick={() => close(false)} autoFocus>Anuluj</button>
            <button className={opts.danger ? 'danger solid' : 'primary'} onClick={() => close(true)}>{opts.confirmLabel ?? 'Potwierdź'}</button>
          </div>
        </>)}
    </dialog>
  );
  return [ask, node];
}
