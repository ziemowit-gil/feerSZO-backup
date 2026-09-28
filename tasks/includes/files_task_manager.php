<?php
/**
 * tasks/includes/files_task_manager.php — menedżer plików zadań obszaru (tasks/files.php).
 * Załączniki (task_files) + pliki z Koszulek powiązane z zadaniami, z filtrem zadania,
 * wyszukiwaniem, typem, sortowaniem, sumą rozmiaru i uploadem do wybranego zadania.
 * Wymaga: $ws_id, $uid, $can_manage, $active_folder_id.
 * Głęboki link: files.php?ws=N&task=T#task-files (przycisk „Pliki” w szczegółach zadania).
 */
$tfm_filters = [
    'task' => (int)($_GET['task'] ?? 0),
    'q'    => trim((string)($_GET['q'] ?? '')),
    'kind' => array_key_exists($_GET['kind'] ?? '', task_file_kinds()) ? $_GET['kind'] : '',
    'sort' => in_array($_GET['sort'] ?? '', ['new', 'old', 'name', 'size'], true) ? $_GET['sort'] : 'new',
];
$tfm       = task_files_manager($ws_id, $tfm_filters);
$tfm_tasks = $tfm['tasks'];

// Wybrane zadanie (także bez plików — żeby dało się do niego wgrać pierwszy)
$tfm_task = null;
if ($tfm_filters['task']) {
    $tfm_task = db_one(
        "SELECT t.id, t.title FROM tasks t WHERE t.id=? AND t.workspace_id=? AND t.deleted_at IS NULL",
        [$tfm_filters['task'], $ws_id]
    );
    if ($tfm_task) $tfm_tasks[(int)$tfm_task['id']] ??= $tfm_task['title'];
    else $tfm_filters['task'] = 0;
}

// Upload do zadania: te same reguły co tasks/api/upload.php (serwer i tak weryfikuje)
$tfm_role       = task_workspace_role($ws_id);
$tfm_can_attach = $tfm_task && (
    in_array($tfm_role, ['admin', 'editor'], true)
    || (task_field_editable('files', $tfm_role) && task_is_assigned((int)$tfm_task['id']))
);

