<?php
/**
 * crm/includes/case/tabs_work.php — Zakładki „Zadania", „Historia", „Poczta" i „Pliki" w widoku sprawy.
 *
 * Wydzielone z crm/cases/view.php: plik miał 2279 wiersze i zmiana w jednej
 * sekcji wymagała przewijania przez wszystkie pozostałe.
 *
 * Włączany przez include w zakresie crm/cases/view.php — korzysta z jego
 * zmiennych ($case, $id, $can_write, ...). Nie wywołuj samodzielnie.
 */
if (!isset($case)) { http_response_code(400); exit; }
?>
  <!-- ── PLIKI ───────────────────────────────────────────────────────────── -->
  <!-- ZAKŁADKA: Zadania sprawy (realne zadania z modułu Zadań) -->
  <div class="tab-pane fade" id="tasks" role="tabpanel" aria-labelledby="case-tab-tasks-btn" tabindex="0">
    <div class="cv-panel"><div class="cv-panel__body">
      <div class="cv-shead">
        <i class="bi bi-check2-square cv-shead__icon" aria-hidden="true"></i>
        <h2 class="cv-shead__title">Zadania</h2>
        <div class="cv-shead__aside"><span class="cv-count"><?= count($case_tasks) ?></span></div>
      </div>

      <?php if ($can_write): ?>
      <?php require_once dirname(dirname(__DIR__)) . '/includes/crm_quick.php';
            $task_lists = crm_quick_task_lists((int)(current_user()['id'] ?? 0)); ?>
      <form method="post" class="row g-2 align-items-end mb-3">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="add_task">
        <div class="col-md-5">
          <label class="form-label small fw-semibold mb-1" for="tTitle">Co jest do zrobienia</label>
          <input class="form-control form-control-sm" id="tTitle" name="task_title" required
                 placeholder="np. Przygotować odpowiedź na skargę">
        </div>
        <div class="col-md-3">
          <label class="form-label small fw-semibold mb-1" for="tDue">Termin</label>
          <input type="date" class="form-control form-control-sm" id="tDue" name="task_due">
        </div>
        <div class="col-md-3">
          <label class="form-label small fw-semibold mb-1" for="tList">Lista</label>
          <select class="form-select form-select-sm" id="tList" name="task_list">
            <option value="0">— domyślna —</option>
            <?php foreach ($task_lists as $tl): ?>
            <option value="<?= (int)$tl['list_id'] ?>"><?= h($tl['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-1">
          <button class="btn btn-crm-primary btn-sm w-100"><i class="bi bi-plus-lg"></i></button>
        </div>
      </form>
      <?php endif; ?>

      <?php if (!$case_tasks): ?>
      <p class="text-muted small mb-0">
        Brak zadań. Zadania sprawy to realne zadania z modułu Zadań — mają termin, przypomnienia
        i widać je na tablicy, w przeciwieństwie do kroków wpisanych w opis.
      </p>
      <?php else: ?>
      <div class="list-group list-group-flush">
        <?php foreach ($case_tasks as $t): $done = !empty($t['completed_at']); ?>
        <a class="list-group-item list-group-item-action px-0 d-flex align-items-center gap-2"
           href="<?= APP_URL ?>/tasks/detail.php?id=<?= (int)$t['id'] ?>">
          <i class="bi <?= $done ? 'bi-check-circle-fill text-success' : 'bi-circle text-muted' ?>" aria-hidden="true"></i>
          <span style="font-size:.87rem<?= $done ? ';text-decoration:line-through;color:#9CA3AF' : '' ?>">
            <?= h($t['title']) ?>
          </span>
          <span class="text-muted ms-auto" style="font-size:.74rem">
            <?= $t['list_name'] ? h($t['list_name']) : '' ?>
            <?php if (!empty($t['due_date'])): ?>
              · <?= h(date('d.m.Y', strtotime((string)$t['due_date']))) ?>
            <?php endif; ?>
          </span>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div></div>
  </div>

  <!-- ZAKŁADKA: Historia zmian statusu -->
  <div class="tab-pane fade" id="history" role="tabpanel" aria-labelledby="case-tab-hist-btn" tabindex="0">
    <div class="cv-panel"><div class="cv-panel__body">
      <div class="cv-shead">
        <i class="bi bi-clock-history cv-shead__icon" aria-hidden="true"></i>
        <h2 class="cv-shead__title">Historia statusów</h2>
        <div class="cv-shead__aside"><span class="cv-count"><?= count($case_hist) ?></span></div>
      </div>

      <?php if (!$case_hist): ?>
      <p class="text-muted small mb-0">
        Brak wpisów — historia zapisuje się od momentu wdrożenia tej funkcji.
        Sprawa założona wcześniej pokaże zmiany dopiero od następnej.
      </p>
      <?php else: ?>
      <?php $reasons = crm_case_close_reasons(); ?>
      <div class="list-group list-group-flush">
        <?php foreach ($case_hist as $hrow): ?>
        <div class="list-group-item px-0" style="font-size:.85rem">
          <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="text-muted"><?= h($status_cfg[$hrow['from_status']]['label'] ?? ($hrow['from_status'] ?: '—')) ?></span>
            <i class="bi bi-arrow-right text-muted" aria-hidden="true"></i>
            <strong><?= h($status_cfg[$hrow['to_status']]['label'] ?? $hrow['to_status']) ?></strong>
            <span class="text-muted ms-auto" style="font-size:.76rem">
              <?= h(date('d.m.Y H:i', strtotime((string)$hrow['created_at']))) ?>
              · <?= h($hrow['user_name'] ?: 'system') ?>
            </span>
          </div>
          <?php if (!empty($hrow['reason'])): ?>
          <div class="text-muted" style="font-size:.78rem">
            Powód: <?= h($reasons[$hrow['reason']] ?? $hrow['reason']) ?>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div></div>
  </div>

  <!-- ZAKŁADKA: Wiadomości dopięte do sprawy -->
  <div class="tab-pane fade" id="mail" role="tabpanel" aria-labelledby="case-tab-mail-btn" tabindex="0">
    <div class="cv-panel"><div class="cv-panel__body">
      <div class="cv-shead">
        <i class="bi bi-envelope cv-shead__icon" aria-hidden="true"></i>
        <h2 class="cv-shead__title">Korespondencja sprawy</h2>
        <div class="cv-shead__aside"><span class="cv-count"><?= count($case_msgs) ?></span></div>
      </div>

      <?php if (!$case_msgs): ?>
      <p class="text-muted small mb-0">
        Nic tu jeszcze nie ma. Wiadomość dopina się do sprawy w Skrzynce CRM — otwórz ją
        i wybierz sprawę z listy „dopnij do sprawy". Treść zostaje w skrzynce, tutaj pojawia się skrót.
      </p>
      <?php else: ?>
      <div class="list-group list-group-flush">
        <?php foreach ($case_msgs as $m): ?>
        <a class="list-group-item list-group-item-action px-0"
           href="<?= APP_URL ?>/crm/inbox.php?view=all&msg=<?= (int)$m['id'] ?>">
          <div class="d-flex align-items-baseline gap-2">
            <i class="bi bi-<?= $m['direction'] === 'in' ? 'arrow-down-left text-primary' : 'arrow-up-right text-success' ?>"
               aria-hidden="true"></i>
            <span class="fw-semibold" style="font-size:.86rem"><?= h($m['subject'] ?: '(bez tematu)') ?></span>
            <?php if ((int)$m['has_attachments']): ?>
            <i class="bi bi-paperclip text-muted" title="Załącznik" aria-hidden="true"></i>
            <?php endif; ?>
            <span class="text-muted ms-auto" style="font-size:.74rem">
              <?= h(date('d.m.Y H:i', strtotime((string)$m['sent_at']))) ?>
            </span>
          </div>
          <div class="text-muted" style="font-size:.76rem">
            <?= h($m['from_name'] ?: $m['from_email']) ?>
            <?php if (!empty($m['msg_no'])): ?> · #<?= h($m['msg_no']) ?><?php endif; ?>
          </div>
          <div class="text-muted" style="font-size:.76rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
            <?= h(mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$m['body'])), 0, 140)) ?>
          </div>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div></div>
  </div>

  <div class="tab-pane fade" id="files" role="tabpanel" aria-labelledby="case-tab-files-btn" tabindex="0">
    <div class="cv-panel"><div class="cv-panel__body">
      <div class="cv-shead">
        <i class="bi bi-paperclip cv-shead__icon" aria-hidden="true"></i>
        <h2 class="cv-shead__title">Pliki</h2>
        <div class="cv-shead__aside"><span class="cv-count"><?= count($files) ?></span></div>
      </div>

      <?php if ($files): ?>
      <?php
      $ext_icons = [
        'pdf'=>'bi-file-earmark-pdf text-danger',
        'docx'=>'bi-file-earmark-word text-primary','doc'=>'bi-file-earmark-word text-primary',
        'xlsx'=>'bi-file-earmark-excel text-success','xls'=>'bi-file-earmark-excel text-success',
        'jpg'=>'bi-file-earmark-image text-warning','jpeg'=>'bi-file-earmark-image text-warning',
        'png'=>'bi-file-earmark-image text-warning','gif'=>'bi-file-earmark-image text-warning',
        'zip'=>'bi-file-earmark-zip text-secondary',
        'txt'=>'bi-file-earmark-text text-muted',
        'csv'=>'bi-file-earmark-spreadsheet text-success',
      ];
      foreach ($files as $f):
        $ext = strtolower(pathinfo($f['original_name'], PATHINFO_EXTENSION));
        $ic  = $ext_icons[$ext] ?? 'bi-file-earmark text-muted';
        $disp = $f['display_name'] ?: $f['original_name'];
        $size = $f['file_size'] ? (round($f['file_size']/1024, 1) . ' KB') : '';
      ?>
      <div class="file-row">
        <div class="file-icon" aria-hidden="true">
          <i class="bi <?= $ic ?>"></i>
        </div>
        <div style="flex:1;min-width:0">
          <div class="fw-semibold" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
            <?= h($disp) ?>
          </div>
          <?php if ($f['description']): ?>
          <div class="text-muted" style="font-size:.75rem"><?= h($f['description']) ?></div>
          <?php endif; ?>
          <div style="font-size:.72rem;color:#5E6470">
            <?= h($f['original_name']) ?> <?= $size ? "· $size" : '' ?>
            · <?= date('d.m.Y H:i', strtotime($f['created_at'])) ?>
          </div>
        </div>
        <div class="d-flex gap-1 flex-shrink-0">
          <a href="<?= APP_URL ?>/crm/cases/download.php?id=<?= (int)$f['id'] ?>"
             class="btn btn-sm btn-outline-primary py-0 px-2"
             aria-label="Pobierz plik: <?= h($disp) ?>">
            <i class="bi bi-download" aria-hidden="true"></i>
          </a>
          <?php if ($can_write && ($f['created_by']==(current_user()['id']??0) || is_admin())): ?>
          <form method="post" class="d-inline" onsubmit="return confirm('Usunąć plik?')">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="_action" value="delete_file">
            <input type="hidden" name="file_id" value="<?= (int)$f['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2"
                    aria-label="Usuń plik: <?= h($disp) ?>">
              <i class="bi bi-trash" aria-hidden="true"></i>
            </button>
          </form>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>

      <?php if ($can_write): ?>
      <div id="drop-zone-wrap" class="<?= $files ? 'mt-3 pt-3 border-top' : 'mt-2' ?>">

        <!-- Ukryty prawdziwy input file — dostępny klawiaturowo przez label -->
        <label for="drop-file-input" class="visually-hidden">Wybierz pliki do przesłania</label>
        <input type="file" id="drop-file-input" multiple
               accept=".pdf,.docx,.doc,.xlsx,.xls,.csv,.txt,.jpg,.jpeg,.png,.gif,.zip"
               style="clip:rect(0 0 0 0);clip-path:inset(50%);height:1px;overflow:hidden;
                      position:absolute;white-space:nowrap;width:1px">

        <!-- Strefa drag & drop — dekoracyjna, aktywuje input -->
        <div id="drop-zone"
             role="presentation"
             aria-hidden="true"
             style="border:2px dashed #6b7280;border-radius:10px;padding:1.6rem 1rem;
                    text-align:center;cursor:pointer;transition:border-color .18s,background .18s;
                    background:#f8fafc;color:#374151;font-size:.88rem">
          <i class="bi bi-cloud-arrow-up" style="font-size:2rem;display:block;margin-bottom:.4rem;color:#4b5563" aria-hidden="true"></i>
          <span>Przeciągnij i upuść pliki tutaj lub</span>
          <span style="text-decoration:underline;text-underline-offset:2px;font-weight:600"> kliknij, aby wybrać</span>
          <div id="drop-formats" style="font-size:.78rem;margin-top:.3rem;color:#4b5563">
            PDF, DOCX, XLSX, CSV, TXT, JPG, PNG, GIF, ZIP
          </div>
        </div>

        <!-- Przyciski wyboru pliku -->
        <div class="mt-2 d-flex flex-wrap gap-2 align-items-center">
          <button type="button" id="drop-btn-choose"
                  class="btn btn-outline-secondary btn-sm"
                  aria-describedby="drop-formats">
            <i class="bi bi-folder2-open me-1" aria-hidden="true"></i>Wybierz pliki…
          </button>
          <?php if (m365_setting('sp_enabled') === '1' && MS_CLIENT_ID): ?>
          <button type="button" id="od-btn"
                  class="btn btn-outline-primary btn-sm"
                  aria-label="Dodaj plik z OneDrive Microsoft 365">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" style="margin-right:.3rem">
              <path d="M19.35 10.04A7.49 7.49 0 0 0 12 4C9.11 4 6.6 5.64 5.35 8.04A5.994 5.994 0 0 0 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96z"/>
            </svg>OneDrive
          </button>
          <?php endif; ?>
          <?php if (m365_setting('sp_enabled') === '1' && is_admin()): ?>
          <button type="button" id="sp-backup-btn"
                  class="btn btn-outline-dark btn-sm ms-auto"
                  aria-label="Wykonaj backup bazy danych na SharePoint">
            <i class="bi bi-cloud-arrow-up me-1" aria-hidden="true"></i>Backup DB → SharePoint
          </button>
          <?php endif; ?>
        </div>

        <!-- Kolejka uploadu — ogłoszenia dla czytników ekranu -->
        <ul id="upload-queue"
            style="list-style:none;padding:0;margin:.5rem 0 0"
            aria-live="polite"
            aria-relevant="additions removals"
            aria-label="Kolejka przesyłanych plików"></ul>

        <!-- Status ogłoszenia (tylko czytniki) -->
        <div id="upload-announce" class="visually-hidden" aria-live="assertive" aria-atomic="true"></div>

      </div>

      <style>
        #drop-zone:focus-within,
        #drop-zone.drag-over   { border-color:#1d4ed8; background:#eff6ff; outline:none }
        #drop-zone:focus-within { outline:3px solid #1d4ed8; outline-offset:2px }

        .upload-item { display:flex;align-items:flex-start;flex-wrap:wrap;gap:.4rem;
          padding:.6rem .7rem;border:1px solid #d1d5db;border-radius:8px;margin-top:.5rem;
          font-size:.84rem;background:#fff }
        .upload-item .ui-row1 { display:flex;align-items:center;gap:.5rem;width:100% }
        .upload-item .ui-name { flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600 }
        .upload-item .ui-icon { width:30px;height:30px;border-radius:6px;background:#eff6ff;
          color:#1d4ed8;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1rem }
        .upload-item .ui-form  { display:flex;gap:.4rem;align-items:center;width:100%;padding-left:38px }
        .upload-item .ui-label { font-size:.78rem;font-weight:600;white-space:nowrap;color:#374151 }
        .upload-item .ui-progress { display:none;align-items:center;gap:.4rem;width:100%;padding-left:38px }
        .upload-item .ui-bar-wrap { flex:1 }
        .upload-item .ui-bar  { height:6px;background:#1d4ed8;border-radius:3px;transition:width .15s;width:0 }
        .upload-item .ui-pct  { font-size:.72rem;color:#374151;min-width:2.8rem;text-align:right;font-variant-numeric:tabular-nums }
        .upload-item .ui-msg  { font-size:.78rem }
        .upload-item.done .ui-bar { background:#15803d }
        .upload-item.error    { border-color:#fca5a5;background:#fff5f5 }
        .upload-item.error .ui-bar { background:#b91c1c }

        .file-row-new { animation:fdIn .3s ease }
        @keyframes fdIn { from{opacity:0;transform:translateY(-4px)} to{opacity:1;transform:none} }

        @media (prefers-reduced-motion:reduce) {
          .file-row-new { animation:none }
          .upload-item .ui-bar { transition:none }
        }
      </style>

      <script>
      (function(){
        var UPLOAD_URL = '<?= APP_URL ?>/crm/cases/upload.php';
        var CASE_ID    = <?= $id ?>;
        var CSRF       = '<?= csrf_token() ?>';
        var EXT_ICONS  = {
          pdf:'bi-file-earmark-pdf text-danger',
          docx:'bi-file-earmark-word text-primary', doc:'bi-file-earmark-word text-primary',
          xlsx:'bi-file-earmark-excel text-success', xls:'bi-file-earmark-excel text-success',
          jpg:'bi-file-earmark-image text-warning',  jpeg:'bi-file-earmark-image text-warning',
          png:'bi-file-earmark-image text-warning',  gif:'bi-file-earmark-image text-warning',
          zip:'bi-file-earmark-zip text-secondary',
          txt:'bi-file-earmark-text text-muted',     csv:'bi-file-earmark-spreadsheet text-success',
        };
        var _uid = 0;
        function uid() { return 'upl-' + (++_uid); }

        var zone     = document.getElementById('drop-zone');
        var input    = document.getElementById('drop-file-input');
        var btnChoose= document.getElementById('drop-btn-choose');
        var queue    = document.getElementById('upload-queue');
        var announce = document.getElementById('upload-announce');

        // Przycisk klawiaturowy otwiera dialog
        btnChoose.addEventListener('click', function(){ input.click(); });
        // Strefa klikalna (myszka / dotyk)
        zone.addEventListener('click', function(){ input.click(); });
        input.addEventListener('change', function(){ handleFiles(this.files); this.value = ''; });

        // Drag & drop na strefie
        zone.addEventListener('dragover', function(e){
          e.preventDefault();
          this.classList.add('drag-over');
          this.setAttribute('aria-label', 'Upuść pliki');
        });
        zone.addEventListener('dragleave', function(e){
          if (!this.contains(e.relatedTarget)) {
            this.classList.remove('drag-over');
            this.removeAttribute('aria-label');
          }
        });
        zone.addEventListener('drop', function(e){
          e.preventDefault();
          this.classList.remove('drag-over');
          this.removeAttribute('aria-label');
          handleFiles(e.dataTransfer.files);
        });

        function handleFiles(files) {
          Array.from(files).forEach(uploadFile);
        }

        function uploadFile(file) {
          var ext  = (file.name.split('.').pop() || '').toLowerCase();
          var icon = EXT_ICONS[ext] || 'bi-file-earmark text-muted';
          var nameId = uid();
          var pbId   = uid();

          var li = document.createElement('li');
          li.className = 'upload-item';
          li.setAttribute('aria-label', 'Plik do wysłania: ' + file.name);
          li.innerHTML =
            '<div class="ui-row1">'
              + '<div class="ui-icon" aria-hidden="true"><i class="bi ' + icon + '"></i></div>'
              + '<div class="ui-name" title="' + esc(file.name) + '">' + esc(file.name) + '</div>'
              + '<button type="button" class="btn btn-outline-secondary btn-sm ui-cancel-btn ms-auto px-2 py-0"'
                + ' aria-label="Anuluj przesyłanie pliku: ' + esc(file.name) + '">'
                + '<i class="bi bi-x" aria-hidden="true"></i><span class="visually-hidden">Anuluj</span>'
              + '</button>'
            + '</div>'
            + '<div class="ui-form">'
              + '<label for="' + nameId + '" class="ui-label">Nazwa własna:</label>'
              + '<input id="' + nameId + '" type="text" class="form-control form-control-sm ui-custom-name" style="flex:1;font-size:.82rem"'
                + ' aria-describedby="' + nameId + '-hint" placeholder="opcjonalna">'
              + '<div id="' + nameId + '-hint" class="visually-hidden">Jeśli puste, użyta zostanie oryginalna nazwa pliku.</div>'
              + '<button type="button" class="btn btn-primary btn-sm ui-send-btn" style="white-space:nowrap">'
                + '<i class="bi bi-upload me-1" aria-hidden="true"></i>Wyślij'
              + '</button>'
            + '</div>'
            + '<div class="ui-progress" role="group" aria-label="Postęp wysyłania: ' + esc(file.name) + '">'
              + '<div class="ui-bar-wrap">'
                + '<div class="ui-bar" role="progressbar" id="' + pbId + '"'
                  + ' aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" aria-valuetext="0 procent"></div>'
              + '</div>'
              + '<div class="ui-pct" aria-hidden="true">0%</div>'
              + '<div class="ui-msg"></div>'
            + '</div>';

          queue.appendChild(li);

          var nameInput   = li.querySelector('.ui-custom-name');
          var sendBtn     = li.querySelector('.ui-send-btn');
          var cancelBtn   = li.querySelector('.ui-cancel-btn');
          var progressRow = li.querySelector('.ui-progress');
          var bar         = li.querySelector('.ui-bar');
          var pct         = li.querySelector('.ui-pct');
          var msg         = li.querySelector('.ui-msg');

          nameInput.focus();

          cancelBtn.addEventListener('click', function(){
            li.remove();
            setAnnounce('Anulowano: ' + file.name);
          });

          function setProgress(p) {
            bar.style.width = p + '%';
            bar.setAttribute('aria-valuenow', p);
            bar.setAttribute('aria-valuetext', p + ' procent');
            pct.textContent = p + '%';
          }

          function doUpload() {
            sendBtn.disabled  = true;
            cancelBtn.disabled= true;
            nameInput.disabled= true;
            progressRow.style.display = 'flex';

            var fd = new FormData();
            fd.append('case_id', CASE_ID);
            fd.append('_csrf',   CSRF);
            fd.append('file',    file);
            var customName = nameInput.value.trim();
            if (customName) fd.append('file_display_name', customName);

            var xhr = new XMLHttpRequest();
            xhr.open('POST', UPLOAD_URL);

            xhr.upload.addEventListener('progress', function(e){
              if (e.lengthComputable) setProgress(Math.round(e.loaded / e.total * 100));
            });

            xhr.addEventListener('load', function(){
              var res;
              try { res = JSON.parse(xhr.responseText); } catch(e){ res = {error:'Błąd odpowiedzi serwera'}; }
              if (xhr.status === 200 && res.ok) {
                setProgress(100);
                li.classList.add('done');
                msg.innerHTML = '<span class="text-success ui-msg"><i class="bi bi-check-circle-fill" aria-hidden="true"></i>'
                  + ' <span>Przesłano</span></span>';
                setAnnounce('Plik przesłany: ' + (res.file.display_name || file.name));
                setTimeout(function(){ li.remove(); }, 2000);
                appendFileRow(res.file);
                updateCount(1);
              } else {
                li.classList.add('error');
                var errTxt = res.error || 'Nieznany błąd';
                msg.innerHTML = '<span class="text-danger ui-msg"><i class="bi bi-x-circle-fill" aria-hidden="true"></i>'
                  + ' <span>' + esc(errTxt) + '</span></span>';
                setAnnounce('Błąd przesyłania pliku ' + file.name + ': ' + errTxt);
                sendBtn.disabled  = false;
                cancelBtn.disabled= false;
                nameInput.disabled= false;
                sendBtn.focus();
              }
            });
            xhr.addEventListener('error', function(){
              li.classList.add('error');
              msg.innerHTML = '<span class="text-danger ui-msg"><i class="bi bi-x-circle-fill" aria-hidden="true"></i>'
                + ' <span>Błąd sieci</span></span>';
              setAnnounce('Błąd sieci podczas przesyłania pliku: ' + file.name);
              sendBtn.disabled  = false;
              cancelBtn.disabled= false;
            });
            xhr.send(fd);
          }

          sendBtn.addEventListener('click', doUpload);
          nameInput.addEventListener('keydown', function(e){ if (e.key === 'Enter') { e.preventDefault(); doUpload(); } });
        }

        function setAnnounce(text) {
          announce.textContent = '';
          // Mikropauza wymusza ponowne ogłoszenie tego samego tekstu przy kolejnym pliku
          setTimeout(function(){ announce.textContent = text; }, 50);
        }

        function appendFileRow(f) {
          var ext  = f.ext || '';
          var icon = EXT_ICONS[ext] || 'bi-file-earmark text-muted';
          var size = f.file_size ? (Math.round(f.file_size / 1024 * 10) / 10 + ' KB') : '';
          var disp = f.display_name || f.original_name;

          var row = document.createElement('div');
          row.className = 'file-row file-row-new';
          row.innerHTML =
            '<div class="file-icon" aria-hidden="true"><i class="bi ' + icon + '"></i></div>'
            + '<div style="flex:1;min-width:0">'
              + '<div class="fw-semibold" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(disp) + '</div>'
              + (f.description ? '<div class="text-muted" style="font-size:.75rem">' + esc(f.description) + '</div>' : '')
              + '<div style="font-size:.72rem;color:#374151">' + esc(f.original_name) + (size ? ' · ' + size : '')
                + ' · ' + esc(f.created_at.slice(0, 16).replace('T', ' ')) + '</div>'
            + '</div>'
            + '<div class="d-flex gap-1 flex-shrink-0">'
              + '<a href="' + f.download_url + '" class="btn btn-sm btn-outline-primary py-0 px-2"'
                + ' aria-label="Pobierz plik: ' + esc(disp) + '">'
                + '<i class="bi bi-download" aria-hidden="true"></i></a>'
              + (f.can_delete
                ? '<button type="button" onclick="deleteFile(' + f.id + ',this)"'
                  + ' class="btn btn-sm btn-outline-danger py-0 px-2"'
                  + ' aria-label="Usuń plik: ' + esc(disp) + '">'
                  + '<i class="bi bi-trash" aria-hidden="true"></i></button>'
                : '')
            + '</div>';

          var firstRow = document.querySelector('#files .file-row');
          if (firstRow) {
            firstRow.parentNode.insertBefore(row, firstRow);
          } else {
            var wrap = document.getElementById('drop-zone-wrap');
            wrap.parentNode.insertBefore(row, wrap);
          }
        }

        function updateCount(delta) {
          document.querySelectorAll('.cv-count').forEach(function(el){
            el.textContent = (parseInt(el.textContent) || 0) + delta;
          });
        }

        function esc(s) {
          return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        window.deleteFile = function(fid) {
          if (!confirm('Usunąć plik?')) return;
          var form = document.createElement('form');
          form.method = 'post';
          form.action  = 'view.php?id=' + CASE_ID + '#files';
          form.innerHTML =
            '<input name="_csrf" value="' + CSRF + '">'
            + '<input name="_action" value="delete_file">'
            + '<input name="file_id" value="' + fid + '">';
          document.body.appendChild(form);
          form.submit();
        };

        // ── OneDrive File Picker v8 ──────────────────────────────────────────
        var OD_CLIENT_ID   = '<?= h(MS_CLIENT_ID) ?>';
        var OD_IMPORT_URL  = '<?= APP_URL ?>/crm/cases/api/od_import.php';
        var odBtn = document.getElementById('od-btn');
        if (odBtn && OD_CLIENT_ID) {
          // Ładuj SDK lazily przy pierwszym kliknięciu
          var _odSdkLoaded = false;
          function loadOdSdk(cb) {
            if (_odSdkLoaded) { cb(); return; }
            var s = document.createElement('script');
            s.src = 'https://res-1.cdn.office.net/files/odsp-next-0.2089.0-0/OneDrive.Picker.js';
            s.onload = function(){ _odSdkLoaded = true; cb(); };
            s.onerror = function(){ setAnnounce('Nie udało się załadować OneDrive Picker.'); };
            document.head.appendChild(s);
          }

          odBtn.addEventListener('click', function() {
            odBtn.disabled = true;
            odBtn.setAttribute('aria-busy', 'true');
            loadOdSdk(function() {
              odBtn.disabled = false;
              odBtn.removeAttribute('aria-busy');
              try {
                var picker = new OneDrive.OneDrivePicker({
                  clientId: OD_CLIENT_ID,
                  action:   'download',
                  multiSelect: true,
                  advanced: {
                    filter: '.pdf,.docx,.doc,.xlsx,.xls,.csv,.txt,.jpg,.jpeg,.png,.gif,.zip,.pptx,.ppt,.odt,.ods',
                    redirectUri: '<?= h(APP_URL) ?>/auth/microsoft.php',
                  },
                  success: function(files) {
                    files.value.forEach(function(f) {
                      importFromOneDrive({
                        name:         f.name,
                        size:         f.size,
                        download_url: f['@microsoft.graph.downloadUrl'],
                      });
                    });
                  },
                  cancel: function() {},
                  error: function(err) {
                    setAnnounce('Błąd OneDrive: ' + (err.message || err));
                  },
                });
                picker.open();
              } catch(e) {
                setAnnounce('Błąd otwierania OneDrive: ' + e.message);
              }
            });
          });

          function importFromOneDrive(f) {
            var ext  = (f.name.split('.').pop() || '').toLowerCase();
            var icon = EXT_ICONS[ext] || 'bi-file-earmark text-muted';
            var nameId = uid();

            var li = document.createElement('li');
            li.className = 'upload-item';
            li.setAttribute('aria-label', 'Import z OneDrive: ' + f.name);
            li.innerHTML =
              '<div class="ui-row1">'
                + '<div class="ui-icon" style="background:#e7f0fd;color:#0078d4" aria-hidden="true">'
                  + '<i class="bi ' + icon + '"></i></div>'
                + '<div class="ui-name" title="' + esc(f.name) + '">' + esc(f.name) + '</div>'
                + '<span class="badge bg-primary ms-1" style="font-size:.65rem;white-space:nowrap">OneDrive</span>'
                + '<button type="button" class="btn btn-outline-secondary btn-sm ui-cancel-btn ms-1 px-2 py-0"'
                  + ' aria-label="Anuluj import: ' + esc(f.name) + '">'
                  + '<i class="bi bi-x" aria-hidden="true"></i><span class="visually-hidden">Anuluj</span></button>'
              + '</div>'
              + '<div class="ui-form">'
                + '<label for="' + nameId + '" class="ui-label">Nazwa własna:</label>'
                + '<input id="' + nameId + '" type="text" class="form-control form-control-sm ui-custom-name"'
                  + ' style="flex:1;font-size:.82rem" placeholder="opcjonalna">'
                + '<button type="button" class="btn btn-primary btn-sm ui-send-btn" style="white-space:nowrap">'
                  + '<i class="bi bi-cloud-download me-1" aria-hidden="true"></i>Importuj</button>'
              + '</div>'
              + '<div class="ui-progress" role="group" aria-label="Postęp importu: ' + esc(f.name) + '">'
                + '<div class="ui-bar-wrap"><div class="ui-bar" role="progressbar"'
                  + ' aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" aria-valuetext="Pobieranie…"'
                  + ' style="animation:odPulse 1.2s ease-in-out infinite"></div></div>'
                + '<div class="ui-pct" aria-hidden="true"></div>'
                + '<div class="ui-msg"></div>'
              + '</div>';

            queue.appendChild(li);

            var nameInput   = li.querySelector('.ui-custom-name');
            var sendBtn     = li.querySelector('.ui-send-btn');
            var cancelBtn   = li.querySelector('.ui-cancel-btn');
            var progressRow = li.querySelector('.ui-progress');
            var bar         = li.querySelector('.ui-bar');
            var msg         = li.querySelector('.ui-msg');

            nameInput.focus();
            cancelBtn.addEventListener('click', function(){ li.remove(); setAnnounce('Anulowano import: ' + f.name); });

            function doImport() {
              sendBtn.disabled   = true;
              cancelBtn.disabled = true;
              nameInput.disabled = true;
              progressRow.style.display = 'flex';
              bar.setAttribute('aria-valuetext', 'Pobieranie z OneDrive…');

              var payload = {
                case_id:      CASE_ID,
                _csrf:        CSRF,
                name:         f.name,
                size:         f.size,
                download_url: f.download_url,
                display_name: nameInput.value.trim(),
              };

              fetch(OD_IMPORT_URL, {
                method:  'POST',
                headers: {'Content-Type': 'application/json'},
                body:    JSON.stringify(payload),
              })
              .then(function(r){ return r.json(); })
              .then(function(res) {
                if (res.ok) {
                  bar.style.width = '100%';
                  bar.style.animation = 'none';
                  bar.setAttribute('aria-valuetext', '100 procent');
                  li.classList.add('done');
                  msg.innerHTML = '<span class="text-success"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> <span>Zaimportowano</span></span>';
                  setAnnounce('Zaimportowano z OneDrive: ' + (res.file.display_name || f.name));
                  setTimeout(function(){ li.remove(); }, 2000);
                  appendFileRow(res.file);
                  updateCount(1);
                } else {
                  li.classList.add('error');
                  bar.style.animation = 'none';
                  msg.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle-fill" aria-hidden="true"></i> <span>' + esc(res.error || 'Błąd') + '</span></span>';
                  setAnnounce('Błąd importu ' + f.name + ': ' + (res.error || 'Błąd'));
                  sendBtn.disabled = cancelBtn.disabled = nameInput.disabled = false;
                  sendBtn.focus();
                }
              })
              .catch(function(e) {
                li.classList.add('error');
                bar.style.animation = 'none';
                msg.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle-fill" aria-hidden="true"></i> <span>Błąd sieci</span></span>';
                setAnnounce('Błąd sieci podczas importu: ' + f.name);
                sendBtn.disabled = cancelBtn.disabled = false;
              });
            }

            sendBtn.addEventListener('click', doImport);
            nameInput.addEventListener('keydown', function(e){ if (e.key === 'Enter') { e.preventDefault(); doImport(); } });
          }
        }

        // ── Backup DB → SharePoint ───────────────────────────────────────────
        var spBtn = document.getElementById('sp-backup-btn');
        if (spBtn) {
          spBtn.addEventListener('click', function() {
            if (!confirm('Wykonać teraz backup bazy danych na SharePoint?')) return;
            spBtn.disabled = true;
            spBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Backup…';
            spBtn.setAttribute('aria-busy', 'true');
            fetch('<?= APP_URL ?>/api/sp_backup.php', {
              method: 'POST',
              headers: {'Content-Type': 'application/x-www-form-urlencoded'},
              body:   '_csrf=' + encodeURIComponent(CSRF),
            })
            .then(function(r){ return r.json(); })
            .then(function(res) {
              spBtn.disabled = false;
              spBtn.removeAttribute('aria-busy');
              spBtn.innerHTML = '<i class="bi bi-cloud-arrow-up me-1" aria-hidden="true"></i>Backup DB → SharePoint';
              if (res.ok) {
                setAnnounce('Backup wykonany: ' + (res.sp_path || '') + ' (' + (res.size_h || '') + ')');
                spBtn.classList.replace('btn-outline-dark', 'btn-outline-success');
                setTimeout(function(){ spBtn.classList.replace('btn-outline-success','btn-outline-dark'); }, 4000);
              } else {
                setAnnounce('Błąd backupu: ' + (res.error || 'Nieznany błąd'));
                alert('Błąd backupu: ' + (res.error || 'Nieznany błąd'));
              }
            })
            .catch(function() {
              spBtn.disabled = false;
              spBtn.removeAttribute('aria-busy');
              spBtn.innerHTML = '<i class="bi bi-cloud-arrow-up me-1" aria-hidden="true"></i>Backup DB → SharePoint';
              alert('Błąd sieci podczas backupu.');
            });
          });
        }
      })();
      </script>
      <style>
        @keyframes odPulse {
          0%,100%{opacity:.4;width:30%} 50%{opacity:1;width:70%}
        }
      </style>
      <?php endif; ?>
    </div></div>
  </div>
