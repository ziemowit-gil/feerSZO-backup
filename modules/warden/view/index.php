<?php
/**
 * modules/warden/view/index.php — panel AccountWarden („Mietek”).
 * Tailwind (CDN, prefiks tw-, bez preflightu: obramowania wymagają tw-border-solid) + Alpine. WCAG 2.2 AA:
 * jeden h1, sekcje h2, komunikaty w regionie aria-live, tabele z <caption> i scope="col", widoczny fokus, etykiety pól.
 * Adres /modules/warden/router.php#uruchom otwiera od razu okno „Uruchom AccountWarden” (link do wysłania administratorowi).
 * Zmienne: $flash, $mode, $liveFrom, $cfg, $open, $blocked, $history, $exemptions, $users, $lastRun, $preview,
 *          $announced, $announceTargets, $logtoReady.
 */
// Wejście wprost na plik widoku (bez routera) → panel. Szablon dostaje dane tylko z WardenController::index().
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    header('Location: ../router.php', true, 302);
    exit;
}

use Feer\Szo\Warden\WardenEngine;
use Feer\Szo\Warden\SzoDirectory;

$PAGE_TITLE = 'AccountWarden: nieaktywne konta';
include dirname(__DIR__, 3) . '/includes/header.php';

$loc = function (?string $utc, bool $time = true): string {
    $d = SzoDirectory::utc($utc);
    return $d ? $d->setTimezone(new DateTimeZone('Europe/Warsaw'))->format($time ? 'd.m.Y H:i' : 'd.m.Y') : '—';
};
$statusLabel = [
    'warning_1_sent' => 'Wysłany e-mail 1', 'warning_2_sent' => 'Wysłany e-mail 2', 'sms_sent' => 'Wysłany SMS',
    'deactivated' => 'Zablokowane', 'resolved_by_login' => 'Zalogował(a) się', 'reactivated' => 'Odblokowane', 'cancelled' => 'Anulowany',
];
$eventLabel = [
    'warning_1_sent' => 'E-mail 1', 'warning_2_sent' => 'E-mail 2', 'sms_sent' => 'SMS', 'sms_skipped' => 'SMS pominięty',
    'sms_failed' => 'SMS nieudany', 'deactivated' => 'Blokada', 'deactivate_failed' => 'Blokada nieudana', 'resolved_by_login' => 'Logowanie',
    'reactivated' => 'Odblokowanie', 'reactivated_outside' => 'Odblokowane poza WARDEN', 'cancelled' => 'Cykl anulowany',
    'exemption_added' => 'Dodany wyjątek', 'exemption_removed' => 'Usunięty wyjątek', 'settings_saved' => 'Zmiana ustawień',
    'launched' => 'Uruchomienie', 'stopped' => 'Zatrzymanie', 'resumed' => 'Wznowienie', 'announcement_sent' => 'Ogłoszenie',
];
$actionLabel = ['warning_1' => 'E-mail 1', 'warning_2' => 'E-mail 2', 'sms' => 'SMS', 'sms_skipped' => 'SMS pominięty', 'deactivate' => 'Blokada',
                'resolved' => 'Zalogował(a) się', 'cancelled' => 'Anulowanie', 'error' => 'Błąd', 'skip' => 'Pominięte', 'limit' => 'Limit', 'reactivated' => 'Odblokowane'];
