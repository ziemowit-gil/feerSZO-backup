<?php
/**
 * tasks/files.php — Pliki workspace w layoucie modułu Zadań.
 *
 * URL: /tasks/files.php?ws=N[&folder=F]
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/tasks.php';
require_once dirname(__DIR__) . '/includes/workspaces.php';

require_login();
require_module_enabled('tasks_enabled', 'Moduł zadań');

$user    = current_user();
$uid     = (int)$user['id'];

// ── Workspace ──────────────────────────────────────────────────────────────
$workspaces    = task_user_workspaces($uid);
$ws_id_param   = (int)($_GET['ws'] ?? 0);
$ws_id         = $ws_id_param;
$overview_mode = ($ws_id === 0);

if ($ws_id && $workspaces && !in_array($ws_id, array_column($workspaces, 'id'), false)) {
    header('Location: ' . APP_URL . '/tasks/files.php');
    exit;
}

if ($overview_mode) {
    // Buduj dane kart z już załadowanych $workspaces (unika problemu z is_admin vs my_role)
    $ov_workspaces = array_map(function ($ws) use ($uid) {
        $wsid = (int)$ws['id'];
        $fc   = (int)(db_one(
            "SELECT COUNT(*) AS n FROM ws_folders WHERE workspace_id=?", [$wsid]
        )['n'] ?? 0);
        $ff   = (int)(db_one(
            "SELECT COUNT(*) AS n FROM ws_files f
             JOIN ws_folders fo ON fo.id=f.folder_id
             WHERE fo.workspace_id=? AND f.deleted_at IS NULL",
            [$wsid]
        )['n'] ?? 0);
        // my_role istnieje dla non-admin, admin widzi wszystko
        $ws_role = $ws['my_role'] ?? (is_admin() ? 'admin' : (ws_user_role($wsid, $uid) ?: 'viewer'));
        return array_merge($ws, ['folder_count' => $fc, 'file_count' => $ff, 'ws_role' => $ws_role]);
    }, $workspaces);

    // Zakres obszarów bierzemy z $ov_workspaces (task_user_workspaces()) zamiast osobnego
    // JOIN-a na task_workspace_members, żeby uwzględnić też dostęp nadany przez zespół
    // (task_workspace_teams) — patrz task_workspace_role() w includes/tasks.php.
    $ov_ws_ids    = array_column($ov_workspaces, 'id');
    $recent_files = empty($ov_ws_ids) ? [] : db_all(
        "SELECT f.id, f.name, f.original_name, f.file_size, f.created_at,
                fo.id AS folder_id, fo.name AS folder_name,
                tw.id AS ws_id, tw.name AS ws_name, tw.color AS ws_color,
                u.name AS uploader_name
         FROM ws_files f
         JOIN ws_folders fo ON fo.id = f.folder_id
         JOIN task_workspaces tw ON tw.id = fo.workspace_id
         LEFT JOIN users u ON u.id = f.uploaded_by
         WHERE tw.id IN (" . implode(',', array_fill(0, count($ov_ws_ids), '?')) . ")
           AND tw.is_active = 1 AND f.deleted_at IS NULL
         ORDER BY f.created_at DESC LIMIT 10",
        $ov_ws_ids
    );
    // Załączniki zadań — lokalne pliki (uploads/tasks/), NIEZALEŻNE od folderów obszaru w chmurze.
    // Działają zawsze (nie wymagają $sp_ok) — patrz tasks/api/upload.php.
    $recent_task_files = empty($ov_ws_ids) ? [] : db_all(
        "SELECT tf.id, tf.original_name, tf.stored_name, tf.file_size, tf.created_at,
                t.id AS task_id, t.title AS task_title,
                tw.id AS ws_id, tw.name AS ws_name, tw.color AS ws_color,
                u.name AS uploader_name
         FROM task_files tf
         JOIN tasks t ON t.id = tf.task_id
         JOIN task_workspaces tw ON tw.id = t.workspace_id
         LEFT JOIN users u ON u.id = tf.uploaded_by
         WHERE tw.id IN (" . implode(',', array_fill(0, count($ov_ws_ids), '?')) . ")
           AND tw.is_active = 1 AND t.deleted_at IS NULL
         ORDER BY tf.created_at DESC LIMIT 10",
        $ov_ws_ids
    );
    $any_can_manage = !empty(array_filter($ov_workspaces, fn($w) => in_array($w['ws_role'], ['admin','editor'])));
    $any_can_upload = !empty(array_filter($ov_workspaces, fn($w) => in_array($w['ws_role'], ['admin','editor','member'])));
    $sp_ok = ws_available();

    $PAGE_TITLE       = 'Pliki';
    $PAGE_SUBTITLE    = 'Pliki';
    $TASKS_BREADCRUMB = null;
    $TASKS_WS_ID      = 0;
    $TASKS_FILES_VIEW = true;
    require_once __DIR__ . '/includes/header_tasks.php';
?>

<style type="text/tailwindcss">
:root {
  --tk-focus:   #2563eb;
  --tk-border:  #e2e8f0;
  --tk-bg-soft: #f8fafc;
  --tk-text:    #0f172a;
  --tk-muted:   #64748b;
  --tk-radius:  .5rem;
}
.tf-toolbar {
  @apply tw-flex tw-flex-wrap tw-gap-2 tw-items-center tw-bg-white tw-py-[.55rem] tw-px-3 tw-mb-[.85rem];
  border: 1px solid var(--tk-border); border-radius: var(--tk-radius);
}
.tf-view-btn {
  @apply tw-inline-flex tw-items-center tw-gap-[.3rem] tw-py-[.22rem] tw-px-[.65rem] tw-rounded-full tw-text-[.77rem] tw-font-semibold tw-border-[1.5px] tw-border-transparent tw-no-underline tw-bg-slate-100 tw-transition-all tw-whitespace-nowrap;
  color: var(--tk-muted);
}
.tf-view-btn.active { background: var(--tsk-green, #059669); color: #fff; border-color: var(--tsk-green, #059669); }
.tf-view-btn:hover:not(.active) { @apply tw-border-slate-400; }
.tf-sep { @apply tw-w-px tw-h-[1.3rem] tw-bg-slate-200 tw-flex-shrink-0; }
.tf-wrap { @apply tw-bg-white tw-overflow-hidden; border: 1px solid var(--tk-border); border-radius: var(--tk-radius); }
.tf-file-icon { @apply tw-text-[1.1rem]; }

.tf-ws-card {
  @apply tw-flex tw-items-center tw-gap-3 tw-py-[.85rem] tw-px-4 tw-bg-white tw-no-underline tw-text-inherit tw-transition-all tw-h-full;
  border: 1px solid var(--tk-border); border-radius: var(--tk-radius);
}
.tf-ws-card:hover { box-shadow: 0 2px 10px rgba(0,0,0,.09); border-color: #94a3b8; color: inherit; }
.tf-ws-card-icon {
  @apply tw-w-10 tw-h-10 tw-rounded-lg tw-flex tw-items-center tw-justify-center tw-text-white tw-text-[1.15rem] tw-flex-shrink-0;
}
.tf-ws-empty {
  @apply tw-border-2 tw-border-dashed tw-py-12 tw-px-4 tw-text-center;
  border-color: var(--tk-border); background: var(--tk-bg-soft); border-radius: var(--tk-radius); color: var(--tk-muted);
}
</style>

<main id="tsk-main" class="py-3 px-3 px-md-4 px-lg-5">
<?php require_once dirname(__DIR__) . '/includes/banner_rewrite.php' ?>

<!-- Pasek narzędzi — widoki + akcje -->
<div class="tf-toolbar">
  <a href="<?= APP_URL ?>/tasks/index.php?view=list" class="tf-view-btn">
    <i class="bi bi-list-ul"></i>Lista
  </a>
  <a href="<?= APP_URL ?>/tasks/index.php?view=kanban" class="tf-view-btn">
    <i class="bi bi-kanban"></i>Kanban
  </a>
  <a href="<?= APP_URL ?>/tasks/files.php" class="tf-view-btn active">
    <i class="bi bi-folder2-open"></i>Pliki
  </a>

  <div class="tf-sep" aria-hidden="true"></div>
  <span style="font-size:.78rem; color:var(--tk-muted)"><?= count($ov_workspaces) ?> obszarów</span>

  <?php if ($sp_ok && ($any_can_manage || $any_can_upload)): ?>
  <div class="ms-auto d-flex gap-2">
    <?php if ($any_can_manage): ?>
    <button class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:.8rem"
            data-bs-toggle="modal" data-bs-target="#ovNewFolderModal">
      <i class="bi bi-folder-plus me-1"></i>Nowy folder
    </button>
    <?php endif; ?>
    <?php if ($any_can_upload): ?>
    <button class="btn btn-sm btn-primary py-0 px-2" style="font-size:.8rem"
            data-bs-toggle="modal" data-bs-target="#ovUploadModal">
      <i class="bi bi-upload me-1"></i>Wgraj plik
    </button>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <div class="ms-auto"></div>
  <?php endif; ?>
</div>

<?php if (empty($ov_workspaces)): ?>
<div class="tf-ws-empty">
  <i class="bi bi-folder2 d-block mb-2" style="font-size:2.5rem; opacity:.3"></i>
  <p class="mb-2">Nie masz jeszcze żadnych obszarów roboczych.</p>
  <?php if ($any_can_manage): ?>
  <a href="<?= APP_URL ?>/tasks/settings/workspaces.php" class="btn btn-sm btn-primary">
    <i class="bi bi-plus-lg me-1"></i>Utwórz obszar
  </a>
  <?php endif; ?>
</div>
<?php else: ?>

<?php if (!$sp_ok): ?>
<?php ws_render_unavailable('Moduł Pliki', 'Foldery obszarów roboczych są w chmurze plików. Bez niej nie można ich przeglądać, wgrywać ani pobierać. Załączniki zadań działają bez zmian.'); ?>
<?php else: // chmura podłączona: siatka obszarów + ostatnio dodane pliki ?>
<!-- Siatka obszarów roboczych -->
<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 g-3 mb-4">
  <?php foreach ($ov_workspaces as $ov_ws):
    $wsColor    = $ov_ws['color'] ?: '#3b82f6';
    $wsIcon     = $ov_ws['icon']  ?: 'bi-kanban';
    $fc         = (int)($ov_ws['folder_count'] ?? 0);
    $ff         = (int)($ov_ws['file_count']   ?? 0);
    $can_init   = $fc === 0 && $sp_ok && in_array($ov_ws['ws_role'], ['admin','editor']);
  ?>
  <div class="col">
    <div class="tf-ws-card">
      <a href="?ws=<?= (int)$ov_ws['id'] ?>"
         class="d-flex align-items-center gap-3 flex-grow-1 text-decoration-none text-body min-width-0">
        <div class="tf-ws-card-icon" style="background:<?= h($wsColor) ?>">
          <i class="bi <?= h($wsIcon) ?>"></i>
        </div>
        <div class="flex-grow-1 min-width-0">
          <div class="fw-semibold text-truncate"><?= h($ov_ws['name']) ?></div>
          <small class="text-muted">
            <?= $fc ?> <?= $fc === 1 ? 'folder' : 'folderów' ?> · <?= $ff ?> <?= $ff === 1 ? 'plik' : 'plików' ?>
          </small>
        </div>
      </a>
      <?php if ($can_init): ?>
      <button class="btn btn-sm btn-outline-warning py-0 px-2 btn-init-ws flex-shrink-0"
              data-ws-id="<?= (int)$ov_ws['id'] ?>"
              title="Utwórz w chmurze foldery dla zadań"
              style="font-size:.75rem; white-space:nowrap">
        <i class="bi bi-magic me-1"></i>Inicjuj
      </button>
      <?php else: ?>
      <i class="bi bi-chevron-right text-muted flex-shrink-0" style="font-size:.75rem"></i>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Ostatnie pliki (cross-workspace) -->
<?php if (!empty($recent_files)): ?>
<div class="tf-wrap">
  <div class="d-flex align-items-center px-3 py-2 border-bottom"
       style="font-size:.8rem; font-weight:700; color:var(--tk-muted); text-transform:uppercase; letter-spacing:.04em">
    <i class="bi bi-clock-history me-1"></i>Ostatnio dodane
    <span class="badge text-bg-secondary rounded-pill ms-2" style="font-size:.6rem"><?= count($recent_files) ?></span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0" style="font-size:.83rem">
      <thead class="table-light">
        <tr>
          <th style="width:2rem" class="ps-3"></th>
          <th>Nazwa</th>
          <th class="d-none d-md-table-cell">Obszar / Folder</th>
          <th class="d-none d-md-table-cell">Rozmiar</th>
          <th class="d-none d-lg-table-cell">Data</th>
          <th class="text-end pe-3">Akcje</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($recent_files as $rf):
        $ext  = strtolower(pathinfo($rf['original_name'] ?? $rf['name'], PATHINFO_EXTENSION));
        $icon = match($ext) {
            'pdf'        => 'bi-file-earmark-pdf text-danger',
            'doc','docx' => 'bi-file-earmark-word text-primary',
            'xls','xlsx' => 'bi-file-earmark-excel text-success',
            'ppt','pptx' => 'bi-file-earmark-ppt text-warning',
            'jpg','jpeg','png','gif','webp' => 'bi-file-earmark-image text-info',
            'zip','7z','tar','gz'           => 'bi-file-earmark-zip text-secondary',
            default      => 'bi-file-earmark text-muted',
        };
        $previewable = in_array($ext, ['pdf','jpg','jpeg','png','gif','webp']);
        $rfid   = (int)$rf['id'];
        $rf_ws  = (int)$rf['ws_id'];
        $dl_url = APP_URL . '/workspaces/api.php?action=download&id=' . $rfid . '&_csrf=' . urlencode(csrf_token());
        $pv_url = APP_URL . '/workspaces/api.php?action=preview&id='  . $rfid . '&_csrf=' . urlencode(csrf_token());
      ?>
      <tr>
        <td class="ps-3"><i class="bi <?= $icon ?> tf-file-icon"></i></td>
        <td class="text-truncate" style="max-width:200px">
          <a href="?ws=<?= $rf_ws ?>&folder=<?= (int)$rf['folder_id'] ?>"
             class="text-decoration-none text-body" title="<?= h($rf['name']) ?>">
            <?= h($rf['name']) ?>
          </a>
        </td>
        <td class="d-none d-md-table-cell text-muted" style="font-size:.78rem">
          <a href="?ws=<?= $rf_ws ?>" class="text-decoration-none text-muted fw-semibold">
            <?= h($rf['ws_name']) ?>
          </a>
          <span class="text-muted"> / <?= h($rf['folder_name']) ?></span>
        </td>
        <td class="d-none d-md-table-cell text-muted"><?= ws_format_size((int)$rf['file_size']) ?></td>
        <td class="d-none d-lg-table-cell text-muted"><?= date('d.m.Y', strtotime($rf['created_at'])) ?></td>
        <td class="text-end pe-2">
          <div class="d-flex gap-1 justify-content-end">
            <?php if ($previewable): ?>
            <button class="btn btn-sm btn-outline-secondary py-0 px-2 btn-ov-preview"
                    data-url="<?= h($pv_url) ?>" data-ext="<?= h($ext) ?>"
                    data-name="<?= h($rf['name']) ?>" title="Podgląd">
              <i class="bi bi-eye"></i>
            </button>
            <?php endif; ?>
            <a href="<?= h($dl_url) ?>"
               class="btn btn-sm btn-outline-primary py-0 px-2" title="Pobierz">
              <i class="bi bi-download"></i>
            </a>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; // recent_files ?>

<?php endif; // sp_ok ?>

<!-- Załączniki zadań (lokalne, niezależne od folderów w chmurze) -->
<?php if (!empty($recent_task_files)): ?>
<div class="tf-wrap mt-3">
  <div class="d-flex align-items-center px-3 py-2 border-bottom"
       style="font-size:.8rem; font-weight:700; color:var(--tk-muted); text-transform:uppercase; letter-spacing:.04em">
    <i class="bi bi-paperclip me-1"></i>Załączniki zadań
    <span class="badge text-bg-secondary rounded-pill ms-2" style="font-size:.6rem"><?= count($recent_task_files) ?></span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0" style="font-size:.83rem">
      <thead class="table-light">
        <tr>
          <th style="width:2rem" class="ps-3"></th>
          <th>Nazwa</th>
          <th class="d-none d-md-table-cell">Zadanie / Obszar</th>
          <th class="d-none d-md-table-cell">Rozmiar</th>
          <th class="d-none d-lg-table-cell">Data</th>
          <th class="text-end pe-3">Akcje</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($recent_task_files as $tf):
        $ext  = strtolower(pathinfo($tf['original_name'], PATHINFO_EXTENSION));
        $icon = match($ext) {
            'pdf'        => 'bi-file-earmark-pdf text-danger',
            'doc','docx' => 'bi-file-earmark-word text-primary',
            'xls','xlsx' => 'bi-file-earmark-excel text-success',
            'ppt','pptx' => 'bi-file-earmark-ppt text-warning',
            'jpg','jpeg','png','gif','webp' => 'bi-file-earmark-image text-info',
            'zip','7z','tar','gz'           => 'bi-file-earmark-zip text-secondary',
            default      => 'bi-file-earmark text-muted',
        };
        $tf_dl_url = APP_URL . '/tasks/api/file.php?id=' . (int)$tf['id'] . '&dl=1';
      ?>
      <tr>
        <td class="ps-3"><i class="bi <?= $icon ?> tf-file-icon"></i></td>
        <td class="text-truncate" style="max-width:200px" title="<?= h($tf['original_name']) ?>">
          <?= h($tf['original_name']) ?>
        </td>
        <td class="d-none d-md-table-cell text-muted" style="font-size:.78rem">
          <a href="<?= APP_URL ?>/tasks/index.php?task=<?= (int)$tf['task_id'] ?>&ws=<?= (int)$tf['ws_id'] ?>"
             class="text-decoration-none fw-semibold">
            <?= h($tf['task_title']) ?>
          </a>
          <span class="text-muted"> / <?= h($tf['ws_name']) ?></span>
        </td>
        <td class="d-none d-md-table-cell text-muted"><?= ws_format_size((int)$tf['file_size']) ?></td>
        <td class="d-none d-lg-table-cell text-muted"><?= date('d.m.Y', strtotime($tf['created_at'])) ?></td>
        <td class="text-end pe-2">
          <div class="d-flex gap-1 justify-content-end">
            <a href="<?= APP_URL ?>/tasks/index.php?task=<?= (int)$tf['task_id'] ?>&ws=<?= (int)$tf['ws_id'] ?>"
               class="btn btn-sm btn-outline-secondary py-0 px-2" title="Otwórz zadanie">
              <i class="bi bi-box-arrow-up-right"></i>
            </a>
            <a href="<?= h($tf_dl_url) ?>" download
               class="btn btn-sm btn-outline-primary py-0 px-2" title="Pobierz">
              <i class="bi bi-download"></i>
            </a>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; // recent_task_files ?>
<?php endif; // ov_workspaces not empty ?>

</main>

<!-- ══ Modals (overview) ═══════════════════════════════════════════════════ -->

<!-- Nowy folder (z wyborem obszaru) -->
<?php if ($sp_ok && $any_can_manage): ?>
<div class="modal fade" id="ovNewFolderModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title fw-semibold"><i class="bi bi-folder-plus me-1 text-primary"></i>Nowy folder</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body pb-2">
        <div id="ovFolderAlert" class="alert alert-danger py-1 small" style="display:none"></div>
        <label class="form-label small fw-semibold mb-1">Obszar roboczy <span class="text-danger">*</span></label>
        <select id="ovFolderWsId" class="form-select form-select-sm mb-3">
          <option value="">— wybierz obszar —</option>
          <?php foreach ($ov_workspaces as $ov_ws): ?>
          <?php if (in_array($ov_ws['ws_role'], ['admin','editor'])): ?>
          <option value="<?= (int)$ov_ws['id'] ?>"><?= h($ov_ws['name']) ?></option>
          <?php endif; ?>
          <?php endforeach; ?>
        </select>
        <label class="form-label small fw-semibold mb-1">Nazwa folderu <span class="text-danger">*</span></label>
        <input type="text" id="ovFolderName" class="form-control form-control-sm mb-2" placeholder="np. Dokumenty">
        <label class="form-label small fw-semibold mb-1">Opis (opcjonalnie)</label>
        <input type="text" id="ovFolderDesc" class="form-control form-control-sm" placeholder="Krótki opis">
      </div>
      <div class="modal-footer py-2">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Anuluj</button>
        <button class="btn btn-sm btn-primary" id="ovBtnCreateFolder">
          <i class="bi bi-folder-plus me-1" aria-hidden="true"></i>Utwórz folder
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Wgraj plik (z wyborem obszaru + folderu) -->
<?php if ($sp_ok && $any_can_upload): ?>
<div class="modal fade" id="ovUploadModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title fw-semibold"><i class="bi bi-upload me-1 text-primary"></i>Wgraj plik</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body pb-2">
        <div id="ovUploadAlert" class="alert alert-danger py-1 small" style="display:none"></div>
        <label class="form-label small fw-semibold mb-1">Obszar roboczy <span class="text-danger">*</span></label>
        <select id="ovUploadWsId" class="form-select form-select-sm mb-3">
          <option value="">— wybierz obszar —</option>
          <?php foreach ($ov_workspaces as $ov_ws): ?>
          <?php if (in_array($ov_ws['ws_role'], ['admin','editor','member'])): ?>
          <option value="<?= (int)$ov_ws['id'] ?>"><?= h($ov_ws['name']) ?></option>
          <?php endif; ?>
          <?php endforeach; ?>
        </select>
        <label class="form-label small fw-semibold mb-1">Folder <span class="text-danger">*</span></label>
        <select id="ovUploadFolderId" class="form-select form-select-sm mb-3" disabled>
          <option value="">— najpierw wybierz obszar —</option>
        </select>
        <label class="form-label small fw-semibold mb-1">Plik(i) <span class="text-danger">*</span></label>
        <input type="file" id="ovUploadFile" class="form-control form-control-sm" multiple>
        <div id="ovUploadProgress" style="display:none" class="mt-2">
          <div class="progress" style="height:4px">
            <div class="progress-bar progress-bar-striped progress-bar-animated" id="ovUploadBar" style="width:0%"></div>
          </div>
          <small class="text-muted" id="ovUploadStatus"></small>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Anuluj</button>
        <button class="btn btn-sm btn-primary" id="ovBtnUpload">
          <i class="bi bi-upload me-1"></i>Wgraj
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Podgląd (reused) -->
<div class="modal fade" id="ovPreviewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title fw-semibold" id="ovPreviewTitle">
          <i class="bi bi-eye me-1 text-primary"></i>Podgląd
        </h6>
        <a id="ovPreviewDownload" href="#" class="btn btn-sm btn-outline-primary py-0 px-2 me-2">
          <i class="bi bi-download me-1"></i>Pobierz
        </a>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0" style="min-height:70vh">
        <div id="ovPreviewSpinner" class="d-flex align-items-center justify-content-center" style="min-height:70vh">
          <span class="spinner-border text-primary"></span>
        </div>
        <iframe id="ovPreviewIframe" src="about:blank" style="display:none;width:100%;height:75vh;border:0"></iframe>
        <div id="ovPreviewImgWrap" style="display:none;text-align:center;padding:1rem">
          <img id="ovPreviewImg" src="" alt="" style="max-width:100%;max-height:75vh;object-fit:contain">
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Footer -->
<footer class="tsk-footer">
  <span><?= h(defined('ORG_NAME') ? ORG_NAME : '') ?> — Moduł Zadań</span>
  <span><i class="bi bi-folder2-open me-1"></i>Pliki</span>
</footer>

<script>
const CSRF = <?= json_encode(csrf_token()) ?>;
const API  = <?= json_encode(APP_URL . '/workspaces/api.php') ?>;

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
async function wsApi(payload) {
  return fetch(API, {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({_csrf: CSRF, ...payload})
  }).then(r => r.json()).catch(() => ({ok: false, error: 'Błąd połączenia.'}));
}

// ── Podgląd (overview) ────────────────────────────────────────────────────
const ovPreviewModal   = new bootstrap.Modal(document.getElementById('ovPreviewModal'));
const ovPreviewIframe  = document.getElementById('ovPreviewIframe');
const ovPreviewImg     = document.getElementById('ovPreviewImg');
const ovPreviewImgWrap = document.getElementById('ovPreviewImgWrap');
const ovPreviewSpinner = document.getElementById('ovPreviewSpinner');

document.querySelectorAll('.btn-ov-preview').forEach(btn => {
  btn.addEventListener('click', () => {
    const url = btn.dataset.url, ext = btn.dataset.ext, name = btn.dataset.name;
    document.getElementById('ovPreviewTitle').textContent = name;
    document.getElementById('ovPreviewDownload').href = url.replace('action=preview','action=download');
    ovPreviewSpinner.style.display  = '';
    ovPreviewIframe.style.display   = 'none';
    ovPreviewImgWrap.style.display  = 'none';
    ovPreviewIframe.src = 'about:blank';
    ovPreviewModal.show();
    if (['jpg','jpeg','png','gif','webp'].includes(ext)) {
      ovPreviewImg.onload = () => { ovPreviewSpinner.style.display = 'none'; ovPreviewImgWrap.style.display = ''; };
      ovPreviewImg.src = url;
    } else {
      ovPreviewIframe.onload = () => { ovPreviewSpinner.style.display = 'none'; ovPreviewIframe.style.display = ''; };
      ovPreviewIframe.src = url;
    }
  });
});
document.getElementById('ovPreviewModal').addEventListener('hidden.bs.modal', () => {
  ovPreviewIframe.src = 'about:blank';
  ovPreviewImg.src = '';
  ovPreviewImgWrap.style.display = ovPreviewIframe.style.display = 'none';
});

// ── Nowy folder (overview) ────────────────────────────────────────────────
document.getElementById('ovBtnCreateFolder')?.addEventListener('click', async () => {
  const wsId    = +document.getElementById('ovFolderWsId').value;
  const name    = document.getElementById('ovFolderName').value.trim();
  const desc    = document.getElementById('ovFolderDesc').value.trim();
  const alertEl = document.getElementById('ovFolderAlert');
  alertEl.style.display = 'none';
  if (!wsId) { alertEl.textContent = 'Wybierz obszar roboczy.'; alertEl.style.display = ''; return; }
  if (!name) { alertEl.textContent = 'Podaj nazwę folderu.';    alertEl.style.display = ''; return; }
  const btn = document.getElementById('ovBtnCreateFolder');
  btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Tworzę…';
  const r = await wsApi({action: 'create_folder', workspace_id: wsId, name, description: desc});
  btn.disabled = false; btn.innerHTML = '<i class="bi bi-folder-plus me-1" aria-hidden="true"></i>Utwórz folder';
  if (r.ok) {
    window.location.href = `?ws=${wsId}&folder=${r.folder.id}`;
  } else { alertEl.textContent = r.error || 'Błąd.'; alertEl.style.display = ''; }
});

// ── Upload (overview): zmiana obszaru → wczytaj foldery ──────────────────
document.getElementById('ovUploadWsId')?.addEventListener('change', async function () {
  const wsId     = +this.value;
  const folderSel = document.getElementById('ovUploadFolderId');
  folderSel.innerHTML = '<option value="">Wczytuję…</option>';
  folderSel.disabled  = true;
  if (!wsId) {
    folderSel.innerHTML = '<option value="">— najpierw wybierz obszar —</option>';
    return;
  }
  const r = await wsApi({action: 'list_folders', workspace_id: wsId});
  if (r.ok && r.folders.length) {
    folderSel.innerHTML = '<option value="">— wybierz folder —</option>' +
      r.folders.map(f => `<option value="${f.id}">${escHtml(f.name)}</option>`).join('');
    folderSel.disabled = false;
  } else {
    folderSel.innerHTML = '<option value="">Brak folderów w tym obszarze</option>';
  }
});

// ── Inicjuj foldery obszaru (overview) ───────────────────────────────────
document.querySelectorAll('.btn-init-ws').forEach(btn => {
  btn.addEventListener('click', async (e) => {
    e.stopPropagation();
    const wsId = +btn.dataset.wsId;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Tworzę…';
    const r = await wsApi({action: 'init_ws_folders', workspace_id: wsId});
    if (r.ok) {
      btn.innerHTML = `<i class="bi bi-check-lg me-1"></i>${r.created} folderów`;
      btn.className = 'btn btn-sm btn-success py-0 px-2 flex-shrink-0';
      btn.style.fontSize = '.75rem';
      setTimeout(() => window.location.href = `?ws=${wsId}`, 900);
    } else {
      btn.innerHTML = '<i class="bi bi-x-lg me-1"></i>Błąd';
      btn.className = 'btn btn-sm btn-danger py-0 px-2 flex-shrink-0';
      btn.disabled = false;
    }
  });
});

// ── Upload (overview): wyślij ─────────────────────────────────────────────
document.getElementById('ovBtnUpload')?.addEventListener('click', async () => {
  const wsId     = +document.getElementById('ovUploadWsId').value;
  const folderId = +document.getElementById('ovUploadFolderId').value;
  const files    = document.getElementById('ovUploadFile').files;
  const alertEl  = document.getElementById('ovUploadAlert');
  alertEl.style.display = 'none';
  if (!wsId)     { alertEl.textContent = 'Wybierz obszar roboczy.'; alertEl.style.display = ''; return; }
  if (!folderId) { alertEl.textContent = 'Wybierz folder.';          alertEl.style.display = ''; return; }
  if (!files.length) { alertEl.textContent = 'Wybierz plik.';        alertEl.style.display = ''; return; }

  const progress = document.getElementById('ovUploadProgress');
  const bar      = document.getElementById('ovUploadBar');
  const status   = document.getElementById('ovUploadStatus');
  const btn      = document.getElementById('ovBtnUpload');
  btn.disabled   = true;

  for (const file of files) {
    progress.style.display = '';
    bar.style.width = '0%';
    bar.className = 'progress-bar progress-bar-striped progress-bar-animated';
    status.textContent = `Wgrywam: ${file.name}…`;
    const fd = new FormData();
    fd.append('_csrf', CSRF);
    fd.append('action', 'upload_file');
    fd.append('folder_id', folderId);
    fd.append('file', file);
    const xhr = new XMLHttpRequest();
    xhr.upload.addEventListener('progress', e => {
      if (e.lengthComputable) bar.style.width = Math.round(e.loaded / e.total * 100) + '%';
    });
    await new Promise(resolve => {
      xhr.onload = () => {
        const r = JSON.parse(xhr.responseText || '{}');
        if (r.ok) {
          status.textContent = `✓ ${file.name} wgrano`;
          bar.style.width = '100%';
          bar.classList.remove('progress-bar-animated');
        } else {
          status.textContent = `✗ ${r.error || 'Błąd wgrywania.'}`;
          bar.classList.remove('bg-primary');
          bar.classList.add('bg-danger');
        }
        resolve();
      };
      xhr.open('POST', API); xhr.send(fd);
    });
  }
  btn.disabled = false;
  setTimeout(() => window.location.href = `?ws=${wsId}&folder=${folderId}`, 700);
});
</script>

<?php
include __DIR__ . '/includes/footer_tasks.php';
exit; // ← overview mode ends here — below is per-workspace code
}

// ── Per-workspace mode ──────────────────────────────────────────────────────

if (!$ws_id) {
    http_response_code(403);
    include dirname(__DIR__) . '/includes/header.php';
    echo '<div class="container py-5 text-center text-muted">Brak dostępu do obszarów roboczych.</div>';
    include dirname(__DIR__) . '/includes/footer.php';
    exit;
}

ws_require_access($ws_id);

$workspace = db_one("SELECT * FROM task_workspaces WHERE id=? AND is_active=1", [$ws_id]);
if (!$workspace) {
    header('Location: ' . APP_URL . '/tasks/index.php');
    exit;
}

// Uprawnienia do plików wg macierzy (includes/workspaces.php → ws_can; ustawia administrator w Zadania → Ustawienia)
$can_view       = ws_can('view', $ws_id, $uid);
$can_dl         = ws_can('download', $ws_id, $uid);
$can_upload     = ws_can('upload', $ws_id, $uid);
$can_link       = ws_can('link', $ws_id, $uid);
$can_folder     = ws_can('create_folder', $ws_id, $uid);
$can_del_folder = ws_can('delete_folder', $ws_id, $uid);
$can_tf         = ws_can('task_folders', $ws_id, $uid);
$can_manage     = ws_can_manage($ws_id, $uid);   // menedżer plików zadań (załączniki lokalne) — bez zmian
$folders    = ws_list_folders($ws_id);
$sp_ok      = ws_available();
$role       = ws_user_role($ws_id, $uid);

// Bez chmury plików widok obszaru (foldery i pliki leżą w chmurze) jest zablokowany — panel z linkiem do ustawień
if (!$sp_ok) {
    $PAGE_TITLE       = h($workspace['name']) . ' — Pliki';
    $PAGE_SUBTITLE    = 'Pliki';
    $TASKS_BREADCRUMB = h($workspace['name']);
    $TASKS_WS_ID      = $ws_id;
    $TASKS_FILES_VIEW = true;
    require_once __DIR__ . '/includes/header_tasks.php';
    echo '<main id="tsk-main" class="py-3 px-3 px-md-4 px-lg-5">';
    ws_render_unavailable('Pliki obszaru „' . $workspace['name'] . '”', 'Foldery obszarów roboczych są w chmurze plików. Załączniki zadań jej nie wymagają i są w widoku zadań.');
    echo '</main>';
    include __DIR__ . '/includes/footer_tasks.php';
    exit;
}

// Bez „Przeglądania folderów wspólnych” widać tylko foldery zadań (pliki zadań są dla wszystkich w obszarze)
if (!$can_view) {
    $folders = array_values(array_filter($folders, fn($f) => !empty($f['task_id'])));
}

// Auto-inicjuj strukturę w chmurze, gdy obszar jest pusty i użytkownik ma uprawnienia
$auto_initialized = false;
$auto_created     = 0;
if (empty($folders) && $can_tf && $sp_ok) {
    try {
        ws_cloud_init_workspace($ws_id);
        $auto_created     = ws_init_task_folders($ws_id, $uid);
        $folders          = ws_list_folders($ws_id);
        $auto_initialized = true;
    } catch (\Throwable $e) {
        error_log('[files.php auto-init] ws=' . $ws_id . ' ' . $e->getMessage());
    }
}

$active_folder_id = (int)($_GET['folder'] ?? ($folders[0]['id'] ?? 0));
if (!$can_view && $active_folder_id && !in_array($active_folder_id, array_map(fn($f) => (int)$f['id'], $folders), true)) {
    $active_folder_id = (int)($folders[0]['id'] ?? 0);   // folder wspólny bez uprawnienia → pierwszy folder zadania
}
$active_folder    = null;
$files            = [];
if ($active_folder_id) {
    $active_folder = ws_get_folder($active_folder_id);
    if ($active_folder && (int)$active_folder['workspace_id'] === $ws_id) {
        $files = ws_list_files($active_folder_id);
    }
}

// Liczniki plików folderów, zadania folderów zadań, brakujące foldery zadań, powiązania plików — po jednym zapytaniu
$tf_counts = [];
foreach (db_all("SELECT f.folder_id, COUNT(*) AS n FROM ws_files f JOIN ws_folders fo ON fo.id = f.folder_id
                 WHERE fo.workspace_id = ? AND f.deleted_at IS NULL GROUP BY f.folder_id", [$ws_id]) as $_r) {
    $tf_counts[(int)$_r['folder_id']] = (int)$_r['n'];
}
$tf_tasks = [];
foreach (db_all("SELECT t.id, t.title, t.completed_at, t.deleted_at, tl.is_done_state, tl.name AS list_name
                 FROM tasks t LEFT JOIN task_lists tl ON tl.id = t.list_id WHERE t.workspace_id = ?", [$ws_id]) as $_t) {
    $tf_tasks[(int)$_t['id']] = $_t + ['_done' => !empty($_t['completed_at']) || !empty($_t['is_done_state'])];
}
$tf_shared = array_values(array_filter($folders, fn($f) => empty($f['task_id'])));
$tf_taskf  = array_values(array_filter($folders, fn($f) => !empty($f['task_id'])));
$tf_missing = (int)(db_one("SELECT COUNT(*) AS n FROM tasks t WHERE t.workspace_id = ? AND t.deleted_at IS NULL
                            AND NOT EXISTS (SELECT 1 FROM ws_folders f WHERE f.task_id = t.id)", [$ws_id])['n'] ?? 0);
$tf_links = [];
if ($active_folder_id) {
    foreach (db_all("SELECT wtf.file_id, COUNT(*) AS n FROM ws_task_files wtf JOIN ws_files f ON f.id = wtf.file_id
                     WHERE f.folder_id = ? GROUP BY wtf.file_id", [$active_folder_id]) as $_r) {
        $tf_links[(int)$_r['file_id']] = (int)$_r['n'];
    }
}
$tf_active_task = ($active_folder && !empty($active_folder['task_id'])) ? ($tf_tasks[(int)$active_folder['task_id']] ?? null) : null;
// Pliki folderu zadania pobiera każdy z dostępem do obszaru; w folderach wspólnych — wg macierzy
if ($active_folder && !empty($active_folder['task_id'])) $can_dl = true;
$tf_ws_color = (string)($workspace['color'] ?: '#5f5f5f');

// Załączniki zadań + powiązane pliki Koszulek → tasks/includes/files_task_manager.php

$PAGE_TITLE       = h($workspace['name']) . ' — Pliki';
$PAGE_SUBTITLE    = 'Pliki';
$TASKS_BREADCRUMB = h($workspace['name']);
$TASKS_WS_ID      = $ws_id;
$TASKS_FILES_VIEW = true;  // sygnał dla header_tasks.php: switcher WS → files.php
require_once __DIR__ . '/includes/header_tasks.php';
?>

<style>
/* ══ Pliki obszaru (2026-11-10) — szata Metro jak lista zadań: nagłówek z kafelkiem obszaru i przełącznikiem widoków,
   foldery wspólne i foldery zadań w lewej kolumnie (filtr), folder zadania z linkiem do zadania, tabela bez zaokrągleń ══ */
