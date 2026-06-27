<?php
/**
 * panel/procedures.php — Procedury (tryb tylko-do-odczytu) w panelu wolontariusza.
 * Personel korzysta z pełnego modułu /procedures/. Wolontariusz widzi listę
 * opublikowanych procedur i ich treść w spójnej powłoce panelu.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/procedures.php';

require_login();
require_module_enabled('procedures_enabled', 'Moduł procedur');

$user = current_user();
$_is_volunteer_only = is_viewer()
    && !db_one("SELECT id FROM users WHERE id=? AND k30_consultant=1", [(int)$user['id']]);

// Personel → pełny moduł procedur (z edycją)
if (!$_is_volunteer_only) {
    $to = isset($_GET['id'])
        ? '/procedures/view.php?id=' . (int)$_GET['id']
        : '/procedures/index.php';
    header('Location: ' . APP_URL . $to);
    exit;
}

$pid = (int)($_GET['id'] ?? 0);

if ($pid) {
    $proc = proc_get($pid);
    if (!$proc || $proc['status'] === 'deleted') {
        flash_set('danger', 'Procedura nie istnieje lub została usunięta.');
        header('Location: procedures.php'); exit;
    }
    $attachments = proc_get_attachments($pid);
    $PAGE_TITLE  = $proc['title'] . ' — Procedury';
} else {
    $filter = [
        'q'        => trim($_GET['q'] ?? ''),
        'category' => trim($_GET['category'] ?? ''),
        'status'   => 'active',   // wolontariusz widzi tylko opublikowane
    ];
    $procs      = proc_get_all($filter);
    $categories = proc_get_categories();
    $PAGE_TITLE = 'Procedury — Panel wolontariusza';
}

include __DIR__ . '/includes/header_panel.php';
?>

<?php if ($pid): /* ── Szczegóły procedury ──────────────────────────────── */ ?>

<div class="pv-page-header d-flex gap-2 flex-wrap align-items-center">
  <h1 class="pv-page-title"><i class="bi bi-journal-text me-2" aria-hidden="true"></i><?= h($proc['title']) ?></h1>
  <a href="procedures.php" class="btn btn-outline-secondary btn-sm ms-auto">
    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Wszystkie procedury
  </a>
</div>

<div class="vol-detail-card mb-3">
  <div class="vol-detail-body">
    <div class="d-flex flex-wrap gap-2 mb-3 small text-muted align-items-center">
      <span class="badge bg-light text-dark border">wersja <?= (int)$proc['version'] ?></span>
      <?php if (!empty($proc['category'])): ?>
      <span class="badge bg-light text-dark border"><i class="bi bi-tag me-1" aria-hidden="true"></i><?= h($proc['category']) ?></span>
      <?php endif; ?>
      <?php if (!empty($proc['updated_at'])): ?>
      <span class="ms-auto"><i class="bi bi-clock me-1" aria-hidden="true"></i>aktualizacja: <?= h(date_pl($proc['updated_at'])) ?></span>
      <?php endif; ?>
    </div>

    <?php if (trim((string)($proc['content'] ?? '')) !== ''): ?>
    <div style="line-height:1.6;white-space:pre-wrap;word-break:break-word"><?= nl2br(h($proc['content'])) ?></div>
    <?php else: ?>
    <p class="text-muted mb-0">Brak treści — sprawdź załączniki poniżej.</p>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($attachments)): ?>
<div class="vol-detail-card mb-3">
  <div class="vol-detail-header"><i class="bi bi-paperclip me-2" aria-hidden="true"></i>Załączniki</div>
  <div class="vol-detail-body p-0">
    <ul class="list-group list-group-flush">
      <?php foreach ($attachments as $a): ?>
      <li class="list-group-item d-flex align-items-center gap-2">
        <i class="bi <?= h(proc_file_icon($a['original_name'] ?? $a['filename'])) ?> text-primary" aria-hidden="true"></i>
        <span class="text-truncate"><?= h($a['original_name'] ?? $a['filename']) ?></span>
        <span class="text-muted small ms-2"><?= h(proc_filesize_human((int)($a['filesize'] ?? 0))) ?></span>
        <a href="<?= APP_URL ?>/procedures/serve.php?id=<?= (int)$a['id'] ?>&download" class="btn btn-outline-primary btn-sm ms-auto">
          <i class="bi bi-download me-1" aria-hidden="true"></i>Pobierz
        </a>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>
<?php endif; ?>

<?php else: /* ── Lista procedur ───────────────────────────────────────── */ ?>

<div class="pv-page-header d-flex gap-2 flex-wrap">
  <h1 class="pv-page-title"><i class="bi bi-journal-text me-2" aria-hidden="true"></i>Procedury</h1>
  <p class="pv-page-sub">Dokumenty i instrukcje organizacji</p>
</div>

<form method="get" class="row g-2 mb-3" role="search" aria-label="Wyszukiwanie procedur">
  <div class="col-12 col-sm">
    <input type="search" name="q" value="<?= h($filter['q']) ?>" class="form-control"
           placeholder="Szukaj procedury…" aria-label="Szukaj procedury">
  </div>
  <?php if (!empty($categories)): ?>
  <div class="col-8 col-sm-auto">
    <select name="category" class="form-select" aria-label="Kategoria">
      <option value="">Wszystkie kategorie</option>
      <?php foreach ($categories as $c): $cn = is_array($c) ? ($c['category'] ?? '') : $c; if ($cn==='') continue; ?>
      <option value="<?= h($cn) ?>" <?= $filter['category']===$cn?'selected':'' ?>><?= h($cn) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>
  <div class="col-4 col-sm-auto">
    <button class="btn btn-primary w-100"><i class="bi bi-search" aria-hidden="true"></i></button>
  </div>
</form>

<?php if (empty($procs)): ?>
<div class="vol-detail-card"><div class="vol-detail-body text-center text-muted py-4">
  <i class="bi bi-folder2-open d-block mb-2" style="font-size:1.8rem" aria-hidden="true"></i>
  Brak procedur<?= $filter['q'] !== '' || $filter['category'] !== '' ? ' dla podanych kryteriów' : '' ?>.
</div></div>
<?php else: ?>
<div class="list-group">
  <?php foreach ($procs as $p): ?>
  <a href="procedures.php?id=<?= (int)$p['id'] ?>" class="list-group-item list-group-item-action d-flex align-items-start gap-3">
    <i class="bi bi-file-earmark-text fs-5 text-primary flex-shrink-0 mt-1" aria-hidden="true"></i>
    <div class="flex-grow-1 min-w-0">
      <div class="fw-semibold d-flex align-items-center gap-2 flex-wrap">
        <?= h($p['title']) ?>
        <span class="badge bg-light text-dark border" style="font-size:.65rem">v<?= (int)$p['version'] ?></span>
        <?php if (!empty($p['category'])): ?><span class="badge bg-light text-dark border" style="font-size:.65rem"><i class="bi bi-tag me-1" aria-hidden="true"></i><?= h($p['category']) ?></span><?php endif; ?>
      </div>
      <?php if (!empty($p['content'])): ?>
      <div class="small text-muted text-truncate"><?= h(mb_substr(strip_tags($p['content']), 0, 140, 'UTF-8')) ?></div>
      <?php endif; ?>
    </div>
    <i class="bi bi-chevron-right text-muted flex-shrink-0 mt-1" aria-hidden="true"></i>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php endif; /* lista / szczegóły */ ?>

<?php include __DIR__ . '/includes/footer_panel.php'; ?>
