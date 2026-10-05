import { useState } from 'react';
import { DealStage } from '@shared/contracts';
import { cleanError, STAGE_PL, useLoad } from '../hooks';
import { Alert, EmptyState, PageHeader } from '../ui';

/** Lejek jako kolumny z listami; zmiana etapu przez <select> (dostępne z klawiatury i czytnika, bez drag&drop). */
export function Deals() {
  const deals = useLoad(() => window.api.crm.deals({}), []);
  const customers = useLoad(() => window.api.crm.customers({}), []);
  const [err, setErr] = useState<string | null>(null);
  const [cust, setCust] = useState('');
  const [title, setTitle] = useState('');
  const [value, setValue] = useState(0);

  const guard = async (fn: () => Promise<unknown>) => {
    try { await fn(); setErr(null); deals.reload(); } catch (e) { setErr(cleanError(e)); }
  };

  return (
    <>
      <PageHeader title="Sprawy" lead="Lejek spraw: wybierz etap z listy przy każdej sprawie, aby ją przesunąć." />
      <form className="card" aria-label="Nowa sprawa" onSubmit={(e) => { e.preventDefault(); void guard(() => window.api.crm.addDeal({ customerId: cust, title, valuePln: value })).then(() => setTitle('')); }}>
        <h2>Nowa sprawa</h2>
        <div className="form-grid">
          <div className="field"><label htmlFor="dc" className="req">Kontrahent</label>
            <select id="dc" required aria-required="true" value={cust} onChange={(e) => setCust(e.target.value)}>
              <option value="">— wybierz —</option>{customers.data?.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select></div>
          <div className="field"><label htmlFor="dt" className="req">Tytuł sprawy</label><input id="dt" required aria-required="true" value={title} onChange={(e) => setTitle(e.target.value)} /></div>
          <div className="field"><label htmlFor="dv">Wartość (zł)</label><input id="dv" type="number" min={0} value={value} onChange={(e) => setValue(Number(e.target.value))} /></div>
        </div>
        <p style={{ marginTop: 14 }}><button className="primary" type="submit">Dodaj sprawę</button></p>
      </form>
      {err && <Alert kind="error">{err}</Alert>}
      <div className="cols">
        {DealStage.options.map((stage) => {
          const items = deals.data?.filter((d) => d.stage === stage) ?? [];
          return (
            <section key={stage} className="card" aria-labelledby={`h-${stage}`}>
              <h2 id={`h-${stage}`}>{STAGE_PL[stage]} <span className="muted">({items.length})</span></h2>
              {items.length === 0 && <EmptyState icon="deal" title="Brak spraw" />}
              <ul className="plain">
                {items.map((d) => (
                  <li key={d.id}>
                    <strong>{d.title}</strong><br /><span className="muted">{d.customerName} · {d.valuePln.toLocaleString('pl-PL')} zł</span>
                    <div className="field" style={{ marginTop: 6 }}>
                      <label className="sr-only" htmlFor={`m-${d.id}`}>Etap sprawy: {d.title}</label>
                      <select id={`m-${d.id}`} value={d.stage} onChange={(e) => void guard(() => window.api.crm.moveDeal({ id: d.id, stage: e.target.value as typeof stage }))}>
                        {DealStage.options.map((s) => <option key={s} value={s}>{STAGE_PL[s]}</option>)}
                      </select>
                    </div>
                  </li>
                ))}
              </ul>
            </section>
          );
        })}
      </div>
    </>
  );
}
