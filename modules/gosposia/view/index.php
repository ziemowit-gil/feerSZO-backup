<?php
/**
 * modules/gosposia/view/index.php — Gosposia: połączenie skrzynki dPoczta, przegląd nadawców, biała lista, przeniesienie do Kosza.
 * Zmienne: $user, $cred (null albo ['email','remembered']), $whitelist (['global'=>[], 'own'=>[{id,pattern}]]), $aiReady, $limit. Kontroler: index.
 *
 * WCAG 2.2 AA: etykiety <label> przy polach, komunikaty stanu w role="status" aria-live="polite", błędy w role="alert",
 * widoczny fokus (outline 3px), rozwijane listy z aria-expanded/aria-controls, okno potwierdzenia role="dialog" aria-modal
 * z pułapką fokusu (x-trap, wtyczka @alpinejs/focus z includes/header.php), zamykane Escape, fokus wraca na przycisk.
 * Klasy w nazwanych regułach gp-* (@apply) — szablony x-for nie polegają na skanowaniu klas przez Tailwind CDN.
 */
$PAGE_TITLE = 'Gosposia — sprzątanie poczty';
include dirname(__DIR__, 3) . '/includes/header.php';
$cfg = [
    'csrf' => Router::csrfToken(),
    'urls' => [
        'scan'      => route('gosposia.scan'),
        'trash'     => route('gosposia.trash'),
        'wlAdd'     => route('gosposia.whitelist.add'),
        'wlRemove'  => route('gosposia.whitelist.remove'),
    ],
    'own'  => $whitelist['own'],
];
$article = rtrim(APP_URL, '/') . '/uslugi-it/uslugi/moja-poczta/gosposia';
?>
<style type="text/tailwindcss">
  .gp-card   { @apply tw-rounded-xl tw-border tw-border-solid tw-border-slate-300 tw-bg-white tw-p-4 tw-shadow-sm sm:tw-p-5; }
  .gp-label  { @apply tw-mb-1 tw-block tw-text-sm tw-font-medium tw-text-slate-800; }
  .gp-input  { @apply tw-w-full tw-rounded-lg tw-border tw-border-solid tw-border-slate-500 tw-bg-white tw-px-3 tw-py-2 tw-text-base tw-text-slate-900; }
  .gp-help   { @apply tw-m-0 tw-mt-1 tw-text-sm tw-text-slate-600; }
  .gp-btn    { @apply tw-inline-flex tw-items-center tw-gap-2 tw-rounded-lg tw-border tw-border-solid tw-px-4 tw-py-2 tw-text-sm tw-font-semibold tw-cursor-pointer; min-height: 44px; }
  .gp-btn-primary { @apply gp-btn tw-border-blue-700 tw-bg-blue-700 tw-text-white hover:tw-bg-blue-800; }
  .gp-btn-danger  { @apply gp-btn tw-border-red-700 tw-bg-red-700 tw-text-white hover:tw-bg-red-800; }
  .gp-btn-ghost   { @apply gp-btn tw-border-slate-400 tw-bg-white tw-text-slate-800 hover:tw-bg-slate-100; }
  .gp-btn-small   { @apply gp-btn tw-px-3 tw-py-1 tw-text-xs; min-height: 36px; }
  .gp-btn[disabled], .gp-btn-primary[disabled], .gp-btn-danger[disabled] { @apply tw-cursor-not-allowed tw-opacity-60; }
  .gp-root :focus-visible { outline: 3px solid #1d4ed8; outline-offset: 2px; }
  .gp-root input[type=checkbox] { width: 1.25rem; height: 1.25rem; accent-color: #1d4ed8; flex-shrink: 0; }
  .gp-sender { @apply tw-rounded-xl tw-border tw-border-solid tw-border-slate-300 tw-bg-white tw-p-3 sm:tw-p-4; }
  .gp-sender-on { @apply tw-border-blue-600 tw-bg-blue-50; }
  .gp-badge  { @apply tw-inline-block tw-rounded-full tw-px-2 tw-py-0.5 tw-text-xs tw-font-semibold; }
  .gp-cat-newsletter              { @apply tw-bg-sky-100 tw-text-sky-900; }
  .gp-cat-oferta_handlowa         { @apply tw-bg-amber-100 tw-text-amber-900; }
  .gp-cat-powiadomienie_systemowe { @apply tw-bg-violet-100 tw-text-violet-900; }
  .gp-cat-cold_mailing            { @apply tw-bg-rose-100 tw-text-rose-900; }
  .gp-src    { @apply tw-inline-block tw-rounded tw-bg-slate-100 tw-px-1.5 tw-py-0.5 tw-text-xs tw-text-slate-700; }
  .gp-msgs   { @apply tw-m-0 tw-mt-3 tw-list-none tw-border-0 tw-border-t tw-border-solid tw-border-slate-200 tw-p-0 tw-pt-2; }
  .gp-msg    { @apply tw-flex tw-items-start tw-gap-2 tw-py-1.5 tw-text-sm; }
  .gp-meta   { @apply tw-text-sm tw-text-slate-700; }
  .gp-status { @apply tw-rounded-lg tw-border tw-border-solid tw-border-blue-300 tw-bg-blue-50 tw-px-3 tw-py-2 tw-text-sm tw-text-blue-950; }
  .gp-error  { @apply tw-rounded-lg tw-border tw-border-solid tw-border-red-400 tw-bg-red-50 tw-px-3 tw-py-2 tw-text-sm tw-text-red-900; }
  .gp-bar    { @apply tw-sticky tw-bottom-0 tw-z-10 tw-mt-4 tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-3 tw-rounded-xl tw-border tw-border-solid tw-border-slate-300 tw-bg-white tw-p-3 tw-shadow-lg; }
  .gp-overlay { @apply tw-fixed tw-inset-0 tw-z-[1100] tw-flex tw-items-center tw-justify-center tw-bg-slate-900/60 tw-p-4; }
  .gp-dialog  { @apply tw-w-full tw-max-w-lg tw-rounded-xl tw-bg-white tw-p-5 tw-shadow-2xl; }
  .gp-sr     { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
</style>

<div class="gp-root tw-mx-auto tw-max-w-5xl" x-data="gosposia(<?= h(json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>)">
  <div class="tw-mb-4">
    <p class="tw-m-0 tw-text-xs tw-font-semibold tw-uppercase tw-tracking-wide tw-text-slate-600">Pomocne narzędzia</p>
    <h1 class="tw-m-0 tw-mt-1 tw-text-2xl tw-font-semibold tw-text-slate-900"><i class="bi bi-stars tw-mr-1 tw-text-blue-700" aria-hidden="true"></i> Gosposia</h1>
    <p class="tw-m-0 tw-mt-1 tw-max-w-3xl tw-text-slate-700">Sprząta ze skrzynki newslettery, oferty handlowe i reklamy. Pokazuje listę nadawców, a Ty decydujesz, co trafi do Kosza. Bez Twojego potwierdzenia nic nie znika i nic nie jest usuwane na stałe.
      <a href="<?= h($article) ?>">Jak to działa</a>.</p>
  </div>
  <?= flash_html() ?>

<?php if (!$cred): ?>
  <section class="gp-card tw-max-w-xl" aria-labelledby="gp-connect-h">
    <h2 id="gp-connect-h" class="tw-m-0 tw-mb-3 tw-text-lg tw-font-semibold">Połącz skrzynkę</h2>
    <form method="post" action="<?= h(route('gosposia.connect')) ?>" novalidate>
      <?= Router::csrfField() ?>
      <div class="tw-mb-3">
        <label class="gp-label" for="gp-email">Adres skrzynki</label>
        <input class="gp-input" id="gp-email" name="email" type="email" required autocomplete="username" value="<?= h($user['email'] ?? '') ?>" aria-describedby="gp-email-help">
        <p class="gp-help" id="gp-email-help">Skrzynka na serwerze dPoczta (imap.dpoczta.pl, połączenie szyfrowane SSL/TLS).</p>
      </div>
      <div class="tw-mb-3">
        <label class="gp-label" for="gp-pass">Hasło do poczty</label>
        <input class="gp-input" id="gp-pass" name="password" type="password" required autocomplete="current-password" aria-describedby="gp-pass-help">
        <p class="gp-help" id="gp-pass-help">To samo hasło co w poczcie w przeglądarce i w telefonie. To nie jest hasło logowania jednokrotnego.</p>
      </div>
      <div class="tw-mb-4 tw-flex tw-items-start tw-gap-2">
        <input id="gp-remember" name="remember" type="checkbox" value="1" aria-describedby="gp-remember-help">
        <div>
          <label for="gp-remember" class="tw-font-medium">Zapamiętaj w SZO</label>
          <p class="gp-help tw-mt-0" id="gp-remember-help">Hasło zapiszemy zaszyfrowane, żeby nie trzeba było go wpisywać następnym razem. Bez zaznaczenia pamiętamy je tylko do wylogowania.</p>
        </div>
      </div>
      <button type="submit" class="gp-btn-primary"><i class="bi bi-plug" aria-hidden="true"></i> Połącz skrzynkę</button>
    </form>
  </section>
<?php else: ?>
  <section class="gp-card tw-mb-4 tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-3" aria-label="Połączona skrzynka">
    <div>
      <p class="tw-m-0 tw-font-semibold"><i class="bi bi-envelope-check tw-mr-1 tw-text-green-700" aria-hidden="true"></i> <?= h($cred['email']) ?></p>
      <p class="gp-help tw-mt-0"><?= $cred['remembered'] ? 'Hasło zapamiętane w SZO (zaszyfrowane).' : 'Hasło pamiętane do końca sesji.' ?>
        Przeglądamy ostatnie <?= (int)$limit ?> wiadomości w Odebranych.<?= $aiReady ? '' : ' Ocena AI jest wyłączona, działają reguły awaryjne.' ?></p>
    </div>
    <div class="tw-flex tw-flex-wrap tw-gap-2">
      <button type="button" class="gp-btn-primary" @click="scan()" :disabled="busy" :aria-busy="busy && phase === 'scan' ? 'true' : 'false'">
        <i class="bi bi-search" aria-hidden="true"></i> <span x-text="scanned ? 'Przejrzyj ponownie' : 'Przejrzyj skrzynkę'">Przejrzyj skrzynkę</span>
      </button>
      <form method="post" action="<?= h(route('gosposia.disconnect')) ?>">
        <?= Router::csrfField() ?>
        <button type="submit" class="gp-btn-ghost"><i class="bi bi-x-circle" aria-hidden="true"></i> Zapomnij skrzynkę</button>
      </form>
    </div>
  </section>

  <div role="status" aria-live="polite" aria-atomic="true" class="tw-mb-3">
    <p class="gp-status tw-m-0" x-show="status" x-text="status" tabindex="-1" x-ref="status" x-cloak></p>
  </div>
  <div role="alert" class="tw-mb-3">
    <p class="gp-error tw-m-0" x-show="error" x-text="error" x-cloak></p>
  </div>

  <section x-show="scanned" x-cloak aria-labelledby="gp-list-h">
    <div class="tw-mb-2 tw-flex tw-flex-wrap tw-items-end tw-justify-between tw-gap-2">
      <h2 id="gp-list-h" class="tw-m-0 tw-text-lg tw-font-semibold">Do sprzątania: <span x-text="senders.length"></span> <span x-text="plural(senders.length, 'nadawca', 'nadawców', 'nadawców')"></span></h2>
      <div class="tw-flex tw-gap-2" x-show="senders.length">
        <button type="button" class="gp-btn-small gp-btn-ghost" @click="selectAll(true)">Zaznacz wszystko</button>
        <button type="button" class="gp-btn-small gp-btn-ghost" @click="selectAll(false)">Odznacz wszystko</button>
      </div>
    </div>
    <p class="gp-meta tw-m-0 tw-mb-3" x-text="statsText()"></p>
    <p class="gp-meta tw-m-0 tw-mb-3" x-show="!senders.length">Nie znaleźliśmy reklam ani newsletterów do sprzątania.</p>

    <ul class="tw-m-0 tw-list-none tw-space-y-3 tw-p-0">
      <template x-for="s in senders" :key="s.email">
        <li class="gp-sender" :class="countSel(s) ? 'gp-sender-on' : ''">
          <div class="tw-flex tw-items-start tw-gap-3">
            <input type="checkbox" :id="'gp-s-' + s.idx" class="tw-mt-1" :checked="countSel(s) === s.messages.length"
                   x-effect="$el.indeterminate = countSel(s) > 0 && countSel(s) < s.messages.length" @change="toggleSender(s, $event.target.checked)">
            <div class="tw-min-w-0 tw-flex-1">
              <label :for="'gp-s-' + s.idx" class="tw-block tw-font-semibold tw-text-slate-900">
                <span x-text="s.name || s.email"></span>
                <span class="gp-sr" x-text="'— zaznacz wszystkie wiadomości od tego nadawcy'"></span>
              </label>
              <p class="gp-meta tw-m-0 tw-break-all" x-text="s.email" x-show="s.name"></p>
              <p class="tw-m-0 tw-mt-1 tw-flex tw-flex-wrap tw-items-center tw-gap-2">
                <span class="gp-badge" :class="'gp-cat-' + s.category" x-text="s.label"></span>
                <span class="gp-meta" x-text="s.reason"></span>
                <span class="gp-src" x-text="(s.source === 'ai' ? 'AI' : 'reguły') + ', pewność ' + Math.round(s.confidence * 100) + '%'"></span>
              </p>
              <p class="gp-meta tw-m-0 tw-mt-1">
                <span x-text="s.count + ' ' + plural(s.count, 'wiadomość', 'wiadomości', 'wiadomości') + ', ' + mb(s.size) + ' MB'"></span>
                <span x-show="countSel(s) && countSel(s) < s.messages.length" x-text="' · zaznaczono ' + countSel(s)"></span>
              </p>
              <div class="tw-mt-2 tw-flex tw-flex-wrap tw-gap-2">
                <button type="button" class="gp-btn-small gp-btn-ghost" @click="s.open = !s.open" :aria-expanded="s.open ? 'true' : 'false'" :aria-controls="'gp-m-' + s.idx">
                  <i class="bi" :class="s.open ? 'bi-chevron-up' : 'bi-chevron-down'" aria-hidden="true"></i>
                  <span x-text="(s.open ? 'Ukryj' : 'Pokaż') + ' wiadomości (' + s.messages.length + ')'"></span>
                </button>
                <button type="button" class="gp-btn-small gp-btn-ghost" @click="whitelist(s, s.email)" :disabled="busy">
                  <i class="bi bi-shield-check" aria-hidden="true"></i> Dodaj do białej listy<span class="gp-sr" x-text="': ' + s.email"></span>
                </button>
                <button type="button" class="gp-btn-small gp-btn-ghost" @click="whitelist(s, s.domain)" :disabled="busy">
                  Zaufaj całej domenie <span x-text="s.domain"></span>
                </button>
              </div>
              <ul class="gp-msgs" :id="'gp-m-' + s.idx" x-show="s.open" :aria-label="'Wiadomości od ' + (s.name || s.email)">
                <template x-for="m in s.messages" :key="m.uid">
                  <li class="gp-msg">
                    <input type="checkbox" :id="'gp-u-' + m.uid" :checked="!!sel[m.uid]" @change="sel[m.uid] = $event.target.checked">
                    <label :for="'gp-u-' + m.uid" class="tw-min-w-0 tw-flex-1">
                      <span class="tw-block tw-break-words" x-text="m.subject || '(bez tematu)'"></span>
                      <span class="gp-meta" x-text="(m.date || 'bez daty') + ' · ' + kb(m.size)"></span>
                    </label>
                  </li>
                </template>
              </ul>
            </div>
          </div>
        </li>
      </template>
    </ul>

    <div class="gp-bar" x-show="senders.length">
      <p class="tw-m-0 tw-text-sm tw-text-slate-800" x-text="selectionText()"></p>
      <button type="button" class="gp-btn-danger" x-ref="trashBtn" @click="openConfirm()" :disabled="busy || !total().count">
        <i class="bi bi-trash3" aria-hidden="true"></i>
        <span x-text="'Przenieś zaznaczone do Kosza (' + total().count + ' ' + plural(total().count, 'wiadomość', 'wiadomości', 'wiadomości') + ', ' + mb(total().bytes) + ' MB)'"></span>
      </button>
    </div>
  </section>

  <details class="gp-card tw-mt-6">
    <summary class="tw-cursor-pointer tw-font-semibold">Biała lista: zaufani nadawcy</summary>
    <p class="gp-help">Poczta od tych nadawców nigdy nie trafia do oceny AI ani na listę do sprzątania.
      Zawsze zaufane: <?= h(implode(', ', $whitelist['global'])) ?>.</p>
    <form class="tw-mt-3 tw-flex tw-flex-wrap tw-items-end tw-gap-2" @submit.prevent="addPattern()">
      <div class="tw-min-w-[16rem] tw-flex-1">
        <label class="gp-label" for="gp-wl-new">Adres e-mail albo domena</label>
        <input class="gp-input" id="gp-wl-new" type="text" x-model="newPattern" placeholder="np. jan@firma.pl albo firma.pl" aria-describedby="gp-wl-help">
        <p class="gp-help" id="gp-wl-help">Domena obejmuje też swoje subdomeny.</p>
      </div>
      <button type="submit" class="gp-btn-ghost" :disabled="busy">Dodaj</button>
    </form>
    <p class="gp-meta tw-mt-3" x-show="!own.length">Nie dodałeś jeszcze własnych zaufanych nadawców.</p>
    <ul class="tw-m-0 tw-mt-3 tw-list-none tw-space-y-1 tw-p-0" x-show="own.length" aria-label="Twoja biała lista">
      <template x-for="w in own" :key="w.id">
        <li class="tw-flex tw-items-center tw-justify-between tw-gap-2">
          <span class="tw-break-all" x-text="w.pattern"></span>
          <button type="button" class="gp-btn-small gp-btn-ghost" @click="removePattern(w)" :disabled="busy">Usuń<span class="gp-sr" x-text="' ' + w.pattern"></span></button>
        </li>
      </template>
    </ul>
  </details>

  <div class="gp-overlay" x-show="confirmOpen" x-cloak @keydown.escape.window="confirmOpen && closeConfirm()">
    <div class="gp-dialog" role="dialog" aria-modal="true" aria-labelledby="gp-dlg-h" aria-describedby="gp-dlg-d"
         x-trap.noscroll.inert="confirmOpen" @click.outside="closeConfirm()">
      <h2 id="gp-dlg-h" class="tw-m-0 tw-text-lg tw-font-semibold">Przenieść do Kosza?</h2>
      <p id="gp-dlg-d" class="tw-mb-2 tw-mt-3" x-text="confirmText()"></p>
      <p class="gp-help tw-mb-4">Wiadomości trafią do folderu <strong x-text="trashName || 'Kosz'"></strong>. Możesz je stamtąd przywrócić w poczcie.</p>
      <div class="tw-flex tw-flex-wrap tw-justify-end tw-gap-2">
        <button type="button" class="gp-btn-ghost" @click="closeConfirm()" x-ref="cancelBtn">Anuluj</button>
        <button type="button" class="gp-btn-danger" @click="doTrash()" :disabled="busy"><i class="bi bi-trash3" aria-hidden="true"></i> Tak, przenieś do Kosza</button>
      </div>
    </div>
  </div>
<?php endif; ?>
</div>

<script>
document.addEventListener('alpine:init', () => {
  Alpine.data('gosposia', (cfg) => ({
    senders: [], sel: {}, own: cfg.own || [], stats: null, trashName: '',
    scanned: false, busy: false, phase: '', status: '', error: '', confirmOpen: false, newPattern: '',

    plural(n, one, few, many) {
      if (n === 1) return one;
      const d = n % 10, h = n % 100;
      return (d >= 2 && d <= 4 && !(h >= 12 && h <= 14)) ? few : many;
    },
    mb(b) { return (b / 1048576).toLocaleString('pl-PL', { minimumFractionDigits: 1, maximumFractionDigits: 1 }); },
    kb(b) { return b < 1048576 ? Math.max(1, Math.round(b / 1024)) + ' kB' : this.mb(b) + ' MB'; },

    async post(url, data) {
      const fd = new FormData();
      fd.append('_csrf', cfg.csrf);
      for (const [k, v] of Object.entries(data || {})) fd.append(k, v);
      let res, json = null;
      try {
        res = await fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
        json = await res.json();
      } catch (e) {
        throw new Error(res && res.status === 403 ? 'Sesja wygasła. Odśwież stronę.' : 'Brak odpowiedzi serwera. Spróbuj ponownie.');
      }
      if (!json || !json.ok) throw new Error((json && json.error) || 'Coś poszło nie tak. Spróbuj ponownie.');
      return json;
    },

    async scan() {
      this.busy = true; this.phase = 'scan'; this.error = '';
      this.status = 'Przeglądam skrzynkę i oceniam nadawców. Przy dużej skrzynce to może potrwać minutę.';
      try {
        const r = await this.post(cfg.urls.scan);
        this.senders = r.senders.map((s, i) => ({ ...s, idx: i, open: false }));
        this.sel = {};
        this.senders.forEach(s => { if (s.preselect) s.messages.forEach(m => this.sel[m.uid] = true); });
        this.stats = r.stats; this.trashName = r.stats.trash || ''; this.scanned = true;
        this.status = 'Gotowe. Do sprzątania: ' + this.senders.length + ' ' + this.plural(this.senders.length, 'nadawca', 'nadawców', 'nadawców')
          + '. Zaznaczyliśmy tych, co do których mamy dużą pewność. Sprawdź listę przed przeniesieniem.';
      } catch (e) { this.status = ''; this.error = e.message; }
      this.busy = false; this.phase = '';
    },

    statsText() {
      const s = this.stats; if (!s) return '';
      let t = 'Przejrzano ' + s.scanned + ' z ' + s.inbox_total + ' wiadomości w Odebranych' + (s.limited ? ' (najnowsze)' : '') + '. ';
      t += 'Od zaufanych nadawców: ' + s.whitelisted + ' (pominięte). ';
      if (s.fallback_batches && !s.ai_batches) t += 'Ocena AI była niedostępna, użyliśmy reguł awaryjnych: sprawdź listę szczególnie uważnie.';
      else if (s.fallback_batches) t += 'Części nadawców nie oceniło AI, użyliśmy reguł awaryjnych.';
      if (!s.trash) t += ' Uwaga: nie znaleźliśmy folderu Kosz, więc przeniesienie jest niemożliwe.';
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
      return t.count ? 'Zaznaczono ' + t.count + ' ' + this.plural(t.count, 'wiadomość', 'wiadomości', 'wiadomości') + ' od ' + t.senders + ' ' + this.plural(t.senders, 'nadawcy', 'nadawców', 'nadawców') + '.'
                     : 'Nic nie zaznaczono.';
    },
    confirmText() {
      const t = this.total();
      return 'Czy na pewno chcesz przenieść ' + t.count + ' ' + this.plural(t.count, 'wiadomość', 'wiadomości', 'wiadomości') + ' od ' + t.senders + ' '
        + this.plural(t.senders, 'nadawcy', 'nadawców', 'nadawców') + ' do Kosza na dPoczta (' + this.mb(t.bytes) + ' MB)?';
    },

    openConfirm() { if (!this.total().count) return; this.confirmOpen = true; this.$nextTick(() => this.$refs.cancelBtn.focus()); },
    closeConfirm() { this.confirmOpen = false; this.$nextTick(() => this.$refs.trashBtn && this.$refs.trashBtn.focus()); },

    async doTrash() {
      const selection = {};
      for (const s of this.senders) {
        const uids = s.messages.filter(m => this.sel[m.uid]).map(m => m.uid);
        if (uids.length) selection[s.email] = uids;
      }
      this.busy = true; this.error = ''; this.status = 'Przenoszę wiadomości do Kosza…';
      try {
        const r = await this.post(cfg.urls.trash, { selection: JSON.stringify(selection) });
        const moved = new Set(Object.values(selection).flat());
        this.senders = this.senders.map(s => {
          const left = s.messages.filter(m => !moved.has(m.uid));
          return { ...s, messages: left, count: left.length, size: left.reduce((a, m) => a + m.size, 0) };
        }).filter(s => s.messages.length);
        moved.forEach(u => delete this.sel[u]);
        this.status = 'Przeniesiono ' + r.moved + ' ' + this.plural(r.moved, 'wiadomość', 'wiadomości', 'wiadomości') + ' od ' + r.senders + ' '
          + this.plural(r.senders, 'nadawcy', 'nadawców', 'nadawców') + ' do folderu ' + (r.folder || 'Kosz') + ' (' + this.mb(r.bytes) + ' MB).';
        this.confirmOpen = false;
        this.$nextTick(() => this.$refs.status.focus());
      } catch (e) { this.status = ''; this.error = e.message; this.closeConfirm(); }
      this.busy = false;
    },

    async whitelist(s, pattern) {
      this.busy = true; this.error = '';
      try {
        const r = await this.post(cfg.urls.wlAdd, { pattern });
        this.own = r.own;
        const isDomain = !r.pattern.includes('@');
        const gone = this.senders.filter(x => isDomain ? (x.domain === r.pattern || x.domain.endsWith('.' + r.pattern)) : x.email === r.pattern);
        gone.forEach(x => x.messages.forEach(m => delete this.sel[m.uid]));
        this.senders = this.senders.filter(x => !gone.includes(x));
        this.status = 'Dodano ' + r.pattern + ' do białej listy. Usunięto z listy do sprzątania: ' + gone.length + ' ' + this.plural(gone.length, 'nadawcę', 'nadawców', 'nadawców') + '.';
      } catch (e) { this.error = e.message; }
      this.busy = false;
    },

    async addPattern() {
      if (!this.newPattern.trim()) { this.error = 'Wpisz adres e-mail albo domenę.'; document.getElementById('gp-wl-new').focus(); return; }
      await this.whitelist(null, this.newPattern.trim());
      if (!this.error) this.newPattern = '';
    },

    async removePattern(w) {
      this.busy = true; this.error = '';
      try {
        const r = await this.post(cfg.urls.wlRemove, { id: w.id });
        this.own = r.own;
        this.status = 'Usunięto ' + w.pattern + ' z białej listy. Przejrzyj skrzynkę ponownie, żeby uwzględnić tego nadawcę.';
      } catch (e) { this.error = e.message; }
      this.busy = false;
    },
  }));
});
</script>
<?php include dirname(__DIR__, 3) . '/includes/footer.php'; ?>
