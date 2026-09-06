<?php
/**
 * karty30/ti/dydaktyk/protokoly.php — Zaległe protokoły (widok kierownika).
 *
 * Protokół jest "zaległy", gdy okres, którego dotyczy, już się skończył
 * (per.date_to < dziś), a status protokołu wciąż jest 'open' — prowadzący
 * nie zdążył go zatwierdzić. Kierownik / pracownik D3 może zatwierdzić
 * pojedynczy protokół albo wszystkie zaległe naraz (ti_protocol_approve()
 * zapisuje ślad z ID i nazwą osoby zatwierdzającej — tu będzie to kierownik,
 * nie prowadzący kursu).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_protocols.php';

$me  = dyd_require();
if (!dyd_is_staff()) { header('Location: index.php'); exit; }
$uid     = (int)$me['user_id'];
$me_name = (string)($me['name'] ?? '');
karty30_migrate();
ti_protocols_migrate();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op = $_POST['_op'] ?? '';

    if ($op === 'approve_one') {
        $pid = (int)($_POST['protocol_id'] ?? 0);
        try {
            ti_protocol_approve($pid, $uid, $me_name);
            flash_set('success', 'Protokół zatwierdzony.');
        } catch (\Throwable $e) {
            flash_set('danger', $e->getMessage());
        }
        header('Location: protokoly.php'); exit;
    }

    if ($op === 'approve_all') {
        // Świeża lista — nie ufamy temu, co przeglądarka wysłała w POST,
        // bo od otwarcia strony ktoś inny mógł już część protokołów zatwierdzić.
        $rows = ti_protocols_overdue();
        $ok = 0; $skip = 0; $reasons = [];
        foreach ($rows as $r) {
            try {
                ti_protocol_approve((int)$r['id'], $uid, $me_name);
                $ok++;
            } catch (\Throwable $e) {
                $skip++;
                $reasons[] = $r['course_name'] . ': ' . $e->getMessage();
            }
        }
        $msg = $ok > 0 ? "Zatwierdzono {$ok} " . ($ok === 1 ? 'protokół' : 'protokołów') . '.' : 'Nie zatwierdzono żadnego protokołu.';
        if ($skip) $msg .= " Pominięto {$skip}: " . implode('; ', array_slice($reasons, 0, 5));
        flash_set($skip && !$ok ? 'danger' : ($skip ? 'warning' : 'success'), $msg);
        header('Location: protokoly.php'); exit;
    }
}

$overdue = ti_protocols_overdue();

$KP_TITLE  = 'Zaległe protokoły — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => $me_name, 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">

<?php $KIER_CUR = 'protokoly.php'; $KIER_LABEL = 'Zaległe protokoły';
   include __DIR__ . '/_kierownik_bar.php'; ?>

<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1100px">
<?= flash_html() ?>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h1 class="h4 mb-0 fw-bold"><i class="bi bi-exclamation-octagon text-danger me-2" aria-hidden="true"></i>Zaległe protokoły</h1>
  <span class="badge bg-danger"><?= count($overdue) ?></span>
</div>

<p class="text-body-secondary small mb-3">
  Protokoły, których okres nauczania już się skończył, a prowadzący nie zdążył ich zatwierdzić.
  Zatwierdzenie tutaj zapisuje w protokole, że zrobił to kierownik/pracownik D3, nie prowadzący.
</p>

<?php if (!$overdue): ?>
<div class="alert alert-success d-flex align-items-center gap-2" role="status">
  <i class="bi bi-check-circle-fill flex-shrink-0" aria-hidden="true"></i>
  <span>Brak zaległych protokołów — wszystkie za zakończone okresy są zatwierdzone.</span>
</div>
<?php else: ?>

<form method="post" class="mb-2" onsubmit="return confirm('Zatwierdzić wszystkie <?= count($overdue) ?> zaległe protokoły naraz? Tej operacji nie można cofnąć.')">
  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
  <input type="hidden" name="_op" value="approve_all">
  <button type="submit" class="btn btn-danger btn-sm">
    <i class="bi bi-check2-all me-1" aria-hidden="true"></i>Zatwierdź wszystkie zaległe (<?= count($overdue) ?>)
  </button>
</form>

<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead>
        <tr>
          <th>Kurs</th>
          <th>Prowadzący</th>
          <th>Okres</th>
          <th>Zaległość</th>
          <th class="text-end">Akcja</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($overdue as $r): ?>
        <tr>
          <td><a href="index.php?course=<?= (int)$r['course_id'] ?>&amp;tab=protokol"><?= h($r['course_name']) ?></a></td>
          <td><?= h($r['instructor_name'] ?: '—') ?></td>
          <td class="text-nowrap">
            <?= h($r['period_name']) ?>
            <span class="text-body-secondary small">(<?= date('d.m.Y', strtotime($r['date_from'])) ?>–<?= date('d.m.Y', strtotime($r['date_to'])) ?>)</span>
          </td>
          <td><span class="badge text-bg-warning"><?= (int)$r['days_overdue'] ?> dni</span></td>
          <td class="text-end">
            <form method="post" class="d-inline" onsubmit="return confirm('Zatwierdzić protokół „<?= h(addslashes($r['course_name'])) ?>”?')">
              <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
              <input type="hidden" name="_op" value="approve_one">
              <input type="hidden" name="protocol_id" value="<?= (int)$r['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-success">
                <i class="bi bi-check2 me-1" aria-hidden="true"></i>Zatwierdź
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

</div>
</main>

<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
