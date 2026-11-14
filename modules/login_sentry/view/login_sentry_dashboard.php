<?php
/**
 * modules/login_sentry/view/login_sentry_dashboard.php — panel LoginSentry („Staszek”).
 * Tailwind (CDN z includes/header.php, prefiks tw-, bez preflightu: obramowania wymagają tw-border-solid) + Alpine. WCAG 2.2 AA:
 * jeden h1, sekcje h2, komunikaty w regionie aria-live="polite", tabele z <caption> i th scope="col", widoczny fokus,
 * etykiety pól, kontrast co najmniej 4.5:1 (slate-700+ na bieli). Wszystkie POST-y z csrf_field().
 * Zmienne z LoginSentryController::index(): $flash, $cfg, $stats, $incidents, $logins, $audit, $userEx, $netEx, $users,
 * $preview, $logtoReady.
 */
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    header('Location: ../router.php', true, 302);
    exit;
}

use Feer\Szo\LoginSentry\LoginSentryTemplates as T;
use Feer\Szo\LoginSentry\SzoDirectory;

$PAGE_TITLE = 'LoginSentry: podejrzane logowania';
include dirname(__DIR__, 3) . '/includes/header.php';

$loc = function (?string $utc): string {
    $d = SzoDirectory::utc($utc);
    return $d ? $d->setTimezone(new DateTimeZone('Europe/Warsaw'))->format('d.m.Y H:i') : '—';
};
$reasonsText = function (string $json): string {
    $r = json_decode($json, true) ?: [];
    $out = [];
    foreach ($r as $k => $v) $out[] = T::reasonLabel((string)$k, $v);
    return implode('; ', $out);
};
$statusLabel = ['notified' => 'Powiadomienie', 'suspended' => 'Zawieszone', 'suspend_failed' => 'Blokada nieudana',
                'reverted' => 'Cofnięte', 'dismissed' => 'Odrzucone', 'preview' => 'Podgląd'];
$statusClass = ['notified' => 'tw-bg-amber-100 tw-text-amber-900', 'suspended' => 'tw-bg-red-700 tw-text-white',
                'suspend_failed' => 'tw-bg-red-100 tw-text-red-900', 'reverted' => 'tw-bg-green-100 tw-text-green-900',
                'dismissed' => 'tw-bg-slate-200 tw-text-slate-900'];
$modeLabel = ['off' => 'Wyłączony', 'preview' => 'Podgląd (bez działań)', 'live' => 'Działa'];
$actionLabel = ['clean' => 'OK', 'exempt' => 'Wyjątek', 'warn' => 'Ostrzeżenie', 'suspend' => 'Blokada', 'repeat' => 'Już obsłużone', 'error' => 'Błąd', 'info' => 'Informacja'];
$mode = (string)$stats['mode'];
?>
<style type="text/tailwindcss">
  .ls-card { @apply tw-bg-white tw-rounded-lg tw-border tw-border-solid tw-border-slate-300 tw-p-4 tw-mb-4; }
  .ls-h2 { @apply tw-text-lg tw-font-semibold tw-text-slate-900 tw-mb-3 tw-mt-0; }
  .ls-btn { @apply tw-inline-flex tw-items-center tw-gap-2 tw-rounded-md tw-px-3 tw-py-2 tw-text-sm tw-font-medium tw-border tw-border-solid tw-cursor-pointer
                   focus-visible:tw-outline focus-visible:tw-outline-2 focus-visible:tw-outline-offset-2 focus-visible:tw-outline-blue-700; }
  .ls-btn-primary { @apply tw-bg-blue-700 tw-text-white tw-border-blue-700 hover:tw-bg-blue-800; }
  .ls-btn-danger { @apply tw-bg-red-700 tw-text-white tw-border-red-700 hover:tw-bg-red-800; }
  .ls-btn-plain { @apply tw-bg-white tw-text-slate-900 tw-border-slate-500 hover:tw-bg-slate-100; }
  .ls-table { @apply tw-w-full tw-text-sm tw-border-collapse; }
  .ls-table caption { @apply tw-text-left tw-text-slate-700 tw-pb-2; }
  .ls-table th { @apply tw-text-left tw-font-semibold tw-text-slate-900 tw-bg-slate-100 tw-px-2 tw-py-2 tw-border-b tw-border-solid tw-border-slate-300; }
  .ls-table td { @apply tw-px-2 tw-py-2 tw-border-b tw-border-solid tw-border-slate-200 tw-align-top tw-text-slate-900; }
  .ls-input { @apply tw-block tw-w-full tw-rounded-md tw-border tw-border-solid tw-border-slate-600 tw-px-2 tw-py-1.5 tw-text-sm tw-bg-white tw-text-slate-900
                     focus-visible:tw-outline focus-visible:tw-outline-2 focus-visible:tw-outline-blue-700; }
  .ls-label { @apply tw-block tw-text-sm tw-font-medium tw-text-slate-900 tw-mb-1; }
  .ls-hint { @apply tw-text-xs tw-text-slate-700 tw-mt-1; }
  .ls-badge { @apply tw-inline-block tw-rounded tw-px-2 tw-py-0.5 tw-text-xs tw-font-semibold; }
  .ls-stat { @apply tw-rounded-lg tw-border tw-border-solid tw-border-slate-300 tw-bg-white tw-p-3; }
