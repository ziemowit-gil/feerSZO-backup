<!--
 tasks/includes/detail_files.php — wydzielone z tasks/detail.php.
 Załączniki + Pliki z Koszulek (workspaces), scalone w jedną sekcję "Pliki zadania"
 z linkiem do pełnego widoku Plików obszaru (tasks/files.php). Wymaga: $task, $my_role,
 $can_manage_files, $files, $ws_linked_files, $ws_for_task, $id, $can_edit, $ms_available, $has_ms, $csrf.
-->
<?php
$td_show_attachments = task_field_visible('files', $my_role);
$td_show_ws_files     = (bool)$ws_for_task;
?>
<!-- ══ PLIKI ZADANIA (Załączniki + Koszulki, jedna sekcja) ═══════════════════ -->
<?php if ($td_show_attachments || $td_show_ws_files): ?>
<div class="td-section">
  <div class="td-label d-flex align-items-center">
    <i class="bi bi-paperclip" aria-hidden="true"></i>Pliki zadania
    <?php
    $td_files_total = ($td_show_attachments ? count($files) : 0) + ($td_show_ws_files ? count($ws_linked_files) : 0);
    if ($td_files_total):
    ?>
    <span class="badge bg-secondary ms-1" style="font-size:.6rem"><?= $td_files_total ?></span>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/tasks/files.php?ws=<?= (int)$task['workspace_id'] ?>"
       target="_blank" rel="noopener"
       class="ms-auto text-decoration-none small"
       style="font-size:.75rem"
       title="Otwórz pełny widok plików tego obszaru w nowej karcie">
      Wszystkie pliki obszaru <i class="bi bi-box-arrow-up-right ms-1" aria-hidden="true"></i>
    </a>
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

    <?php if ($ms_available && $has_ms): ?>
    <button type="button"
            class="btn btn-sm"
            style="background:#0078d4;color:#fff;border:none"
            onclick="tdOpenOneDrive()"
            aria-label="Wybierz plik z Microsoft OneDrive lub SharePoint">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" class="me-1" aria-hidden="true">
        <path d="M10.5 18.5H6a4.5 4.5 0 0 1-.95-8.9A6 6 0 0 1 16.7 7.6a4 4 0 0 1 2.8 6.9H10.5zm5-4.5-3.5-3.5-3.5 3.5h2.5v4h2v-4z"/>
      </svg>
      OneDrive / SharePoint
    </button>
    <?php elseif ($ms_available && !$has_ms): ?>
    <span class="btn btn-sm btn-outline-secondary disabled"
          aria-disabled="true"
          tabindex="-1"
          title="Zaloguj się przez Microsoft, aby importować z OneDrive">
      <i class="bi bi-cloud-arrow-up me-1" aria-hidden="true"></i>OneDrive
      <span class="badge bg-warning text-dark ms-1" style="font-size:.6rem">Wymagane konto MS</span>
    </span>
    <?php endif; ?>
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

  <!-- ── Pliki z Koszulek (workspaces / SharePoint) ────────────────────────── -->
  <?php if ($td_show_ws_files): ?>
  <div class="td-files-sub<?= $td_show_attachments ? ' mt-3' : '' ?>">
    Pliki z Koszulek
    <span class="text-muted" style="font-weight:400"><?= h($ws_for_task['name']) ?></span>
  </div>

  <div id="td-ws-files">
    <?php foreach ($ws_linked_files as $wf):
      $ext  = strtolower(pathinfo($wf['original_name'] ?: $wf['name'], PATHINFO_EXTENSION));
      $icon = match(true) {
          in_array($ext, ['jpg','jpeg','png','gif','webp'], true) => 'image',
          $ext === 'pdf'                                          => 'pdf',
          in_array($ext, ['xls','xlsx','csv'], true)              => 'spreadsheet',
          in_array($ext, ['doc','docx'], true)                    => 'word',
          in_array($ext, ['zip','7z'], true)                      => 'zip',
          default                                                 => 'text',
      };
      $dl_url = APP_URL . '/workspaces/api.php?action=download&id=' . (int)$wf['id'] . '&_csrf=' . urlencode($csrf);
    ?>
    <div class="td-file-row" id="wsfile-<?= $wf['id'] ?>">
      <i class="bi bi-file-earmark-<?= $icon ?> flex-shrink-0" style="color:#3b82f6" aria-hidden="true"></i>
      <span class="td-file-name text-truncate" style="flex:1;min-width:0" title="<?= h($wf['name']) ?>">
        <?= h($wf['name']) ?>
      </span>
      <span class="text-muted flex-shrink-0" style="font-size:.7rem"><?= ws_format_size((int)$wf['file_size']) ?></span>
      <a href="<?= h($dl_url) ?>"
         class="td-file-dl flex-shrink-0"
         title="Pobierz przez backend" aria-label="Pobierz <?= h($wf['name']) ?>">
        <i class="bi bi-download" aria-hidden="true"></i>
      </a>
      <?php if ($can_edit): ?>
      <button type="button"
              class="btn-close flex-shrink-0 td-ws-unlink"
              data-file-id="<?= (int)$wf['id'] ?>"
              data-task-id="<?= $id ?>"
              aria-label="Odepnij plik <?= h($wf['name']) ?>"
              style="font-size:.55rem"></button>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php if (empty($ws_linked_files)): ?>
    <p class="text-muted small mb-0" id="td-ws-empty">Brak powiązanych plików z koszulki.</p>
    <?php endif; ?>
  </div>

  <?php if ($can_edit): ?>
  <div class="mt-2">
    <button type="button" class="btn btn-sm btn-outline-primary" id="td-ws-link-btn"
            data-task-id="<?= $id ?>" data-ws-id="<?= (int)$task['workspace_id'] ?>">
      <i class="bi bi-link-45deg me-1" aria-hidden="true"></i>Dodaj z koszulki
    </button>
  </div>

  <!-- Inline picker plików z koszulki -->
  <div id="td-ws-picker" class="mt-2" style="display:none">
    <div class="input-group input-group-sm mb-1">
      <input type="text" id="td-ws-search" class="form-control" placeholder="Szukaj pliku…" autocomplete="off">
      <button class="btn btn-outline-secondary" id="td-ws-search-btn" type="button">
        <i class="bi bi-search" aria-hidden="true"></i>
      </button>
      <button class="btn btn-outline-secondary" id="td-ws-picker-close" type="button">
        <i class="bi bi-x-lg" aria-hidden="true"></i>
      </button>
    </div>
    <div id="td-ws-results" class="list-group" style="max-height:180px;overflow-y:auto;font-size:.82rem"></div>
  </div>
  <?php endif; ?>
  <?php endif; // td_show_ws_files ?>

</div>
<?php endif; // td_show_attachments || td_show_ws_files ?>
