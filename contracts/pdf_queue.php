<?php
/**
 * contracts/pdf_queue.php — Lista umów do wydruku jako PDF.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once __DIR__ . '/includes/pdf_queue.php';

require_login();
$PAGE_TITLE = 'Pliki do wydruku (PDF)';

pdf_queue_ensure_table();
$uid = (int)current_user()['id'];

// Oznacz jako wydrukowane
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_done'])) {
    csrf_check();
    $qid = (int)$_POST['mark_done'];
    db()->prepare("UPDATE pdf_do_druku SET done_at = ? WHERE id = ? AND user_id = ?")
        ->execute([date('Y-m-d H:i:s'), $qid, $uid]);
    flash_set('success', 'Oznaczono jako wydrukowane.');
    header('Location: ' . APP_URL . '/contracts/pdf_queue.php');
    exit;
}

// Oznacz wszystkie
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_done'])) {
    csrf_check();
    db()->prepare("UPDATE pdf_do_druku SET done_at = ? WHERE user_id = ? AND done_at IS NULL")
        ->execute([date('Y-m-d H:i:s'), $uid]);
    flash_set('success', 'Wszystkie oznaczono jako wydrukowane.');
    header('Location: ' . APP_URL . '/contracts/pdf_queue.php');
    exit;
}

$items = db()->prepare(
    "SELECT * FROM pdf_do_druku WHERE user_id = ? AND done_at IS NULL ORDER BY created_at DESC"
);
$items->execute([$uid]);
$items = $items->fetchAll();

$type_labels = [
    'wolontariat'        => 'Porozumienie wolontariackie',
    'zlecenie'           => 'Umowa zlecenie',
    'dzielo'             => 'Umowa o dzieło',
    'uslugi'             => 'Umowa o usługi',
    'praca'              => 'Umowa o pracę',
    'inne'               => 'Inna umowa',
    'migracja_webngo'    => 'Migracja webNGO',
    'rodo_authorization'  => 'Upoważnienie RODO',
    'rodo_revocation'     => 'Odwołanie upoważnienia RODO',
    'certificate'         => 'Zaświadczenie',
    'letter'              => 'Pismo/korespondencja',
    'wolontariat_wkladka' => 'Karta do segregatora (wolontariat)',
    'wolontariat_confirm' => 'Potwierdzenie dla wolontariusza',
    'wolontariat_aneks'   => 'Aneks do porozumienia wolontariackiego',
];

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">
    <i class="bi bi-printer text-warning"></i> Pliki do wydruku
    <?php if ($items): ?>
    <span class="badge bg-warning text-dark ms-1"><?= count($items) ?></span>
    <?php endif; ?>
  </h4>
  <?php if ($items): ?>
  <form method="post">
    <?= csrf_field() ?>
    <button name="mark_all_done" value="1" class="btn btn-sm btn-outline-success"
            onclick="return confirm('Oznaczyć wszystkie jako wydrukowane?')">
      <i class="bi bi-check2-all"></i> Wszystkie wydrukowane
    </button>
  </form>
  <?php endif; ?>
</div>

<?php if (!$items): ?>
<div class="alert alert-success">
  <i class="bi bi-check-circle-fill me-2"></i>
  Brak oczekujących plików do wydruku. Wszystko gotowe!
</div>
<?php else: ?>
<div class="alert alert-warning d-flex gap-2 align-items-start py-2 mb-3">
  <i class="bi bi-printer-fill fs-5 flex-shrink-0 mt-1"></i>
  <div>
    <strong>Masz <?= count($items) ?> <?= count($items) === 1 ? 'plik' : (count($items) < 5 ? 'pliki' : 'plików') ?> do wydruku.</strong>
    Kliknij <em>Drukuj PDF</em>, a w oknie drukowania wybierz <strong>„Zapisz jako PDF"</strong> zamiast drukarki.
    Gdy skończysz, kliknij <em>Wydrukowane</em>.
  </div>
</div>

<div class="table-responsive">
<table class="table table-bordered table-hover align-middle">
  <thead class="table-light">
    <tr>
      <th>Typ umowy</th>
      <th>Numer</th>
      <th>Osoba / Strona</th>
      <th>Dodano</th>
      <th class="text-center">Akcje</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($items as $it):
    $type_label = $type_labels[$it['contract_type']] ?? $it['contract_type'];
    $cid_safe = (int)$it['contract_id'];
    if ($it['contract_type'] === 'migracja_webngo') {
        $print_url = APP_URL . '/contracts/migracja_webngo/print.php?id=' . $cid_safe;
    } elseif ($it['contract_type'] === 'rodo_authorization') {
        $print_url = APP_URL . '/rodo/print.php?id=' . $cid_safe;
    } elseif ($it['contract_type'] === 'rodo_revocation') {
        $print_url = APP_URL . '/rodo/print_revoke.php?id=' . $cid_safe;
    } elseif ($it['contract_type'] === 'certificate') {
        $print_url = APP_URL . '/certificates/print.php?id=' . $cid_safe;
    } elseif ($it['contract_type'] === 'letter') {
        $print_url = APP_URL . '/contracts/letters/view.php?id=' . $cid_safe;
    } elseif ($it['contract_type'] === 'wolontariat_wkladka') {
        $print_url = APP_URL . '/contracts/wolontariat/potwierdzenie.php?id=' . $cid_safe . '&typ=wkladka&preview=1';
    } elseif ($it['contract_type'] === 'wolontariat_confirm') {
        $print_url = APP_URL . '/contracts/wolontariat/potwierdzenie.php?id=' . $cid_safe . '&typ=wolontariusz&preview=1';
    } elseif ($it['contract_type'] === 'wolontariat_aneks') {
        $print_url = APP_URL . '/contracts/wolontariat/aneks.php?id=' . $cid_safe . '&format=pdf&preview=1';
    } else {
        $print_url = APP_URL . '/contracts/print.php?type=' . urlencode($it['contract_type']) . '&id=' . $cid_safe;
    }
  ?>
  <tr>
    <td><span class="badge bg-secondary"><?= h($type_label) ?></span></td>
    <td class="fw-semibold font-monospace"><?= h($it['numer_umowy']) ?></td>
    <td><?= h($it['osoba']) ?></td>
    <td class="text-muted small"><?= h(substr($it['created_at'], 0, 16)) ?></td>
    <td class="text-center">
      <div class="d-flex gap-1 justify-content-center">
        <a href="<?= h($print_url) ?>" target="_blank"
           class="btn btn-sm btn-outline-danger"
           title="Otwórz i zapisz jako PDF (Ctrl+P → Zapisz jako PDF)">
          <i class="bi bi-file-earmark-pdf"></i> Drukuj PDF
        </a>
        <form method="post" class="d-inline">
          <?= csrf_field() ?>
          <button name="mark_done" value="<?= (int)$it['id'] ?>"
                  class="btn btn-sm btn-outline-success"
                  title="Oznacz jako wydrukowane">
            <i class="bi bi-check-lg"></i> Wydrukowane
          </button>
        </form>
      </div>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
