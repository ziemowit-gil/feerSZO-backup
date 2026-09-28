<?php
/**
 * modules/smart_cards/nfc/index.php — Programator NFC: mikroaplikacja na telefon
 * (Chrome na Androidzie, Web NFC, HTTPS).
 *
 * Tryb „Programuj”: wybór karty czekającej na chip → przyłożenie chipu (odczyt UID)
 * → serwer wiąże UID i losuje token → zapis NDEF (URL weryfikacji + rekord MIME
 * z tokenem) → ponowny odczyt i porównanie UID+tokenu → opcjonalna blokada zapisu
 * chipu → potwierdzenie na serwerze. Zapis niezweryfikowany nie jest potwierdzany.
 *
 * Tryb „Sprawdź”: przyłożenie karty → posiadacz, status, autentyczność chipu,
 * strefy i test wejścia do wybranej strefy. Bez Web NFC działa ręczne wpisanie
 * UID (np. czytnik USB w trybie klawiatury) — wtedy bez oceny autentyczności.
 *
 * Backend: nfc/api.php. Pakiet dozwolonych chipów: NTAG213/215/216 lub inne
 * z NDEF (Web NFC nie obsługuje MIFARE Classic).
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__, 3) . '/includes/db.php';
require_once dirname(__DIR__, 3) . '/includes/auth.php';
require_once dirname(__DIR__, 3) . '/includes/functions.php';
require_once dirname(__DIR__) . '/logic/smart_cards.php';

require_role('admin', 'editor');
require_module_enabled('smart_cards_enabled', 'Moduł Karty dostępu');

$svc   = new SmartCardService(current_user());
$zones = array_map(fn($z) => ['id' => (int)$z['id'], 'name' => $z['zone_name'], 'level' => (int)$z['security_level']], $svc->zones(true));
$preselect = (int)($_GET['card'] ?? 0);
$J = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?><!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0f172a">
<meta name="robots" content="noindex">
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" href="icon.svg" type="image/svg+xml">
<title>Programator NFC</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3/dist/cdn.min.js" defer></script>
<?php include dirname(__DIR__) . '/partials/card_css.php'; ?>
<style>
  :root { --ink: #0f172a; --muted: #5b6472; --line: #e2e8f0; --brand: #1d4ed8; --ok: #047857; --warn: #b45309; --bad: #b91c1c; }
  * { box-sizing: border-box; }
  [x-cloak] { display: none !important; }
  body { margin: 0; background: #f1f5f9; color: var(--ink); font: 15px/1.45 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
  .top { position: sticky; top: 0; z-index: 5; display: flex; align-items: center; gap: 10px; padding: calc(10px + env(safe-area-inset-top)) 16px 10px;
         background: #0f172a; color: #fff; }
  .top a { color: #cbd5e1; text-decoration: none; font-size: 20px; }
  .top h1 { margin: 0; font-size: 1.05rem; font-weight: 700; }
  main { max-width: 520px; margin: 0 auto; padding: 16px 16px calc(32px + env(safe-area-inset-bottom)); }
  .seg { display: grid; grid-template-columns: 1fr 1fr; gap: 4px; padding: 4px; background: #e2e8f0; border-radius: 12px; margin-bottom: 16px; }
  .seg button { border: 0; border-radius: 9px; padding: 10px; font: inherit; font-weight: 600; background: transparent; color: var(--muted); cursor: pointer; }
  .seg button[aria-pressed="true"] { background: #fff; color: var(--ink); box-shadow: 0 1px 2px rgba(0,0,0,.08); }
  .panel { background: #fff; border-radius: 16px; padding: 16px; margin-bottom: 14px; box-shadow: 0 1px 3px rgba(16,24,40,.08); }
  .panel h2 { margin: 0 0 10px; font-size: .95rem; }
  .muted { color: var(--muted); font-size: .85rem; }
  .btn { display: flex; width: 100%; align-items: center; justify-content: center; gap: 8px; border: 0; border-radius: 12px; padding: 14px; font: inherit; font-weight: 700; cursor: pointer; }
  .btn:focus-visible, .seg button:focus-visible, .pick:focus-visible { outline: 3px solid #93c5fd; outline-offset: 2px; }
  .btn-primary { background: var(--brand); color: #fff; } .btn-ghost { background: #fff; color: var(--ink); border: 1px solid #cbd5e1; }
  .btn:disabled { opacity: .5; cursor: not-allowed; }
  .alert { display: flex; gap: 10px; border-radius: 12px; padding: 12px; font-size: .9rem; margin-bottom: 14px; }
  .alert.warn { background: #fffbeb; color: #78350f; border: 1px solid #fcd34d; } .alert.bad { background: #fef2f2; color: #991b1b; border: 1px solid #fca5a5; }
  .alert.ok { background: #ecfdf5; color: #065f46; border: 1px solid #6ee7b7; } .alert.info { background: #eff6ff; color: #1e3a8a; border: 1px solid #bfdbfe; }
  .list { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; }
  .pick { width: 100%; display: flex; align-items: center; gap: 12px; text-align: left; border: 1px solid var(--line); background: #fff; border-radius: 12px; padding: 10px 12px; font: inherit; cursor: pointer; }
  .pick[aria-pressed="true"] { border-color: var(--brand); box-shadow: inset 0 0 0 1px var(--brand); background: #f8fbff; }
  .swatch { width: 44px; height: 28px; border-radius: 6px; flex: none; }
  .pick strong { display: block; } .mono { font-family: ui-monospace, Menlo, monospace; font-size: .8rem; }
  .field { display: block; width: 100%; border: 1px solid #cbd5e1; border-radius: 10px; padding: 11px 12px; font: inherit; background: #fff; color: var(--ink); }
  label.lbl { display: block; font-weight: 600; font-size: .85rem; margin: 0 0 4px; }
  .check { display: flex; gap: 10px; align-items: flex-start; font-size: .9rem; margin: 12px 0; }
  .check input { width: 20px; height: 20px; margin: 0; accent-color: var(--brand); flex: none; }
  .steps { list-style: none; padding: 0; margin: 12px 0 0; display: grid; gap: 8px; }
  .steps li { display: flex; gap: 10px; align-items: center; font-size: .9rem; color: var(--muted); }
  .steps li i { width: 22px; text-align: center; }
  .steps li.is-now { color: var(--ink); font-weight: 600; } .steps li.is-done { color: var(--ok); } .steps li.is-fail { color: var(--bad); }
  .pulse { position: relative; width: 132px; height: 132px; margin: 8px auto 14px; display: grid; place-items: center; border-radius: 50%; background: #dbeafe; color: var(--brand); font-size: 48px; }
  .pulse::before, .pulse::after { content: ''; position: absolute; inset: 0; border-radius: 50%; border: 3px solid #60a5fa; animation: ring 1.8s ease-out infinite; }
  .pulse::after { animation-delay: .9s; }
  @keyframes ring { from { transform: scale(.8); opacity: .9; } to { transform: scale(1.5); opacity: 0; } }
  @media (prefers-reduced-motion: reduce) { .pulse::before, .pulse::after { animation: none; opacity: .4; } }
  .zones { list-style: none; margin: 8px 0 0; padding: 0; display: flex; flex-wrap: wrap; gap: 6px; }
  .zones li { font-size: .78rem; font-weight: 600; border-radius: 999px; padding: 3px 10px; background: #ecfdf5; color: var(--ok); }
  .zones li.off { background: #f1f5f9; color: #64748b; text-decoration: line-through; }
  .verdict { text-align: center; font-size: 1.3rem; font-weight: 800; padding: 18px; border-radius: 14px; color: #fff; margin-top: 12px; }
  .row { display: flex; gap: 8px; } .row > * { flex: 1; }
  .sc-card { max-width: none; margin: 0 auto 4px; }
</style>
</head>
<body>
<header class="top">
  <a href="<?= h(APP_URL . '/modules/smart_cards/index.php') ?>" aria-label="Wróć do modułu Karty dostępu"><i class="bi bi-arrow-left" aria-hidden="true"></i></a>
  <h1><i class="bi bi-broadcast" aria-hidden="true"></i> Programator NFC</h1>
</header>

<main x-data="nfcApp()" x-init="init()">
  <div class="seg" role="group" aria-label="Tryb">
    <button type="button" :aria-pressed="(mode === 'program').toString()" @click="setMode('program')"><i class="bi bi-pencil-square" aria-hidden="true"></i> Programuj</button>
    <button type="button" :aria-pressed="(mode === 'check').toString()" @click="setMode('check')"><i class="bi bi-search" aria-hidden="true"></i> Sprawdź</button>
  </div>

  <template x-if="!supported">
    <div class="alert warn" role="note"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
      <div><strong>Ta przeglądarka nie obsługuje Web NFC.</strong> Programowanie działa w Chrome na Androidzie (HTTPS, włączone NFC).
        W trybie „Sprawdź” możesz wpisać UID ręcznie lub czytnikiem USB.</div></div>
  </template>

  <div class="alert" :class="msg.type" x-show="msg.text" x-cloak role="status" aria-live="polite">
    <i class="bi" :class="{ ok: 'bi-check-circle', bad: 'bi-x-octagon', warn: 'bi-exclamation-triangle', info: 'bi-info-circle' }[msg.type]" aria-hidden="true"></i>
    <div x-text="msg.text"></div>
  </div>

  <!-- ── PROGRAMUJ ──────────────────────────────────────────────────────── -->
  <section x-show="mode === 'program'" aria-label="Programowanie karty">
    <div class="panel" x-show="!busy">
      <h2>1. Wybierz kartę do zaprogramowania</h2>
      <p class="muted" x-show="!loading && !cards.length">Brak kart czekających na chip. Karty pojawiają się tu po zatwierdzeniu wniosku.</p>
      <p class="muted" x-show="loading">Wczytywanie…</p>
      <ul class="list">
        <template x-for="c in cards" :key="c.id">
          <li><button type="button" class="pick" :aria-pressed="(sel && sel.id === c.id).toString()" @click="sel = c; say('', '')">
            <span class="swatch" :style="`background:linear-gradient(135deg, ${c.design.bg_from}, ${c.design.bg_to})`" aria-hidden="true"></span>
            <span><strong x-text="c.holder"></strong><span class="muted" x-text="c.template + ' · ważna do ' + c.expires_short"></span>
              <span class="mono muted" x-show="c.uid_source === 'reader'" x-text="'oczekiwany chip ' + scardUid(c.uid)"></span></span>
          </button></li>
        </template>
      </ul>
    </div>

    <template x-if="sel">
      <div class="panel">
        <div :class="'sc-card pat-' + sel.design.pattern + ' chip-' + sel.design.chip" :style="scardVars(sel.design)" role="img" :aria-label="'Karta: ' + sel.holder">
          <div class="sc-card__top"><span class="sc-card__label" x-text="sel.design.label"></span>
            <svg class="sc-card__nfc" viewBox="0 0 24 24" aria-hidden="true"><path d="M8.5 7.5a6.5 6.5 0 0 1 0 9M12 5a10 10 0 0 1 0 14M15.5 2.5a13.5 13.5 0 0 1 0 19M5 10a3 3 0 0 1 0 4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></div>
          <div class="sc-card__chip" aria-hidden="true"><span></span></div>
          <div class="sc-card__uid" x-text="chipUid ? scardUid(chipUid) : '•••• •••• •••• ••'"></div>
          <div class="sc-card__bottom">
            <div><span class="sc-card__cap">Posiadacz</span><span class="sc-card__holder" x-text="sel.holder.toUpperCase()"></span></div>
            <div style="text-align:right"><span class="sc-card__cap">Ważna do</span><span class="sc-card__exp" x-text="sel.expires_short"></span></div>
          </div>
        </div>

        <div x-show="busy" class="pulse" aria-hidden="true"><i class="bi bi-phone-vibrate"></i></div>
        <ol class="steps" aria-label="Postęp programowania">
          <template x-for="(s, i) in STEPS" :key="i">
            <li :class="stepClass(i)"><i class="bi" :class="stepIcon(i)" aria-hidden="true"></i><span x-text="s"></span></li>
          </template>
        </ol>

        <label class="check"><input type="checkbox" x-model="lock" :disabled="busy">
          <span><strong>Zablokuj chip przed nadpisaniem</strong> <span class="muted">— nieodwracalne. Zalecane dla kart wydawanych na stałe.</span></span></label>

        <button type="button" class="btn btn-primary" @click="program()" :disabled="busy || !supported">
          <i class="bi bi-broadcast" aria-hidden="true"></i> <span x-text="busy ? 'Przytrzymaj kartę przy telefonie…' : 'Zaprogramuj — przyłóż kartę'"></span></button>
        <button type="button" class="btn btn-ghost" style="margin-top:8px" x-show="busy" @click="cancel()">Anuluj</button>
      </div>
    </template>
  </section>

  <!-- ── SPRAWDŹ ────────────────────────────────────────────────────────── -->
  <section x-show="mode === 'check'" x-cloak aria-label="Sprawdzanie karty">
    <div class="panel">
      <div x-show="busy" class="pulse" aria-hidden="true"><i class="bi bi-phone-vibrate"></i></div>
      <button type="button" class="btn btn-primary" @click="check()" :disabled="busy || !supported" x-show="supported">
        <i class="bi bi-broadcast" aria-hidden="true"></i> <span x-text="busy ? 'Przyłóż kartę do telefonu…' : 'Odczytaj kartę'"></span></button>
      <button type="button" class="btn btn-ghost" style="margin-top:8px" x-show="busy" @click="cancel()">Anuluj</button>
      <form @submit.prevent="identify(manualUid, '')" style="margin-top:14px">
        <label class="lbl" for="m_uid">…albo wpisz UID <span class="muted">(czytnik USB)</span></label>
        <div class="row"><input id="m_uid" class="field mono" x-model="manualUid" autocomplete="off" placeholder="04:A1:B2:C3:D4:E5:F6">
          <button class="btn btn-ghost" style="flex:0 0 auto;width:auto;padding:0 16px" type="submit" :disabled="!manualUid.trim()">Sprawdź</button></div>
      </form>
    </div>

    <template x-if="found">
      <div class="panel" aria-live="polite">
        <div :class="'sc-card pat-' + found.design.pattern + ' chip-' + found.design.chip + (found.status !== 'active' ? ' is-inactive' : '')" :style="scardVars(found.design)" role="img" :aria-label="'Karta: ' + found.holder + ', ' + found.status_label">
          <div class="sc-card__top"><span class="sc-card__label" x-text="found.design.label"></span>
            <svg class="sc-card__nfc" viewBox="0 0 24 24" aria-hidden="true"><path d="M8.5 7.5a6.5 6.5 0 0 1 0 9M12 5a10 10 0 0 1 0 14M15.5 2.5a13.5 13.5 0 0 1 0 19M5 10a3 3 0 0 1 0 4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></div>
          <div class="sc-card__chip" aria-hidden="true"><span></span></div>
          <div class="sc-card__uid" x-text="scardUid(found.uid)"></div>
          <div class="sc-card__bottom">
            <div><span class="sc-card__cap">Posiadacz</span><span class="sc-card__holder" x-text="found.holder.toUpperCase()"></span></div>
            <div style="text-align:right"><span class="sc-card__cap">Ważna do</span><span class="sc-card__exp" x-text="found.expires_short"></span></div>
          </div>
          <span class="sc-card__ribbon" x-show="found.status !== 'active'" x-text="found.status_label.toUpperCase()" aria-hidden="true"></span>
        </div>
        <p style="margin:12px 0 4px"><strong x-text="found.holder"></strong> <span class="muted" x-text="found.email"></span></p>
        <p class="muted" style="margin:0" x-text="found.template + ' · ' + found.status_label + ' · ważna do ' + found.expires"></p>
        <ul class="zones" aria-label="Strefy">
          <template x-for="z in found.zones" :key="z.name"><li :class="!z.effective && 'off'" x-text="'L' + z.level + ' ' + z.name"></li></template>
          <li class="off" x-show="!found.zones.length">brak stref</li>
        </ul>
        <div style="margin-top:14px" x-show="zones.length">
          <label class="lbl" for="c_zone">Test wejścia do strefy</label>
          <div class="row"><select id="c_zone" class="field" x-model.number="zoneId">
              <template x-for="z in zones" :key="z.id"><option :value="z.id" x-text="'L' + z.level + ' · ' + z.name"></option></template></select>
            <button type="button" class="btn btn-ghost" style="flex:0 0 auto;width:auto;padding:0 16px" @click="access()">Sprawdź</button></div>
          <div class="verdict" x-show="verdict" x-cloak :style="`background:${verdict?.granted ? 'var(--ok)' : 'var(--bad)'}`">
            <i class="bi" :class="verdict?.granted ? 'bi-door-open' : 'bi-door-closed'" aria-hidden="true"></i>
            <span x-text="verdict?.granted ? 'WEJŚCIE' : 'ODMOWA'"></span><div style="font-size:.85rem;font-weight:500" x-text="verdict?.reason"></div></div>
        </div>
        <a class="btn btn-ghost" style="margin-top:12px;text-decoration:none" :href="found.url">Szczegóły karty w SZO</a>
      </div>
    </template>
  </section>
</main>

<script>
const NFC_MIME = 'application/vnd.szo.card+json';
function nfcApp() {
  return {
    supported: 'NDEFReader' in window,
    mode: 'program', cards: [], sel: null, loading: true, busy: false, lock: false,
    step: -1, failed: -1, chipUid: '', ctrl: null,
    msg: { type: '', text: '' },
    zones: <?= json_encode($zones, $J) ?>, zoneId: <?= (int)($zones[0]['id'] ?? 0) ?>,
    manualUid: '', found: null, foundUid: '', verdict: null,
    STEPS: ['Odczyt UID chipu', 'Przygotowanie zapisu na serwerze', 'Zapis rekordu NDEF', 'Weryfikacja zapisu', 'Potwierdzenie w ewidencji'],

    async init() {
      await this.load();
      const pre = <?= $preselect ?>;
      if (pre) this.sel = this.cards.find(c => c.id === pre) || null;
    },
    setMode(m) { this.cancel(); this.mode = m; this.say('', ''); this.verdict = null; },
    say(type, text) { this.msg = { type, text }; if (type === 'ok') navigator.vibrate?.(80); if (type === 'bad') navigator.vibrate?.([60, 60, 60]); },
    stepClass(i) { return i === this.failed ? 'is-fail' : (i < this.step ? 'is-done' : (i === this.step && this.busy ? 'is-now' : '')); },
    stepIcon(i)  { return i === this.failed ? 'bi-x-circle-fill' : (i < this.step ? 'bi-check-circle-fill' : (i === this.step && this.busy ? 'bi-arrow-repeat' : 'bi-circle')); },

    async api(action, data = {}) {
      const fd = new FormData();
      fd.append('_action', action);
      fd.append('_csrf', <?= json_encode(csrf_token()) ?>);
      for (const [k, v] of Object.entries(data)) fd.append(k, v);
      let r, j;
      try { r = await fetch('api.php', { method: 'POST', body: fd, credentials: 'same-origin' }); j = await r.json(); }
      catch (e) { throw new Error('Brak połączenia z serwerem lub sesja wygasła.'); }
      if (!j.ok) throw new Error(j.error || 'Błąd serwera.');
      return j;
    },
    async load() {
      this.loading = true;
      try { this.cards = (await this.api('list')).cards; } catch (e) { this.say('bad', e.message); }
      this.loading = false;
    },

    // Jeden odczyt tagu: zwraca zdarzenie NDEFReadingEvent albo rzuca po czasie / anulowaniu
    scanOnce(ms = 30000) {
      return new Promise(async (resolve, reject) => {
        const ctrl = new AbortController();
        this.ctrl = ctrl;
        const reader = new NDEFReader();
        const timer = setTimeout(() => { ctrl.abort(); reject(new Error('Nie wykryto karty w ciągu 30 s.')); }, ms);
        ctrl.signal.addEventListener('abort', () => { clearTimeout(timer); reject(new Error('Anulowano.')); });
        reader.onreading = e => { clearTimeout(timer); ctrl.abort(); resolve(e); };
        reader.onreadingerror = () => this.say('warn', 'Nie udało się odczytać chipu — może nie obsługiwać NDEF (np. MIFARE Classic). Użyj NTAG213/215/216.');
        try { await reader.scan({ signal: ctrl.signal }); }
        catch (e) { clearTimeout(timer); reject(e.name === 'NotAllowedError' ? new Error('Brak zgody na NFC — zezwól w ustawieniach strony.') : e); }
      });
    },
    tokenFrom(message) {
      for (const rec of (message?.records || [])) {
        try {
          if (rec.recordType === 'mime' && rec.mediaType === NFC_MIME) return JSON.parse(new TextDecoder().decode(rec.data)).t || '';
          if (rec.recordType === 'url') { const t = new URL(new TextDecoder().decode(rec.data)).searchParams.get('t'); if (t) return t; }
        } catch (e) { /* inny rekord — pomijamy */ }
      }
      return '';
    },
    norm(uid) { return (uid || '').replace(/[^0-9a-f]/gi, '').toUpperCase(); },
    cancel() { this.ctrl?.abort(); this.ctrl = null; this.busy = false; },

    async program() {
      if (!this.sel) return;
      if (this.lock && !confirm('Zablokowanego chipu nie da się już nadpisać ani odblokować. Kontynuować?')) return;
      this.busy = true; this.failed = -1; this.chipUid = ''; this.say('info', 'Przyłóż kartę do tylnej części telefonu i trzymaj do końca.');
      try {
        this.step = 0;
        const first = await this.scanOnce();
        if (!first.serialNumber) throw new Error('Chip nie udostępnia UID.');
        this.chipUid = this.norm(first.serialNumber);

        this.step = 1;
        const prep = await this.api('prepare', { card_id: this.sel.id, uid: this.chipUid });

        this.step = 2;
        this.say('info', 'Zapisuję — nie odsuwaj karty.');
        const writer = new NDEFReader();
        await writer.write({ records: [
          { recordType: 'url', data: prep.url },
          { recordType: 'mime', mediaType: NFC_MIME, data: new TextEncoder().encode(JSON.stringify({ v: 1, card: this.sel.id, t: prep.token })) },
        ] }, { overwrite: true });

        this.step = 3;
        this.say('info', 'Zapisano. Odsuń kartę i przyłóż ją ponownie, żeby zweryfikować zapis.');
        const check = await this.scanOnce(15000);
        if (this.norm(check.serialNumber) !== this.chipUid) throw new Error('Weryfikację odczytano z innego chipu — zaprogramuj ponownie, trzymając jedną kartę.');
        if (this.tokenFrom(check.message) !== prep.token) throw new Error('Zapis na chipie nie zgadza się z przygotowanym — spróbuj ponownie.');
        let locked = false;
        if (this.lock) {
          if (typeof writer.makeReadOnly !== 'function') throw new Error('Ta wersja Chrome nie potrafi zablokować chipu. Odznacz blokadę albo zaktualizuj przeglądarkę.');
          await writer.makeReadOnly();
          locked = true;
        }

        this.step = 4;
        await this.api('confirm', { card_id: this.sel.id, uid: this.chipUid, token: prep.token, locked: locked ? 1 : 0 });
        this.step = 5;
        this.say('ok', 'Karta ' + this.sel.holder + ' zaprogramowana' + (locked ? ' i zablokowana' : '') + '. UID ' + scardUid(this.chipUid) + '.');
        this.sel = null;
        await this.load();
      } catch (e) {
        this.failed = this.step;
        if (e.message !== 'Anulowano.') this.say('bad', e.message || String(e));
      } finally {
        this.busy = false; this.ctrl = null;
      }
    },

    async check() {
      this.busy = true; this.found = null; this.verdict = null; this.say('info', 'Przyłóż kartę do telefonu.');
      try {
        const e = await this.scanOnce();
        await this.identify(e.serialNumber, this.tokenFrom(e.message));
      } catch (e) {
        if (e.message !== 'Anulowano.') this.say('bad', e.message || String(e));
      } finally { this.busy = false; this.ctrl = null; }
    },
    async identify(uid, token) {
      this.found = null; this.verdict = null;
      try {
        const r = await this.api('identify', { uid: this.norm(uid), token });
        this.found = r.card; this.foundUid = this.norm(uid);
        this.say(r.authentic === true ? 'ok' : (r.authentic === null && r.card ? 'warn' : (r.card && !token ? 'info' : 'bad')),
                 !token && r.card && r.authentic === false ? 'Karta znaleziona po UID (bez odczytu tokenu — autentyczności nie sprawdzono).' : r.message);
      } catch (e) { this.say('bad', e.message); }
    },
    async access() {
      if (!this.foundUid || !this.zoneId) return;
      try { this.verdict = await this.api('access', { uid: this.foundUid, zone_id: this.zoneId }); navigator.vibrate?.(this.verdict.granted ? 80 : [60, 60, 60]); }
      catch (e) { this.say('bad', e.message); }
    },
  };
}
</script>
</body>
</html>
