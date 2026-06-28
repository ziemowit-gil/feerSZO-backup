<?php
/**
 * panel/org_documents.php — „Dokumenty organizacji" w panelu wolontariusza (odczyt).
 * Personel zarządza w /admin/org_documents.php; wolontariusz przegląda i pobiera.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/org_docs.php';

require_login();
require_module_enabled('org_documents_enabled', 'Moduł dokumentów organizacji');

$user = current_user();
$_is_volunteer_only = is_viewer()
    && !db_one("SELECT id FROM users WHERE id=? AND k30_consultant=1", [(int)$user['id']]);

// Personel → panel zarządzania
if (!$_is_volunteer_only) { header('Location: ' . APP_URL . '/admin/org_documents.php'); exit; }

$filter = [
    'q'           => trim($_GET['q'] ?? ''),
    'category'    => trim($_GET['category'] ?? ''),
    'active_only' => true,                 // wolontariusz widzi tylko widoczne
    'user_id'     => (int)($user['id'] ?? 0), // + filtr widoczności wg jednostek
];
$docs       = org_docs_all($filter);
$categories = org_docs_categories();
$PAGE_TITLE = 'Dokumenty organizacji — Panel wolontariusza';

include __DIR__ . '/includes/header_panel.php';
?>

<div class="pv-page-header d-flex gap-2 flex-wrap">
  <h1 class="pv-page-title"><i class="bi bi-folder2-open me-2" aria-hidden="true"></i>Dokumenty organizacji</h1>
  <p class="pv-page-sub">Statut, regulaminy, formularze i wzory do pobrania</p>
</div>

<form method="get" class="row g-2 mb-3" role="search" aria-label="Wyszukiwanie dokumentów">
  <div class="col-12 col-sm">
    <input type="search" name="q" value="<?= h($filter['q']) ?>" class="form-control" placeholder="Szukaj dokumentu…" aria-label="Szukaj dokumentu">
  </div>
  <?php if (!empty($categories)): ?>
  <div class="col-8 col-sm-auto">
    <select name="category" class="form-select" aria-label="Kategoria">
      <option value="">Wszystkie kategorie</option>
      <?php foreach ($categories as $c): ?><option value="<?= h($c) ?>" <?= $filter['category']===$c?'selected':'' ?>><?= h($c) ?></option><?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>
  <div class="col-4 col-sm-auto"><button class="btn btn-primary w-100"><i class="bi bi-search" aria-hidden="true"></i></button></div>
</form>

<?php if (empty($docs)): ?>
<div class="vol-detail-card"><div class="vol-detail-body text-center text-muted py-4">
  <i class="bi bi-folder2-open d-block mb-2" style="font-size:1.8rem" aria-hidden="true"></i>
  Brak dokumentów<?= $filter['q'] !== '' || $filter['category'] !== '' ? ' dla podanych kryteriów' : '' ?>.
</div></div>
<?php else: ?>
<div class="list-group">
  <?php foreach ($docs as $d): ?>
  <div class="list-group-item d-flex align-items-start gap-3">
    <i class="bi <?= h(org_docs_file_icon($d['original_name'])) ?> fs-4 text-primary flex-shrink-0 mt-1" aria-hidden="true"></i>
    <div class="flex-grow-1 min-w-0">
      <div class="fw-semibold d-flex align-items-center gap-2 flex-wrap">
        <?= h($d['title']) ?>
        <span class="badge bg-light text-dark border" style="font-size:.65rem">v<?= h($d['version'] ?? '1') ?></span>
        <?php if (!empty($d['category'])): ?><span class="badge bg-light text-dark border" style="font-size:.65rem"><i class="bi bi-tag me-1" aria-hidden="true"></i><?= h($d['category']) ?></span><?php endif; ?>
        <?php if (($d['visibility'] ?? 'all') === 'unit'): ?><span class="badge bg-info-subtle text-info-emphasis border border-info-subtle" style="font-size:.65rem"><i class="bi bi-diagram-3 me-1" aria-hidden="true"></i><?= h($d['unit_name'] ?: 'Twoja jednostka') ?></span><?php endif; ?>
      </div>
      <?php if (!empty($d['description'])): ?><div class="small text-muted"><?= h($d['description']) ?></div><?php endif; ?>
      <div class="small text-muted">
        <?= h($d['original_name']) ?> · <?= h(org_docs_filesize_human((int)$d['file_size'])) ?>
        <?php if (!empty($d['owner_name'])): ?> · <i class="bi bi-person me-1" aria-hidden="true"></i>Lider: <?= h($d['owner_name']) ?><?php endif; ?>
      </div>
    </div>
    <a href="<?= APP_URL ?>/org_documents/serve.php?id=<?= (int)$d['id'] ?>&download" class="btn btn-outline-primary btn-sm flex-shrink-0 mt-1" aria-label="Pobierz: <?= h($d['title']) ?>">
      <i class="bi bi-download me-1" aria-hidden="true"></i>Pobierz
    </a>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer_panel.php'; ?>