$tfm_active = $tfm_filters['task'] || $tfm_filters['q'] !== '' || $tfm_filters['kind'] !== '' || $tfm_filters['sort'] !== 'new';
$tfm_kinds  = task_file_kinds();
?>
<!-- Menedżer plików zadań (załączniki + Koszulki powiązane z zadaniami) -->
<div class="tf-wrap mt-3" id="task-files">
  <div class="d-flex flex-wrap align-items-center gap-2 px-3 py-2 border-bottom">
    <span style="font-size:.8rem; font-weight:700; color:var(--tk-muted); text-transform:uppercase; letter-spacing:.04em">
      <i class="bi bi-paperclip me-1" aria-hidden="true"></i>Pliki zadań
      <?php if ($tfm_task): ?>
      <span class="text-body" style="text-transform:none; letter-spacing:0">— <?= h($tfm_task['title']) ?></span>
      <?php endif; ?>
    </span>
    <span class="badge text-bg-secondary rounded-pill" style="font-size:.6rem"><?= count($tfm['rows']) ?></span>
    <span class="text-muted small ms-auto">Łącznie: <?= ws_format_size((int)$tfm['total_size']) ?></span>
  </div>

  <form method="get" action="#task-files" class="d-flex flex-wrap gap-2 align-items-end px-3 py-2 border-bottom bg-light"
        role="search" aria-label="Filtry plików zadań">
    <input type="hidden" name="ws" value="<?= (int)$ws_id ?>">
    <?php if ($active_folder_id): ?><input type="hidden" name="folder" value="<?= (int)$active_folder_id ?>"><?php endif; ?>
    <div>
      <label for="tfm-task" class="form-label small mb-0">Zadanie</label>
      <select id="tfm-task" name="task" class="form-select form-select-sm" style="min-width:12rem; max-width:18rem" onchange="this.form.submit()">
        <option value="0">Wszystkie zadania</option>
        <?php foreach ($tfm_tasks as $tid => $ttitle): ?>
        <option value="<?= (int)$tid ?>" <?= (int)$tid === $tfm_filters['task'] ? 'selected' : '' ?>><?= h(mb_strimwidth($ttitle, 0, 60, '…')) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="tfm-q" class="form-label small mb-0">Szukaj</label>
      <input type="search" id="tfm-q" name="q" value="<?= h($tfm_filters['q']) ?>" class="form-control form-control-sm"
             placeholder="Nazwa pliku lub zadania">
    </div>
    <div>
      <label for="tfm-kind" class="form-label small mb-0">Typ</label>
      <select id="tfm-kind" name="kind" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="">Wszystkie</option>
        <?php foreach ($tfm_kinds as $k => $kl): ?>
        <option value="<?= h($k) ?>" <?= $k === $tfm_filters['kind'] ? 'selected' : '' ?>><?= h($kl) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="tfm-sort" class="form-label small mb-0">Sortuj</label>
      <select id="tfm-sort" name="sort" class="form-select form-select-sm" onchange="this.form.submit()">
        <?php foreach (['new' => 'Najnowsze', 'old' => 'Najstarsze', 'name' => 'Nazwa A–Z', 'size' => 'Największe'] as $sk => $sl): ?>
        <option value="<?= $sk ?>" <?= $sk === $tfm_filters['sort'] ? 'selected' : '' ?>><?= $sl ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-search" aria-hidden="true"></i> Filtruj</button>
    <?php if ($tfm_active): ?>
    <a href="?ws=<?= (int)$ws_id ?><?= $active_folder_id ? '&folder=' . (int)$active_folder_id : '' ?>#task-files"
       class="btn btn-sm btn-link text-decoration-none">Wyczyść</a>
    <?php endif; ?>

    <?php if ($tfm_task && $tfm['rows']): ?>
    <a href="<?= h(APP_URL . '/tasks/api/files_zip.php?task=' . (int)$tfm_task['id']) ?>"
       class="btn btn-sm btn-outline-secondary ms-auto">
      <i class="bi bi-file-earmark-zip me-1" aria-hidden="true"></i>Pobierz ZIP
    </a>
    <?php endif; ?>
    <?php if ($tfm_can_attach): ?>
    <label class="btn btn-sm btn-primary <?= $tfm['rows'] ? '' : 'ms-auto' ?> mb-0" style="cursor:pointer">
      <i class="bi bi-upload me-1" aria-hidden="true"></i>Dodaj pliki do zadania
      <input type="file" id="tfm-upload" class="visually-hidden" multiple
             accept="<?= h(task_upload_accept_attr()) ?>"
             data-task-id="<?= (int)$tfm_task['id'] ?>" data-max-bytes="<?= TASK_UPLOAD_MAX_BYTES ?>"
             aria-label="Wybierz pliki do dodania do zadania">
    </label>
    <?php endif; ?>
  </form>

  <div id="tfm-upload-status" class="small text-muted px-3 py-2 border-bottom d-none" aria-live="polite">
    <span id="tfm-upload-msg">Wysyłanie…</span>
    <div class="progress mt-1" style="height:4px">
      <div id="tfm-upload-bar" class="progress-bar" role="progressbar" style="width:0%" aria-label="Postęp wysyłania"></div>
    </div>
  </div>

  <?php if (empty($tfm['rows'])): ?>
  <p class="text-muted small px-3 py-3 mb-0">
    <?= $tfm_active ? 'Brak plików pasujących do filtrów.' : 'Żadne zadanie w tym obszarze nie ma jeszcze plików.' ?>
  </p>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0" style="font-size:.83rem">
      <thead class="table-light">
        <tr>
          <th style="width:2rem" class="ps-3"><span class="visually-hidden">Typ</span></th>
          <th>Nazwa</th>
          <?php if (!$tfm_task): ?><th>Zadanie</th><?php endif; ?>
          <th class="d-none d-md-table-cell">Źródło</th>
          <th class="d-none d-md-table-cell">Dodał/a</th>
          <th class="d-none d-md-table-cell">Rozmiar</th>
          <th class="d-none d-lg-table-cell">Data</th>
          <th class="text-end pe-3">Akcje</th>
        </tr>
      </thead>
      <tbody id="taskAttachTbody">
      <?php foreach ($tfm['rows'] as $tf):
        $tf_kind    = task_file_kind($tf['name']);
        $tf_task_url = APP_URL . '/tasks/index.php?task=' . (int)$tf['task_id'] . '&ws=' . (int)$ws_id;
        $can_delete = $tf['source'] === 'attach' && ($can_manage || (int)$tf['uploaded_by'] === $uid);
      ?>
      <tr id="<?= $tf['source'] === 'attach' ? 'tattach-' . (int)$tf['id'] : 'twsfile-' . (int)$tf['id'] . '-' . (int)$tf['task_id'] ?>">
        <td class="ps-3"><i class="bi <?= task_file_kind_icon($tf_kind) ?> tf-file-icon" aria-hidden="true"></i></td>
        <td class="text-truncate" style="max-width:240px" title="<?= h($tf['name']) ?>">
          <a href="<?= h($tf['pv_url']) ?>" target="_blank" rel="noopener" class="text-decoration-none text-body">
            <?= h($tf['name']) ?>
          </a>
        </td>
        <?php if (!$tfm_task): ?>
        <td class="text-truncate" style="max-width:180px">
          <a href="?ws=<?= (int)$ws_id ?>&task=<?= (int)$tf['task_id'] ?>#task-files"
             class="text-decoration-none fw-semibold" title="Pokaż tylko pliki zadania: <?= h($tf['task_title']) ?>">
            <?= h($tf['task_title']) ?>
          </a>
        </td>
        <?php endif; ?>
        <td class="d-none d-md-table-cell">
          <?php if ($tf['source'] === 'attach'): ?>
          <span class="badge text-bg-light border">Załącznik</span>
          <?php else: ?>
          <span class="badge text-bg-light border" title="Plik z Koszulki powiązany z zadaniem">Koszulka</span>
          <?php endif; ?>
        </td>
        <td class="d-none d-md-table-cell text-muted"><?= h($tf['uploader_name'] ?? '—') ?></td>
        <td class="d-none d-md-table-cell text-muted"><?= ws_format_size((int)$tf['file_size']) ?></td>
        <td class="d-none d-lg-table-cell text-muted"><?= h(date('d.m.Y', strtotime($tf['created_at']))) ?></td>
        <td class="text-end pe-2">
          <div class="d-flex gap-1 justify-content-end">
            <a href="<?= h($tf_task_url) ?>" class="btn btn-sm btn-outline-secondary py-0 px-2"
               title="Otwórz zadanie" aria-label="Otwórz zadanie <?= h($tf['task_title']) ?>">
              <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
            </a>
            <a href="<?= h($tf['dl_url']) ?>" download class="btn btn-sm btn-outline-primary py-0 px-2"
               title="Pobierz" aria-label="Pobierz <?= h($tf['name']) ?>">
              <i class="bi bi-download" aria-hidden="true"></i>
            </a>
            <?php if ($can_delete): ?>
            <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2 btn-delete-task-attach"
                    data-id="<?= (int)$tf['id'] ?>" data-task-id="<?= (int)$tf['task_id'] ?>"
                    title="Usuń plik" aria-label="Usuń <?= h($tf['name']) ?>">
              <i class="bi bi-trash3" aria-hidden="true"></i>
            </button>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php if ($tfm_can_attach): ?>
