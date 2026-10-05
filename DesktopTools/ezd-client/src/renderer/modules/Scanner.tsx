import { useEffect, useRef, useState } from 'react';
import type { In, PismoHit, RpwHit, ScanRec, ScannerCaps, ScannerInfo, ServerInfo, ServerStatus, Settings, SprawaHit, TeczkaHit } from '@shared/contracts';
import { fmtBytes } from '@shared/locale';
import { cleanError, useLoad } from '../hooks';
import { Alert, Badge, EmptyState, Icon, Listbox, PageHeader, Spinner, Tabs, useConfirm } from '../ui';

const STATUS: Record<ScanRec['status'], { label: string; tone: 'info' | 'warn' | 'ok' | 'danger' }> = {
  new: { label: 'Do wysłania', tone: 'info' }, uploading: { label: 'Wysyłanie…', tone: 'warn' },
  uploaded: { label: 'Wgrany do EZD', tone: 'ok' }, error: { label: 'Błąd wysyłki', tone: 'danger' }
};
const RPW_TYPES = [['list', 'List zwykły'], ['polecony', 'List polecony'], ['paczka', 'Paczka'], ['email', 'E-mail'], ['epuap', 'e-Doręczenia'], ['fax', 'Faks'], ['osobiscie', 'Złożone osobiście'], ['inne', 'Inne']] as const;

function useDebounced<T>(value: T, ms = 300): T {
  const [v, setV] = useState(value);
  useEffect(() => { const t = setTimeout(() => setV(value), ms); return () => clearTimeout(t); }, [value, ms]);
  return v;
}

function ServerBox({ onChange }: { onChange: () => void }) {
  const [st, setSt] = useState<ServerStatus>();
  const [set, setSet] = useState<Settings>();
  const [url, setUrl] = useState('');
  const [token, setToken] = useState('');
  const [info, setInfo] = useState<ServerInfo>();
  const [msg, setMsg] = useState<{ ok: boolean; text: string }>();
  const [busy, setBusy] = useState(false);

  const reload = async () => {
    const [s, cfg] = await Promise.all([window.api.server.status(), window.api.settings.get()]);
    setSt(s); setSet(cfg); setUrl(s.url);
  };
  useEffect(() => { void reload(); }, []);

  const save = async () => {
    setBusy(true); setMsg(undefined); setInfo(undefined);
    try {
      if (url !== st?.url) await window.api.settings.set({ serverUrl: url });
      const i = token ? await window.api.server.setToken({ token }) : await window.api.server.info();
      setToken(''); setInfo(i); setMsg({ ok: true, text: 'Połączono z serwerem.' });
      await reload(); onChange();
    } catch (e) { setMsg({ ok: false, text: cleanError(e) }); await reload(); } finally { setBusy(false); }
  };
  const needed = ['ezd:read', 'ezd:upload', 'ezd:write'].filter((p) => info && !info.permissions.includes(p));
  const ok = !!st?.url && st.hasToken;

  return (
    <details className="card">
      <summary><strong>Połączenie z serwerem EZD</strong>{' '}
        <Badge tone={ok ? 'ok' : 'warn'}>{ok ? (st?.account ? `zalogowano: ${st.account.name}` : 'klucz API') : 'wymaga logowania'}</Badge></summary>
      <form onSubmit={(e) => { e.preventDefault(); void save(); }} style={{ marginTop: 14 }}>
        <div className="field"><label htmlFor="tok">Klucz API — wpisz nowy, aby go zmienić</label>
          <input id="tok" type="password" autoComplete="off" value={token} onChange={(e) => setToken(e.target.value)} /></div>
        <details style={{ margin: '12px 0' }}>
          <summary>Zaawansowane</summary>
          <div className="field" style={{ marginTop: 8 }}><label htmlFor="srv">Adres serwera</label>
            <input id="srv" type="url" required value={url} onChange={(e) => setUrl(e.target.value)} aria-describedby="srv-h" />
            <p id="srv-h" className="hint">Domyślny adres jest zaszyty w aplikacji — zmieniaj go tylko dla serwera testowego.</p></div>
        </details>
        <div className="row">
          <button className="primary" type="submit" disabled={busy}>{busy ? <><Spinner />Sprawdzam…</> : 'Sprawdź połączenie / zmień klucz'}</button>
          <label className="check">
            <input type="checkbox" checked={set?.openAfterUpload ?? true} onChange={(e) => void window.api.settings.set({ openAfterUpload: e.target.checked }).then(setSet)} />
            Otwieraj rekord w przeglądarce po wgraniu
          </label>
        </div>
      </form>
      <div role="status" aria-live="polite">
        {msg && <Alert kind={msg.ok ? 'ok' : 'error'}>{msg.text}</Alert>}
        {info && (
          <p className="hint">Serwer {info.org ?? ''} (wersja {info.version ?? '?'}), klucz „{info.keyName}”{info.owner ? `, właściciel ${info.owner}` : ''}.
            Maks. plik: {fmtBytes(info.maxUploadBytes)}.{needed.length > 0 && <strong className="error-text"> Brakuje uprawnień: {needed.join(', ')}.</strong>}</p>
        )}
      </div>
    </details>
  );
}

