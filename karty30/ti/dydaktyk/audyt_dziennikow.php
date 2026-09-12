<?php
/**
 * karty30/ti/dydaktyk/audyt_dziennikow.php — Audyt dzienników (widok kierownika).
 *
 * Zbiorczy wgląd w braki po prowadzących: niekompletna dokumentacja lekcji
 * i kursanci bez oceny w e-dzienniku (patrz includes/ti_audit.php). Kierownik
 * może wysłać e-mailem przypomnienie do jednego prowadzącego albo do
 * wszystkich, którzy mają jakiekolwiek braki.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_audit.php';

$me      = dyd_require();
if (!dyd_is_staff()) { header('Location: index.php'); exit; }
$me_name = (string)($me['name'] ?? '');
karty30_migrate();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op = $_POST['_op'] ?? '';
    $gaps = ti_audit_gaps_by_instructor();
    $instructors = [];
    foreach (k30_ti_instructors() as $ins) { $instructors[(int)$ins['id']] = $ins; }

    if ($op === 'notify_one') {
        $iid = (int)($_POST['instructor_id'] ?? 0);
        if (isset($instructors[$iid]) && isset($gaps[$iid])) {
            $ok = ti_audit_notify_instructor($instructors[$iid], $gaps[$iid]);
            flash_set($ok ? 'success' : 'danger', $ok
                ? 'Przypomnienie wysłane do ' . $instructors[$iid]['name'] . '.'
                : 'Nie udało się wysłać (brak adresu e-mail?).');
        }
        header('Location: audyt_dziennikow.php'); exit;
    }

    if ($op === 'notify_all') {
        $sent = 0; $skip = 0;
        foreach ($gaps as $iid => $gap) {
            if (!isset($instructors[$iid])) { $skip++; continue; }
            if (ti_audit_notify_instructor($instructors[$iid], $gap)) $sent++; else $skip++;
        }
        flash_set($sent ? 'success' : 'warning',
            $sent ? "Wysłano przypomnień: {$sent}." . ($skip ? " Pominięto: {$skip}." : '')
                  : 'Nie wysłano żadnego przypomnienia.');
        header('Location: audyt_dziennikow.php'); exit;
    }
}

$gaps = ti_audit_gaps_by_instructor();
$instructors = [];
foreach (k30_ti_instructors() as $ins) { $instructors[(int)$ins['id']] = $ins; }

// Tylko prowadzący, których znamy z imienia (istniejące konto) i mają jakikolwiek brak.
$rows = [];
foreach ($gaps as $iid => $gap) {
    if (!isset($instructors[$iid])) continue;
    $rows[] = ['instructor' => $instructors[$iid], 'gap' => $gap];
}
usort($rows, fn($a, $b) => strcasecmp($a['instructor']['name'], $b['instructor']['name']));

$KP_TITLE  = 'Audyt dzienników — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => $me_name, 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">

<?php $KIER_CUR = 'audyt_dziennikow.php'; $KIER_LABEL = 'Audyt dzienników';
   include __DIR__ . '/_kierownik_bar.php'; ?>

<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1000px">
<?= flash_html() ?>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h1 class="h4 mb-0 fw-bold"><i class="bi bi-clipboard2-check text-primary me-2" aria-hidden="true"></i>Audyt dzienników</h1>
  <span class="badge bg-secondary"><?= count($rows) ?></span>
</div>

<p class="text-body-secondary small mb-3">
  Prowadzący z niekompletną dokumentacją odbytych lekcji albo kursantami bez żadnej oceny w e-dzienniku.
  Wysłanie przypomnienia nie zmienia niczego w danych — to tylko e-mail do prowadzącego.
</p>

<?php if (!$rows): ?>
<div class="alert alert-success d-flex align-items-center gap-2" role="status">
  <i class="bi bi-check-circle-fill flex-shrink-0" aria-hidden="true"></i>
  <span>Brak braków — dokumentacja i oceny są na bieżąco u wszystkich prowadzących.</span>
</div>
<?php else: ?>

<form method="post" class="mb-2" onsubmit="return confirm('Wysłać przypomnienie e-mailem do wszystkich <?= count($rows) ?> prowadzących z brakami?')">
  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
  <input type="hidden" name="_op" value="notify_all">
  <button type="submit" class="btn btn-primary btn-sm">
    <i class="bi bi-envelope-fill me-1" aria-hidden="true"></i>Wyślij przypomnienia do wszystkich (<?= count($rows) ?>)
  </button>
</form>

<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead>
        <tr>
          <th>Prowadzący</th>
          <th>Niekompletna dokumentacja</th>
          <th>Brak ocen</th>
          <th class="text-end">Akcja</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): $ins = $r['instructor']; $gap = $r['gap']; ?>
        <tr>
          <td>
            <div class="fw-semibold"><?= h($ins['name']) ?></div>
            <div class="text-body-secondary small"><?= h($ins['email'] ?: '—') ?></div>
          </td>
          <td>
            <?php if (!empty($gap['docs_total'])): ?>
            <span class="badge text-bg-warning mb-1"><?= (int)$gap['docs_total'] ?> <?= (int)$gap['docs_total'] === 1 ? 'lekcja' : 'lekcje/lekcji' ?></span>
            <div class="small text-body-secondary">
              <?= implode(', ', array_map(fn($d) => h($d['course_name']) . ' (' . (int)$d['n'] . ')', $gap['docs'] ?? [])) ?>
            </div>
            <?php else: ?>
            <span class="text-body-secondary">—</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if (!empty($gap['grades_total'])): ?>
            <span class="badge text-bg-danger mb-1"><?= (int)$gap['grades_total'] ?> os.</span>
            <div class="small text-body-secondary">
              <?= implode(', ', array_map(fn($g) => h($g['course_name']) . ' (' . (int)$g['n'] . ')', $gap['grades'] ?? [])) ?>
            </div>
            <?php else: ?>
            <span class="text-body-secondary">—</span>
            <?php endif; ?>
          </td>
          <td class="text-end">
            <form method="post">
              <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
              <input type="hidden" name="_op" value="notify_one">
              <input type="hidden" name="instructor_id" value="<?= (int)$ins['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-primary" <?= empty($ins['email']) ? 'disabled title="Brak adresu e-mail"' : '' ?>>
                <i class="bi bi-envelope me-1" aria-hidden="true"></i>Wyślij przypomnienie
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
