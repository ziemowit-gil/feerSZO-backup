<?php
/**
 * Kreator konfiguracji Exchange Online dla automatycznej stopki (tylko administrator).
 * Kroki i ich stan: stopkaExWizardState() w logic/stopkaExchange.php; odświeżanie przez
 * POST _action=wizard_check (JSON). Wymaga: $self, csrf_token(), Alpine.
 */
$wzInit = stopkaExWizardState(false);
$wzSteps = [
    ['app', 'Aplikacja Microsoft 365 w SZO', 'SZO łączy się z Exchange tą samą aplikacją co z Microsoft Graph (identyfikator dzierżawy, Client ID i sekret z ustawień Microsoft 365).'],
    ['perm', 'Uprawnienie Exchange.ManageAsApp', 'Entra ID → Rejestracje aplikacji → ta aplikacja → Uprawnienia API → Dodaj uprawnienie → zakładka „API używane przez moją organizację” → Office 365 Exchange Online → Uprawnienia aplikacji → zaznacz Exchange.ManageAsApp → Dodaj.'],
    ['consent', 'Zgoda administratora', 'Na tej samej stronie (Uprawnienia API) kliknij „Udziel zgody administratora dla …” i potwierdź. W kolumnie Stan ma pojawić się zielony znacznik.'],
    ['role', 'Rola „Exchange Administrator” dla aplikacji', 'Entra ID → Role i administratorzy → Exchange Administrator → Dodaj przypisania → wyszukaj aplikację po nazwie lub Client ID → Przypisz. Uprawnienia działają po kilku–kilkunastu minutach.'],
    ['rule', 'Reguła transportowa stopki', 'Gdy kroki 1–4 są zielone, włącz synchronizację poniżej i kliknij „Zapisz i wypchnij do Exchange”. Zacznij od trybu Audit.'],
];
?>
<section class="mt-group" aria-labelledby="h-wz"
  x-data='stopkaWizard(<?= json_encode(['state' => $wzInit, 'url' => $self, 'csrf' => csrf_token()], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>)' x-init="check()">
  <div class="tw-flex tw-flex-wrap tw-items-start tw-justify-between tw-gap-3 tw-mb-4">
    <div>
      <h2 id="h-wz" class="mt-h2">Kreator konfiguracji <b>Exchange Online</b></h2>
      <p class="tw-text-sm tw-text-slate-600">Pięć kroków w Entra ID, każdy sprawdzany na żywo. Kreator tylko czyta — niczego nie zmienia w Microsoft 365.</p>
    </div>
    <button type="button" @click="check()" :disabled="busy" class="tw-rounded tw-bg-white tw-text-slate-800 tw-border tw-border-solid tw-border-slate-400 tw-px-4 tw-py-2 hover:tw-bg-slate-50 disabled:tw-opacity-60">
      <span x-text="busy ? 'Sprawdzam…' : 'Sprawdź ponownie'"></span>
    </button>
  </div>

  <!-- pasek postępu -->
  <ol class="tw-list-none tw-m-0 tw-p-0 tw-flex tw-items-center tw-mb-5" aria-label="Postęp konfiguracji">
    <?php foreach ($wzSteps as $i => [$key, $title]): ?>
    <li class="tw-flex tw-items-center <?= $i < count($wzSteps) - 1 ? 'tw-flex-1' : '' ?>">
      <button type="button" @click="open = '<?= $key ?>'" :class="dot('<?= $key ?>')" :aria-current="open === '<?= $key ?>' ? 'step' : null"
        class="tw-w-9 tw-h-9 tw-rounded-full tw-flex tw-items-center tw-justify-center tw-font-bold tw-text-sm tw-border-2 tw-border-solid tw-shrink-0"
        :aria-label="'Krok <?= $i + 1 ?>: <?= h($title) ?> — ' + label('<?= $key ?>')">
        <span x-text="icon('<?= $key ?>') || '<?= $i + 1 ?>'"></span>
      </button>
      <?php if ($i < count($wzSteps) - 1): ?>
      <span class="tw-h-1 tw-flex-1 tw-mx-1 tw-rounded" :class="st('<?= $key ?>') === 'ok' ? 'tw-bg-green-600' : 'tw-bg-slate-200'" aria-hidden="true"></span>
      <?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ol>

  <p class="tw-text-sm tw-mb-3" role="status" aria-live="polite">
    <span x-show="done" x-cloak class="tw-text-green-700 tw-font-semibold">Konfiguracja Exchange kompletna.</span>
    <span x-show="!done && !busy" x-cloak>Do zrobienia: <strong x-text="firstOpenTitle()"></strong>.</span>
    <span x-show="err" x-cloak class="tw-text-red-700" x-text="err"></span>
    <span class="tw-text-slate-500" x-show="state.checkedAt && !busy" x-cloak> · sprawdzono <span x-text="state.checkedAt"></span></span>
  </p>

  <div class="tw-space-y-2">
    <?php foreach ($wzSteps as $i => [$key, $title, $how]): ?>
    <div class="tw-border tw-border-solid tw-rounded" :class="st('<?= $key ?>') === 'fail' ? 'tw-border-red-300' : (st('<?= $key ?>') === 'ok' ? 'tw-border-green-300' : 'tw-border-slate-200')">
      <h3 class="tw-m-0 tw-text-base">
        <button type="button" @click="open = open === '<?= $key ?>' ? '' : '<?= $key ?>'" :aria-expanded="open === '<?= $key ?>'" aria-controls="wz-<?= $key ?>"
          class="tw-w-full tw-flex tw-items-center tw-gap-3 tw-text-left tw-bg-transparent tw-border-0 tw-px-4 tw-py-3 tw-cursor-pointer">
          <span class="tw-w-6 tw-text-center tw-font-bold" :class="txt('<?= $key ?>')" aria-hidden="true" x-text="icon('<?= $key ?>') || '<?= $i + 1 ?>'"></span>
          <span class="tw-flex-1 tw-font-semibold"><?= $i + 1 ?>. <?= h($title) ?></span>
          <span class="tw-text-xs tw-rounded tw-px-2 tw-py-0.5" :class="badge('<?= $key ?>')" x-text="label('<?= $key ?>')"></span>
        </button>
      </h3>
      <div id="wz-<?= $key ?>" x-show="open === '<?= $key ?>'" x-cloak class="tw-px-4 tw-pb-4 tw-space-y-3 tw-text-sm">
        <p class="tw-m-0" :class="txt('<?= $key ?>')" x-text="state.steps['<?= $key ?>']?.msg"></p>
        <p class="tw-m-0 tw-text-slate-700"><?= h($how) ?></p>
        <?php if ($key === 'app'): ?>
          <dl class="tw-grid tw-grid-cols-[auto_1fr_auto] tw-gap-x-3 tw-gap-y-1 tw-m-0 tw-items-center">
            <template x-for="[k, v] in [['Dzierżawa (tenant)', state.tenant], ['Client ID', state.clientId]]" :key="k">
              <div class="tw-contents">
                <dt class="tw-text-slate-500" x-text="k"></dt>
                <dd class="tw-m-0 tw-font-mono tw-text-xs tw-break-all" x-text="v || '— brak —'"></dd>
                <dd class="tw-m-0"><button type="button" x-show="v" @click="copy(v, k)" class="tw-text-xs tw-rounded tw-border tw-border-solid tw-border-slate-300 tw-bg-white tw-px-2 tw-py-1" x-text="copied === k ? 'Skopiowano ✔' : 'Kopiuj'"></button></dd>
              </div>
            </template>
          </dl>
          <a x-show="state.links.overview" :href="state.links.overview" target="_blank" rel="noopener" class="tw-inline-block tw-text-blue-700 tw-underline">Otwórz aplikację w Entra ID ↗</a>
        <?php elseif ($key === 'perm' || $key === 'consent'): ?>
          <a x-show="state.links.perms" :href="state.links.perms" target="_blank" rel="noopener"
            class="tw-inline-block tw-rounded tw-bg-blue-700 tw-text-white tw-px-4 tw-py-2 hover:tw-bg-blue-800 tw-no-underline">Otwórz „Uprawnienia API” tej aplikacji ↗</a>
          <?php if ($key === 'perm'): ?>
          <p class="tw-m-0 tw-text-xs tw-text-slate-500">Nie widać „Office 365 Exchange Online”? Wpisz w wyszukiwarkę zakładki „API używane przez moją organizację” identyfikator <code>00000002-0000-0ff1-ce00-000000000000</code>.</p>
          <?php endif; ?>
        <?php elseif ($key === 'role'): ?>
          <a :href="state.links.role" target="_blank" rel="noopener"
            class="tw-inline-block tw-rounded tw-bg-blue-700 tw-text-white tw-px-4 tw-py-2 hover:tw-bg-blue-800 tw-no-underline">Otwórz rolę „Exchange Administrator” ↗</a>
        <?php elseif ($key === 'rule'): ?>
          <a href="#h-ex" class="tw-inline-block tw-text-blue-700 tw-underline">Przejdź do ustawień synchronizacji ↓</a>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <details class="tw-mt-4 tw-border tw-border-solid tw-border-slate-200 tw-rounded">
    <summary class="tw-cursor-pointer tw-px-4 tw-py-3 tw-font-semibold tw-text-sm">Szybciej: kroki 2–4 jednym skryptem PowerShell</summary>
    <div class="tw-px-4 tw-pb-4 tw-space-y-2 tw-text-sm">
      <p class="tw-m-0 tw-text-slate-600">Uruchamia administrator globalny (lub administrator ról uprzywilejowanych + aplikacji). Skrypt jest idempotentny — można go puścić ponownie.</p>
      <pre class="tw-m-0 tw-bg-slate-900 tw-text-slate-100 tw-text-xs tw-rounded tw-p-3 tw-overflow-x-auto" x-text="state.script"></pre>
      <button type="button" @click="copy(state.script, 'script')" class="tw-rounded tw-bg-slate-700 tw-text-white tw-px-4 tw-py-2 hover:tw-bg-slate-800 tw-border-0" x-text="copied === 'script' ? 'Skopiowano ✔' : 'Kopiuj skrypt'"></button>
    </div>
  </details>
