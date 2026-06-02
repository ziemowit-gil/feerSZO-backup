<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/procedures.php';

require_login();
require_module_enabled('procedures_enabled', 'Moduł procedur');

$id   = (int)($_GET['id']  ?? 0);
$proc = proc_get($id);
if (!$proc || $proc['status'] === 'deleted') {
    flash_set('error', 'Procedura nie istnieje.');
    header('Location: ' . APP_URL . '/procedures/index.php'); exit;
}

// Wersja do wyświetlenia
$view_version = isset($_GET['v']) ? (int)$_GET['v'] : $proc['version'];
$is_current   = $view_version === (int)$proc['version'];

if (!$is_current) {
    $snapshot = proc_get_version($id, $view_version);
    if (!$snapshot) {
        flash_set('error', 'Nie znaleziono tej wersji.');
        header('Location: ' . APP_URL . '/procedures/view.php?id=' . $id); exit;
    }
    $display_title   = $snapshot['title'];
    $display_content = $snapshot['content'];
    $display_cat     = $snapshot['category'];
} else {
    $display_title   = $proc['title'];
    $display_content = $proc['content'];
    $display_cat     = $proc['category'];
}

$versions    = proc_get_versions($id);
$related     = proc_get_related($id);
$attachments = proc_get_attachments($id);

$PAGE_TITLE = $display_title;

// Obsługa uploadu załącznika (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_upload'])) {
    csrf_check();
    if (!can_edit()) { http_response_code(403); die('Brak uprawnień.'); }
    $err = proc_upload_attachment($id, 'attachment', (int)current_user()['id']);
    if ($err) {
        flash_set('error', $err);
    } else {
        flash_set('success', 'Załącznik dodany.');
    }
    header('Location: ' . APP_URL . '/procedures/view.php?id=' . $id . '#attachments'); exit;
}

// Usuwanie załącznika
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_del_attachment'])) {
    csrf_check();
    if (!can_edit()) { http_response_code(403); die('Brak uprawnień.'); }
    proc_delete_attachment((int)($_POST['att_id'] ?? 0));
    flash_set('success', 'Załącznik usunięty.');
    header('Location: ' . APP_URL . '/procedures/view.php?id=' . $id . '#attachments'); exit;
}

// Archiwizacja / przywrócenie
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_status'])) {
    csrf_check();
    if (!is_admin()) { http_response_code(403); die('Brak uprawnień.'); }
    $action = $_POST['_status'];
    if ($action === 'archive') proc_archive($id, (int)current_user()['id']);
    elseif ($action === 'restore') proc_restore($id, (int)current_user()['id']);
    elseif ($action === 'delete') {
        proc_delete($id, (int)current_user()['id']);
        flash_set('success', 'Procedura przeniesiona do kosza.');
        header('Location: ' . APP_URL . '/procedures/index.php'); exit;
    }
    flash_set('success', 'Status zaktualizowany.');
    header('Location: ' . APP_URL . '/procedures/view.php?id=' . $id); exit;
}

include dirname(__DIR__) . '/includes/header.php';
?>

<style>
/* ── Bento sidebar ─────────────────────────── */
.bento-card {
    background:#fff;
    border:1.5px solid #e8edf3;
    border-radius:12px;
    overflow:hidden;
    margin-bottom:1rem;
}
.bento-card-header {
    padding:.6rem 1rem;
    font-size:.72rem;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.08em;
    color:#94a3b8;
    border-bottom:1px solid #f1f5f9;
    background:#fafbfc;
    display:flex;
    align-items:center;
    gap:.4rem;
}
.bento-card-body { padding:.85rem 1rem; }

