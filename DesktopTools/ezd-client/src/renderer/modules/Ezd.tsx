import { useState } from 'react';
import type { Doc, DocKind, Stage } from '@shared/contracts';
import { fmtDateTime, isoOf } from '@shared/locale';
import { cleanError, KIND_PL, STAGE_PL, useLoad } from '../hooks';
import { Alert, Badge, EmptyState, PageHeader } from '../ui';

const NEXT: Record<Stage, Stage[]> = {
  registered: ['dispatched', 'archived'], dispatched: ['in_review'], in_review: ['approved', 'dispatched'],
  approved: ['signed', 'in_review'], signed: ['archived'], archived: []
};
const tone = (s: Stage) => (s === 'signed' || s === 'approved' ? 'ok' : s === 'in_review' ? 'warn' : s === 'archived' ? 'neutral' : 'info') as 'ok' | 'warn' | 'neutral' | 'info';

export function Ezd() {
  const [q, setQ] = useState('');
  const [kind, setKind] = useState<DocKind | ''>('');
  const [sel, setSel] = useState<Doc>();
  const [err, setErr] = useState<string | null>(null);
  const [title, setTitle] = useState('');
  const [newKind, setNewKind] = useState<DocKind>('incoming');
  const list = useLoad(() => window.api.ezd.list({ q, kind: kind || undefined }), [q, kind]);
  const audit = useLoad(() => (sel ? window.api.ezd.audit({ entityId: sel.id }) : Promise.resolve([])), [sel?.id, sel?.stage]);
  const vers = useLoad(() => (sel ? window.api.ezd.versions({ id: sel.id }) : Promise.resolve([])), [sel?.id, sel?.version]);

  const run = async (fn: () => Promise<Doc>) => {
    try { const d = await fn(); setSel(d); setErr(null); list.reload(); } catch (e) { setErr(cleanError(e)); }
  };

  return (
    <>
      <PageHeader title="Rejestr dokumentów" lead="Lokalny rejestr kancelaryjny na tym komputerze: numeracja, obieg, wersje plików i metryka. Skany wgrywasz do serwera w sekcji „Skanowanie do EZD”." />
      <div className="card">
        <form className="toolbar" role="search" aria-label="Szukaj dokumentów" onSubmit={(e) => e.preventDefault()}>
          <div className="field" style={{ flex: 1, minWidth: 220 }}><label htmlFor="q">Szukaj (pełny tekst)</label><input id="q" type="search" value={q} onChange={(e) => setQ(e.target.value)} /></div>
          <div className="field"><label htmlFor="k">Rodzaj</label>
            <select id="k" value={kind} onChange={(e) => setKind(e.target.value as DocKind | '')}>
              <option value="">Wszystkie</option>{Object.entries(KIND_PL).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select></div>
        </form>
      </div>
      <form className="card toolbar" aria-label="Nowy dokument" onSubmit={(e) => { e.preventDefault(); void run(() => window.api.ezd.register({ kind: newKind, title, attach: true })).then(() => setTitle('')); }}>
        <div className="field" style={{ flex: 1, minWidth: 220 }}><label htmlFor="nt" className="req">Nowy dokument — tytuł</label><input id="nt" required aria-required="true" value={title} onChange={(e) => setTitle(e.target.value)} /></div>
        <div className="field"><label htmlFor="nk">Rodzaj</label>
          <select id="nk" value={newKind} onChange={(e) => setNewKind(e.target.value as DocKind)}>
            {Object.entries(KIND_PL).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select></div>
        <button className="primary" type="submit">Zarejestruj i dołącz plik…</button>
      </form>
      {err && <Alert kind="error">{err}</Alert>}
      <div className="split">
        <div className="table-wrap">
          <table>
            <caption className="sr-only">Rejestr dokumentów</caption>
            <thead><tr><th scope="col">Numer</th><th scope="col">Tytuł</th><th scope="col">Etap</th></tr></thead>
            <tbody>
              {list.data?.length === 0 && <tr><td colSpan={3}><EmptyState icon="doc" title="Brak dokumentów">Zarejestruj pierwszy dokument powyżej.</EmptyState></td></tr>}
              {list.data?.map((d) => (
                <tr key={d.id} className={sel?.id === d.id ? 'selected' : undefined}>
                  <td>{d.regNo}</td>
                  <td><button className="link" aria-pressed={sel?.id === d.id} onClick={() => setSel(d)}>{d.title}</button></td>
                  <td><Badge tone={tone(d.stage)}>{STAGE_PL[d.stage]}</Badge></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <aside className="card" aria-label="Szczegóły dokumentu" aria-live="polite">
          {!sel ? <EmptyState icon="doc" title="Wybierz dokument">Kliknij tytuł w tabeli, aby zobaczyć szczegóły.</EmptyState> : (
            <>
              <h2>{sel.regNo} — {sel.title}</h2>
              <p>Etap: <Badge tone={tone(sel.stage)}>{STAGE_PL[sel.stage]}</Badge> · wersja {sel.version}</p>
              <div className="row" style={{ margin: '10px 0' }}>
                {NEXT[sel.stage].map((s) => <button key={s} onClick={() => void run(() => window.api.ezd.advance({ id: sel.id, to: s }))}>Przejdź do: {STAGE_PL[s]}</button>)}
                {sel.stage === 'approved' && <button className="primary" onClick={() => void run(() => window.api.ezd.sign({ id: sel.id, signer: 'pkcs11' }))}>Podpisz (karta kwalifikowana)</button>}
              </div>
              <h3>Wersje plików</h3>
              <ul className="plain">{vers.data?.length === 0 && <li className="muted">Brak plików.</li>}{vers.data?.map((v) => <li key={v.version}>v{v.version} — {v.fileName} <span className="muted">SHA-256 {v.sha256.slice(0, 12)}…</span></li>)}</ul>
              <h3 style={{ marginTop: 14 }}>Metryka (audyt)</h3>
              <ul className="plain">{audit.data?.map((a) => <li key={a.id}><time dateTime={isoOf(a.at)}>{fmtDateTime(a.at)}</time> — {a.actor}: {a.action}{a.detail ? ` ${a.detail}` : ''}</li>)}</ul>
            </>
          )}
        </aside>
      </div>
    </>
  );
}
