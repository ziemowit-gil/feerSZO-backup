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

  <!-- ── NOTATKI ─────────────────────────────────────────────────────────── -->
  <div class="tab-pane fade show active" id="notes" role="tabpanel" aria-labelledby="case-tab-notes-btn" tabindex="0">
    <div class="cv-panel"><div class="cv-panel__body">
      <div class="cv-shead">
        <i class="bi bi-chat-dots cv-shead__icon" aria-hidden="true"></i>
        <h2 class="cv-shead__title">Notatki</h2>
        <div class="cv-shead__aside"><span class="cv-count"><?= count($notes) ?></span></div>
      </div>

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
    </div></div>
  </div>

  <!-- ── PISMA ─────────────────────────────────────────────────────────── -->
  <div class="tab-pane fade" id="pisma" role="tabpanel" aria-labelledby="case-tab-pisma-btn" tabindex="0">
    <div class="cv-panel"><div class="cv-panel__body">
      <div class="cv-shead">
        <i class="bi bi-envelope cv-shead__icon" aria-hidden="true"></i>
        <h2 class="cv-shead__title">Pisma</h2>
        <div class="cv-shead__aside">
          <span class="cv-count"><?= count($letters) ?></span>
          <?php if ($can_write): ?>
          <button type="button" class="btn btn-warning btn-sm py-0 px-2"
                  data-bs-toggle="modal" data-bs-target="#modalPismo">
            <i class="bi bi-plus me-1" aria-hidden="true"></i>Nowe pismo
          </button>
          <?php endif; ?>
        </div>
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
              <span class="badge bg-<?= $ld['class'] ?> bg-opacity-15 text-<?= $ld['class'] ?> border border-<?= $ld['class'] ?>" style="font-size:.7rem">
                <i class="bi <?= $ld['icon'] ?> me-1"></i><?= $ld['label'] ?>
              </span>
              <span class="badge bg-secondary bg-opacity-10 text-secondary border" style="font-size:.7rem">
                <i class="bi <?= $lt['icon'] ?> me-1"></i><?= $lt['label'] ?>
              </span>
              <?php if (!empty($l['pilnosc']) && $l['pilnosc'] !== 'zwykłe'): ?>
              <?php $pilnosc_cls = ['pilne'=>'warning','poufne'=>'danger','ściśle_tajne'=>'danger'][$l['pilnosc']] ?? 'secondary'; ?>
              <span class="badge bg-<?= $pilnosc_cls ?> bg-opacity-15 text-<?= $pilnosc_cls ?> border border-<?= $pilnosc_cls ?>" style="font-size:.7rem;text-transform:uppercase">
                <?= h($l['pilnosc']) ?>
              </span>
              <?php endif; ?>
              <?php if (!empty($l['sygnatura'])): ?>
              <code style="font-size:.65rem;color:#6B7280;background:#F3F4F6;padding:.1rem .35rem;border-radius:4px"><?= h($l['sygnatura']) ?></code>
              <?php endif; ?>
              <?php if ($is_edoreczenia): ?>
              <span class="badge" style="font-size:.7rem;background:#EEF2FF;color:#4338CA;border:1px solid #C7D2FE">
                <i class="bi bi-shield-check me-1"></i>eDoręczenia
              </span>
              <?php endif; ?>
            </div>
            <div style="font-size:.72rem;color:#5E6470;margin-top:.25rem;display:flex;flex-wrap:wrap;gap:0 .75rem">
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
               class="btn btn-sm btn-outline-primary py-0 px-2"
               aria-label="Pobierz załącznik: <?= h($l['temat'] ?: $l['typ']) ?>">
              <i class="bi bi-download" aria-hidden="true"></i>
            </a>
            <?php endif; ?>
            <?php if ($can_write): ?>
            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2"
                    aria-label="Edytuj pismo: <?= h($l['temat'] ?: $l['typ']) ?>"
                    onclick="pismoEdit(<?= $ldata ?>)">
              <i class="bi bi-pencil" aria-hidden="true"></i>
            </button>
            <?php endif; ?>
            <?php if ($can_write && ($l['created_by']==$uid || is_admin())): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Usunąć pismo?')">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="delete_pismo">
              <input type="hidden" name="letter_id" value="<?= (int)$l['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2"
                      aria-label="Usuń pismo: <?= h($l['temat'] ?: $l['typ']) ?>">
                <i class="bi bi-trash" aria-hidden="true"></i>
              </button>
            </form>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php else: ?>
      <div class="cv-empty">
        <i class="bi bi-envelope" aria-hidden="true"></i>
        Brak pism — użyj przycisku „Nowe pismo", aby dodać.
      </div>
      <?php endif; ?>
    </div></div>
  </div>

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

  </div><!-- /tab-content -->