<script>
(function () {
  const input = document.getElementById('tfm-upload');
  if (!input) return;
  const URL_UPLOAD = <?= json_encode(APP_URL . '/tasks/api/upload.php') ?>;
  const TOKEN      = <?= json_encode(csrf_token()) ?>;
  input.addEventListener('change', function () {
    const files = Array.from(this.files); this.value = '';
    const maxB = +this.dataset.maxBytes, taskId = this.dataset.taskId;
    const st = document.getElementById('tfm-upload-status'), msg = document.getElementById('tfm-upload-msg'),
          bar = document.getElementById('tfm-upload-bar');
    const errors = []; let done = 0;
    const queue = files.filter(f => f.size <= maxB || !errors.push(f.name + ': plik za duży'));
    const next = i => {
      if (i >= queue.length) {
        st.classList.add('d-none');
        if (errors.length) alert('Nie wszystkie pliki zostały dodane:\n\n' + errors.join('\n'));
        if (done) location.reload();
        return;
      }
      const f = queue[i];
      st.classList.remove('d-none');
      msg.textContent = 'Wysyłanie ' + (i + 1) + '/' + queue.length + ': ' + f.name + '…';
      bar.style.width = '0%';
      const fd = new FormData();
      fd.append('_csrf', TOKEN); fd.append('task_id', taskId); fd.append('file', f);
      const xhr = new XMLHttpRequest();
      xhr.open('POST', URL_UPLOAD);
      xhr.upload.onprogress = e => { if (e.lengthComputable) bar.style.width = (e.loaded / e.total * 100) + '%'; };
      xhr.onload = () => {
        let r = null; try { r = JSON.parse(xhr.responseText); } catch (e) {}
        if (r && r.ok) done++; else errors.push(f.name + ': ' + ((r && r.error) || 'błąd serwera ' + xhr.status));
        next(i + 1);
      };
      xhr.onerror = () => { errors.push(f.name + ': błąd sieci'); next(i + 1); };
      xhr.send(fd);
    };
    next(0);
  });
})();
</script>
<?php endif; ?>