$days = (int)$cfg['inactivity_days'];
?>
<style type="text/tailwindcss">
  .wd-card { @apply tw-bg-white tw-rounded-lg tw-border tw-border-solid tw-border-slate-300 tw-p-4 tw-mb-4; }
  .wd-h2 { @apply tw-text-lg tw-font-semibold tw-text-slate-900 tw-mb-3 tw-mt-0; }
  .wd-btn { @apply tw-inline-flex tw-items-center tw-gap-2 tw-rounded-md tw-px-3 tw-py-2 tw-text-sm tw-font-medium tw-border tw-border-solid tw-cursor-pointer
                   focus-visible:tw-outline focus-visible:tw-outline-2 focus-visible:tw-outline-offset-2 focus-visible:tw-outline-blue-700; }
  .wd-btn-primary { @apply tw-bg-blue-700 tw-text-white tw-border-blue-700 hover:tw-bg-blue-800; }
  .wd-btn-danger { @apply tw-bg-red-700 tw-text-white tw-border-red-700 hover:tw-bg-red-800; }
  .wd-btn-plain { @apply tw-bg-white tw-text-slate-900 tw-border-slate-400 hover:tw-bg-slate-100; }
  .wd-table { @apply tw-w-full tw-text-sm tw-border-collapse; }
  .wd-table caption { @apply tw-text-left tw-text-slate-700 tw-pb-2; }
  .wd-table th { @apply tw-text-left tw-font-semibold tw-text-slate-800 tw-bg-slate-100 tw-px-2 tw-py-2 tw-border-b tw-border-solid tw-border-slate-300; }
  .wd-table td { @apply tw-px-2 tw-py-2 tw-border-b tw-border-solid tw-border-slate-200 tw-align-top; }
  .wd-input { @apply tw-block tw-w-full tw-rounded-md tw-border tw-border-solid tw-border-slate-500 tw-px-2 tw-py-1.5 tw-text-sm tw-bg-white
                     focus-visible:tw-outline focus-visible:tw-outline-2 focus-visible:tw-outline-blue-700; }
  .wd-label { @apply tw-block tw-text-sm tw-font-medium tw-text-slate-900 tw-mb-1; }
  .wd-hint { @apply tw-text-xs tw-text-slate-700 tw-mt-1; }
  .wd-badge { @apply tw-inline-block tw-rounded tw-px-2 tw-py-0.5 tw-text-xs tw-font-semibold; }
</style>