</div><!-- /główna -->

<!-- ══ ASIDE: status, aktywność, współdzielenie, EZD ════════════════════════ -->
<div class="col-lg-4">

  <!-- Status -->
  <?php if ($can_write): ?>
  <div class="cv-panel" id="status"><div class="cv-panel__body">
      <div class="cv-shead">
        <i class="bi bi-flag cv-shead__icon" aria-hidden="true"></i>
        <h2 class="cv-shead__title">Zmień status</h2>
      </div>
      <form method="post" class="d-flex flex-column gap-2">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="set_status">
        <?php /* Zamknięcie i anulowanie wymagają powodu — inaczej z historii nie
                 wyczytasz, czy sprawa się udała, czy odpadła. */ ?>
        <select name="reason" class="form-select form-select-sm" aria-label="Powód (przy zamknięciu lub anulowaniu)">
          <option value="">— powód (wymagany przy zamknięciu) —</option>
          <?php foreach (crm_case_close_reasons() as $rk => $rl): ?>
          <option value="<?= h($rk) ?>"><?= h($rl) ?></option>
          <?php endforeach; ?>
        </select>
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
  <div class="cv-panel"><div class="cv-panel__body">
      <div class="cv-shead">
        <i class="bi bi-clock-history cv-shead__icon" aria-hidden="true"></i>
        <h2 class="cv-shead__title">Aktywność</h2>
      </div>
      <div style="font-size:.84rem;display:flex;flex-direction:column;gap:.4rem">
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
          <span class="cv-muted">Zamknięta</span>
          <span style="color:#B45309;font-weight:600"><?= date('d.m.Y H:i', strtotime($case['closed_at'])) ?></span>
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

  <!-- Prowadzenie sprawy: kto, do kiedy, jaki typ i jak z SLA -->
  <?php
    $case_types_all = crm_case_types();
    $case_users_all = db_all("SELECT id, name FROM users WHERE is_active=1 ORDER BY name");
    $case_type_cur  = crm_case_type((int)($case['type_id'] ?? 0));
    $sla            = crm_case_sla($case);
    $due_ts         = !empty($case['due_date']) ? strtotime((string)$case['due_date']) : 0;
    $due_left       = $due_ts ? (int)floor(($due_ts - time()) / 86400) : null;
    $sla_style = static fn(string $st): string => match ($st) {
        'breach' => 'color:#B91C1C;font-weight:700',
        'soon'   => 'color:#B45309;font-weight:600',
        'met'    => 'color:#2E844A;font-weight:600',
        default  => 'color:#2E844A',
    };
  ?>
  <div class="cv-panel" id="prowadzenie"><div class="cv-panel__body">
    <div class="cv-shead">
      <i class="bi bi-person-workspace cv-shead__icon" aria-hidden="true"></i>
      <h2 class="cv-shead__title">Prowadzenie sprawy</h2>
    </div>

    <?php if ($sla['has']): ?>
    <div class="mb-2" style="font-size:.82rem">
      <?php if ($sla['response']): $r = $sla['response']; ?>
      <div class="d-flex justify-content-between">
        <span class="text-muted">SLA — pierwsza odpowiedź</span>
        <span style="<?= $sla_style($r['state']) ?>">
          <?= $r['met_at']
              ? ($r['state'] === 'met' ? 'dotrzymane' : 'przekroczone') . ' · ' . h(date('d.m.Y H:i', strtotime($r['met_at'])))
              : ($r['left_h'] < 0 ? 'po terminie o ' . abs($r['left_h']) . ' h' : 'zostało ' . $r['left_h'] . ' h') ?>
        </span>
      </div>
      <?php endif; ?>
      <?php if ($sla['close']): $c2 = $sla['close']; ?>
      <div class="d-flex justify-content-between">
        <span class="text-muted">SLA — zamknięcie</span>
        <span style="<?= $sla_style($c2['state']) ?>">
          <?= $c2['met_at']
              ? ($c2['state'] === 'met' ? 'dotrzymane' : 'przekroczone')
              : ($c2['left_h'] < 0 ? 'po terminie' : 'zostało ' . (int)round($c2['left_h'] / 24) . ' dni') ?>
        </span>
      </div>
      <?php endif; ?>
      <div class="text-muted" style="font-size:.72rem">Liczone od wpływu sprawy, wg typu „<?= h($case_type_cur['name'] ?? '') ?>".</div>
    </div>
    <?php endif; ?>

    <?php if ($can_write): ?>
    <form method="post" class="row g-2">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="set_meta">
      <?php if ($case_types_all): ?>
      <div class="col-12">
        <label class="form-label small fw-semibold mb-1" for="mtype">Typ</label>
        <select name="type_id" id="mtype" class="form-select form-select-sm">
          <option value="">— bez typu —</option>
          <?php foreach ($case_types_all as $ct): ?>
          <option value="<?= (int)$ct['id'] ?>" <?= (int)($case['type_id'] ?? 0) === (int)$ct['id'] ? 'selected' : '' ?>>
            <?= h($ct['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="col-12">
        <label class="form-label small fw-semibold mb-1" for="mowner">Prowadzi</label>
        <select name="owner_id" id="mowner" class="form-select form-select-sm">
          <option value="">— nieprzypisana —</option>
          <?php foreach ($case_users_all as $u): ?>
          <option value="<?= (int)$u['id'] ?>" <?= (int)($case['owner_id'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>>
            <?= h($u['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12">
        <label class="form-label small fw-semibold mb-1" for="mdue">Termin</label>
        <input type="date" name="due_date" id="mdue" class="form-control form-control-sm"
               value="<?= h($case['due_date'] ?? '') ?>">
        <?php if ($due_left !== null && !in_array($case['status'], ['closed','cancelled'], true)): ?>
        <div style="font-size:.74rem;<?= $due_left < 0 ? 'color:#B91C1C;font-weight:600' : ($due_left <= 2 ? 'color:#B45309' : 'color:#9CA3AF') ?>">
          <?= $due_left < 0 ? 'Po terminie o ' . abs($due_left) . ' dni' : ($due_left === 0 ? 'Termin dziś' : 'Zostało ' . $due_left . ' dni') ?>
        </div>
        <?php endif; ?>
      </div>
      <div class="col-12">
        <button class="btn btn-crm-outline btn-sm w-100"><i class="bi bi-check-lg me-1"></i>Zapisz prowadzenie</button>
      </div>
    </form>
    <?php else: ?>
    <div style="font-size:.84rem">
      Prowadzi: <strong><?= h(db_one("SELECT name FROM users WHERE id=?", [(int)($case['owner_id'] ?? 0)])['name'] ?? '—') ?></strong><br>
      Termin: <strong><?= $case['due_date'] ? h(date('d.m.Y', strtotime((string)$case['due_date']))) : '—' ?></strong>
    </div>
    <?php endif; ?>
  </div></div>

  <!-- Współdzielenie -->
  <?php if ($can_manage_shares || $shares): ?>
  <div class="cv-panel" id="share"><div class="cv-panel__body">
      <div class="cv-shead">
        <i class="bi bi-people cv-shead__icon" aria-hidden="true"></i>
        <h2 class="cv-shead__title">Współdzielenie</h2>
        <div class="cv-shead__aside"><span class="cv-count"><?= count($shares) ?></span></div>
      </div>

      <?php if ($shares): ?>
      <ul class="list-group list-group-flush mb-2">
        <?php foreach ($shares as $s):
          $sn = trim(($s['first_name']??'').' '.($s['last_name']??'')) ?: ($s['user_name']??'?');
          $is_edit = (int)$s['can_write'] === 1;
        ?>
        <li class="list-group-item px-0 py-2 d-flex align-items-center gap-2" style="font-size:.84rem">
          <i class="bi bi-person-circle text-secondary"></i>
          <span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($sn) ?></span>
          <?php if ($can_manage_shares): ?>
          <form method="post" class="d-inline">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="_action" value="share_update">
            <input type="hidden" name="share_user_id" value="<?= (int)$s['user_id'] ?>">
            <select name="share_level" class="form-select form-select-sm py-0" style="width:auto;font-size:.74rem"
                    onchange="this.form.submit()" aria-label="Poziom dostępu: <?= h($sn) ?>">
              <option value="read" <?= $is_edit?'':'selected' ?>>odczyt</option>
              <option value="edit" <?= $is_edit?'selected':'' ?>>edycja</option>
            </select>
          </form>
          <form method="post" class="d-inline" onsubmit="return confirm('Cofnąć współdzielenie?')">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="_action" value="share_remove">
            <input type="hidden" name="share_user_id" value="<?= (int)$s['user_id'] ?>">
            <button type="submit" class="btn btn-link btn-sm text-danger py-0 px-1" aria-label="Cofnij dla: <?= h($sn) ?>">
              <i class="bi bi-x-lg"></i>
            </button>
          </form>
          <?php else: ?>
          <span class="badge <?= $is_edit?'bg-success-subtle text-success border border-success-subtle':'bg-secondary-subtle text-secondary border' ?>" style="font-size:.68rem">
            <?= $is_edit?'edycja':'odczyt' ?>
          </span>
          <?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?>
      <div class="text-muted mb-2" style="font-size:.8rem">Sprawa nie jest jeszcze nikomu udostępniona.</div>
      <?php endif; ?>

      <?php if ($can_manage_shares):
        $avail = array_filter($users_list, fn($u) =>
            (int)$u['id'] !== (int)($case['created_by'] ?? 0) && !in_array((int)$u['id'], $shared_uids, true));
      ?>
      <?php if ($avail): ?>
      <form method="post" class="border-top pt-2 mt-1">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="share_add">
        <div class="d-flex gap-1">
          <select name="share_user_id" class="form-select form-select-sm" required style="font-size:.78rem">
            <option value="">— udostępnij osobie —</option>
            <?php foreach ($avail as $u): ?>
            <option value="<?= (int)$u['id'] ?>"><?= h($u['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <select name="share_level" class="form-select form-select-sm" style="width:auto;font-size:.78rem">
            <option value="read">odczyt</option>
            <option value="edit">edycja</option>
          </select>
          <button type="submit" class="btn btn-sm btn-primary px-2" aria-label="Udostępnij">
            <i class="bi bi-plus-lg"></i>
          </button>
        </div>
      </form>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- EZD -->
  <?php if (module_enabled('ezd_enabled')): ?>
  <div class="cv-panel" id="ezd"><div class="cv-panel__body">
      <div class="cv-shead">
        <i class="bi bi-folder2-open cv-shead__icon" aria-hidden="true"></i>
        <h2 class="cv-shead__title">Powiązanie z EZD</h2>
      </div>

      <?php if ($ezd_sprawa): ?>
      <div style="background:#FFFBF0;border:1px solid #FDE68A;border-radius:8px;padding:.65rem .85rem;font-size:.82rem;margin-bottom:.75rem">
        <div class="fw-semibold">
          <code style="font-size:.75rem;color:#1d4ed8"><?= h($ezd_sprawa['znak_sprawy']) ?></code>
        </div>
        <div style="color:#374151"><?= h($ezd_sprawa['title']) ?></div>
        <div style="font-size:.72rem;color:#5E6470;margin-top:.2rem">
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
<!-- ══ MODAL: Nowe / Edytuj pismo ══════════════════════════════════════════ -->
<div class="modal fade" id="modalPismo" tabindex="-1" aria-labelledby="modalPismoLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header py-2 px-3" style="background:#1E3A5F;color:#fff">
        <h5 class="modal-title fw-bold" id="modalPismoLabel" style="font-size:.92rem">
          <i class="bi bi-envelope-plus me-2" id="pismoModalIcon"></i><span id="pismoModalTitle">Nowe pismo do sprawy</span>
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" enctype="multipart/form-data" id="formPismo">
        <div class="modal-body p-0" style="font-size:.85rem">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" id="pismoAction" value="add_pismo">
          <input type="hidden" name="letter_id" id="pismoLetterId" value="">

          <!-- ── SEKCJA 1: Klasyfikacja ──────────────────────────────── -->
          <div class="px-3 pt-3 pb-2">
            <div class="fw-bold text-uppercase mb-2" style="font-size:.72rem;letter-spacing:.08em;color:#5E6470"><i class="bi bi-tag me-1"></i>Klasyfikacja</div>
            <div class="row g-2">
              <div class="col-sm-4">
                <label class="form-label small fw-semibold mb-1">Kierunek <span class="text-danger">*</span></label>
                <select name="kierunek" id="p_kierunek" class="form-select form-select-sm" required>
                  <?php foreach (LETTER_DIRECTIONS as $dk => $dv): ?>
                  <option value="<?= $dk ?>"><?= h($dv['label']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-sm-4">
                <label class="form-label small fw-semibold mb-1">Typ pisma</label>
                <select name="typ_pisma" id="p_typ_pisma" class="form-select form-select-sm">
                  <?php foreach (LETTER_TYPES as $tk => $tv): ?>
                  <option value="<?= $tk ?>"><?= h($tv['label']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-sm-4">
                <label class="form-label small fw-semibold mb-1">Pilność</label>
                <select name="pilnosc" id="p_pilnosc" class="form-select form-select-sm">
                  <option value="zwykłe">Zwykłe</option>
                  <option value="pilne">Pilne</option>
                  <option value="poufne">Poufne</option>
                  <option value="ściśle_tajne">Ściśle tajne</option>
                </select>
              </div>
              <div class="col-12">
                <label class="form-label small fw-semibold mb-1">Tytuł / przedmiot <span class="text-danger">*</span></label>
                <input type="text" name="tytul" id="p_tytul" class="form-control form-control-sm"
                       placeholder="np. Wezwanie do złożenia dokumentów" required>
              </div>
            </div>
          </div><hr class="my-0">

          <!-- ── SEKCJA 2: Metadane ──────────────────────────────────── -->
          <div class="px-3 py-2">
            <div class="fw-bold text-uppercase mb-2" style="font-size:.72rem;letter-spacing:.08em;color:#5E6470"><i class="bi bi-info-circle me-1"></i>Metadane pisma</div>
            <div class="row g-2">
              <div class="col-sm-4">
                <label class="form-label small fw-semibold mb-1">Sygnatura / numer</label>
                <input type="text" name="sygnatura" id="p_sygnatura" class="form-control form-control-sm" placeholder="np. CRM/2026/001">
              </div>
              <div class="col-sm-4">
                <label class="form-label small fw-semibold mb-1">Miejsce wystawienia</label>
                <input type="text" name="miejsce" id="p_miejsce" class="form-control form-control-sm"
                       placeholder="np. Warszawa" value="<?= defined('ORG_CITY') ? h(ORG_CITY) : '' ?>">
              </div>
              <div class="col-sm-4">
                <label class="form-label small fw-semibold mb-1">Data pisma</label>
                <input type="date" name="data_pisma" id="p_data_pisma" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
              </div>
              <div class="col-sm-6">
                <label class="form-label small fw-semibold mb-1">Sposób doręczenia</label>
                <select name="sposob_doreczenia" id="p_sposob_doreczenia" class="form-select form-select-sm" onchange="pismoToggleDoreczenie(this.value)">
                  <option value="email">E-mail</option>
                  <option value="poczta">Poczta tradycyjna</option>
                  <option value="kurier">Kurier</option>
                  <option value="edoreczenia">eDoręczenia (PURDE)</option>
                  <option value="osobisty">Odbiór osobisty</option>
                  <option value="epuap">eDoręczenia (ePUAP)</option>
                  <option value="fax">Fax</option>
                  <option value="inny">Inny</option>
                </select>
              </div>
              <div class="col-sm-6">
                <label class="form-label small fw-semibold mb-1">Termin odpowiedzi</label>
                <input type="date" name="termin_odpowiedzi" id="p_termin_odpowiedzi" class="form-control form-control-sm">
              </div>

              <!-- Pola kurier / poczta -->
              <div class="col-12" id="p_row_nadania" style="display:none">
                <label class="form-label small fw-semibold mb-1">
                  <i class="bi bi-truck me-1 text-warning"></i>Numer nadania / listu przewozowego
                </label>
                <input type="text" name="nr_nadania" id="p_nr_nadania" class="form-control form-control-sm"
                       placeholder="np. PL123456789PL lub numer listu kurierskiego" style="font-family:monospace">
              </div>

              <!-- Pola eDoręczenia -->
              <div id="p_row_edoreczenia" style="display:none" class="col-12">
                <div class="row g-2">
                  <div class="col-sm-6">
                    <label class="form-label small fw-semibold mb-1">
                      <i class="bi bi-shield-check me-1" style="color:#4338CA"></i>Adres eDoręczeń (ADE)
                    </label>
                    <input type="text" name="adres_edoreczenia" id="p_adres_edoreczenia" class="form-control form-control-sm"
                           placeholder="AE:PL-12345-67890-ABCDE-01" style="font-family:monospace;font-size:.8rem">
                  </div>
                  <div class="col-sm-6">
                    <label class="form-label small fw-semibold mb-1">
                      Numer referencyjny wiadomości
                    </label>
                    <input type="text" name="edoreczenia_ref" id="p_edoreczenia_ref" class="form-control form-control-sm"
                           placeholder="ID wiadomości po wysłaniu" style="font-family:monospace;font-size:.8rem">
                  </div>
                </div>
              </div>
            </div>
          </div><hr class="my-0">

          <!-- ── SEKCJA 3: Strony ────────────────────────────────────── -->
          <div class="px-3 py-2">
            <div class="fw-bold text-uppercase mb-2" style="font-size:.72rem;letter-spacing:.08em;color:#5E6470"><i class="bi bi-people me-1"></i>Strony</div>
            <div class="row g-2">
              <div class="col-sm-6">
                <label class="form-label small fw-semibold mb-1">Nadawca</label>
                <input type="text" name="nadawca" id="p_nadawca" class="form-control form-control-sm"
                       value="<?= defined('ORG_NAME') ? h(ORG_NAME) : '' ?>">
              </div>
              <div class="col-sm-6">
                <label class="form-label small fw-semibold mb-1">Odbiorca</label>
                <input type="text" name="odbiorca" id="p_odbiorca" class="form-control form-control-sm"
                       value="<?= h($case['contact_name'] ?? '') ?>">
              </div>
              <div class="col-sm-6">
                <label class="form-label small fw-semibold mb-1">E-mail odbiorcy</label>
                <input type="email" name="odbiorca_email" id="p_odbiorca_email" class="form-control form-control-sm" placeholder="opcjonalnie">
              </div>
              <div class="col-sm-6">
                <label class="form-label small fw-semibold mb-1">Kopia do (DW)</label>
                <input type="text" name="kopia_do" id="p_kopia_do" class="form-control form-control-sm" placeholder="imię, e-mail lub dział">
              </div>
              <div class="col-12">
                <label class="form-label small fw-semibold mb-1">Podpisujący</label>
                <select name="podpisujacy_id" id="p_podpisujacy_id" class="form-select form-select-sm">
                  <option value="">— nie wskazano —</option>
                  <?php foreach ($users_list as $u): ?>
                  <option value="<?= (int)$u['id'] ?>"><?= h($u['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div><hr class="my-0">

          <!-- ── SEKCJA 4: Treść ────────────────────────────────────── -->
          <div class="px-3 py-2">
            <div class="fw-bold text-uppercase mb-2" style="font-size:.72rem;letter-spacing:.08em;color:#5E6470"><i class="bi bi-file-text me-1"></i>Treść i załączniki</div>
            <div class="row g-2">
              <div class="col-12">
                <label class="form-label small fw-semibold mb-1">Podstawa prawna</label>
                <input type="text" name="podstawa_prawna" id="p_podstawa_prawna" class="form-control form-control-sm"
                       placeholder="np. Art. 14 RODO, §5 umowy nr …">
              </div>
              <div class="col-12">
                <label class="form-label small fw-semibold mb-1">Treść pisma</label>
                <textarea name="tresc" id="p_tresc" class="form-control form-control-sm" rows="5"
                          placeholder="Treść pisma (opcjonalna)"></textarea>
              </div>
              <div class="col-12">
                <label class="form-label small fw-semibold mb-1">
                  Załącznik <span id="p_plik_hint" class="text-muted fw-normal">(opcjonalny)</span>
                </label>
                <input type="file" name="pismo_plik" id="p_plik" class="form-control form-control-sm"
                       accept=".pdf,.docx,.doc,.png,.jpg,.jpeg">
              </div>
              <div class="col-12">
                <label class="form-label small fw-semibold mb-1">Uwagi wewnętrzne</label>
                <input type="text" name="uwagi" id="p_uwagi" class="form-control form-control-sm"
                       placeholder="widoczne tylko wewnętrznie">
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer py-2 bg-light">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-warning btn-sm" id="pismoSubmitBtn">
            <i class="bi bi-envelope-check me-1"></i>Zapisz pismo
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// Pokaż/ukryj pola zależne od sposobu doręczenia
function pismoToggleDoreczenie(val) {
  document.getElementById('p_row_nadania').style.display =
    (val === 'kurier' || val === 'poczta') ? '' : 'none';
  document.getElementById('p_row_edoreczenia').style.display =
    val === 'edoreczenia' ? '' : 'none';
}

// Resetuj modal do trybu "Nowe pismo"
document.getElementById('modalPismo').addEventListener('show.bs.modal', function(e) {
  if (e.relatedTarget && e.relatedTarget.dataset.bsTarget) {
    // Otwarto przez przycisk "Nowe pismo" — wyczyść tylko jeśli nie jest edit
    if (!e.relatedTarget.getAttribute('onclick')) {
      pismoReset();
    }
  }
});

function pismoReset() {
  document.getElementById('pismoModalTitle').textContent = 'Nowe pismo do sprawy';
  document.getElementById('pismoModalIcon').className = 'bi bi-envelope-plus me-2';
  document.getElementById('pismoAction').value = 'add_pismo';
  document.getElementById('pismoLetterId').value = '';
  document.getElementById('formPismo').reset();
  // Przywróć domyślne wartości które reset() czyści
  document.getElementById('p_data_pisma').value = '<?= date('Y-m-d') ?>';
  document.getElementById('p_nadawca').value = <?= json_encode(defined('ORG_NAME') ? ORG_NAME : '') ?>;
  document.getElementById('p_odbiorca').value = <?= json_encode($case['contact_name'] ?? '') ?>;
  document.getElementById('p_miejsce').value = <?= json_encode(defined('ORG_CITY') ? ORG_CITY : '') ?>;
  pismoToggleDoreczenie('email');
  document.getElementById('p_plik_hint').textContent = '(opcjonalny)';
  document.getElementById('pismoSubmitBtn').innerHTML = '<i class="bi bi-envelope-check me-1"></i>Zapisz pismo';
}

// Wypełnij modal danymi istniejącego pisma
function pismoEdit(l) {
  pismoReset();
  document.getElementById('pismoModalTitle').textContent = 'Edytuj pismo';
  document.getElementById('pismoModalIcon').className = 'bi bi-pencil-square me-2';
  document.getElementById('pismoAction').value = 'edit_pismo';
  document.getElementById('pismoLetterId').value = l.id;

  var f = function(id, val) {
    var el = document.getElementById('p_' + id);
    if (!el) return;
    if (el.tagName === 'SELECT') {
      el.value = val || '';
    } else if (el.tagName === 'TEXTAREA') {
      el.value = val || '';
    } else {
      el.value = val || '';
    }
  };

  f('kierunek',          l.kierunek);
  f('typ_pisma',         l.typ_pisma);
  f('pilnosc',           l.pilnosc || 'zwykłe');
  f('tytul',             l.tytul);
  f('sygnatura',         l.sygnatura);
  f('miejsce',           l.miejsce);
  f('data_pisma',        l.data_pisma);
  f('sposob_doreczenia', l.sposob_doreczenia || 'email');
  f('termin_odpowiedzi', l.termin_odpowiedzi);
  f('nr_nadania',        l.nr_nadania);
  f('adres_edoreczenia', l.adres_edoreczenia);
  f('edoreczenia_ref',   l.edoreczenia_ref);
  f('nadawca',           l.nadawca);
  f('odbiorca',          l.odbiorca);
  f('odbiorca_email',    l.odbiorca_email);
  f('kopia_do',          l.kopia_do);
  f('podpisujacy_id',    l.podpisujacy_id || '');
  f('podstawa_prawna',   l.podstawa_prawna);
  f('tresc',             l.tresc);
  f('uwagi',             l.uwagi);

  pismoToggleDoreczenie(l.sposob_doreczenia || 'email');

  if (l.plik) {
    document.getElementById('p_plik_hint').textContent = '(pozostaw puste, aby zachować obecny)';
  }
  document.getElementById('pismoSubmitBtn').innerHTML = '<i class="bi bi-floppy me-1"></i>Zapisz zmiany';

  var modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalPismo'));
  modal.show();
}
</script>
<?php endif; ?>

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
