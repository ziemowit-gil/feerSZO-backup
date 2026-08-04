<?php
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ksiegowosc.php';

require_login();
if (!is_admin()) { http_response_code(403); die('Brak uprawnień.'); }
kdok_migrate();

// Pobieranie protokołu PDF
$download_id = (int)($_GET['download'] ?? 0);
if ($download_id > 0) {
    $entry = kdok_one("SELECT * FROM kdok_deletion_log WHERE id = ?", [$download_id]);
    if (!$entry || !$entry['protocol_pdf_path']) {
        http_response_code(404); die('Protokół nie istnieje.');
    }
    $full_path = UPLOAD_DIR . $entry['protocol_pdf_path'];
    if (!is_file($full_path)) {
        http_response_code(404); die('Plik protokołu nie istnieje na dysku.');
    }
    $filename = 'protokol_usuniecia_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $entry['doc_number']) . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($full_path));
    readfile($full_path);
    exit;
}

// Lista wpisów
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$total    = (int)(kdok_one("SELECT COUNT(*) AS c FROM kdok_deletion_log")['c'] ?? 0);
$pag      = paginate($total, $per_page, $page, '?');
$entries  = kdok_all(
    "SELECT * FROM kdok_deletion_log ORDER BY id DESC LIMIT ? OFFSET ?",
    [$per_page, $pag['offset']]
);

$PAGE_TITLE = 'Rejestr usunięć dokumentów';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <a href="<?= APP_URL ?>/ksiegowosc/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h4 class="mb-0"><i class="bi bi-trash3-fill text-danger"></i> Rejestr usunięć dokumentów</h4>
  <span class="badge bg-secondary ms-1"><?= $total ?></span>
</div>

<?= flash_html() ?>

<?php if (!$entries): ?>
<div class="alert alert-info">Brak wpisów w rejestrze usunięć.</div>
<?php else: ?>
<div class="table-responsive">
  <table class="table table-sm table-hover align-middle">
    <thead class="table-light">
      <tr>
        <th>Nr dokumentu</th>
        <th>Typ</th>
        <th>Tytuł</th>
        <th>Status</th>
        <th>Kwota</th>
        <th>Usunął</th>
        <th>Data usunięcia</th>
        <th>Powód</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($entries as $row): ?>
    <tr>
      <td><code class="small"><?= h($row['doc_number']) ?></code></td>
      <td class="text-muted small"><?= h(KDOK_TYPES[$row['doc_type']]['label'] ?? $row['doc_type']) ?></td>
      <td class="small"><?= h($row['doc_title']) ?></td>
      <td><?= kdok_status_badge($row['doc_status']) ?></td>
      <td class="small text-nowrap"><?= $row['doc_kwota'] ? h($row['doc_kwota']) . ' PLN' : '—' ?></td>
      <td class="small"><?= h($row['deleted_by_name']) ?></td>
      <td class="small text-nowrap"><?= date('d.m.Y H:i', strtotime($row['deleted_at'])) ?></td>
      <td class="small" style="max-width:200px">
        <span title="<?= h($row['reason']) ?>"><?= h(mb_strimwidth($row['reason'], 0, 60, '…')) ?></span>
      </td>
      <td class="text-end text-nowrap">
        <?php if ($row['protocol_pdf_path']): ?>
        <a href="?download=<?= $row['id'] ?>" class="btn btn-sm btn-outline-danger" title="Pobierz protokół PDF">
          <i class="bi bi-file-earmark-pdf"></i> Protokół
        </a>
        <?php else: ?>
        <span class="text-muted small">brak pliku</span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?= pagination_html($pag) ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