<main class="tw-max-w-6xl tw-mx-auto tw-px-3 tw-py-4" aria-labelledby="wd-title">
  <h1 id="wd-title" class="tw-text-2xl tw-font-bold tw-text-slate-900 tw-mb-1">AccountWarden <span class="tw-font-normal tw-text-slate-700">(Mietek)</span></h1>
  <p class="tw-text-slate-700 tw-mb-4">Pilnuje kont w Systemie Tożsamość (Logto) i w SZO. Po <?= $days ?> dniach bez logowania wysyła dwa e-maile i SMS, a po 7 dniach bez reakcji zawiesza konto w obu systemach.</p>

  <div aria-live="polite" role="status" class="tw-mb-4">
    <?php if ($flash): $fc = ['success' => 'tw-bg-green-50 tw-border-green-700 tw-text-green-900', 'danger' => 'tw-bg-red-50 tw-border-red-700 tw-text-red-900', 'info' => 'tw-bg-blue-50 tw-border-blue-700 tw-text-blue-900'][$flash['type']] ?? 'tw-bg-slate-50 tw-border-slate-700'; ?>
      <p class="tw-rounded-md tw-border tw-border-solid tw-p-3 tw-m-0 <?= $fc ?>"><?= h($flash['msg']) ?></p>
    <?php endif; ?>
  </div>

  <section class="wd-card" aria-labelledby="wd-state">
    <h2 id="wd-state" class="wd-h2">Stan</h2>
    <?php if ($mode === 'live'): ?>
      <p class="tw-mb-2"><span class="wd-badge tw-bg-green-700 tw-text-white">Działa</span>
        <?php if ($liveFrom && SzoDirectory::utc($liveFrom) > new DateTimeImmutable('now', new DateTimeZone('UTC'))): ?>
          Sprawdzanie kont zacznie się <?= h($loc($liveFrom)) ?>.
        <?php else: ?>Codzienny przebieg między 9:00 a 12:00.<?php endif; ?>
      </p>
    <?php else: ?>
      <p class="tw-mb-2"><span class="wd-badge tw-bg-slate-700 tw-text-white">Zatrzymany</span> Nie wysyła wiadomości i nie blokuje kont.</p>
    <?php endif; ?>
    <?php if (!$logtoReady): ?>
      <p class="tw-text-red-800 tw-mb-2">Brak połączenia z Logto. Skonfiguruj je w <a class="tw-text-blue-800 tw-underline" href="<?= h(rtrim(APP_URL, '/')) ?>/ssoadmin">ustawieniach logowania jednokrotnego</a>.</p>
    <?php endif; ?>
    <p class="tw-text-sm tw-text-slate-700 tw-mb-3">Ostatni przebieg: <?= $lastRun ? h($loc($lastRun['started_at'])) : 'jeszcze nie było' ?>.
      Ogłoszenie wysłane do <?= (int)$announced ?> osób.</p>

    <div class="tw-flex tw-flex-wrap tw-gap-2">
      <?php if ($mode !== 'live' && $announced === 0): ?>
        <div id="uruchom" x-data="{ open: false }" x-init="if (location.hash === '#uruchom') { open = true; $nextTick(() => $refs.dlg.focus()) }">
          <button type="button" class="wd-btn wd-btn-primary" @click="open = true; $nextTick(() => $refs.dlg.focus())" :aria-expanded="open" aria-controls="wd-launch">
            <i class="bi bi-play-fill" aria-hidden="true"></i> Uruchom AccountWarden
          </button>
          <div id="wd-launch" x-show="open" x-cloak x-trap.noscroll="open" @keydown.escape.window="open = false"
               class="tw-fixed tw-inset-0 tw-z-50 tw-flex tw-items-center tw-justify-center tw-bg-black/50 tw-p-4">
            <div x-ref="dlg" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="wd-launch-t" class="tw-bg-white tw-rounded-lg tw-max-w-lg tw-w-full tw-p-5 focus:tw-outline-none">
              <h2 id="wd-launch-t" class="wd-h2">Uruchomić AccountWarden?</h2>
              <p class="tw-text-sm tw-mb-2">Ogłoszenie „Poznajcie Mietka” trafi do <strong><?= (int)$announceTargets ?></strong> aktywnych kont SZO. Sprawdzanie kont i pierwsze ostrzeżenia ruszą najwcześniej za dobę.</p>
              <form method="post" action="<?= h(route('warden.launch')) ?>">
                <?= csrf_field() ?>
                <p class="tw-flex tw-items-start tw-gap-2 tw-text-sm tw-mb-4">
                  <input type="checkbox" id="wd-confirm" name="confirm" value="1" required class="tw-mt-1">
                  <label for="wd-confirm">Rozumiem, że ogłoszenie pójdzie do <?= (int)$announceTargets ?> osób i nie da się go cofnąć.</label>
                </p>
                <div class="tw-flex tw-gap-2 tw-justify-end">
                  <button type="button" class="wd-btn wd-btn-plain" @click="open = false">Anuluj</button>
                  <button type="submit" class="wd-btn wd-btn-primary">Uruchom i wyślij ogłoszenie</button>
                </div>
              </form>
            </div>
          </div>
        </div>
      <?php elseif ($mode !== 'live'): ?>
        <form method="post" action="<?= h(route('warden.resume')) ?>"><?= csrf_field() ?>
          <button type="submit" class="wd-btn wd-btn-primary"><i class="bi bi-play-fill" aria-hidden="true"></i> Wznów</button></form>
      <?php else: ?>
        <form method="post" action="<?= h(route('warden.run')) ?>"><?= csrf_field() ?>
          <button type="submit" class="wd-btn wd-btn-plain"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Przebieg teraz</button></form>
        <form method="post" action="<?= h(route('warden.stop')) ?>"><?= csrf_field() ?>
          <button type="submit" class="wd-btn wd-btn-danger"><i class="bi bi-stop-fill" aria-hidden="true"></i> Zatrzymaj</button></form>
      <?php endif; ?>
      <form method="post" action="<?= h(route('warden.preview')) ?>"><?= csrf_field() ?>
        <button type="submit" class="wd-btn wd-btn-plain"><i class="bi bi-eye" aria-hidden="true"></i> Podgląd (bez wysyłki)</button></form>
    </div>
  </section>

  <?php if ($preview): ?>
  <section class="wd-card" aria-labelledby="wd-prev">
    <h2 id="wd-prev" class="wd-h2">Podgląd z <?= h($loc($preview['at'] ?? null)) ?></h2>
    <?php if (!empty($preview['error'])): ?><p class="tw-text-red-800"><?= h($preview['error']) ?></p><?php endif; ?>
    <?php $pc = $preview['counts'] ?? []; ?>
    <p class="tw-text-sm">Sprawdzonych kont: <?= (int)($pc['checked'] ?? 0) ?>, wyjątków: <?= (int)($pc['exempt'] ?? 0) ?>, nowych ostrzeżeń: <?= (int)($pc['started'] ?? 0) ?>,
      ponad limit: <?= (int)($pc['limit_skipped'] ?? 0) ?>, błędów: <?= (int)($pc['errors'] ?? 0) ?>.</p>
    <?php if (!empty($preview['items'])): ?>
    <div class="tw-overflow-x-auto">
      <table class="wd-table">
        <caption>Co zrobiłby przebieg</caption>
        <thead><tr><th scope="col">Osoba</th><th scope="col">Działanie</th><th scope="col">Szczegóły</th></tr></thead>
        <tbody>
          <?php foreach (array_slice($preview['items'], 0, 200) as $i): ?>
            <tr><td><?= h($i['name'] ?: $i['email']) ?><br><span class="tw-text-slate-700"><?= h($i['email']) ?></span></td>
                <td><?= h($actionLabel[$i['action']] ?? $i['action']) ?></td><td><?= h($i['note']) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <section class="wd-card" aria-labelledby="wd-open">
    <h2 id="wd-open" class="wd-h2">Trwające odliczania (<?= count($open) ?>)</h2>
    <?php if (!$open): ?><p class="tw-text-slate-700 tw-m-0">Nikt nie jest teraz ostrzegany.</p><?php else: ?>
    <div class="tw-overflow-x-auto">
      <table class="wd-table">
        <caption>Osoby, które dostały ostrzeżenie i jeszcze się nie zalogowały</caption>
        <thead><tr><th scope="col">Osoba</th><th scope="col">Ostatnia aktywność</th><th scope="col">Etap</th><th scope="col">Termin blokady</th><th scope="col">Uwagi</th></tr></thead>
        <tbody>
          <?php foreach ($open as $c): ?>
            <tr><td><?= h((string)($c['name'] ?? '')) ?><br><span class="tw-text-slate-700"><?= h($c['email']) ?></span></td>
                <td><?= h($loc($c['last_activity_at_start'])) ?></td>
                <td><?= h($statusLabel[$c['status']] ?? $c['status']) ?><?= $c['sms_skipped'] !== '' ? ' (SMS: ' . h($c['sms_skipped']) . ')' : '' ?></td>
                <td><?= h($loc($c['deadline_at'])) ?></td>
                <td class="tw-text-red-800"><?= h($c['last_error']) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </section>

  <section class="wd-card" id="zablokowane" aria-labelledby="wd-blocked">
    <h2 id="wd-blocked" class="wd-h2">Zablokowane przez AccountWarden (<?= count($blocked) ?>)</h2>
    <?php if (!$blocked): ?><p class="tw-text-slate-700 tw-m-0">Brak.</p><?php else: ?>
    <div class="tw-overflow-x-auto">
      <table class="wd-table">
        <caption>Konta zawieszone w Logto i zablokowane w SZO</caption>
        <thead><tr><th scope="col">Osoba</th><th scope="col">Zablokowane</th><th scope="col">Działanie</th></tr></thead>
        <tbody>
          <?php foreach ($blocked as $c): ?>
            <tr><td><?= h((string)($c['name'] ?? '')) ?><br><span class="tw-text-slate-700"><?= h($c['email']) ?></span></td>
                <td><?= h($loc($c['deactivated_at'])) ?></td>
                <td><form method="post" action="<?= h(route('warden.reactivate')) ?>"><?= csrf_field() ?>
                  <input type="hidden" name="user_id" value="<?= (int)$c['user_id'] ?>">
                  <button type="submit" class="wd-btn wd-btn-plain">Odblokuj<span class="tw-sr-only"> konto <?= h((string)($c['name'] ?? $c['email'])) ?></span></button>
                </form></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </section>

  <section class="wd-card" id="wyjatki" aria-labelledby="wd-ex">
    <h2 id="wd-ex" class="wd-h2">Wyjątki (<?= count($exemptions) ?>)</h2>
    <p class="tw-text-sm tw-text-slate-700">Konta, których AccountWarden nie sprawdza, np. konta serwisowe i zarząd.</p>
    <form method="post" action="<?= h(route('warden.exemptAdd')) ?>" class="tw-grid tw-gap-3 md:tw-grid-cols-3 tw-items-end tw-mb-4">
      <?= csrf_field() ?>
      <div>
        <label for="wd-ex-user" class="wd-label">Osoba</label>
        <select id="wd-ex-user" name="user_id" required class="wd-input">
          <option value="">Wybierz…</option>
          <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"><?= h($u['name'] . ' (' . $u['email'] . ')') ?></option><?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="wd-ex-reason" class="wd-label">Powód</label>
        <input id="wd-ex-reason" name="reason" required maxlength="200" class="wd-input" placeholder="np. konto serwisowe">
      </div>
      <div><button type="submit" class="wd-btn wd-btn-primary">Dodaj wyjątek</button></div>
    </form>
    <?php if ($exemptions): ?>
    <div class="tw-overflow-x-auto">
      <table class="wd-table">
        <caption>Lista wyjątków</caption>
        <thead><tr><th scope="col">Osoba</th><th scope="col">Powód</th><th scope="col">Dodał(a)</th><th scope="col">Działanie</th></tr></thead>
        <tbody>
          <?php foreach ($exemptions as $e): ?>
            <tr><td><?= h($e['name']) ?><br><span class="tw-text-slate-700"><?= h($e['email']) ?></span></td>
                <td><?= h($e['reason']) ?></td><td><?= h($e['created_by']) ?>, <?= h($loc($e['created_at'], false)) ?></td>
                <td><form method="post" action="<?= h(route('warden.exemptRemove')) ?>"><?= csrf_field() ?>
                  <input type="hidden" name="user_id" value="<?= (int)$e['user_id'] ?>">
                  <button type="submit" class="wd-btn wd-btn-plain">Usuń<span class="tw-sr-only"> wyjątek dla <?= h($e['name']) ?></span></button>
                </form></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </section>

  <section class="wd-card" aria-labelledby="wd-set">
    <h2 id="wd-set" class="wd-h2">Ustawienia</h2>
    <form method="post" action="<?= h(route('warden.settings')) ?>" class="tw-grid tw-gap-3 md:tw-grid-cols-3">
      <?= csrf_field() ?>
      <div>
        <label for="wd-days" class="wd-label">Próg nieaktywności (dni)</label>
        <input id="wd-days" name="inactivity_days" type="number" min="14" max="365" required value="<?= $days ?>" class="wd-input" aria-describedby="wd-days-h">
        <p id="wd-days-h" class="wd-hint">Liczba dni bez logowania w SZO i w Logto, po której startuje 7-dniowe odliczanie.</p>
      </div>
      <div>
        <label for="wd-max" class="wd-label">Nowe odliczania na jeden przebieg</label>
        <input id="wd-max" name="max_new_per_run" type="number" min="1" max="1000" required value="<?= (int)$cfg['max_new_per_run'] ?>" class="wd-input" aria-describedby="wd-max-h">
        <p id="wd-max-h" class="wd-hint">Bezpiecznik: reszta poczeka do kolejnego dnia.</p>
      </div>
      <div>
        <label for="wd-url" class="wd-label">Adres logowania w wiadomościach</label>
        <input id="wd-url" name="login_url" type="url" required value="<?= h($cfg['login_url']) ?>" class="wd-input">
      </div>
      <div class="md:tw-col-span-3"><button type="submit" class="wd-btn wd-btn-primary">Zapisz ustawienia</button></div>
    </form>
  </section>

  <section class="wd-card" aria-labelledby="wd-hist">
    <h2 id="wd-hist" class="wd-h2">Historia (ostatnie 50 zdarzeń)</h2>
    <?php if (!$history): ?><p class="tw-text-slate-700 tw-m-0">Brak zdarzeń.</p><?php else: ?>
    <div class="tw-overflow-x-auto">
      <table class="wd-table">
        <caption>Dziennik AccountWarden</caption>
        <thead><tr><th scope="col">Kiedy</th><th scope="col">Zdarzenie</th><th scope="col">Osoba</th><th scope="col">Kto</th></tr></thead>
        <tbody>
          <?php foreach ($history as $a): ?>
            <tr><td><?= h($loc($a['created_at'])) ?></td><td><?= h($eventLabel[$a['event']] ?? $a['event']) ?></td>
                <td><?= h((string)($a['name'] ?? $a['email'] ?? '')) ?></td><td><?= h($a['actor']) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </section>
</main>
<?php include dirname(__DIR__, 3) . '/includes/footer.php'; ?>
