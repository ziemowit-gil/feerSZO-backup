<?php
/**
 * workspaces/view.php — Widok koszulek workspace z dwoma panelami:
 *   Tab A: Menedżer plików (foldery + upload + pobieranie)
 *   Tab B: Zadania powiązane z workspace
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/workspaces.php';

require_login();
require_module_enabled('tasks_enabled', 'Moduł zadań (wymagany dla Koszulek)');

$ws_id = (int)($_GET['ws'] ?? 0);
if (!$ws_id) { header('Location: ' . APP_URL . '/workspaces/index.php'); exit; }

ws_require_access($ws_id);

$workspace   = db_one("SELECT * FROM task_workspaces WHERE id = ? AND is_active = 1", [$ws_id]);
if (!$workspace) { http_response_code(404); echo '<p>Nie znaleziono workspace.</p>'; exit; }

$user        = current_user();
$role        = ws_user_role($ws_id, $user['id']);
$can_upload  = ws_can_upload($ws_id, $user['id']);
$can_manage  = ws_can_manage($ws_id, $user['id']);
$folders     = ws_list_folders($ws_id);
$sp_ok       = ws_available();

// Aktywny folder (URL ?folder=ID)
$active_folder_id = (int)($_GET['folder'] ?? ($folders[0]['id'] ?? 0));
$active_folder    = null;
$files            = [];
if ($active_folder_id) {
    $active_folder = ws_get_folder($active_folder_id);
    if ($active_folder && (int)$active_folder['workspace_id'] === $ws_id) {
        $files = ws_list_files($active_folder_id);
    }
}

// Zadania workspace (tylko aktywne + nie-usunięte)
$tasks = db_all(
    "SELECT t.id, t.title, t.due_date, t.priority,
            tl.name AS list_name, tl.is_done_state,
            (SELECT COUNT(*) FROM ws_task_files tf WHERE tf.task_id = t.id) AS linked_files
     FROM tasks t
     LEFT JOIN task_lists tl ON tl.id = t.list_id
     WHERE t.workspace_id = ? AND t.deleted_at IS NULL AND t.archived_at IS NULL
     ORDER BY t.due_date ASC NULLS LAST, t.id DESC
     LIMIT 200",
    [$ws_id]
);

$active_tab = $_GET['tab'] ?? 'files';
$color      = $workspace['color'] ?: '#3b82f6';

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="container-fluid px-3 px-md-4">

  <!-- Breadcrumb + nagłówek -->
  <nav aria-label="breadcrumb" style="font-size:.82rem" class="mb-2">
    <ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="<?= APP_URL ?>/workspaces/index.php">Koszulki</a></li>
      <li class="breadcrumb-item active"><?= h($workspace['name']) ?></li>
    </ol>
  </nav>

  <div class="d-flex align-items-center gap-2 mb-3">
    <div class="rounded-2 d-flex align-items-center justify-content-center flex-shrink-0"
         style="width:32px;height:32px;background:<?= h($color) ?>20">
      <i class="bi <?= h($workspace['icon'] ?: 'bi-folder2') ?>" style="color:<?= h($color) ?>;font-size:1.1rem"></i>
    </div>
    <h5 class="mb-0 fw-bold"><?= h($workspace['name']) ?></h5>
    <span class="badge rounded-pill" style="background:<?= h($color) ?>20;color:<?= h($color) ?>;font-size:.72rem">
      <?= ucfirst($role) ?>
    </span>
  </div>

  <!-- Zakładki -->
  <ul class="nav nav-tabs mb-3" id="wsTabs">
    <li class="nav-item">
      <a class="nav-link <?= $active_tab === 'files' ? 'active' : '' ?>"
         href="?ws=<?= $ws_id ?>&tab=files<?= $active_folder_id ? '&folder='.$active_folder_id : '' ?>">
        <i class="bi bi-folder2-open me-1"></i>Pliki
        <?php if (count($files)): ?>
        <span class="badge rounded-pill bg-secondary ms-1" style="font-size:.62rem"><?= count($files) ?></span>
        <?php endif; ?>
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= $active_tab === 'tasks' ? 'active' : '' ?>"
         href="?ws=<?= $ws_id ?>&tab=tasks">
        <i class="bi bi-check2-square me-1"></i>Zadania
        <span class="badge rounded-pill bg-secondary ms-1" style="font-size:.62rem"><?= count($tasks) ?></span>
      </a>
    </li>
  </ul>

  <?php if ($active_tab === 'files'): ?>
  <!-- ═══════════════════════ TAB: PLIKI ═══════════════════════════════════ -->

  <div class="row g-3">

    <!-- Lewa kolumna: foldery -->
    <div class="col-12 col-lg-3">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white d-flex align-items-center justify-content-between py-2">
          <span class="fw-semibold" style="font-size:.85rem"><i class="bi bi-folder me-1 text-primary"></i>Foldery</span>
          <?php if ($can_manage && $sp_ok): ?>
          <button class="btn btn-sm btn-outline-primary py-0 px-2" data-bs-toggle="modal" data-bs-target="#newFolderModal">
            <i class="bi bi-folder-plus"></i>
          </button>
          <?php endif; ?>
        </div>
        <div class="list-group list-group-flush" style="font-size:.85rem">
          <?php if (empty($folders)): ?>
          <div class="list-group-item text-muted text-center py-3" style="font-size:.8rem">
            <i class="bi bi-folder2 d-block mb-1" style="font-size:1.4rem;opacity:.3"></i>
            Brak folderów
          </div>
          <?php else: ?>
          <?php foreach ($folders as $f): ?>
          <a href="?ws=<?= $ws_id ?>&tab=files&folder=<?= (int)$f['id'] ?>"
             class="list-group-item list-group-item-action d-flex align-items-center gap-2 py-2 px-3
                    <?= (int)$f['id'] === $active_folder_id ? 'active' : '' ?>">
            <i class="bi bi-folder-fill" style="color:<?= h($color) ?>"></i>
            <span class="text-truncate flex-grow-1"><?= h($f['name']) ?></span>
          </a>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Prawa kolumna: lista plików -->
    <div class="col-12 col-lg-9">

      <?php if (!$active_folder && !empty($folders)): ?>
      <!-- Brak wybranego folderu -->
      <div class="card border-0 shadow-sm">
        <div class="card-body text-center text-muted py-5">
          <i class="bi bi-arrow-left-circle" style="font-size:2rem;opacity:.3"></i>
          <p class="mt-2 mb-0">Wybierz folder z listy po lewej.</p>
        </div>
      </div>

      <?php elseif ($active_folder): ?>
      <!-- Nagłówek folderu + toolbar upload -->
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white d-flex align-items-center gap-2 py-2">
          <i class="bi bi-folder2-open" style="color:<?= h($color) ?>"></i>
          <span class="fw-semibold flex-grow-1" style="font-size:.88rem"><?= h($active_folder['name']) ?></span>

          <?php if ($can_upload && $sp_ok): ?>
          <button class="btn btn-sm btn-primary py-0 px-2" id="btnUpload" onclick="document.getElementById('uploadInput').click()">
            <i class="bi bi-upload me-1"></i>Wgraj plik
          </button>
          <input type="file" id="uploadInput" multiple style="display:none">
          <?php endif; ?>

          <?php if ($can_manage): ?>
          <button class="btn btn-sm btn-outline-danger py-0 px-2" data-bs-toggle="tooltip"
                  title="Usuń folder" data-action="delete-folder" data-id="<?= $active_folder_id ?>">
            <i class="bi bi-trash3"></i>
          </button>
          <?php endif; ?>
        </div>

        <!-- Upload progress (hidden) -->
        <div id="uploadProgress" class="px-3 py-1" style="display:none">
          <div class="progress" style="height:4px">
            <div class="progress-bar progress-bar-striped progress-bar-animated" id="uploadBar" style="width:0%"></div>
          </div>
          <small class="text-muted" id="uploadStatus"></small>
        </div>

        <?php if (empty($files)): ?>
        <div class="card-body text-center text-muted py-4">
          <i class="bi bi-file-earmark" style="font-size:2rem;opacity:.3"></i>
          <p class="mt-2 mb-0" style="font-size:.85rem">Folder jest pusty. Wgraj pierwszy plik.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm table-hover mb-0" style="font-size:.83rem">
            <thead class="table-light">
              <tr>
                <th style="width:2rem"></th>
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
                  'pdf'              => 'bi-file-earmark-pdf text-danger',
                  'doc','docx'       => 'bi-file-earmark-word text-primary',
                  'xls','xlsx'       => 'bi-file-earmark-excel text-success',
                  'ppt','pptx'       => 'bi-file-earmark-ppt text-warning',
                  'jpg','jpeg','png',
                  'gif','webp'       => 'bi-file-earmark-image text-info',
                  'zip','7z',
                  'tar','gz'         => 'bi-file-earmark-zip text-secondary',
                  default            => 'bi-file-earmark text-muted',
              };
              $task_count = (int)(db_one(
                  "SELECT COUNT(*) AS n FROM ws_task_files WHERE file_id=?",
                  [$f['id']]
              )['n'] ?? 0);
              $fid = (int)$f['id'];
            ?>
            <tr id="frow-<?= $fid ?>">
              <td class="ps-3">
                <i class="bi <?= $icon ?>" style="font-size:1.1rem"></i>
              </td>
              <td class="text-truncate" style="max-width:220px">
                <span title="<?= h($f['name']) ?>"><?= h($f['name']) ?></span>
                <!-- klikalny badge — pokazuje/ukrywa wiersz z zadaniami -->
                <button class="badge rounded-pill border-0 ms-1 btn-file-tasks
                               <?= $task_count ? 'text-bg-info' : 'text-bg-light text-muted' ?>"
                        style="font-size:.6rem;cursor:pointer"
                        data-file-id="<?= $fid ?>"
                        title="<?= $task_count ? 'Pokaż powiązane zadania' : 'Brak powiązanych zadań — przypisz' ?>">
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
                  <a href="<?= APP_URL ?>/workspaces/api.php?action=download&id=<?= $fid ?>&_csrf=<?= urlencode(csrf_token()) ?>"
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
            <!-- Wiersz detali: zadania powiązane z tym plikiem (lazy-load) -->
            <tr id="ftasks-<?= $fid ?>" class="file-tasks-row" style="display:none">
              <td colspan="6" class="p-0 ps-5 pe-3 pb-2 bg-light" style="border-top:none">
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
      </div>

      <?php else: ?>
      <!-- Brak folderów w ogóle -->
      <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5">
          <i class="bi bi-folder2" style="font-size:2.5rem;opacity:.3"></i>
          <p class="mt-2 mb-1">Brak folderów w tym workspace.</p>
          <?php if ($can_manage && $sp_ok): ?>
          <button class="btn btn-sm btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#newFolderModal">
            <i class="bi bi-folder-plus me-1"></i>Utwórz pierwszy folder
          </button>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

    </div>
  </div><!-- /row files -->

  <?php else: ?>
  <!-- ═══════════════════════ TAB: ZADANIA ═════════════════════════════════ -->

  <div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex align-items-center justify-content-between py-2">
      <span class="fw-semibold" style="font-size:.88rem">
        <i class="bi bi-check2-square me-1 text-primary"></i>Zadania — <?= h($workspace['name']) ?>
      </span>
      <a href="<?= APP_URL ?>/tasks/?ws=<?= $ws_id ?>" class="btn btn-sm btn-outline-primary py-0 px-2">
        <i class="bi bi-box-arrow-up-right me-1"></i>Otwórz tablicę
      </a>
    </div>

    <?php if (empty($tasks)): ?>
    <div class="card-body text-center text-muted py-5">
      <i class="bi bi-clipboard-check" style="font-size:2rem;opacity:.3"></i>
      <p class="mt-2 mb-0">Brak aktywnych zadań w tym workspace.</p>
    </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0" style="font-size:.83rem">
        <thead class="table-light">
          <tr>
            <th class="ps-3">Tytuł</th>
            <th class="d-none d-sm-table-cell">Lista</th>
            <th class="d-none d-md-table-cell">Termin</th>
            <th class="d-none d-lg-table-cell">Pliki</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($tasks as $t):
          $done     = (bool)$t['is_done_state'];
          $overdue  = !$done && $t['due_date'] && $t['due_date'] < date('Y-m-d');
          $tid      = (int)$t['id'];
          $lf       = (int)$t['linked_files'];
          $priority = match($t['priority'] ?? '') {
              'high'   => ['bi-dot text-danger',   'Wysoki'],
              'medium' => ['bi-dot text-warning',  'Średni'],
              'low'    => ['bi-dot text-success',  'Niski'],
              default  => ['bi-dot text-muted',    ''],
          };
        ?>
        <tr id="trow-<?= $tid ?>" class="<?= $done ? 'text-muted' : '' ?>">
          <td class="ps-3">
            <div class="d-flex align-items-center gap-1">
              <i class="bi <?= $priority[0] ?>" style="font-size:1.2rem" title="Priorytet: <?= $priority[1] ?>"></i>
              <span class="text-truncate <?= $done ? 'text-decoration-line-through' : '' ?>" style="max-width:240px">
                <?= h($t['title']) ?>
              </span>
            </div>
          </td>
          <td class="d-none d-sm-table-cell text-muted"><?= h($t['list_name'] ?? '—') ?></td>
          <td class="d-none d-md-table-cell <?= $overdue ? 'text-danger fw-semibold' : 'text-muted' ?>">
            <?= $t['due_date'] ? date('d.m.Y', strtotime($t['due_date'])) : '—' ?>
          </td>
          <td class="d-none d-lg-table-cell">
            <!-- klikalny badge — pokazuje/ukrywa pliki zadania -->
            <button class="badge rounded-pill border-0 btn-task-files
                           <?= $lf ? 'text-bg-info' : 'text-bg-light text-muted' ?>"
                    style="font-size:.65rem;cursor:pointer"
                    data-task-id="<?= $tid ?>"
                    title="<?= $lf ? 'Pokaż pliki z koszulki' : 'Brak powiązanych plików — dołącz' ?>">
              <i class="bi bi-paperclip me-1"></i><?= $lf ?>
            </button>
          </td>
          <td class="text-end pe-2">
            <a href="<?= APP_URL ?>/tasks/?task=<?= $tid ?>&ws=<?= $ws_id ?>"
               class="btn btn-sm btn-outline-secondary py-0 px-2" title="Otwórz zadanie">
              <i class="bi bi-box-arrow-up-right"></i>
            </a>
          </td>
        </tr>
        <!-- Wiersz detali: pliki z koszulki powiązane z tym zadaniem -->
        <tr id="tfiles-<?= $tid ?>" class="task-files-row" style="display:none">
          <td colspan="5" class="p-0 ps-4 pe-3 pb-2 bg-light" style="border-top:none">
            <div class="task-files-content py-2" id="tfiles-content-<?= $tid ?>">
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
  </div>

  <?php endif; ?>
</div><!-- /container -->

<!-- Modal: nowy folder -->
<div class="modal fade" id="newFolderModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title fw-semibold"><i class="bi bi-folder-plus me-1 text-primary"></i>Nowy folder</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label small fw-semibold">Nazwa folderu</label>
          <input type="text" id="newFolderName" class="form-control" placeholder="np. Dokumenty projektowe" maxlength="120">
        </div>
        <div class="mb-2">
          <label class="form-label small fw-semibold">Opis (opcjonalnie)</label>
          <input type="text" id="newFolderDesc" class="form-control" placeholder="" maxlength="255">
        </div>
        <div id="newFolderAlert" class="alert alert-danger py-1 small" style="display:none"></div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Anuluj</button>
        <button type="button" class="btn btn-sm btn-primary" id="btnCreateFolder">
          <i class="bi bi-folder-plus me-1"></i>Utwórz w SharePoint
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: przypisz do zadania -->
<div class="modal fade" id="linkTaskModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title fw-semibold"><i class="bi bi-link-45deg me-1 text-info"></i>Przypisz plik do zadania</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="linkFileId">
        <p class="small text-muted mb-2">Plik: <strong id="linkFileName"></strong></p>
        <label class="form-label small fw-semibold">Wybierz zadanie</label>
        <select id="linkTaskSelect" class="form-select">
          <option value="">— wybierz —</option>
          <?php foreach ($tasks as $t): ?>
          <option value="<?= (int)$t['id'] ?>"><?= h($t['title']) ?></option>
          <?php endforeach; ?>
        </select>
        <div id="linkTaskAlert" class="alert alert-danger py-1 small mt-2" style="display:none"></div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Anuluj</button>
        <button type="button" class="btn btn-sm btn-info text-white" id="btnLinkTask">
          <i class="bi bi-link-45deg me-1"></i>Przypisz
        </button>
      </div>
    </div>
  </div>
</div>

<script>
const CSRF      = <?= json_encode(csrf_token()) ?>;
const API       = <?= json_encode(APP_URL . '/workspaces/api.php') ?>;
const TASKS_URL = <?= json_encode(APP_URL . '/tasks/') ?>;
const WS_ID     = <?= $ws_id ?>;
const FOLDER_ID = <?= $active_folder_id ?: 'null' ?>;

// ── Pomocnicze ────────────────────────────────────────────────────────────────
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

// ── Ikona pliku wg rozszerzenia ───────────────────────────────────────────────
function fileIcon(name) {
  const ext = (name.split('.').pop() || '').toLowerCase();
  if (['jpg','jpeg','png','gif','webp'].includes(ext)) return 'bi-file-earmark-image text-info';
  if (ext === 'pdf')                                   return 'bi-file-earmark-pdf text-danger';
  if (['doc','docx'].includes(ext))                    return 'bi-file-earmark-word text-primary';
  if (['xls','xlsx'].includes(ext))                    return 'bi-file-earmark-excel text-success';
  if (['zip','7z'].includes(ext))                      return 'bi-file-earmark-zip text-secondary';
  return 'bi-file-earmark text-muted';
}

// ────────────────────────────────────────────────────────────────────────────
//  TAB A: Plik → lista powiązanych zadań
// ────────────────────────────────────────────────────────────────────────────

async function loadFileTasksRow(fileId) {
  const el = document.getElementById(`ftasks-content-${fileId}`);
  if (!el) return;

  const r = await wsApi({action: 'tasks_for_file', file_id: fileId});
  if (!r.ok) { el.innerHTML = `<span class="text-danger small">${r.error}</span>`; return; }

  if (!r.tasks?.length) {
    el.innerHTML = '<span class="text-muted small">Brak powiązanych zadań.</span>';
    return;
  }

  el.innerHTML = `
    <div class="d-flex flex-wrap gap-2 align-items-center">
      <span class="text-muted small me-1">Zadania:</span>
      ${r.tasks.map(t => `
        <span class="badge rounded-pill text-bg-secondary d-inline-flex align-items-center gap-1"
              style="font-size:.72rem">
          <a href="${TASKS_URL}?task=${t.id}&ws=${WS_ID}"
             class="text-white text-decoration-none" target="_blank">
            ${escHtml(t.title)}
          </a>
          <button type="button"
                  class="btn-close btn-close-white flex-shrink-0"
                  style="font-size:.45rem"
                  title="Odepnij od zadania"
                  data-file-id="${fileId}" data-task-id="${t.id}"
                  onclick="unlinkFileTask(this)"></button>
        </span>`).join('')}
    </div>`;
}

window.unlinkFileTask = async function(btn) {
  if (!confirm('Odpiąć plik od tego zadania?')) return;
  const fileId = +btn.dataset.fileId;
  const taskId = +btn.dataset.taskId;
  const r = await wsApi({action: 'unlink_task', file_id: fileId, task_id: taskId});
  if (r.ok) {
    await loadFileTasksRow(fileId);
    // zaktualizuj licznik w badgu
    const badge = document.querySelector(`.btn-file-tasks[data-file-id="${fileId}"]`);
    if (badge) {
      const cur = parseInt(badge.textContent.trim()) || 1;
      const next = Math.max(0, cur - 1);
      badge.innerHTML = `<i class="bi bi-check2-square me-1"></i>${next}`;
      if (next === 0) badge.className = badge.className.replace('text-bg-info', 'text-bg-light text-muted');
    }
  } else alert(r.error);
};

document.querySelectorAll('.btn-file-tasks').forEach(btn => {
  btn.addEventListener('click', async () => {
    const fileId  = btn.dataset.fileId;
    const detRow  = document.getElementById(`ftasks-${fileId}`);
    if (!detRow) return;
    const isOpen  = detRow.style.display !== 'none';
    detRow.style.display = isOpen ? 'none' : '';
    if (!isOpen) await loadFileTasksRow(fileId);
  });
});

// ────────────────────────────────────────────────────────────────────────────
//  TAB B: Zadanie → lista powiązanych plików z koszulki
// ────────────────────────────────────────────────────────────────────────────

async function loadTaskFilesRow(taskId) {
  const el = document.getElementById(`tfiles-content-${taskId}`);
  if (!el) return;

  const r = await wsApi({action: 'files_for_task', task_id: taskId});
  if (!r.ok) { el.innerHTML = `<span class="text-danger small">${r.error}</span>`; return; }

  const attachBtn = `
    <button class="btn btn-sm btn-outline-primary py-0 px-2 mt-1 btn-attach-to-task"
            data-task-id="${taskId}" style="font-size:.75rem">
      <i class="bi bi-link-45deg me-1"></i>Dołącz plik z koszulki
    </button>`;

  if (!r.files?.length) {
    el.innerHTML = `<div class="d-flex align-items-center gap-2 flex-wrap">
      <span class="text-muted small">Brak powiązanych plików.</span>${attachBtn}</div>`;
    bindAttachBtn(el.querySelector('.btn-attach-to-task'));
    return;
  }

  const fileRows = r.files.map(f => `
    <div class="d-flex align-items-center gap-2 py-1 border-bottom" style="font-size:.82rem"
         id="tfile-row-${taskId}-${f.id}">
      <i class="bi ${fileIcon(f.name)} flex-shrink-0"></i>
      <span class="text-truncate flex-grow-1" style="max-width:220px" title="${escHtml(f.name)}">
        ${escHtml(f.name)}
      </span>
      <span class="text-muted flex-shrink-0" style="font-size:.73rem">${f.size_label}</span>
      <a href="${f.download_url}" class="btn btn-sm btn-outline-primary py-0 px-1 flex-shrink-0" title="Pobierz">
        <i class="bi bi-download" style="font-size:.8rem"></i>
      </a>
      <button type="button"
              class="btn btn-sm btn-outline-danger py-0 px-1 flex-shrink-0"
              title="Odepnij"
              onclick="unlinkTaskFile(this, ${taskId}, ${f.id})">
        <i class="bi bi-x-lg" style="font-size:.75rem"></i>
      </button>
    </div>`).join('');

  el.innerHTML = `<div>${fileRows}</div><div class="mt-2">${attachBtn}</div>`;
  el.querySelectorAll('.btn-attach-to-task').forEach(b => bindAttachBtn(b));
}

window.unlinkTaskFile = async function(btn, taskId, fileId) {
  if (!confirm('Odpiąć plik od zadania?')) return;
  const r = await wsApi({action: 'unlink_task', file_id: fileId, task_id: taskId});
  if (r.ok) {
    document.getElementById(`tfile-row-${taskId}-${fileId}`)?.remove();
    // zaktualizuj licznik
    const badge = document.querySelector(`.btn-task-files[data-task-id="${taskId}"]`);
    if (badge) {
      const cur  = parseInt(badge.textContent.trim()) || 1;
      const next = Math.max(0, cur - 1);
      badge.innerHTML = `<i class="bi bi-paperclip me-1"></i>${next}`;
      if (next === 0) badge.className = badge.className.replace('text-bg-info', 'text-bg-light text-muted');
    }
  } else alert(r.error);
};

// Picker: dołącz plik do zadania (otwiera mini-search z plików workspace)
function bindAttachBtn(btn) {
  if (!btn) return;
  btn.addEventListener('click', async () => {
    const taskId = btn.dataset.taskId;
    const existing = btn.closest('.task-files-content')?.querySelector('.attach-picker');
    if (existing) { existing.remove(); return; }

    const picker = document.createElement('div');
    picker.className = 'attach-picker mt-2 border rounded p-2 bg-white';
    picker.style.fontSize = '.82rem';
    picker.innerHTML = `
      <div class="input-group input-group-sm mb-1">
        <input type="text" class="form-control attach-search" placeholder="Szukaj pliku w koszulce…">
        <button class="btn btn-outline-secondary attach-search-btn" type="button">
          <i class="bi bi-search"></i>
        </button>
        <button class="btn btn-outline-secondary attach-close-btn" type="button">
          <i class="bi bi-x-lg"></i>
        </button>
      </div>
      <div class="attach-results list-group" style="max-height:160px;overflow-y:auto"></div>`;

    btn.after(picker);
    const inp  = picker.querySelector('.attach-search');
    const res  = picker.querySelector('.attach-results');
    inp.focus();

    async function doSearch(q) {
      res.innerHTML = '<div class="list-group-item py-1 text-muted small"><span class="spinner-border spinner-border-sm me-1"></span>Szukam…</div>';
      const r = await wsApi({action: 'list_ws_files', workspace_id: WS_ID, q});
      if (!r.ok || !r.files?.length) {
        res.innerHTML = '<div class="list-group-item py-1 small text-muted">Brak plików.</div>'; return;
      }
      res.innerHTML = r.files.map(f =>
        `<button type="button"
                 class="list-group-item list-group-item-action py-1 px-2 d-flex gap-2 align-items-center ws-pick-file"
                 data-file-id="${f.id}">
           <i class="bi ${fileIcon(f.name)} flex-shrink-0"></i>
           <span class="text-truncate flex-grow-1">${escHtml(f.name)}</span>
           <small class="text-muted flex-shrink-0">${f.size_label}</small>
         </button>`).join('');

      res.querySelectorAll('.ws-pick-file').forEach(row => {
        row.addEventListener('click', async () => {
          row.disabled = true;
          const r2 = await wsApi({action: 'link_task', file_id: +row.dataset.fileId, task_id: +taskId});
          if (r2.ok) {
            picker.remove();
            await loadTaskFilesRow(taskId);
            const badge = document.querySelector(`.btn-task-files[data-task-id="${taskId}"]`);
            if (badge) {
              const next = (parseInt(badge.textContent.trim()) || 0) + 1;
              badge.innerHTML = `<i class="bi bi-paperclip me-1"></i>${next}`;
              badge.className = badge.className.replace('text-bg-light text-muted', 'text-bg-info');
            }
          } else { alert(r2.error); row.disabled = false; }
        });
      });
    }

    picker.querySelector('.attach-search-btn').addEventListener('click', () => doSearch(inp.value.trim()));
    inp.addEventListener('keydown', e => { if (e.key === 'Enter') doSearch(inp.value.trim()); });
    picker.querySelector('.attach-close-btn').addEventListener('click', () => picker.remove());
    doSearch('');
  });
}

document.querySelectorAll('.btn-task-files').forEach(btn => {
  btn.addEventListener('click', async () => {
    const taskId  = btn.dataset.taskId;
    const detRow  = document.getElementById(`tfiles-${taskId}`);
    if (!detRow) return;
    const isOpen  = detRow.style.display !== 'none';
    detRow.style.display = isOpen ? 'none' : '';
    if (!isOpen) await loadTaskFilesRow(taskId);
  });
});

// ── Nowy folder ───────────────────────────────────────────────────────────────
document.getElementById('btnCreateFolder')?.addEventListener('click', async () => {
  const name = document.getElementById('newFolderName').value.trim();
  const desc = document.getElementById('newFolderDesc').value.trim();
  const alert_ = document.getElementById('newFolderAlert');
  alert_.style.display = 'none';
  if (!name) { alert_.textContent = 'Podaj nazwę folderu.'; alert_.style.display = ''; return; }

  const btn = document.getElementById('btnCreateFolder');
  btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Tworzę…';

  const r = await fetch(API, {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({_csrf: CSRF, action: 'create_folder', workspace_id: WS_ID, name, description: desc})
  }).then(r => r.json());

  btn.disabled = false; btn.innerHTML = '<i class="bi bi-folder-plus me-1"></i>Utwórz w SharePoint';
  if (r.ok) {
    window.location.href = `?ws=${WS_ID}&tab=files&folder=${r.folder.id}`;
  } else {
    alert_.textContent = r.error || 'Błąd tworzenia folderu.';
    alert_.style.display = '';
  }
});

// ── Upload pliku ──────────────────────────────────────────────────────────────
document.getElementById('uploadInput')?.addEventListener('change', async function() {
  if (!FOLDER_ID || !this.files.length) return;
  const progress = document.getElementById('uploadProgress');
  const bar      = document.getElementById('uploadBar');
  const status   = document.getElementById('uploadStatus');

  for (const file of this.files) {
    progress.style.display = '';
    bar.style.width = '0%';
    status.textContent = `Wgrywam: ${file.name}…`;

    const fd = new FormData();
    fd.append('_csrf',     CSRF);
    fd.append('action',    'upload_file');
    fd.append('folder_id', FOLDER_ID);
    fd.append('file',      file);

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
      xhr.open('POST', API);
      xhr.send(fd);
    });
  }
});

// ── Usuń plik ─────────────────────────────────────────────────────────────────
document.querySelectorAll('.btn-delete-file').forEach(btn => {
  btn.addEventListener('click', async () => {
    if (!confirm('Usunąć plik? Operacja jest nieodwracalna.')) return;
    const r = await fetch(API, {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({_csrf: CSRF, action: 'delete_file', id: +btn.dataset.id})
    }).then(r => r.json());
    if (r.ok) {
      const row = btn.closest('tr');
      const fileId = btn.dataset.id;
      document.getElementById(`ftasks-${fileId}`)?.remove();
      row?.remove();
    } else alert(r.error || 'Błąd usuwania.');
  });
});

// ── Przypisz do zadania ───────────────────────────────────────────────────────
document.querySelectorAll('.btn-link-task').forEach(btn => {
  btn.addEventListener('click', () => {
    document.getElementById('linkFileId').value  = btn.dataset.id;
    document.getElementById('linkFileName').textContent = btn.dataset.name;
    document.getElementById('linkTaskAlert').style.display = 'none';
    document.getElementById('linkTaskSelect').value = '';
    new bootstrap.Modal(document.getElementById('linkTaskModal')).show();
  });
});

document.getElementById('btnLinkTask')?.addEventListener('click', async () => {
  const file_id = +document.getElementById('linkFileId').value;
  const task_id = +document.getElementById('linkTaskSelect').value;
  const alert_  = document.getElementById('linkTaskAlert');
  alert_.style.display = 'none';
  if (!task_id) { alert_.textContent = 'Wybierz zadanie.'; alert_.style.display = ''; return; }

  const r = await fetch(API, {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({_csrf: CSRF, action: 'link_task', file_id, task_id})
  }).then(r => r.json());

  if (r.ok) bootstrap.Modal.getInstance(document.getElementById('linkTaskModal')).hide();
  else { alert_.textContent = r.error || 'Błąd przypisania.'; alert_.style.display = ''; }
});

// ── Usuń folder ───────────────────────────────────────────────────────────────
document.querySelectorAll('[data-action="delete-folder"]').forEach(btn => {
  btn.addEventListener('click', async () => {
    if (!confirm('Usunąć folder? Pliki zostaną ukryte, ale pozostaną w SharePoint.')) return;
    const r = await fetch(API, {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({_csrf: CSRF, action: 'delete_folder', id: +btn.dataset.id})
    }).then(r => r.json());
    if (r.ok) window.location.href = `?ws=${WS_ID}&tab=files`;
    else alert(r.error || 'Błąd usuwania folderu.');
  });
});

// Tooltips Bootstrap
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
