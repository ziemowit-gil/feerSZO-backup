<?php
/**
 * modules/dhosting_migrator/view/admin.php — migrator kont Microsoft 365 → dhosting (administrator).
 * Zmienne: $overview (accounts, errors, domains), $result (wyniki zakładania — hasła, tylko raz), $flash, $test, $cfg.
 */
$PAGE_TITLE = 'Migrator kont Microsoft 365 → dhosting';
include dirname(__DIR__, 3) . '/includes/header.php';

$accounts = $overview['accounts'];
$cntAll    = count($accounts);
$cntExists = count(array_filter($accounts, fn($a) => $a['exists']));
$cntTodo   = $cntAll - $cntExists;
$cntNoTel  = count(array_filter($accounts, fn($a) => !$a['mobile']));
$selfUrl   = route('dhm.self');
$apiReady  = $cfg['api_user'] !== '' && $cfg['has_pass'] && trim($cfg['domains']) !== '';
$statusOf  = function (array $a): array {
    if (($a['registry']['status'] ?? '') === 'error' && !$a['exists']) return ['error', 'Błąd', 'dhm-b--err'];
    if ($a['exists']) return ['exists', 'Na dhosting', 'dhm-b--ok'];
    return ['todo', 'Do założenia', 'dhm-b--todo'];
};
?>
<style>
  .dhm-stat { border: 1px solid #d0d5dd; border-radius: 8px; padding: .75rem 1rem; background: #fff; height: 100%; }
  .dhm-stat b { display: block; font-size: 1.5rem; line-height: 1.2; color: #1d2939; }
  .dhm-stat span { font-size: .8rem; color: #475467; }
  .dhm-b { display: inline-block; padding: .15rem .5rem; border-radius: 999px; font-size: .75rem; font-weight: 600; white-space: nowrap; }
  .dhm-b--ok   { background: #dcfae6; color: #085d3a; }
  .dhm-b--todo { background: #eff4ff; color: #1849a9; }
  .dhm-b--err  { background: #fee4e2; color: #912018; }
  .dhm-b--off  { background: #f2f4f7; color: #344054; }
  .dhm-arrow { color: #667085; }
  .dhm-pass { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .95rem; letter-spacing: .03em; }
  #dhmTable tbody tr.dhm-hidden { display: none; }
  #dhmTable td, #dhmTable th { vertical-align: middle; }
  .dhm-bar { position: sticky; bottom: 0; z-index: 5; background: #fff; border-top: 1px solid #d0d5dd; padding: .75rem 0; }
  .dhm-check { width: 1.15rem; height: 1.15rem; }
  :is(.dhm-wrap) :focus-visible { outline: 3px solid #1d2939; outline-offset: 2px; }
  @media print { body * { visibility: hidden; } #wynik, #wynik * { visibility: visible; } #wynik { position: absolute; top: 0; left: 0; width: 100%; } .no-print { display: none !important; } }
</style>

<?php ob_start(); ?>
<section id="ustawienia" class="card mt-4" aria-labelledby="dhmSetH">
  <div class="card-header"><h2 id="dhmSetH" class="h6 mb-0"><i class="bi bi-gear" aria-hidden="true"></i> Konfiguracja API dhosting</h2></div>
  <div class="card-body">
    <?php if ($test): ?>
      <div class="alert alert-<?= $test['ok'] ? 'success' : 'danger' ?> small" role="<?= $test['ok'] ? 'status' : 'alert' ?>">
        <strong>Test połączenia:</strong>
        <ul class="mb-1"><?php foreach ($test['lines'] as $l): ?><li><?= h($l) ?></li><?php endforeach; ?></ul>
        <?php if ($test['raw']): ?>
          <details><summary style="cursor:pointer">Surowa odpowiedź dhosting (HTTP <?= (int)$test['raw']['http'] ?>)</summary>
            <pre class="small bg-light p-2 mt-1 mb-0" style="max-height:260px;overflow:auto"><?= h(is_array($test['raw']['body']) ? json_encode($test['raw']['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string)$test['raw']['body']) ?></pre>
          </details>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <form method="post" action="<?= h(route('dhm.settings')) ?>" class="row g-3">
      <?= Router::csrfField() ?>
      <div class="col-md-6">
        <label for="dhmUser" class="form-label small">Login API</label>
        <input type="text" class="form-control form-control-sm" id="dhmUser" name="api_user" value="<?= h($cfg['api_user']) ?>" autocomplete="off">
        <div class="form-text">Wydaje dhosting.pl (to nie jest login do panelu klienta).</div>
      </div>
      <div class="col-md-6">
        <label for="dhmPass" class="form-label small">Hasło API</label>
        <input type="password" class="form-control form-control-sm" id="dhmPass" name="api_pass" value="" autocomplete="new-password" aria-describedby="dhmPassHelp">
        <div class="form-text" id="dhmPassHelp"><?= $cfg['has_pass'] ? 'Zapisane (zaszyfrowane). Zostaw puste, żeby nie zmieniać.' : 'Nie ustawiono.' ?></div>
      </div>
      <div class="col-md-6">
        <label for="dhmDomains" class="form-label small">Przenoszone domeny</label>
        <textarea class="form-control form-control-sm font-monospace" id="dhmDomains" name="domains" rows="3" aria-describedby="dhmDomHelp" placeholder="feer.org.pl"><?= h($cfg['domains']) ?></textarea>
        <div class="form-text" id="dhmDomHelp">Po jednej w linii. Ta sama domena: <code>feer.org.pl</code>. Inna na dhosting: <code>feer.org.pl = feer.pl</code>. Domena musi być zaparkowana na koncie dhosting z włączoną pocztą.</div>
      </div>
      <div class="col-md-6">
        <div class="mb-3">
          <label for="dhmQuota" class="form-label small">Pojemność nowej skrzynki (GB)</label>
          <input type="text" inputmode="decimal" class="form-control form-control-sm" id="dhmQuota" name="quota" value="<?= h($cfg['quota']) ?>" aria-describedby="dhmQuotaHelp" style="max-width:10rem">
          <div class="form-text" id="dhmQuotaHelp">Puste = domyślna usługi, <code>-1</code> = bez limitu.</div>
        </div>
        <div class="mb-3">
          <label for="dhmWeb" class="form-label small">Adres poczty przez przeglądarkę (do SMS)</label>
          <input type="url" class="form-control form-control-sm" id="dhmWeb" name="webmail_url" value="<?= h($cfg['webmail_url']) ?>" placeholder="https://…">
        </div>
        <div class="mb-3">
          <label for="dhmSwitch" class="form-label small">Data przełączenia poczty (zmiana MX)</label>
          <input type="date" class="form-control form-control-sm" id="dhmSwitch" name="switch_date" value="<?= h($cfg['switch_date']) ?>" aria-describedby="dhmSwitchHelp" style="max-width:12rem">
          <div class="form-text" id="dhmSwitchHelp">Pokazywana użytkownikom na /migracjapoczty. Puste = „wkrótce”.</div>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="self_enabled" value="1" id="dhmSelf" <?= $cfg['self_enabled'] ? 'checked' : '' ?>>
          <label class="form-check-label small" for="dhmSelf">Użytkownik może sam założyć skrzynkę (hasło SMS-em)</label>
        </div>
      </div>
      <fieldset class="col-12 border-top pt-3">
        <legend class="small fw-semibold mb-2" style="font-size:.875rem">Kopiowanie poczty z Microsoft 365 (IMAP dhosting)</legend>
        <div class="row g-3">
          <div class="col-md-6">
            <label for="dhmImap" class="form-label small">Serwer IMAP skrzynek dhosting</label>
            <input type="text" class="form-control form-control-sm" id="dhmImap" name="imap_host" value="<?= h($cfg['imap_host'] !== '' ? $cfg['imap_host'] : DhostingConfig::DEFAULT_IMAP) ?>" placeholder="<?= h(DhostingConfig::DEFAULT_IMAP) ?>" aria-describedby="dhmImapHelp" autocomplete="off">
            <div class="form-text" id="dhmImapHelp">Serwer poczty dhosting (SSL). Domyślnie <code><?= h(DhostingConfig::DEFAULT_IMAP) ?></code> — puste pole też oznacza ten serwer.</div>
          </div>
          <div class="col-md-2">
            <label for="dhmImapPort" class="form-label small">Port</label>
            <input type="text" inputmode="numeric" class="form-control form-control-sm" id="dhmImapPort" name="imap_port" value="<?= h($cfg['imap_port']) ?>" placeholder="993">
          </div>
          <div class="col-md-4 small text-body-secondary">
            Aplikacja SZO w Microsoft potrzebuje uprawnienia aplikacji <code>Mail.Read</code> (ze zgodą administratora) — sprawdza to „Testuj połączenie”.
          </div>
        </div>
      </fieldset>
      <details class="col-12">
        <summary class="small" style="cursor:pointer">Zaawansowane</summary>
        <label for="dhmUrl" class="form-label small mt-2">Adres API</label>
        <input type="url" class="form-control form-control-sm" id="dhmUrl" name="api_url" value="<?= h($cfg['api_url']) ?>" placeholder="<?= h(DhostingApi::DEFAULT_URL) ?>" style="max-width:24rem">
      </details>
      <div class="col-12 d-flex gap-2">
        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check2" aria-hidden="true"></i> Zapisz ustawienia</button>
      </div>
    </form>
    <form method="post" action="<?= h(route('dhm.test')) ?>" class="mt-2">
      <?= Router::csrfField() ?>
      <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-plug" aria-hidden="true"></i> Testuj połączenie (dhosting + Microsoft 365)</button>
      <span class="form-text ms-2">Tylko odczyt: lista skrzynek w domenach i lista kont Microsoft 365.</span>
    </form>
  </div>
</section>
<?php $settingsHtml = (string)ob_get_clean(); ?>

<div class="dhm-wrap">
<nav aria-label="Ścieżka nawigacji" class="mb-3">
  <ol class="breadcrumb" style="font-size:.8rem">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/admin/index.php">Admin</a></li>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/admin/m365_settings.php">Microsoft 365</a></li>
    <li class="breadcrumb-item active" aria-current="page">Migrator → dhosting</li>
  </ol>
</nav>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
  <h1 class="h4 mb-0"><i class="bi bi-envelope-arrow-up text-primary" aria-hidden="true"></i> Migrator kont Microsoft 365 → dhosting</h1>
  <a class="btn btn-sm btn-outline-secondary" href="#ustawienia"><i class="bi bi-gear" aria-hidden="true"></i> Konfiguracja API dhosting</a>
</div>
<p class="text-body-secondary small mb-3">
  Zakłada skrzynki pocztowe na dhosting.pl z <strong>tym samym loginem</strong> co konto Microsoft 365 i <strong>nowym hasłem</strong>
  (Microsoft nie udostępnia haseł, więc nie da się ich skopiować). Hasła zobaczysz tylko raz, zaraz po założeniu; możesz je też od razu
  wysłać SMS-em na numer z konta SZO. Użytkownicy mogą też założyć skrzynkę sami: <a href="<?= h($selfUrl) ?>">Moja skrzynka dhosting</a>
  (<?= $cfg['self_enabled'] ? 'włączone' : 'wyłączone w ustawieniach' ?>).
</p>

<?php if ($flash): ?>
  <div class="alert alert-<?= h($flash['type']) ?> small" role="<?= $flash['type'] === 'success' ? 'status' : 'alert' ?>"><?= h($flash['msg']) ?></div>
<?php endif; ?>

<?php if (!$apiReady): ?>
  <div class="alert alert-info small" role="status"><strong>Zacznij od konfiguracji API dhosting</strong> — login i hasło API od dhosting.pl oraz przenoszone domeny. Bez tego nie widać stanu skrzynek i nie da się ich zakładać.</div>
  <?= $settingsHtml ?>
<?php endif; ?>

<?php foreach ($overview['errors'] as $e): ?>
  <div class="alert alert-warning small mb-2" role="alert"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> <?= h($e) ?></div>
<?php endforeach; ?>

<?php if ($result): ?>
<?php $withPass = array_filter($result, fn($r) => $r['password'] !== null); ?>
<section id="wynik" class="card border-primary mb-4" aria-labelledby="dhmResH">
  <div class="card-header bg-primary-subtle d-flex flex-wrap justify-content-between align-items-center gap-2">
    <h2 id="dhmResH" class="h6 mb-0">Wynik: <?= count($withPass) ?> z <?= count($result) ?> kont ma nowe hasło</h2>
    <div class="no-print d-flex gap-2">
      <?php if ($withPass): ?>
      <button type="button" class="btn btn-sm btn-primary" id="dhmCopy"><i class="bi bi-clipboard" aria-hidden="true"></i> Kopiuj loginy i hasła</button>
      <button type="button" class="btn btn-sm btn-outline-primary" onclick="window.print()"><i class="bi bi-printer" aria-hidden="true"></i> Drukuj</button>
      <?php endif; ?>
    </div>
  </div>
  <div class="card-body p-0">
    <?php if ($withPass): ?>
    <div class="alert alert-warning rounded-0 border-0 border-bottom small mb-0" role="note">
      <strong>Ważne:</strong> hasła są widoczne tylko na tym ekranie — system ich nie zapisuje. Po odświeżeniu strony znikną.
      Przekaż je bezpiecznie (SMS, osobiście), nie zwykłym e-mailem.
    </div>
    <?php endif; ?>
    <div class="table-responsive">
      <table class="table table-sm mb-0 small" id="dhmResTable">
        <caption class="visually-hidden">Wynik zakładania skrzynek na dhosting</caption>
        <thead><tr><th scope="col">Osoba</th><th scope="col">Skrzynka (login)</th><th scope="col">Hasło</th><th scope="col">Wynik</th><th scope="col">SMS</th><th scope="col">Kopiowanie poczty</th><th scope="col">Powiadomienie</th></tr></thead>
        <tbody>
        <?php foreach ($result as $r): ?>
          <tr>
            <td><?= h($r['name']) ?></td>
            <td><code class="dhm-login"><?= h($r['mailbox']) ?></code></td>
            <td><?= $r['password'] !== null ? '<span class="dhm-pass">' . h($r['password']) . '</span>' : '—' ?></td>
            <td><span class="dhm-b <?= $r['status'] === 'ok' ? 'dhm-b--ok' : ($r['status'] === 'exists' ? 'dhm-b--off' : 'dhm-b--err') ?>"><?= $r['status'] === 'ok' ? 'OK' : ($r['status'] === 'exists' ? 'Pominięto' : 'Błąd') ?></span>
                <?= h($r['msg']) ?></td>
            <td><?= h($r['sms'] !== '' ? $r['sms'] : '—') ?></td>
            <td><?= h(($r['copy'] ?? '') !== '' ? $r['copy'] : '—') ?></td>
            <td><?= h(($r['notify'] ?? '') !== '' ? $r['notify'] : '—') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>
<?php endif; ?>

<div class="row g-2 mb-3" aria-label="Podsumowanie">
  <div class="col-6 col-md-3"><div class="dhm-stat"><b><?= $cntAll ?></b><span>kont Microsoft 365 w przenoszonych domenach</span></div></div>
  <div class="col-6 col-md-3"><div class="dhm-stat"><b><?= $cntExists ?></b><span>ma już skrzynkę na dhosting</span></div></div>
  <div class="col-6 col-md-3"><div class="dhm-stat"><b><?= $cntTodo ?></b><span>do założenia</span></div></div>
  <div class="col-6 col-md-3"><div class="dhm-stat"><b><?= $cntNoTel ?></b><span>bez numeru komórkowego w SZO (SMS nie pójdzie)</span></div></div>
</div>

<?php if ($accounts): ?>
<form method="post" action="<?= h(route('dhm.create')) ?>" id="dhmForm">
  <?= Router::csrfField() ?>
  <div class="d-flex flex-wrap gap-2 align-items-end mb-2">
    <div>
      <label for="dhmQ" class="form-label small mb-1">Szukaj</label>
      <input type="search" id="dhmQ" class="form-control form-control-sm" placeholder="imię, nazwisko, login" autocomplete="off" style="min-width:16rem">
    </div>
    <div>
      <label for="dhmF" class="form-label small mb-1">Pokaż</label>
      <select id="dhmF" class="form-select form-select-sm">
        <option value="">wszystkie</option>
        <option value="todo" selected>do założenia</option>
        <option value="exists">już na dhosting</option>
        <option value="error">z błędem</option>
      </select>
    </div>
    <p class="small text-body-secondary mb-1 ms-auto" id="dhmShown" aria-live="polite"></p>
  </div>

  <div class="table-responsive border rounded">
    <table class="table table-sm table-hover mb-0 small" id="dhmTable">
      <caption class="visually-hidden">Konta Microsoft 365 i ich skrzynki na dhosting</caption>
      <thead class="table-light">
        <tr>
          <th scope="col" style="width:2.5rem"><input type="checkbox" class="form-check-input dhm-check" id="dhmAll" aria-label="Zaznacz wszystkie widoczne"></th>
          <th scope="col">Osoba</th>
          <th scope="col">Login Microsoft <span class="dhm-arrow" aria-hidden="true">→</span> skrzynka dhosting</th>
          <th scope="col">Stan</th>
          <th scope="col">Telefon (SZO)</th>
          <th scope="col">Ostatnia operacja</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($accounts as $i => $a): [$st, $stLabel, $stCls] = $statusOf($a); $reg = $a['registry']; ?>
        <tr data-st="<?= h($st) ?>" data-admin="<?= !empty($a['admin']) ? '1' : '0' ?>" data-q="<?= h(mb_strtolower($a['name'] . ' ' . $a['login'] . ' ' . $a['mailbox'])) ?>">
          <td><input type="checkbox" class="form-check-input dhm-check dhm-row" name="ids[]" value="<?= h($a['m365_id']) ?>" id="dhmR<?= $i ?>"></td>
          <td><label for="dhmR<?= $i ?>" class="mb-0"><?= h($a['name']) ?></label>
              <?= !empty($a['admin']) ? '<span class="dhm-b dhm-b--todo ms-1">administrator SZO</span>' : '' ?>
              <?= !$a['enabled'] ? '<span class="dhm-b dhm-b--off ms-1">konto M365 wyłączone</span>' : '' ?>
              <?= $a['user_id'] ? '' : '<span class="dhm-b dhm-b--off ms-1">brak konta w SZO</span>' ?></td>
          <td><code><?= h($a['login']) ?></code><?php if ($a['mailbox'] !== $a['login']): ?> <span class="dhm-arrow" aria-label="na">→</span> <code><?= h($a['mailbox']) ?></code><?php endif; ?></td>
          <td><span class="dhm-b <?= $stCls ?>"><?= $stLabel ?></span>
              <?php if ($st === 'error'): ?><div class="text-danger-emphasis mt-1" style="font-size:.75rem"><?= h(mb_substr((string)$reg['last_error'], 0, 160)) ?></div><?php endif; ?></td>
          <td><?= $a['phone'] !== '' ? h(sms_format_phone($a['phone'])) . ($a['mobile'] ? '' : ' <span class="dhm-b dhm-b--off">nie komórka</span>') : '<span class="text-body-secondary">—</span>' ?></td>
          <td class="text-body-secondary"><?php if ($reg && $reg['last_action_at']): ?>
              <?= h(substr((string)$reg['last_action_at'], 0, 16)) ?><?= $reg['via'] === 'self' ? ' · sam użytkownik' : ($reg['via'] === 'admin' ? ' · administrator' : '') ?><?= $reg['sms_at'] ? ' · SMS' : '' ?>
            <?php else: ?>—<?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="dhm-bar d-flex flex-wrap align-items-center gap-3">
    <div class="form-check mb-0">
      <input class="form-check-input" type="checkbox" name="send_sms" value="1" id="dhmSms" checked>
      <label class="form-check-label small" for="dhmSms">Wyślij hasło SMS-em na numer z konta SZO</label>
    </div>
    <div class="form-check mb-0">
      <input class="form-check-input" type="checkbox" name="reset_existing" value="1" id="dhmReset">
      <label class="form-check-label small" for="dhmReset">Dla skrzynek, które już są na dhosting — ustaw nowe hasło</label>
    </div>
    <div class="form-check mb-0">
      <input class="form-check-input" type="checkbox" name="copy_mail" value="1" id="dhmCopyMail" <?= $copyOk ? 'checked' : 'disabled' ?> aria-describedby="dhmCopyHelp">
      <label class="form-check-label small" for="dhmCopyMail">Skopiuj też pocztę z Microsoft 365 (w tle)</label>
      <span id="dhmCopyHelp" class="visually-hidden"><?= $copyOk ? 'Kopiowanie działa w tle co 5 minut; gdy serwer jest niedostępny, ponawia co 6 godzin.' : 'Niedostępne: integracja Microsoft 365 nie jest skonfigurowana (Admin › Microsoft 365).' ?></span>
    </div>
    <div class="form-check mb-0">
      <input class="form-check-input" type="checkbox" name="notify" value="1" id="dhmNotify" checked aria-describedby="dhmNotifyHelp">
      <label class="form-check-label small" for="dhmNotify">Zleć migrację: powiadom osoby, że administrator zlecił migrację</label>
      <span id="dhmNotifyHelp" class="visually-hidden">Każda osoba dostanie powiadomienie w SZO (gdy ma konto) i e-mail na adres służbowy: co się stało, skąd hasło, czy poczta się kopiuje, link do strony migracji. Bez hasła.</span>
    </div>
    <button type="submit" class="btn btn-primary btn-sm ms-auto" id="dhmGo" disabled>
      <i class="bi bi-envelope-plus" aria-hidden="true"></i> <span id="dhmGoTxt">Zleć migrację</span>
    </button>
  </div>
  <?php include dirname(__DIR__, 2) . '/sso_logto/view/migratorBar.php'; // chmura + pliki z OneDrive dla zaznaczonych kont ?>
  <p class="small text-body-secondary mb-0">Naraz najwyżej <?= DhostingMigrator::BATCH_MAX ?> kont. Każda operacja trafia do dziennika (audit).</p>
</form>
<?php elseif (!$overview['errors']): ?>
  <div class="alert alert-info small">Brak kont Microsoft 365 w przenoszonych domenach.</div>
<?php endif; ?>

<?php $_seenBox = []; $storedPass = $storedPass ?? []; $retried = $retried ?? null; ?>
<section id="kopiowanie" class="card mt-4" aria-labelledby="dhmCopyH">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <h2 id="dhmCopyH" class="h6 mb-0"><i class="bi bi-arrow-left-right" aria-hidden="true"></i> Kopiowanie poczty z Microsoft 365</h2>
    <span class="small text-body-secondary">w tle co 5 min · niedostępny serwer = ponowienie co <?= DhostingCopy::RETRY_HOURS ?> h (najdłużej 7 dni)</span>
  </div>
  <?php if ($retried): ?>
    <div class="alert alert-warning rounded-0 border-0 border-bottom small mb-0" role="status">
      <strong>Ponowiono z nowym hasłem:</strong> <code><?= h($retried['mailbox']) ?></code> · hasło: <code class="user-select-all fs-6"><?= h($retried['password']) ?></code><?= $retried['sms'] !== '' ? ' · SMS: ' . h($retried['sms']) : '' ?>
      <div>Hasło widać tylko teraz — system go nie zapisuje (poza zaszyfrowaną kopią na czas kopiowania). Przekaż je bezpiecznie, nie zwykłym e-mailem.</div>
    </div>
  <?php endif; ?>
  <?php if (!$copyOk): ?>
    <div class="card-body small"><div class="alert alert-secondary mb-0">Kopiowanie niedostępne — integracja Microsoft 365 nie jest skonfigurowana (Admin › Microsoft 365). Sprawdź też „Testuj połączenie”.</div></div>
  <?php endif; ?>
  <?php if ($jobs): ?>
  <div class="table-responsive">
    <table class="table table-sm mb-0 small align-middle">
      <caption class="visually-hidden">Zadania kopiowania poczty</caption>
      <thead class="table-light"><tr><th scope="col">Skrzynka</th><th scope="col">Stan</th><th scope="col" style="min-width:12rem">Postęp</th><th scope="col">Uwagi</th><th scope="col"><span class="visually-hidden">Akcje</span></th></tr></thead>
      <tbody>
      <?php foreach ($jobs as $j): $pct = DhostingCopy::percent($j); $active = in_array($j['status'], DhostingCopy::ACTIVE, true);
            $cls = ['done' => 'dhm-b--ok', 'error' => 'dhm-b--err', 'cancelled' => 'dhm-b--off', 'waiting' => 'dhm-b--err'][$j['status']] ?? 'dhm-b--todo';
            // wykaz nieudanych — raz na skrzynkę (przy najnowszym zadaniu), stan bieżący rejestru
            $_fl = !isset($_failShown[$j['mailbox']]) ? DhostingCopy::failedFor((string)$j['mailbox']) : []; $_failShown[$j['mailbox']] = true; ?>
        <tr>
          <td><code><?= h($j['mailbox']) ?></code><div class="text-body-secondary" style="font-size:.75rem"><?= h($j['via'] === 'self' ? 'sam użytkownik' : 'administrator') ?> · <?= h(substr((string)$j['created_at'], 0, 16)) ?></div></td>
          <td><span class="dhm-b <?= $cls ?>"><?= h(DhostingCopy::LABELS[$j['status']] ?? $j['status']) ?></span></td>
          <td>
            <div class="progress" role="progressbar" aria-label="Postęp kopiowania <?= h($j['mailbox']) ?>" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100" style="height:.6rem">
              <div class="progress-bar<?= $j['status'] === 'done' ? ' bg-success' : '' ?>" style="width:<?= $pct ?>%"></div>
            </div>
            <div style="font-size:.75rem"><?= (int)$j['copied_items'] ?> / <?= (int)$j['total_items'] ?> wiad. (<?= $pct ?>%)<?= (int)$j['failed_items'] ? ' · nieudane: ' . (int)$j['failed_items'] : '' ?> · <?= h(number_format((int)$j['bytes'] / 1048576, 1, ',', ' ')) ?> MB</div>
          </td>
          <td style="max-width:22rem">
            <?php if ($j['status'] === 'waiting' && $j['next_run_at']): ?><div>Ponowienie: <?= h(substr((string)$j['next_run_at'], 0, 16)) ?> (próba <?= (int)$j['attempts'] ?>)</div><?php endif; ?>
            <?php if ($j['status'] === 'done' && $j['finished_at']): ?><div>Zakończono <?= h(substr((string)$j['finished_at'], 0, 16)) ?></div><?php endif; ?>
            <?php if (!empty($j['need_password'])): ?><div class="fw-semibold text-danger-emphasis"><i class="bi bi-key" aria-hidden="true"></i> Hasło do nowej skrzynki nie pasuje — zaznacz konto na liście i ustaw nowe hasło z kopiowaniem albo poproś osobę o podanie hasła na /migracjapoczty.</div><?php endif; ?>
            <?php if ($j['last_error']): ?><div class="text-danger-emphasis"><?= h(mb_substr((string)$j['last_error'], 0, 400)) ?></div><?php endif; ?>
            <?php if ($_fl): ?>
            <details class="mt-1"><summary>Wykaz nieudanych i niepełnych (<?= count($_fl) ?>)<span class="visually-hidden"> — <?= h($j['mailbox']) ?></span></summary>
              <div class="table-responsive mt-1" style="max-height:18rem">
                <table class="table table-sm table-bordered mb-1" style="font-size:.75rem">
                  <caption class="visually-hidden">Wiadomości skrzynki <?= h($j['mailbox']) ?>, których nie udało się przenieść</caption>
                  <thead><tr><th scope="col">Stan</th><th scope="col">Data</th><th scope="col">Folder</th><th scope="col">Temat</th><th scope="col">Powód</th></tr></thead>
                  <tbody>
                  <?php foreach ($_fl as $fr): ?>
                    <tr><td class="text-nowrap"><?= (int)$fr['result'] === 3 ? '<span class="dhm-b dhm-b--todo">niepełna</span>' : '<span class="dhm-b dhm-b--err">nieprzeniesiona</span>' ?></td><td class="text-nowrap"><?= h($fr['received_at'] ? substr((string)$fr['received_at'], 0, 16) : '—') ?></td><td><?= h((string)($fr['folder'] ?: '—')) ?></td>
                        <td><?= h((string)($fr['subject'] ?? '') !== '' ? (string)$fr['subject'] : ($fr['failed_at'] ? '(bez tematu)' : '(brak szczegółów — sprzed 13.11)')) ?></td><td><?= h(mb_substr((string)($fr['reason'] ?? ''), 0, 200)) ?></td></tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <div class="text-body-secondary">Oryginały zostały w Microsoft 365. „Ponów przenoszenie poczty” spróbuje jeszcze raz nieprzeniesione (udane znikną z wykazu); niepełne są już w nowej skrzynce.</div>
            </details>
            <?php endif; ?>
          </td>
          <td class="text-end text-nowrap">
            <?php $_first = !isset($_seenBox[$j['mailbox']]); $_seenBox[$j['mailbox']] = true; ?>
            <?php if (!$active && $_first): /* „Ponów przenoszenie poczty” tylko przy najnowszym zadaniu skrzynki */ ?>
            <form method="post" action="<?= h(route('dhm.copy')) ?>" class="d-inline">
              <?= Router::csrfField() ?><input type="hidden" name="job" value="<?= (int)$j['id'] ?>">
              <?php if (isset($storedPass[$j['mailbox']])): ?>
                <button class="btn btn-sm btn-outline-primary" name="op" value="retry" title="Nowe przejście zapisanym hasłem — tylko brakujące wiadomości"
                        onclick="return confirm('Ponowić przenoszenie poczty do <?= h($j['mailbox']) ?>? Skopiowane zostaną tylko brakujące wiadomości.')"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Ponów przenoszenie poczty</button>
              <?php else: ?>
                <button class="btn btn-sm btn-outline-primary" name="op" value="retry_newpass" title="Brak zapisanego hasła — ustawi nowe hasło (SMS do osoby) i ponowi"
                        onclick="return confirm('Brak zapisanego hasła do <?= h($j['mailbox']) ?>.\nUstawić NOWE hasło (dotychczasowe przestanie działać), wysłać je osobie SMS-em i ponowić przenoszenie poczty?')"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Ponów z nowym hasłem</button>
              <?php endif; ?>
            </form>
            <?php endif; ?>
            <?php if ($active): ?>
            <form method="post" action="<?= h(route('dhm.copy')) ?>" class="d-inline">
              <?= Router::csrfField() ?><input type="hidden" name="job" value="<?= (int)$j['id'] ?>">
              <?php if ($j['status'] !== 'running'): ?><button class="btn btn-sm btn-outline-primary" name="op" value="run" title="Uruchom w najbliższym przebiegu CRON">Ponów teraz</button><?php endif; ?>
              <button class="btn btn-sm btn-outline-danger" name="op" value="cancel" onclick="return confirm('Anulować kopiowanie do <?= h($j['mailbox']) ?>? Zapisane hasło zostanie usunięte.')">Anuluj</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php elseif ($copyOk): ?>
    <div class="card-body small text-body-secondary">Brak zadań. Zaznacz „Skopiuj też pocztę” przy zakładaniu skrzynek albo poproś użytkowników o skorzystanie z /migracjapoczty.</div>
  <?php endif; ?>
</section>

<?php include dirname(__DIR__, 2) . '/sso_logto/view/migratorFiles.php'; // sekcja #pliki: OneDrive → chmura ?>

<?php $suspended = $suspended ?? []; $restored = $restored ?? null;
      $_ctLabel = ['wolontariat' => 'Wolontariat', 'zlecenie' => 'Zlecenie', 'dzielo' => 'Dzieło']; ?>
<section id="zawieszone" class="card mt-4" aria-labelledby="dhmSuspH">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <h2 id="dhmSuspH" class="h6 mb-0"><i class="bi bi-pause-circle" aria-hidden="true"></i> Zawieszone skrzynki <span class="badge text-bg-secondary"><?= count($suspended) ?></span></h2>
    <span class="small text-body-secondary">automatycznie <?= DhostingContractMail::GRACE_DAYS ?> dni po wygaśnięciu umowy albo przez administratora · wyłączone, z losowym hasłem, bez powiadamiania</span>
  </div>
  <?php if ($restored): ?>
    <div class="alert alert-warning rounded-0 border-0 border-bottom small mb-0" role="status">
      <strong>Przywrócono:</strong> <code><?= h($restored['mailbox']) ?></code><?= $restored['person'] !== '' ? ' (' . h($restored['person']) . ')' : '' ?> ·
      nowe hasło: <code class="user-select-all fs-6"><?= h($restored['password']) ?></code><?= $restored['sms'] !== '' ? ' · SMS: ' . h($restored['sms']) : '' ?>
      <div>Hasło widać tylko teraz — system go nie zapisuje. Przekaż je bezpiecznie (SMS, osobiście), nie zwykłym e-mailem.</div>
    </div>
  <?php endif; ?>
  <?php if (!$suspended): ?>
    <div class="card-body small text-body-secondary">Brak zawieszonych skrzynek.</div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm mb-0 small align-middle">
      <caption class="visually-hidden">Zawieszone skrzynki pocztowe</caption>
      <thead class="table-light"><tr><th scope="col">Skrzynka</th><th scope="col">Osoba</th><th scope="col">Umowy</th><th scope="col">Zawieszona</th><th scope="col">Powód</th><th scope="col"><span class="visually-hidden">Akcje</span></th></tr></thead>
      <tbody>
      <?php foreach ($suspended as $i => $s): ?>
        <tr>
          <td><code><?= h($s['mailbox']) ?></code></td>
          <td><?= $s['person'] !== '' ? h($s['person']) : '—' ?></td>
          <td>
            <?php if (!$s['contracts']): ?>—<?php endif; ?>
            <?php foreach ($s['contracts'] as $c): ?>
              <div><a href="<?= h(APP_URL . '/contracts/' . $c['type'] . '/view.php?id=' . $c['id'] . '&tab=m365#skrzynka') ?>"><?= h(($_ctLabel[$c['type']] ?? $c['type']) . ' ' . ($c['numer'] !== '' ? $c['numer'] : '#' . $c['id'])) ?></a><?= $c['end'] !== '' ? ' <span class="text-body-secondary">do ' . h($c['end']) . '</span>' : '' ?></div>
            <?php endforeach; ?>
          </td>
          <td class="text-nowrap"><?= h(substr($s['suspended_at'], 0, 16)) ?><?= $s['by'] !== '' ? '<div class="text-body-secondary" style="font-size:.75rem">' . h($s['by'] === 'CRON' ? 'automatycznie' : $s['by']) . '</div>' : '' ?></td>
          <td style="max-width:18rem"><?= $s['reason'] !== '' ? h($s['reason']) : '—' ?></td>
          <td class="text-end">
            <form method="post" action="<?= h(route('dhm.suspended')) ?>" class="d-inline-flex flex-wrap gap-2 align-items-center justify-content-end"
                  onsubmit="return confirm('Przywrócić skrzynkę <?= h($s['mailbox']) ?>? Zostanie włączona z nowym hasłem.')">
              <?= Router::csrfField() ?><input type="hidden" name="mailbox" value="<?= h($s['mailbox']) ?>">
              <?php if ($s['phone'] !== ''): ?>
                <span class="form-check mb-0"><input class="form-check-input" type="checkbox" name="sms" value="1" id="dhmSuspSms<?= $i ?>">
                  <label class="form-check-label" for="dhmSuspSms<?= $i ?>">SMS na <?= h(DhostingMigrator::maskPhone($s['phone'])) ?></label></span>
              <?php endif; ?>
              <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-play-circle" aria-hidden="true"></i> Przywróć</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<?php if ($apiReady) echo $settingsHtml; ?>
</div>

<script>
(function () {
  const rows = Array.from(document.querySelectorAll('#dhmTable tbody tr'));
  const q = document.getElementById('dhmQ'), f = document.getElementById('dhmF');
  const all = document.getElementById('dhmAll'), go = document.getElementById('dhmGo'), goTxt = document.getElementById('dhmGoTxt');
  const shown = document.getElementById('dhmShown'), max = <?= (int)DhostingMigrator::BATCH_MAX ?>;
  function visible() { return rows.filter(r => !r.classList.contains('dhm-hidden')); }
  function count() {
    const n = rows.filter(r => r.querySelector('.dhm-row').checked).length;
    const ord = document.getElementById('dhmNotify') && document.getElementById('dhmNotify').checked;
    if (go) { go.disabled = n === 0 || n > max; goTxt.textContent = n > max ? 'Za dużo zaznaczonych (maks. ' + max + ')' : (ord ? 'Zleć migrację (' : 'Załóż skrzynki (') + n + ')'; }
  }
  function filter() {
    const t = (q ? q.value : '').trim().toLowerCase(), s = f ? f.value : '';
    // konta administratorów SZO zostają widoczne przy każdym filtrze stanu (np. ze skrzynką przy „do założenia”) — do zaznaczenia ręcznie
    rows.forEach(r => {
      const pinOnly = !!s && r.dataset.st !== s && r.dataset.admin === '1';
      r.classList.toggle('dhm-hidden', (s && r.dataset.st !== s && !pinOnly) || (t && !r.dataset.q.includes(t)));
      r.dataset.pinOnly = pinOnly ? '1' : '';
    });
    if (shown) shown.textContent = 'Widoczne: ' + visible().length + ' z ' + rows.length;
    if (all) all.checked = false;
  }
  if (q) q.addEventListener('input', filter);
  if (f) f.addEventListener('change', filter);
  // „zaznacz wszystkie widoczne” pomija konta administratorów pokazane tylko dlatego, że są przypięte (inny stan niż filtr)
  if (all) all.addEventListener('change', () => { visible().filter(r => r.dataset.pinOnly !== '1').forEach(r => r.querySelector('.dhm-row').checked = all.checked); count(); });
  rows.forEach(r => r.querySelector('.dhm-row').addEventListener('change', count));
  const nt = document.getElementById('dhmNotify'); if (nt) nt.addEventListener('change', count);
  const form = document.getElementById('dhmForm');
  if (form) form.addEventListener('submit', e => {
    const n = rows.filter(r => r.querySelector('.dhm-row').checked).length;
    if (e.submitter && e.submitter.id === 'ssoCloudGo') {   // chmura + pliki (modules/sso_logto/view/migratorBar.php)
      const files = document.getElementById('ssoFilesCopy') && document.getElementById('ssoFilesCopy').checked;
      if (!confirm('Założyć konta logowania jednokrotnego i w chmurze dla ' + n + ' kont? Każde dostanie NOWE hasło (to samo w Logto i w chmurze).' + (files ? '\nPliki z OneDrive skopiują się w tle.' : ''))) { e.preventDefault(); return; }
      e.submitter.disabled = true;
      return;
    }
    const reset = document.getElementById('dhmReset').checked ? '\nDla istniejących skrzynek zostanie ustawione NOWE hasło.' : '';
    const ordr = document.getElementById('dhmNotify') && document.getElementById('dhmNotify').checked;
    if (!confirm((ordr ? 'Zlecić migrację ' + n + ' kont? Osoby dostaną powiadomienie w SZO i e-mail.' : 'Założyć skrzynki na dhosting dla ' + n + ' kont?') + reset)) { e.preventDefault(); return; }
    go.disabled = true; goTxt.textContent = ordr ? 'Trwa zlecanie…' : 'Trwa zakładanie…';
  });
  filter(); count();

  const copy = document.getElementById('dhmCopy');
  if (copy) copy.addEventListener('click', () => {
    const lines = ['Osoba\tLogin\tHasło'];
    document.querySelectorAll('#dhmResTable tbody tr').forEach(tr => {
      const p = tr.querySelector('.dhm-pass');
      if (p) lines.push(tr.cells[0].textContent.trim() + '\t' + tr.querySelector('.dhm-login').textContent.trim() + '\t' + p.textContent.trim());
    });
    navigator.clipboard.writeText(lines.join('\n')).then(() => {
      const old = copy.innerHTML; copy.innerHTML = '<i class="bi bi-check2" aria-hidden="true"></i> Skopiowano';
      setTimeout(() => { copy.innerHTML = old; }, 1500);
    });
  });
})();
</script>

<?php include dirname(__DIR__, 3) . '/includes/footer.php'; ?>
