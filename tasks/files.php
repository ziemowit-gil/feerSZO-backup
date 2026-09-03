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

<style>
:root {
  --tk-focus:   #2563eb;
  --tk-border:  #e2e8f0;
  --tk-bg-soft: #f8fafc;
  --tk-text:    #0f172a;
  --tk-muted:   #64748b;
  --tk-radius:  .5rem;
}
.tf-toolbar {
  display: flex; flex-wrap: wrap; gap: .5rem; align-items: center;
  background: #fff; border: 1px solid var(--tk-border);
  border-radius: var(--tk-radius); padding: .55rem .75rem;
  margin-bottom: .85rem;
}
.tf-view-btn {
  display: inline-flex; align-items: center; gap: .3rem;
  padding: .22rem .65rem; border-radius: 2rem; font-size: .77rem; font-weight: 600;
  border: 1.5px solid transparent; text-decoration: none;
  background: #f1f5f9; color: var(--tk-muted); transition: all .12s; white-space: nowrap;
}
.tf-view-btn.active { background: var(--tsk-green, #059669); color: #fff; border-color: var(--tsk-green, #059669); }
.tf-view-btn:hover:not(.active) { border-color: #94a3b8; }
.tf-sep { width: 1px; height: 1.3rem; background: #e2e8f0; flex-shrink: 0; }
.tf-wrap { background: #fff; border: 1px solid var(--tk-border); border-radius: var(--tk-radius); overflow: hidden; }
.tf-file-icon { font-size: 1.1rem; }

.tf-ws-card {
  display: flex; align-items: center; gap: .75rem;
  padding: .85rem 1rem;
  background: #fff; border: 1px solid var(--tk-border);
  border-radius: var(--tk-radius);
  text-decoration: none; color: inherit;
  transition: box-shadow .12s, border-color .12s;
  height: 100%;
}
.tf-ws-card:hover { box-shadow: 0 2px 10px rgba(0,0,0,.09); border-color: #94a3b8; color: inherit; }
.tf-ws-card-icon {
  width: 2.5rem; height: 2.5rem; border-radius: .5rem;
  display: flex; align-items: center; justify-content: center;
  color: #fff; font-size: 1.15rem; flex-shrink: 0;
}
.tf-ws-empty {
  border: 2px dashed var(--tk-border); background: var(--tk-bg-soft);
  border-radius: var(--tk-radius); padding: 3rem 1rem;
  text-align: center; color: var(--tk-muted);
}
</style>

<main id="tsk-main" class="py-3 px-3 px-md-4 px-lg-5">
<?php require_once dirname(dirname(__DIR__)) . '/includes/banner_rewrite.php' ?>

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
              title="Utwórz foldery dla zadań w SharePoint"
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
          <i class="bi bi-folder-plus me-1"></i>Utwórz w SharePoint
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
  btn.disabled = false; btn.innerHTML = '<i class="bi bi-folder-plus me-1"></i>Utwórz w SharePoint';
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
include dirname(__DIR__) . '/includes/footer.php';
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

$can_upload = ws_can_upload($ws_id, $uid);
$can_manage = ws_can_manage($ws_id, $uid);
$folders    = ws_list_folders($ws_id);
$sp_ok      = ws_available();
$role       = ws_user_role($ws_id, $uid);

// Auto-inicjuj strukturę SP gdy obszar jest pusty i użytkownik ma uprawnienia
$auto_initialized = false;
$auto_created     = 0;
if (empty($folders) && $can_manage && $sp_ok) {
    try {
        ws_sp_init_workspace($ws_id);
        $auto_created     = ws_init_task_folders($ws_id, $uid);
        $folders          = ws_list_folders($ws_id);
        $auto_initialized = true;
    } catch (\Throwable $e) {
        error_log('[files.php auto-init] ws=' . $ws_id . ' ' . $e->getMessage());
    }
}

$active_folder_id = (int)($_GET['folder'] ?? ($folders[0]['id'] ?? 0));
$active_folder    = null;
$files            = [];
if ($active_folder_id) {
    $active_folder = ws_get_folder($active_folder_id);
    if ($active_folder && (int)$active_folder['workspace_id'] === $ws_id) {
        $files = ws_list_files($active_folder_id);
    }
}

$PAGE_TITLE       = h($workspace['name']) . ' — Pliki';
$PAGE_SUBTITLE    = 'Pliki';
$TASKS_BREADCRUMB = h($workspace['name']);
$TASKS_WS_ID      = $ws_id;
$TASKS_FILES_VIEW = true;  // sygnał dla header_tasks.php: switcher WS → files.php
require_once __DIR__ . '/includes/header_tasks.php';
?>

<style>
/* ── Tokeny wspólne z tasks/index.php ─────────────────────────────────── */
:root {
  --tk-focus:   #2563eb;
  --tk-border:  #e2e8f0;
  --tk-bg-soft: #f8fafc;
  --tk-text:    #0f172a;
  --tk-muted:   #64748b;
  --tk-radius:  .5rem;
}

.tf-toolbar {
  display: flex; flex-wrap: wrap; gap: .5rem; align-items: center;
  background: #fff; border: 1px solid var(--tk-border);
  border-radius: var(--tk-radius); padding: .55rem .75rem;
  margin-bottom: .85rem;
}
.tf-view-btn {
  display: inline-flex; align-items: center; gap: .3rem;
  padding: .22rem .65rem; border-radius: 2rem; font-size: .77rem; font-weight: 600;
  border: 1.5px solid transparent; text-decoration: none;
  background: #f1f5f9; color: var(--tk-muted); transition: all .12s; white-space: nowrap;
}
.tf-view-btn.active { background: var(--tsk-green, #059669); color: #fff; border-color: var(--tsk-green, #059669); }
.tf-view-btn:hover:not(.active) { border-color: #94a3b8; }
.tf-sep { width: 1px; height: 1.3rem; background: #e2e8f0; flex-shrink: 0; }
.tf-wrap { background: #fff; border: 1px solid var(--tk-border); border-radius: var(--tk-radius); overflow: hidden; }
.tf-folder-link {
  display: flex; align-items: center; gap: .5rem;
  padding: .45rem .9rem; font-size: .85rem; text-decoration: none; color: #374151;
  border-left: 3px solid transparent;
  transition: background .1s, color .1s, border-color .1s;
}
.tf-folder-link:hover { background: #f8fafc; color: var(--tsk-green, #059669); border-left-color: #e2e8f0; }
.tf-folder-link.active {
  background: var(--tsk-green-bg, #ecfdf5); color: var(--tsk-green, #059669);
  font-weight: 600; border-left-color: var(--tsk-green, #059669);
}
.tf-file-icon { font-size: 1.1rem; }
</style>

<main id="tsk-main" class="py-3 px-3 px-md-4 px-lg-5">
<?php require_once dirname(dirname(__DIR__)) . '/includes/banner_rewrite.php' ?>

<!-- Pasek narzędzi — widoki + breadcrumb -->
<div class="tf-toolbar">
  <!-- Przełącznik widoków: Zadania | Kanban | Pliki (aktywny) -->
  <a href="<?= APP_URL ?>/tasks/index.php?ws=<?= $ws_id ?>&view=list" class="tf-view-btn">
    <i class="bi bi-list-ul"></i>Lista
  </a>
  <a href="<?= APP_URL ?>/tasks/index.php?ws=<?= $ws_id ?>&view=kanban" class="tf-view-btn">
    <i class="bi bi-kanban"></i>Kanban
  </a>
  <a href="<?= APP_URL ?>/tasks/files.php?ws=<?= $ws_id ?>" class="tf-view-btn active">
    <i class="bi bi-folder2-open"></i>Pliki
  </a>

  <div class="tf-sep" aria-hidden="true"></div>

  <!-- Rola użytkownika -->
  <span class="badge rounded-pill text-bg-secondary" style="font-size:.7rem"><?= ucfirst($role ?: 'viewer') ?></span>

  <!-- Prawostronnie: dodaj folder -->
  <?php if ($can_manage && $sp_ok): ?>
  <button class="btn btn-sm btn-outline-primary py-0 px-2 ms-auto"
          data-bs-toggle="modal" data-bs-target="#newFolderModal" style="font-size:.8rem">
    <i class="bi bi-folder-plus me-1"></i>Nowy folder
  </button>
  <?php else: ?>
  <div class="ms-auto"></div>
  <?php endif; ?>
</div>

<div class="row g-3">

  <!-- ── Sidebar: Foldery ───────────────────────────────────────────────── -->
  <div class="col-12 col-lg-3">
    <div class="tf-wrap">
      <div class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom"
           style="font-size:.8rem; font-weight:700; color:var(--tk-muted); text-transform:uppercase; letter-spacing:.04em">
        <span><i class="bi bi-folder me-1"></i>Foldery</span>
        <span class="badge text-bg-secondary rounded-pill" style="font-size:.6rem"><?= count($folders) ?></span>
      </div>

      <?php if (empty($folders)): ?>
      <div class="text-center text-muted py-4 px-3" style="font-size:.8rem">
        <i class="bi bi-folder2 d-block mb-1" style="font-size:1.5rem; opacity:.3"></i>
        Brak folderów
        <?php if ($can_manage && $sp_ok): ?>
        <div class="mt-2">
          <button class="btn btn-sm btn-outline-primary py-0 px-2" data-bs-toggle="modal" data-bs-target="#newFolderModal">
            <i class="bi bi-folder-plus me-1"></i>Utwórz folder
          </button>
        </div>
        <?php endif; ?>
      </div>
      <?php else: ?>
      <nav aria-label="Foldery workspace">
        <?php foreach ($folders as $f): ?>
        <a href="?ws=<?= $ws_id ?>&folder=<?= (int)$f['id'] ?>"
           class="tf-folder-link <?= (int)$f['id'] === $active_folder_id ? 'active' : '' ?>">
          <i class="bi bi-folder-fill flex-shrink-0" style="color:<?= h($workspace['color'] ?: '#3b82f6') ?>"></i>
          <span class="text-truncate flex-grow-1"><?= h($f['name']) ?></span>
        </a>
        <?php endforeach; ?>
      </nav>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Główna kolumna: pliki ──────────────────────────────────────────── -->
  <div class="col-12 col-lg-9">

    <?php if ($active_folder): ?>
    <!-- Nagłówek folderu -->
    <div class="tf-wrap mb-3">
      <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom">
        <i class="bi bi-folder2-open" style="color:<?= h($workspace['color'] ?: '#3b82f6') ?>"></i>
        <span class="fw-semibold flex-grow-1" style="font-size:.9rem"><?= h($active_folder['name']) ?></span>

        <?php if ($can_upload && $sp_ok): ?>
        <button class="btn btn-sm btn-primary py-0 px-2" onclick="document.getElementById('uploadInput').click()">
          <i class="bi bi-upload me-1"></i>Wgraj
        </button>
        <input type="file" id="uploadInput" multiple style="display:none">
        <?php endif; ?>

        <?php if ($can_manage): ?>
        <button class="btn btn-sm btn-outline-danger py-0 px-2"
                title="Usuń folder" data-action="delete-folder" data-id="<?= $active_folder_id ?>">
          <i class="bi bi-trash3"></i>
        </button>
        <?php endif; ?>
      </div>

      <!-- Progress upload -->
      <div id="uploadProgress" style="display:none" class="px-3 py-1">
        <div class="progress" style="height:4px">
          <div class="progress-bar progress-bar-striped progress-bar-animated" id="uploadBar" style="width:0%"></div>
        </div>
        <small class="text-muted" id="uploadStatus"></small>
      </div>

      <?php if (empty($files)): ?>
      <div class="text-center text-muted py-5" style="font-size:.88rem">
        <i class="bi bi-file-earmark d-block mb-2" style="font-size:2rem; opacity:.3"></i>
        Folder jest pusty. Wgraj pierwszy plik.
      </div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 tk-table" style="font-size:.83rem">
          <thead class="table-light">
            <tr>
              <th style="width:2rem" class="ps-3"></th>
              <th>Nazwa</th>
              <th class="d-none d-md-table-cell">Rozmiar</th>
              <th class="d-none d-lg-table-cell">Wgrał/a</th>
              <th class="d-none d-md-table-cell">Data</th>
              <th class="text-end pe-3">Akcje</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($files as $f):
            $ext  = strtolower(pathinfo($f['original_name'], PATHINFO_EXTENSION));
            $icon = match($ext) {
                'pdf'            => 'bi-file-earmark-pdf text-danger',
                'doc','docx'     => 'bi-file-earmark-word text-primary',
                'xls','xlsx'     => 'bi-file-earmark-excel text-success',
                'ppt','pptx'     => 'bi-file-earmark-ppt text-warning',
                'jpg','jpeg','png',
                'gif','webp'     => 'bi-file-earmark-image text-info',
                'zip','7z','tar',
                'gz'             => 'bi-file-earmark-zip text-secondary',
                default          => 'bi-file-earmark text-muted',
            };
            $previewable = in_array($ext, ['pdf','jpg','jpeg','png','gif','webp']);
            $docPreview  = in_array($ext, ['doc','docx','xls','xlsx','ppt','pptx']);
            $task_count  = (int)(db_one(
                "SELECT COUNT(*) AS n FROM ws_task_files WHERE file_id=?", [$f['id']]
            )['n'] ?? 0);
            $fid = (int)$f['id'];
            $dl_url = APP_URL . '/workspaces/api.php?action=download&id=' . $fid . '&_csrf=' . urlencode(csrf_token());
            $pv_url = APP_URL . '/workspaces/api.php?action=preview&id='  . $fid . '&_csrf=' . urlencode(csrf_token());
          ?>
          <tr id="frow-<?= $fid ?>">
            <td class="ps-3"><i class="bi <?= $icon ?> tf-file-icon"></i></td>
            <td class="text-truncate" style="max-width:220px">
              <?php if ($previewable): ?>
              <button class="btn btn-link p-0 text-body text-truncate btn-preview"
                      style="font-size:inherit;text-decoration:none;max-width:200px;vertical-align:baseline"
                      data-url="<?= h($pv_url) ?>" data-ext="<?= h($ext) ?>"
                      data-name="<?= h($f['name']) ?>" title="<?= h($f['name']) ?>">
                <?= h($f['name']) ?>
              </button>
              <?php elseif ($docPreview && $f['web_url']): ?>
              <a href="<?= h($f['web_url']) ?>" target="_blank" rel="noopener"
                 class="text-body text-decoration-none" title="Otwórz w Office Online">
                <?= h($f['name']) ?>
              </a>
              <?php else: ?>
              <a href="<?= h($dl_url) ?>" class="text-body text-decoration-none"
                 title="Pobierz <?= h($f['name']) ?>">
                <?= h($f['name']) ?>
              </a>
              <?php endif; ?>
              <button class="badge rounded-pill border-0 ms-1 btn-file-tasks
                             <?= $task_count ? 'text-bg-info' : 'text-bg-light text-muted' ?>"
                      style="font-size:.6rem; cursor:pointer"
                      data-file-id="<?= $fid ?>"
                      title="<?= $task_count ? 'Pokaż powiązane zadania' : 'Brak zadań — przypisz' ?>">
                <i class="bi bi-check2-square me-1"></i><?= $task_count ?>
              </button>
            </td>
            <td class="d-none d-md-table-cell text-muted"><?= ws_format_size((int)$f['file_size']) ?></td>
            <td class="d-none d-lg-table-cell text-muted text-truncate" style="max-width:120px">
              <?= h($f['uploader_name'] ?? '—') ?>
            </td>
            <td class="d-none d-md-table-cell text-muted">
              <?= date('d.m.Y', strtotime($f['created_at'])) ?>
            </td>
            <td class="text-end pe-2">
              <div class="d-flex gap-1 justify-content-end">
                <?php if ($previewable): ?>
                <button class="btn btn-sm btn-outline-secondary py-0 px-2 btn-preview"
                        data-url="<?= h($pv_url) ?>" data-ext="<?= h($ext) ?>"
                        data-name="<?= h($f['name']) ?>" title="Podgląd">
                  <i class="bi bi-eye"></i>
                </button>
                <?php elseif ($docPreview && $f['web_url']): ?>
                <a href="<?= h($f['web_url']) ?>" target="_blank" rel="noopener"
                   class="btn btn-sm btn-outline-secondary py-0 px-2" title="Otwórz w Office Online">
                  <i class="bi bi-box-arrow-up-right"></i>
                </a>
                <?php endif; ?>
                <a href="<?= h($dl_url) ?>"
                   class="btn btn-sm btn-outline-primary py-0 px-2" title="Pobierz">
                  <i class="bi bi-download"></i>
                </a>
                <button class="btn btn-sm btn-outline-secondary py-0 px-2 btn-link-task"
                        data-id="<?= $fid ?>" data-name="<?= h($f['name']) ?>"
                        title="Przypisz do zadania">
                  <i class="bi bi-link-45deg"></i>
                </button>
                <?php if ($can_manage): ?>
                <button class="btn btn-sm btn-outline-danger py-0 px-2 btn-delete-file"
                        data-id="<?= $fid ?>" title="Usuń plik">
                  <i class="bi bi-trash3"></i>
                </button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <!-- Detail row: zadania powiązane z plikiem -->
          <tr id="ftasks-<?= $fid ?>" class="file-tasks-row" style="display:none">
            <td colspan="6" class="p-0 ps-5 pe-3 pb-2" style="background:var(--tsk-bg); border-top:none">
              <div class="file-tasks-content py-2" id="ftasks-content-<?= $fid ?>">
                <span class="text-muted small">
                  <span class="spinner-border spinner-border-sm me-1"></span>Wczytuję…
                </span>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div><!-- /folder card -->

    <?php elseif (!empty($folders)): ?>
    <div class="tf-wrap py-5 text-center text-muted">
      <i class="bi bi-arrow-left-circle d-block mb-2" style="font-size:2rem; opacity:.3"></i>
      Wybierz folder z listy po lewej.
    </div>

    <?php else: ?>
    <?php if ($auto_initialized): ?>
    <div class="tf-wrap py-5 text-center text-muted">
      <i class="bi bi-check-circle d-block mb-2 text-success" style="font-size:2.5rem"></i>
      <p class="mb-1">Folder obszaru został utworzony w SharePoint.</p>
      <?php if ($auto_created > 0): ?>
      <p class="small mb-2">Zainicjalizowano <?= $auto_created ?> <?= $auto_created === 1 ? 'folder zadania' : ($auto_created < 5 ? 'foldery zadań' : 'folderów zadań') ?>.</p>
      <?php endif; ?>
      <?php if ($can_manage): ?>
      <button class="btn btn-sm btn-primary mt-1" data-bs-toggle="modal" data-bs-target="#newFolderModal">
        <i class="bi bi-folder-plus me-1"></i>Utwórz folder
      </button>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="tf-wrap py-5 text-center text-muted">
      <i class="bi bi-folder2 d-block mb-2" style="font-size:2.5rem; opacity:.3"></i>
      <p>Brak folderów w tym obszarze.</p>
      <?php if ($can_manage && $sp_ok): ?>
      <button class="btn btn-sm btn-primary mt-1" data-bs-toggle="modal" data-bs-target="#newFolderModal">
        <i class="bi bi-folder-plus me-1"></i>Utwórz pierwszy folder
      </button>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>

  </div><!-- /col-lg-9 -->
</div><!-- /row -->

</main>

<!-- ══ Modals ══════════════════════════════════════════════════════════════ -->

<!-- Nowy folder -->
<?php if ($can_manage && $sp_ok): ?>
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
          <i class="bi bi-folder-plus me-1"></i>Utwórz w SharePoint
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
  btn.disabled = false; btn.innerHTML = '<i class="bi bi-folder-plus me-1"></i>Utwórz w SharePoint';
  if (r.ok) window.location.href = `?ws=${WS_ID}&folder=${r.folder.id}`;
  else { alertEl.textContent = r.error || 'Błąd.'; alertEl.style.display = ''; }
});

// ── Upload ────────────────────────────────────────────────────────────────
document.getElementById('uploadInput')?.addEventListener('change', async function() {
  if (!FOLDER_ID || !this.files.length) return;
  const progress = document.getElementById('uploadProgress');
  const bar = document.getElementById('uploadBar');
  const status = document.getElementById('uploadStatus');
  for (const file of this.files) {
    progress.style.display = '';
    bar.style.width = '0%';
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
});

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
    if (!confirm('Usunąć folder? Pliki pozostaną w SharePoint.')) return;
    const r = await wsApi({action: 'delete_folder', id: +btn.dataset.id});
    if (r.ok) window.location.href = `?ws=${WS_ID}`;
    else alert(r.error || 'Błąd.');
  });
});
</script>

<?php
function _tasks_files_footer(): void {}
include dirname(__DIR__) . '/includes/footer.php';
?>
