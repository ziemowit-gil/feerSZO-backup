import { useState } from 'react';
import { cleanError } from '../hooks';
import { Alert, Icon, Spinner } from '../ui';

/** Pierwsze uruchomienie: logowanie kontem Microsoft 365 (przeglądarka). Klucz API tylko jako opcja zaawansowana. */
export function Setup({ defaultUrl, onDone }: { defaultUrl: string; onDone: () => void }) {
  const [token, setToken] = useState('');
  const [url, setUrl] = useState(defaultUrl);
  const [waiting, setWaiting] = useState(false);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');

  const saveUrl = async () => { if (url !== defaultUrl) await window.api.settings.set({ serverUrl: url }); };
  const login = async () => {
    setErr(''); setWaiting(true);
    try { await saveUrl(); await window.api.auth.login(); onDone(); }
    catch (e) { setErr(cleanError(e)); } finally { setWaiting(false); }
  };
  const withKey = async () => {
    setErr(''); setBusy(true);
    try { await saveUrl(); await window.api.server.setToken({ token }); onDone(); }
    catch (e) { setErr(cleanError(e)); } finally { setBusy(false); }
  };

  return (
    <main className="center-screen" aria-labelledby="login-h">
      <section className="card login-card">
        <div className="brand"><span className="brand-mark"><Icon name="scan" size={18} /></span><span>EZD Client</span></div>
        <h1 id="login-h" tabIndex={-1}>Zaloguj się</h1>
        <p className="lead">Użyj konta Microsoft 365, którego używasz w systemie. Logowanie otworzy się w przeglądarce i odbywa się tylko raz.</p>
        <div className="row" style={{ marginTop: 16 }}>
          <button className="primary" onClick={() => void login()} disabled={waiting}>
            {waiting ? <><Spinner />Dokończ logowanie w przeglądarce…</> : 'Zaloguj kontem Microsoft 365'}
          </button>
          {waiting && <button onClick={() => void window.api.auth.cancel()}>Anuluj</button>}
        </div>
        <div role="status" aria-live="polite">
          {waiting && <p className="hint">Czekam na zatwierdzenie w przeglądarce. Po zalogowaniu wróć do tej aplikacji i nie zamykaj jej.</p>}
        </div>
        {err && <Alert kind="error">{err}</Alert>}
        <details style={{ marginTop: 18 }}>
          <summary>Zaawansowane</summary>
          <div className="field" style={{ marginTop: 10 }}>
            <label htmlFor="url">Adres serwera</label>
            <input id="url" type="url" required value={url} onChange={(e) => setUrl(e.target.value)} aria-describedby="url-h" />
            <p id="url-h" className="hint">Domyślnie {defaultUrl}. Zmień tylko dla serwera testowego.</p>
          </div>
          <form onSubmit={(e) => { e.preventDefault(); void withKey(); }} style={{ marginTop: 10 }}>
            <div className="field">
              <label htmlFor="key">Klucz API (zamiast logowania kontem)</label>
              <input id="key" type="password" autoComplete="off" minLength={10} value={token} onChange={(e) => setToken(e.target.value)} />
            </div>
            <p style={{ marginTop: 10 }}><button type="submit" disabled={busy || token.length < 10}>{busy ? 'Łączę…' : 'Połącz kluczem'}</button></p>
          </form>
        </details>
        <p className="hint" style={{ marginTop: 14 }}>Dostęp jest zapisywany w systemowym sejfie (Keychain / Windows DPAPI). Wpisy w EZD i CRM będą na Twoje nazwisko.</p>
      </section>
    </main>
  );
}