.tf-page{--tf-line:#e1e1e1;--tf-muted:#5f5f5f;--tf-text:#111827}
.tf-layout{display:grid;grid-template-columns:minmax(0,1fr);gap:1.25rem;align-items:start}
@media(min-width:992px){.tf-layout{grid-template-columns:280px minmax(0,1fr)}}
.tf-side{background:#fff;border-right:1px solid var(--tf-line);padding:.25rem 0 .5rem}
@media(min-width:992px){.tf-side{position:sticky;top:120px;max-height:calc(100vh - 140px);overflow-y:auto}}
.tf-side-search{padding:.25rem .75rem .5rem}
.tf-side-search input{width:100%;min-height:32px;padding:0 .55rem;border:1px solid #8a8886;font-size:.84rem}
.tf-side-search input:focus{outline:0;border-color:var(--tsk-green,#007a00);box-shadow:inset 0 0 0 1px var(--tsk-green,#007a00)}
.tf-side-h{display:flex;align-items:center;gap:.4rem;padding:.6rem .9rem .3rem;font-size:.66rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:var(--tf-muted)}
.tf-side-h .tf-cnt{margin-left:auto}
.tf-side ul{list-style:none;margin:0;padding:0}
.tf-folder-link{display:flex;align-items:center;gap:.6rem;min-height:40px;padding:.3rem .75rem .3rem calc(.9rem - 3px);border-left:3px solid transparent;color:var(--tf-text);text-decoration:none;font-size:.86rem;line-height:1.2}
.tf-folder-link:hover{background:#f2f2f2;color:var(--tf-text)}
.tf-folder-link:focus-visible{outline:2px solid var(--tf-text);outline-offset:-2px}
.tf-folder-link.active{background:#f2f2f2;background:color-mix(in srgb,var(--ws) 12%,#fff);border-left-color:var(--ws);font-weight:700}
.tf-folder-link>.bi{flex-shrink:0;font-size:1rem;color:var(--ws)}
.tf-folder-link.is-done>.bi{color:#5f5f5f}
.tf-folder-link.is-done .tf-folder-name{color:#5f5f5f}
.tf-folder-name{flex:1;min-width:0;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;overflow-wrap:anywhere}
.tf-cnt{flex-shrink:0;min-width:1.5rem;padding:.08rem .35rem;text-align:center;font-size:.72rem;font-weight:700;background:#f2f2f2;color:var(--tf-text);font-variant-numeric:tabular-nums}
.tf-cnt.is-zero{background:transparent;color:var(--tf-muted);font-weight:400}
.tf-side-none{padding:.3rem .9rem .5rem;font-size:.8rem;color:var(--tf-muted);margin:0}
.tf-ws-list{margin-bottom:.4rem!important;padding-bottom:.4rem!important;border-bottom:1px solid var(--tf-line)!important}
.tf-ws-list .tk-ws-side-link{min-height:36px!important;padding-top:.2rem!important;padding-bottom:.2rem!important;font-size:.84rem!important}
.tf-ws-list .tk-ws-tile{width:24px!important;height:24px!important;font-size:.78rem!important}
.tf-empty{padding:2rem 1rem!important}
.tf-more{margin-top:1.25rem;border-top:1px solid var(--tf-line)}
.tf-more>summary{display:flex;align-items:center;gap:.5rem;padding:.75rem .25rem;font-size:.95rem;font-weight:600;color:var(--tf-text);cursor:pointer;list-style:none}
.tf-more>summary::-webkit-details-marker{display:none}
.tf-more>summary::after{content:"\25BE";margin-left:auto;color:var(--tf-muted)}
.tf-more[open]>summary::after{content:"\25B4"}
.tf-more>summary .tf-muted{font-weight:400;font-size:.82rem}
.tf-more>summary:focus-visible{outline:2px solid var(--tf-text);outline-offset:2px}

.tf-card{position:relative;background:#fff;border:1px solid var(--tf-line);border-top:2px solid var(--ws,#007a00)}
.tf-card-head{display:flex;align-items:center;flex-wrap:wrap;gap:.5rem .75rem;padding:.75rem 1rem;border-bottom:1px solid var(--tf-line)}
.tf-card-t{display:flex;align-items:center;gap:.5rem;margin:0;font-size:1.05rem;font-weight:600;color:var(--tf-text);min-width:0}
.tf-card-t>.bi{color:var(--ws);flex-shrink:0}
.tf-card-act{display:flex;align-items:center;gap:.35rem;margin-left:auto;flex-wrap:wrap}
.tf-taskline{display:flex;align-items:center;flex-wrap:wrap;gap:.35rem .75rem;padding:.5rem 1rem;background:#f7f7f7;border-bottom:1px solid var(--tf-line);font-size:.84rem;color:#3f3f46}
.tf-taskline a{font-weight:600;color:var(--tm-blue,#0f6fa8);text-decoration:none}
.tf-taskline a:hover{text-decoration:underline}
.tf-st{display:inline-block;padding:.08rem .45rem;font-size:.74rem;font-weight:700;background:#eef3ff;color:#0b3a9e}
.tf-st.is-done{background:#dff6dd;color:#0e5a0e}
.tf-st.is-gone{background:#fde7e9;color:#a20025}
.tf-ibtn{display:inline-flex;align-items:center;justify-content:center;gap:.35rem;min-width:32px;height:32px;padding:0 .55rem;border:1px solid #8a8886;background:#fff;color:var(--tf-text);font-size:.82rem;font-weight:600;text-decoration:none;cursor:pointer;white-space:nowrap}
.tf-ibtn:hover{background:#f2f2f2;color:var(--tf-text)}
.tf-ibtn:focus-visible{outline:2px solid var(--tf-text);outline-offset:1px}
.tf-ibtn.is-cta{background:var(--tsk-green,#007a00);border-color:var(--tsk-green,#007a00);color:#fff}
.tf-ibtn.is-cta:hover{background:#1d1d1d;border-color:#1d1d1d}
.tf-ibtn.is-danger{color:#a20025}
.tf-ibtn.is-danger:hover{background:#a20025;border-color:#a20025;color:#fff}
.tf-ibtn.active{background:var(--tf-text);border-color:var(--tf-text);color:#fff}
.tf-seg{display:inline-flex}
.tf-seg .tf-ibtn+.tf-ibtn{border-left:0}
.tf-progress{padding:.4rem 1rem;border-bottom:1px solid var(--tf-line)}
.tf-progress .progress{height:4px;border-radius:0}
.tf-progress small{color:var(--tf-muted)}
.tf-empty{display:flex;flex-direction:column;align-items:center;gap:.4rem;padding:3rem 1rem;text-align:center;color:#3f3f46;font-size:.9rem}
.tf-empty>.bi{font-size:2rem;color:var(--tf-muted)}
.tf-table{width:100%;margin:0;font-size:.86rem}
.tf-table thead th{padding:.6rem .75rem;background:#fff;font-size:.7rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#3f3f46;border-bottom:1px solid var(--tf-line);white-space:nowrap}
.tf-table td{padding:.55rem .75rem;vertical-align:middle;border-bottom:1px solid #f0f0f0}
.tf-table tbody tr:hover>td{background:#f7f7f7}
.tf-fname{display:block;max-width:100%;border:0;background:none;padding:0;text-align:left;font:inherit;font-weight:600;color:var(--tf-text);text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;cursor:pointer}
.tf-fname:hover,.tf-fname:focus-visible{text-decoration:underline;color:var(--tm-blue,#0f6fa8)}
.tf-file-icon{font-size:1.15rem}
.tf-links{display:inline-flex;align-items:center;gap:.25rem;margin-top:.15rem;padding:.05rem .4rem;border:0;font-size:.72rem;font-weight:600;background:#f2f2f2;color:#3f3f46;cursor:pointer}
.tf-links.has{background:#eef3ff;color:#0b3a9e}
.tf-links:hover{text-decoration:underline}
.tf-acts{display:flex;gap:.25rem;justify-content:flex-end}
.tf-muted{color:var(--tf-muted)}
.tf-dropzone{position:relative}
.tf-drop-overlay{display:none;position:absolute;inset:0;z-index:20;flex-direction:column;align-items:center;justify-content:center;gap:.5rem;padding:1rem;
  color:#fff;font-size:.95rem;font-weight:600;text-align:center;pointer-events:none;background:rgba(0,122,0,.9);border:3px dashed rgba(255,255,255,.8)}
.tf-drop-overlay i{font-size:2.25rem}
.tf-dropzone.tf-drop-active .tf-drop-overlay{display:flex}
.tf-file-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:.75rem;padding:.9rem}
.tf-grid-card{display:flex;flex-direction:column;gap:.15rem}
.tf-grid-thumb{position:relative;aspect-ratio:1/1;overflow:hidden;display:flex;align-items:center;justify-content:center;background:#f2f2f2;border:1px solid var(--tf-line)}
.tf-grid-thumb img{width:100%;height:100%;object-fit:cover}
.tf-grid-thumb i{font-size:2.5rem;color:var(--tf-muted)}
.tf-grid-overlay{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;gap:.25rem;opacity:0;transition:opacity .15s;background:rgba(17,24,39,.55)}
.tf-grid-thumb:hover .tf-grid-overlay,.tf-grid-thumb:focus-within .tf-grid-overlay{opacity:1}
.tf-grid-taskbadge{position:absolute;top:.3rem;right:.3rem;padding:.05rem .35rem;font-size:.66rem;font-weight:700;background:#0050ef;color:#fff}
.tf-grid-name{margin-top:.25rem;font-size:.82rem;font-weight:600;color:var(--tf-text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tf-grid-meta{font-size:.72rem;color:var(--tf-muted)}
/* Menedżer plików zadań pod spodem (tasks/includes/files_task_manager.php) — ta sama szata */
.tf-page .tf-wrap{background:#fff;border:1px solid var(--tf-line);border-top:2px solid var(--tf-text);border-radius:0;overflow:hidden}
.tf-page .tf-wrap .table thead th{background:#fff;font-size:.7rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#3f3f46}
.tf-page .tf-wrap .bg-light{background:#f7f7f7!important}
.tf-page .tf-wrap .badge{border-radius:0}
@media(prefers-reduced-motion:reduce){.tf-grid-overlay{transition:none}}
</style>

<div class="tf-page" style="--ws:<?= h($tf_ws_color) ?>">
<?php require_once dirname(__DIR__) . '/includes/banner_rewrite.php' ?>

<!-- ── Nagłówek obszaru: nazwa, przełącznik widoków, działania ─────────────────────── -->
<div class="tk-head">
  <h1 class="tk-head-t">
    <span class="tk-head-tile" style="--ws:<?= h($tf_ws_color) ?>;--ws-ink:<?= task_ws_ink($tf_ws_color) ?>" aria-hidden="true"><i class="bi <?= h($workspace['icon'] ?: 'bi-folder') ?>"></i></span>
    <span><?= h($workspace['name']) ?><span class="ws-set-sub">Pliki obszaru</span></span>
  </h1>
  <div class="tk-head-act">
    <div class="tk-viewsw" role="group" aria-label="Widok obszaru">
      <a href="<?= APP_URL ?>/tasks/index.php?ws=<?= $ws_id ?>&view=list" class="tk-viewsw-b"><i class="bi bi-list-ul" aria-hidden="true"></i>Lista</a>
      <a href="<?= APP_URL ?>/tasks/index.php?ws=<?= $ws_id ?>&view=kanban" class="tk-viewsw-b"><i class="bi bi-kanban" aria-hidden="true"></i>Kanban</a>
      <a href="<?= APP_URL ?>/tasks/files.php?ws=<?= $ws_id ?>" class="tk-viewsw-b is-on" aria-current="page"><i class="bi bi-folder2-open" aria-hidden="true"></i>Pliki</a>
    </div>
    <?php if ($can_tf && $sp_ok && $tf_missing > 0): ?>
    <button type="button" class="tk-hbtn" id="tfInitTaskFolders" title="Utwórz w chmurze foldery dla zadań, które jeszcze ich nie mają">
      <i class="bi bi-folder-plus" aria-hidden="true"></i>Foldery dla zadań (<?= $tf_missing ?>)</button>
    <?php endif; ?>
    <?php if ($can_folder && $sp_ok): ?>
    <button type="button" class="tk-hbtn tk-hbtn--cta" data-bs-toggle="modal" data-bs-target="#newFolderModal">
      <i class="bi bi-folder-plus" aria-hidden="true"></i>Nowy folder</button>
    <?php endif; ?>
  </div>
</div>

<div class="tf-layout">

  <!-- ── Foldery: wspólne + foldery zadań ─────────────────────────────────────── -->
  <nav class="tf-side" aria-label="Obszary i foldery">
    <?php /* Wybór obszaru tylko po boku (bez przełącznika w górnym pasku) — te same kafelki co na liście zadań */ ?>
    <?php if (count($workspaces) > 1): ?>
    <div class="tf-side-h">Obszary</div>
    <ul class="tk-ws-side-list tf-ws-list">
      <?php foreach ($workspaces as $_w): $_won = (int)$_w['id'] === $ws_id; $_wc = (string)($_w['color'] ?: '#5f5f5f'); ?>
      <li>
        <a href="<?= APP_URL ?>/tasks/files.php?ws=<?= (int)$_w['id'] ?>" class="tk-ws-side-link<?= $_won ? ' active' : '' ?>"<?= $_won ? ' aria-current="page"' : '' ?>
           style="--ws:<?= h($_wc) ?>;--ws-ink:<?= task_ws_ink($_wc) ?>" title="<?= h($_w['name']) ?>">
          <span class="tk-ws-tile" aria-hidden="true"><i class="bi <?= h($_w['icon'] ?: 'bi-folder') ?>"></i></span>
          <span class="tk-ws-side-name"><?= h($_w['name']) ?></span>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php if (count($folders) > 8): ?>
    <div class="tf-side-search">
      <label class="visually-hidden" for="tfFolderFilter">Filtruj foldery</label>
      <input type="search" id="tfFolderFilter" placeholder="Filtruj foldery…" autocomplete="off">
    </div>
    <?php endif; ?>
    <?php if (!$folders): ?><p class="tf-side-none">Brak folderów.</p><?php endif; ?>
    <?php foreach ([['Foldery wspólne', $tf_shared, 'bi-folder-fill'], ['Foldery zadań', $tf_taskf, 'bi-check2-square']] as [$_gl, $_gf, $_gi]): ?>
    <?php if ($_gf): ?>
    <div class="tf-side-h"><?= h($_gl) ?><span class="tf-cnt"><?= count($_gf) ?></span></div>
    <ul class="tf-folder-group">
      <?php foreach ($_gf as $f):
        $_fid = (int)$f['id']; $_n = $tf_counts[$_fid] ?? 0;
        $_tk  = !empty($f['task_id']) ? ($tf_tasks[(int)$f['task_id']] ?? null) : null;
        $_done = $_tk && ($_tk['_done'] || !empty($_tk['deleted_at'])); ?>
      <li>
        <a href="?ws=<?= $ws_id ?>&folder=<?= $_fid ?>" class="tf-folder-link<?= $_fid === $active_folder_id ? ' active' : '' ?><?= $_done ? ' is-done' : '' ?>"
           <?= $_fid === $active_folder_id ? 'aria-current="page"' : '' ?> data-name="<?= h(mb_strtolower($f['name'])) ?>" title="<?= h($f['name']) ?>">
          <i class="bi <?= $_tk && $_tk['_done'] ? 'bi-check2-circle' : $_gi ?>" aria-hidden="true"></i>
          <span class="tf-folder-name"><?= h($f['name']) ?><?php if ($_done): ?><span class="visually-hidden"> (zadanie <?= !empty($_tk['deleted_at']) ? 'usunięte' : 'ukończone' ?>)</span><?php endif; ?></span>
          <span class="tf-cnt<?= $_n ? '' : ' is-zero' ?>"><span class="visually-hidden">, plików: </span><?= $_n ?></span>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php endforeach; ?>
  </nav>

  <!-- ── Zawartość folderu ─────────────────────────────────────────────────── -->
  <section class="tf-main" aria-label="Zawartość folderu">

    <?php if ($active_folder): ?>
    <div class="tf-card<?= ($can_upload && $sp_ok) ? ' tf-dropzone' : '' ?>" id="tfFolderCard">
      <div class="tf-card-head">
        <h2 class="tf-card-t"><i class="bi <?= $tf_active_task ? 'bi-check2-square' : 'bi-folder2-open' ?>" aria-hidden="true"></i><span><?= h($active_folder['name']) ?></span></h2>
        <span class="tf-muted" style="font-size:.82rem"><?= count($files) ?> <?= count($files) === 1 ? 'plik' : ((count($files) % 10 >= 2 && count($files) % 10 <= 4 && (count($files) % 100 < 12 || count($files) % 100 > 14)) ? 'pliki' : 'plików') ?></span>
        <div class="tf-card-act">
          <?php if (!empty($files)): ?>
          <div class="tf-seg" role="group" aria-label="Widok plików">
            <button type="button" class="tf-ibtn tf-view-toggle active" data-view="list" title="Widok listy" aria-label="Widok listy"><i class="bi bi-list-ul" aria-hidden="true"></i></button>
            <button type="button" class="tf-ibtn tf-view-toggle" data-view="grid" title="Widok galerii" aria-label="Widok galerii"><i class="bi bi-grid-3x3-gap" aria-hidden="true"></i></button>
          </div>
          <?php endif; ?>
          <?php $tf_cloud_link = ws_cloud_link($active_folder); if ($tf_cloud_link !== ''): ?>
          <a class="tf-ibtn" href="<?= h($tf_cloud_link) ?>" target="_blank" rel="noopener"
             title="Otwórz folder w Nextcloudzie (pliki, aplikacja na komputerze i telefonie)">
            <i class="bi bi-cloud" aria-hidden="true"></i>W Nextcloudzie<span class="visually-hidden"> (nowa karta)</span></a>
          <?php endif; ?>
          <?php if ($can_upload && $sp_ok): ?>
          <button type="button" class="tf-ibtn is-cta" onclick="document.getElementById('uploadInput').click()">
            <i class="bi bi-cloud-arrow-up" aria-hidden="true"></i>Wgraj</button>
          <input type="file" id="uploadInput" multiple hidden>
          <?php endif; ?>
          <?php if ($can_del_folder): ?>
          <button type="button" class="tf-ibtn is-danger" title="Usuń folder" aria-label="Usuń folder <?= h($active_folder['name']) ?>"
                  data-action="delete-folder" data-id="<?= $active_folder_id ?>"><i class="bi bi-trash3" aria-hidden="true"></i></button>
          <?php endif; ?>
        </div>
      </div>

      <?php if (!empty($active_folder['task_id'])): ?>
      <div class="tf-taskline">
        <span>Folder zadania</span>
        <?php if ($tf_active_task && empty($tf_active_task['deleted_at'])): ?>
        <span class="tf-st<?= $tf_active_task['_done'] ? ' is-done' : '' ?>"><?= $tf_active_task['_done'] ? 'ukończone' : h($tf_active_task['list_name'] ?: 'otwarte') ?></span>
        <a href="<?= APP_URL ?>/tasks/index.php?ws=<?= $ws_id ?>&task=<?= (int)$active_folder['task_id'] ?>" title="Pliki z tego folderu widać w oknie zadania">Otwórz zadanie <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        <?php else: ?>
        <span class="tf-st is-gone">zadanie usunięte</span>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <div id="uploadProgress" class="tf-progress" style="display:none" role="status" aria-live="polite">
        <div class="progress"><div class="progress-bar progress-bar-striped progress-bar-animated" id="uploadBar" style="width:0%;background:#007a00"></div></div>
        <small id="uploadStatus"></small>
      </div>

      <?php if ($can_upload && $sp_ok): ?>
      <div id="tfDropOverlay" class="tf-drop-overlay">
        <i class="bi bi-cloud-arrow-up-fill" aria-hidden="true"></i>
        <span>Upuść pliki, aby wgrać do folderu „<?= h($active_folder['name']) ?>”</span>
      </div>
      <?php endif; ?>

      <?php if (empty($files)): ?>
      <div class="tf-empty" id="tfEmptyState">
        <i class="bi bi-file-earmark" aria-hidden="true"></i>
        <span>Folder jest pusty.<?= ($can_upload && $sp_ok) ? ' Wgraj pierwszy plik albo przeciągnij go tutaj.' : '' ?></span>
      </div>
      <?php else: ?>
      <div class="table-responsive" id="tfListView">
        <table class="tf-table">
          <thead>
            <tr>
              <th style="width:2.5rem"><span class="visually-hidden">Typ</span></th>
              <th>Nazwa</th>
              <th class="d-none d-md-table-cell">Rozmiar</th>
              <th class="d-none d-lg-table-cell">Wgrał/a</th>
              <th class="d-none d-md-table-cell">Data</th>
              <th class="text-end"><span class="visually-hidden">Działania</span></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($files as $f):
            $ext  = strtolower(pathinfo($f['original_name'], PATHINFO_EXTENSION));
            $icon = match($ext) {
                'pdf'                         => 'bi-file-earmark-pdf text-danger',
                'doc','docx'                  => 'bi-file-earmark-word text-primary',
                'xls','xlsx'                  => 'bi-file-earmark-excel text-success',
                'ppt','pptx'                  => 'bi-file-earmark-ppt text-warning',
                'jpg','jpeg','png','gif','webp' => 'bi-file-earmark-image text-info',
                'zip','7z','tar','gz'         => 'bi-file-earmark-zip text-secondary',
                default                       => 'bi-file-earmark tf-muted',
            };
            $previewable = $can_dl && in_array($ext, ['pdf','jpg','jpeg','png','gif','webp']);
            $docPreview  = $can_dl && in_array($ext, ['doc','docx','xls','xlsx','ppt','pptx']);
            $fid        = (int)$f['id'];
            $task_count = $tf_links[$fid] ?? 0;
            $dl_url = APP_URL . '/workspaces/api.php?action=download&id=' . $fid . '&_csrf=' . urlencode(csrf_token());
            $pv_url = APP_URL . '/workspaces/api.php?action=preview&id='  . $fid . '&_csrf=' . urlencode(csrf_token());
          ?>
          <tr id="frow-<?= $fid ?>">
            <td><i class="bi <?= $icon ?> tf-file-icon" aria-hidden="true"></i></td>
            <td style="max-width:0;width:50%">
              <?php if ($previewable): ?>
              <button type="button" class="tf-fname btn-preview" data-url="<?= h($pv_url) ?>" data-ext="<?= h($ext) ?>"
                      data-name="<?= h($f['name']) ?>" title="Podgląd: <?= h($f['name']) ?>"><?= h($f['name']) ?></button>
              <?php elseif ($docPreview && $f['web_url'] && ($f['provider'] ?? '') === 'nextcloud'): ?>
              <a href="<?= h($f['web_url']) ?>" target="_blank" rel="noopener" class="tf-fname" title="Otwórz w chmurze: <?= h($f['name']) ?>"><?= h($f['name']) ?></a>
              <?php elseif ($can_dl): ?>
              <a href="<?= h($dl_url) ?>" class="tf-fname" title="Pobierz: <?= h($f['name']) ?>"><?= h($f['name']) ?></a>
              <?php else: ?>
              <span class="tf-fname" style="cursor:default"><?= h($f['name']) ?></span>
              <?php endif; ?>
              <button type="button" class="tf-links btn-file-tasks<?= $task_count ? ' has' : '' ?>" data-file-id="<?= $fid ?>"
                      title="<?= $task_count ? 'Pokaż zadania, do których przypięto plik' : 'Plik nie jest przypięty do zadań' ?>">
                <i class="bi bi-check2-square" aria-hidden="true"></i><?= $task_count ? 'w zadaniach: ' . $task_count : 'brak zadań' ?>
              </button>
            </td>
            <td class="d-none d-md-table-cell tf-muted"><?= ws_format_size((int)$f['file_size']) ?></td>
            <td class="d-none d-lg-table-cell tf-muted text-truncate" style="max-width:140px"><?= h($f['uploader_name'] ?? '—') ?></td>
            <td class="d-none d-md-table-cell tf-muted"><?= date('d.m.Y', strtotime($f['created_at'])) ?></td>
            <td>
              <div class="tf-acts">
                <?php if ($previewable): ?>
                <button type="button" class="tf-ibtn btn-preview" data-url="<?= h($pv_url) ?>" data-ext="<?= h($ext) ?>" data-name="<?= h($f['name']) ?>"
                        title="Podgląd" aria-label="Podgląd: <?= h($f['name']) ?>"><i class="bi bi-eye" aria-hidden="true"></i></button>
                <?php elseif ($docPreview && $f['web_url'] && ($f['provider'] ?? '') === 'nextcloud'): ?>
                <a href="<?= h($f['web_url']) ?>" target="_blank" rel="noopener" class="tf-ibtn" title="Otwórz w chmurze" aria-label="Otwórz w chmurze (nowa karta): <?= h($f['name']) ?>"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a>
                <?php endif; ?>
                <?php if ($can_dl): ?>
                <a href="<?= h($dl_url) ?>" class="tf-ibtn" title="Pobierz" aria-label="Pobierz: <?= h($f['name']) ?>"><i class="bi bi-download" aria-hidden="true"></i></a>
                <?php endif; ?>
                <?php if ($can_link): ?>
                <button type="button" class="tf-ibtn btn-link-task" data-id="<?= $fid ?>" data-name="<?= h($f['name']) ?>"
                        title="Przypnij do zadania" aria-label="Przypnij do zadania: <?= h($f['name']) ?>"><i class="bi bi-link-45deg" aria-hidden="true"></i></button>
                <?php endif; ?>
                <?php if (ws_can('delete_file', $ws_id, $uid, $f)): ?>
                <button type="button" class="tf-ibtn is-danger btn-delete-file" data-id="<?= $fid ?>"
                        title="Usuń plik" aria-label="Usuń plik: <?= h($f['name']) ?>"><i class="bi bi-trash3" aria-hidden="true"></i></button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <tr id="ftasks-<?= $fid ?>" class="file-tasks-row" style="display:none">
            <td colspan="6" style="background:#f7f7f7;padding:.4rem 1rem .6rem 3rem">
              <div class="file-tasks-content" id="ftasks-content-<?= $fid ?>">
                <span class="tf-muted small"><span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Wczytuję…</span>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Widok galerii (kafelki + miniatury dla obrazów) -->
      <div class="tf-file-grid" id="tfGridView" style="display:none">
        <?php foreach ($files as $f):
          $ext  = strtolower(pathinfo($f['original_name'], PATHINFO_EXTENSION));
          $icon = match($ext) {
              'pdf'                         => 'bi-file-earmark-pdf text-danger',
              'doc','docx'                  => 'bi-file-earmark-word text-primary',
              'xls','xlsx'                  => 'bi-file-earmark-excel text-success',
              'ppt','pptx'                  => 'bi-file-earmark-ppt text-warning',
              'jpg','jpeg','png','gif','webp' => 'bi-file-earmark-image text-info',
              'zip','7z','tar','gz'         => 'bi-file-earmark-zip text-secondary',
              default                       => 'bi-file-earmark',
          };
          $is_image    = $can_dl && in_array($ext, ['jpg','jpeg','png','gif','webp'], true);
          $previewable = $can_dl && in_array($ext, ['pdf','jpg','jpeg','png','gif','webp']);
          $docPreview  = $can_dl && in_array($ext, ['doc','docx','xls','xlsx','ppt','pptx']);
          $fid        = (int)$f['id'];
          $task_count = $tf_links[$fid] ?? 0;
          $dl_url = APP_URL . '/workspaces/api.php?action=download&id=' . $fid . '&_csrf=' . urlencode(csrf_token());
          $pv_url = APP_URL . '/workspaces/api.php?action=preview&id='  . $fid . '&_csrf=' . urlencode(csrf_token());
        ?>
        <div class="tf-grid-card" id="gcard-<?= $fid ?>">
          <div class="tf-grid-thumb">
            <?php if ($is_image): ?>
            <img src="<?= h($pv_url) ?>" alt="<?= h($f['name']) ?>" loading="lazy">
            <?php else: ?>
            <i class="bi <?= $icon ?>" aria-hidden="true"></i>
            <?php endif; ?>
            <div class="tf-grid-overlay">
              <?php if ($previewable): ?>
              <button type="button" class="tf-ibtn btn-preview" data-url="<?= h($pv_url) ?>" data-ext="<?= h($ext) ?>" data-name="<?= h($f['name']) ?>" aria-label="Podgląd: <?= h($f['name']) ?>"><i class="bi bi-eye" aria-hidden="true"></i></button>
              <?php elseif ($docPreview && $f['web_url'] && ($f['provider'] ?? '') === 'nextcloud'): ?>
              <a href="<?= h($f['web_url']) ?>" target="_blank" rel="noopener" class="tf-ibtn" aria-label="Otwórz w chmurze (nowa karta): <?= h($f['name']) ?>"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a>
              <?php endif; ?>
              <?php if ($can_dl): ?>
              <a href="<?= h($dl_url) ?>" class="tf-ibtn" aria-label="Pobierz: <?= h($f['name']) ?>"><i class="bi bi-download" aria-hidden="true"></i></a>
              <?php endif; ?>
              <?php if ($can_link): ?>
              <button type="button" class="tf-ibtn btn-link-task" data-id="<?= $fid ?>" data-name="<?= h($f['name']) ?>" aria-label="Przypnij do zadania: <?= h($f['name']) ?>"><i class="bi bi-link-45deg" aria-hidden="true"></i></button>
              <?php endif; ?>
              <?php if (ws_can('delete_file', $ws_id, $uid, $f)): ?>
              <button type="button" class="tf-ibtn is-danger btn-delete-file" data-id="<?= $fid ?>" aria-label="Usuń plik: <?= h($f['name']) ?>"><i class="bi bi-trash3" aria-hidden="true"></i></button>
              <?php endif; ?>
            </div>
            <?php if ($task_count): ?>
            <span class="tf-grid-taskbadge" title="Przypięty do zadań: <?= $task_count ?>"><i class="bi bi-check2-square" aria-hidden="true"></i> <?= $task_count ?></span>
            <?php endif; ?>
          </div>
          <div class="tf-grid-name" title="<?= h($f['name']) ?>"><?= h($f['name']) ?></div>
          <div class="tf-grid-meta"><?= ws_format_size((int)$f['file_size']) ?> · <?= date('d.m.Y', strtotime($f['created_at'])) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div><!-- /folder card -->

    <?php elseif (!empty($folders)): ?>
    <div class="tf-card"><div class="tf-empty"><i class="bi bi-arrow-left-circle" aria-hidden="true"></i><span>Wybierz folder z listy po lewej.</span></div></div>

    <?php else: ?>
    <div class="tf-card"><div class="tf-empty">
      <?php if ($auto_initialized): ?>
      <i class="bi bi-check-circle" aria-hidden="true" style="color:#007a00"></i>
      <span>Folder obszaru został utworzony w chmurze.<?= $auto_created > 0 ? ' Utworzono foldery dla ' . $auto_created . ' ' . ($auto_created === 1 ? 'zadania' : 'zadań') . '.' : '' ?></span>
      <?php else: ?>
      <i class="bi bi-folder2" aria-hidden="true"></i>
      <span>Brak folderów w tym obszarze.</span>
      <?php endif; ?>
      <?php if ($can_folder && $sp_ok): ?>
      <button type="button" class="tf-ibtn is-cta" data-bs-toggle="modal" data-bs-target="#newFolderModal"><i class="bi bi-folder-plus" aria-hidden="true"></i>Utwórz folder</button>
      <?php endif; ?>
    </div></div>
    <?php endif; ?>

  </section>
</div><!-- /.tf-layout -->

<?php $tf_tm_open = !empty($_GET['task']) || ($_GET['q'] ?? '') !== '' || !empty($_GET['kind']) || !empty($_GET['sort']); ?>
<details class="tf-more" id="tf-task-files-wrap"<?= $tf_tm_open ? ' open' : '' ?>>
  <summary><i class="bi bi-paperclip" aria-hidden="true"></i>Załączniki zadań <span class="tf-muted">— pliki dodane bezpośrednio do zadań (filtry, wgrywanie do wybranego zadania)</span></summary>
  <?php require __DIR__ . '/includes/files_task_manager.php'; ?>
</details>
<script>
// Wejście z okna zadania (#task-files) — rozwiń sekcję i przewiń do kotwicy menedżera załączników
if (location.hash === '#task-files') {
  var _tfm = document.getElementById('tf-task-files-wrap');
  if (_tfm) { _tfm.open = true; var _tfa = document.getElementById('task-files'); if (_tfa) _tfa.scrollIntoView(); }
}
</script>

</div><!-- /.tf-page -->

<!-- ══ Modals ══════════════════════════════════════════════════════════════ -->

<!-- Nowy folder -->
<?php if ($can_folder && $sp_ok): ?>
<div class="modal fade" id="newFolderModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title fw-semibold"><i class="bi bi-folder-plus me-1 text-primary"></i>Nowy folder</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body pb-2">
        <div id="newFolderAlert" class="alert alert-danger py-1 small" style="display:none"></div>
        <label class="form-label small fw-semibold mb-1">Nazwa folderu <span class="text-danger">*</span></label>
        <input type="text" id="newFolderName" class="form-control form-control-sm" placeholder="np. Dokumenty">
        <label class="form-label small fw-semibold mb-1 mt-2">Opis (opcjonalnie)</label>
        <input type="text" id="newFolderDesc" class="form-control form-control-sm" placeholder="Krótki opis">
      </div>
      <div class="modal-footer py-2">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Anuluj</button>
        <button class="btn btn-sm btn-primary" id="btnCreateFolder">
          <i class="bi bi-folder-plus me-1" aria-hidden="true"></i>Utwórz folder
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Przypisz do zadania -->
<div class="modal fade" id="linkTaskModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title fw-semibold"><i class="bi bi-link-45deg me-1 text-primary"></i>Przypisz plik do zadania</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body pb-2">
        <input type="hidden" id="linkFileId">
        <p class="small text-muted mb-2">Plik: <strong id="linkFileName"></strong></p>
        <div id="linkTaskAlert" class="alert alert-danger py-1 small" style="display:none"></div>
        <label class="form-label small fw-semibold mb-1">Zadanie</label>
        <select id="linkTaskSelect" class="form-select form-select-sm">
          <option value="">— wybierz zadanie —</option>
          <?php
          $ws_tasks = db_all(
              "SELECT t.id, t.title, tl.name AS list_name
               FROM tasks t
               LEFT JOIN task_lists tl ON tl.id = t.list_id
               WHERE t.workspace_id=? AND t.deleted_at IS NULL AND t.completed_at IS NULL
               ORDER BY t.title LIMIT 300",
              [$ws_id]
          );
          foreach ($ws_tasks as $wt):
          ?>
          <option value="<?= (int)$wt['id'] ?>">
            <?= h($wt['list_name'] ? '[' . $wt['list_name'] . '] ' : '') ?><?= h($wt['title']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="modal-footer py-2">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Anuluj</button>
        <button class="btn btn-sm btn-primary" id="btnLinkTask">
          <i class="bi bi-link-45deg me-1"></i>Przypisz
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Podgląd pliku (PDF/obraz) -->
<div class="modal fade" id="previewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title fw-semibold" id="previewModalTitle">
          <i class="bi bi-eye me-1 text-primary"></i>Podgląd
        </h6>
        <a id="previewDownloadBtn" href="#" class="btn btn-sm btn-outline-primary py-0 px-2 me-2">
          <i class="bi bi-download me-1"></i>Pobierz
        </a>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0" style="min-height:70vh">
        <div id="previewSpinner" class="d-flex align-items-center justify-content-center" style="min-height:70vh">
          <span class="spinner-border text-primary"></span>
        </div>
        <iframe id="previewIframe" src="about:blank" style="display:none;width:100%;height:75vh;border:0"></iframe>
        <div id="previewImgWrap" style="display:none;text-align:center;padding:1rem">
          <img id="previewImg" src="" alt="" style="max-width:100%;max-height:75vh;object-fit:contain">
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ══ Footer ═══════════════════════════════════════════════════════════ -->
<footer class="tsk-footer">
  <span><?= h(defined('ORG_NAME') ? ORG_NAME : '') ?> — Moduł Zadań</span>
  <span><i class="bi bi-folder2-open me-1"></i>Pliki</span>
</footer>

<script>
const CSRF      = <?= json_encode(csrf_token()) ?>;
const API       = <?= json_encode(APP_URL . '/workspaces/api.php') ?>;
const TASKS_URL = <?= json_encode(APP_URL . '/tasks/') ?>;
const WS_ID     = <?= $ws_id ?>;
const FOLDER_ID = <?= $active_folder_id ?: 'null' ?>;

// ── Helpers ───────────────────────────────────────────────────────────────
function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
async function wsApi(payload) {
  return fetch(API, {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({_csrf: CSRF, ...payload})
  }).then(r => r.json()).catch(() => ({ok: false, error: 'Błąd połączenia.'}));
}
function fileIcon(name) {
  const ext = (name.split('.').pop() || '').toLowerCase();
  if (['jpg','jpeg','png','gif','webp'].includes(ext)) return 'bi-file-earmark-image text-info';
  if (ext === 'pdf')                                   return 'bi-file-earmark-pdf text-danger';
  if (['doc','docx'].includes(ext))                    return 'bi-file-earmark-word text-primary';
  if (['xls','xlsx'].includes(ext))                    return 'bi-file-earmark-excel text-success';
  if (['zip','7z'].includes(ext))                      return 'bi-file-earmark-zip text-secondary';
  return 'bi-file-earmark text-muted';
}

// ── Podgląd pliku ─────────────────────────────────────────────────────────
const previewModal  = new bootstrap.Modal(document.getElementById('previewModal'));
const previewIframe = document.getElementById('previewIframe');
const previewImg    = document.getElementById('previewImg');
const previewImgW   = document.getElementById('previewImgWrap');
const previewSpinner = document.getElementById('previewSpinner');

document.querySelectorAll('.btn-preview').forEach(btn => {
  btn.addEventListener('click', () => {
    const url  = btn.dataset.url;
    const ext  = btn.dataset.ext;
    const name = btn.dataset.name;

    document.getElementById('previewModalTitle').textContent = name;
    document.getElementById('previewDownloadBtn').href = url.replace('action=preview', 'action=download');

    previewSpinner.style.display = '';
    previewIframe.style.display  = 'none';
    previewImgW.style.display    = 'none';
    previewIframe.src = 'about:blank';

    previewModal.show();

    const imgs = ['jpg','jpeg','png','gif','webp'];
    if (imgs.includes(ext)) {
      previewImg.onload = () => { previewSpinner.style.display = 'none'; previewImgW.style.display = ''; };
      previewImg.src = url;
    } else {
      previewIframe.onload = () => { previewSpinner.style.display = 'none'; previewIframe.style.display = ''; };
      previewIframe.src = url;
    }
  });
});

document.getElementById('previewModal').addEventListener('hidden.bs.modal', () => {
  previewIframe.src = 'about:blank';
  previewImg.src = '';
  previewImgW.style.display = 'none';
  previewIframe.style.display = 'none';
});

// ── Zadania powiązane z plikiem (expand row) ──────────────────────────────
async function loadFileTasksRow(fileId) {
  const el = document.getElementById(`ftasks-content-${fileId}`);
  if (!el) return;
  const r = await wsApi({action: 'tasks_for_file', file_id: +fileId});
  if (!r.ok) { el.innerHTML = `<span class="text-danger small">${r.error}</span>`; return; }
  if (!r.tasks?.length) {
    el.innerHTML = '<span class="text-muted small">Brak powiązanych zadań.</span>';
    return;
  }
  el.innerHTML = `
    <div class="d-flex flex-wrap gap-2 align-items-center">
      <span class="text-muted small me-1">Zadania:</span>
      ${r.tasks.map(t => `
        <span class="badge rounded-pill text-bg-secondary d-inline-flex align-items-center gap-1" style="font-size:.72rem">
          <a href="${TASKS_URL}?task=${t.id}&ws=${WS_ID}" class="text-white text-decoration-none" target="_blank">
            ${escHtml(t.title)}
          </a>
          <button type="button" class="btn-close btn-close-white flex-shrink-0" style="font-size:.45rem"
                  title="Odepnij" data-file-id="${fileId}" data-task-id="${t.id}"
                  onclick="unlinkFileTask(this)"></button>
        </span>`).join('')}
    </div>`;
}

window.unlinkFileTask = async function(btn) {
  if (!confirm('Odpiąć plik od tego zadania?')) return;
  const fileId = +btn.dataset.fileId, taskId = +btn.dataset.taskId;
  const r = await wsApi({action: 'unlink_task', file_id: fileId, task_id: taskId});
  if (r.ok) {
    await loadFileTasksRow(fileId);
    const badge = document.querySelector(`.btn-file-tasks[data-file-id="${fileId}"]`);
    if (badge) {
      const next = Math.max(0, (parseInt(badge.textContent.trim()) || 1) - 1);
      badge.innerHTML = `<i class="bi bi-check2-square me-1"></i>${next}`;
      if (next === 0) badge.className = badge.className.replace('text-bg-info','text-bg-light text-muted');
    }
  } else alert(r.error);
};

document.querySelectorAll('.btn-file-tasks').forEach(btn => {
  btn.addEventListener('click', async () => {
    const fid = btn.dataset.fileId;
    const row = document.getElementById(`ftasks-${fid}`);
    if (!row) return;
    const open = row.style.display !== 'none';
    row.style.display = open ? 'none' : '';
    if (!open) await loadFileTasksRow(fid);
  });
});

// ── Nowy folder ───────────────────────────────────────────────────────────
document.getElementById('btnCreateFolder')?.addEventListener('click', async () => {
  const name = document.getElementById('newFolderName').value.trim();
  const desc = document.getElementById('newFolderDesc').value.trim();
  const alertEl = document.getElementById('newFolderAlert');
  alertEl.style.display = 'none';
  if (!name) { alertEl.textContent = 'Podaj nazwę folderu.'; alertEl.style.display = ''; return; }
  const btn = document.getElementById('btnCreateFolder');
  btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Tworzę…';
  const r = await wsApi({action: 'create_folder', workspace_id: WS_ID, name, description: desc});
  btn.disabled = false; btn.innerHTML = '<i class="bi bi-folder-plus me-1" aria-hidden="true"></i>Utwórz folder';
  if (r.ok) window.location.href = `?ws=${WS_ID}&folder=${r.folder.id}`;
  else { alertEl.textContent = r.error || 'Błąd.'; alertEl.style.display = ''; }
});

// ── Upload (współdzielone: input plików + drag&drop) ───────────────────────
async function uploadFilesToFolder(fileList) {
  if (!FOLDER_ID || !fileList.length) return;
  const progress = document.getElementById('uploadProgress');
  const bar = document.getElementById('uploadBar');
  const status = document.getElementById('uploadStatus');
  for (const file of fileList) {
    progress.style.display = '';
    bar.style.width = '0%';
    bar.classList.remove('progress-bar-animated');
    bar.classList.add('progress-bar-animated');
    bar.classList.replace('bg-danger','bg-primary');
    status.textContent = `Wgrywam: ${file.name}…`;
    const fd = new FormData();
    fd.append('_csrf', CSRF);
    fd.append('action', 'upload_file');
    fd.append('folder_id', FOLDER_ID);
    fd.append('file', file);
    const xhr = new XMLHttpRequest();
    xhr.upload.addEventListener('progress', e => {
      if (e.lengthComputable) bar.style.width = Math.round(e.loaded / e.total * 100) + '%';
    });
    await new Promise(resolve => {
      xhr.onload = () => {
        const r = JSON.parse(xhr.responseText || '{}');
        if (r.ok) {
          status.textContent = `✓ ${file.name} wgrano pomyślnie`;
          bar.classList.remove('progress-bar-animated');
          bar.style.width = '100%';
          setTimeout(() => window.location.reload(), 800);
        } else {
          status.textContent = `✗ ${r.error || 'Błąd wgrywania.'}`;
          bar.classList.replace('bg-primary','bg-danger');
        }
        resolve();
      };
      xhr.open('POST', API); xhr.send(fd);
    });
  }
}

document.getElementById('uploadInput')?.addEventListener('change', function() {
  uploadFilesToFolder(this.files);
});

// ── Drag & drop wgrywanie ────────────────────────────────────────────────
(function () {
  const zone = document.getElementById('tfFolderCard');
  if (!zone || !zone.classList.contains('tf-dropzone')) return;
  let dragDepth = 0;

  zone.addEventListener('dragenter', e => {
    e.preventDefault();
    dragDepth++;
    zone.classList.add('tf-drop-active');
  });
  zone.addEventListener('dragover', e => e.preventDefault());
  zone.addEventListener('dragleave', () => {
    dragDepth = Math.max(0, dragDepth - 1);
    if (dragDepth === 0) zone.classList.remove('tf-drop-active');
  });
  zone.addEventListener('drop', e => {
    e.preventDefault();
    dragDepth = 0;
    zone.classList.remove('tf-drop-active');
    const files = e.dataTransfer?.files;
    if (files && files.length) uploadFilesToFolder(files);
  });
})();

// ── Przełącznik widoku Lista / Galeria (localStorage per przeglądarkę) ────
(function () {
  const listView = document.getElementById('tfListView');
  const gridView = document.getElementById('tfGridView');
  const toggles  = document.querySelectorAll('.tf-view-toggle');
  if (!listView || !gridView || !toggles.length) return;

  function setView(view) {
    listView.style.display = view === 'grid' ? 'none' : '';
    gridView.style.display = view === 'grid' ? '' : 'none';
    toggles.forEach(b => b.classList.toggle('active', b.dataset.view === view));
    try { localStorage.setItem('tfFileView', view); } catch (e) {}
  }

  toggles.forEach(btn => btn.addEventListener('click', () => setView(btn.dataset.view)));

  let saved = 'list';
  try { saved = localStorage.getItem('tfFileView') || 'list'; } catch (e) {}
  setView(saved);
})();

// ── Usuń plik ─────────────────────────────────────────────────────────────
document.querySelectorAll('.btn-delete-file').forEach(btn => {
  btn.addEventListener('click', async () => {
    if (!confirm('Usunąć plik?')) return;
    const r = await wsApi({action: 'delete_file', id: +btn.dataset.id});
    if (r.ok) {
      const fileId = btn.dataset.id;
      document.getElementById(`ftasks-${fileId}`)?.remove();
      btn.closest('tr')?.remove();
    } else alert(r.error || 'Błąd usuwania.');
  });
});

// ── Przypisz do zadania (modal) ───────────────────────────────────────────
document.querySelectorAll('.btn-link-task').forEach(btn => {
  btn.addEventListener('click', () => {
    document.getElementById('linkFileId').value = btn.dataset.id;
    document.getElementById('linkFileName').textContent = btn.dataset.name;
    document.getElementById('linkTaskAlert').style.display = 'none';
    document.getElementById('linkTaskSelect').value = '';
    new bootstrap.Modal(document.getElementById('linkTaskModal')).show();
  });
});
document.getElementById('btnLinkTask')?.addEventListener('click', async () => {
  const file_id = +document.getElementById('linkFileId').value;
  const task_id = +document.getElementById('linkTaskSelect').value;
  const alertEl = document.getElementById('linkTaskAlert');
  alertEl.style.display = 'none';
  if (!task_id) { alertEl.textContent = 'Wybierz zadanie.'; alertEl.style.display = ''; return; }
  const r = await wsApi({action: 'link_task', file_id, task_id});
  if (r.ok) bootstrap.Modal.getInstance(document.getElementById('linkTaskModal')).hide();
  else { alertEl.textContent = r.error || 'Błąd.'; alertEl.style.display = ''; }
});

// ── Usuń folder ───────────────────────────────────────────────────────────
document.querySelectorAll('[data-action="delete-folder"]').forEach(btn => {
  btn.addEventListener('click', async () => {
    if (!confirm('Usunąć folder z listy? Folder i pliki zostaną w chmurze.')) return;
    const r = await wsApi({action: 'delete_folder', id: +btn.dataset.id});
    if (r.ok) window.location.href = `?ws=${WS_ID}`;
    else alert(r.error || 'Błąd.');
  });
});

// ── Usuń załącznik zadania (lokalny, uploads/tasks/ — tasks/api/upload.php) ──
document.querySelectorAll('.btn-delete-task-attach').forEach(btn => {
  btn.addEventListener('click', async () => {
    if (!confirm('Usunąć załącznik zadania?')) return;
    const fileId = +btn.dataset.id, taskId = +btn.dataset.taskId;
    try {
      const r = await fetch(<?= json_encode(APP_URL . '/tasks/api/upload.php') ?>, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({_csrf: CSRF, action: 'delete', file_id: fileId, task_id: taskId})
      }).then(res => res.json());
      if (r.ok) document.getElementById(`tattach-${fileId}`)?.remove();
      else alert(r.error || 'Błąd usuwania.');
    } catch (e) { alert('Błąd połączenia.'); }
  });
});

// ── Filtr folderów w lewej kolumnie ───────────────────────────────────────
(function () {
  const inp = document.getElementById('tfFolderFilter');
  if (!inp) return;
  inp.addEventListener('input', () => {
    const q = inp.value.trim().toLowerCase();
    document.querySelectorAll('.tf-folder-group').forEach(ul => {
      let shown = 0;
      ul.querySelectorAll('.tf-folder-link').forEach(a => {
        const on = !q || (a.dataset.name || '').includes(q);
        a.parentElement.hidden = !on; if (on) shown++;
      });
      ul.hidden = shown === 0;
    });
  });
})();

// ── Foldery dla zadań bez folderu (lider/admin) ───────────────────────────
document.getElementById('tfInitTaskFolders')?.addEventListener('click', async function () {
  if (!confirm('Utworzyć w chmurze foldery dla zadań, które jeszcze ich nie mają?')) return;
  this.disabled = true;
  this.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Tworzę foldery…';
  const r = await wsApi({action: 'init_ws_folders', workspace_id: WS_ID});
  if (r.ok) location.reload();
  else { alert(r.error || 'Nie udało się utworzyć folderów.'); this.disabled = false; this.textContent = 'Foldery dla zadań'; }
});
</script>

<?php
include __DIR__ . '/includes/footer_tasks.php';
?>