</section>

<script>
function stopkaWizard(d) {
  const ORDER = ['app', 'perm', 'consent', 'role', 'rule'];
  const TITLES = <?= json_encode(array_column($wzSteps, 1, 0), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  return {
    state: d.state, busy: false, err: '', open: '', copied: '',
    get done() { return ORDER.every(k => ['ok', 'info'].includes(this.st(k))) && this.st('role') === 'ok'; },
    st(k) { return this.state.steps[k]?.status || 'loading'; },
    firstOpen() { return ORDER.find(k => this.st(k) !== 'ok' && !(k === 'rule' && this.st(k) === 'info')) || ''; },
    firstOpenTitle() { const k = this.firstOpen(); return k ? TITLES[k] : '—'; },
    icon(k) { return {ok: '✔', fail: '✖', info: 'ℹ', loading: '…'}[this.st(k)] || ''; },
    label(k) { return {ok: 'Gotowe', fail: 'Do poprawy', info: 'Informacja', wait: 'Czeka', loading: 'Sprawdzanie…'}[this.st(k)]; },
    txt(k) { return {ok: 'tw-text-green-700', fail: 'tw-text-red-700', info: 'tw-text-blue-700'}[this.st(k)] || 'tw-text-slate-500'; },
    badge(k) { return {ok: 'tw-bg-green-100 tw-text-green-800', fail: 'tw-bg-red-100 tw-text-red-800', info: 'tw-bg-blue-100 tw-text-blue-800'}[this.st(k)] || 'tw-bg-slate-100 tw-text-slate-700'; },
    dot(k) {
      const ring = this.open === k ? ' tw-ring-2 tw-ring-offset-2 tw-ring-blue-500' : '';
      return ({ok: 'tw-bg-green-600 tw-border-green-600 tw-text-white', fail: 'tw-bg-white tw-border-red-600 tw-text-red-700', info: 'tw-bg-white tw-border-blue-600 tw-text-blue-700'}[this.st(k)]
        || 'tw-bg-white tw-border-slate-300 tw-text-slate-500') + ring;
    },
    async check() {
      this.busy = true; this.err = '';
      try {
        const fd = new FormData(); fd.append('_csrf', d.csrf); fd.append('_action', 'wizard_check');
        const r = await fetch(d.url, {method: 'POST', body: fd, headers: {'Accept': 'application/json'}});
        if (!r.ok) throw new Error('HTTP ' + r.status);
        this.state = await r.json();
        this.open = this.firstOpen();
      } catch (e) {
        this.err = 'Nie udało się sprawdzić: ' + e.message;
      } finally { this.busy = false; }
    },
    async copy(text, key) {
      try { await navigator.clipboard.writeText(text); this.copied = key; setTimeout(() => this.copied = '', 2000); }
      catch (e) { this.err = 'Przeglądarka zablokowała schowek — zaznacz tekst ręcznie.'; }
    }
  };
}
</script>
