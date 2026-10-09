<?php
/**
 * karty30/ti/dydaktyk/planLinki.php — Publiczne linki do planów grup (kierownik).
 *
 * Osobny tajny link na każdą grupę: włącz / wyłącz / wygeneruj nowy adres, ustaw
 * zakres siatki, kontakty do prowadzących, listę terminów i komunikat dla odbiorców.
 * Strona publiczna: planPubliczny.php?g=<token> (na subdomenie ti.* także /plan/<token>).
 * Logika: modules/ti_plan_public/logic/.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_plan_public/logic/planPublicManager.php';
require_once dirname(__DIR__, 3) . '/modules/ti_plan_public/logic/planPublicNotify.php';

$me = dyd_require();
if (!dyd_is_staff()) { header('Location: index.php'); exit; }
$uid      = (int)$me['user_id'];
$dyd_name = (string)($me['name'] ?? '') ?: 'Kierownik';
tiPlanPublicMigrate();

$focus = (int)($_GET['course_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op  = (string)($_POST['_op'] ?? '');
    $cid = (int)($_POST['course_id'] ?? 0);
    $ok  = $cid && db_one("SELECT id FROM k30_ti_courses WHERE id=?", [$cid]);
    if (!$ok) {
        flash_set('danger', 'Nie znaleziono grupy.');
    } else {
        $c = db_one("SELECT name FROM k30_ti_courses WHERE id=?", [$cid]);
        switch ($op) {
            case 'enable':
                tiPlanPublicEnable($cid, $uid);
                flash_set('success', 'Plan grupy „' . $c['name'] . '” jest opublikowany — skopiuj link poniżej.');
                break;
            case 'disable':
                tiPlanPublicDisable($cid);
                flash_set('warning', 'Publikacja planu grupy „' . $c['name'] . '” wyłączona — link przestał działać.');
                break;
            case 'regenerate':
                tiPlanPublicRegenerate($cid, $uid);
                flash_set('success', 'Nowy adres dla grupy „' . $c['name'] . '” — poprzedni link już nie działa.');
                break;
            case 'send':
                $ch = [];
                if (!empty($_POST['ch_email'])) $ch[] = 'email';
                if (!empty($_POST['ch_sms']))   $ch[] = 'sms';
                $res = TiPlanPublicNotifier::send($cid, $ch, $uid, (string)($_POST['extra_note'] ?? ''));
                flash_set($res['ok'] ? 'success' : 'warning', 'Wysyłka linku do planu grupy „' . $c['name'] . '” — ' . $res['msg']);
                break;
            case 'options':
                if (!tiPlanPublicGet($cid)) tiPlanPublicEnable($cid, $uid);
                tiPlanPublicUpdateOptions($cid, $_POST);
                flash_set('success', 'Ustawienia linku grupy „' . $c['name'] . '” zapisane.');
                break;
            default:
                flash_set('danger', 'Nieznana operacja.');
        }
    }
    header('Location: planLinki.php' . ($cid ? '?course_id=' . $cid . '#g' . $cid : ''));
    exit;
}

$courses = k30_ti_courses(false);
usort($courses, fn($a, $b) => strcasecmp((string)$a['name'], (string)$b['name']));
$links   = tiPlanPublicMap(array_column($courses, 'id'));
$q = trim((string)($_GET['q'] ?? ''));
$only = (string)($_GET['only'] ?? '');
$rows = [];
foreach ($courses as $c) {
    $cid = (int)$c['id'];
    $l   = $links[$cid] ?? null;
    $on  = $l && (int)$l['is_enabled'] === 1;
    if ($q !== '' && mb_stripos((string)$c['name'], $q) === false) continue;
    if ($only === 'on' && !$on) continue;
    if ($only === 'off' && $on) continue;
    $rows[] = ['c' => $c, 'l' => $l, 'on' => $on];
}
$nOn = count(array_filter($links, fn($l) => (int)$l['is_enabled'] === 1));

$KP_TITLE  = 'Linki do planów grup — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => $dyd_name, 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<style>
  .pl-card { border: 1px solid #adb5bd; border-left-width: 6px; border-radius: 4px; background: #fff; margin-bottom: .75rem; }
  .pl-card.is-on  { border-left-color: #0a3622; }
  .pl-card.is-off { border-left-color: #6c757d; }
  .pl-card:target { box-shadow: 0 0 0 3px #10335c; }
  .pl-head { display: flex; flex-wrap: wrap; gap: .5rem 1rem; align-items: center; padding: .6rem .9rem; }
  .pl-body { border-top: 1px dashed #dee2e6; padding: .7rem .9rem; }
  .badge.pl-b { font-weight: 700; font-size: .78rem; padding: .3em .55em; border: 1px solid currentColor; white-space: nowrap; }
  .badge.pl-on  { background: #d1e7dd; color: #0a3622; }
  .badge.pl-off { background: #e2e3e5; color: #2b2f32; }
  .pl-url { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .85rem; }
  .pl-stats { font-size: .82rem; color: #495057; }
</style>

<?php $KIER_CUR = 'planLinki.php'; $KIER_LABEL = 'Linki do planów grup';
   include __DIR__ . '/_kierownik_bar.php'; ?>

<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1100px">

<div class="d-flex align-items-center mb-2 gap-2 flex-wrap">
  <h1 class="h4 mb-0 fw-bold"><i class="bi bi-link-45deg text-primary me-2" aria-hidden="true"></i>Publiczne linki do planów grup</h1>
  <span class="text-body-secondary small">osobny tajny adres dla każdej grupy — bez logowania, bez danych kursantów</span>
</div>
<p class="small text-body-secondary mb-3">
  Link pokazuje siatkę tygodnia (jak wydruk „Siatka”) i listę nadchodzących terminów danej grupy.
  Odbiorca widzi zawsze aktualny stan — nie trzeba rozsyłać nowych PDF-ów po zmianie terminu.
  Wyłączenie linku blokuje go natychmiast; „Nowy adres” unieważnia stary (np. gdy trafił do niepowołanych osób).
  Link można też wysłać kursantom grupy e-mailem / SMS-em (szablon <em>ti_plan_link</em>, wg zgód z kont kursantów).
  Opublikowanych: <strong><?= $nOn ?></strong> z <?= count($courses) ?> grup.
</p>

<?= flash_html() ?>

<form method="get" class="row g-2 align-items-end mb-3" role="search">
  <div class="col-sm-5">
    <label class="form-label small mb-0" for="pl-q">Szukaj grupy</label>
    <input type="search" class="form-control form-control-sm" id="pl-q" name="q" value="<?= h($q) ?>" placeholder="nazwa grupy">
  </div>
  <div class="col-sm-3">
    <label class="form-label small mb-0" for="pl-only">Pokaż</label>
    <select class="form-select form-select-sm" id="pl-only" name="only">
      <option value="" <?= $only === '' ? 'selected' : '' ?>>wszystkie grupy</option>
      <option value="on" <?= $only === 'on' ? 'selected' : '' ?>>tylko opublikowane</option>
      <option value="off" <?= $only === 'off' ? 'selected' : '' ?>>tylko nieopublikowane</option>
    </select>
  </div>
  <div class="col-sm-auto"><button class="btn btn-sm btn-outline-primary">Filtruj</button>
    <?php if ($q !== '' || $only !== ''): ?><a class="btn btn-sm btn-link" href="planLinki.php">Wyczyść</a><?php endif; ?></div>
</form>

<div role="status" class="visually-hidden">Wyświetlono <?= count($rows) ?> grup.</div>

<?php if (!$rows): ?>
<div class="alert alert-secondary">Brak grup spełniających kryteria.</div>
<?php endif; ?>

<?php foreach ($rows as $r): $c = $r['c']; $l = $r['l']; $cid = (int)$c['id']; $on = $r['on'];
  $url = $l ? tiPlanPublicUrl($l) : ''; $short = $l ? tiPlanPublicShortUrl($l) : null; $open = $on && ($focus === $cid || $focus === 0 && count($rows) <= 3); ?>
<section class="pl-card <?= $on ? 'is-on' : 'is-off' ?>" id="g<?= $cid ?>" aria-labelledby="g<?= $cid ?>-h">
  <div class="pl-head">
    <h2 class="h6 mb-0 fw-bold" id="g<?= $cid ?>-h"><?= h($c['name']) ?></h2>
    <span class="small text-body-secondary"><?= h($c['instructor_name'] ?? '') ?></span>
    <?php if ($on): ?><span class="badge pl-b pl-on"><i class="bi bi-globe2 me-1" aria-hidden="true"></i>opublikowany</span>
    <?php else: ?><span class="badge pl-b pl-off"><i class="bi bi-eye-slash me-1" aria-hidden="true"></i>nieopublikowany</span><?php endif; ?>
    <?php if ($l): ?><span class="pl-stats">wyświetleń: <?= (int)$l['views'] ?><?= $l['last_view_at'] ? ', ostatnio ' . h(date('d.m.Y H:i', strtotime((string)$l['last_view_at']))) : '' ?></span><?php endif; ?>
    <div class="ms-auto d-flex gap-1 flex-wrap">
      <?php if ($on): ?>
        <a class="btn btn-sm btn-outline-primary" href="<?= h($url) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Otwórz</a>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#opt<?= $cid ?>" aria-expanded="<?= $open ? 'true' : 'false' ?>" aria-controls="opt<?= $cid ?>"><i class="bi bi-sliders me-1" aria-hidden="true"></i>Ustawienia</button>
        <form method="post" class="d-inline" onsubmit="return confirm('Wygenerować nowy adres dla grupy „<?= h(addslashes($c['name'])) ?>&quot;?\n\nDotychczasowy link przestanie działać.')">
          <input type="hidden" name="_token" value="<?= dyd_token() ?>"><input type="hidden" name="_op" value="regenerate"><input type="hidden" name="course_id" value="<?= $cid ?>">
          <button class="btn btn-sm btn-outline-warning"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Nowy adres</button>
        </form>
        <form method="post" class="d-inline" onsubmit="return confirm('Wyłączyć publikację planu grupy „<?= h(addslashes($c['name'])) ?>&quot;?\n\nLink przestanie działać (ponowne włączenie przywróci ten sam adres).')">
          <input type="hidden" name="_token" value="<?= dyd_token() ?>"><input type="hidden" name="_op" value="disable"><input type="hidden" name="course_id" value="<?= $cid ?>">
          <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle me-1" aria-hidden="true"></i>Wyłącz</button>
        </form>
      <?php else: ?>
        <form method="post" class="d-inline">
          <input type="hidden" name="_token" value="<?= dyd_token() ?>"><input type="hidden" name="_op" value="enable"><input type="hidden" name="course_id" value="<?= $cid ?>">
          <button class="btn btn-sm btn-success"><i class="bi bi-globe2 me-1" aria-hidden="true"></i>Opublikuj plan</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($on): ?>
  <div class="pl-body">
    <label class="form-label small fw-semibold mb-1" for="url<?= $cid ?>">Adres planu (do przekazania kursantom / rodzicom / instytucjom)</label>
    <div class="input-group input-group-sm mb-1">
      <input type="text" class="form-control pl-url" id="url<?= $cid ?>" value="<?= h($url) ?>" readonly onfocus="this.select()">
      <button type="button" class="btn btn-outline-secondary" data-copy="url<?= $cid ?>"><i class="bi bi-clipboard me-1" aria-hidden="true"></i>Kopiuj</button>
    </div>
    <?php if ($short): ?>
    <div class="input-group input-group-sm mb-2">
      <span class="input-group-text">krótki</span>
      <input type="text" class="form-control pl-url" id="surl<?= $cid ?>" value="<?= h($short) ?>" readonly onfocus="this.select()">
      <button type="button" class="btn btn-outline-secondary" data-copy="surl<?= $cid ?>"><i class="bi bi-clipboard me-1" aria-hidden="true"></i>Kopiuj</button>
    </div>
    <?php endif; ?>
    <div class="collapse<?= $open ? ' show' : '' ?>" id="opt<?= $cid ?>">
      <form method="post" class="row g-2 align-items-end mt-1">
        <input type="hidden" name="_token" value="<?= dyd_token() ?>"><input type="hidden" name="_op" value="options"><input type="hidden" name="course_id" value="<?= $cid ?>">
        <div class="col-sm-3 col-md-2">
          <label class="form-label small mb-0" for="w<?= $cid ?>">Zakres siatki</label>
          <select class="form-select form-select-sm" id="w<?= $cid ?>" name="weeks">
            <?php foreach (TI_PLAN_PUBLIC_WEEKS as $w): ?><option value="<?= $w ?>" <?= (int)$l['weeks'] === $w ? 'selected' : '' ?>><?= $w ?> tyg.</option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-4 col-md-3">
          <div class="form-check"><input class="form-check-input" type="checkbox" id="sl<?= $cid ?>" name="show_list" value="1" <?= (int)$l['show_list'] ? 'checked' : '' ?>><label class="form-check-label small" for="sl<?= $cid ?>">Lista terminów pod siatką</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" id="sc<?= $cid ?>" name="show_contacts" value="1" <?= (int)$l['show_contacts'] ? 'checked' : '' ?>><label class="form-check-label small" for="sc<?= $cid ?>">Kontakty do prowadzących (telefon, e-mail)</label></div>
        </div>
        <div class="col-sm-5 col-md-5">
          <label class="form-label small mb-0" for="n<?= $cid ?>">Komunikat na stronie planu (opcjonalny)</label>
          <input type="text" class="form-control form-control-sm" id="n<?= $cid ?>" name="note" maxlength="500" value="<?= h($l['note']) ?>" placeholder="np. plan może się zmienić do 15 października">
        </div>
        <div class="col-sm-auto"><button class="btn btn-sm btn-primary">Zapisz</button></div>
      </form>
    </div>

    <?php /* ── Wysyłka linku do kursantów (e-mail / SMS) ── */ ?>
    <div class="mt-2 pt-2 border-top" id="send<?= $cid ?>">
      <?php if ($open): $pv = TiPlanPublicNotifier::preview($cid); $hist = TiPlanPublicNotifier::history($cid, 3); ?>
      <form method="post" class="row g-2 align-items-end"
            onsubmit="return confirm('Wysłać link do planu grupy „<?= h(addslashes($c['name'])) ?>&quot; do kursantów?\n\nE-mail: <?= (int)$pv['email_ok'] ?> adresów, SMS: <?= (int)$pv['sms_ok'] ?> numerów.')">
        <input type="hidden" name="_token" value="<?= dyd_token() ?>"><input type="hidden" name="_op" value="send"><input type="hidden" name="course_id" value="<?= $cid ?>">
        <div class="col-12">
          <span class="fw-semibold small"><i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij link kursantom</span>
          <span class="small text-body-secondary">— aktywne zapisy: <?= (int)$pv['students'] ?>; e-mail dotrze do <?= (int)$pv['email_ok'] ?>, SMS do <?= (int)$pv['sms_ok'] ?> odbiorców (kursanci i opiekunowie małoletnich, wg ich zgód).</span>
          <?php $sk = []; foreach ($pv['email_skip'] as $why => $n) $sk[] = 'e-mail: ' . $why . ' (' . $n . ')'; foreach ($pv['sms_skip'] as $why => $n) $sk[] = 'SMS: ' . $why . ' (' . $n . ')'; ?>
          <?php if ($sk): ?><div class="small text-body-secondary">Pominięci — <?= h(implode('; ', $sk)) ?>.</div><?php endif; ?>
        </div>
        <div class="col-sm-auto">
          <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="ce<?= $cid ?>" name="ch_email" value="1" <?= $pv['email_ok'] ? 'checked' : 'disabled' ?>><label class="form-check-label small" for="ce<?= $cid ?>">E-mail</label></div>
          <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="cs<?= $cid ?>" name="ch_sms" value="1" <?= $pv['sms_ok'] ? 'checked' : 'disabled' ?>><label class="form-check-label small" for="cs<?= $cid ?>">SMS</label></div>
        </div>
        <div class="col-sm">
          <label class="form-label small mb-0 visually-hidden" for="en<?= $cid ?>">Dodatkowa uwaga w e-mailu</label>
          <input type="text" class="form-control form-control-sm" id="en<?= $cid ?>" name="extra_note" maxlength="300" placeholder="dodatkowa uwaga w e-mailu (opcjonalnie)">
        </div>
        <div class="col-sm-auto">
          <button class="btn btn-sm btn-primary" <?= ($pv['email_ok'] || $pv['sms_ok']) ? '' : 'disabled' ?>><i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij</button>
        </div>
      </form>
      <?php if ($hist): ?>
      <ul class="list-unstyled small text-body-secondary mb-0 mt-2">
        <?php foreach ($hist as $hh): ?>
        <li><i class="bi bi-clock-history me-1" aria-hidden="true"></i><?= h(date('d.m.Y H:i', strtotime((string)$hh['created_at']))) ?> — wysłano <?= (int)$hh['sent'] ?> (e-mail <?= (int)$hh['sent_email'] ?>, SMS <?= (int)$hh['sent_sms'] ?>)<?= (int)$hh['failed'] ? ', błędy ' . (int)$hh['failed'] : '' ?><?= (int)$hh['skipped'] ? ', pominięto ' . (int)$hh['skipped'] : '' ?></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
      <?php else: ?>
      <a class="btn btn-sm btn-outline-primary" href="planLinki.php?course_id=<?= $cid ?>#send<?= $cid ?>"><i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij link kursantom…</a>
      <?php if (!empty($l['notified_at'])): ?><span class="small text-body-secondary ms-2">ostatnia wysyłka: <?= h(date('d.m.Y H:i', strtotime((string)$l['notified_at']))) ?></span><?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</section>
<?php endforeach; ?>

</div>
</main>
<script>
document.addEventListener('click', function (e) {
  var b = e.target.closest('[data-copy]'); if (!b) return;
  var inp = document.getElementById(b.getAttribute('data-copy')); if (!inp) return;
  var done = function () { var t = b.innerHTML; b.innerHTML = '<i class="bi bi-check2 me-1" aria-hidden="true"></i>Skopiowano'; b.setAttribute('aria-live', 'polite'); setTimeout(function () { b.innerHTML = t; }, 1800); };
  if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(inp.value).then(done, function () { inp.select(); document.execCommand('copy'); done(); });
  else { inp.select(); document.execCommand('copy'); done(); }
});
</script>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
