<?php
/**
 * crm/cases/view.php — Widok sprawy CRM: notatki, pliki, zmiana statusu.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_case_extras.php';
require_once dirname(dirname(__DIR__)) . '/includes/letters.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
require_once dirname(dirname(__DIR__)) . '/includes/crm_perms.php';
crm_require('cases', 'read');
crm_migrate();

$id  = (int)($_GET['id'] ?? 0);
$case = db_one(
    "SELECT c.*, ct.imie_nazwisko AS contact_name, ct.type AS contact_type, ct.id AS ct_id,
            u.name AS owner_name
     FROM crm_cases c
     LEFT JOIN crm_contacts ct ON ct.id=c.contact_id
     LEFT JOIN users        u  ON u.id  = c.created_by
     WHERE c.id=?", [$id]
);
if (!$case) { http_response_code(404); die('Nie znaleziono sprawy.'); }

$uid = (int)(current_user()['id'] ?? 0);
$can_write = crm_case_can_edit($case);
// Udostępnieniami zarządza twórca sprawy lub admin.
$can_manage_shares = is_admin() || (int)($case['created_by'] ?? 0) === $uid;
$PAGE_TITLE = 'Sprawa: ' . $case['title'];

$status_cfg = [
    'open'        => ['label'=>'Otwarta',   'color'=>'#2563EB','bg'=>'#EEF4FF','icon'=>'bi-circle'],
    'in_progress' => ['label'=>'W toku',    'color'=>'#D97706','bg'=>'#FEF3E2','icon'=>'bi-arrow-clockwise'],
    'closed'      => ['label'=>'Zamknięta', 'color'=>'#2E844A','bg'=>'#EFF7ED','icon'=>'bi-check-circle'],
    'cancelled'   => ['label'=>'Anulowana', 'color'=>'#5E6470','bg'=>'#F3F4F6','icon'=>'bi-x-circle'],
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

    // „Zostaw otwartą" — świadome odłożenie decyzji. Zapisujemy znacznik, żeby
    // baner nie wracał przy każdym wejściu; wróci po kolejnym okresie bez ruchu.
    if ($action === 'stale_ack') {
        db()->prepare("UPDATE crm_cases SET stale_ack_at=? WHERE id=?")
            ->execute([date('Y-m-d H:i:s'), $id]);
        flash_set('success', 'Sprawa zostaje otwarta — przypomnimy za '
            . CRM_CASE_STALE_DAYS . ' dni bez aktywności.');
        header('Location: view.php?id=' . $id); exit;
    }

    // Szybkie zamknięcie sprawy — jedno kliknięcie zamiast wybierania statusu
    // z listy. Opcjonalna notatka domykająca ląduje w notatkach sprawy, żeby
    // z akt było widać PO CO sprawę zamknięto, nie tylko że jest zamknięta.
    if ($action === 'quick_close') {
        if ($case['status'] === 'closed') {
            flash_set('info', 'Sprawa jest już zamknięta.');
        } else {
            $now  = date('Y-m-d H:i:s');
            $from = $case['status'];
            crm_case_set_status($id, 'closed',
                (string)($_POST['close_reason'] ?? '') ?: 'zalatwiona');

            $note = trim((string)($_POST['close_note'] ?? ''));
            if ($note !== '') {
                try {
                    db_insert('crm_case_notes', [
                        'case_id'    => $id,
                        'body'       => 'Zamknięcie sprawy: ' . $note,
                        'created_by' => (int)(current_user()['id'] ?? 0) ?: null,
                        'created_at' => $now,
                    ]);
                } catch (\Throwable $e) { /* notatka nie może zablokować zamknięcia */ }
            }

            flash_set('success', 'Sprawa zamknięta.');
        }
        header('Location: view.php?id=' . $id); exit;
    }

    // Zmiana statusu — zawsze przez crm_case_set_status(), żeby historia nie kłamała
    if ($action === 'set_status') {
        $ns = $_POST['status'] ?? '';
        if (array_key_exists($ns, $status_cfg) && $ns !== $case['status']) {
            $reason = trim((string)($_POST['reason'] ?? ''));
            if (in_array($ns, ['closed', 'cancelled'], true) && $reason === '') {
                flash_set('warning', 'Przy zamykaniu sprawy podaj powód — bez tego nie da się policzyć skuteczności.');
                header('Location: view.php?id=' . $id . '#status'); exit;
            }
            $r = crm_case_set_status($id, $ns, $reason);
            flash_set(!empty($r['ok']) ? 'success' : 'danger', !empty($r['ok'])
                ? 'Status zmieniony na: ' . $status_cfg[$ns]['label']
                : ($r['error'] ?? 'Nie udało się zmienić statusu.'));
        }
        header('Location: view.php?id=' . $id . '#status'); exit;
    }

    // Metadane prowadzenia sprawy: typ, opiekun, termin
    if ($action === 'set_meta') {
        try {
            db()->prepare("UPDATE crm_cases SET type_id=?, owner_id=?, due_date=?, updated_at=? WHERE id=?")
                ->execute([
                    (int)($_POST['type_id'] ?? 0) ?: null,
                    (int)($_POST['owner_id'] ?? 0) ?: null,
                    trim((string)($_POST['due_date'] ?? '')) ?: null,
                    date('Y-m-d H:i:s'), $id,
                ]);
            flash_set('success', 'Zapisano prowadzenie sprawy.');
        } catch (\Throwable $e) {
            flash_set('danger', 'Nie udało się zapisać.');
        }
        header('Location: view.php?id=' . $id); exit;
    }

    // Zadanie do sprawy — realne zadanie w module Zadań, z linkiem w obie strony
    if ($action === 'add_task') {
        $t = trim((string)($_POST['task_title'] ?? ''));
        if ($t === '') {
            flash_set('warning', 'Wpisz, co jest do zrobienia.');
        } else {
            $r = crm_case_add_task($id, $t, (int)($_POST['task_list'] ?? 0), trim((string)($_POST['task_due'] ?? '')));
            flash_set(!empty($r['ok']) ? 'success' : 'danger',
                !empty($r['ok']) ? 'Zadanie dodane do sprawy.' : ($r['error'] ?: 'Nie udało się dodać zadania.'));
        }
        header('Location: view.php?id=' . $id . '#tasks'); exit;
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

    // ── Współdzielenie sprawy (tylko twórca lub admin) ────────────────────────
    if (in_array($action, ['share_add','share_update','share_remove'], true) && $can_manage_shares) {
        $target = (int)($_POST['share_user_id'] ?? 0);
        $level  = ($_POST['share_level'] ?? 'read') === 'edit' ? 1 : 0;

        if ($action === 'share_remove' && $target) {
            db()->prepare("DELETE FROM crm_case_shares WHERE case_id=? AND user_id=?")
                ->execute([$id, $target]);
            flash_set('success', 'Współdzielenie cofnięte.');
        } elseif ($target && $target !== (int)($case['created_by'] ?? 0)) {
            $usr = db_one("SELECT id FROM users WHERE id=? AND is_active=1", [$target]);
            if ($usr) {
                // upsert: UNIQUE(case_id,user_id)
                db()->prepare(
                    "INSERT INTO crm_case_shares (case_id, user_id, can_write, shared_by, shared_at)
                     VALUES (?,?,?,?,?)
                     ON CONFLICT(case_id, user_id) DO UPDATE SET can_write=excluded.can_write"
                )->execute([$id, $target, $level, $uid, date('Y-m-d H:i:s')]);
                flash_set('success', $action === 'share_add' ? 'Sprawa udostępniona.' : 'Poziom dostępu zmieniony.');
            }
        }
        header('Location: view.php?id=' . $id . '#share'); exit;
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
$shares      = crm_case_shares($id);
$shared_uids = array_map(fn($s) => (int)$s['user_id'], $shares);
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
.case-note-body { font-size:.9rem;color:#181818;white-space:pre-wrap;word-break:break-word }
.case-note-meta { font-size:.78rem;color:#5E6470;margin-top:.4rem }
.file-row { display:flex;align-items:center;gap:.75rem;padding:.65rem .9rem;border-bottom:1px solid #ECECEC;font-size:.88rem }
.file-row:last-child { border-bottom:none }
.file-icon { width:34px;height:34px;border-radius:7px;background:#EEF4FF;color:#0176D3;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0 }
</style>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-3" style="font-size:.82rem">
  <ol class="breadcrumb mb-0">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/dashboard.php">CRM</a></li>
    <li class="breadcrumb-item"><a href="index.php">Sprawy</a></li>
    <li class="breadcrumb-item active"><?= h($case['title']) ?></li>
  </ol>
</nav>

<?php $_stale = crm_case_stale_days($case); ?>
<?php if ($_stale && $can_write): ?>
<!-- Sprawa leży bez ruchu — propozycja zamknięcia z decyzją na miejscu.
     Nie zamykamy automatycznie: to sprawa merytoryczna, a nie porządkowa. -->
<div class="cv-panel mb-3" style="overflow:hidden">
  <div style="height:4px;background:#D97706" aria-hidden="true"></div>
  <div class="d-flex flex-wrap align-items-center gap-3" style="padding:.9rem 1.4rem">
    <i class="bi bi-clock-history" style="font-size:1.4rem;color:#D97706" aria-hidden="true"></i>
    <div style="flex:1;min-width:14rem">
      <div style="font-weight:700;font-size:.95rem">
        Bez aktywności od <?= (int)$_stale ?> dni — zamknąć sprawę?
      </div>
      <div class="cv-meta">
        Ostatni ruch: <?= h(date('d.m.Y', strtotime((string)($case['updated_at'] ?: $case['created_at'])))) ?>.
        Prowadzi: <?= h($case['owner_name'] ?? '—') ?>.
      </div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <button type="button" class="btn btn-sm btn-success"
              data-bs-toggle="collapse" data-bs-target="#collapseQuickClose"
              aria-expanded="false" aria-controls="collapseQuickClose">
        <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Zamknij
      </button>
      <form method="post" class="d-inline">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="stale_ack">
        <button type="submit" class="btn btn-sm btn-outline-secondary">
          Zostaw otwartą
        </button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Nagłówek sprawy -->
<div class="cv-panel mb-3" id="case-header" style="overflow:hidden">
  <!-- Pasek statusu -->
  <div style="height:4px;background:<?= $sc['color'] ?>" aria-hidden="true"></div>

  <div style="padding:1.1rem 1.4rem 1rem">
    <div class="d-flex align-items-start gap-3 flex-wrap">

      <!-- Ikona sprawy -->
      <div style="flex-shrink:0;margin-top:.1rem">
        <div style="width:46px;height:46px;border-radius:11px;background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>;display:flex;align-items:center;justify-content:center;font-size:1.3rem;border:1.5px solid <?= $sc['color'] ?>44" aria-hidden="true">
          <i class="bi bi-briefcase-fill"></i>
        </div>
      </div>

      <!-- Treść główna -->
      <div style="flex:1;min-width:0">
        <?php if (!empty($case['case_number'])): ?>
        <div style="font-family:monospace;font-size:.82rem;font-weight:700;color:#1D4ED8;letter-spacing:.05em;margin-bottom:.3rem">
          <?= h($case['case_number']) ?>
        </div>
        <?php endif; ?>
        <div class="d-flex align-items-center flex-wrap gap-2 mb-1">
          <h1 style="font-size:1.2rem;font-weight:700;margin:0;color:#181818;line-height:1.3"><?= h($case['title']) ?></h1>
          <span style="display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .65rem;border-radius:2rem;font-size:.76rem;font-weight:600;background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>;border:1px solid <?= $sc['color'] ?>66">
            <i class="bi <?= $sc['icon'] ?>" aria-hidden="true"></i><?= $sc['label'] ?>
          </span>
          <span style="display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .65rem;border-radius:2rem;font-size:.76rem;font-weight:600;background:#F9FAFB;color:<?= $pc['color'] ?>;border:1px solid <?= $pc['color'] ?>66">
            <span style="width:6px;height:6px;border-radius:50%;background:<?= $pc['color'] ?>;display:inline-block" aria-hidden="true"></span>
            Priorytet: <?= $pc['label'] ?>
          </span>
        </div>

        <!-- Metadane w wierszu -->
        <div class="cv-meta" style="display:flex;flex-wrap:wrap;gap:.1rem .9rem;margin-top:.3rem">
          <span>
            <i class="bi bi-person me-1" style="color:#5E6470" aria-hidden="true"></i>
            <a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= $case['ct_id'] ?>" style="color:var(--crm-accent);text-decoration:none;font-weight:600">
              <?= h($case['contact_name']) ?>
            </a>
          </span>
          <span><i class="bi bi-calendar3 me-1" style="color:#5E6470" aria-hidden="true"></i><?= date('d.m.Y', strtotime($case['created_at'])) ?></span>
          <span><i class="bi bi-chat me-1" style="color:#5E6470" aria-hidden="true"></i><?= count($notes) ?> notatek</span>
          <span><i class="bi bi-paperclip me-1" style="color:#5E6470" aria-hidden="true"></i><?= count($files) ?> plików</span>
          <span><i class="bi bi-envelope me-1" style="color:#5E6470" aria-hidden="true"></i><?= count($letters) ?> pism</span>
          <?php if ($case['closed_at']): ?>
          <span style="color:#B45309;font-weight:600"><i class="bi bi-flag me-1" aria-hidden="true"></i>Zamknięta <?= date('d.m.Y', strtotime($case['closed_at'])) ?></span>
          <?php endif; ?>
          <?php if ($ezd_sprawa): ?>
          <span><i class="bi bi-folder2-open me-1" style="color:#5E6470" aria-hidden="true"></i>
            <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= (int)$ezd_sprawa['id'] ?>" style="color:var(--crm-accent);text-decoration:none">
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
                data-bs-toggle="modal" data-bs-target="#modalPismo" style="font-size:.82rem">
          <i class="bi bi-envelope-plus me-1" aria-hidden="true"></i>Nowe pismo
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary"
                data-bs-toggle="collapse" data-bs-target="#collapseEditMeta"
                aria-expanded="false" aria-controls="collapseEditMeta" style="font-size:.82rem">
          <i class="bi bi-pencil me-1" aria-hidden="true"></i>Edytuj
        </button>
        <?php if ($case['status'] !== 'closed'): ?>
        <button type="button" class="btn btn-sm btn-success"
                data-bs-toggle="collapse" data-bs-target="#collapseQuickClose"
                aria-expanded="false" aria-controls="collapseQuickClose" style="font-size:.82rem">
          <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Zamknij sprawę
        </button>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Szybkie zamknięcie (collapse) -->
    <?php if ($can_write && $case['status'] !== 'closed'): ?>
    <div class="collapse" id="collapseQuickClose">
      <form method="post" style="margin-top:.8rem;padding:.8rem .9rem;background:#EFF7ED;border-radius:8px;border-left:3px solid #2E844A">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="quick_close">
        <label for="closeReason" style="font-size:.82rem;font-weight:600;display:block;margin-bottom:.3rem">
          Powód zamknięcia
        </label>
        <select name="close_reason" id="closeReason" class="form-select form-select-sm mb-2" style="font-size:.86rem">
          <?php foreach (crm_case_close_reasons() as $rk => $rl): ?>
          <option value="<?= h($rk) ?>"><?= h($rl) ?></option>
          <?php endforeach; ?>
        </select>
        <label for="closeNote" style="font-size:.82rem;font-weight:600;display:block;margin-bottom:.3rem">
          Notatka domykająca (opcjonalnie)
        </label>
        <textarea name="close_note" id="closeNote" rows="2" maxlength="1000"
                  class="form-control form-control-sm mb-2" style="font-size:.86rem"
                  placeholder="Np. sprawa rozpatrzona pozytywnie, umowa podpisana…"></textarea>
        <div class="d-flex gap-2 align-items-center flex-wrap">
          <button type="submit" class="btn btn-sm btn-success" style="font-size:.82rem">
            <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Zamknij sprawę
          </button>
          <button type="button" class="btn btn-sm btn-link text-muted" style="font-size:.82rem"
                  data-bs-toggle="collapse" data-bs-target="#collapseQuickClose">Anuluj</button>
          <span class="text-muted" style="font-size:.78rem">
            Status zmieni się na „Zamknięta"; powód i data trafią do historii sprawy.
          </span>
        </div>
      </form>
    </div>
    <?php endif; ?>

    <!-- Opis -->
    <?php if ($case['description']): ?>
    <div style="margin-top:.8rem;padding:.7rem .9rem;background:#F8FAFF;border-radius:8px;font-size:.9rem;color:#374151;white-space:pre-wrap;border-left:3px solid <?= $sc['color'] ?>88">
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

<!-- ══ GŁÓWNA: notatki / pisma / pliki w zakładkach ═══════════════════════════ -->
<div class="col-lg-8">

  <ul class="nav cv-tabs mb-3" id="caseTabs" role="tablist">
    <li class="nav-item" role="presentation">
      <button class="nav-link active" id="case-tab-notes-btn" data-bs-toggle="tab"
              data-bs-target="#notes" type="button" role="tab"
              aria-controls="notes" aria-selected="true">
        <i class="bi bi-chat-dots" aria-hidden="true"></i>Notatki <span class="cv-count"><?= count($notes) ?></span>
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="case-tab-pisma-btn" data-bs-toggle="tab"
              data-bs-target="#pisma" type="button" role="tab"
              aria-controls="pisma" aria-selected="false">
        <i class="bi bi-envelope" aria-hidden="true"></i>Pisma <span class="cv-count"><?= count($letters) ?></span>
      </button>
    </li>
    <?php
      $case_msgs  = crm_case_messages((int)$case['id']);
      $case_tasks = crm_case_tasks((int)$case['id']);
      $case_hist  = crm_case_status_history((int)$case['id']);
    ?>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="case-tab-tasks-btn" data-bs-toggle="tab"
              data-bs-target="#tasks" type="button" role="tab"
              aria-controls="tasks" aria-selected="false">
        <i class="bi bi-check2-square" aria-hidden="true"></i>Zadania
        <?php if ($case_tasks): ?><span class="cv-count"><?= count($case_tasks) ?></span><?php endif; ?>
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="case-tab-hist-btn" data-bs-toggle="tab"
              data-bs-target="#history" type="button" role="tab"
              aria-controls="history" aria-selected="false">
        <i class="bi bi-clock-history" aria-hidden="true"></i>Historia
        <?php if ($case_hist): ?><span class="cv-count"><?= count($case_hist) ?></span><?php endif; ?>
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="case-tab-mail-btn" data-bs-toggle="tab"
              data-bs-target="#mail" type="button" role="tab"
              aria-controls="mail" aria-selected="false">
        <i class="bi bi-envelope" aria-hidden="true"></i>Wiadomości
        <?php if ($case_msgs): ?><span class="cv-count"><?= count($case_msgs) ?></span><?php endif; ?>
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="case-tab-files-btn" data-bs-toggle="tab"
              data-bs-target="#files" type="button" role="tab"
              aria-controls="files" aria-selected="false">
        <i class="bi bi-paperclip" aria-hidden="true"></i>Pliki <span class="cv-count"><?= count($files) ?></span>
      </button>
    </li>
  </ul>

  <div class="tab-content">

  <?php include dirname(__DIR__) . '/includes/case/tabs_notes_pisma.php'; ?>
  <?php include dirname(__DIR__) . '/includes/case/tabs_work.php'; ?>
  </div><!-- /tab-content -->
</div><!-- /główna -->

<?php include dirname(__DIR__) . '/includes/case/aside.php'; ?>
</div><!-- /row -->

<?php include dirname(__DIR__) . '/includes/case/modal_pismo.php'; ?>
<script>
/* Aktywacja zakładki na podstawie kotwicy URL (#notes/#pisma/#files) — po
   przekierowaniach POST oraz przy kliknięciu w odnośnik wewnętrzny. */
(function () {
  if (typeof bootstrap === 'undefined') return;
  var contentTabs = { '#notes': '#case-tab-notes-btn', '#pisma': '#case-tab-pisma-btn', '#files': '#case-tab-files-btn' };

  function activateFromHash(hash) {
    var btnSel = contentTabs[hash];
    if (!btnSel) return false;
    var btn = document.querySelector(btnSel);
    if (btn) { bootstrap.Tab.getOrCreateInstance(btn).show(); return true; }
    return false;
  }

  // Przy starcie strony
  if (window.location.hash) activateFromHash(window.location.hash);

  // Reakcja na zmianę kotwicy w trakcie (np. klik w link do sekcji)
  window.addEventListener('hashchange', function () { activateFromHash(window.location.hash); });
  // Aktualizuj kotwicę po ręcznym wyborze zakładki (spójność z linkami)
  document.querySelectorAll('#caseTabs [data-bs-target]').forEach(function (b) {
    b.addEventListener('shown.bs.tab', function () {
      history.replaceState(null, '', b.getAttribute('data-bs-target'));
    });
  });
})();
</script>
<?php include dirname(__DIR__) . '/includes/footer_crm.php'; ?>