/* ── Wersje timeline ───────────────────────── */
.ver-item {
    display:flex; gap:.65rem;
    padding:.45rem 0;
    border-bottom:1px solid #f8fafc;
    align-items:flex-start;
}
.ver-item:last-child { border-bottom:none; }
.ver-badge {
    width:26px; height:26px;
    border-radius:6px;
    background:#eff6ff;
    color:#2563eb;
    font-size:.68rem;
    font-weight:700;
    display:flex; align-items:center; justify-content:center;
    flex-shrink:0;
}
.ver-badge.current { background:#2563eb; color:#fff; }

/* ── Content render ────────────────────────── */
.proc-content {
    font-size:.9rem;
    line-height:1.75;
    color:#1e293b;
}
.proc-content h1,.proc-content h2,.proc-content h3 {
    margin-top:1.5rem; margin-bottom:.5rem;
    font-weight:700; color:#0f172a;
}
.proc-content h1 { font-size:1.25rem; }
.proc-content h2 { font-size:1.1rem; }
.proc-content h3 { font-size:.95rem; }
.proc-content ul,.proc-content ol { padding-left:1.5rem; }
.proc-content li { margin-bottom:.25rem; }
.proc-content code {
    background:#f1f5f9; border-radius:4px;
    padding:.1em .35em; font-size:.85em; color:#be185d;
}
.proc-content pre {
    background:#1e293b; color:#e2e8f0;
    border-radius:8px; padding:1rem;
    overflow-x:auto; font-size:.82rem;
}
.proc-content pre code { background:none; color:inherit; padding:0; }
.proc-content blockquote {
    border-left:3px solid #2563eb;
    padding:.5rem 1rem; margin:0;
    background:#eff6ff; border-radius:0 6px 6px 0;
    color:#1e3a8a;
}
.proc-content table {
    width:100%; border-collapse:collapse; margin:1rem 0;
}
.proc-content th,.proc-content td {
    border:1px solid #e2e8f0; padding:.45rem .75rem; font-size:.85rem;
}
.proc-content th { background:#f8fafc; font-weight:600; }
.proc-content hr { border-color:#e2e8f0; margin:1.5rem 0; }
.proc-content a { color:#2563eb; }
.proc-content img { max-width:100%; border-radius:6px; }

/* ── Attachment row ────────────────────────── */
.att-row {
    display:flex; align-items:center; gap:.6rem;
    padding:.4rem 0;
    border-bottom:1px solid #f8fafc;
    font-size:.8rem;
}
.att-row:last-child { border-bottom:none; }

/* ── Version banner ────────────────────────── */
.ver-banner {
    background:#fef3c7;
    border:1px solid #fcd34d;
    border-radius:8px;
    padding:.55rem 1rem;
    font-size:.82rem;
    margin-bottom:1rem;
    display:flex; align-items:center; gap:.6rem;
}

/* ── Related proc link ─────────────────────── */
.related-link {
    display:flex; align-items:center; gap:.5rem;
    padding:.35rem 0;
    border-bottom:1px solid #f8fafc;
    text-decoration:none;
    color:#1e293b;
    font-size:.82rem;
    transition:color .12s;
}
.related-link:last-child { border-bottom:none; }
.related-link:hover { color:#2563eb; }
</style>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb" style="font-size:.8rem">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/procedures/index.php">Procedury</a></li>
    <?php if ($display_cat): ?>
    <li class="breadcrumb-item">
      <a href="<?= APP_URL ?>/procedures/index.php?category=<?= urlencode($display_cat) ?>">
        <?= h($display_cat) ?>
      </a>
    </li>
    <?php endif; ?>
    <li class="breadcrumb-item active"><?= h($display_title) ?></li>
  </ol>
</nav>

<?= flash_html() ?>

<?php if (!$is_current): ?>
<div class="ver-banner">
  <i class="bi bi-clock-history text-warning"></i>
  <span>Oglądasz <strong>wersję <?= $view_version ?></strong> z <?= count($versions) > 0 ? date_pl($versions[array_search($view_version, array_column($versions, 'version'))]['created_at'] ?? '') : '' ?>.
  <a href="<?= APP_URL ?>/procedures/view.php?id=<?= $id ?>">Wróć do bieżącej (v<?= $proc['version'] ?>)</a></span>
</div>
<?php endif; ?>

<div class="row g-4">
  <!-- ── Lewa: treść ─────────────────────────────── -->
  <div class="col-lg-8">
    <div class="card shadow-sm">
      <div class="card-body p-4">
        <!-- Nagłówek -->
        <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
          <div>
            <h2 class="fw-bold mb-1" style="font-size:1.35rem;color:#0f172a">
              <?= h($display_title) ?>
            </h2>
            <div class="d-flex flex-wrap gap-2 align-items-center">
              <?php if ($display_cat): ?>
              <span class="badge" style="background:#eff6ff;color:#1d4ed8;font-size:.73rem">
                <i class="bi bi-tag me-1"></i><?= h($display_cat) ?>
              </span>
              <?php endif; ?>
              <span class="badge bg-<?= $proc['status'] === 'active' ? 'success' : 'secondary' ?> opacity-75">
                <?= $proc['status'] === 'active' ? 'Aktywna' : 'Archiwum' ?>
              </span>
              <span style="font-size:.72rem;color:#94a3b8">
                wersja <?= $view_version ?>
                <?= $is_current ? '<span class="text-success">· bieżąca</span>' : '' ?>
              </span>
            </div>
          </div>
          <?php if (can_edit() && $is_current): ?>
          <div class="d-flex gap-2 flex-shrink-0">
            <a href="<?= APP_URL ?>/procedures/edit.php?id=<?= $id ?>"
               class="btn btn-sm btn-outline-primary">
              <i class="bi bi-pencil me-1"></i>Edytuj
            </a>
          </div>
          <?php endif; ?>
        </div>

        <hr class="my-3">

        <!-- Treść Markdown -->
        <div class="proc-content" id="proc-content-render">
          <?= h($display_content) ?>
        </div>
      </div>
    </div>

    <!-- Załączniki -->
    <div class="card shadow-sm mt-3" id="attachments">
      <div class="card-header d-flex align-items-center justify-content-between">
        <span class="fw-semibold" style="font-size:.88rem">
          <i class="bi bi-paperclip text-primary me-1"></i>Załączniki
          <?php if ($attachments): ?>
          <span class="badge bg-primary ms-1"><?= count($attachments) ?></span>
          <?php endif; ?>
        </span>
      </div>
      <div class="card-body p-3">
        <?php if ($attachments): ?>
        <?php foreach ($attachments as $att): ?>
        <div class="att-row">
          <i class="bi <?= proc_file_icon($att['original_name']) ?> fs-5 flex-shrink-0"></i>
          <div class="flex-grow-1 overflow-hidden">
            <a href="<?= APP_URL ?>/procedures/serve.php?id=<?= $att['id'] ?>"
               class="fw-semibold text-truncate d-block" style="color:#1e293b;font-size:.82rem"
               target="_blank">
              <?= h($att['original_name']) ?>
            </a>
            <div style="font-size:.7rem;color:#94a3b8">
              <?= proc_filesize_human((int)$att['file_size']) ?>
              <?php if ($att['uploader_name']): ?>
              &nbsp;·&nbsp;<?= h($att['uploader_name']) ?>
              <?php endif; ?>
              &nbsp;·&nbsp;<?= date_pl($att['uploaded_at']) ?>
            </div>
          </div>
          <a href="<?= APP_URL ?>/procedures/serve.php?id=<?= $att['id'] ?>&download=1"
             class="btn btn-xs btn-outline-secondary btn-sm" title="Pobierz">
            <i class="bi bi-download"></i>
          </a>
          <?php if (can_edit()): ?>
          <form method="post" class="d-inline" onsubmit="return confirm('Usunąć załącznik?')">
            <input type="hidden" name="_csrf"         value="<?= csrf_token() ?>">
            <input type="hidden" name="_del_attachment" value="1">
            <input type="hidden" name="att_id"        value="<?= $att['id'] ?>">
            <button type="submit" class="btn btn-xs btn-outline-danger btn-sm" title="Usuń">
              <i class="bi bi-trash3"></i>
            </button>
          </form>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php else: ?>
        <div class="text-muted text-center py-2" style="font-size:.8rem">Brak załączników</div>
        <?php endif; ?>

        <?php if (can_edit()): ?>
        <form method="post" enctype="multipart/form-data" class="mt-3 pt-3 border-top">
          <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
          <input type="hidden" name="_upload"  value="1">
          <div class="d-flex gap-2 align-items-center flex-wrap">
            <input type="file" name="attachment" class="form-control form-control-sm"
                   style="max-width:300px"
                   accept=".pdf,.doc,.docx,.xls,.xlsx,.odt,.ods,.png,.jpg,.jpeg,.gif,.webp,.zip,.txt,.csv">
            <button type="submit" class="btn btn-sm btn-outline-primary">
              <i class="bi bi-upload me-1"></i>Dodaj plik
            </button>
            <small class="text-muted">Maks. 15 MB · PDF, DOC, XLS, PNG, ZIP…</small>
          </div>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ── Prawa: bento ────────────────────────────── -->
  <div class="col-lg-4">

    <!-- Metadane -->
    <div class="bento-card">
      <div class="bento-card-header">
        <i class="bi bi-info-circle"></i> Informacje
      </div>
      <div class="bento-card-body">
        <div class="d-flex flex-column gap-2" style="font-size:.82rem">
          <div class="d-flex justify-content-between">
            <span class="text-muted">Właściciel</span>
            <span class="fw-semibold text-end"><?= $proc['owner_name'] ? h($proc['owner_name']) : '<span class="text-muted">—</span>' ?></span>
          </div>
          <div class="d-flex justify-content-between">
            <span class="text-muted">Kategoria</span>
            <span class="fw-semibold"><?= $proc['category'] ? h($proc['category']) : '<span class="text-muted">—</span>' ?></span>
          </div>
          <div class="d-flex justify-content-between">
            <span class="text-muted">Wersja</span>
            <span class="fw-semibold">v<?= $proc['version'] ?></span>
          </div>
          <div class="d-flex justify-content-between">
            <span class="text-muted">Utworzono</span>
            <span><?= date_pl($proc['created_at']) ?></span>
          </div>
          <div class="d-flex justify-content-between">
            <span class="text-muted">Ostatnia zmiana</span>
            <span><?= date_pl($proc['updated_at']) ?></span>
          </div>
          <?php if ($proc['creator_name']): ?>
          <div class="d-flex justify-content-between">
            <span class="text-muted">Autor</span>
            <span><?= h($proc['creator_name']) ?></span>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Akcje -->
    <?php if (is_admin()): ?>
    <div class="bento-card">
      <div class="bento-card-header"><i class="bi bi-gear"></i> Akcje</div>
      <div class="bento-card-body d-flex flex-column gap-2">
        <?php if (can_edit()): ?>
        <a href="<?= APP_URL ?>/procedures/edit.php?id=<?= $id ?>"
           class="btn btn-sm btn-outline-primary w-100 text-start">
          <i class="bi bi-pencil me-2"></i>Edytuj procedurę
        </a>
        <?php endif; ?>
        <form method="post">
          <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
          <input type="hidden" name="_status" value="<?= $proc['status'] === 'active' ? 'archive' : 'restore' ?>">
          <button type="submit" class="btn btn-sm btn-outline-secondary w-100 text-start">
            <i class="bi bi-<?= $proc['status'] === 'active' ? 'archive' : 'arrow-counterclockwise' ?> me-2"></i>
            <?= $proc['status'] === 'active' ? 'Archiwizuj' : 'Przywróć' ?>
          </button>
        </form>
        <form method="post" onsubmit="return confirm('Przenieść procedurę do kosza? Dane nie zostaną trwale usunięte.')">
          <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
          <input type="hidden" name="_status" value="delete">
          <button type="submit" class="btn btn-sm btn-outline-danger w-100 text-start">
            <i class="bi bi-trash3 me-2"></i>Przenieś do kosza
          </button>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <!-- Procedury powiązane -->
    <?php if ($related || can_edit()): ?>
    <div class="bento-card">
      <div class="bento-card-header">
        <i class="bi bi-diagram-3"></i> Powiązane
        <?php if ($related): ?>
        <span class="ms-auto badge bg-light text-dark" style="font-size:.65rem"><?= count($related) ?></span>
        <?php endif; ?>
      </div>
      <div class="bento-card-body">
        <?php if ($related): ?>
        <?php foreach ($related as $rel): ?>
        <a href="<?= APP_URL ?>/procedures/view.php?id=<?= $rel['id'] ?>" class="related-link">
          <i class="bi bi-journal-text text-primary" style="font-size:.85rem;flex-shrink:0"></i>
          <span class="flex-grow-1 text-truncate"><?= h($rel['title']) ?></span>
          <?php if ($rel['category']): ?>
          <span class="badge" style="background:#f1f5f9;color:#475569;font-size:.62rem"><?= h($rel['category']) ?></span>
          <?php endif; ?>
        </a>
        <?php endforeach; ?>
        <?php else: ?>
        <div class="text-muted text-center" style="font-size:.78rem">Brak powiązanych procedur</div>
        <?php endif; ?>
        <?php if (can_edit()): ?>
        <a href="<?= APP_URL ?>/procedures/edit.php?id=<?= $id ?>#relations"
           class="btn btn-xs btn-outline-secondary btn-sm mt-2 w-100">
          <i class="bi bi-plus-lg me-1"></i>Zarządzaj powiązaniami
        </a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Historia wersji -->
    <div class="bento-card">
      <div class="bento-card-header">
        <i class="bi bi-clock-history"></i> Historia wersji
        <span class="ms-auto badge bg-light text-dark" style="font-size:.65rem"><?= count($versions) ?></span>
      </div>
      <div class="bento-card-body" style="max-height:320px;overflow-y:auto">
        <?php foreach ($versions as $v): ?>
        <?php $is_v_current = $v['version'] === (int)$proc['version']; ?>
        <div class="ver-item">
          <div class="ver-badge <?= $is_v_current ? 'current' : '' ?>" title="Wersja <?= $v['version'] ?>">
            v<?= $v['version'] ?>
          </div>
          <div class="flex-grow-1">
            <div style="font-size:.78rem;font-weight:<?= $is_v_current ? '700' : '500' ?>;color:<?= $is_v_current ? '#2563eb' : '#1e293b' ?>">
              <?= $v['change_note'] ? h($v['change_note']) : 'Zmiana' ?>
            </div>
            <div style="font-size:.7rem;color:#94a3b8">
              <?= h($v['changed_by_name'] ?? '—') ?>
              &nbsp;·&nbsp;<?= date('d.m.Y H:i', strtotime($v['created_at'])) ?>
            </div>
          </div>
          <?php if (!$is_v_current): ?>
          <a href="?id=<?= $id ?>&v=<?= $v['version'] ?>"
             class="btn btn-xs btn-outline-secondary btn-sm flex-shrink-0"
             title="Podejrzyj tę wersję">
            <i class="bi bi-eye"></i>
          </a>
          <?php else: ?>
          <span class="badge bg-success opacity-75 flex-shrink-0" style="font-size:.62rem;align-self:center">bieżąca</span>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

  </div><!-- /col-lg-4 -->
</div>

<!-- Marked.js — renderowanie Markdown -->
<script src="https://cdn.jsdelivr.net/npm/marked@9/marked.min.js"></script>
<script>
(function () {
    var el = document.getElementById('proc-content-render');
    if (!el || typeof marked === 'undefined') return;
    var raw = el.textContent;
    marked.setOptions({ breaks: true, gfm: true });
    el.innerHTML = marked.parse(raw);
})();
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