</style>

<main class="tw-max-w-6xl tw-mx-auto tw-px-3 tw-py-4" aria-labelledby="ls-title">
  <h1 id="ls-title" class="tw-text-2xl tw-font-bold tw-text-slate-900 tw-mb-1">LoginSentry <span class="tw-font-normal tw-text-slate-700">(Staszek)</span></h1>
  <p class="tw-text-slate-700 tw-mb-4">Co 6 godzin sprawdza adresy IP i przeglądarki logowań do SZO i Systemu Tożsamość (Logto). Od <?= (int)$cfg['warn_score'] ?> punktów pisze do administratora, od <?= (int)$cfg['suspend_score'] ?> punktów zawiesza konto w obu systemach.</p>

  <div aria-live="polite" role="status" class="tw-mb-4">
    <?php if ($flash): $fc = ['success' => 'tw-bg-green-50 tw-border-green-700 tw-text-green-900', 'danger' => 'tw-bg-red-50 tw-border-red-700 tw-text-red-900', 'info' => 'tw-bg-blue-50 tw-border-blue-700 tw-text-blue-900'][$flash['type']] ?? 'tw-bg-slate-50 tw-border-slate-700'; ?>
      <p class="tw-rounded-md tw-border tw-border-solid tw-p-3 tw-m-0 <?= $fc ?>"><?= h($flash['msg']) ?></p>
    <?php endif; ?>
  </div>

  <section class="ls-card" aria-labelledby="ls-state">
    <h2 id="ls-state" class="ls-h2">Stan</h2>
    <p class="tw-mb-2">
      <span class="ls-badge <?= $mode === 'live' ? 'tw-bg-green-700 tw-text-white' : ($mode === 'preview' ? 'tw-bg-amber-100 tw-text-amber-900' : 'tw-bg-slate-700 tw-text-white') ?>"><?= h($modeLabel[$mode] ?? $mode) ?></span>
      <?php if ($stats['last_run']): ?>Ostatni przebieg: <?= h($loc($stats['last_run']['started_at'])) ?> (<?= h($stats['last_run']['trigger']) ?>).<?php else: ?>Jeszcze nie było przebiegu.<?php endif; ?>
    </p>
    <?php if (!$logtoReady): ?>
      <p class="tw-rounded-md tw-border tw-border-solid tw-border-amber-700 tw-bg-amber-50 tw-text-amber-900 tw-p-2 tw-text-sm">Brak połączenia z Logto: Staszek widzi tylko logowania do SZO i nie zawiesi konta w Systemie Tożsamość.</p>
    <?php endif; ?>
    <?php if ((int)$stats['cloudflare_24h'] > 0): ?>
      <p class="tw-rounded-md tw-border tw-border-solid tw-border-amber-700 tw-bg-amber-50 tw-text-amber-900 tw-p-2 tw-text-sm">W ostatniej dobie <?= (int)$stats['cloudflare_24h'] ?> logowań ma adres Cloudflare zamiast adresu osoby. Serwer SZO powinien odtwarzać prawdziwy adres (nagłówek CF-Connecting-IP), inaczej Staszek nie oceni sieci.</p>
    <?php endif; ?>
    <div class="tw-flex tw-flex-wrap tw-gap-2">
      <form method="post" action="<?= h(route('login_sentry.preview')) ?>"><?= csrf_field() ?>
        <button type="submit" class="ls-btn ls-btn-plain">Podgląd (bez działań)</button></form>
      <?php if ($mode !== 'off'): ?>
      <form method="post" action="<?= h(route('login_sentry.run')) ?>"><?= csrf_field() ?>
        <button type="submit" class="ls-btn ls-btn-primary">Uruchom przebieg teraz</button></form>
      <?php endif; ?>
    </div>
  </section>

  <section aria-labelledby="ls-stats" class="tw-mb-4">
    <h2 id="ls-stats" class="ls-h2">Liczby</h2>
    <dl class="tw-grid tw-gap-3 tw-grid-cols-2 md:tw-grid-cols-3 lg:tw-grid-cols-6 tw-m-0">
      <?php foreach ([
          ['Logowania (24 h)', $stats['logins_24h']], ['Z punktami (24 h)', $stats['flagged_24h']],
          ['Zdarzenia (30 dni)', $stats['incidents_30d']], ['Zawieszone teraz', $stats['suspended_open']],
          ['Osoby ze wzorcem', $stats['users_with_pattern']], ['Zaufane sieci', $stats['trusted_networks']],
      ] as [$label, $val]): ?>
        <div class="ls-stat"><dt class="tw-text-sm tw-text-slate-700"><?= h($label) ?></dt><dd class="tw-m-0 tw-text-2xl tw-font-bold tw-text-slate-900"><?= (int)$val ?></dd></div>
      <?php endforeach; ?>
    </dl>
  </section>

  <section class="ls-card" aria-labelledby="ls-incidents" id="zdarzenia"
           x-data="{ q: '', st: '', shown: 0,
                     ok(el) { return (!this.q || el.dataset.search.includes(this.q.toLowerCase())) && (!this.st || el.dataset.status === this.st) },
                     recount() { this.$nextTick(() => { this.shown = [...this.$refs.rows.children].filter(r => this.ok(r)).length }) } }"
           x-init="recount()">
    <h2 id="ls-incidents" class="ls-h2">Zdarzenia</h2>
    <div class="tw-grid tw-gap-3 md:tw-grid-cols-3 tw-items-end tw-mb-3">
      <div><label for="ls-q" class="ls-label">Szukaj (osoba, e-mail, IP)</label>
        <input id="ls-q" type="search" class="ls-input" x-model="q" @input="recount()" autocomplete="off"></div>
      <div><label for="ls-st" class="ls-label">Stan</label>
        <select id="ls-st" class="ls-input" x-model="st" @change="recount()">
          <option value="">Wszystkie</option>
          <?php foreach ($statusLabel as $k => $v): if ($k === 'preview') continue; ?><option value="<?= h($k) ?>"><?= h($v) ?></option><?php endforeach; ?>
        </select></div>
      <p class="tw-m-0 tw-text-sm tw-text-slate-700" aria-live="polite">Widoczne: <span x-text="shown"><?= count($incidents) ?></span> z <?= count($incidents) ?></p>
    </div>
    <div class="tw-overflow-x-auto">
      <table class="ls-table">
        <caption>Podejrzane logowania, najnowsze na górze. Jedno zdarzenie na osobę na dzień.</caption>
        <thead><tr><th scope="col">Dzień</th><th scope="col">Osoba</th><th scope="col">Punkty</th><th scope="col">Stan</th><th scope="col">Powody</th><th scope="col">IP</th><th scope="col">Działanie</th></tr></thead>
        <tbody x-ref="rows">
          <?php foreach ($incidents as $i): $ips = implode(', ', json_decode((string)$i['ips'], true) ?: []); ?>
            <tr data-status="<?= h($i['status']) ?>" data-search="<?= h(mb_strtolower($i['name'] . ' ' . $i['email'] . ' ' . $ips)) ?>" x-show="ok($el)">
              <td><?= h($i['day']) ?></td>
              <td><?= h($i['name'] ?: $i['email']) ?><br><span class="tw-text-slate-700"><?= h($i['email']) ?></span></td>
              <td><?= (int)$i['score'] ?></td>
              <td><span class="ls-badge <?= $statusClass[$i['status']] ?? '' ?>"><?= h($statusLabel[$i['status']] ?? $i['status']) ?></span>
                <?php if ($i['last_error'] !== ''): ?><br><span class="tw-text-red-800 tw-text-xs"><?= h($i['last_error']) ?></span><?php endif; ?>
                <?php if ($i['reverted_by'] !== ''): ?><br><span class="tw-text-xs tw-text-slate-700"><?= h($i['reverted_by']) ?>, <?= h($loc($i['reverted_at'])) ?></span><?php endif; ?></td>
              <td><?= h($reasonsText((string)$i['reasons'])) ?></td>
              <td class="tw-break-all"><?= h($ips) ?></td>
              <td>
                <?php if (in_array($i['status'], ['notified', 'suspended', 'suspend_failed'], true)): ?>
                  <form method="post" action="<?= h(route('login_sentry.revert')) ?>"
                        onsubmit="return confirm('<?= $i['status'] === 'notified' ? 'Uznać to logowanie za bezpieczne?' : 'Przywrócić dostęp do konta ' . h(addslashes((string)$i['email'])) . '?' ?>')">
                    <?= csrf_field() ?><input type="hidden" name="incident_id" value="<?= (int)$i['id'] ?>">
                    <button type="submit" class="ls-btn ls-btn-plain"><?= $i['status'] === 'notified' ? 'To była ta osoba' : 'To była ta osoba, cofnij' ?></button>
                  </form>
                <?php else: ?>—<?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (!$incidents): ?><p class="tw-text-slate-700 tw-mt-2">Na razie nic podejrzanego.</p><?php endif; ?>
    </div>
  </section>

  <section class="ls-card" aria-labelledby="ls-check" id="sprawdz">
    <h2 id="ls-check" class="ls-h2">Wymuś sprawdzenie osoby</h2>
    <form method="post" action="<?= h(route('login_sentry.check')) ?>" class="tw-grid tw-gap-3 md:tw-grid-cols-3 tw-items-end">
      <?= csrf_field() ?>
      <div class="md:tw-col-span-2"><label for="ls-check-user" class="ls-label">Osoba</label>
        <select id="ls-check-user" name="user_id" class="ls-input" required>
          <option value="">Wybierz…</option>
          <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"><?= h($u['name'] . ' (' . $u['email'] . ')') ?></option><?php endforeach; ?>
        </select>
        <p class="ls-hint">Ocenia jeszcze raz logowania tej osoby z ostatnich <?= (int)$cfg['window_hours'] ?> godzin, także już sprawdzone. W trybie podglądu tylko raport.</p></div>
      <div><button type="submit" class="ls-btn ls-btn-primary">Sprawdź teraz</button></div>
    </form>
  </section>

  <?php if ($preview): ?>
  <section class="ls-card" aria-labelledby="ls-preview" id="podglad">
    <h2 id="ls-preview" class="ls-h2">Ostatni podgląd (<?= h($loc($preview['at'] ?? null)) ?>)</h2>
    <div class="tw-overflow-x-auto">
      <table class="ls-table">
        <caption>Co zrobiłby przebieg. Nic nie zostało wysłane ani zablokowane.</caption>
        <thead><tr><th scope="col">Osoba</th><th scope="col">Wynik</th><th scope="col">Punkty</th><th scope="col">Uwagi</th></tr></thead>
        <tbody>
          <?php foreach ($preview['items'] ?? [] as $it): ?>
            <tr><td><?= h($it['email']) ?></td><td><?= h($actionLabel[$it['action']] ?? $it['action']) ?></td><td><?= (int)$it['score'] ?></td><td><?= h($it['note']) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (empty($preview['items'])): ?><p class="tw-text-slate-700 tw-mt-2">Brak nowych logowań w oknie.</p><?php endif; ?>
    </div>
  </section>
  <?php endif; ?>

  <section class="ls-card" aria-labelledby="ls-logins" x-data="{ q: '' }">
    <h2 id="ls-logins" class="ls-h2">Ostatnie sprawdzone logowania</h2>
    <div class="tw-max-w-sm tw-mb-3"><label for="ls-lq" class="ls-label">Szukaj (e-mail, IP, sieć, przeglądarka)</label>
      <input id="ls-lq" type="search" class="ls-input" x-model="q" autocomplete="off"></div>
    <div class="tw-overflow-x-auto">
      <table class="ls-table">
        <caption>200 ostatnich logowań ocenionych przez Staszka.</caption>
        <thead><tr><th scope="col">Kiedy</th><th scope="col">Źródło</th><th scope="col">E-mail</th><th scope="col">IP / sieć</th><th scope="col">Przeglądarka</th><th scope="col">Punkty</th></tr></thead>
        <tbody>
          <?php foreach ($logins as $l): ?>
            <tr data-search="<?= h(mb_strtolower($l['email'] . ' ' . $l['ip'] . ' ' . $l['network'] . ' ' . $l['ua_family'])) ?>" x-show="!q || $el.dataset.search.includes(q.toLowerCase())">
              <td><?= h($loc($l['occurred_at'])) ?></td><td><?= $l['source'] === 'logto' ? 'Logto' : 'SZO' ?></td><td><?= h($l['email']) ?></td>
              <td class="tw-break-all"><?= h($l['ip']) ?><?php if ($l['ip_state'] !== 'ok'): ?> <span class="ls-badge tw-bg-slate-200 tw-text-slate-900"><?= h($l['ip_state']) ?></span><?php endif; ?><br><span class="tw-text-slate-700"><?= h($l['network']) ?></span></td>
              <td><?= h($l['ua_family']) ?></td><td><?= (int)$l['score'] ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="ls-card" aria-labelledby="ls-ex" id="wyjatki">
    <h2 id="ls-ex" class="ls-h2">Wyjątki</h2>
    <h3 class="tw-text-base tw-font-semibold tw-text-slate-900 tw-mb-2">Osoby</h3>
    <form method="post" action="<?= h(route('login_sentry.userExAdd')) ?>" class="tw-grid tw-gap-3 md:tw-grid-cols-4 tw-items-end tw-mb-3">
      <?= csrf_field() ?>
      <div><label for="ls-ex-user" class="ls-label">Osoba</label>
        <select id="ls-ex-user" name="user_id" class="ls-input" required><option value="">Wybierz…</option>
          <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"><?= h($u['name'] . ' (' . $u['email'] . ')') ?></option><?php endforeach; ?>
        </select></div>
      <div><label for="ls-ex-reason" class="ls-label">Powód</label><input id="ls-ex-reason" name="reason" class="ls-input" maxlength="200" required placeholder="np. wyjazd służbowy"></div>
      <div><label for="ls-ex-days" class="ls-label">Na ile dni</label><input id="ls-ex-days" name="days" type="number" min="0" max="365" value="14" class="ls-input" aria-describedby="ls-ex-days-hint">
        <p id="ls-ex-days-hint" class="ls-hint">0 = bez końca</p></div>
      <div><button type="submit" class="ls-btn ls-btn-primary">Dodaj wyjątek</button></div>
    </form>
    <div class="tw-overflow-x-auto tw-mb-4">
      <table class="ls-table">
        <caption>Logowania tych osób są zapisywane, ale bez maili i blokad.</caption>
        <thead><tr><th scope="col">Osoba</th><th scope="col">Powód</th><th scope="col">Do</th><th scope="col">Dodał(a)</th><th scope="col">Działanie</th></tr></thead>
        <tbody>
          <?php foreach ($userEx as $e): ?>
            <tr><td><?= h($e['name']) ?><br><span class="tw-text-slate-700"><?= h($e['email']) ?></span></td><td><?= h($e['reason']) ?></td>
              <td><?= $e['expires_at'] ? h($loc($e['expires_at'])) : 'bez końca' ?></td><td><?= h($e['created_by']) ?></td>
              <td><form method="post" action="<?= h(route('login_sentry.userExRemove')) ?>"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int)$e['user_id'] ?>">
                <button type="submit" class="ls-btn ls-btn-plain">Usuń</button></form></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <h3 class="tw-text-base tw-font-semibold tw-text-slate-900 tw-mb-2">Zaufane sieci</h3>
    <form method="post" action="<?= h(route('login_sentry.netExAdd')) ?>" class="tw-grid tw-gap-3 md:tw-grid-cols-3 tw-items-end tw-mb-3">
      <?= csrf_field() ?>
      <div><label for="ls-net" class="ls-label">Adres IP albo zakres CIDR</label><input id="ls-net" name="cidr" class="ls-input" required placeholder="203.0.113.0/24" autocomplete="off"></div>
      <div><label for="ls-net-reason" class="ls-label">Powód</label><input id="ls-net-reason" name="reason" class="ls-input" maxlength="200" required placeholder="np. biuro FEER"></div>
      <div><button type="submit" class="ls-btn ls-btn-primary">Dodaj sieć</button></div>
    </form>
    <div class="tw-overflow-x-auto">
      <table class="ls-table">
        <caption>Logowania z tych sieci zawsze dostają 0 punktów<?= $cfg['trusted_cidrs'] ? '. Z pliku konfiguracji: ' . h(implode(', ', (array)$cfg['trusted_cidrs'])) : '' ?>.</caption>
        <thead><tr><th scope="col">Sieć</th><th scope="col">Powód</th><th scope="col">Dodał(a)</th><th scope="col">Działanie</th></tr></thead>
        <tbody>
          <?php foreach ($netEx as $n): ?>
            <tr><td><?= h($n['cidr']) ?></td><td><?= h($n['reason']) ?></td><td><?= h($n['created_by']) ?></td>
              <td><form method="post" action="<?= h(route('login_sentry.netExRemove')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                <button type="submit" class="ls-btn ls-btn-plain">Usuń</button></form></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="ls-card" aria-labelledby="ls-settings" id="ustawienia">
    <h2 id="ls-settings" class="ls-h2">Ustawienia</h2>
    <form method="post" action="<?= h(route('login_sentry.settings')) ?>" class="tw-grid tw-gap-3 md:tw-grid-cols-3">
      <?= csrf_field() ?>
      <div><label for="ls-mode" class="ls-label">Tryb</label>
        <select id="ls-mode" name="mode" class="ls-input">
          <?php foreach ($modeLabel as $k => $v): ?><option value="<?= h($k) ?>" <?= $mode === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?>
        </select>
        <p class="ls-hint">Podgląd ocenia i zapisuje w panelu, ale nie wysyła maili i nie blokuje.</p></div>
      <div><label for="ls-warn" class="ls-label">Próg ostrzeżenia (punkty)</label><input id="ls-warn" name="warn_score" type="number" min="10" max="200" value="<?= (int)$cfg['warn_score'] ?>" class="ls-input"></div>
      <div><label for="ls-susp" class="ls-label">Próg blokady (punkty)</label><input id="ls-susp" name="suspend_score" type="number" min="11" max="300" value="<?= (int)$cfg['suspend_score'] ?>" class="ls-input"></div>
      <div><label for="ls-fails" class="ls-label">Błędne hasła przed sukcesem</label><input id="ls-fails" name="fail_threshold" type="number" min="2" max="50" value="<?= (int)$cfg['fail_threshold'] ?>" class="ls-input">
        <p class="ls-hint">W ciągu <?= (int)$cfg['fail_window_min'] ?> minut przed udanym logowaniem.</p></div>
      <div><label for="ls-nets" class="ls-label">Różne sieci w jednym oknie</label><input id="ls-nets" name="many_networks" type="number" min="2" max="20" value="<?= (int)$cfg['many_networks'] ?>" class="ls-input"></div>
      <div><label for="ls-max" class="ls-label">Najwięcej blokad na przebieg</label><input id="ls-max" name="max_suspend_per_run" type="number" min="0" max="100" value="<?= (int)$cfg['max_suspend_per_run'] ?>" class="ls-input">
        <p class="ls-hint">Powyżej limitu tylko e-mail (masowe blokady to raczej awaria niż atak).</p></div>
      <div class="md:tw-col-span-2"><label for="ls-admins" class="ls-label">Adresy administratorów</label>
        <input id="ls-admins" name="admin_emails" class="ls-input" value="<?= h(implode(', ', (array)$cfg['admin_emails'])) ?>" aria-describedby="ls-admins-hint">
        <p id="ls-admins-hint" class="ls-hint">Oddzielone przecinkami. Puste pole: wszyscy aktywni administratorzy SZO.</p></div>
      <div class="tw-flex tw-items-center tw-gap-2 tw-self-center">
        <input id="ls-sa" name="suspend_admins" type="checkbox" value="1" <?= !empty($cfg['suspend_admins']) ? 'checked' : '' ?> class="tw-h-4 tw-w-4 focus-visible:tw-outline focus-visible:tw-outline-2 focus-visible:tw-outline-blue-700">
        <label for="ls-sa" class="tw-text-sm tw-text-slate-900">Blokuj też konta administratorów</label></div>
      <div class="md:tw-col-span-3"><button type="submit" class="ls-btn ls-btn-primary">Zapisz ustawienia</button></div>
    </form>
  </section>

  <section class="ls-card" aria-labelledby="ls-audit">
    <h2 id="ls-audit" class="ls-h2">Dziennik audytu</h2>
    <div class="tw-overflow-x-auto">
      <table class="ls-table">
        <caption>100 ostatnich wpisów: działania Staszka i administratorów.</caption>
        <thead><tr><th scope="col">Kiedy</th><th scope="col">Poziom</th><th scope="col">Zdarzenie</th><th scope="col">Konto</th><th scope="col">Kto</th></tr></thead>
        <tbody>
          <?php foreach ($audit as $a): ?>
            <tr><td><?= h($loc($a['created_at'])) ?></td>
              <td><span class="ls-badge <?= $a['level'] === 'ERROR' ? 'tw-bg-red-700 tw-text-white' : ($a['level'] === 'WARN' ? 'tw-bg-amber-100 tw-text-amber-900' : 'tw-bg-slate-200 tw-text-slate-900') ?>"><?= h($a['level']) ?></span></td>
              <td><?= h($a['event']) ?></td><td><?= h((string)($a['inc_email'] ?? '') ?: ($a['user_id'] ? '#' . $a['user_id'] : '—')) ?></td><td><?= h($a['actor']) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
</main>
<?php include dirname(__DIR__, 3) . '/includes/footer.php'; ?>
