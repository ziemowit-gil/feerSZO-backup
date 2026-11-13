<!--
 tasks/includes/detail_files.php — wydzielone z tasks/detail.php.
 Załączniki + Pliki z Koszulek (workspaces), scalone w jedną sekcję "Pliki zadania"
 z linkiem do pełnego widoku Plików obszaru (tasks/files.php). Wymaga: $task, $my_role,
 $can_manage_files, $files, $ws_linked_files, $ws_for_task, $id, $can_edit, $csrf.
-->
<?php
$td_show_attachments = task_field_visible('files', $my_role);
$td_show_ws_files     = (bool)$ws_for_task && $ws_can_view;   // macierz uprawnień do plików: „przeglądanie”
?>
<!-- ══ PLIKI ZADANIA (Załączniki + Koszulki, jedna sekcja) ═══════════════════ -->
<?php if ($td_show_attachments || $td_show_ws_files): ?>
<div class="td-section">
  <div class="td-label d-flex align-items-center">
    <i class="bi bi-paperclip" aria-hidden="true"></i>Pliki zadania
    <?php
    $td_files_total = ($td_show_attachments ? count($files) : 0)
        + ($td_show_ws_files ? count(array_unique(array_merge(array_column($ws_folder_files, 'id'), array_column($ws_linked_files, 'id')))) : 0);
    if ($td_files_total):
    ?>
    <span class="badge bg-secondary ms-1" style="font-size:.6rem"><?= $td_files_total ?></span>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/tasks/files.php?ws=<?= (int)$task['workspace_id'] ?>&task=<?= (int)$id ?>#task-files"
       target="_blank" rel="noopener"
       class="ms-auto text-decoration-none small"
       style="font-size:.75rem"
       title="Menedżer plików: pliki tego zadania (w nowej karcie)">
      Menedżer plików <i class="bi bi-box-arrow-up-right ms-1" aria-hidden="true"></i>
    </a>
    <?php if ($td_files_total > 1): ?>
    <a href="<?= APP_URL ?>/tasks/api/files_zip.php?task=<?= (int)$id ?>"
       class="ms-2 text-decoration-none small" style="font-size:.75rem"
       title="Pobierz wszystkie załączniki zadania jako archiwum ZIP">
      <i class="bi bi-file-earmark-zip me-1" aria-hidden="true"></i>ZIP
    </a>
    <?php endif; ?>
  </div>

  <!-- ── Załączniki (lokalne, uploads/tasks/) ──────────────────────────────── -->
  <?php if ($td_show_attachments): ?>
  <div class="td-files-sub">Załączniki</div>
  <div id="td-files">
    <?php foreach ($files as $f):
      $ext = strtolower(pathinfo($f['original_name'], PATHINFO_EXTENSION));
      $icon = match (true) {
        in_array($ext, ['jpg','jpeg','png','gif','webp','svg','bmp'], true) => 'image',
        $ext === 'pdf'                                                      => 'pdf',
        in_array($ext, ['xls','xlsx','csv'], true)                          => 'spreadsheet',
        in_array($ext, ['doc','docx'], true)                               => 'word',
        $ext === 'zip'                                                      => 'zip',
        default                                                             => 'text',
      };
      $file_url = APP_URL . '/tasks/api/file.php?id=' . (int)$f['id'];
    ?>
    <div class="td-file-row" id="file-<?= $f['id'] ?>">
      <i class="bi bi-file-earmark-<?= $icon ?> text-primary flex-shrink-0" aria-hidden="true"></i>
      <button type="button" class="td-file-name"
              onclick="tdPreviewFile(<?= htmlspecialchars(json_encode($file_url), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($f['original_name']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($ext), ENT_QUOTES) ?>)"
              aria-label="Podgląd: <?= h($f['original_name']) ?>">
        <?= h($f['original_name']) ?>
      </button>
      <span class="text-muted flex-shrink-0" style="font-size:.7rem"><?= round($f['file_size']/1024) ?> KB</span>
      <a href="<?= h($file_url . '&dl=1') ?>" download
         class="td-file-dl"
         title="Pobierz" aria-label="Pobierz <?= h($f['original_name']) ?>">
        <i class="bi bi-download" aria-hidden="true"></i>
      </a>
      <?php if (in_array($f['mime_type'] ?? '', TASK_COVER_MIMES, true)):
        $is_cover = (int)$f['id'] === $cover_id; ?>
      <?php if ($can_manage_files): ?>
      <button type="button" class="td-file-dl <?= $is_cover ? 'text-warning' : '' ?>"
              onclick="tdSetCover(<?= $is_cover && $cover_mode === 'manual' ? 0 : (int)$f['id'] ?>)"
              title="<?= $is_cover ? 'Okładka karty' . ($cover_mode === 'auto' ? ' (automatycznie)' : '') : 'Ustaw jako okładkę karty' ?>"
              aria-pressed="<?= $is_cover ? 'true' : 'false' ?>"
              aria-label="<?= $is_cover ? 'Okładka karty: ' : 'Ustaw jako okładkę: ' ?><?= h($f['original_name']) ?>">
        <i class="bi <?= $is_cover ? 'bi-image-fill' : 'bi-image' ?>" aria-hidden="true"></i>
      </button>
      <?php elseif ($is_cover): ?>
      <span class="td-file-dl text-warning" title="Okładka karty"><i class="bi bi-image-fill" aria-hidden="true"></i><span class="visually-hidden">Okładka karty</span></span>
      <?php endif; ?>
      <?php endif; ?>
      <?php if ($can_manage_files): ?>
      <button type="button"
              class="btn-close flex-shrink-0"
              onclick="tdDeleteFile(<?= $f['id'] ?>)"
              aria-label="Usuń plik <?= h($f['original_name']) ?>"
              style="font-size:.55rem"></button>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php if (empty($files)): ?>
    <p class="text-muted small mb-0">Brak załączników.</p>
    <?php endif; ?>
    <?php if ($can_manage_files && ($cover_id || $cover_mode === 'none')): ?>
    <div class="small mt-1">
      <?php if ($cover_mode === 'none'): ?>
      <span class="text-muted">Karta bez okładki.</span>
      <button type="button" class="btn btn-link btn-sm p-0 align-baseline" onclick="tdSetCover(0)">Włącz automatyczną okładkę</button>
      <?php else: ?>
      <button type="button" class="btn btn-link btn-sm p-0 align-baseline text-muted" onclick="tdSetCover(-1)">Bez okładki na karcie</button>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($can_manage_files): ?>
  <div id="td-drop-zone" class="td-drop-zone mt-2" data-max-bytes="<?= TASK_UPLOAD_MAX_BYTES ?>">
    <i class="bi bi-cloud-arrow-up" aria-hidden="true"></i>
    Upuść pliki tutaj
    <span class="text-muted">— lub użyj przycisku „Z dysku” (max <?= TASK_UPLOAD_MAX_BYTES >> 20 ?> MB na plik)</span>
  </div>
  <div class="d-flex flex-wrap gap-2 mt-2">
    <label class="btn btn-sm btn-outline-secondary" style="cursor:pointer">
      <i class="bi bi-upload me-1" aria-hidden="true"></i>Z dysku
      <span class="text-muted fw-normal small">(max <?= TASK_UPLOAD_MAX_BYTES >> 20 ?> MB)</span>
      <input type="file"
             id="td-file-input"
             class="visually-hidden"
             multiple
             accept="<?= h(task_upload_accept_attr()) ?>"
             aria-label="Wybierz pliki do uploadu">
    </label>

  </div>

  <div id="td-upload-status" class="small text-muted mt-2 d-none" aria-live="polite">
    <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
    <span id="td-upload-msg">Wysyłanie…</span>
    <div class="progress mt-1" style="height:4px">
      <div id="td-upload-bar" class="progress-bar" role="progressbar" style="width:0%"
           aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-label="Postęp wysyłania"></div>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; // td_show_attachments ?>

  <!-- ── Folder zadania w chmurze (Nextcloud) + pliki przypięte z innych folderów obszaru ── -->
  <?php if ($td_show_ws_files):
    $td_ws_in_folder = array_map(fn($f) => (int)$f['id'], $ws_folder_files);
    $td_ws_other     = array_values(array_filter($ws_linked_files, fn($f) => !in_array((int)$f['id'], $td_ws_in_folder, true)));
    $td_ws_unlink = $can_edit && $ws_can_link;
    $td_ws_row = static function (array $wf, bool $linked) use ($csrf, $td_ws_unlink, $ws_can_dl): string {
        $ext  = strtolower(pathinfo($wf['original_name'] ?: $wf['name'], PATHINFO_EXTENSION));
        $icon = match (true) {
            in_array($ext, ['jpg','jpeg','png','gif','webp'], true) => 'image',
            $ext === 'pdf'                                          => 'pdf',
            in_array($ext, ['xls','xlsx','csv'], true)              => 'excel',
            in_array($ext, ['doc','docx'], true)                    => 'word',
            in_array($ext, ['ppt','pptx'], true)                    => 'ppt',
            in_array($ext, ['zip','7z'], true)                      => 'zip',
            default                                                 => 'text',
        };
        $fid = (int)$wf['id'];
        $dl  = APP_URL . '/workspaces/api.php?action=download&id=' . $fid . '&_csrf=' . urlencode($csrf);
        $pv  = APP_URL . '/workspaces/api.php?action=preview&id=' . $fid . '&_csrf=' . urlencode($csrf);
        $name = h($wf['name']);
        if (!$ws_can_dl) {
            $open = '<span class="td-ws-name" style="cursor:default">' . $name . '</span>';
        } elseif (in_array($ext, ['pdf','jpg','jpeg','png','gif','webp'], true)) {
            $open = '<button type="button" class="td-ws-name" onclick="tdPreviewFile(' . h(json_encode($pv)) . ',' . h(json_encode($wf['name'])) . ',' . h(json_encode($ext)) . ')" title="Podgląd: ' . $name . '">' . $name . '</button>';
        } elseif (in_array($ext, ['doc','docx','xls','xlsx','ppt','pptx','odt','ods','odp'], true) && ($wf['provider'] ?? '') === 'nextcloud' && !empty($wf['web_url'])) {
            $open = '<a class="td-ws-name" href="' . h($wf['web_url']) . '" target="_blank" rel="noopener" title="Otwórz w chmurze: ' . $name . '">' . $name . '<span class="visually-hidden"> (otwiera chmurę w nowej karcie)</span></a>';
        } else {
            $open = '<a class="td-ws-name" href="' . h($dl) . '" title="Pobierz: ' . $name . '">' . $name . '</a>';
        }
        $meta = ws_format_size((int)$wf['file_size']) . ($linked && !empty($wf['folder_name']) ? ' · z folderu „' . h($wf['folder_name']) . '”' : '');
        return '<div class="td-file-row td-ws-row" id="wsfile-' . $fid . '" data-ws-file="' . $fid . '">'
             . '<i class="bi bi-file-earmark-' . $icon . ' flex-shrink-0" aria-hidden="true"></i>'
             . '<span class="td-ws-main">' . $open . '<span class="td-ws-meta">' . $meta . '</span></span>'
             . ($ws_can_dl ? '<a href="' . h($dl) . '" class="td-file-dl flex-shrink-0" title="Pobierz" aria-label="Pobierz ' . $name . '"><i class="bi bi-download" aria-hidden="true"></i></a>' : '')
             . ($linked && $td_ws_unlink ? '<button type="button" class="btn-close flex-shrink-0 td-ws-unlink" data-file-id="' . $fid . '" aria-label="Odepnij plik ' . $name . ' od zadania" title="Odepnij od zadania (plik zostaje w swoim folderze)" style="font-size:.55rem"></button>' : '')
             . '</div>';
    };
  ?>
  <div class="td-ws" id="td-ws" data-ws-id="<?= (int)$task['workspace_id'] ?>" data-folder-id="<?= (int)($ws_task_folder['id'] ?? 0) ?>">
    <div class="td-files-sub<?= $td_show_attachments ? ' mt-3' : '' ?> td-ws-head">
      <span><i class="bi bi-folder2-open" aria-hidden="true"></i> Folder zadania w chmurze</span>
      <?php if ($ws_task_folder): $td_ws_cloud = ws_cloud_link($ws_task_folder); ?>
      <a class="td-ws-open" href="<?= APP_URL ?>/tasks/files.php?ws=<?= (int)$task['workspace_id'] ?>&folder=<?= (int)$ws_task_folder['id'] ?>" target="_blank" rel="noopener">
        Otwórz w Plikach <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i><span class="visually-hidden"> (nowa karta)</span></a>
      <?php if ($td_ws_cloud !== ''): ?>
      <a class="td-ws-open" href="<?= h($td_ws_cloud) ?>" target="_blank" rel="noopener">
        Otwórz w Nextcloudzie <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i><span class="visually-hidden"> (nowa karta)</span></a>
      <?php endif; ?>
      <?php endif; ?>
    </div>

    <?php if (!$ws_cloud_ok): ?>
    <p class="td-ws-note">Chmura plików nie jest podłączona, więc pliki obszaru są chwilowo niedostępne. Załączniki zadania działają bez zmian.</p>
    <?php else: ?>
    <div id="td-ws-files" class="td-ws-list">
      <?php foreach ($ws_folder_files as $wf) echo $td_ws_row($wf, false); ?>
      <?php if ($td_ws_other): ?>
      <div class="td-ws-subhead">Przypięte z innych folderów obszaru</div>
      <?php foreach ($td_ws_other as $wf) echo $td_ws_row($wf, true); ?>
      <?php endif; ?>
    </div>
    <?php if (!$ws_folder_files && !$td_ws_other): ?>
    <p class="td-ws-note" id="td-ws-empty"><?= $ws_task_folder
        ? 'Folder zadania jest pusty.'
        : 'Zadanie nie ma jeszcze folderu. Powstanie w chmurze przy pierwszym wgraniu pliku.' ?></p>
    <?php endif; ?>

    <?php if ($ws_can_upload || ($can_edit && $ws_can_link)): ?>
    <div class="td-ws-actions">
      <?php if ($ws_can_upload): ?>
      <button type="button" class="td-mini-btn" id="td-ws-upload-btn"><i class="bi bi-cloud-arrow-up" aria-hidden="true"></i>Wgraj do folderu zadania</button>
      <input type="file" id="td-ws-upload" multiple hidden>
      <?php endif; ?>
      <?php if ($can_edit && $ws_can_link && $ws_folders_all): ?>
      <button type="button" class="td-mini-btn" id="td-ws-link-btn" aria-expanded="false" aria-controls="td-ws-picker">
        <i class="bi bi-folder-symlink" aria-hidden="true"></i>Dodaj z folderów obszaru</button>
      <?php endif; ?>
    </div>
    <p class="td-ws-note" id="td-ws-progress" role="status" aria-live="polite" hidden></p>

    <?php if ($can_edit && $ws_can_link && $ws_folders_all): ?>
    <div id="td-ws-picker" class="td-ws-picker" hidden>
      <div class="td-ws-pick-bar">
        <label class="visually-hidden" for="td-ws-folder">Folder</label>
        <select id="td-ws-folder" class="form-select form-select-sm">
          <option value="0">Wszystkie foldery obszaru</option>
          <?php foreach ($ws_folders_all as $wfo): if ((int)($ws_task_folder['id'] ?? 0) === (int)$wfo['id']) continue; ?>
          <option value="<?= (int)$wfo['id'] ?>"><?= !empty($wfo['task_id']) ? 'Zadanie: ' : '' ?><?= h($wfo['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <label class="visually-hidden" for="td-ws-search">Szukaj pliku</label>
        <input type="search" id="td-ws-search" class="form-control form-control-sm" placeholder="Szukaj pliku…" autocomplete="off">
        <button type="button" class="td-mini-btn" id="td-ws-picker-close" aria-label="Zamknij wybór plików"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
      </div>
      <div id="td-ws-results" class="td-ws-results" role="list" aria-label="Pliki do przypięcia"></div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
    <?php endif; // !$ws_cloud_ok ?>
  </div>
  <?php endif; // td_show_ws_files ?>

</div>
<?php endif; // td_show_attachments || td_show_ws_files ?>
