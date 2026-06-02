<?php
/**
 * crm/cases/view.php — Widok sprawy CRM: notatki, pliki, zmiana statusu.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_migrate();

$id  = (int)($_GET['id'] ?? 0);
$case = db_one(
    "SELECT c.*, ct.imie_nazwisko AS contact_name, ct.type AS contact_type, ct.id AS ct_id
     FROM crm_cases c
     LEFT JOIN crm_contacts ct ON ct.id=c.contact_id
     WHERE c.id=?", [$id]
);
if (!$case) { http_response_code(404); die('Nie znaleziono sprawy.'); }

$can_write = can_write('crm') || is_admin();
$PAGE_TITLE = 'Sprawa: ' . $case['title'];

$status_cfg = [
    'open'        => ['label'=>'Otwarta',   'color'=>'#2563EB','bg'=>'#EEF4FF','icon'=>'bi-circle'],
    'in_progress' => ['label'=>'W toku',    'color'=>'#D97706','bg'=>'#FEF3E2','icon'=>'bi-arrow-clockwise'],
    'closed'      => ['label'=>'Zamknięta', 'color'=>'#2E844A','bg'=>'#EFF7ED','icon'=>'bi-check-circle'],
    'cancelled'   => ['label'=>'Anulowana', 'color'=>'#9CA3AF','bg'=>'#F3F4F6','icon'=>'bi-x-circle'],
];
$priority_cfg = [
    'low'    => ['label'=>'Niski',  'color'=>'#6B7280'],
    'medium' => ['label'=>'Średni', 'color'=>'#D97706'],
    'high'   => ['label'=>'Wysoki', 'color'=>'#DC2626'],
];

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_write) {
    csrf_check();
    $action = $_POST['_action'] ?? '';
    $uid = (int)(current_user()['id'] ?? 0);

    // Zmiana statusu
    if ($action === 'set_status') {
        $ns = $_POST['status'] ?? '';
        if (array_key_exists($ns, $status_cfg)) {
            db()->prepare(
                "UPDATE crm_cases SET status=?, updated_at=?, closed_at=? WHERE id=?"
            )->execute([
                $ns, date('Y-m-d H:i:s'),
                in_array($ns, ['closed','cancelled']) ? date('Y-m-d H:i:s') : null,
                $id,
            ]);
            flash_set('success', 'Status zmieniony na: ' . $status_cfg[$ns]['label']);
        }
        header('Location: view.php?id=' . $id . '#status'); exit;
    }

    // Edycja tytułu/opisu
    if ($action === 'edit_meta') {
        $title = trim($_POST['title'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $prio  = in_array($_POST['priority']??'', ['low','medium','high']) ? $_POST['priority'] : $case['priority'];
        if ($title) {
            db()->prepare("UPDATE crm_cases SET title=?, description=?, priority=?, updated_at=? WHERE id=?")
                ->execute([$title, $desc ?: null, $prio, date('Y-m-d H:i:s'), $id]);
            flash_set('success', 'Sprawa zaktualizowana.');
        }
        header('Location: view.php?id=' . $id); exit;
    }

    // Dodaj notatkę
    if ($action === 'add_note') {
        $body = trim($_POST['note_body'] ?? '');
        if ($body) {
            db_insert('crm_case_notes', [
                'case_id'    => $id,
                'body'       => $body,
                'created_by' => $uid,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            db()->prepare("UPDATE crm_cases SET updated_at=? WHERE id=?")->execute([date('Y-m-d H:i:s'), $id]);
            flash_set('success', 'Notatka dodana.');
        }
        header('Location: view.php?id=' . $id . '#notes'); exit;
    }

    // Usuń notatkę
    if ($action === 'delete_note') {
        $nid = (int)($_POST['note_id'] ?? 0);
        $note = db_one("SELECT * FROM crm_case_notes WHERE id=? AND case_id=?", [$nid, $id]);
        if ($note && ($note['created_by'] == $uid || is_admin())) {
            db()->prepare("DELETE FROM crm_case_notes WHERE id=?")->execute([$nid]);
        }
        header('Location: view.php?id=' . $id . '#notes'); exit;
    }

    // Upload pliku
    if ($action === 'upload_file') {
        $f = $_FILES['case_file'] ?? null;
        if ($f && $f['error'] === UPLOAD_ERR_OK) {
            $dir = dirname(dirname(__DIR__)) . '/uploads/crm_cases/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);

            $ext     = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            $allowed = ['pdf','docx','doc','xlsx','xls','csv','txt','jpg','jpeg','png','gif','zip'];
            if (!in_array($ext, $allowed)) {
                flash_set('danger', 'Niedozwolone rozszerzenie pliku.');
            } else {
                $stored   = date('Ymd_His') . '_' . substr(bin2hex(random_bytes(6)), 0, 8) . '.' . $ext;
                $disp     = trim($_POST['file_display_name'] ?? '') ?: $f['name'];
                $file_desc= trim($_POST['file_description'] ?? '');
                move_uploaded_file($f['tmp_name'], $dir . $stored);
                db_insert('crm_case_files', [
                    'case_id'       => $id,
                    'original_name' => $f['name'],
                    'stored_path'   => 'crm_cases/' . $stored,
                    'display_name'  => $disp,
                    'description'   => $file_desc ?: null,
                    'file_size'     => $f['size'],
                    'mime_type'     => $f['type'],
                    'created_by'    => $uid,
                    'created_at'    => date('Y-m-d H:i:s'),
                ]);
                db()->prepare("UPDATE crm_cases SET updated_at=? WHERE id=?")->execute([date('Y-m-d H:i:s'), $id]);
                flash_set('success', 'Plik „' . h($disp) . '" dodany.');
            }
        }
        header('Location: view.php?id=' . $id . '#files'); exit;
    }

    // Usuń plik
    if ($action === 'delete_file') {
        $fid  = (int)($_POST['file_id'] ?? 0);
        $file = db_one("SELECT * FROM crm_case_files WHERE id=? AND case_id=?", [$fid, $id]);
        if ($file && ($file['created_by'] == $uid || is_admin())) {
            $path = dirname(dirname(__DIR__)) . '/uploads/' . $file['stored_path'];
            if (is_file($path)) unlink($path);
            db()->prepare("DELETE FROM crm_case_files WHERE id=?")->execute([$fid]);
            flash_set('success', 'Plik usunięty.');
        }
        header('Location: view.php?id=' . $id . '#files'); exit;
    }
}

// Pobierz notatki i pliki
$notes = db_all(
    "SELECT n.*, u.name AS user_name, u.first_name, u.last_name
     FROM crm_case_notes n
     LEFT JOIN users u ON u.id=n.created_by
     WHERE n.case_id=? ORDER BY n.created_at ASC",
    [$id]
);
$files = db_all(
    "SELECT f.*, u.name AS user_name
     FROM crm_case_files f
     LEFT JOIN users u ON u.id=f.created_by
     WHERE f.case_id=? ORDER BY f.created_at DESC",
    [$id]
);

$sc = $status_cfg[$case['status']] ?? $status_cfg['open'];
$pc = $priority_cfg[$case['priority']] ?? $priority_cfg['medium'];

include dirname(__DIR__) . '/includes/header_crm.php';
?>

<style>
.case-note { background:#FFFBEA;border:1px solid #FDE68A;border-radius:8px;padding:.8rem 1rem;margin-bottom:.6rem }
.case-note-body { font-size:.88rem;color:#111827;white-space:pre-wrap;word-break:break-word }
.case-note-meta { font-size:.73rem;color:#9CA3AF;margin-top:.35rem }
.file-row { display:flex;align-items:center;gap:.75rem;padding:.6rem .9rem;border-bottom:1px solid #F3F4F6;font-size:.84rem }
.file-row:last-child { border-bottom:none }
.file-icon { width:34px;height:34px;border-radius:7px;background:#EEF4FF;color:#0176D3;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0 }
.case-section-title { font-size:.7rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#6B7280;padding-bottom:.4rem;border-bottom:1px solid #F3F4F6;margin-bottom:.75rem }
</style>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-3" style="font-size:.82rem">
  <ol class="breadcrumb mb-0">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/dashboard.php">CRM</a></li>
    <li class="breadcrumb-item"><a href="index.php">Sprawy</a></li>
    <li class="breadcrumb-item active"><?= h($case['title']) ?></li>
  </ol>
</nav>

<!-- Nagłówek sprawy -->
<div class="card border-0 shadow-sm mb-3" style="border-radius:12px;overflow:hidden">
  <div style="background:linear-gradient(90deg,#1E3A5F,#0176D3);padding:1.25rem 1.5rem;color:#fff">
    <div class="d-flex align-items-start gap-3">
      <div style="width:44px;height:44px;border-radius:10px;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0">
        <i class="bi bi-briefcase-fill"></i>
      </div>
      <div style="flex:1;min-width:0">
        <h1 style="font-size:1.15rem;font-weight:700;margin:0 0 .3rem;color:#fff"><?= h($case['title']) ?></h1>
        <div style="font-size:.82rem;opacity:.8">
          <i class="bi bi-person me-1"></i>
          <a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= $case['ct_id'] ?>" style="color:#93C5FD">
            <?= h($case['contact_name']) ?>
          </a>
          &nbsp;·&nbsp;
          <i class="bi bi-calendar3 me-1"></i><?= date_pl($case['created_at']) ?>
          <?php if ($case['closed_at']): ?>
          &nbsp;·&nbsp;<i class="bi bi-flag me-1"></i>Zamknięta <?= date_pl($case['closed_at']) ?>
          <?php endif; ?>
        </div>
      </div>
      <div class="d-flex align-items-center gap-2 flex-shrink-0">
        <span style="display:inline-flex;align-items:center;gap:.3rem;padding:.25rem .75rem;border-radius:2rem;font-size:.75rem;font-weight:600;background:rgba(255,255,255,.2);color:#fff">
          <span style="width:7px;height:7px;border-radius:50%;background:<?= $pc['color'] ?>;display:inline-block"></span>
          <?= $pc['label'] ?>
        </span>
        <span style="display:inline-flex;align-items:center;gap:.3rem;padding:.25rem .75rem;border-radius:2rem;font-size:.75rem;font-weight:600;background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>">
          <i class="bi <?= $sc['icon'] ?>"></i><?= $sc['label'] ?>
        </span>
      </div>
    </div>
  </div>
  <?php if ($case['description']): ?>
  <div class="card-body" style="font-size:.88rem;color:#374151;white-space:pre-wrap;background:#FAFAFA">
    <?= h($case['description']) ?>
  </div>
  <?php endif; ?>
</div>

<div class="row g-3">

<!-- ══ LEWA: notatki + pliki ═══════════════════════════════════════════════ -->
<div class="col-lg-8">

  <!-- ── NOTATKI ─────────────────────────────────────────────────────────── -->
  <div class="card border-0 shadow-sm mb-3" id="notes">
    <div class="card-body">
      <div class="case-section-title"><i class="bi bi-chat-dots me-1"></i>Notatki (<?= count($notes) ?>)</div>

      <?php if ($notes): ?>
      <?php foreach ($notes as $n):
        $un = trim(($n['first_name']??'').' '.($n['last_name']??'')) ?: ($n['user_name']??'?');
      ?>
      <div class="case-note">
        <div class="case-note-body"><?= h($n['body']) ?></div>
        <div class="case-note-meta d-flex justify-content-between">
          <span><i class="bi bi-person me-1"></i><?= h($un) ?> · <?= date('d.m.Y H:i', strtotime($n['created_at'])) ?></span>
          <?php if ($can_write && ($n['created_by']==(current_user()['id']??0) || is_admin())): ?>
          <form method="post" class="d-inline" onsubmit="return confirm('Usunąć notatkę?')">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="_action" value="delete_note">
            <input type="hidden" name="note_id" value="<?= (int)$n['id'] ?>">
            <button type="submit" class="btn btn-link btn-sm text-danger py-0 px-1" style="font-size:.72rem">
              <i class="bi bi-trash"></i> Usuń
            </button>
          </form>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <?php else: ?>
      <div class="text-muted text-center py-3" style="font-size:.85rem">
        <i class="bi bi-chat d-block mb-2 opacity-25" style="font-size:1.5rem"></i>
        Brak notatek — dodaj pierwszą poniżej.
      </div>
      <?php endif; ?>

      <?php if ($can_write): ?>
      <form method="post" class="mt-3 pt-3 border-top" id="note-form">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="add_note">
        <label class="form-label fw-semibold small">Dodaj notatkę</label>
        <textarea name="note_body" class="form-control form-control-sm mb-2" rows="3"
                  placeholder="Wpisz notatkę do sprawy… (Ctrl+Enter zapisuje)"
                  id="noteBody" required></textarea>
        <button type="submit" class="btn btn-warning btn-sm">
          <i class="bi bi-chat-dots me-1"></i>Dodaj notatkę
        </button>
      </form>
      <script>
      document.getElementById('noteBody')?.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
          this.closest('form').submit();
        }
      });
      </script>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── PLIKI ───────────────────────────────────────────────────────────── -->
  <div class="card border-0 shadow-sm" id="files">
    <div class="card-body">
      <div class="case-section-title"><i class="bi bi-paperclip me-1"></i>Pliki (<?= count($files) ?>)</div>

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
        <div class="file-icon">
          <i class="bi <?= $ic ?>"></i>
        </div>
        <div style="flex:1;min-width:0">
          <div class="fw-semibold" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
            <?= h($disp) ?>
          </div>
          <?php if ($f['description']): ?>
          <div class="text-muted" style="font-size:.75rem"><?= h($f['description']) ?></div>
          <?php endif; ?>
          <div style="font-size:.72rem;color:#9CA3AF">
            <?= h($f['original_name']) ?> <?= $size ? "· $size" : '' ?>
            · <?= date('d.m.Y H:i', strtotime($f['created_at'])) ?>
          </div>
        </div>
        <div class="d-flex gap-1 flex-shrink-0">
          <a href="<?= APP_URL ?>/crm/cases/download.php?id=<?= (int)$f['id'] ?>"
             class="btn btn-sm btn-outline-primary py-0 px-2" title="Pobierz">
            <i class="bi bi-download"></i>
          </a>
          <?php if ($can_write && ($f['created_by']==(current_user()['id']??0) || is_admin())): ?>
          <form method="post" class="d-inline" onsubmit="return confirm('Usunąć plik?')">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="_action" value="delete_file">
            <input type="hidden" name="file_id" value="<?= (int)$f['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" title="Usuń">
              <i class="bi bi-trash"></i>
            </button>
          </form>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>

      <?php if ($can_write): ?>
      <form method="post" enctype="multipart/form-data"
            class="<?= $files ? 'mt-3 pt-3 border-top' : 'mt-2' ?>" id="upload-form">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="upload_file">
        <div class="case-section-title mb-2">Dodaj plik</div>
        <div class="row g-2">
          <div class="col-12">
            <input type="file" name="case_file" class="form-control form-control-sm" required
                   accept=".pdf,.docx,.doc,.xlsx,.xls,.csv,.txt,.jpg,.jpeg,.png,.gif,.zip">
          </div>
          <div class="col-sm-6">
            <input name="file_display_name" class="form-control form-control-sm"
                   placeholder="Nazwa wyświetlana (opcjonalna)">
          </div>
          <div class="col-sm-6">
            <input name="file_description" class="form-control form-control-sm"
                   placeholder="Opis / uwagi (opcjonalny)">
          </div>
          <div class="col-12">
            <button type="submit" class="btn btn-outline-primary btn-sm">
              <i class="bi bi-upload me-1"></i>Dodaj plik
            </button>
          </div>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>

</div><!-- /col-8 -->

<!-- ══ PRAWA: sidebar ═══════════════════════════════════════════════════════ -->
<div class="col-lg-4">

  <!-- Status -->
  <?php if ($can_write): ?>
  <div class="card border-0 shadow-sm mb-3" id="status">
    <div class="card-body">
      <div class="case-section-title">Zmień status</div>
      <form method="post" class="d-flex flex-column gap-2">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="set_status">
        <?php foreach ($status_cfg as $sv=>$sd): ?>
        <button type="submit" name="status" value="<?= $sv ?>"
                class="btn btn-sm text-start d-flex align-items-center gap-2 <?= $case['status']===$sv?'fw-bold':'' ?>"
                style="background:<?= $sd['bg'] ?>;color:<?= $sd['color'] ?>;border:1.5px solid <?= $case['status']===$sv?$sd['color']:'transparent' ?>">
          <i class="bi <?= $sd['icon'] ?>"></i><?= $sd['label'] ?>
          <?php if ($case['status']===$sv): ?><i class="bi bi-check-lg ms-auto"></i><?php endif; ?>
        </button>
        <?php endforeach; ?>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- Szczegóły sprawy -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <div class="case-section-title">Szczegóły</div>
      <table class="table table-sm table-borderless mb-0" style="font-size:.82rem">
        <tr><td class="text-muted ps-0">Kontakt</td>
            <td><a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= $case['ct_id'] ?>"><?= h($case['contact_name']) ?></a></td></tr>
        <tr><td class="text-muted ps-0">Priorytet</td>
            <td><span style="color:<?= $pc['color'] ?>;font-weight:600">
              <span style="width:7px;height:7px;border-radius:50%;background:<?= $pc['color'] ?>;display:inline-block;margin-right:4px"></span>
              <?= $pc['label'] ?>
            </span></td></tr>
        <tr><td class="text-muted ps-0">Utworzona</td><td><?= date('d.m.Y H:i', strtotime($case['created_at'])) ?></td></tr>
        <tr><td class="text-muted ps-0">Zmieniona</td><td><?= date('d.m.Y H:i', strtotime($case['updated_at'])) ?></td></tr>
        <?php if ($case['closed_at']): ?>
        <tr><td class="text-muted ps-0">Zamknięta</td><td><?= date('d.m.Y H:i', strtotime($case['closed_at'])) ?></td></tr>
        <?php endif; ?>
        <tr><td class="text-muted ps-0">Notatki</td><td><?= count($notes) ?></td></tr>
        <tr><td class="text-muted ps-0">Pliki</td><td><?= count($files) ?></td></tr>
      </table>
    </div>
  </div>

  <!-- Edycja tytułu/opisu -->
  <?php if ($can_write): ?>
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <div class="case-section-title">Edytuj sprawę</div>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="edit_meta">
        <div class="mb-2">
          <label class="form-label small fw-semibold">Tytuł</label>
          <input name="title" class="form-control form-control-sm" value="<?= h($case['title']) ?>" required>
        </div>
        <div class="mb-2">
          <label class="form-label small fw-semibold">Opis</label>
          <textarea name="description" class="form-control form-control-sm" rows="3"><?= h($case['description']) ?></textarea>
        </div>
        <div class="mb-2">
          <label class="form-label small fw-semibold">Priorytet</label>
          <select name="priority" class="form-select form-select-sm">
            <?php foreach ($priority_cfg as $pv=>$pd): ?>
            <option value="<?= $pv ?>" <?= $case['priority']===$pv?'selected':'' ?>><?= $pd['label'] ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" class="btn btn-outline-primary btn-sm w-100">
          <i class="bi bi-floppy me-1"></i>Zapisz zmiany
        </button>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- Linki -->
  <div class="d-flex flex-column gap-2">
    <a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= $case['ct_id'] ?>" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-person me-1"></i>Otwórz kartę kontaktu
    </a>
    <a href="add.php?contact_id=<?= $case['ct_id'] ?>" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-plus me-1"></i>Nowa sprawa dla tego kontaktu
    </a>
    <a href="index.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-arrow-left me-1"></i>Wszystkie sprawy
    </a>
  </div>

</div><!-- /col-4 -->
</div><!-- /row -->

<?php include dirname(__DIR__) . '/includes/footer_crm.php'; ?>
