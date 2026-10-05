import { useState } from 'react';
import type { Customer } from '@shared/contracts';
import { cleanError, useLoad } from '../hooks';
import { Alert, Badge, EmptyState, PageHeader } from '../ui';

export function Customers() {
  const [q, setQ] = useState('');
  const [sel, setSel] = useState<Customer>();
  const [err, setErr] = useState<string | null>(null);
  const [form, setForm] = useState({ kind: 'b2b' as 'b2b' | 'b2c', name: '', nip: '', email: '' });
  const [note, setNote] = useState('');
  const list = useLoad(() => window.api.crm.customers({ q }), [q]);
  const hist = useLoad(() => (sel ? window.api.crm.history({ customerId: sel.id }) : Promise.resolve([])), [sel?.id]);

  const add = async () => {
    try {
      const c = await window.api.crm.upsertCustomer({ kind: form.kind, name: form.name, nip: form.nip || undefined, email: form.email || undefined });
      setSel(c); setForm({ ...form, name: '', nip: '', email: '' }); setErr(null); list.reload();
    } catch (e) { setErr(cleanError(e)); }
  };
  const addNote = async () => {
    if (!sel) return;
    try { await window.api.crm.addNote({ customerId: sel.id, body: note }); setNote(''); hist.reload(); } catch (e) { setErr(cleanError(e)); }
  };

  return (
    <>
      <PageHeader title="Kontrahenci" lead="Lokalna kartoteka kontrahentów. Nowi kontrahenci i notatki są synchronizowani z CRM na serwerze." />
      <div className="card">
        <div className="field" style={{ maxWidth: 420 }}><label htmlFor="cq">Szukaj (nazwa, NIP, e-mail)</label><input id="cq" type="search" value={q} onChange={(e) => setQ(e.target.value)} /></div>
      </div>
      <form className="card" aria-label="Nowy kontrahent" onSubmit={(e) => { e.preventDefault(); void add(); }}>
        <h2>Nowy kontrahent</h2>
        <div className="form-grid">
          <div className="field"><label htmlFor="ck">Typ</label><select id="ck" value={form.kind} onChange={(e) => setForm({ ...form, kind: e.target.value as 'b2b' | 'b2c' })}><option value="b2b">Firma (B2B)</option><option value="b2c">Osoba (B2C)</option></select></div>
          <div className="field"><label htmlFor="cn" className="req">Nazwa</label><input id="cn" required aria-required="true" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></div>
          <div className="field"><label htmlFor="cnip">NIP</label><input id="cnip" inputMode="numeric" pattern="\d{10}" title="10 cyfr bez myślników" aria-describedby="nip-h" value={form.nip} onChange={(e) => setForm({ ...form, nip: e.target.value })} /><p id="nip-h" className="hint">10 cyfr, bez myślników.</p></div>
          <div className="field"><label htmlFor="ce">E-mail</label><input id="ce" type="email" autoComplete="off" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} /></div>
        </div>
        <p style={{ marginTop: 14 }}><button className="primary" type="submit">Dodaj kontrahenta</button></p>
      </form>
      {err && <Alert kind="error">{err}</Alert>}
      <div className="split">
        <div className="table-wrap">
          <table>
            <caption className="sr-only">Kontrahenci</caption>
            <thead><tr><th scope="col">Nazwa</th><th scope="col">Typ</th><th scope="col">NIP</th></tr></thead>
            <tbody>
              {list.data?.length === 0 && <tr><td colSpan={3}><EmptyState icon="users" title="Brak kontrahentów" /></td></tr>}
              {list.data?.map((c) => (
                <tr key={c.id} className={sel?.id === c.id ? 'selected' : undefined}>
                  <td><button className="link" aria-pressed={sel?.id === c.id} onClick={() => setSel(c)}>{c.name}</button></td>
                  <td><Badge tone="info">{c.kind === 'b2b' ? 'Firma' : 'Osoba'}</Badge></td><td>{c.nip ?? '—'}</td>
                </tr>))}
            </tbody>
          </table>
        </div>
        <aside className="card" aria-label="Karta kontrahenta" aria-live="polite">
          {!sel ? <EmptyState icon="users" title="Wybierz kontrahenta">Kliknij nazwę w tabeli.</EmptyState> : (
            <>
              <h2>{sel.name}</h2>
              <p className="muted">{sel.email ?? 'brak e-mail'} · {sel.phone ?? 'brak telefonu'}</p>
              <div className="field" style={{ marginTop: 10 }}><label htmlFor="nn">Nowa notatka</label>
                <textarea id="nn" rows={3} value={note} onChange={(e) => setNote(e.target.value)} /></div>
              <p style={{ marginTop: 10 }}><button onClick={() => void addNote()} disabled={!note.trim()}>Dodaj notatkę</button></p>
              <h3>Historia kontaktów</h3>
              <ul className="plain">{hist.data?.length === 0 && <li className="muted">Brak wpisów.</li>}
                {hist.data?.map((h, i) => <li key={i}><Badge>{h.type === 'note' ? 'notatka' : h.type === 'document' ? 'dokument' : h.type === 'deal' ? 'sprawa' : 'mail'}</Badge> <time>{h.at}</time> — {h.text}</li>)}</ul>
            </>
          )}
        </aside>
      </div>
    </>
  );
}
