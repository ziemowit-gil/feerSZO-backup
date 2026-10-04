<?php
/**
 * karty30/ti/dydaktyk/przypomnieniaWplat.php — Przypomnienia o braku wpłaty za zajęcia TI (kierownik).
 *
 * Codziennie o 7:00 (agent CRON ti_payment_reminders) system sprawdza, kto ma wymagalną, niepokrytą należność, i wysyła
 * kursantowi — a dla małoletniego opiekunowi — e-mail i SMS. Ekran: włączenie i zasady (dni po terminie, minimalna kwota,
 * odstęp, limit przypomnień), podgląd treści obu wiadomości (edycja w Szablonach e-mail / SMS), podgląd „kto dostałby
 * przypomnienie dziś” z powodami pominięć, ręczne „Wyślij teraz” i dziennik ostatnich wysyłek. Mechanizm jest domyślnie
 * WYŁĄCZONY — najpierw przegląd treści, potem włączenie.
 *
 * Logika i reguły: modules/ti_payment_reminders/logic/tiPaymentReminders.php. Ekran: Bootstrap 5.3, WCAG 2.1 — etykiety pól
 * z opisami, odznaki z ikoną i tekstem (kolor nie jest jedynym nośnikiem informacji), tabele z podpisem i nagłówkami kolumn,
 * podgląd e-maila w ramce bez skryptów (sandbox). Adresaci w podglądzie i dzienniku są zamaskowani.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_payment_reminders/logic/tiPaymentReminders.php';

$me = dyd_require();
if (!dyd_is_staff()) { header('Location: index.php'); exit; }
$uid      = (int)$me['user_id'];
$dyd_name = (string)($me['name'] ?? '') ?: 'Kierownik';
ti_payment_reminders_migrate();

$SELF = 'przypomnieniaWplat.php';
$errors = []; $old = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op = (string)($_POST['_op'] ?? '');
    if ($op === 'save_settings') {
        $res = TiPaymentReminders::saveSettings($_POST, $uid);
        if ($res['ok']) {
            $on = TiPaymentReminders::settings()['enabled'];
            flash_set('success', 'Ustawienia zapisane. ' . ($on ? 'Przypomnienia są WŁĄCZONE — wysyłka codziennie o 7:00.' : 'Przypomnienia są wyłączone.'));
            header('Location: ' . $SELF); exit;
        }
        $errors = $res['errors']; $old = $_POST;
    } elseif ($op === 'run_now') {
        if (!TiPaymentReminders::settings()['enabled']) {
            flash_set('danger', 'Przypomnienia są wyłączone — najpierw je włącz w ustawieniach.');
        } else {
            $r = TiPaymentReminders::run(['user_id' => $uid]);
            $skipped = array_sum($r['skipped']);
            flash_set($r['failed'] > 0 ? 'warning' : 'success',
                'Przebieg wykonany: wysłano ' . $r['sent']['email'] . ' e-maili i ' . $r['sent']['sms'] . ' SMS, błędów: ' . $r['failed'] . ', pominięto: ' . $skipped
                . ($r['errors'] ? ' (' . implode('; ', array_slice($r['errors'], 0, 3)) . ')' : '') . '.');
        }
        header('Location: ' . $SELF); exit;
    }
}

$s      = TiPaymentReminders::settings();
$f      = $old !== null
    ? ['enabled' => !empty($old['enabled']), 'email' => !empty($old['email']), 'sms' => !empty($old['sms']), 'grace_days' => (string)($old['grace_days'] ?? ''),
       'min_amount' => (string)($old['min_amount'] ?? ''), 'every_days' => (string)($old['every_days'] ?? ''), 'max_reminders' => (string)($old['max_reminders'] ?? '')]
    : ['enabled' => $s['enabled'], 'email' => $s['email'], 'sms' => $s['sms'], 'grace_days' => (string)$s['grace_days'],
       'min_amount' => number_format($s['min_amount'], 2, ',', ''), 'every_days' => (string)$s['every_days'], 'max_reminders' => (string)$s['max_reminders']];
$plan   = TiPaymentReminders::plan();
$toSend = array_values(array_filter($plan, fn($e) => $e['channels']['email']['action'] === 'send' || $e['channels']['sms']['action'] === 'send'));
$rest   = array_values(array_filter($plan, fn($e) => !($e['channels']['email']['action'] === 'send' || $e['channels']['sms']['action'] === 'send')));
$cnt    = ['email' => 0, 'sms' => 0];
foreach ($plan as $e) foreach (['email', 'sms'] as $ch) if ($e['channels'][$ch]['action'] === 'send') $cnt[$ch]++;
$last   = TiPaymentReminders::lastRun();
$log    = TiPaymentReminders::recent(60);
$key    = TiPaymentReminders::TEMPLATE;
$etpl   = email_tpl_get($key); $stpl = sms_tpl_get($key);
$eprev  = email_tpl_render($key, email_tpl_sample_vars($key));
$sprev  = TiPaymentReminders::ascii(sms_tpl_render($key, sms_tpl_sample_vars($key))['message']);
$sper   = 160; $ssegs = mb_strlen($sprev) === 0 ? 0 : (mb_strlen($sprev) <= $sper ? 1 : (int)ceil(mb_strlen($sprev) / 153));
$zl     = fn(float $x): string => number_format($x, 2, ',', ' ') . ' zł';
$dtxt   = fn(int $n): string => TiPaymentReminders::daysText($n);
$ST_LOG = ['sent' => ['bi-check-circle-fill', 'pr-ok', 'Wysłano'], 'failed' => ['bi-x-octagon-fill', 'pr-err', 'Błąd'], 'pending' => ['bi-hourglass-split', 'pr-wait', 'W trakcie']];

$KP_TITLE  = 'Przypomnienia o wpłatach — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => $dyd_name, 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<style>
  /* Odznaki: jasne tło + ciemny tekst (≥ 7:1) + obramowanie w kolorze tekstu + ikona i etykieta tekstowa */
  .badge.pr-b { font-weight: 700; font-size: .78rem; padding: .3em .55em; border: 1px solid currentColor; white-space: nowrap; }
  .badge.pr-ok   { background: #d1e7dd; color: #0a3622; }
  .badge.pr-err  { background: #f8d7da; color: #58151c; }
  .badge.pr-wait { background: #fff3cd; color: #664d03; }
  .badge.pr-off  { background: #e2e3e5; color: #2b2f32; }
  .pr-status { display: flex; align-items: center; gap: .75rem; flex-wrap: wrap; padding: .75rem 1rem; border: 1px solid #adb5bd; border-left-width: 6px; border-radius: 4px; background: #fff; margin: 0 0 1rem; }
  .pr-status.is-on  { border-left-color: #0a3622; } .pr-status.is-off { border-left-color: #2b2f32; }
  .pr-preview-frame { width: 100%; min-height: 340px; border: 1px solid #adb5bd; border-radius: 4px; background: #fff; }
  .pr-sms { white-space: pre-wrap; word-break: break-word; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; background: #f8f9fa; border: 1px solid #adb5bd; border-radius: 4px; padding: .6rem .8rem; margin: 0; }
  .pr-table td, .pr-table th { vertical-align: top; }
  .pr-reason { color: #664d03; }
</style>

<?php $KIER_CUR = $SELF; $KIER_LABEL = 'Przypomnienia o wpłatach';
   include __DIR__ . '/_kierownik_bar.php'; ?>

<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1280px">

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h1 class="h4 mb-0 fw-bold"><i class="bi bi-cash-coin text-primary me-2" aria-hidden="true"></i>Przypomnienia o wpłatach</h1>
  <span class="text-body-secondary small">codziennie o 7:00 — e-mail i SMS do kursantów (a dla małoletnich: do opiekunów), którym brakuje wpłaty</span>
</div>

<?= flash_html() ?>

<?php if ($errors): ?>
<div class="alert alert-danger" role="alert" id="pr-errors" tabindex="-1">
  <strong>Nie zapisano — popraw dane:</strong>
  <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<script>document.getElementById('pr-errors').focus();</script>
<?php endif; ?>

<section class="pr-status <?= $s['enabled'] ? 'is-on' : 'is-off' ?>" aria-labelledby="pr-status-h">
  <h2 class="visually-hidden" id="pr-status-h">Stan przypomnień</h2>
  <?php if ($s['enabled']): ?>
    <span class="badge pr-b pr-ok fs-6"><i class="bi bi-check-circle-fill me-1" aria-hidden="true"></i>WŁĄCZONE</span>
    <span>Wysyłka codziennie o 7:00<?= $s['email'] ? ' · e-mail' : '' ?><?= $s['sms'] ? ' · SMS' : '' ?>.</span>
  <?php else: ?>
    <span class="badge pr-b pr-off fs-6"><i class="bi bi-slash-circle-fill me-1" aria-hidden="true"></i>WYŁĄCZONE</span>
    <span>Nic nie jest wysyłane. Sprawdź treść wiadomości i listę poniżej, potem włącz przypomnienia.</span>
  <?php endif; ?>
  <span class="text-body-secondary small ms-md-auto">
    <?php if ($last): $ld = $last['details']; ?>
      Ostatni przebieg: <?= h(substr($last['at'], 0, 16)) ?> — wysłano <?= (int)($ld['sent']['email'] ?? 0) ?> e-maili i <?= (int)($ld['sent']['sms'] ?? 0) ?> SMS, błędów: <?= (int)($ld['failed'] ?? 0) ?>.
    <?php else: ?>Przebieg jeszcze się nie odbył.<?php endif; ?>
  </span>
</section>

<div class="row g-3">
<div class="col-lg-5">
<section class="card shadow-sm h-100" aria-labelledby="pr-set-h">
  <div class="card-body">
    <h2 class="h6 fw-bold" id="pr-set-h"><i class="bi bi-sliders me-1" aria-hidden="true"></i>Ustawienia</h2>
    <form method="post" action="<?= $SELF ?>">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
      <input type="hidden" name="_op" value="save_settings">
      <div class="form-check form-switch mb-3">
        <input class="form-check-input" type="checkbox" role="switch" id="pr-enabled" name="enabled" value="1"<?= $f['enabled'] ? ' checked' : '' ?>>
        <label class="form-check-label fw-semibold" for="pr-enabled">Włącz codzienne przypomnienia</label>
      </div>
      <fieldset class="mb-3">
        <legend class="form-label small mb-1">Kanały</legend>
        <div class="form-check form-check-inline">
          <input class="form-check-input" type="checkbox" id="pr-email" name="email" value="1"<?= $f['email'] ? ' checked' : '' ?>>
          <label class="form-check-label" for="pr-email">E-mail</label>
        </div>
        <div class="form-check form-check-inline">
          <input class="form-check-input" type="checkbox" id="pr-sms" name="sms" value="1"<?= $f['sms'] ? ' checked' : '' ?>>
          <label class="form-check-label" for="pr-sms">SMS</label>
        </div>
      </fieldset>
      <div class="mb-3">
        <label class="form-label small mb-1" for="pr-grace">Wysyłaj od ilu dni po terminie płatności</label>
        <input class="form-control form-control-sm" style="max-width:8rem" type="number" min="0" max="60" step="1" id="pr-grace" name="grace_days" value="<?= h($f['grace_days']) ?>" aria-describedby="pr-grace-h" required>
        <div class="form-text" id="pr-grace-h">0 = od dnia po terminie (termin to ostatni dzień na wpłatę).</div>
      </div>
      <div class="mb-3">
        <label class="form-label small mb-1" for="pr-min">Minimalna zaległość (zł)</label>
        <input class="form-control form-control-sm" style="max-width:8rem" type="text" inputmode="decimal" id="pr-min" name="min_amount" value="<?= h($f['min_amount']) ?>" aria-describedby="pr-min-h" required>
        <div class="form-text" id="pr-min-h">Zaległości poniżej tej kwoty są pomijane (0 = bez progu).</div>
      </div>
      <div class="mb-3">
        <label class="form-label small mb-1" for="pr-every">Odstęp między przypomnieniami (dni)</label>
        <input class="form-control form-control-sm" style="max-width:8rem" type="number" min="1" max="30" step="1" id="pr-every" name="every_days" value="<?= h($f['every_days']) ?>" aria-describedby="pr-every-h" required>
        <div class="form-text" id="pr-every-h">1 = codziennie, aż do wpłaty. Przy 3 — co trzeci dzień.</div>
      </div>
      <div class="mb-3">
        <label class="form-label small mb-1" for="pr-max">Maksymalna liczba przypomnień na jedną należność</label>
        <input class="form-control form-control-sm" style="max-width:8rem" type="number" min="0" max="365" step="1" id="pr-max" name="max_reminders" value="<?= h($f['max_reminders']) ?>" aria-describedby="pr-max-h" required>
        <div class="form-text" id="pr-max-h">0 = bez limitu. Po osiągnięciu limitu należność nie jest już przypominana.</div>
      </div>
      <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Zapisz ustawienia</button>
    </form>
    <hr>
    <h3 class="h6 fw-bold">Zasady stałe</h3>
    <ul class="small mb-0 ps-3">
      <li>Brak wpłaty = należność po terminie płatności, niepokryta w księdze wpłat (alokacja FIFO, wpłaty znaczone na grupę liczone osobno).</li>
      <li>Adresat: kursant, a dla małoletniego — opiekun (gdy nie ma jego e-maila / telefonu — dane kursanta).</li>
      <li>Pomijani: płatnik PFRON / FEER, kursanci bez rozliczeń i wirtualni, grupy „tylko godziny”, należności z wnioskiem o odroczenie oraz kursanci z zgłoszoną, niezatwierdzoną wpłatą.</li>
      <li>Tego samego dnia jeden odbiorca dostaje najwyżej jedno przypomnienie na kanał — powtórny przebieg niczego nie dubluje.</li>
      <li>To komunikacja rozliczeniowa — nie podlega zgodom na powiadomienia dydaktyczne.</li>
      <li>Godzinę wysyłki (domyślnie 7:00) zmienia administrator w Harmonogramie CRON (agent „ti_payment_reminders”).</li>
    </ul>
  </div>
</section>
</div>

<div class="col-lg-7">
<section class="card shadow-sm h-100" aria-labelledby="pr-msg-h">
  <div class="card-body">
    <h2 class="h6 fw-bold" id="pr-msg-h"><i class="bi bi-chat-square-text me-1" aria-hidden="true"></i>Treść wiadomości</h2>
    <p class="small text-body-secondary">Podgląd z przykładowymi danymi. Treść, temat i włączenie każdego kanału zmienia się w edytorach szablonów.</p>

    <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
      <h3 class="h6 mb-0">E-mail</h3>
      <span class="badge pr-b <?= $etpl['enabled'] ? 'pr-ok' : 'pr-off' ?>"><i class="bi <?= $etpl['enabled'] ? 'bi-check-circle-fill' : 'bi-slash-circle-fill' ?> me-1" aria-hidden="true"></i><?= $etpl['enabled'] ? 'szablon aktywny' : 'szablon wyłączony' ?></span>
      <span class="small text-body-secondary"><?= $etpl['is_custom'] ? 'treść zmieniona' : 'treść domyślna' ?></span>
      <a class="btn btn-outline-primary btn-sm ms-auto" href="szablonyEmail.php?key=<?= h(urlencode($key)) ?>"><i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Edytuj treść e-maila</a>
    </div>
    <p class="small mb-1"><strong>Temat:</strong> <?= h($eprev['subject']) ?></p>
    <iframe class="pr-preview-frame mb-3" title="Podgląd e-maila z przypomnieniem o wpłacie" sandbox="" srcdoc="<?= h($eprev['html']) ?>"></iframe>

    <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
      <h3 class="h6 mb-0">SMS</h3>
      <span class="badge pr-b <?= $stpl['enabled'] ? 'pr-ok' : 'pr-off' ?>"><i class="bi <?= $stpl['enabled'] ? 'bi-check-circle-fill' : 'bi-slash-circle-fill' ?> me-1" aria-hidden="true"></i><?= $stpl['enabled'] ? 'szablon aktywny' : 'szablon wyłączony' ?></span>
      <span class="small text-body-secondary"><?= $stpl['is_custom'] ? 'treść zmieniona' : 'treść domyślna' ?></span>
      <a class="btn btn-outline-primary btn-sm ms-auto" href="szablonySms.php?key=<?= h(urlencode($key)) ?>"><i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Edytuj treść SMS</a>
    </div>
    <p class="pr-sms mb-1" aria-label="Podgląd SMS"><?= h($sprev) ?></p>
    <p class="small text-body-secondary mb-0"><?= (int)mb_strlen($sprev) ?> znaków (~<?= $ssegs ?> <?= $ssegs === 1 ? 'segment' : 'segm.' ?> SMS); przy wysyłce polskie znaki są zamieniane na ASCII, a długość zależy od nazwy organizacji, rachunku i tytułu.</p>
  </div>
</section>
</div>
</div>

<section class="mt-4" aria-labelledby="pr-plan-h">
  <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
    <h2 class="h6 fw-bold mb-0" id="pr-plan-h"><i class="bi bi-people me-1" aria-hidden="true"></i>Kto dostałby przypomnienie dziś</h2>
    <span class="small text-body-secondary">stan na <?= h(date('d.m.Y H:i')) ?> — kursantów z zaległością: <?= count($plan) ?>; do wysłania: e-mail <?= (int)$cnt['email'] ?>, SMS <?= (int)$cnt['sms'] ?><?= $s['enabled'] ? '' : ' (po włączeniu)' ?></span>
    <form method="post" action="<?= $SELF ?>" class="ms-auto">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
      <input type="hidden" name="_op" value="run_now">
      <button class="btn btn-warning btn-sm" type="submit"<?= $s['enabled'] && $toSend ? '' : ' disabled' ?>
              onclick="return confirm('Wysłać przypomnienia teraz? Każdy odbiorca dostanie najwyżej jedno przypomnienie na kanał dziennie — dzisiejsza wysyłka o 7:00 pominie tych, którzy już je dostali.');"><i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij teraz</button>
    </form>
  </div>
  <?php if (!$plan): ?>
    <div class="alert alert-light border small" role="status">Nikt nie ma dziś wymagalnej, niepokrytej należności — nie ma komu przypominać.</div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm align-middle pr-table">
      <caption class="small text-body-secondary">Kursanci z zaległością, najwyższe kwoty na górze. „Wyślemy” — przypomnienie wyjdzie przy najbliższym przebiegu; „Pominięty” — z powodem.</caption>
      <thead class="table-light">
        <tr><th scope="col">Kursant</th><th scope="col" class="text-end">Zaległość</th><th scope="col">Najstarszy termin</th><th scope="col">E-mail</th><th scope="col">SMS</th></tr>
      </thead>
      <tbody>
      <?php foreach (array_merge($toSend, $rest) as $i => $e): if ($i >= 200) break; ?>
        <tr>
          <th scope="row" class="fw-normal"><?= h($e['client_name']) ?><div class="small text-body-secondary">id <?= (int)$e['client_id'] ?></div></th>
          <td class="text-end text-nowrap"><strong><?= h($zl((float)$e['total'])) ?></strong><div class="small text-body-secondary"><?= count($e['items']) ?> <?= count($e['items']) === 1 ? 'należność' : 'należn.' ?></div></td>
          <td class="text-nowrap"><?= h(date('d.m.Y', strtotime($e['oldest_due']))) ?><div class="small text-body-secondary"><?= h($dtxt((int)$e['max_days'])) ?> po terminie</div></td>
          <?php foreach (['email', 'sms'] as $ch): $d = $e['channels'][$ch]; ?>
            <td>
              <?php if ($d['action'] === 'send'): ?>
                <span class="badge pr-b pr-ok"><i class="bi bi-send-check-fill me-1" aria-hidden="true"></i>Wyślemy</span>
                <div class="small text-body-secondary"><?= h($d['kind']) ?>: <?= h($d['masked']) ?></div>
              <?php else: ?>
                <span class="badge pr-b pr-off"><i class="bi bi-dash-circle-fill me-1" aria-hidden="true"></i>Pominięty</span>
                <div class="small pr-reason"><?= h($d['reason']) ?></div>
              <?php endif; ?>
            </td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (count($plan) > 200): ?><p class="small text-body-secondary">Pokazano 200 z <?= count($plan) ?> kursantów.</p><?php endif; ?>
  <?php endif; ?>
</section>

<section class="mt-4" aria-labelledby="pr-log-h">
  <h2 class="h6 fw-bold" id="pr-log-h"><i class="bi bi-clock-history me-1" aria-hidden="true"></i>Dziennik wysyłek (ostatnie <?= count($log) ?>)</h2>
  <?php if (!$log): ?>
    <div class="alert alert-light border small" role="status">Nic jeszcze nie wysłano.</div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm align-middle pr-table">
      <caption class="visually-hidden">Ostatnie wysyłki przypomnień o wpłatach</caption>
      <thead class="table-light">
        <tr><th scope="col">Dzień</th><th scope="col">Kursant</th><th scope="col">Kanał</th><th scope="col">Adresat</th><th scope="col" class="text-end">Kwota</th><th scope="col">Wynik</th></tr>
      </thead>
      <tbody>
      <?php foreach ($log as $r): $u = $ST_LOG[$r['status']] ?? $ST_LOG['pending']; ?>
        <tr>
          <td class="text-nowrap"><?= h(date('d.m.Y', strtotime((string)$r['run_date']))) ?></td>
          <th scope="row" class="fw-normal"><?= h((string)($r['client_name'] ?? ('#' . $r['client_id']))) ?></th>
          <td><?= h(TiPaymentReminders::CHANNELS[$r['channel']] ?? $r['channel']) ?></td>
          <td class="text-nowrap"><?= h($r['recipient_kind']) ?>: <?= h($r['recipient']) ?></td>
          <td class="text-end text-nowrap"><?= h($zl((float)$r['amount'])) ?></td>
          <td><span class="badge pr-b <?= h($u[1]) ?>"><i class="bi <?= h($u[0]) ?> me-1" aria-hidden="true"></i><?= h($u[2]) ?></span><?= $r['reason'] !== '' ? '<div class="small pr-reason">' . h($r['reason']) . '</div>' : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<p class="small text-body-secondary mt-4 mb-0">
  Z konsoli: <code>php cron/ti_payment_reminders.php --dry-run</code> (podgląd bez wysyłki), <code>--client=ID</code> (jeden kursant).
  Każdy przebieg i zmiana ustawień zapisują się w audycie (<code>ti_payment_reminders.run</code>, <code>ti_payment_reminders.settings</code>).
</p>

</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
