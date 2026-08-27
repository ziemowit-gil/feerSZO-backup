<?php
/**
 * rekrutacja/view.php — Karta kandydata
 * Akcje (POST + CSRF): zmiana statusu, tagi (dodaj/usuń), notatki, planowanie
 * rozmowy, wysyłka e-maila (szablon + edytor), dogranie dokumentu.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/rekrutacja.php';

rekr_migrate();
require_module_enabled('rekrutacja_enabled', 'Moduł rekrutacji');
rekr_require_operator();

$id  = (int)($_GET['id'] ?? 0);
$app = $id ? db_one("SELECT a.*, p.name AS position_name FROM rekr_applications a
                     LEFT JOIN rekr_positions p ON p.id=a.position_id WHERE a.id=?", [$id]) : null;
if (!$app) { http_response_code(404); die('Zgłoszenie nie istnieje.'); }

$u   = current_user();
$uid = (int)$u['id'];

// ── Akcje POST ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $back   = APP_URL . '/rekrutacja/view.php?id=' . $id;

    switch ($action) {
        case 'status':
            $to   = (string)($_POST['status'] ?? '');
            $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 2000);
            if (isset(rekr_statuses()[$to])) {
                $done = rekr_set_status($id, $to, $note);
                flash_set('success', 'Status zmieniono na „' . rekr_status_label($to) . '".'
                    . ($done ? ' Automatyzacje: ' . implode('; ', $done) . '.' : ''));
            }
            break;

        case 'tag_add':
            if (rekr_add_tag($id, (string)($_POST['tag'] ?? ''))) {
                try { rekr_crm_sync(db_one("SELECT * FROM rekr_applications WHERE id=?", [$id])); } catch (\Throwable $e) {}
                flash_set('success', 'Dodano tag.');
            }
            break;

        case 'tag_del':
            rekr_remove_tag($id, (string)($_POST['tag'] ?? ''));
            flash_set('success', 'Usunięto tag.');
            break;

        case 'note_add':
            $body = mb_substr(trim((string)($_POST['body'] ?? '')), 0, 10000);
            if ($body !== '') {
                db_insert('rekr_notes', ['application_id' => $id, 'user_id' => $uid, 'user_name' => $u['name'] ?? '', 'body' => $body]);
                flash_set('success', 'Notatka zapisana.');
            }
            break;

        case 'interview_add':
            $starts = (string)($_POST['starts_at'] ?? '');
            $ts = strtotime($starts);
            if ($ts) {
                db_insert('rekr_interviews', [
                    'application_id' => $id,
                    'starts_at'      => date('Y-m-d H:i:s', $ts),
                    'duration_min'   => max(5, min(480, (int)($_POST['duration_min'] ?? 30))),
                    'location'       => mb_substr(trim((string)($_POST['location'] ?? '')), 0, 500),
                    'interviewer_id' => (int)($_POST['interviewer_id'] ?? 0) ?: null,
                    'note'           => mb_substr(trim((string)($_POST['note'] ?? '')), 0, 2000),
                    'created_by'     => $uid,
                ]);
                flash_set('success', 'Rozmowa zaplanowana.');
            } else {
                flash_set('error', 'Podaj poprawny termin rozmowy.');
            }
            break;

        case 'interview_status':
            $ist = in_array($_POST['value'] ?? '', ['zaplanowana', 'odbyta', 'odwolana'], true) ? $_POST['value'] : null;
            $iid = (int)($_POST['interview_id'] ?? 0);
            if ($ist && db_one("SELECT id FROM rekr_interviews WHERE id=? AND application_id=?", [$iid, $id])) {
                db_exec("UPDATE rekr_interviews SET status=? WHERE id=?", [$ist, $iid]);
                flash_set('success', 'Zaktualizowano rozmowę.');
            }
            break;

        case 'email_send':
            $subject = mb_substr(trim((string)($_POST['subject'] ?? '')), 0, 300);
            $body    = rekr_sanitize_html((string)($_POST['body'] ?? ''));
            if ($subject === '' || trim(strip_tags($body)) === '') {
                flash_set('error', 'Temat i treść wiadomości są wymagane.');
            } else {
                $ok = rekr_send_email($app, $subject, $body, $uid);
                flash_set($ok ? 'success' : 'error', $ok ? 'Wiadomość wysłana do ' . $app['email'] . '.' : 'Wysyłka nie powiodła się.');
            }
            break;

        case 'file_add':
            if (!empty($_FILES['file'])) {
                $res = rekr_store_upload($_FILES['file'], $id, (string)($_POST['kind'] ?? 'inne'), $uid);
                flash_set($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Dokument dodany.' : $res['error']);
                if ($res['ok']) rekr_apply_autotags($id);
            }
            break;
    }
    header('Location: ' . $back);
    exit;
}

// ── Dane widoku ───────────────────────────────────────────────────────────────
$statuses   = rekr_statuses();
$tags       = rekr_tags($id);
$files      = db_all("SELECT * FROM rekr_files WHERE application_id=? ORDER BY created_at", [$id]);
$history    = db_all("SELECT * FROM rekr_status_history WHERE application_id=? ORDER BY created_at DESC, id DESC", [$id]);
$notes      = db_all("SELECT * FROM rekr_notes WHERE application_id=? ORDER BY created_at DESC", [$id]);
$interviews = db_all("SELECT i.*, u.name AS interviewer_name FROM rekr_interviews i
                      LEFT JOIN users u ON u.id=i.interviewer_id
                      WHERE i.application_id=? ORDER BY i.starts_at DESC", [$id]);
$emails     = db_all("SELECT l.*, u.name AS sender_name FROM rekr_email_log l
                      LEFT JOIN users u ON u.id=l.sent_by
                      WHERE l.application_id=? ORDER BY l.created_at DESC", [$id]);
$templates  = db_all("SELECT * FROM rekr_email_templates WHERE enabled=1 ORDER BY name");
$operators  = db_all("SELECT id, name FROM users WHERE role IN ('admin','editor') OR rekrutacja_operator=1 ORDER BY name");
$type       = REKR_TYPES[$app['type']] ?? REKR_TYPES['wolontariat'];

// Szablony wyrenderowane pod tego kandydata — do JS edytora
$tpl_js = [];
foreach ($templates as $t) {
    [$s, $b] = rekr_tpl_render($t, $app);
    $tpl_js[$t['id']] = ['name' => $t['name'], 'subject' => $s, 'body' => $b];
}

$PAGE_TITLE = 'Rekrutacja — ' . rekr_candidate_name($app);
include dirname(__DIR__) . '/includes/header.php';
?>
<div class="container-fluid py-3">
  <nav aria-label="breadcrumb">
    <ol class="breadcrumb small">
      <li class="breadcrumb-item"><a href="<?= h(APP_URL) ?>/rekrutacja/index.php">Rekrutacja</a></li>
      <li class="breadcrumb-item active" aria-current="page"><?= h(rekr_candidate_name($app)) ?></li>
    </ol>
  </nav>

  <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
      <h1 class="h4 mb-1"><?= h(rekr_candidate_name($app)) ?>
        <span class="badge text-bg-<?= h($type['class']) ?> ms-1"><i class="bi <?= h($type['icon']) ?> me-1" aria-hidden="true"></i><?= h($type['label']) ?></span>
        <?= rekr_status_badge($app['status']) ?>
      </h1>
      <div class="text-muted small">
        <i class="bi bi-envelope me-1" aria-hidden="true"></i><a href="mailto:<?= h($app['email']) ?>"><?= h($app['email']) ?></a>
        <?php if ($app['telefon']): ?> · <i class="bi bi-telephone me-1" aria-hidden="true"></i><?= h($app['telefon']) ?><?php endif; ?>
        · Stanowisko: <strong><?= h($app['position_name'] ?? '—') ?></strong>
        · Złożono: <?= h(date('d.m.Y H:i', strtotime($app['created_at']))) ?> (<?= h($app['source']) ?>)
        <?php if ($app['crm_contact_id']): ?>
          · <a href="<?= h(APP_URL) ?>/crm/contact/view.php?id=<?= (int)$app['crm_contact_id'] ?>">kontakt CRM #<?= (int)$app['crm_contact_id'] ?></a>
        <?php endif; ?>
      </div>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#emailModal">
      <i class="bi bi-send me-1" aria-hidden="true"></i>Napisz do kandydata
    </button>
  </div>

  <div class="row g-3">
    <!-- Lewa kolumna: dokumenty, wiadomość, notatki -->
    <div class="col-12 col-lg-7">

      <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span><i class="bi bi-paperclip me-1" aria-hidden="true"></i>Dokumenty aplikacyjne</span>
        </div>
        <ul class="list-group list-group-flush">
          <?php if (!$files): ?><li class="list-group-item text-muted">Brak dokumentów.</li><?php endif; ?>
          <?php foreach ($files as $f): ?>
          <li class="list-group-item d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
              <span class="badge text-bg-light border me-1"><?= h(REKR_FILE_KINDS[$f['kind']] ?? $f['kind']) ?></span>
              <?= h($f['original_name']) ?>
              <span class="text-muted small">(<?= number_format($f['size'] / 1024, 0, ',', ' ') ?> KB)</span>
            </div>
            <div class="btn-group btn-group-sm">
              <?php if ($f['mime'] === 'application/pdf'): ?>
              <a class="btn btn-outline-secondary" href="attachment.php?id=<?= (int)$f['id'] ?>" target="_blank" rel="noopener">
                <i class="bi bi-eye me-1" aria-hidden="true"></i>Podgląd</a>
              <?php endif; ?>
              <a class="btn btn-outline-secondary" href="attachment.php?id=<?= (int)$f['id'] ?>&dl=1">
                <i class="bi bi-download me-1" aria-hidden="true"></i>Pobierz</a>
            </div>
          </li>
          <?php endforeach; ?>
        </ul>
        <div class="card-footer">
          <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="file_add">
            <div class="col-5">
              <label class="form-label small mb-1" for="f-kind">Rodzaj</label>
              <select class="form-select form-select-sm" id="f-kind" name="kind">
                <?php foreach (REKR_FILE_KINDS as $k => $l): ?><option value="<?= h($k) ?>"><?= h($l) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-5">
              <label class="form-label small mb-1" for="f-file">Plik (PDF/DOCX, do 10 MB)</label>
              <input type="file" class="form-control form-control-sm" id="f-file" name="file" accept=".pdf,.docx" required>
            </div>
            <div class="col-2"><button class="btn btn-sm btn-outline-primary w-100">Dodaj</button></div>
          </form>
        </div>
      </div>

      <?php if (trim($app['message']) !== ''): ?>
      <div class="card mb-3">
        <div class="card-header"><i class="bi bi-chat-left-text me-1" aria-hidden="true"></i>Wiadomość kandydata</div>
        <div class="card-body"><p class="mb-0" style="white-space:pre-wrap"><?= h($app['message']) ?></p></div>
      </div>
      <?php endif; ?>

      <div class="card mb-3">
        <div class="card-header"><i class="bi bi-journal-text me-1" aria-hidden="true"></i>Notatki wewnętrzne</div>
        <div class="card-body">
          <form method="post" class="mb-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="note_add">
            <label class="form-label visually-hidden" for="note-body">Treść notatki</label>
            <textarea class="form-control form-control-sm mb-2" id="note-body" name="body" rows="2" required
                      placeholder="Notatka widoczna tylko dla zespołu rekrutacji…"></textarea>
            <button class="btn btn-sm btn-outline-primary">Zapisz notatkę</button>
          </form>
          <?php if (!$notes): ?><p class="text-muted small mb-0">Brak notatek.</p><?php endif; ?>
          <?php foreach ($notes as $n): ?>
          <div class="border-start border-3 ps-3 mb-3">
            <div class="small text-muted"><?= h($n['user_name'] ?: 'system') ?> · <?= h(date('d.m.Y H:i', strtotime($n['created_at']))) ?></div>
            <div style="white-space:pre-wrap"><?= h($n['body']) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><i class="bi bi-envelope-paper me-1" aria-hidden="true"></i>Wysłane wiadomości</div>
        <ul class="list-group list-group-flush">
          <?php if (!$emails): ?><li class="list-group-item text-muted">Nic jeszcze nie wysłano.</li><?php endif; ?>
          <?php foreach ($emails as $m): ?>
          <li class="list-group-item">
            <div class="d-flex justify-content-between">
              <strong class="small"><?= h($m['subject']) ?></strong>
              <span class="badge text-bg-<?= str_starts_with($m['result'], 'sent') ? 'success' : 'danger' ?>"><?= h($m['result']) ?></span>
            </div>
            <div class="small text-muted"><?= h($m['sender_name'] ?: 'automatyzacja') ?> · <?= h(date('d.m.Y H:i', strtotime($m['created_at']))) ?></div>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>

    <!-- Prawa kolumna: status, tagi, rozmowy, historia -->
    <div class="col-12 col-lg-5">

      <div class="card mb-3">
        <div class="card-header"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Zmiana statusu</div>
        <div class="card-body">
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="status">
            <div class="mb-2">
              <label class="form-label small mb-1" for="st-sel">Nowy status</label>
              <select class="form-select form-select-sm" id="st-sel" name="status">
                <?php foreach ($statuses as $s): ?>
                <option value="<?= h($s['slug']) ?>" <?= $s['slug'] === $app['status'] ? 'selected' : '' ?>><?= h($s['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-2">
              <label class="form-label small mb-1" for="st-note">Komentarz (do historii)</label>
              <input type="text" class="form-control form-control-sm" id="st-note" name="note" maxlength="2000">
            </div>
            <button class="btn btn-sm btn-primary">Zmień status</button>
            <div class="form-text">Zmiana statusu uruchamia skonfigurowane automatyzacje (e-mail, tagi, grupy CRM).</div>
          </form>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header"><i class="bi bi-tags me-1" aria-hidden="true"></i>Tagi</div>
        <div class="card-body">
          <?php foreach ($tags as $t): ?>
          <form method="post" class="d-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="tag_del"><input type="hidden" name="tag" value="<?= h($t) ?>">
            <span class="badge rounded-pill text-bg-light border mb-1">
              <?= h($t) ?>
              <button class="btn btn-link btn-sm p-0 ms-1 align-baseline" style="line-height:1" aria-label="Usuń tag <?= h($t) ?>">×</button>
            </span>
          </form>
          <?php endforeach; ?>
          <form method="post" class="d-flex gap-2 mt-2">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="tag_add">
            <label class="visually-hidden" for="tag-new">Nowy tag</label>
            <input type="text" class="form-control form-control-sm" id="tag-new" name="tag" maxlength="60" placeholder="np. angielski-b2" required>
            <button class="btn btn-sm btn-outline-primary">Dodaj</button>
          </form>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header"><i class="bi bi-calendar-event me-1" aria-hidden="true"></i>Rozmowy kwalifikacyjne</div>
        <div class="card-body">
          <?php $tidycal = rekr_tidycal_type(); if ($tidycal): ?>
          <div class="alert alert-light border d-flex justify-content-between align-items-center gap-2 py-2">
            <div class="small">
              <i class="bi bi-calendar2-plus me-1" aria-hidden="true"></i>
              Samodzielne umawianie (TidyCal): <strong><?= h($tidycal['title']) ?></strong>
              <?= $tidycal['duration'] ? '(' . (int)$tidycal['duration'] . ' min)' : '' ?>
              — w szablonach dostępny jako <code>{{link_tidycal}}</code>.
            </div>
            <div class="btn-group btn-group-sm flex-shrink-0">
              <a class="btn btn-outline-secondary" href="<?= h($tidycal['public_url']) ?>" target="_blank" rel="noopener">Otwórz</a>
              <button type="button" class="btn btn-outline-secondary" id="tc-copy"
                      data-url="<?= h($tidycal['public_url']) ?>">Kopiuj link</button>
            </div>
          </div>
          <?php endif; ?>
          <?php foreach ($interviews as $iv): ?>
          <div class="d-flex justify-content-between align-items-start border-bottom pb-2 mb-2">
            <div class="small">
              <strong><?= h(date('d.m.Y H:i', strtotime($iv['starts_at']))) ?></strong> (<?= (int)$iv['duration_min'] ?> min)
              <span class="badge text-bg-<?= ['zaplanowana' => 'warning', 'odbyta' => 'success', 'odwolana' => 'secondary'][$iv['status']] ?? 'light' ?>"><?= h($iv['status']) ?></span>
              <div class="text-muted">
                <?= $iv['interviewer_name'] ? 'Prowadzi: ' . h($iv['interviewer_name']) : '' ?>
                <?= $iv['location'] ? ' · ' . h($iv['location']) : '' ?>
              </div>
              <?php if ($iv['note']): ?><div><?= h($iv['note']) ?></div><?php endif; ?>
            </div>
            <?php if ($iv['status'] === 'zaplanowana'): ?>
            <div class="btn-group btn-group-sm">
              <?php foreach (['odbyta' => 'Odbyta', 'odwolana' => 'Odwołaj'] as $val => $lbl): ?>
              <form method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="interview_status">
                <input type="hidden" name="interview_id" value="<?= (int)$iv['id'] ?>">
                <input type="hidden" name="value" value="<?= h($val) ?>">
                <button class="btn btn-outline-secondary btn-sm"><?= h($lbl) ?></button>
              </form>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>

          <form method="post" class="row g-2">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="interview_add">
            <div class="col-7">
              <label class="form-label small mb-1" for="iv-start">Termin</label>
              <input type="datetime-local" class="form-control form-control-sm" id="iv-start" name="starts_at" required>
            </div>
            <div class="col-5">
              <label class="form-label small mb-1" for="iv-dur">Czas (min)</label>
              <input type="number" class="form-control form-control-sm" id="iv-dur" name="duration_min" value="30" min="5" max="480">
            </div>
            <div class="col-12">
              <label class="form-label small mb-1" for="iv-loc">Miejsce / link do spotkania</label>
              <input type="text" class="form-control form-control-sm" id="iv-loc" name="location" maxlength="500" placeholder="biuro / https://…">
            </div>
            <div class="col-12">
              <label class="form-label small mb-1" for="iv-who">Rekruter prowadzący</label>
              <select class="form-select form-select-sm" id="iv-who" name="interviewer_id">
                <option value="">— nie wskazano —</option>
                <?php foreach ($operators as $op): ?>
                <option value="<?= (int)$op['id'] ?>" <?= (int)$op['id'] === $uid ? 'selected' : '' ?>><?= h($op['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12"><button class="btn btn-sm btn-outline-primary">Zaplanuj rozmowę</button></div>
          </form>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><i class="bi bi-clock-history me-1" aria-hidden="true"></i>Historia statusów</div>
        <ul class="list-group list-group-flush">
          <?php foreach ($history as $hrow): ?>
          <li class="list-group-item small">
            <?= $hrow['from_status'] ? rekr_status_badge($hrow['from_status']) . ' <i class="bi bi-arrow-right" aria-hidden="true"></i> ' : '' ?>
            <?= rekr_status_badge($hrow['to_status']) ?>
            <div class="text-muted"><?= h($hrow['changed_name'] ?: 'system') ?> · <?= h(date('d.m.Y H:i', strtotime($hrow['created_at']))) ?></div>
            <?php if ($hrow['note']): ?><div><?= h($hrow['note']) ?></div><?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </div>
</div>

<!-- Modal: wiadomość do kandydata (POZA zakładkami — zob. pułapka tab-pane) -->
<div class="modal fade" id="emailModal" tabindex="-1" aria-labelledby="emailModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form method="post" class="modal-content">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="email_send">
      <div class="modal-header">
        <h2 class="modal-title h5" id="emailModalLabel">Wiadomość do: <?= h($app['email']) ?></h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2">
          <label class="form-label small mb-1" for="em-tpl">Szablon (opcjonalnie)</label>
          <select class="form-select form-select-sm" id="em-tpl">
            <option value="">— pusta wiadomość —</option>
            <?php foreach ($templates as $t): ?><option value="<?= (int)$t['id'] ?>"><?= h($t['name']) ?></option><?php endforeach; ?>
          </select>
          <div class="form-text">Zmienne {{imie}}, {{stanowisko}}, {{termin_spotkania}}, {{link_do_spotkania}} są już podstawione pod tego kandydata.</div>
        </div>
        <div class="mb-2">
          <label class="form-label small mb-1" for="em-subject">Temat</label>
          <input type="text" class="form-control" id="em-subject" name="subject" maxlength="300" required>
        </div>
        <div class="mb-1">
          <span class="form-label small mb-1 d-block" id="em-editor-label">Treść</span>
          <div class="btn-toolbar gap-1 mb-1" role="toolbar" aria-label="Formatowanie">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-cmd="bold" title="Pogrubienie" aria-label="Pogrubienie"><i class="bi bi-type-bold" aria-hidden="true"></i></button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-cmd="italic" title="Kursywa" aria-label="Kursywa"><i class="bi bi-type-italic" aria-hidden="true"></i></button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-cmd="insertUnorderedList" title="Lista" aria-label="Lista punktowana"><i class="bi bi-list-ul" aria-hidden="true"></i></button>
          </div>
          <div id="em-editor" class="form-control" contenteditable="true" role="textbox" aria-multiline="true"
               aria-labelledby="em-editor-label" style="min-height:180px;overflow:auto"></div>
          <textarea name="body" id="em-body" hidden></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
        <button class="btn btn-primary"><i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij</button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  const templates = <?= json_encode($tpl_js, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  const sel = document.getElementById('em-tpl');
  const subj = document.getElementById('em-subject');
  const editor = document.getElementById('em-editor');
  const hidden = document.getElementById('em-body');

  sel.addEventListener('change', () => {
    const t = templates[sel.value];
    if (!t) return;
    subj.value = t.subject;
    editor.innerHTML = t.body;   // treść renderowana serwerowo z szablonu zaufanego (admin)
  });
  document.querySelectorAll('#emailModal [data-cmd]').forEach(btn =>
    btn.addEventListener('click', () => { editor.focus(); document.execCommand(btn.dataset.cmd, false, null); }));
  // Przy submit przenieś HTML edytora do ukrytego pola (serwer i tak sanityzuje)
  editor.closest('form').addEventListener('submit', () => { hidden.value = editor.innerHTML; });

  // Kopiowanie linku TidyCal do schowka
  const tcCopy = document.getElementById('tc-copy');
  if (tcCopy) tcCopy.addEventListener('click', () => {
    navigator.clipboard.writeText(tcCopy.dataset.url).then(() => {
      tcCopy.textContent = 'Skopiowano ✓';
      setTimeout(() => { tcCopy.textContent = 'Kopiuj link'; }, 2000);
    });
  });
})();
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
