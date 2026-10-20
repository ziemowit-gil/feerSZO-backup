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
require_once dirname(dirname(__DIR__)) . '/includes/crm_case_parties.php';
require_once dirname(dirname(__DIR__)) . '/includes/letters.php';
require_once dirname(dirname(__DIR__)) . '/includes/m365.php';   // m365_setting() w zakładce „Pliki” (crm/includes/case/tabs_work.php) — bez tego fatal przy renderze

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
        header('Location: view.php?id=' . $id . '#prowadzenie'); exit;
    }

    // Zadania sprawy to zadania WŁASNE CRM (includes/crm_tasks.php), nie wiersze
    // w module Zadań — przypomnienie przy sprawie nie potrzebuje obszaru i tablicy.
    if ($action === 'add_task') {
        $t = trim((string)($_POST['task_title'] ?? ''));
        if ($t === '') {
            flash_set('warning', 'Wpisz, co jest do zrobienia.');
        } else {
            $r = crm_case_add_task($id, $t, trim((string)($_POST['task_due'] ?? '')));
            flash_set(!empty($r['ok']) ? 'success' : 'danger',
                !empty($r['ok']) ? 'Zadanie dodane do sprawy.' : ($r['error'] ?: 'Nie udało się dodać zadania.'));
        }
        header('Location: view.php?id=' . $id . '#tasks'); exit;
    }

    if ($action === 'done_task' || $action === 'undone_task') {
        require_once dirname(dirname(__DIR__)) . '/includes/crm_tasks.php';
        crm_task_set_done((int)($_POST['task_id'] ?? 0), $action === 'done_task');
        header('Location: view.php?id=' . $id . '#tasks'); exit;
    }

    if ($action === 'del_task') {
        require_once dirname(dirname(__DIR__)) . '/includes/crm_tasks.php';
        crm_task_delete((int)($_POST['task_id'] ?? 0));
        flash_set('success', 'Zadanie usunięte.');
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
            // Ręczna zmiana numeru sprawy (CRM/NNNN/RRRR, bez powtórzeń)
            $num_err = isset($_POST['case_number']) ? crm_case_set_number($id, (string)$_POST['case_number'], $uid) : '';
            if ($num_err !== '') flash_set('danger', 'Zapisano sprawę, ale numeru nie zmieniono: ' . $num_err);
            else flash_set('success', 'Sprawa zaktualizowana.');
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
            // Pismo na podstawie pliku z zakładki „Pliki” — kopia pliku do pism (oryginał zostaje w Plikach)
            $src_note = '';
            if (!$plik && ($sfid = (int)($_POST['source_file_id'] ?? 0))) {
                $sf  = db_one("SELECT * FROM crm_case_files WHERE id=? AND case_id=?", [$sfid, $id]);
                $ext = $sf ? strtolower(pathinfo((string)$sf['original_name'], PATHINFO_EXTENSION)) : '';
                $src = $sf ? dirname(dirname(__DIR__)) . '/uploads/' . $sf['stored_path'] : '';
                if ($sf && in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'docx'], true) && !str_contains((string)$sf['stored_path'], '..') && is_file($src)) {
                    if (!is_dir(UPLOAD_DIR . 'letters/')) mkdir(UPLOAD_DIR . 'letters/', 0755, true);
                    $rel = 'letters/' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    if (copy($src, UPLOAD_DIR . $rel)) {
                        $plik = $rel;
                        if (function_exists('upload_sync_sharepoint')) upload_sync_sharepoint($rel);
                        $src_note = ' z pliku „' . ($sf['display_name'] ?: $sf['original_name']) . '”';
                    }
                }
                if (!$plik) flash_set('warning', 'Nie udało się podpiąć pliku z zakładki Pliki (plik niedostępny albo rodzaj inny niż PDF, DOCX, JPG, PNG) — pismo zapisano bez pliku.');
            }
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
            flash_set('success', 'Pismo „' . h($tytul) . '" dodane' . h($src_note) . '.');
        }
        header('Location: view.php?id=' . $id . '#pisma'); exit;
    }

    // Usuń pismo
    if ($action === 'delete_pismo') {
        $lid    = (int)($_POST['letter_id'] ?? 0);
        $letter = db_one("SELECT * FROM contract_letters WHERE id=? AND contract_type='crm_case' AND contract_id=?", [$lid, $id]);
        if ($letter && ($letter['created_by'] == $uid || is_admin())) {
            db()->prepare("DELETE FROM contract_letters WHERE id=?")->execute([$lid]);
            letter_file_delete($letter['plik'] ?? null);          // plik po rekordzie; ścieżka z „letters/” (dawniej złe sklejenie — nic nie kasowało)
            flash_set('success', 'Pismo usunięte.');
        }
        header('Location: view.php?id=' . $id . '#pisma'); exit;
    }

    // Podmioty sprawy: wepnij / odepnij; przeniesienie dokumentacji z innych spraw (includes/crm_case_parties.php)
    if ($action === 'add_party') {
        $pcid = (int)($_POST['party_contact_id'] ?? 0);
        $err  = crm_case_party_add($id, $pcid, (string)($_POST['party_role'] ?? 'strona'), (string)($_POST['party_note'] ?? ''), $uid);
        if ($err !== '') { flash_set('danger', $err); header('Location: view.php?id=' . $id . '#podmioty'); exit; }
        flash_set('success', 'Podmiot wpięty do sprawy.');
        // inne sprawy podmiotu → propozycja przeniesienia dokumentacji
        header('Location: view.php?id=' . $id . (crm_case_party_other_cases($id, $pcid) ? '&przenies=' . $pcid : '') . '#podmioty'); exit;
    }
    if ($action === 'remove_party') {
        crm_case_party_remove($id, (int)($_POST['party_id'] ?? 0), $uid);
        flash_set('success', 'Podmiot odpięty — dokumentacja zostaje w sprawie.');
        header('Location: view.php?id=' . $id . '#podmioty'); exit;
    }
    if ($action === 'move_docs') {
        $msgs = []; $errs = [];
        foreach (array_unique(array_map('intval', (array)($_POST['src_case'] ?? []))) as $src) {
            $r = crm_case_move_docs($src, $id, $uid, !empty($_POST['close_src']));
            if ($r['ok']) $msgs[] = array_sum($r['moved']); else $errs[] = $r['error'];
        }
        if ($errs) flash_set('danger', implode(' ', $errs));
        if ($msgs) flash_set('success', 'Przeniesiono dokumentację z ' . count($msgs) . ' spraw (' . array_sum($msgs) . ' pozycji).');
        if (!$msgs && !$errs) flash_set('warning', 'Nie zaznaczono żadnej sprawy.');
        header('Location: view.php?id=' . $id . '#podmioty'); exit;
    }

    // Wyślij pismo e-mailem na dowolny adres (crm_case_letter_mail w includes/crm_case_extras.php)
    if ($action === 'mail_pismo') {
        $res = crm_case_letter_mail($id, (int)($_POST['letter_id'] ?? 0), [
            'to' => (string)($_POST['mail_to'] ?? ''), 'cc' => (string)($_POST['mail_cc'] ?? ''),
            'subject' => (string)($_POST['mail_subject'] ?? ''), 'body' => (string)($_POST['mail_body'] ?? ''),
            'sender' => (string)($_POST['mail_sender'] ?? 'system'),
            'attach_file' => !empty($_POST['mail_attach']), 'include_text' => !empty($_POST['mail_text']),
        ], current_user() ?: []);
        if ($res['ok']) flash_set('success', 'Pismo wysłane do: ' . implode(', ', $res['to']) . ($res['queued'] ? ' (' . $res['queued'] . ' w kolejce — wyjdzie za chwilę)' : '') . '.');
        else flash_set('danger', 'Nie wysłano: ' . $res['error']);
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
                    db()->prepare("UPDATE contract_letters SET plik=? WHERE id=?")->execute([$plik, $lid]);
                    letter_file_delete($letter['plik'] ?? null, $lid);   // stary plik dopiero po zapisaniu nowego w bazie
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
/* Układ Dynamics 365: wspólne style w assets/css/crmDynamics.css (ładowane w crm/includes/header_crm.php) */
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

<?php
  // Dane do nagłówka w stylu Dynamics 365: prowadzący, typ, termin
  $_dyn_owner = !empty($case['owner_id']) ? (string)(db_one("SELECT name FROM users WHERE id = ?", [(int)$case['owner_id']])['name'] ?? '') : '';
  $_dyn_type  = '';
  if (!empty($case['type_id'])) { try { $_dyn_type = (string)(db_one("SELECT name FROM crm_case_types WHERE id = ?", [(int)$case['type_id']])['name'] ?? ''); } catch (\Throwable $e) {} }
  $_dyn_due   = !empty($case['due_date']) ? date('d.m.Y', strtotime((string)$case['due_date'])) : '';
  $_dyn_late  = !empty($case['due_date']) && $case['due_date'] < date('Y-m-d') && !in_array($case['status'], ['closed', 'cancelled'], true);
?>
<!-- Pasek poleceń (jak w Dynamics 365) -->
<div class="dyn-cmdbar" role="toolbar" aria-label="Polecenia sprawy">
  <a href="index.php" class="dyn-cmd"><i class="bi bi-arrow-left" aria-hidden="true"></i><span>Wszystkie sprawy</span></a>
  <?php if ($can_write): ?>
  <span class="dyn-sep" aria-hidden="true"></span>
  <button type="button" class="dyn-cmd" data-bs-toggle="modal" data-bs-target="#modalPismo"><i class="bi bi-envelope-plus" aria-hidden="true"></i><span>Nowe pismo</span></button>
  <a href="#notes" class="dyn-cmd" onclick="setTimeout(function(){var t=document.querySelector('textarea[name=note_body]');if(t)t.focus();},150)"><i class="bi bi-chat-left-text" aria-hidden="true"></i><span>Nowa notatka</span></a>
  <button type="button" class="dyn-cmd" data-bs-toggle="modal" data-bs-target="#crmTaskModal"><i class="bi bi-check2-square" aria-hidden="true"></i><span>Nowe zadanie</span></button>
  <button type="button" class="dyn-cmd" data-bs-toggle="collapse" data-bs-target="#collapseEditMeta" aria-expanded="false" aria-controls="collapseEditMeta"><i class="bi bi-pencil" aria-hidden="true"></i><span>Edytuj</span></button>
  <?php if ($case['status'] !== 'closed'): ?>
  <button type="button" class="dyn-cmd" data-bs-toggle="collapse" data-bs-target="#collapseQuickClose" aria-expanded="false" aria-controls="collapseQuickClose"><i class="bi bi-check2-circle" aria-hidden="true"></i><span>Zamknij sprawę</span></button>
  <?php endif; ?>
  <?php if ($can_manage_shares): ?><a href="#share" class="dyn-cmd"><i class="bi bi-people" aria-hidden="true"></i><span>Udostępnij</span></a><?php endif; ?>
  <?php endif; ?>
  <a href="add.php?contact_id=<?= (int)$case['ct_id'] ?>" class="dyn-cmd ms-auto"><i class="bi bi-plus-lg" aria-hidden="true"></i><span>Nowa sprawa kontaktu</span></a>
</div>

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
        <div class="dyn-entity">Sprawa<?php if (!empty($case['case_number'])): ?> · <span class="font-monospace"><?= h($case['case_number']) ?></span><?php endif; ?></div>
        <div class="d-flex align-items-center flex-wrap gap-2 mb-1">
          <h1 style="font-size:1.35rem;font-weight:600;margin:0;color:#111827;line-height:1.3"><?= h($case['title']) ?></h1>
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

      <!-- Pola nagłówka (jak w Dynamics 365) -->
      <dl class="dyn-hfields">
        <div><dt>Priorytet</dt><dd style="color:<?= $pc['color'] ?>"><?= h($pc['label']) ?></dd></div>
        <div><dt>Status</dt><dd style="color:<?= $sc['color'] ?>"><?= h($sc['label']) ?></dd></div>
        <div><dt>Prowadzi</dt><dd><?= $_dyn_owner !== '' ? h($_dyn_owner) : '<span class="text-muted">—</span>' ?></dd></div>
        <div><dt>Termin</dt><dd<?= $_dyn_late ? ' style="color:#B91C1C"' : '' ?>><?= $_dyn_due !== '' ? h($_dyn_due) : '<span class="text-muted">—</span>' ?></dd></div>
        <?php if ($_dyn_type !== ''): ?><div><dt>Typ</dt><dd><?= h($_dyn_type) ?></dd></div><?php endif; ?>
        <div><dt>Utworzona</dt><dd><?= date('d.m.Y', strtotime($case['created_at'])) ?></dd></div>
      </dl>
    </div>

    <!-- Etapy sprawy (jak pasek procesu biznesowego w Dynamics) -->
    <?php $_dyn_stages = ['open' => 'Otwarta', 'in_progress' => 'W toku', 'closed' => 'Zamknięta']; $_dyn_keys = array_keys($_dyn_stages); $_dyn_pos = array_search($case['status'], $_dyn_keys, true); ?>
    <nav class="dyn-bpf" aria-label="Etapy sprawy">
      <?php foreach ($_dyn_stages as $_sk => $_sl): $_si = array_search($_sk, $_dyn_keys, true); $_cur = $case['status'] === $_sk; $_done = $_dyn_pos !== false && $_si < $_dyn_pos; ?>
        <?php if ($can_write && !$_cur && $_sk !== 'closed' && $case['status'] !== 'cancelled'): ?>
          <form method="post" class="dyn-stage<?= $_done ? ' done' : '' ?>"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_action" value="set_status"><input type="hidden" name="status" value="<?= $_sk ?>">
            <button type="submit" title="Przejdź do etapu: <?= h($_sl) ?>"><span class="dyn-dot" aria-hidden="true"><?= $_done ? '✓' : $_si + 1 ?></span><?= h($_sl) ?></button></form>
        <?php elseif ($can_write && !$_cur && $_sk === 'closed'): ?>
          <div class="dyn-stage"><button type="button" data-bs-toggle="collapse" data-bs-target="#collapseQuickClose" aria-controls="collapseQuickClose" title="Zamknij sprawę (wymaga powodu)"><span class="dyn-dot" aria-hidden="true"><?= $_si + 1 ?></span><?= h($_sl) ?></button></div>
        <?php else: ?>
          <div class="dyn-stage<?= $_cur ? ' current' : ($_done ? ' done' : '') ?>"<?= $_cur ? ' aria-current="step"' : '' ?>><span><span class="dyn-dot" aria-hidden="true"><?= $_done ? '✓' : ($_cur && $_sk === 'closed' ? '✓' : $_si + 1) ?></span><?= h($_sl) ?></span></div>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if ($case['status'] === 'cancelled'): ?><div class="dyn-stage current cancelled" aria-current="step"><span><span class="dyn-dot" aria-hidden="true">✕</span>Anulowana</span></div><?php endif; ?>
    </nav>

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
        <div class="col-sm-3">
          <label class="visually-hidden" for="cvCaseNo">Numer sprawy</label>
          <input id="cvCaseNo" name="case_number" class="form-control form-control-sm font-monospace" value="<?= h((string)$case['case_number']) ?>"
                 placeholder="CRM/0001/<?= date('Y') ?>" pattern="[Cc][Rr][Mm]/[0-9]{4,}/[0-9]{4}" title="Numer sprawy w formacie CRM/0001/2026" aria-describedby="cvCaseNoHelp">
          <div id="cvCaseNoHelp" class="form-text" style="font-size:.72rem">numer sprawy — zmiana ręczna</div>
        </div>
        <div class="col-sm-5">
          <input name="title" class="form-control form-control-sm" value="<?= h($case['title']) ?>" placeholder="Tytuł" required aria-label="Tytuł sprawy">
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

<?php include dirname(__DIR__) . '/includes/case/aside.php'; /* zbiera panele do $_cvp, nic nie wypisuje */ ?>
<div class="row g-3 dyn-form">

<!-- ══ LEWA: szczegóły — prowadzenie i aktywność ═════════════════════════════════ -->
<div class="col-xl-3 col-lg-4 order-2 order-lg-1 dyn-col" aria-label="Szczegóły sprawy">
  <?php include dirname(__DIR__) . '/includes/case/parties.php'; ?>
  <?= ($_cvp['prowadzenie'][2] ?? '') . ($_cvp['aktywnosc'][2] ?? '') ?>
</div>

<!-- ══ ŚRODEK: notatki / pisma / zadania / historia / wiadomości / pliki ══════════ -->
<div class="col-xl-6 col-lg-8 order-1 order-lg-2">

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
      $case_tasks     = crm_case_tasks((int)$case['id']);          // powiązania historyczne z modułem Zadań
      require_once dirname(dirname(__DIR__)) . '/includes/crm_tasks.php';
      $crm_case_tasks = crm_tasks_for_case((int)$case['id']);      // zadania własne CRM
      $case_hist  = crm_case_status_history((int)$case['id']);
    ?>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="case-tab-tasks-btn" data-bs-toggle="tab"
              data-bs-target="#tasks" type="button" role="tab"
              aria-controls="tasks" aria-selected="false">
        <i class="bi bi-check2-square" aria-hidden="true"></i>Zadania
        <?php $tab_tasks_n = count($crm_case_tasks) + count($case_tasks); ?>
        <?php if ($tab_tasks_n): ?><span class="cv-count"><?= $tab_tasks_n ?></span><?php endif; ?>
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
</div><!-- /środek -->

<!-- ══ PRAWA: status, współdzielenie, EZD, skróty ═════════════════════════════════ -->
<div class="col-xl-3 col-lg-12 order-3 dyn-col dyn-col-right">
  <?= ($_cvp['status'][2] ?? '') . ($_cvp['share'][2] ?? '') . ($_cvp['ezd'][2] ?? '') . $_cvp_links ?>
</div>
</div><!-- /row -->

<?php include dirname(__DIR__) . '/includes/case/modal_pismo.php'; ?>
<?php include dirname(__DIR__) . '/includes/case/modal_pismo_mail.php'; ?>
<?php if ($can_write):
        $tm_case_id    = (int)$case['id'];
        $tm_contact_id = (int)($case['contact_id'] ?? 0);
        $tm_title_hint = 'np. Przygotować odpowiedź na skargę';
        include dirname(__DIR__) . '/includes/task_modal.php';
      endif; ?>
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
