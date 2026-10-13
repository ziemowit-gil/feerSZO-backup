<?php
/**
 * karty30/ti/dydaktyk/konsultacje.php — Karty konsultacji (przeniesione z karty30/consultations/index.php).
 *
 * Lista kart (numer KON.XXX.MM.RRRR) z filtrami: beneficjent / numer, status, miesiąc, rodzaj (samodzielne / terminy
 * szkoleń krótkich SzK). Kadra widzi wszystkie, doradca i prowadzący — swoje. ?course=<id> pokazuje panel szkolenia
 * krótkiego: terminy z kartami, gotowość do zamknięcia i przycisk „Zamknij szkolenie” (karty → EZD).
 * Logika: modules/ti_consultations/logic/consultations.php.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_consultations/logic/consultations.php';

$me    = dyd_require();
$uid   = (int)$me['user_id'];
$staff = dyd_is_staff();
karty30_migrate();
$isConsultant = in_array('konsultacje', dyd_modes_available(), true);   // uprawnienie do Konsultacji (Zespół i role → Tryby pracy)

// Panel szkolenia krótkiego (zamknięcie)
$courseId = (int)($_GET['course'] ?? 0);
$course   = $courseId ? db_one("SELECT id, name, internal_desc, simple_mode, closed_at, instructor_id FROM k30_ti_courses WHERE id=?", [$courseId]) : null;
if ($course && !dyd_owns_course($uid, $courseId)) $course = null;   // kadra albo prowadzący tej grupy
// Bez uprawnienia do Konsultacji — tylko panel własnego szkolenia krótkiego (karty jego terminów)
if (!$isConsultant && !$course) { flash_set('warning', 'Nie masz uprawnień do Konsultacji — ustawia je kierownik w „Zespół i role”.'); header('Location: index.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    if (($_POST['_op'] ?? '') === 'close_group' && $course && $staff) {
        $chk = TiConsultations::groupClosable($courseId);
        if (!$chk['ok'] && empty($_POST['force'])) {
            flash_set('danger', 'Nie zamknięto: ' . $chk['reason']);
        } else {
            $sid = TiConsultations::closeGroup($courseId, $uid, (string)($me['name'] ?? ''));
            flash_set('success', 'Szkolenie ' . $course['name'] . ' zamknięte i zarchiwizowane.' . ($sid ? ' Karty konsultacji trafiły do EZD.' : (TiConsultations::ezdActive() ? ' Nie udało się zapisać kart w EZD — sprawdź klasę JRWA 381.' : '')));
        }
        header('Location: konsultacje.php?course=' . $courseId); exit;
    }
}

$f = ['q' => trim((string)($_GET['q'] ?? '')), 'status' => (string)($_GET['status'] ?? ''), 'ym' => (string)($_GET['ym'] ?? ''),
      'kind' => (string)($_GET['kind'] ?? ''), 'client_id' => (int)($_GET['client_id'] ?? 0), 'course_id' => $course ? $courseId : 0];
$rows = TiConsultations::list($f, $uid, $staff);
$ST = TiConsultations::statuses();

$sessions = [];
if ($course) {
    $sessions = db_all(
        "SELECT s.id, s.lesson_date, s.time_from, s.time_to, s.status, s.lesson_method,
                (SELECT COUNT(*) FROM k30_consultations k WHERE k.session_id = s.id AND k.status = 'completed') AS ok_cards,
                (SELECT COUNT(*) FROM k30_consultations k WHERE k.session_id = s.id AND k.status = 'draft') AS draft_cards
           FROM k30_ti_sessions s WHERE s.course_id = ? AND s.draft_id IS NULL AND s.status NOT IN ('draft','reserved')
          ORDER BY s.lesson_date, s.time_from", [$courseId]);
    $closable = TiConsultations::groupClosable($courseId);
}

$KP_TITLE  = 'Karty konsultacji — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => (string)($me['name'] ?? ''), 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
$STL = ['planned' => 'zaplanowana', 'held' => 'odbyta', 'individual_change' => 'odbyta (indyw.)', 'remote_material' => 'praca własna', 'cancelled' => 'odwołana'];
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<?php if ($staff): $KIER_CUR = 'konsultacje.php'; $KIER_LABEL = 'Karty konsultacji'; include __DIR__ . '/_kierownik_bar.php'; endif; ?>

<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1200px">
<div class="d-flex align-items-center gap-2 flex-wrap mb-2">
  <h1 class="h4 fw-bold mb-0"><i class="bi bi-clipboard2-check text-primary me-2" aria-hidden="true"></i>Karty konsultacji</h1>
  <?php if ($staff || $isConsultant): ?><a class="btn btn-sm btn-primary ms-auto" href="konsultacja.php?nowa=1"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nowa konsultacja</a><?php endif; ?>
</div>
<p class="small text-body-secondary">Numer karty: <span class="font-monospace">KON.XXX.MM.RRRR</span> (kolejny w miesiącu). Karty terminów szkoleń krótkich (SzK) wypełnia się z karty lekcji albo z listy szkoleń krótkich.</p>
<?= flash_html() ?>

<?php if ($course): ?>
<section class="card border-0 shadow-sm mb-3" aria-labelledby="kc-course-h">
  <div class="card-header fw-semibold d-flex flex-wrap align-items-center gap-2" id="kc-course-h">
    <span>Szkolenie krótkie <span class="font-monospace"><?= h($course['name']) ?></span><?php if (trim((string)$course['internal_desc']) !== ''): ?> <span class="fw-normal fst-italic text-body-secondary">— <?= h($course['internal_desc']) ?></span><?php endif; ?></span>
    <?= !empty($course['closed_at']) ? '<span class="badge text-bg-secondary">zamknięte ' . h(substr((string)$course['closed_at'], 0, 10)) . '</span>' : '' ?>
    <a class="small ms-auto" href="konsultacje.php">Wszystkie karty</a>
  </div>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <caption class="visually-hidden">Terminy szkolenia i ich karty</caption>
    <thead class="table-light"><tr><th scope="col">Termin</th><th scope="col">Forma</th><th scope="col">Status lekcji</th><th scope="col">Karty</th><th scope="col"><span class="visually-hidden">Akcje</span></th></tr></thead>
    <tbody>
    <?php foreach ($sessions as $i => $s): ?>
    <tr>
      <th scope="row" class="fw-normal"><?= $i + 1 ?>. <?= h(date('d.m.Y', strtotime((string)$s['lesson_date']))) ?> <?= h(substr((string)$s['time_from'], 0, 5)) ?>–<?= h(substr((string)$s['time_to'], 0, 5)) ?></th>
      <td class="small"><?= str_starts_with((string)$s['lesson_method'], 'zdalna') ? 'online' : 'stacjonarnie' ?></td>
      <td class="small"><?= h($STL[$s['status']] ?? $s['status']) ?></td>
      <td class="small"><?= (int)$s['ok_cards'] ?> zatwierdz.<?= (int)$s['draft_cards'] ? ', <span class="text-warning-emphasis fw-semibold">' . (int)$s['draft_cards'] . ' robocze</span>' : '' ?></td>
      <td class="text-end"><?php if ($s['status'] !== 'cancelled'): ?><a class="btn btn-sm btn-outline-primary" href="konsultacjaTermin.php?session=<?= (int)$s['id'] ?>">Karty terminu</a><?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if (empty($course['closed_at'])): ?>
  <div class="card-body border-top">
    <?php if ($closable['ok']): ?>
      <p class="small mb-2 text-success-emphasis"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>Wszystkie terminy rozliczone, karty zatwierdzone (<?= (int)$closable['cards'] ?>). Szkolenie zamknie się samo po zatwierdzeniu ostatniej karty — albo zamknij je teraz.</p>
    <?php else: ?>
      <p class="small mb-2 text-body-secondary"><i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>Jeszcze nie do zamknięcia: <?= h($closable['reason']) ?></p>
    <?php endif; ?>
    <?php if ($staff): ?>
    <form method="post" class="d-flex flex-wrap align-items-center gap-2" onsubmit="return confirm('Zamknąć i zarchiwizować szkolenie? Karty zatwierdzone trafią do EZD, a dokumentacja zostanie zablokowana.')">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="close_group">
      <?php if (!$closable['ok']): ?><div class="form-check small mb-0"><input class="form-check-input" type="checkbox" name="force" value="1" id="kc-force"><label class="form-check-label" for="kc-force">Zamknij mimo braków</label></div><?php endif; ?>
      <button class="btn btn-sm <?= $closable['ok'] ? 'btn-success' : 'btn-outline-danger' ?>"><i class="bi bi-lock me-1" aria-hidden="true"></i>Zamknij szkolenie (karty → EZD)</button>
    </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<form method="get" class="row g-2 align-items-end mb-3" role="search" aria-label="Filtry kart">
  <?php if ($course): ?><input type="hidden" name="course" value="<?= (int)$courseId ?>"><?php endif; ?>
  <div class="col-sm-4 col-lg-3"><label class="form-label small mb-0" for="kc-q">Beneficjent lub numer</label><input class="form-control form-control-sm" id="kc-q" name="q" value="<?= h($f['q']) ?>"></div>
  <div class="col-sm-3 col-lg-2"><label class="form-label small mb-0" for="kc-st">Etap</label>
    <select class="form-select form-select-sm" id="kc-st" name="status"><option value="">wszystkie</option><?php foreach (TiConsultations::STAGES as $k => [$l]): ?><option value="<?= h($k) ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
  <div class="col-sm-3 col-lg-2"><label class="form-label small mb-0" for="kc-ym">Miesiąc</label><input class="form-control form-control-sm" type="month" id="kc-ym" name="ym" value="<?= h($f['ym']) ?>"></div>
  <div class="col-sm-3 col-lg-2"><label class="form-label small mb-0" for="kc-kind">Rodzaj</label>
    <select class="form-select form-select-sm" id="kc-kind" name="kind"><option value="">wszystkie</option><option value="sam"<?= $f['kind'] === 'sam' ? ' selected' : '' ?>>samodzielne</option><option value="szk"<?= $f['kind'] === 'szk' ? ' selected' : '' ?>>terminy SzK</option></select></div>
  <div class="col-auto"><button class="btn btn-sm btn-outline-primary">Pokaż</button></div>
</form>

<?php if (!$rows): ?>
<div class="alert alert-secondary">Brak kart dla wybranych filtrów.</div>
<?php else: ?>
<div class="table-responsive">
<table class="table table-sm table-hover align-middle">
  <caption class="visually-hidden">Karty konsultacji</caption>
  <thead class="table-light"><tr><th scope="col">Numer</th><th scope="col">Data</th><th scope="col">Beneficjent</th><th scope="col">Konsultant</th><th scope="col">Szkolenie</th><th scope="col" class="text-end">Min</th><th scope="col">Etap</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $st = $ST[$r['status']] ?? ['label' => $r['status'], 'color' => '#555', 'bg' => '#eee']; ?>
  <tr>
    <th scope="row"><a class="font-monospace" href="konsultacja.php?id=<?= (int)$r['id'] ?>"><?= h($r['card_no'] ?: '#' . $r['id']) ?></a></th>
    <td class="small text-nowrap"><?= h(date('d.m.Y H:i', strtotime((string)$r['consultation_datetime']))) ?></td>
    <td><?= h($r['client_name'] ?? '') ?></td>
    <td class="small"><?= h($r['consultant_name'] ?? '—') ?></td>
    <td class="small"><?php if ($r['course_id']): ?><a class="font-monospace" href="konsultacje.php?course=<?= (int)$r['course_id'] ?>"><?= h($r['course_name'] ?? '') ?></a><?php else: ?>—<?php endif; ?></td>
    <td class="text-end small"><?= $r['duration_minutes'] ? (int)$r['duration_minutes'] : '—' ?></td>
    <td><?= TiConsultations::stageBadge($r) ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