function ScanBox({ onDone }: { onDone: () => void }) {
  const [devs, setDevs] = useState<ScannerInfo[]>([]);
  const [dev, setDev] = useState('');
  const [caps, setCaps] = useState<ScannerCaps>();
  const [opt, setOpt] = useState({ source: 'Platen' as 'Platen' | 'Feeder', duplex: false, color: 'RGB24' as 'RGB24' | 'Grayscale8', dpi: 300, name: '' });
  const [busy, setBusy] = useState('');
  const [msg, setMsg] = useState('');
  const [note, setNote] = useState('');
  const [err, setErr] = useState('');

  const find = async () => {
    setBusy('Szukam skanerów w sieci…'); setErr(''); setMsg(''); setNote('');
    try {
      let l = await window.api.scan.scanners();
      if (l.length === 0) l = await window.api.scan.scanners(); // mDNS bywa zawodny — jedna powtórka
      setDevs(l); setNote(l.length ? '' : 'Nie znaleziono skanerów eSCL/AirScan w tej sieci.');
      if (l[0]) setDev(l[0].id);
    } catch (e) { setErr(cleanError(e)); } finally { setBusy(''); }
  };
  useEffect(() => { void find(); }, []);
  useEffect(() => {
    setCaps(undefined);
    if (!dev) return;
    window.api.scan.caps({ id: dev }).then((c) => {
      setCaps(c);
      setOpt((o) => ({ ...o, source: c.sources.includes(o.source) ? o.source : (c.sources[0] ?? 'Platen'),
        dpi: c.resolutions.includes(o.dpi) ? o.dpi : (c.resolutions.includes(300) ? 300 : (c.resolutions[0] ?? 300)) }));
    }).catch((e: unknown) => setErr(cleanError(e)));
  }, [dev]);

  const go = async () => {
    setBusy('Skanowanie… nie otwieraj pokrywy ani podajnika.'); setErr(''); setMsg('');
    try {
      const r = await window.api.scan.start({ scannerId: dev, ...opt, name: opt.name || undefined });
      setMsg(`Zeskanowano: ${r.name} (stron: ${r.pages}).`); setOpt((o) => ({ ...o, name: '' })); onDone();
    } catch (e) { setErr(cleanError(e)); } finally { setBusy(''); }
  };

  return (
    <section className="card" aria-labelledby="sc-h">
      <h2 id="sc-h"><span className="step" aria-hidden="true">1</span> Zeskanuj dokument</h2>
      <div className="form-grid">
        <div className="field"><label htmlFor="dev">Skaner</label>
          <select id="dev" value={dev} onChange={(e) => setDev(e.target.value)} disabled={!!busy}>
            {devs.length === 0 && <option value="">— nie znaleziono —</option>}
            {devs.map((d) => <option key={d.id} value={d.id}>{d.name} ({d.host})</option>)}
          </select></div>
        <div><button onClick={() => void find()} disabled={!!busy}>Wyszukaj ponownie</button></div>
      </div>
      {caps && (
        <form onSubmit={(e) => { e.preventDefault(); void go(); }} style={{ marginTop: 14 }}>
          <div className="form-grid">
            <div className="field"><label htmlFor="src">Źródło</label>
              <select id="src" value={opt.source} onChange={(e) => setOpt({ ...opt, source: e.target.value as 'Platen' | 'Feeder' })}>
                {caps.sources.map((s) => <option key={s} value={s}>{s === 'Platen' ? 'Szyba' : 'Podajnik (ADF)'}</option>)}
              </select></div>
            <div className="field"><label htmlFor="col">Kolor</label>
              <select id="col" value={opt.color} onChange={(e) => setOpt({ ...opt, color: e.target.value as 'RGB24' | 'Grayscale8' })}>
                <option value="RGB24">Kolorowy</option><option value="Grayscale8">Odcienie szarości</option>
              </select></div>
            <div className="field"><label htmlFor="dpi">Rozdzielczość</label>
              <select id="dpi" value={opt.dpi} onChange={(e) => setOpt({ ...opt, dpi: Number(e.target.value) })}>
                {caps.resolutions.map((r) => <option key={r} value={r}>{r} DPI</option>)}
              </select></div>
            <div className="field"><label htmlFor="nm">Nazwa pliku (opcjonalnie)</label>
              <input id="nm" value={opt.name} onChange={(e) => setOpt({ ...opt, name: e.target.value })} placeholder="np. Umowa najmu" /></div>
          </div>
          <div className="row" style={{ marginTop: 14 }}>
            <button className="primary" type="submit" disabled={!!busy}><Icon name="scan" />Skanuj do PDF</button>
            {opt.source === 'Feeder' && caps.duplex && (
              <label className="check"><input type="checkbox" checked={opt.duplex} onChange={(e) => setOpt({ ...opt, duplex: e.target.checked })} />Dwustronnie</label>)}
          </div>
        </form>)}
      <div role="status" aria-live="polite">
        {busy && <p style={{ marginTop: 12 }}><Spinner />{busy}</p>}
        {!busy && msg && <Alert kind="ok">{msg}</Alert>}
        {!busy && note && <p className="hint">{note}</p>}
      </div>
      {err && <Alert kind="error">{err}</Alert>}
    </section>
  );
}

