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

<div class="pv-wrap">

  <div class="pv-page-header">
    <div class="pv-page-head-main">
      <a href="procedures.php" class="pv-page-back">
        <i class="bi bi-arrow-left" aria-hidden="true"></i>Wszystkie procedury
      </a>
      <h1 class="pv-page-title">
        <i class="bi bi-journal-text" aria-hidden="true"></i><?= h($proc['title']) ?>
      </h1>
    </div>
  </div>

  <div class="tz-card">
    <div class="tz-card__bd">
      <div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
        <span class="tz-badge tz-badge--off">wersja <?= (int)$proc['version'] ?></span>
        <?php if (!empty($proc['category'])): ?>
        <span class="tz-badge tz-badge--off">
          <i class="bi bi-tag" aria-hidden="true"></i><?= h($proc['category']) ?>
        </span>
        <?php endif; ?>
        <?php if (!empty($proc['updated_at'])): ?>
        <span class="ms-auto small" style="color:var(--tz-muted)">
          <i class="bi bi-clock me-1" aria-hidden="true"></i>aktualizacja: <?= h(date_pl($proc['updated_at'])) ?>
        </span>
        <?php endif; ?>
      </div>

      <?php if (trim((string)($proc['content'] ?? '')) !== ''): ?>
      <div style="line-height:1.6;white-space:pre-wrap;word-break:break-word;color:var(--tz-ink)"><?= nl2br(h($proc['content'])) ?></div>
      <?php else: ?>
      <p class="mb-0" style="color:var(--tz-muted)">Brak treści — sprawdź załączniki poniżej.</p>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!empty($attachments)): ?>
  <div class="tz-card">
    <div class="tz-card__hd">
      <i class="bi bi-paperclip" aria-hidden="true"></i>Załączniki
    </div>
    <ul class="list-group list-group-flush">
      <?php foreach ($attachments as $a): ?>
      <li class="list-group-item d-flex align-items-center gap-2">
        <i class="bi <?= h(proc_file_icon($a['original_name'] ?? $a['filename'])) ?>"
           style="color:var(--tz)" aria-hidden="true"></i>
        <span class="flex-grow-1 text-truncate" style="color:var(--tz-ink)"><?= h($a['original_name'] ?? $a['filename']) ?></span>
        <span class="small" style="color:var(--tz-muted)"><?= h(proc_filesize_human((int)($a['filesize'] ?? 0))) ?></span>
        <a href="<?= APP_URL ?>/procedures/serve.php?id=<?= (int)$a['id'] ?>&download"
           class="tz-btn tz-btn--ghost ms-auto"
           aria-label="Pobierz <?= h($a['original_name'] ?? $a['filename']) ?>">
          <i class="bi bi-download" aria-hidden="true"></i>Pobierz
        </a>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

</div><!-- /.pv-wrap -->

<?php else: /* ── Lista procedur ───────────────────────────────────────── */ ?>

<div class="pv-wrap">

  <div class="pv-page-header">
    <div class="pv-page-head-main">
      <a href="panel.php" class="pv-page-back">
        <i class="bi bi-arrow-left" aria-hidden="true"></i>Panel
      </a>
      <h1 class="pv-page-title">
        <i class="bi bi-journal-text" aria-hidden="true"></i>Procedury
      </h1>
      <p class="pv-page-sub">Dokumenty i instrukcje organizacji</p>
    </div>
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
      <button type="submit" class="tz-btn w-100" aria-label="Szukaj">
        <i class="bi bi-search" aria-hidden="true"></i>
      </button>
    </div>
  </form>

  <?php if (empty($procs)): ?>
  <div class="tz-empty">
    <i class="bi bi-folder2-open" aria-hidden="true"></i>
    Brak procedur<?= $filter['q'] !== '' || $filter['category'] !== '' ? ' dla podanych kryteriów' : '' ?>.
  </div>
  <?php else: ?>
  <div class="list-group pv-list">
    <?php foreach ($procs as $p): ?>
    <a href="procedures.php?id=<?= (int)$p['id'] ?>"
       class="list-group-item list-group-item-action d-flex align-items-start gap-3">
      <i class="bi bi-file-earmark-text fs-5 flex-shrink-0 mt-1"
         style="color:var(--tz)" aria-hidden="true"></i>
      <div class="flex-grow-1 min-w-0">
        <div class="fw-semibold d-flex align-items-center gap-2 flex-wrap" style="color:var(--tz-ink)">
          <?= h($p['title']) ?>
          <span class="tz-badge tz-badge--off">v<?= (int)$p['version'] ?></span>
          <?php if (!empty($p['category'])): ?>
          <span class="tz-badge tz-badge--off">
            <i class="bi bi-tag" aria-hidden="true"></i><?= h($p['category']) ?>
          </span>
          <?php endif; ?>
        </div>
        <?php if (!empty($p['content'])): ?>
        <div class="small text-truncate" style="color:var(--tz-muted)"><?= h(mb_substr(strip_tags($p['content']), 0, 140, 'UTF-8')) ?></div>
        <?php endif; ?>
      </div>
      <i class="bi bi-chevron-right flex-shrink-0 mt-1"
         style="color:var(--tz-muted)" aria-hidden="true"></i>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div><!-- /.pv-wrap -->

<?php endif; /* lista / szczegóły */ ?>

<?php include __DIR__ . '/includes/footer_panel.php'; ?>
