<?php
/**
 * karty30/ti/dydaktyk/protokoly_moje.php — Protokoły miesięczne (widok prowadzącego).
 *
 * Prosty kreator: lista miesięcy do zamknięcia (per kurs) → podsumowanie
 * (lekcje, frekwencja) → "Zatwierdź protokół". Osobny tor od protokoly.php
 * (widok kierownika, protokoły per-okres) — patrz includes/ti_protocols.php.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_protocols.php';

$me      = dyd_require();
$uid     = (int)$me['user_id'];
$me_name = (string)($me['name'] ?? '');
karty30_migrate();
ti_protocols_migrate();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op = $_POST['_op'] ?? '';

    if ($op === 'approve_month') {
        $cid = (int)($_POST['course_id'] ?? 0);
        $ym  = (string)($_POST['year_month'] ?? '');
        if (!$cid || !preg_match('/^\d{4}-\d{2}$/', $ym) || !dyd_owns_course($uid, $cid)) {
            flash_set('danger', 'Nieprawidłowe dane protokołu.');
            header('Location: protokoly_moje.php'); exit;
        }
        // Wprost na bazie. Wcześniejsze samo-wywołanie api_protocols.php przez HTTP
        // zawsze kończyło się 403 (brak tokenu CSRF) i blokowało się na zamku pliku
        // sesji tego samego żądania — dawało tylko 3 s opóźnienia przed fallbackiem.
        try {
            $prot = ti_protocol_get_or_create_for_month($cid, $ym);
            ti_protocol_approve((int)$prot['id'], $uid, $me_name);
            flash_set('success', 'Protokół za ' . $ym . ' zatwierdzony.');
        } catch (\Throwable $e) {
            flash_set('danger', $e->getMessage());
        }
        header('Location: protokoly_moje.php'); exit;
    }
}

$pending = ti_protocol_pending_months_for_instructor($uid);
$closed  = ti_protocol_closed_months_for_instructor($uid);

// Krok 2 kreatora: konkretny kurs+miesiąc wybrany z listy — pokaż podsumowanie.
$open_cid = (int)($_GET['course'] ?? 0);
$open_ym  = (string)($_GET['ym'] ?? '');
$open_row = null;
if ($open_cid && preg_match('/^\d{4}-\d{2}$/', $open_ym)) {
    foreach ($pending as $p) {
        if ($p['course_id'] === $open_cid && $p['year_month'] === $open_ym) { $open_row = $p; break; }
    }
}
$open_summary = $open_row ? ti_protocol_month_summary($open_cid, $open_ym) : null;

$_mon_names = [1=>'styczeń',2=>'luty',3=>'marzec',4=>'kwiecień',5=>'maj',6=>'czerwiec',
               7=>'lipiec',8=>'sierpień',9=>'wrzesień',10=>'październik',11=>'listopad',12=>'grudzień'];
$fmt_ym = function (string $ym) use ($_mon_names): string {
    [$y, $m] = explode('-', $ym);
    return ($_mon_names[(int)$m] ?? $ym) . ' ' . $y;
};

$KP_TITLE  = 'Protokoły — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => $me_name, 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">

<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:760px">
<?= flash_html() ?>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h1 class="h4 mb-0 fw-bold"><i class="bi bi-journal-check text-primary me-2" aria-hidden="true"></i>Protokoły</h1>
  <?php if ($pending): ?><span class="badge bg-warning text-dark"><?= count($pending) ?></span><?php endif; ?>
  <a href="index.php?tab=pulpit" class="btn btn-sm btn-outline-secondary ms-auto">
    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Pulpit
  </a>
</div>

<?php if ($open_row): ?>
<!-- ═══ KROK 2: podsumowanie i potwierdzenie ═══════════════════════════ -->
<div class="card border-0 shadow-sm">
  <div class="card-header fw-semibold">
    <i class="bi bi-journal-text me-2 text-primary" aria-hidden="true"></i>
    <?= h($open_row['course_name']) ?> — <?= h($fmt_ym($open_ym)) ?>
  </div>
  <div class="card-body">
    <?php if ($open_row['is_overdue']): ?>
    <div class="alert alert-warning py-2 small mb-3">
      <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Ten miesiąc już się skończył — zamknij protokół najszybciej jak możesz.
    </div>
    <?php else: ?>
    <div class="alert alert-info py-2 small mb-3">
      <i class="bi bi-info-circle me-1" aria-hidden="true"></i>Bieżący miesiąc — zwykle zamyka się go po zakończeniu, ale możesz też teraz.
    </div>
    <?php endif; ?>

    <dl class="row mb-3">
      <dt class="col-sm-6">Lekcje odbyte</dt>
      <dd class="col-sm-6"><?= (int)$open_summary['lessons_held'] ?> z <?= (int)$open_summary['lessons_total'] ?> zaplanowanych</dd>
      <dt class="col-sm-6">Średnia frekwencja</dt>
      <dd class="col-sm-6"><?= $open_summary['attendance_pct'] !== null ? (int)$open_summary['attendance_pct'] . '%' : '—' ?></dd>
    </dl>

    <p class="text-body-secondary small">
      Zatwierdzenie zamyka protokół za ten miesiąc — nie da się go już cofnąć samodzielnie
      (odblokować może administrator, z podaniem powodu).
    </p>

    <div class="d-flex gap-2">
      <a href="protokoly_moje.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Wstecz
      </a>
      <form method="post" class="ms-auto" onsubmit="return confirm('Zatwierdzić protokół za <?= h(addslashes($fmt_ym($open_ym))) ?>? Tej operacji nie można cofnąć samodzielnie.')">
        <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
        <input type="hidden" name="_op" value="approve_month">
        <input type="hidden" name="course_id" value="<?= (int)$open_row['course_id'] ?>">
        <input type="hidden" name="year_month" value="<?= h($open_ym) ?>">
        <button type="submit" class="btn btn-success">
          <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Zatwierdź protokół
        </button>
      </form>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ═══ KROK 1: lista miesięcy do zamknięcia ═══════════════════════════ -->
<?php if (!$pending): ?>
<div class="alert alert-success d-flex align-items-center gap-2" role="status">
  <i class="bi bi-check-circle-fill flex-shrink-0" aria-hidden="true"></i>
  <span>Nic do zrobienia — wszystkie protokoły są zamknięte.</span>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
  <div class="card-header fw-semibold">Miesiące do zamknięcia</div>
  <ul class="list-group list-group-flush">
    <?php foreach ($pending as $p): ?>
    <li class="list-group-item d-flex align-items-center gap-3 flex-wrap">
      <div class="flex-grow-1">
        <div class="fw-semibold"><?= h($p['course_name']) ?></div>
        <div class="text-body-secondary small"><?= h($fmt_ym($p['year_month'])) ?></div>
      </div>
      <?php if ($p['is_overdue']): ?>
      <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>zaległy</span>
      <?php else: ?>
      <span class="badge bg-secondary">bieżący</span>
      <?php endif; ?>
      <a href="protokoly_moje.php?course=<?= (int)$p['course_id'] ?>&ym=<?= h($p['year_month']) ?>" class="btn btn-sm btn-primary">
        <i class="bi bi-journal-check me-1" aria-hidden="true"></i>Zamknij protokół
      </a>
    </li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<?php if ($closed): ?>
<div class="card border-0 shadow-sm mt-3">
  <div class="card-header fw-semibold">Zatwierdzone protokoły</div>
  <ul class="list-group list-group-flush">
    <?php foreach ($closed as $c): ?>
    <li class="list-group-item d-flex align-items-center gap-3 flex-wrap">
      <div class="flex-grow-1">
        <div class="fw-semibold"><?= h($c['course_name']) ?></div>
        <div class="text-body-secondary small">
          <?= h($fmt_ym($c['year_month'])) ?>
          <?php if (!empty($c['approved_at'])): ?> · zatwierdził <?= h($c['approved_name'] ?: '—') ?>, <?= h(date('d.m.Y', strtotime((string)$c['approved_at']))) ?><?php endif; ?>
        </div>
      </div>
      <span class="badge bg-success">zatwierdzony</span>
      <a href="protokol_pdf.php?id=<?= (int)$c['protocol_id'] ?>" class="btn btn-sm btn-outline-secondary"
         aria-label="Pobierz PDF protokołu: <?= h($c['course_name']) ?>, <?= h($fmt_ym($c['year_month'])) ?>">
        <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF
      </a>
    </li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>
<?php endif; ?>

</div>
</main>

<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
