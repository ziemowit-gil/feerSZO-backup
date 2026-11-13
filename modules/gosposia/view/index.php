<?php
/**
 * modules/gosposia/view/index.php — Gosposia (/pomocne/gosposia): samodzielna strona bez menu SZO, wygląd METRO jak /nowehaslo
 * (style: modules/dhosting_migrator/view/_metro.php). Dla administratora i współpracowników (middleware gosposia.access).
 * Zmienne: $user, $cred (null albo ['email','remembered']), $whitelist (['global'=>[], 'own'=>[{id,pattern}]]), $aiReady, $limit, $flash.
 *
 * Proste opisy (polecenie 2026-11-14). WCAG 2.2 AA: etykiety <label>, komunikaty w role="status" aria-live="polite", błędy w role="alert",
 * fokus: żółta ramka 3 px, rozwijane listy z aria-expanded/aria-controls, okno potwierdzenia role="dialog" aria-modal z pułapką fokusu
 * (x-trap, @alpinejs/focus), zamykane Escape, fokus wraca na przycisk. Cele dotyku ≥ 44 px.
 */
$org = org_setting('org_short_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'SZO');
$cfg = [
    'csrf' => Router::csrfToken(),
    'urls' => ['scan' => route('gosposia.scan'), 'trash' => route('gosposia.trash'),
               'wlAdd' => route('gosposia.whitelist.add'), 'wlRemove' => route('gosposia.whitelist.remove')],
    'own'  => $whitelist['own'],
];
$article = rtrim(APP_URL, '/') . '/uslugi-it/uslugi/moja-poczta/gosposia';
?><!doctype html>
<html lang="pl" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Gosposia — <?= h($org) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?php include dirname(__DIR__, 2) . '/dhosting_migrator/view/_metro.php'; ?>
<style>
  [x-cloak]{display:none!important}
  .pvm .gp-bar-act{display:flex;flex-wrap:wrap;gap:.6rem;align-items:center;margin:0 0 1rem}
  .pvm .gp-msg{margin:0 0 1rem}
  .pvm .gp-list{list-style:none;margin:0;padding:0}
  .pvm .gp-s{border:2px solid var(--pvm-line);border-left:8px solid var(--c,var(--m-blue));background:#fff;padding:.8rem 1rem;margin:0 0 .6rem}
  .pvm .gp-s--on{background:var(--pvm-light);border-color:var(--m-blue)}
  .pvm .gp-s__top{display:flex;gap:.7rem;align-items:flex-start}
  .pvm .gp-s__top>div{min-width:0;flex:1}
  .pvm input[type=checkbox]{width:1.35rem;height:1.35rem;margin-top:.15rem;flex-shrink:0;accent-color:var(--m-blue)}
  .pvm input[type=checkbox]:focus-visible{outline:3px solid #fbbf24;outline-offset:2px}
  .pvm .gp-name{font-weight:600;font-size:1.05rem;display:block;word-break:break-word}
  .pvm .gp-mail{color:var(--pvm-muted);font-size:.9rem;word-break:break-all;margin:0}
  .pvm .gp-why{margin:.35rem 0 0;font-size:.95rem}
  .pvm .gp-kind{display:inline-block;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#fff;background:var(--c,var(--m-blue));padding:.1rem .45rem;margin-right:.4rem}
  .pvm .gp-cat-newsletter{--c:#0050ef} .pvm .gp-cat-oferta_handlowa{--c:#a0522d}
  .pvm .gp-cat-powiadomienie_systemowe{--c:#6a00ff} .pvm .gp-cat-cold_mailing{--c:#a20025}
  .pvm .gp-meta{color:var(--pvm-muted);font-size:.9rem;margin:.25rem 0 0}
  .pvm .gp-acts{display:flex;flex-wrap:wrap;gap:.4rem;margin-top:.55rem}
  .pvm .gp-link{background:none;border:0;padding:.35rem .2rem;min-height:44px;font:inherit;font-size:.92rem;color:#0b5a8a;text-decoration:underline;text-underline-offset:3px;cursor:pointer;display:inline-flex;gap:.35rem;align-items:center}
  .pvm .gp-link:focus-visible,.pvm .mt-btn:focus-visible{outline:3px solid #fbbf24;outline-offset:2px}
  .pvm .gp-link[disabled]{color:var(--pvm-muted);cursor:not-allowed}
  .pvm .gp-msgs{list-style:none;margin:.6rem 0 0;padding:.5rem 0 0;border-top:1px solid var(--pvm-line)}
  .pvm .gp-msgs li{display:flex;gap:.6rem;align-items:flex-start;padding:.3rem 0}
  .pvm .gp-msgs label{min-width:0;flex:1}
  .pvm .gp-msgs .gp-subj{display:block;word-break:break-word}
  .pvm .gp-foot{position:sticky;bottom:0;background:#fff;border-top:2px solid var(--pvm-ink);padding:.75rem 0;margin-top:1rem;display:flex;flex-wrap:wrap;gap:.6rem;align-items:center;justify-content:space-between}
  .pvm .gp-foot p{margin:0}
  .pvm .mt-btn--danger[disabled]{opacity:.6;cursor:not-allowed}
  .pvm .mt-btn--danger:not([disabled]){background:var(--m-red);color:#fff}
  .pvm .mt-btn--danger:not([disabled]):hover{filter:brightness(.92)}
  .gp-over{position:fixed;inset:0;background:rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center;padding:1rem;z-index:50}
  .gp-dlg{background:#fff;max-width:32rem;width:100%;padding:1.4rem;border-top:8px solid var(--m-red)}
  .gp-dlg h2{margin:0 0 .6rem;font-size:1.35rem}
  .gp-dlg .dm-row{justify-content:flex-end;margin-top:1rem}
  .pvm .gp-wl li{display:flex;justify-content:space-between;align-items:center;gap:.5rem;border-bottom:1px solid var(--pvm-line)}
  .pvm .gp-wl span{word-break:break-all}
</style>
<script src="https://cdn.jsdelivr.net/npm/@alpinejs/focus@3/dist/cdn.min.js" defer></script>
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3/dist/cdn.min.js" defer></script>
</head>
<body>
<a class="skip" href="#tresc">Przejdź do treści</a>
<header class="dm-bar">
  <div class="dm-bar__in">
    <span class="dm-bar__org"><span class="sq" aria-hidden="true"><i class="bi bi-stars"></i></span><?= h($org) ?></span>
    <span class="dm-bar__sp"></span>
    <span class="dm-bar__who"><?= h((string)($user['name'] ?? '')) ?></span>
    <a href="<?= h($article) ?>"><i class="bi bi-question-circle" aria-hidden="true"></i> Pomoc</a>
    <a href="<?= h(APP_URL . '/') ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Wróć do SZO</a>
  </div>
</header>

<main id="tresc" class="pvm dm" x-data="gosposia(<?= h(json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>)">
  <div class="mt-head">
    <div>
      <div class="mt-kicker">Pomocne narzędzia</div>
      <h1 class="mt-title">Gosposia</h1>
      <p class="mt-lead">Sprząta z poczty reklamy i newslettery. Pokazuje, od kogo są, a Ty wybierasz, co wyrzucić.
        Wiadomości trafiają do Kosza, więc możesz je odzyskać.</p>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="dm-msg dm-msg--<?= h($flash['type']) ?>" role="<?= in_array($flash['type'], ['success', 'info'], true) ? 'status' : 'alert' ?>">
      <i class="bi <?= $flash['type'] === 'success' ? 'bi-check-circle' : ($flash['type'] === 'info' ? 'bi-info-circle' : 'bi-exclamation-triangle') ?>" aria-hidden="true"></i><div><?= h($flash['msg']) ?></div>
    </div>
  <?php endif; ?>

<?php if (!$cred): ?>
  <section class="dm-sec" aria-labelledby="gpConnH">
    <h2 class="mt-h" id="gpConnH">Połącz pocztę</h2>
    <form method="post" action="<?= h(route('gosposia.connect')) ?>" novalidate>
      <?= Router::csrfField() ?>
      <label class="dm-fld" for="gpEmail">Adres poczty</label>
      <input class="dm-inp" id="gpEmail" name="email" type="email" required autocomplete="username" spellcheck="false" value="<?= h($user['email'] ?? '') ?>">
      <p class="dm-help">Twój adres w Fundacji, np. imie.nazwisko@feer.org.pl.</p>
      <label class="dm-fld" for="gpPass">Hasło do poczty</label>
      <input class="dm-inp" id="gpPass" name="password" type="password" required autocomplete="current-password" aria-describedby="gpPassHelp">
      <p class="dm-help" id="gpPassHelp">To samo, którym logujesz się do poczty. Nie hasło do SZO.</p>
      <div class="dm-chk">
        <input id="gpRemember" name="remember" type="checkbox" value="1" aria-describedby="gpRememberHelp">
        <div><label for="gpRemember"><strong>Zapamiętaj</strong></label>
          <p class="dm-help" id="gpRememberHelp" style="margin:0">Nie będziesz wpisywać hasła następnym razem. Przechowujemy je zaszyfrowane.</p></div>
      </div>
      <button type="submit" class="mt-btn"><i class="bi bi-plug" aria-hidden="true"></i> Połącz</button>
    </form>
  </section>
<?php else: ?>
  <section class="dm-sec" aria-label="Twoja poczta">
    <p style="margin:0 0 .5rem"><i class="bi bi-envelope-check" aria-hidden="true" style="color:var(--m-green)"></i> Poczta: <strong><?= h($cred['email']) ?></strong></p>
    <div class="gp-bar-act">
      <button type="button" class="mt-btn" @click="scan()" :disabled="busy">
        <i class="bi bi-search" aria-hidden="true"></i> <span x-text="scanned ? 'Sprawdź jeszcze raz' : 'Sprawdź pocztę'">Sprawdź pocztę</span>
      </button>
      <form method="post" action="<?= h(route('gosposia.disconnect')) ?>"><?= Router::csrfField() ?>
        <button type="submit" class="gp-link"><i class="bi bi-x-circle" aria-hidden="true"></i> Odłącz pocztę</button>
      </form>
    </div>
    <p class="dm-help" style="margin-top:0">Sprawdzamy <?= (int)$limit ?> najnowszych wiadomości w Odebranych.<?= $aiReady ? '' : ' Sztuczna inteligencja jest wyłączona, więc działają prostsze reguły.' ?></p>
  </section>

  <div role="status" aria-live="polite" aria-atomic="true">
    <div class="dm-msg dm-msg--info gp-msg" x-show="status" x-cloak tabindex="-1" x-ref="status"><i class="bi bi-info-circle" aria-hidden="true"></i><div x-text="status"></div></div>
  </div>
  <div role="alert">
    <div class="dm-msg dm-msg--danger gp-msg" x-show="error" x-cloak><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><div x-text="error"></div></div>
  </div>

  <section class="dm-sec" x-show="scanned" x-cloak aria-labelledby="gpListH">
    <h2 class="mt-h" id="gpListH">Do wyrzucenia: <span x-text="senders.length"></span> <span x-text="plural(senders.length, 'nadawca', 'nadawców', 'nadawców')"></span></h2>
    <p class="dm-help" style="margin-top:0" x-text="statsText()"></p>
    <p x-show="!senders.length">Nie ma tu reklam ani newsletterów. Poczta jest czysta.</p>
    <div class="gp-bar-act" x-show="senders.length">
      <button type="button" class="gp-link" @click="selectAll(true)">Zaznacz wszystko</button>
      <button type="button" class="gp-link" @click="selectAll(false)">Odznacz wszystko</button>
    </div>

    <ul class="gp-list">
      <template x-for="s in senders" :key="s.email">
        <li class="gp-s" :class="['gp-cat-' + s.category, countSel(s) ? 'gp-s--on' : '']">
          <div class="gp-s__top">
            <input type="checkbox" :id="'gpS' + s.idx" :checked="countSel(s) === s.messages.length"
                   x-effect="$el.indeterminate = countSel(s) > 0 && countSel(s) < s.messages.length" @change="toggleSender(s, $event.target.checked)">
            <div>
              <label :for="'gpS' + s.idx" class="gp-name"><span x-text="s.name || s.email"></span><span class="visually-hidden">: zaznacz wszystkie wiadomości</span></label>
              <p class="gp-mail" x-show="s.name" x-text="s.email"></p>
              <p class="gp-why"><span class="gp-kind" x-text="s.label"></span><span x-text="s.reason"></span></p>
              <p class="gp-meta">
                <span x-text="s.count + ' ' + plural(s.count, 'wiadomość', 'wiadomości', 'wiadomości') + ', ' + mb(s.size) + ' MB'"></span>
                <span x-show="s.confidence < 0.7"> · niepewne, sprawdź</span>
                <span x-show="countSel(s) && countSel(s) < s.messages.length" x-text="' · wybrano ' + countSel(s)"></span>
              </p>
              <div class="gp-acts">
                <button type="button" class="gp-link" @click="s.open = !s.open" :aria-expanded="s.open ? 'true' : 'false'" :aria-controls="'gpM' + s.idx">
                  <i class="bi" :class="s.open ? 'bi-chevron-up' : 'bi-chevron-down'" aria-hidden="true"></i>
                  <span x-text="(s.open ? 'Ukryj' : 'Pokaż') + ' wiadomości'"></span>
                </button>
                <button type="button" class="gp-link" @click="whitelist(s.email)" :disabled="busy">
                  <i class="bi bi-shield-check" aria-hidden="true"></i> Zostaw tego nadawcę<span class="visually-hidden" x-text="': ' + s.email"></span>
                </button>
                <button type="button" class="gp-link" @click="whitelist(s.domain)" :disabled="busy">
                  Zostaw wszystkich z <span x-text="s.domain"></span>
                </button>
              </div>
              <ul class="gp-msgs" :id="'gpM' + s.idx" x-show="s.open" :aria-label="'Wiadomości od ' + (s.name || s.email)">
                <template x-for="m in s.messages" :key="m.uid">
                  <li>
                    <input type="checkbox" :id="'gpU' + m.uid" :checked="!!sel[m.uid]" @change="sel[m.uid] = $event.target.checked">
                    <label :for="'gpU' + m.uid"><span class="gp-subj" x-text="m.subject || '(bez tematu)'"></span>
                      <span class="gp-meta" x-text="(m.date || 'bez daty') + ' · ' + kb(m.size)"></span></label>
                  </li>
                </template>
              </ul>
            </div>
          </div>
        </li>
      </template>
    </ul>

    <div class="gp-foot" x-show="senders.length">
      <p x-text="selectionText()"></p>
      <button type="button" class="mt-btn mt-btn--danger" x-ref="trashBtn" @click="openConfirm()" :disabled="busy || !total().count">
        <i class="bi bi-trash3" aria-hidden="true"></i>
        <span x-text="'Przenieś zaznaczone do Kosza (' + total().count + ' ' + plural(total().count, 'wiadomość', 'wiadomości', 'wiadomości') + ', ' + mb(total().bytes) + ' MB)'"></span>
      </button>
    </div>
  </section>

  <section class="dm-sec">
    <details class="dm-more">
      <summary>Zaufani nadawcy</summary>
      <p>Poczty od nich Gosposia nigdy nie rusza. Zawsze zaufane są urzędy, Fundacja, dostawca poczty i banki.</p>
      <form @submit.prevent="addPattern()">
        <label class="dm-fld" for="gpWlNew">Dodaj adres albo domenę</label>
        <div class="dm-row">
          <input class="dm-inp" id="gpWlNew" type="text" x-model="newPattern" spellcheck="false" aria-describedby="gpWlHelp" style="margin:0">
          <button type="submit" class="mt-btn" :disabled="busy">Dodaj</button>
        </div>
        <p class="dm-help" id="gpWlHelp">Na przykład jan@firma.pl albo firma.pl.</p>
      </form>
      <p x-show="!own.length">Nie dodano jeszcze nikogo.</p>
      <ul class="gp-list gp-wl" x-show="own.length" aria-label="Twoi zaufani nadawcy">
        <template x-for="w in own" :key="w.id">
          <li><span x-text="w.pattern"></span>
            <button type="button" class="gp-link" @click="removePattern(w)" :disabled="busy">Usuń<span class="visually-hidden" x-text="' ' + w.pattern"></span></button></li>
        </template>
      </ul>
    </details>
  </section>

  <div class="gp-over" x-show="confirmOpen" x-cloak @keydown.escape.window="confirmOpen && closeConfirm()">
    <div class="gp-dlg" role="dialog" aria-modal="true" aria-labelledby="gpDlgH" aria-describedby="gpDlgD"
         x-trap.noscroll.inert="confirmOpen" @click.outside="closeConfirm()">
      <h2 id="gpDlgH">Przenieść do Kosza?</h2>
      <p id="gpDlgD" x-text="confirmText()"></p>
      <p class="dm-help">Odzyskasz je z Kosza w poczcie.</p>
      <div class="dm-row">
        <button type="button" class="mt-btn" @click="closeConfirm()" x-ref="cancelBtn">Anuluj</button>
        <button type="button" class="mt-btn mt-btn--danger" @click="doTrash()" :disabled="busy"><i class="bi bi-trash3" aria-hidden="true"></i> Tak, przenieś</button>
      </div>
    </div>
  </div>
<?php endif; ?>
</main>

<script>
document.addEventListener('alpine:init', () => {
  Alpine.data('gosposia', (cfg) => ({
    senders: [], sel: {}, own: cfg.own || [], stats: null, trashName: '',
    scanned: false, busy: false, status: '', error: '', confirmOpen: false, newPattern: '',

    plural(n, one, few, many) {
      if (n === 1) return one;
      const d = n % 10, h = n % 100;
      return (d >= 2 && d <= 4 && !(h >= 12 && h <= 14)) ? few : many;
    },
    mb(b) { return (b / 1048576).toLocaleString('pl-PL', { minimumFractionDigits: 1, maximumFractionDigits: 1 }); },
    kb(b) { return b < 1048576 ? Math.max(1, Math.round(b / 1024)) + ' kB' : this.mb(b) + ' MB'; },
    wiad(n) { return n + ' ' + this.plural(n, 'wiadomość', 'wiadomości', 'wiadomości'); },

    async post(url, data) {
      const fd = new FormData();
      fd.append('_csrf', cfg.csrf);
      for (const [k, v] of Object.entries(data || {})) fd.append(k, v);
      let res, json = null;
      try {
        res = await fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
        json = await res.json();
      } catch (e) {
        throw new Error(res && res.status === 403 ? 'Sesja wygasła. Odśwież stronę.' : 'Serwer nie odpowiada. Spróbuj jeszcze raz.');
      }
      if (!json || !json.ok) throw new Error((json && json.error) || 'Coś poszło nie tak. Spróbuj jeszcze raz.');
      return json;
    },

    async scan() {
      this.busy = true; this.error = '';
      this.status = 'Sprawdzam pocztę. To może potrwać około minuty.';
      try {
        const r = await this.post(cfg.urls.scan);
        this.senders = r.senders.map((s, i) => ({ ...s, idx: i, open: false }));
        this.sel = {};
        this.senders.forEach(s => { if (s.preselect) s.messages.forEach(m => this.sel[m.uid] = true); });
        this.stats = r.stats; this.trashName = r.stats.trash || ''; this.scanned = true;
        this.status = this.senders.length
          ? 'Gotowe. Zaznaczyliśmy pewne przypadki. Przejrzyj listę i kliknij przycisk na dole.'
          : 'Gotowe. Nie ma nic do wyrzucenia.';
      } catch (e) { this.status = ''; this.error = e.message; }
      this.busy = false;
    },

    statsText() {
      const s = this.stats; if (!s) return '';
      let t = 'Sprawdzono ' + this.wiad(s.scanned) + '. Od zaufanych nadawców: ' + s.whitelisted + ', tych nie ruszamy.';
      if (s.fallback_batches) t += ' Sztuczna inteligencja nie odpowiedziała, więc ocena jest prostsza. Sprawdź listę uważnie.';
      if (!s.trash) t += ' Nie ma folderu Kosz, więc nie możemy nic przenieść. Napisz do Helpdesku IT.';
      return t;
    },

    countSel(s) { let n = 0; for (const m of s.messages) if (this.sel[m.uid]) n++; return n; },
    toggleSender(s, on) { s.messages.forEach(m => this.sel[m.uid] = on); },
    selectAll(on) { this.senders.forEach(s => this.toggleSender(s, on)); },
    total() {
      let count = 0, bytes = 0, senders = 0;
      for (const s of this.senders) {
        let any = false;
        for (const m of s.messages) if (this.sel[m.uid]) { count++; bytes += m.size; any = true; }
        if (any) senders++;
      }
      return { count, bytes, senders };
    },
    selectionText() {
      const t = this.total();
      return t.count ? 'Wybrano ' + this.wiad(t.count) + ' od ' + t.senders + ' ' + this.plural(t.senders, 'nadawcy', 'nadawców', 'nadawców') + '.' : 'Nic nie wybrano.';
    },
    confirmText() {
      const t = this.total();
      return 'Czy na pewno chcesz przenieść ' + this.wiad(t.count) + ' od ' + t.senders + ' ' + this.plural(t.senders, 'nadawcy', 'nadawców', 'nadawców')
        + ' do Kosza na dPoczta (' + this.mb(t.bytes) + ' MB)?';
    },

    openConfirm() { if (!this.total().count) return; this.confirmOpen = true; this.$nextTick(() => this.$refs.cancelBtn.focus()); },
    closeConfirm() { this.confirmOpen = false; this.$nextTick(() => this.$refs.trashBtn && this.$refs.trashBtn.focus()); },

    async doTrash() {
      const selection = {};
      for (const s of this.senders) {
        const uids = s.messages.filter(m => this.sel[m.uid]).map(m => m.uid);
        if (uids.length) selection[s.email] = uids;
      }
      this.busy = true; this.error = ''; this.status = 'Przenoszę do Kosza…';
      try {
        const r = await this.post(cfg.urls.trash, { selection: JSON.stringify(selection) });
        const moved = new Set(Object.values(selection).flat());
        this.senders = this.senders.map(s => {
          const left = s.messages.filter(m => !moved.has(m.uid));
          return { ...s, messages: left, count: left.length, size: left.reduce((a, m) => a + m.size, 0) };
        }).filter(s => s.messages.length);
        moved.forEach(u => delete this.sel[u]);
        this.status = 'Gotowe. ' + this.wiad(r.moved) + ' (' + this.mb(r.bytes) + ' MB) jest w Koszu.';
        this.confirmOpen = false;
        this.$nextTick(() => this.$refs.status.focus());
      } catch (e) { this.status = ''; this.error = e.message; this.closeConfirm(); }
      this.busy = false;
    },

    async whitelist(pattern) {
      this.busy = true; this.error = '';
      try {
        const r = await this.post(cfg.urls.wlAdd, { pattern });
        this.own = r.own;
        const isDomain = !r.pattern.includes('@');
        const gone = this.senders.filter(x => isDomain ? (x.domain === r.pattern || x.domain.endsWith('.' + r.pattern)) : x.email === r.pattern);
        gone.forEach(x => x.messages.forEach(m => delete this.sel[m.uid]));
        this.senders = this.senders.filter(x => !gone.includes(x));
        this.status = r.pattern + ' jest teraz na liście zaufanych. Gosposia nie będzie już ruszać tej poczty.';
      } catch (e) { this.error = e.message; }
      this.busy = false;
    },

    async addPattern() {
      if (!this.newPattern.trim()) { this.error = 'Wpisz adres albo domenę.'; document.getElementById('gpWlNew').focus(); return; }
      await this.whitelist(this.newPattern.trim());
      if (!this.error) this.newPattern = '';
    },

    async removePattern(w) {
      this.busy = true; this.error = '';
      try {
        const r = await this.post(cfg.urls.wlRemove, { id: w.id });
        this.own = r.own;
        this.status = 'Usunięto ' + w.pattern + ' z zaufanych.';
      } catch (e) { this.error = e.message; }
      this.busy = false;
    },
  }));
});
</script>
</body>
</html>
