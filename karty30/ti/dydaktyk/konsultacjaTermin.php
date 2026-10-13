<?php
/**
 * karty30/ti/dydaktyk/konsultacjaTermin.php — karty konsultacji jednego terminu szkolenia krótkiego (SzK).
 *
 * Prowadzący po zajęciach wpisuje raz: temat, opis, następne działania, czas trwania i zaznacza obecnych.
 * Zapis tworzy / aktualizuje roboczą kartę każdego obecnego uczestnika (numer KON.XXX.MM.RRRR) i uzupełnia dokumentację
 * lekcji (temat, obecność, czas, status „odbyła się”, gdy termin minął). „Zatwierdź wszystkie” — jeden kod IKA + x509;
 * po zatwierdzeniu ostatniej karty, gdy wszystkie terminy minęły, szkolenie zamyka się samo (karty → EZD).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_consultations/logic/consultations.php';

$me    = dyd_require();
$uid   = (int)$me['user_id'];
$staff = dyd_is_staff();
karty30_migrate();
$sid = (int)($_GET['session'] ?? 0);
if (!$sid || !dyd_owns_session($uid, $sid)) { flash_set('danger', 'Nie znaleziono terminu albo nie prowadzisz tej grupy.'); header('Location: konsultacje.php'); exit; }
$view = TiConsultations::sessionView($sid);
if (!$view || (int)$view['session']['simple_mode'] <= 0) { flash_set('warning', 'Karty terminu prowadzi się dla szkoleń krótkich (SzK). Kartę samodzielną dodasz w Kartach konsultacji.'); header('Location: konsultacje.php'); exit; }
$meRow = db_one("SELECT name, first_name, last_name, cpc_code FROM users WHERE id=?", [$uid]) ?: [];
$approver = trim(($meRow['first_name'] ?? '') . ' ' . ($meRow['last_name'] ?? '')) ?: (string)($meRow['name'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op = (string)($_POST['_op'] ?? '');
    if ($op === 'save') {
        $notes = [];
        foreach ((array)($_POST['note'] ?? []) as $k => $t) $notes[(int)$k] = (string)$t;
        $res = TiConsultations::saveSession($sid, [
            'topic' => (string)($_POST['topic'] ?? ''), 'description' => (string)($_POST['description'] ?? ''), 'next_action' => (string)($_POST['next_action'] ?? ''),
            'duration' => (int)($_POST['duration'] ?? 0), 'present' => (array)($_POST['present'] ?? []), 'notes' => $notes,
        ], $uid);
        flash_set($res['ok'] ? 'success' : 'danger', $res['msg']);
    } elseif ($op === 'mgr_approve_all' && $staff) {
        $ids = array_map(fn($c) => (int)$c['id'], array_filter($view['cards'], fn($c) => TiConsultations::stage($c) === 'signed'));
        $res = TiConsultations::managerApprove($ids, $uid, (string)($me['name'] ?? ''));
        flash_set('success', 'Zatwierdzono jako kierownik: ' . count($res['approved']) . '.' . ($res['closed'] ? ' Szkolenie ' . $res['closed'] . ' zamknięte — karty trafiły do EZD.' : ''));
    } elseif ($op === 'approve_all') {
        $ids = array_map(fn($c) => (int)$c['id'], array_filter($view['cards'], fn($c) => $c['status'] === 'draft'));
        if (!$ids) flash_set('warning', 'Brak roboczych kart do zatwierdzenia.');
        else {
            $res = TiConsultations::approve($ids, $uid, $approver, (string)($_POST['ika_code'] ?? ''));
            if ($res['ok']) flash_set('success', 'Podpisano karty: ' . count($res['approved']) . ' — czekają na zatwierdzenie przez kierownika.');
            else foreach ($res['errors'] as $e) flash_set('danger', $e);
        }
    }
    header('Location: konsultacjaTermin.php?session=' . $sid); exit;
}

$s = $view['session']; $people = $view['people']; $cards = $view['cards'];
$first = $cards ? reset($cards) : null;
$closed = !empty($s['closed_at']);
$drafts = count(array_filter($cards, fn($c) => $c['status'] === 'draft'));
$certChk = k30_verify_consultant_cert($uid);
$hasIka  = trim((string)($meRow['cpc_code'] ?? '')) !== '';
$ST = TiConsultations::statuses();
$dayNames = ['niedziela', 'poniedziałek', 'wtorek', 'środa', 'czwartek', 'piątek', 'sobota'];

$KP_TITLE  = 'Karty terminu ' . $s['course_name'] . ' — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => (string)($me['name'] ?? ''), 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<?php if ($staff): $KIER_CUR = 'konsultacje.php'; $KIER_LABEL = 'Karty konsultacji'; include __DIR__ . '/_kierownik_bar.php'; endif; ?>

<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1000px">
<nav aria-label="Okruszki" class="small mb-2"><a href="konsultacje.php">Karty konsultacji</a> › <a class="font-monospace" href="konsultacje.php?course=<?= (int)$s['course_id'] ?>"><?= h($s['course_name']) ?></a> › termin <?= (int)$s['ordinal'] ?></nav>
<h1 class="h4 fw-bold mb-1">Karty konsultacji — termin <?= (int)$s['ordinal'] ?></h1>
<p class="small text-body-secondary mb-3"><span class="font-monospace"><?= h($s['course_name']) ?></span><?= trim((string)$s['internal_desc']) !== '' ? ' — ' . h($s['internal_desc']) : '' ?> ·
  <?= h(date('d.m.Y', strtotime((string)$s['lesson_date'])) . ' (' . $dayNames[(int)date('w', strtotime((string)$s['lesson_date']))] . '), ' . substr((string)$s['time_from'], 0, 5) . '–' . substr((string)$s['time_to'], 0, 5)) ?>
  · <a href="index.php?course=<?= (int)$s['course_id'] ?>&amp;tab=lekcje&amp;lesson=<?= $sid ?>">karta lekcji</a></p>
<?= flash_html() ?>
<?php if ($closed): ?><div class="alert alert-secondary">Szkolenie jest zamknięte — karty i dokumentacja są tylko do odczytu.</div><?php endif; ?>
<?php if ((string)$s['lesson_date'] > date('Y-m-d')): ?><div class="alert alert-info small">Termin jeszcze się nie odbył. Karty możesz przygotować wcześniej — lekcja zostanie oznaczona jako odbyta dopiero po zapisie w dniu zajęć lub później.</div><?php endif; ?>

<form method="post" class="card border-0 shadow-sm mb-3"><div class="card-body">
  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="save">
  <fieldset<?= $closed ? ' disabled' : '' ?>>
  <div class="row g-3 mb-3">
    <div class="col-md-8"><label class="form-label fw-semibold" for="kt-topic">Temat zajęć</label>
      <input class="form-control" id="kt-topic" name="topic" maxlength="200" value="<?= h((string)$s['topic']) ?>" placeholder="trafi do dziennika jako temat lekcji"></div>
    <div class="col-md-4"><label class="form-label" for="kt-dur">Czas trwania (min)</label>
      <input class="form-control" type="number" min="5" step="5" id="kt-dur" name="duration" value="<?= (int)(($first['duration_minutes'] ?? 0) ?: $s['duration_min']) ?>"></div>
  </div>
  <div class="mb-3"><label class="form-label fw-semibold" for="kt-desc">Opis konsultacji</label>
    <textarea class="form-control" id="kt-desc" name="description" rows="5"><?= h((string)($first['description'] ?? '')) ?></textarea>
    <div class="form-text">Wspólny dla wszystkich obecnych; uwagi o konkretnej osobie wpisz niżej.</div></div>
  <div class="mb-3"><label class="form-label" for="kt-next">Następne działania</label>
    <textarea class="form-control" id="kt-next" name="next_action" rows="2"><?= h((string)($first['next_action'] ?? '')) ?></textarea></div>

  <fieldset class="mb-3"><legend class="h6 fw-bold">Uczestnicy — obecność i karta</legend>
  <?php foreach ($people as $p): $cid = (int)$p['client_id']; $c = $cards[$cid] ?? null; $locked = $c && $c['status'] === 'completed';
        $st = $c ? ($ST[$c['status']] ?? null) : null; $present = $c ? true : ((int)($p['attended'] ?? 0) === 1); ?>
  <div class="border rounded p-2 mb-2">
    <div class="d-flex flex-wrap align-items-center gap-2">
      <div class="form-check mb-0"><input class="form-check-input" type="checkbox" name="present[]" value="<?= $cid ?>" id="kt-p<?= $cid ?>"<?= $present ? ' checked' : '' ?><?= $locked ? ' disabled' : '' ?>>
        <label class="form-check-label fw-semibold" for="kt-p<?= $cid ?>"><?= h($p['client_name']) ?> — obecny/a</label></div>
      <?php if ($locked): ?><input type="hidden" name="present[]" value="<?= $cid ?>"><?php endif; ?>
      <?php if ($c): ?><a class="small font-monospace ms-auto" href="konsultacja.php?id=<?= (int)$c['id'] ?>"><?= h($c['card_no']) ?></a>
        <?= TiConsultations::stageBadge($c) ?><?php endif; ?>
    </div>
    <?php if (!$locked): ?>
    <label class="form-label small mb-0 mt-1" for="kt-n<?= $cid ?>">Uwagi tylko do karty tej osoby (opcjonalnie)</label>
    <input class="form-control form-control-sm" id="kt-n<?= $cid ?>" name="note[<?= $cid ?>]">
    <?php else: ?><div class="small text-body-secondary mt-1">Karta zatwierdzona — bez zmian.</div><?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php if (!$people): ?><p class="small text-body-secondary">Brak aktywnych uczestników grupy.</p><?php endif; ?>
  </fieldset>
  <button class="btn btn-primary"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zapisz karty i dokumentację lekcji</button>
  </fieldset>
</div></form>

<?php $signed = count(array_filter($cards, fn($c) => TiConsultations::stage($c) === 'signed'));
if ($signed && $staff && !$closed): ?>
<form method="post" class="card border-0 shadow-sm mb-3"><div class="card-body d-flex flex-wrap gap-2 align-items-center">
  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="mgr_approve_all">
  <span class="small">Podpisane karty czekające na kierownika: <strong><?= $signed ?></strong></span>
  <button class="btn btn-sm btn-success ms-auto"><i class="bi bi-check2-all me-1" aria-hidden="true"></i>Zatwierdź jako kierownik</button>
</div></form>
<?php endif; ?>
<?php if ($drafts && !$closed): ?>
<section class="card border-0 shadow-sm" aria-labelledby="kt-a-h">
  <div class="card-header fw-semibold" id="kt-a-h"><i class="bi bi-shield-lock me-1 text-primary" aria-hidden="true"></i>Podpisz wszystkie robocze karty terminu (<?= $drafts ?>)</div>
  <div class="card-body">
  <?php if ($certChk['ok'] && $hasIka): ?>
    <form method="post" class="d-flex flex-wrap align-items-end gap-2">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="approve_all">
      <div><label class="form-label small mb-0" for="kt-ika">Kod IKA (6 cyfr)</label>
        <input class="form-control font-monospace text-center" style="width:9rem;letter-spacing:.3em" id="kt-ika" name="ika_code" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code" required></div>
      <button class="btn btn-success"><i class="bi bi-shield-check me-1" aria-hidden="true"></i>Podpisz (IKA + x509)</button>
      <a class="btn btn-outline-secondary" href="konsultacjaPdf.php?session=<?= $sid ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Podgląd PDF</a>
    </form>
  <?php else: ?>
    <p class="small mb-0">Zatwierdzenie wymaga <?= !$hasIka ? 'kodu IKA' : '' ?><?= !$hasIka && !$certChk['ok'] ? ' i ' : '' ?><?= !$certChk['ok'] ? 'certyfikatu x509 (' . h((string)$certChk['error']) . ')' : '' ?>. Może je zatwierdzić kierownik albo doradca z certyfikatem.</p>
  <?php endif; ?>
  </div>
</section>
<?php elseif ($cards): ?>
<p><a class="btn btn-sm btn-outline-secondary" href="konsultacjaPdf.php?session=<?= $sid ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF kart terminu</a></p>
<?php endif; ?>
</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
