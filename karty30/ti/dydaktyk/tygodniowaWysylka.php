<?php
/**
 * karty30/ti/dydaktyk/tygodniowaWysylka.php — „Zajęcia w tym tygodniu”: poniedziałkowa wysyłka e-mail + SMS do uczestników (kierownik).
 *
 * Co poniedziałek 7:00–8:00 (agent CRON ti_week_digest) każdy uczestnik dostaje jedną wiadomość z zajęciami od poniedziałku do niedzieli.
 * Ekran: włączenie i kanały (domyślnie WYŁĄCZONE), podgląd „kto dostałby wiadomość” dla bieżącego tygodnia, ręczne „Wyślij teraz”
 * (idempotentne — nikt nie dostanie drugi raz w tym samym tygodniu) i dziennik ostatnich wysyłek. Treść SMS: Szablony SMS → „Zajęcia w tym tygodniu”.
 * Logika: modules/ti_week_digest/logic/tiWeekDigest.php.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_week_digest/logic/tiWeekDigest.php';

$me = dyd_require();
if (!dyd_is_staff()) { header('Location: index.php'); exit; }
$who = (string)($me['name'] ?? '') ?: 'Kierownik';
TiWeekDigest::migrate();
$SELF = 'tygodniowaWysylka.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op = (string)($_POST['_op'] ?? '');
    if ($op === 'save') {
        TiWeekDigest::saveSettings($_POST);
        $s = TiWeekDigest::settings();
        flash_set('success', 'Ustawienia zapisane. ' . ($s['enabled'] ? 'Wysyłka jest WŁĄCZONA — co poniedziałek 7:00–8:00 (' . implode(' + ', array_filter([$s['email'] ? 'e-mail' : '', $s['sms'] ? 'SMS' : ''])) . ').' : 'Wysyłka jest wyłączona.'));
    } elseif ($op === 'run_now') {
        if (!TiWeekDigest::settings()['enabled']) flash_set('danger', 'Wysyłka jest wyłączona — najpierw ją włącz.');
        else {
            $r = TiWeekDigest::run(['by' => $who]);
            flash_set($r['failed'] ? 'warning' : 'success', 'Wysłano: ' . $r['sent']['email'] . ' e-maili i ' . $r['sent']['sms'] . ' SMS dla tygodnia od ' . date('d.m.Y', strtotime($r['week']))
                . '. Pominięto: ' . json_encode($r['skipped'], JSON_UNESCAPED_UNICODE) . ($r['errors'] ? ' · błędy: ' . implode('; ', array_slice($r['errors'], 0, 3)) : '') . '.');
        }
    }
    header('Location: ' . $SELF); exit;
}

$set = TiWeekDigest::settings();
$prev = $set['enabled'] ? TiWeekDigest::run(['dry_run' => true]) : ['preview' => [], 'skipped' => []];   // podgląd bez wysyłki i bez zapisu
$recent = TiWeekDigest::recent(30);
$from = TiWeekDigest::weekStart(date('Y-m-d')); $to = date('Y-m-d', strtotime($from . ' +6 days'));

$KP_TITLE  = 'Wysyłka tygodniowa — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => $who, 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
$tok = '<input type="hidden" name="_token" value="' . h(dyd_token()) . '">';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<?php $KIER_CUR = $SELF; $KIER_LABEL = 'Wysyłka tygodniowa'; include __DIR__ . '/_kierownik_bar.php'; ?>
<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1000px">
<?= flash_html() ?>
<h1 class="h4 fw-bold mb-1"><i class="bi bi-calendar-week me-2" style="color:#0f6fa8" aria-hidden="true"></i>Zajęcia w tym tygodniu — wysyłka w poniedziałki</h1>
<p class="text-body-secondary small">Co poniedziałek rano (7:00–8:00) uczestnik dostaje jedną wiadomość z <strong>wszystkimi swoimi zajęciami od poniedziałku do niedzieli</strong>:
  e-mail (uczestnik i opiekun małoletniego) oraz SMS (osoby z włączonym „SMS o lekcjach”; małoletni — także opiekun). Nikt nie dostaje wiadomości dwa razy w tym samym tygodniu.</p>

<section class="card border-0 shadow-sm mb-3" aria-labelledby="wd-set">
  <div class="card-header fw-semibold" id="wd-set"><i class="bi bi-sliders me-2" aria-hidden="true"></i>Ustawienia</div>
  <form method="post" class="card-body"><?= $tok ?><input type="hidden" name="_op" value="save">
    <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" id="wd-en" name="enabled" value="1" <?= $set['enabled'] ? 'checked' : '' ?>><label class="form-check-label fw-semibold" for="wd-en">Wysyłka włączona</label>
      <div class="form-text">Domyślnie wyłączona — najpierw sprawdź treść (Szablony SMS → „Zajęcia w tym tygodniu”), potem włącz.</div></div>
    <div class="form-check mb-1"><input class="form-check-input" type="checkbox" id="wd-em" name="email" value="1" <?= $set['email'] ? 'checked' : '' ?>><label class="form-check-label" for="wd-em">E-mail</label></div>
    <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="wd-sms" name="sms" value="1" <?= $set['sms'] ? 'checked' : '' ?>><label class="form-check-label" for="wd-sms">SMS <span class="text-body-secondary small">(treść: <a href="szablonySms.php">Szablony SMS</a>)</span></label></div>
    <button class="btn btn-primary">Zapisz ustawienia</button>
  </form>
</section>

<section class="card border-0 shadow-sm mb-3" aria-labelledby="wd-prev">
  <div class="card-header fw-semibold d-flex flex-wrap align-items-center gap-2" id="wd-prev"><i class="bi bi-eye me-2" aria-hidden="true"></i>Bieżący tydzień (<?= h(date('d.m', strtotime($from)) . '–' . date('d.m.Y', strtotime($to))) ?>)
    <form method="post" class="ms-auto" onsubmit="return confirm('Wysłać teraz wiadomości z zajęciami tego tygodnia? Osoby, które już je dostały, zostaną pominięte.')"><?= $tok ?><input type="hidden" name="_op" value="run_now">
      <button class="btn btn-sm btn-outline-primary" <?= $set['enabled'] ? '' : 'disabled aria-disabled="true" title="Najpierw włącz wysyłkę"' ?>><i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij teraz</button></form></div>
  <div class="card-body">
  <?php if (!$set['enabled']): ?>
    <p class="mb-0 text-body-secondary"><i class="bi bi-pause-circle me-1" aria-hidden="true"></i>Wysyłka jest wyłączona — podgląd odbiorców pokaże się po włączeniu.</p>
  <?php elseif (!$prev['preview']): ?>
    <p class="mb-0 text-body-secondary"><i class="bi bi-check2-circle text-success me-1" aria-hidden="true"></i>Nikt nie czeka na wiadomość w tym tygodniu (brak zajęć, brak kontaktu albo już wysłano). Pominięto: <?= h(json_encode($prev['skipped'], JSON_UNESCAPED_UNICODE)) ?>.</p>
  <?php else: ?>
    <p class="small text-body-secondary">Dostaną wiadomość (kolejny automat: najbliższy poniedziałek 7:00–8:00, albo od razu przyciskiem „Wyślij teraz”):</p>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
      <caption class="visually-hidden">Uczestnicy, którzy dostaną wiadomość z zajęciami tygodnia</caption>
      <thead><tr><th scope="col">Uczestnik</th><th scope="col" class="text-end">Zajęć</th><th scope="col" class="text-end">E-mail</th><th scope="col" class="text-end">SMS</th></tr></thead>
      <tbody><?php foreach ($prev['preview'] as $p): ?><tr><td><?= h($p['name']) ?></td><td class="text-end"><?= (int)$p['lessons'] ?></td><td class="text-end"><?= (int)$p['email'] ?: '—' ?></td><td class="text-end"><?= (int)$p['sms'] ?: '—' ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
  <?php endif; ?>
  </div>
</section>

<section class="card border-0 shadow-sm" aria-labelledby="wd-log">
  <div class="card-header fw-semibold" id="wd-log"><i class="bi bi-clock-history me-2" aria-hidden="true"></i>Ostatnie wysyłki</div>
  <?php if (!$recent): ?><div class="card-body small text-body-secondary">Jeszcze nic nie wysłano.</div><?php else: ?>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <caption class="visually-hidden">Dziennik wysyłek tygodniowych</caption>
    <thead><tr><th scope="col">Kiedy</th><th scope="col">Tydzień od</th><th scope="col">Uczestnik</th><th scope="col">Kanał</th><th scope="col" class="text-end">Zajęć</th><th scope="col">Kto</th></tr></thead>
    <tbody><?php foreach ($recent as $r): ?><tr><td class="text-nowrap small"><?= h(substr((string)$r['sent_at'], 0, 16)) ?></td><td class="small"><?= h(date('d.m.Y', strtotime((string)$r['week_start']))) ?></td><td><?= h((string)$r['name']) ?></td>
      <td><span class="badge text-bg-<?= $r['channel'] === 'sms' ? 'info' : 'secondary' ?>"><i class="bi bi-<?= $r['channel'] === 'sms' ? 'chat-text' : 'envelope' ?> me-1" aria-hidden="true"></i><?= $r['channel'] === 'sms' ? 'SMS' : 'E-mail' ?></span></td>
      <td class="text-end"><?= (int)$r['lessons'] ?></td><td class="small"><?= h((string)$r['sent_by']) ?></td></tr><?php endforeach; ?></tbody>
  </table></div><?php endif; ?>
</section>
</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
