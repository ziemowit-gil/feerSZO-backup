import { useEffect, useState } from 'react';
import type { ContactHit } from '@shared/contracts';
import { cleanError } from '../hooks';
import { Alert, Badge, EmptyState, Icon, PageHeader, Spinner } from '../ui';

/** Szybka lista kontaktów CRM z serwera: wpisz fragment (nazwa, e-mail, telefon, NIP) — wyniki na bieżąco. */
export function Contacts() {
  const [q, setQ] = useState('');
  const [rows, setRows] = useState<ContactHit[]>([]);
  const [err, setErr] = useState('');
  const [busy, setBusy] = useState(true);
  const [copied, setCopied] = useState('');

  useEffect(() => {
    setBusy(true);
    const t = setTimeout(() => {
      window.api.server.contacts({ q }).then((r) => { setRows(r); setErr(''); })
        .catch((e: unknown) => { setRows([]); setErr(cleanError(e)); })
        .finally(() => setBusy(false));
    }, 250);
    return () => clearTimeout(t);
  }, [q]);

  const copy = (v: string) => { void navigator.clipboard.writeText(v).then(() => { setCopied(v); setTimeout(() => setCopied(''), 2000); }); };

  return (
    <>
      <PageHeader title="Szybka lista kontaktów" lead="Kontakty z CRM na serwerze. Wybierz adres e-mail lub numer telefonu, aby go skopiować." />
      <div className="card">
        <div className="toolbar">
          <div className="field" style={{ flex: 1, minWidth: 260 }}>
            <label htmlFor="cq">Szukaj (nazwa, e-mail, telefon, NIP)</label>
            <input id="cq" type="search" autoFocus value={q} onChange={(e) => setQ(e.target.value)} />
          </div>
        </div>
        <p className="hint" role="status" aria-live="polite">{busy ? <><Spinner />Szukam…</> : `Znaleziono kontaktów: ${rows.length}`}</p>
        <div className="sr-only" role="status" aria-live="polite">{copied && `Skopiowano: ${copied}`}</div>
      </div>
      {err && <Alert kind="error">{err}</Alert>}
      <div className="table-wrap">
        <table>
          <caption className="sr-only">Kontakty CRM</caption>
          <thead><tr><th scope="col">Nazwa</th><th scope="col">Typ</th><th scope="col">E-mail</th><th scope="col">Telefon</th></tr></thead>
          <tbody>
            {!busy && rows.length === 0 && !err && <tr><td colSpan={4}><EmptyState icon="users" title="Brak kontaktów">Zmień frazę wyszukiwania.</EmptyState></td></tr>}
            {rows.map((c) => (
              <tr key={c.id}>
                <th scope="row" style={{ textTransform: 'none', letterSpacing: 0, fontSize: 'inherit', color: 'inherit', background: 'none', whiteSpace: 'normal' }}>
                  {c.name}{(c.position || (c.org && c.org !== c.name)) && <div className="sub">{[c.position, c.org !== c.name ? c.org : ''].filter(Boolean).join(' · ')}</div>}
                </th>
                <td><Badge tone="info">{c.type === 'organizacja' ? 'Organizacja' : c.type === 'osoba' ? 'Osoba' : c.type}</Badge></td>
                <td>{c.email ? <button className="small" onClick={() => copy(c.email)} aria-label={`Kopiuj e-mail: ${c.email}`}><Icon name={copied === c.email ? 'check' : 'copy'} size={15} />{c.email}</button> : <span className="muted" aria-label="brak">—</span>}</td>
                <td>{c.phone ? <button className="small" onClick={() => copy(c.phone)} aria-label={`Kopiuj telefon: ${c.phone}`}><Icon name={copied === c.phone ? 'check' : 'copy'} size={15} />{c.phone}</button> : <span className="muted" aria-label="brak">—</span>}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </>
  );
}