type Tab = 'sprawa' | 'newSprawa' | 'newRpw' | 'rpw';
const TABS = [['sprawa', 'Istniejąca koszulka'], ['newSprawa', 'Nowa koszulka'], ['newRpw', 'Nowy wpis RPW'], ['rpw', 'Istniejący wpis RPW']] as const;

function UploadBox({ scan, onDone }: { scan: ScanRec; onDone: () => void }) {
  const [tab, setTab] = useState<Tab>('sprawa');
  const [q, setQ] = useState('');
  const dq = useDebounced(q);
  const [sprawy, setSprawy] = useState<SprawaHit[]>([]);
  const [teczki, setTeczki] = useState<TeczkaHit[]>([]);
  const [rpws, setRpws] = useState<RpwHit[]>([]);
  const [pisma, setPisma] = useState<PismoHit[]>([]);
  const [sprawa, setSprawa] = useState<number>();
  const [pismo, setPismo] = useState<number>();
  const [teczka, setTeczka] = useState<number>();
  const [title, setTitle] = useState(scan.name.replace(/\.[^.]+$/, ''));
  const [rpwId, setRpwId] = useState<number>();
  const [rpw, setRpw] = useState({ typ: 'list' as (typeof RPW_TYPES)[number][0], nadawca: '', opis: '' });
  const [err, setErr] = useState('');
  const [busy, setBusy] = useState(false);
  const head = useRef<HTMLHeadingElement>(null);
  useEffect(() => { head.current?.scrollIntoView?.({ block: 'start', behavior: 'smooth' }); head.current?.focus(); }, []);

  useEffect(() => {
    setErr('');
    const fail = (e: unknown) => setErr(cleanError(e));
    if (tab === 'sprawa') window.api.server.sprawy({ q: dq }).then(setSprawy).catch(fail);
    if (tab === 'newSprawa') window.api.server.teczki({ q: dq }).then(setTeczki).catch(fail);
    if (tab === 'rpw') window.api.server.rpw({ q: dq }).then(setRpws).catch(fail);
  }, [tab, dq]);
  useEffect(() => {
    setPismo(undefined); setPisma([]);
    if (tab === 'sprawa' && sprawa) window.api.server.pisma({ sprawaId: sprawa }).then(setPisma).catch(() => setPisma([]));
  }, [sprawa, tab]);

  const target = (): In<'scan:upload'>['target'] | null => {
    if (tab === 'sprawa') return sprawa ? { type: 'sprawa', sprawaId: sprawa, pismoId: pismo } : null;
    if (tab === 'newSprawa') return teczka && title.trim() ? { type: 'newSprawa', teczkaId: teczka, title } : null;
    if (tab === 'newRpw') return { type: 'newRpw', ...rpw };
    return rpwId ? { type: 'rpw', rpwId } : null;
  };
  const t = target();
  const send = async () => {
    if (!t) return;
    setBusy(true); setErr('');
    try { await window.api.scan.upload({ id: scan.id, target: t }); } catch (e) { setErr(cleanError(e)); } finally { setBusy(false); onDone(); }
  };
  const searchLabel = tab === 'newSprawa' ? 'Znajdź segregator (symbol lub tytuł)' : tab === 'rpw' ? 'Szukaj wpisu RPW (nadawca, opis, znak)' : 'Znajdź koszulkę (znak sprawy lub tytuł)';

  return (
    <section className="card" aria-labelledby="up-h">
      <h2 id="up-h" ref={head} tabIndex={-1}><span className="step" aria-hidden="true">2</span> Wgraj do EZD</h2>
      <p className="muted">Plik: <strong>{scan.name}</strong></p>
      <Tabs label="Cel wgrania" tabs={TABS} value={tab} onChange={(k) => { setTab(k); setQ(''); }}>
        {(tab === 'sprawa' || tab === 'newSprawa' || tab === 'rpw') && (
          <div className="field" style={{ marginBottom: 10 }}>
            <label htmlFor="sq">{searchLabel}</label>
            <input id="sq" type="search" value={q} onChange={(e) => setQ(e.target.value)} />
          </div>)}
        {tab === 'sprawa' && (
          <>
            <Listbox label="Koszulki — wybierz strzałkami i Enter" items={sprawy.map((s) => ({ ...s }))} selected={sprawa} onSelect={setSprawa} render={(s) => <span><strong>{s.znak}</strong> — {s.title}</span>} />
            {sprawa && pisma.length > 0 && (
              <div className="field" style={{ marginTop: 12 }}><label htmlFor="pl">Dołącz do pisma (opcjonalnie)</label>
                <select id="pl" value={pismo ?? ''} onChange={(e) => setPismo(e.target.value ? Number(e.target.value) : undefined)}>
                  <option value="">— tylko do repozytorium koszulki —</option>
                  {pisma.map((p) => <option key={p.id} value={p.id}>{p.sygnatura} — {p.title}</option>)}
                </select></div>)}
          </>)}
        {tab === 'newSprawa' && (
          <>
            <Listbox label="Segregatory — wybierz strzałkami i Enter" items={teczki} selected={teczka} onSelect={setTeczka} render={(x) => <span><strong>{x.symbol}</strong> — {x.title} ({x.rok})</span>} />
            <div className="field" style={{ marginTop: 12 }}><label htmlFor="nt" className="req">Tytuł nowej koszulki</label>
              <input id="nt" value={title} onChange={(e) => setTitle(e.target.value)} maxLength={300} required aria-required="true" /></div>
            <p className="hint">Znak sprawy nada serwer. Plik trafi do repozytorium nowej koszulki.</p>
          </>)}
        {tab === 'newRpw' && (
          <div className="form-grid">
            <div className="field"><label htmlFor="rt">Rodzaj przesyłki</label>
              <select id="rt" value={rpw.typ} onChange={(e) => setRpw({ ...rpw, typ: e.target.value as typeof rpw.typ })}>
                {RPW_TYPES.map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select></div>
            <div className="field"><label htmlFor="rn">Nadawca</label><input id="rn" value={rpw.nadawca} onChange={(e) => setRpw({ ...rpw, nadawca: e.target.value })} /></div>
            <div className="field" style={{ gridColumn: '1 / -1' }}><label htmlFor="ro">Opis</label><input id="ro" value={rpw.opis} onChange={(e) => setRpw({ ...rpw, opis: e.target.value })} /></div>
            <p className="hint" style={{ gridColumn: '1 / -1' }}>Serwer zarejestruje przesyłkę w dzienniku podawczym (numer RPW nada automatycznie) i powiadomi administratorów; skan zostanie do niej dołączony.</p>
          </div>)}
        {tab === 'rpw' && <Listbox label="Wpisy RPW — wybierz strzałkami i Enter" items={rpws} selected={rpwId} onSelect={setRpwId} render={(r) => <span><strong>{r.label}</strong> — {r.nadawca || 'brak nadawcy'}{r.opis ? `: ${r.opis}` : ''}</span>} />}
      </Tabs>
      <div className="row" style={{ marginTop: 16 }}>
        <button className="primary" disabled={!t || busy} onClick={() => void send()}>{busy ? <><Spinner />Wysyłanie…</> : <><Icon name="upload" />Wgraj do EZD</>}</button>
        {!t && <span className="hint" style={{ margin: 0 }}>Wybierz cel, aby wysłać plik.</span>}
      </div>
      {err && <Alert kind="error">{err}</Alert>}
    </section>
  );
}

export function Scanner() {
  const scans = useLoad(() => window.api.scan.list(), []);
  const [sel, setSel] = useState<string>();
  const [err, setErr] = useState('');
  const [confirm, confirmNode] = useConfirm();
  const current = scans.data?.find((s) => s.id === sel);
  const guard = async (fn: () => Promise<unknown>) => { try { await fn(); setErr(''); scans.reload(); } catch (e) { setErr(cleanError(e)); } };

  return (
    <>
      <PageHeader title="Skanowanie do EZD" lead="Zeskanuj dokument ze skanera sieciowego (AirScan/eSCL) i wgraj go do koszulki lub dziennika podawczego." />
      <ServerBox onChange={scans.reload} />
      <ScanBox onDone={scans.reload} />
      <section aria-labelledby="q-h">
          <div className="row" style={{ justifyContent: 'space-between', marginBottom: 10 }}>
            <h2 id="q-h" style={{ margin: 0 }}>Kolejka skanów</h2>
            <button onClick={() => void guard(() => window.api.scan.importFiles())}><Icon name="plus" />Dodaj pliki z dysku…</button>
          </div>
          {err && <Alert kind="error">{err}</Alert>}
          <div className="table-wrap">
            <table>
              <caption className="sr-only">Kolejka skanów</caption>
              <thead><tr><th scope="col">Nazwa</th><th scope="col">Str.</th><th scope="col">Rozmiar</th><th scope="col">Status</th><th scope="col"><span className="sr-only">Akcje</span></th></tr></thead>
              <tbody>
                {scans.data?.length === 0 && <tr><td colSpan={5}><EmptyState icon="scan" title="Brak skanów">Zeskanuj dokument lub dodaj plik z dysku.</EmptyState></td></tr>}
                {scans.data?.map((s) => (
                  <tr key={s.id} className={sel === s.id ? 'selected' : undefined}>
                    <th scope="row" style={{ textTransform: 'none', letterSpacing: 0, fontSize: 'inherit', color: 'inherit', background: 'none', whiteSpace: 'normal' }}>{s.name}</th>
                    <td>{s.pages}</td><td>{fmtBytes(s.size)}</td>
                    <td><Badge tone={STATUS[s.status].tone}>{STATUS[s.status].label}</Badge>{s.error && <div className="error-text sub">{s.error}</div>}</td>
                    <td className="actions"><div className="row">
                      <button className="small" onClick={() => void guard(() => window.api.scan.open({ id: s.id }))} aria-label={`Podgląd: ${s.name}`}><Icon name="eye" size={15} />Podgląd</button>
                      {s.status !== 'uploaded' && <button className="small primary" onClick={() => setSel(s.id)} aria-pressed={sel === s.id} aria-label={`Wgraj do EZD: ${s.name}`}><Icon name="upload" size={15} />Wgraj…</button>}
                      <button className="small danger" aria-label={`Usuń: ${s.name}`} onClick={() => void confirm({ title: 'Usunąć skan?', message: `Lokalny plik „${s.name}” zostanie usunięty z tego komputera. Kopia już wgrana do EZD pozostanie.`, confirmLabel: 'Usuń', danger: true }).then((ok) => { if (ok) void guard(() => window.api.scan.remove({ id: s.id })); })}><Icon name="trash" size={15} />Usuń</button>
                    </div></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
      </section>
      {current && current.status !== 'uploaded'
        ? <UploadBox key={current.id} scan={current} onDone={scans.reload} />
        : scans.data && scans.data.some((s) => s.status !== 'uploaded') && <p className="hint">Użyj przycisku „Wgraj…” przy skanie, aby wskazać cel w EZD.</p>}
      {confirmNode}
    </>
  );
}
