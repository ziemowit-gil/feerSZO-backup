<?php
/**
 * crm/cases/view.php — Widok sprawy CRM: notatki, pliki, zmiana statusu.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/letters.php';

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

    // Dodaj pismo
    if ($action === 'add_pismo') {
        $tytul      = trim($_POST['tytul']           ?? '');
        $kierunek   = $_POST['kierunek']              ?? 'wychodzące';
        $typ        = $_POST['typ_pisma']             ?? 'inne';
        $tresc      = trim($_POST['tresc']            ?? '');
        $nadawca    = trim($_POST['nadawca']          ?? '');
        $odbiorca   = trim($_POST['odbiorca']         ?? '');
        $data_p     = trim($_POST['data_pisma']       ?? '') ?: date('Y-m-d');

        if (!$tytul) {
            flash_set('danger', 'Tytuł pisma jest wymagany.');
        } elseif (!array_key_exists($kierunek, LETTER_DIRECTIONS)) {
            flash_set('danger', 'Nieprawidłowy kierunek pisma.');
        } else {
            $plik = handle_letter_upload('pismo_plik');
            create_letter([
                'contract_type'      => 'crm_case',
                'contract_id'        => $id,
                'kierunek'           => $kierunek,
                'typ_pisma'          => array_key_exists($typ, LETTER_TYPES) ? $typ : 'inne',
                'tytul'              => $tytul,
                'tresc'              => $tresc ?: null,
                'plik'               => $plik,
                'data_pisma'         => $data_p,
                'nadawca'            => $nadawca ?: null,
                'odbiorca'           => $odbiorca ?: null,
                'odbiorca_email'     => trim($_POST['odbiorca_email']    ?? '') ?: null,
                'uwagi'              => trim($_POST['uwagi']              ?? '') ?: null,
                'sygnatura'          => trim($_POST['sygnatura']          ?? '') ?: null,
                'miejsce'            => trim($_POST['miejsce']            ?? '') ?: null,
                'sposob_doreczenia'  => trim($_POST['sposob_doreczenia']  ?? '') ?: null,
                'pilnosc'            => trim($_POST['pilnosc']            ?? '') ?: 'zwykłe',
                'termin_odpowiedzi'  => trim($_POST['termin_odpowiedzi']  ?? '') ?: null,
                'kopia_do'           => trim($_POST['kopia_do']           ?? '') ?: null,
                'podpisujacy_id'     => ((int)($_POST['podpisujacy_id']   ?? 0)) ?: null,
                'podstawa_prawna'    => trim($_POST['podstawa_prawna']    ?? '') ?: null,
                'nr_nadania'         => trim($_POST['nr_nadania']         ?? '') ?: null,
                'adres_edoreczenia'  => trim($_POST['adres_edoreczenia']  ?? '') ?: null,
                'edoreczenia_ref'    => trim($_POST['edoreczenia_ref']    ?? '') ?: null,
                'email_sent'         => 0,
                'created_by'         => $uid,
                'created_at'         => date('Y-m-d H:i:s'),
            ]);
            db()->prepare("UPDATE crm_cases SET updated_at=? WHERE id=?")->execute([date('Y-m-d H:i:s'), $id]);
            flash_set('success', 'Pismo „' . h($tytul) . '" dodane.');
        }
        header('Location: view.php?id=' . $id . '#pisma'); exit;
    }

    // Usuń pismo
    if ($action === 'delete_pismo') {
        $lid    = (int)($_POST['letter_id'] ?? 0);
        $letter = db_one("SELECT * FROM contract_letters WHERE id=? AND contract_type='crm_case' AND contract_id=?", [$lid, $id]);
        if ($letter && ($letter['created_by'] == $uid || is_admin())) {
            if ($letter['plik']) {
                $fp = dirname(dirname(__DIR__)) . '/uploads/letters/' . $letter['plik'];
                if (is_file($fp)) unlink($fp);
            }
            db()->prepare("DELETE FROM contract_letters WHERE id=?")->execute([$lid]);
            flash_set('success', 'Pismo usunięte.');
        }
        header('Location: view.php?id=' . $id . '#pisma'); exit;
    }

    // Edytuj pismo
    if ($action === 'edit_pismo') {
        $lid    = (int)($_POST['letter_id'] ?? 0);
        $letter = db_one("SELECT * FROM contract_letters WHERE id=? AND contract_type='crm_case' AND contract_id=?", [$lid, $id]);
        if ($letter && ($letter['created_by'] == $uid || is_admin())) {
            $tytul    = trim($_POST['tytul'] ?? '');
            $kierunek = $_POST['kierunek'] ?? $letter['kierunek'];
            if ($tytul && array_key_exists($kierunek, LETTER_DIRECTIONS)) {
                $typ = $_POST['typ_pisma'] ?? $letter['typ_pisma'];
                $sd  = trim($_POST['sposob_doreczenia'] ?? '') ?: null;
                db()->prepare(
                    "UPDATE contract_letters SET
                        kierunek=?, typ_pisma=?, tytul=?, tresc=?, data_pisma=?,
                        nadawca=?, odbiorca=?, odbiorca_email=?, uwagi=?,
                        sygnatura=?, miejsce=?, sposob_doreczenia=?, pilnosc=?,
                        termin_odpowiedzi=?, kopia_do=?, podpisujacy_id=?,
                        podstawa_prawna=?, nr_nadania=?, adres_edoreczenia=?, edoreczenia_ref=?
                     WHERE id=?"
                )->execute([
                    $kierunek,
                    array_key_exists($typ, LETTER_TYPES) ? $typ : 'inne',
                    $tytul,
                    trim($_POST['tresc']              ?? '') ?: null,
                    trim($_POST['data_pisma']          ?? '') ?: date('Y-m-d'),
                    trim($_POST['nadawca']             ?? '') ?: null,
                    trim($_POST['odbiorca']            ?? '') ?: null,
                    trim($_POST['odbiorca_email']      ?? '') ?: null,
                    trim($_POST['uwagi']               ?? '') ?: null,
                    trim($_POST['sygnatura']           ?? '') ?: null,
                    trim($_POST['miejsce']             ?? '') ?: null,
                    $sd,
                    trim($_POST['pilnosc']             ?? '') ?: 'zwykłe',
                    trim($_POST['termin_odpowiedzi']   ?? '') ?: null,
                    trim($_POST['kopia_do']            ?? '') ?: null,
                    ((int)($_POST['podpisujacy_id']    ?? 0)) ?: null,
                    trim($_POST['podstawa_prawna']     ?? '') ?: null,
                    trim($_POST['nr_nadania']          ?? '') ?: null,
                    trim($_POST['adres_edoreczenia']   ?? '') ?: null,
                    trim($_POST['edoreczenia_ref']     ?? '') ?: null,
                    $lid,
                ]);
                // Nowy plik (jeśli wgrany)
                $plik = handle_letter_upload('pismo_plik');
                if ($plik) {
                    if ($letter['plik']) {
                        $old = dirname(dirname(__DIR__)) . '/uploads/letters/' . $letter['plik'];
                        if (is_file($old)) unlink($old);
                    }
                    db()->prepare("UPDATE contract_letters SET plik=? WHERE id=?")->execute([$plik, $lid]);
                }
                db()->prepare("UPDATE crm_cases SET updated_at=? WHERE id=?")->execute([date('Y-m-d H:i:s'), $id]);
                flash_set('success', 'Pismo zaktualizowane.');
            }
        }
        header('Location: view.php?id=' . $id . '#pisma'); exit;
    }

    // Powiąż z EZD
    if ($action === 'link_ezd') {
        $sid = (int)($_POST['ezd_sprawa_id'] ?? 0);
        if ($sid && module_enabled('ezd_enabled')) {
            $sp = db_one("SELECT id FROM ezd_sprawy WHERE id=?", [$sid]);
            if ($sp) {
                db()->prepare("UPDATE crm_cases SET ezd_sprawa_id=?, updated_at=? WHERE id=?")
                    ->execute([$sid, date('Y-m-d H:i:s'), $id]);
                flash_set('success', 'Sprawa powiązana z EZD.');
            }
        }
        header('Location: view.php?id=' . $id . '#ezd'); exit;
    }

    // Odepnij EZD
    if ($action === 'unlink_ezd') {
        db()->prepare("UPDATE crm_cases SET ezd_sprawa_id=NULL, updated_at=? WHERE id=?")
            ->execute([date('Y-m-d H:i:s'), $id]);
        flash_set('success', 'Powiązanie z EZD usunięte.');
        header('Location: view.php?id=' . $id . '#ezd'); exit;
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

$letters     = get_contract_letters('crm_case', $id);
$users_list  = db_all("SELECT id, name FROM users WHERE is_active=1 ORDER BY name");
$ezd_sprawa  = null;
$ezd_sprawy_list = [];
if (module_enabled('ezd_enabled')) {
    if (!empty($case['ezd_sprawa_id'])) {
        $ezd_sprawa = db_one(
            "SELECT s.*, t.symbol AS teczka_symbol
             FROM ezd_sprawy s LEFT JOIN ezd_teczki t ON t.id=s.teczka_id
             WHERE s.id=?", [(int)$case['ezd_sprawa_id']]
        );
    }
    $ezd_sprawy_list = db_all(
        "SELECT s.id, s.znak_sprawy, s.title, s.status
         FROM ezd_sprawy s WHERE s.status != 'closed'
         ORDER BY s.updated_at DESC LIMIT 200"
    );
}

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
<div class="mb-3" id="case-header" style="border-radius:14px;overflow:hidden;border:1.5px solid #E5E7EB;background:#fff;box-shadow:0 1px 6px rgba(0,0,0,.06)">
  <!-- Pasek statusu -->
  <div style="height:4px;background:<?= $sc['color'] ?>"></div>

  <div style="padding:1.1rem 1.4rem 1rem">
    <div class="d-flex align-items-start gap-3 flex-wrap">

      <!-- Ikona + status badge -->
      <div style="flex-shrink:0;display:flex;flex-direction:column;align-items:center;gap:.4rem;margin-top:.1rem">
        <div style="width:42px;height:42px;border-radius:11px;background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>;display:flex;align-items:center;justify-content:center;font-size:1.25rem;border:1.5px solid <?= $sc['color'] ?>22">
          <i class="bi bi-briefcase-fill"></i>
        </div>
      </div>

      <!-- Treść główna -->
      <div style="flex:1;min-width:0">
        <div class="d-flex align-items-center flex-wrap gap-2 mb-1">
          <h1 style="font-size:1.1rem;font-weight:700;margin:0;color:#111827;line-height:1.3"><?= h($case['title']) ?></h1>
          <span style="display:inline-flex;align-items:center;gap:.3rem;padding:.18rem .65rem;border-radius:2rem;font-size:.72rem;font-weight:600;background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>;border:1px solid <?= $sc['color'] ?>44">
            <i class="bi <?= $sc['icon'] ?>"></i><?= $sc['label'] ?>
          </span>
          <span style="display:inline-flex;align-items:center;gap:.3rem;padding:.18rem .65rem;border-radius:2rem;font-size:.72rem;font-weight:600;background:#F9FAFB;color:<?= $pc['color'] ?>;border:1px solid <?= $pc['color'] ?>55">
            <span style="width:6px;height:6px;border-radius:50%;background:<?= $pc['color'] ?>;display:inline-block"></span>
            <?= $pc['label'] ?>
          </span>
        </div>

        <!-- Metadane w wierszu -->
        <div style="font-size:.79rem;color:#6B7280;display:flex;flex-wrap:wrap;gap:.1rem .9rem;margin-top:.25rem">
          <span>
            <i class="bi bi-person me-1" style="color:#9CA3AF"></i>
            <a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= $case['ct_id'] ?>" style="color:#0176D3;text-decoration:none;font-weight:500">
              <?= h($case['contact_name']) ?>
            </a>
          </span>
          <span><i class="bi bi-calendar3 me-1" style="color:#9CA3AF"></i><?= date('d.m.Y', strtotime($case['created_at'])) ?></span>
          <span><i class="bi bi-chat me-1" style="color:#9CA3AF"></i><?= count($notes) ?> notatek</span>
          <span><i class="bi bi-paperclip me-1" style="color:#9CA3AF"></i><?= count($files) ?> plików</span>
          <span><i class="bi bi-envelope me-1" style="color:#9CA3AF"></i><?= count($letters) ?> pism</span>
          <?php if ($case['closed_at']): ?>
          <span style="color:#D97706"><i class="bi bi-flag me-1"></i>Zamknięta <?= date('d.m.Y', strtotime($case['closed_at'])) ?></span>
          <?php endif; ?>
          <?php if ($ezd_sprawa): ?>
          <span><i class="bi bi-folder2-open me-1" style="color:#9CA3AF"></i>
            <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= (int)$ezd_sprawa['id'] ?>" style="color:#0176D3;text-decoration:none">
              <?= h($ezd_sprawa['znak_sprawy']) ?>
            </a>
          </span>
          <?php endif; ?>
        </div>
      </div>

      <!-- Akcje -->
      <?php if ($can_write): ?>
      <div class="d-flex align-items-start gap-2 flex-shrink-0 flex-wrap">
        <button type="button" class="btn btn-sm btn-warning"
                data-bs-toggle="modal" data-bs-target="#modalPismo" style="font-size:.8rem">
          <i class="bi bi-envelope-plus me-1"></i>Nowe pismo
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary"
                data-bs-toggle="collapse" data-bs-target="#collapseEditMeta" style="font-size:.8rem">
          <i class="bi bi-pencil me-1"></i>Edytuj
        </button>
      </div>
      <?php endif; ?>
    </div>

    <!-- Opis -->
    <?php if ($case['description']): ?>
    <div style="margin-top:.75rem;padding:.6rem .8rem;background:#F8FAFF;border-radius:8px;font-size:.84rem;color:#374151;white-space:pre-wrap;border-left:3px solid <?= $sc['color'] ?>55">
      <?= h($case['description']) ?>
    </div>
    <?php endif; ?>

    <!-- Edycja inline (collapse) -->
    <?php if ($can_write): ?>
    <div class="collapse mt-3 pt-3 border-top" id="collapseEditMeta">
      <form method="post" class="row g-2">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="edit_meta">
        <div class="col-sm-8">
          <input name="title" class="form-control form-control-sm" value="<?= h($case['title']) ?>" placeholder="Tytuł" required>
        </div>
        <div class="col-sm-4">
          <select name="priority" class="form-select form-select-sm">
            <?php foreach ($priority_cfg as $pv => $pd): ?>
            <option value="<?= $pv ?>" <?= $case['priority']===$pv?'selected':'' ?>><?= $pd['label'] ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12">
          <textarea name="description" class="form-control form-control-sm" rows="2" placeholder="Opis (opcjonalny)"><?= h($case['description']) ?></textarea>
        </div>
        <div class="col-auto">
          <button type="submit" class="btn btn-sm btn-primary">
            <i class="bi bi-floppy me-1"></i>Zapisz
          </button>
          <button type="button" class="btn btn-sm btn-outline-secondary ms-1" data-bs-toggle="collapse" data-bs-target="#collapseEditMeta">
            Anuluj
          </button>
        </div>
      </form>
    </div>
    <?php endif; ?>
  </div>
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

  <!-- ── PISMA ─────────────────────────────────────────────────────────── -->
  <div class="card border-0 shadow-sm mb-3" id="pisma">
    <div class="card-body">
      <div class="case-section-title d-flex justify-content-between align-items-center">
        <span><i class="bi bi-envelope me-1"></i>Pisma (<?= count($letters) ?>)</span>
        <?php if ($can_write): ?>
        <button type="button" class="btn btn-warning btn-sm py-0 px-2"
                data-bs-toggle="modal" data-bs-target="#modalPismo" style="font-size:.75rem">
          <i class="bi bi-plus me-1"></i>Nowe pismo
        </button>
        <?php endif; ?>
      </div>

      <?php if ($letters): ?>
      <?php foreach ($letters as $l):
        $lt  = LETTER_TYPES[$l['typ_pisma']] ?? LETTER_TYPES['inne'];
        $ld  = LETTER_DIRECTIONS[$l['kierunek']] ?? ['label'=>$l['kierunek'],'class'=>'secondary','icon'=>'bi-arrow-right'];
        $is_edoreczenia = ($l['sposob_doreczenia'] === 'edoreczenia');
        $has_nadania    = !empty($l['nr_nadania']) && in_array($l['sposob_doreczenia'], ['kurier','poczta']);
        $ldata = json_encode($l, JSON_HEX_APOS | JSON_HEX_QUOT);
      ?>
      <div style="background:#F8FAFF;border:1px solid #DBEAFE;border-radius:8px;padding:.65rem 1rem;margin-bottom:.5rem;font-size:.84rem">
        <div class="d-flex align-items-start gap-2">
          <div style="flex:1;min-width:0">
            <div class="fw-semibold" style="color:#1E3A5F"><?= h($l['tytul']) ?></div>
            <div class="d-flex flex-wrap gap-1 mt-1">
              <span class="badge bg-<?= $ld['class'] ?> bg-opacity-15 text-<?= $ld['class'] ?> border border-<?= $ld['class'] ?>" style="font-size:.63rem">
                <i class="bi <?= $ld['icon'] ?> me-1"></i><?= $ld['label'] ?>
              </span>
              <span class="badge bg-secondary bg-opacity-10 text-secondary border" style="font-size:.63rem">
                <i class="bi <?= $lt['icon'] ?> me-1"></i><?= $lt['label'] ?>
              </span>
              <?php if (!empty($l['pilnosc']) && $l['pilnosc'] !== 'zwykłe'): ?>
              <?php $pilnosc_cls = ['pilne'=>'warning','poufne'=>'danger','ściśle_tajne'=>'danger'][$l['pilnosc']] ?? 'secondary'; ?>
              <span class="badge bg-<?= $pilnosc_cls ?> bg-opacity-15 text-<?= $pilnosc_cls ?> border border-<?= $pilnosc_cls ?>" style="font-size:.63rem;text-transform:uppercase">
                <?= h($l['pilnosc']) ?>
              </span>
              <?php endif; ?>
              <?php if (!empty($l['sygnatura'])): ?>
              <code style="font-size:.65rem;color:#6B7280;background:#F3F4F6;padding:.1rem .35rem;border-radius:4px"><?= h($l['sygnatura']) ?></code>
              <?php endif; ?>
              <?php if ($is_edoreczenia): ?>
              <span class="badge" style="font-size:.63rem;background:#EEF2FF;color:#4338CA;border:1px solid #C7D2FE">
                <i class="bi bi-shield-check me-1"></i>eDoręczenia
              </span>
              <?php endif; ?>
            </div>
            <div style="font-size:.72rem;color:#9CA3AF;margin-top:.25rem;display:flex;flex-wrap:wrap;gap:0 .75rem">
              <?php if ($l['nadawca']): ?><span><?= h($l['nadawca']) ?> → <?= h($l['odbiorca'] ?? '—') ?></span><?php endif; ?>
              <?= $l['data_pisma'] ? '<span><i class="bi bi-calendar3 me-1"></i>' . date('d.m.Y', strtotime($l['data_pisma'])) . '</span>' : '' ?>
              <?php if (!empty($l['termin_odpowiedzi'])): ?>
              <span style="color:<?= $l['termin_odpowiedzi'] < date('Y-m-d') ? '#DC2626' : '#D97706' ?>">
                <i class="bi bi-alarm me-1"></i>do <?= date('d.m.Y', strtotime($l['termin_odpowiedzi'])) ?>
              </span>
              <?php endif; ?>
              <?php if (!empty($l['sposob_doreczenia'])): ?>
              <span><i class="bi bi-send me-1"></i><?= h($l['sposob_doreczenia']) ?></span>
              <?php endif; ?>
              <?= $l['created_by_name'] ? '<span>' . h($l['created_by_name']) . '</span>' : '' ?>
            </div>
            <?php if ($has_nadania): ?>
            <div style="margin-top:.35rem;display:inline-flex;align-items:center;gap:.4rem;background:#FFF7ED;border:1px solid #FED7AA;border-radius:6px;padding:.2rem .55rem;font-size:.73rem">
              <i class="bi bi-truck text-warning"></i>
              <span class="text-muted">Nr nadania:</span>
              <strong style="color:#92400E;font-family:monospace"><?= h($l['nr_nadania']) ?></strong>
            </div>
            <?php endif; ?>
            <?php if ($is_edoreczenia && (!empty($l['adres_edoreczenia']) || !empty($l['edoreczenia_ref']))): ?>
            <div style="margin-top:.35rem;display:inline-flex;align-items:center;gap:.4rem;background:#EEF2FF;border:1px solid #C7D2FE;border-radius:6px;padding:.2rem .55rem;font-size:.73rem">
              <i class="bi bi-shield-check" style="color:#4338CA"></i>
              <?php if (!empty($l['adres_edoreczenia'])): ?>
              <span class="text-muted">ADE:</span>
              <code style="font-size:.7rem;color:#3730A3"><?= h($l['adres_edoreczenia']) ?></code>
              <?php endif; ?>
              <?php if (!empty($l['edoreczenia_ref'])): ?>
              <span class="text-muted ms-1">Ref:</span>
              <code style="font-size:.7rem;color:#3730A3"><?= h($l['edoreczenia_ref']) ?></code>
              <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($l['tresc']): ?>
            <div style="font-size:.78rem;color:#374151;margin-top:.3rem;white-space:pre-wrap;max-height:3.5em;overflow:hidden"><?= h(mb_substr(strip_tags($l['tresc']),0,200)) ?></div>
            <?php endif; ?>
          </div>
          <div class="d-flex gap-1 flex-shrink-0 align-items-start mt-1">
            <?php if ($l['plik']): ?>
            <a href="<?= h(letter_file_url($l['plik'])) ?>" target="_blank" rel="noopener"
               class="btn btn-sm btn-outline-primary py-0 px-2" title="Pobierz załącznik">
              <i class="bi bi-download"></i>
            </a>
            <?php endif; ?>
            <?php if ($can_write): ?>
            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" title="Edytuj"
                    onclick="pismoEdit(<?= $ldata ?>)">
              <i class="bi bi-pencil"></i>
            </button>
            <?php endif; ?>
            <?php if ($can_write && ($l['created_by']==$uid || is_admin())): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Usunąć pismo?')">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="delete_pismo">
              <input type="hidden" name="letter_id" value="<?= (int)$l['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" title="Usuń">
                <i class="bi bi-trash"></i>
              </button>
            </form>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php else: ?>
      <div class="text-muted text-center py-3" style="font-size:.85rem">
        <i class="bi bi-envelope d-block mb-2 opacity-25" style="font-size:1.5rem"></i>
        Brak pism — użyj przycisku „Nowe pismo" aby dodać.
      </div>
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

  <!-- Aktywność -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2 px-3">
      <div class="case-section-title mb-2">Aktywność</div>
      <div style="font-size:.79rem;display:flex;flex-direction:column;gap:.35rem">
        <div class="d-flex justify-content-between">
          <span class="text-muted">Utworzona</span>
          <span><?= date('d.m.Y H:i', strtotime($case['created_at'])) ?></span>
        </div>
        <div class="d-flex justify-content-between">
          <span class="text-muted">Zmieniona</span>
          <span><?= date('d.m.Y H:i', strtotime($case['updated_at'])) ?></span>
        </div>
        <?php if ($case['closed_at']): ?>
        <div class="d-flex justify-content-between">
          <span class="text-muted">Zamknięta</span>
          <span style="color:#D97706"><?= date('d.m.Y H:i', strtotime($case['closed_at'])) ?></span>
        </div>
        <?php endif; ?>
        <hr class="my-1">
        <div class="d-flex justify-content-between">
          <span class="text-muted"><i class="bi bi-chat me-1"></i>Notatki</span>
          <strong><?= count($notes) ?></strong>
        </div>
        <div class="d-flex justify-content-between">
          <span class="text-muted"><i class="bi bi-paperclip me-1"></i>Pliki</span>
          <strong><?= count($files) ?></strong>
        </div>
        <div class="d-flex justify-content-between">
          <span class="text-muted"><i class="bi bi-envelope me-1"></i>Pisma</span>
          <strong><?= count($letters) ?></strong>
        </div>
      </div>
    </div>
  </div>

  <!-- EZD -->
  <?php if (module_enabled('ezd_enabled')): ?>
  <div class="card border-0 shadow-sm mb-3" id="ezd">
    <div class="card-body">
      <div class="case-section-title"><i class="bi bi-folder2-open me-1"></i>Powiązanie z EZD</div>

      <?php if ($ezd_sprawa): ?>
      <div style="background:#FFFBF0;border:1px solid #FDE68A;border-radius:8px;padding:.65rem .85rem;font-size:.82rem;margin-bottom:.75rem">
        <div class="fw-semibold">
          <code style="font-size:.75rem;color:#1d4ed8"><?= h($ezd_sprawa['znak_sprawy']) ?></code>
        </div>
        <div style="color:#374151"><?= h($ezd_sprawa['title']) ?></div>
        <div style="font-size:.72rem;color:#9CA3AF;margin-top:.2rem">
          Status: <?= h($ezd_sprawa['status'] ?? '—') ?>
          · <?= h($ezd_sprawa['teczka_symbol'] ?? '') ?>
        </div>
        <div class="d-flex gap-2 mt-2">
          <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= (int)$ezd_sprawa['id'] ?>"
             class="btn btn-sm btn-outline-warning py-0 px-2" style="font-size:.75rem">
            <i class="bi bi-box-arrow-up-right me-1"></i>Otwórz w EZD
          </a>
          <?php if ($can_write): ?>
          <form method="post" class="d-inline" onsubmit="return confirm('Odpiąć powiązanie z EZD?')">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="_action" value="unlink_ezd">
            <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size:.75rem">
              <i class="bi bi-x-lg me-1"></i>Odepnij
            </button>
          </form>
          <?php endif; ?>
        </div>
      </div>
      <?php else: ?>
      <div class="text-muted mb-2" style="font-size:.8rem">Nie powiązano z żadną sprawą EZD.</div>
      <?php endif; ?>

      <?php if ($can_write && $ezd_sprawy_list): ?>
      <form method="post" class="<?= $ezd_sprawa ? 'border-top pt-2 mt-1' : '' ?>">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="link_ezd">
        <div class="d-flex gap-1">
          <select name="ezd_sprawa_id" class="form-select form-select-sm" required style="font-size:.78rem">
            <option value="">— wybierz sprawę EZD —</option>
            <?php foreach ($ezd_sprawy_list as $es): ?>
            <option value="<?= (int)$es['id'] ?>"
                    <?= ((int)($case['ezd_sprawa_id'] ?? 0) === (int)$es['id']) ? 'selected' : '' ?>>
              <?= h($es['znak_sprawy']) ?> — <?= h(mb_substr($es['title'],0,40)) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-sm btn-warning px-2" style="font-size:.75rem">
            <i class="bi bi-link-45deg"></i>
          </button>
        </div>
      </form>
      <?php endif; ?>
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

<?php if ($can_write): ?>
<!-- ══ MODAL: Nowe pismo ════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalPismo" tabindex="-1" aria-labelledby="modalPismoLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header" style="background:#1E3A5F;color:#fff;padding:.75rem 1.25rem">
        <h5 class="modal-title fw-bold" id="modalPismoLabel" style="font-size:.95rem">
          <i class="bi bi-envelope-plus me-2"></i>Nowe pismo do sprawy
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" enctype="multipart/form-data">
        <div class="modal-body p-0" style="font-size:.86rem">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="add_pismo">

          <!-- ── SEKCJA 1: Klasyfikacja ─────────────────────────────────── -->
          <div class="px-3 pt-3 pb-2">
            <div class="fw-bold text-uppercase mb-2" style="font-size:.67rem;letter-spacing:.09em;color:#6B7280">
              <i class="bi bi-tag me-1"></i>Klasyfikacja
            </div>
            <div class="row g-2">
              <div class="col-sm-4">
                <label class="form-label small fw-semibold mb-1">Kierunek <span class="text-danger">*</span></label>
                <select name="kierunek" class="form-select form-select-sm" required>
                  <?php foreach (LETTER_DIRECTIONS as $dk => $dv): ?>
                  <option value="<?= $dk ?>"><?= h($dv['label']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-sm-4">
                <label class="form-label small fw-semibold mb-1">Typ pisma</label>
                <select name="typ_pisma" class="form-select form-select-sm">
                  <?php foreach (LETTER_TYPES as $tk => $tv): ?>
                  <option value="<?= $tk ?>"><?= h($tv['label']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-sm-4">
                <label class="form-label small fw-semibold mb-1">Pilność</label>
                <select name="pilnosc" class="form-select form-select-sm">
                  <option value="zwykłe">Zwykłe</option>
                  <option value="pilne">Pilne</option>
                  <option value="poufne">Poufne</option>
                  <option value="ściśle_tajne">Ściśle tajne</option>
                </select>
              </div>
              <div class="col-12">
                <label class="form-label small fw-semibold mb-1">Tytuł / przedmiot <span class="text-danger">*</span></label>
                <input type="text" name="tytul" class="form-control form-control-sm"
                       placeholder="np. Wezwanie do złożenia dokumentów" required>
              </div>
            </div>
          </div>

          <hr class="my-0">

          <!-- ── SEKCJA 2: Metadane pisma ───────────────────────────────── -->
          <div class="px-3 py-2">
            <div class="fw-bold text-uppercase mb-2" style="font-size:.67rem;letter-spacing:.09em;color:#6B7280">
              <i class="bi bi-info-circle me-1"></i>Metadane pisma
            </div>
            <div class="row g-2">
              <div class="col-sm-4">
                <label class="form-label small fw-semibold mb-1">Sygnatura / numer</label>
                <input type="text" name="sygnatura" class="form-control form-control-sm"
                       placeholder="np. CRM/2026/001">
              </div>
              <div class="col-sm-4">
                <label class="form-label small fw-semibold mb-1">Miejsce wystawienia</label>
                <input type="text" name="miejsce" class="form-control form-control-sm"
                       placeholder="np. Warszawa"
                       value="<?= defined('ORG_CITY') ? h(ORG_CITY) : '' ?>">
              </div>
              <div class="col-sm-4">
                <label class="form-label small fw-semibold mb-1">Data pisma</label>
                <input type="date" name="data_pisma" class="form-control form-control-sm"
                       value="<?= date('Y-m-d') ?>">
              </div>
              <div class="col-sm-6">
                <label class="form-label small fw-semibold mb-1">Sposób doręczenia</label>
                <select name="sposob_doreczenia" class="form-select form-select-sm">
                  <option value="email">E-mail</option>
                  <option value="poczta">Poczta tradycyjna</option>
                  <option value="kurier">Kurier</option>
                  <option value="osobisty">Odbiór osobisty</option>
                  <option value="epuap">ePUAP</option>
                  <option value="fax">Fax</option>
                  <option value="inny">Inny</option>
                </select>
              </div>
              <div class="col-sm-6">
                <label class="form-label small fw-semibold mb-1">Termin odpowiedzi</label>
                <input type="date" name="termin_odpowiedzi" class="form-control form-control-sm"
                       min="<?= date('Y-m-d') ?>">
              </div>
            </div>
          </div>

          <hr class="my-0">

          <!-- ── SEKCJA 3: Strony ───────────────────────────────────────── -->
          <div class="px-3 py-2">
            <div class="fw-bold text-uppercase mb-2" style="font-size:.67rem;letter-spacing:.09em;color:#6B7280">
              <i class="bi bi-people me-1"></i>Strony
            </div>
            <div class="row g-2">
              <div class="col-sm-6">
                <label class="form-label small fw-semibold mb-1">Nadawca</label>
                <input type="text" name="nadawca" class="form-control form-control-sm"
                       value="<?= defined('ORG_NAME') ? h(ORG_NAME) : '' ?>">
              </div>
              <div class="col-sm-6">
                <label class="form-label small fw-semibold mb-1">Odbiorca</label>
                <input type="text" name="odbiorca" class="form-control form-control-sm"
                       value="<?= h($case['contact_name'] ?? '') ?>">
              </div>
              <div class="col-sm-6">
                <label class="form-label small fw-semibold mb-1">E-mail odbiorcy</label>
                <input type="email" name="odbiorca_email" class="form-control form-control-sm"
                       placeholder="opcjonalnie">
              </div>
              <div class="col-sm-6">
                <label class="form-label small fw-semibold mb-1">Kopia do (DW)</label>
                <input type="text" name="kopia_do" class="form-control form-control-sm"
                       placeholder="imię, e-mail lub dział">
              </div>
              <div class="col-12">
                <label class="form-label small fw-semibold mb-1">Podpisujący</label>
                <select name="podpisujacy_id" class="form-select form-select-sm">
                  <option value="">— nie wskazano —</option>
                  <?php foreach ($users_list as $u): ?>
                  <option value="<?= (int)$u['id'] ?>"><?= h($u['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>

          <hr class="my-0">

          <!-- ── SEKCJA 4: Treść ───────────────────────────────────────── -->
          <div class="px-3 py-2">
            <div class="fw-bold text-uppercase mb-2" style="font-size:.67rem;letter-spacing:.09em;color:#6B7280">
              <i class="bi bi-file-text me-1"></i>Treść i załączniki
            </div>
            <div class="row g-2">
              <div class="col-12">
                <label class="form-label small fw-semibold mb-1">Podstawa prawna</label>
                <input type="text" name="podstawa_prawna" class="form-control form-control-sm"
                       placeholder="np. Art. 14 RODO, §5 umowy nr …">
              </div>
              <div class="col-12">
                <label class="form-label small fw-semibold mb-1">Treść pisma</label>
                <textarea name="tresc" class="form-control form-control-sm" rows="6"
                          placeholder="Treść pisma (opcjonalna — możesz dołączyć plik poniżej)"></textarea>
              </div>
              <div class="col-sm-8">
                <label class="form-label small fw-semibold mb-1">Załącznik (opcjonalny)</label>
                <input type="file" name="pismo_plik" class="form-control form-control-sm"
                       accept=".pdf,.docx,.doc,.png,.jpg,.jpeg">
              </div>
              <div class="col-12">
                <label class="form-label small fw-semibold mb-1">Uwagi wewnętrzne</label>
                <input type="text" name="uwagi" class="form-control form-control-sm"
                       placeholder="opcjonalnie — widoczne tylko wewnętrznie">
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer py-2 bg-light">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-warning btn-sm">
            <i class="bi bi-envelope-check me-1"></i>Zapisz pismo
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer_crm.php'; ?>
