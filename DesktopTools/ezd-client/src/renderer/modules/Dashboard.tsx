import { STAGE_PL, useLoad } from '../hooks';
import { Badge, EmptyState, Icon, PageHeader } from '../ui';

const kb = (n: number) => (n > 1048576 ? `${(n / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(n / 1024))} KB`);

export function Dashboard() {
  const scans = useLoad(() => window.api.scan.list(), []);
  const docs = useLoad(() => window.api.ezd.list({}), []);
  const deals = useLoad(() => window.api.crm.deals({}), []);
  const status = useLoad(() => window.api.server.status(), []);
  const pending = scans.data?.filter((s) => s.status === 'new' || s.status === 'error').length ?? 0;
  const uploaded = scans.data?.filter((s) => s.status === 'uploaded').length ?? 0;
  const toReview = docs.data?.filter((d) => d.stage === 'in_review').length ?? 0;
  const open = deals.data?.filter((d) => d.stage !== 'won' && d.stage !== 'lost').length ?? 0;

  const kpis = [
    { icon: 'upload', n: pending, cap: 'Skany do wysłania do EZD' },
    { icon: 'check', n: uploaded, cap: 'Skany wgrane do EZD' },
    { icon: 'doc', n: toReview, cap: 'Dokumenty do akceptacji (lokalnie)' },
    { icon: 'deal', n: open, cap: 'Otwarte sprawy (lokalnie)' }
  ];

  return (
    <>
      <PageHeader title="Pulpit" lead="Zeskanuj dokument i wgraj go do EZD albo szybko znajdź kontakt w CRM."
        actions={<><a className="btn primary" href="#/scan"><Icon name="scan" />Skanuj dokument</a><a className="btn" href="#/contacts"><Icon name="search" />Znajdź kontakt</a></>} />
      <section aria-label="Podsumowanie" className="cols">
        {kpis.map((k) => (
          <div key={k.cap} className="card kpi">
            <span className="icon-wrap"><Icon name={k.icon} size={22} /></span>
            <div><p className="num">{k.n}</p><p className="cap">{k.cap}</p></div>
          </div>
        ))}
      </section>
      <div className="split">
        <section className="card" aria-labelledby="rs-h">
          <h2 id="rs-h">Ostatnie skany</h2>
          {scans.data?.length === 0 && <EmptyState icon="scan" title="Brak skanów">Zeskanuj pierwszy dokument w sekcji „Skanowanie do EZD”.</EmptyState>}
          <ul className="plain">
            {scans.data?.slice(0, 5).map((s) => (
              <li key={s.id} className="row" style={{ justifyContent: 'space-between' }}>
                <span><strong>{s.name}</strong><br /><span className="muted">{s.pages} str. · {kb(s.size)}</span></span>
                <Badge tone={s.status === 'uploaded' ? 'ok' : s.status === 'error' ? 'danger' : 'info'}>
                  {s.status === 'uploaded' ? 'Wgrany' : s.status === 'error' ? 'Błąd wysyłki' : s.status === 'uploading' ? 'Wysyłanie' : 'Do wysłania'}
                </Badge>
              </li>
            ))}
          </ul>
        </section>
        <section className="card" aria-labelledby="co-h">
          <h2 id="co-h">Połączenie z serwerem</h2>
          <p><strong>{status.data?.url ?? '…'}</strong></p>
          {status.data?.account
            ? <p>Zalogowano jako <strong>{status.data.account.name}</strong><br /><span className="muted">{status.data.account.email}</span></p>
            : <p className="muted">Połączono kluczem API.</p>}
          <h3 style={{ marginTop: 16 }}>Ostatnie dokumenty (lokalnie)</h3>
          <ul className="plain">
            {docs.data?.slice(0, 3).map((d) => <li key={d.id}>{d.regNo} — {d.title} <Badge>{STAGE_PL[d.stage]}</Badge></li>)}
          </ul>
        </section>
      </div>
    </>
  );
}
