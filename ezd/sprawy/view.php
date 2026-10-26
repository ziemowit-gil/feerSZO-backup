<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd_rpwy.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd_kopia.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd_zal_menu.php';
require_once dirname(dirname(__DIR__)) . '/modules/doc_registry/lib/DocRegistry.php';
require_once dirname(dirname(__DIR__)) . '/includes/mail_queue.php';
require_once dirname(dirname(__DIR__)) . '/includes/sms.php';
if (module_enabled('org_enabled')) {
    require_once dirname(dirname(__DIR__)) . '/includes/org.php';
    require_once dirname(dirname(__DIR__)) . '/includes/notification_service.php';
}

require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko'); ezd_require_access();

$id     = (int)($_GET['id'] ?? 0);
$sprawa = ezd_sprawa_get($id);
if (!$sprawa) { flash_set('error', 'Koszulka nie istnieje.'); header('Location: ' . APP_URL . '/ezd/sprawy/index.php'); exit; }

$user_id = (int)current_user()['id'];
$access  = ezd_sprawa_access($sprawa, $user_id);
if (!$access) { flash_set('error', 'Brak dostępu do tej koszulki.'); header('Location: ' . APP_URL . '/ezd/index.php'); exit; }

$PAGE_TITLE = $sprawa['znak_sprawy'] . ' — ' . $sprawa['title'];

$timeline    = ezd_timeline($id);
$dekretacje  = ezd_dekretacje_by_sprawa($id);
$zalaczniki  = ezd_zalaczniki_by($id);
$log_entries = ezd_log_by_sprawa($id, 30);
$users       = db_all("SELECT id,name FROM users WHERE is_active=1 ORDER BY name");
$podsprawy   = ezd_podsprawy_by_parent($id);
$dokumenty   = ezd_dokumenty_by_sprawa($id);
$notatki     = ezd_notatki_by_sprawa($id);
$grupy         = ezd_grupy_by_sprawa($id);
$sign_requests = ezd_sign_requests_by_sprawa($id);
$shares        = ezd_sprawa_share_list($id);
$ext_shares    = ezd_ext_share_list($id);
try { $strony = db_all("SELECT s.*, c.imie_nazwisko AS crm_name FROM ezd_strony s LEFT JOIN crm_contacts c ON c.id=s.crm_id WHERE s.sprawa_id=? ORDER BY s.created_at", [$id]); } catch (\Throwable $e) { $strony = []; }

// Pliki repozytorium pogrupowane: grupa_id => [pliki], 0 => bez grupy
$grupy_map = [];
foreach ($grupy as $g) $grupy_map[(int)$g['id']] = [];
$bez_grupy = [];
foreach ($zalaczniki as $z) {
    $gid = (int)($z['grupa_id'] ?? 0);
    if ($gid && isset($grupy_map[$gid])) $grupy_map[$gid][] = $z;
    else $bez_grupy[] = $z;
}

$is_closed        = $sprawa['status'] === 'closed';
$can_edit_case      = $access === 'write';                                        // metadane + współdzielenie
$can_act            = $can_edit_case && !$is_closed;                             // pełna edycja treści
$can_create_pismo   = in_array($access, ['write','pisma'], true) && !$is_closed; // tworzenie pism (poziom pisma+)
$can_manage_share   = ezd_sprawa_can_manage_share($sprawa, $user_id);
$mini             = ezd_mini(); // tryb uproszczony — ukrywa metrykę i obieg/workflow

// Tylko PDF-y z podpisem elektronicznym ze wszystkich plików repozytorium koszulki
$signed_pdfs = [];
foreach ($zalaczniki as $z) {
    if (strtolower(pathinfo($z['original_name'], PATHINFO_EXTENSION)) !== 'pdf') continue;
    $fp = UPLOAD_DIR . EZD_UPLOAD_SUBDIR . $id . '/' . $z['filename'];
    if (is_file($fp) && ezd_signature_info($fp, $z['original_name'])['signed']) {
        $signed_pdfs[] = $z;
    }
}

// Obsługa POST (upload + dekretacja + zmiana statusu sprawy)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    if ($action === 'upload') {
        if (!$can_act) { http_response_code(403); exit; }
        $grupa_id = (int)($_POST['grupa_id'] ?? 0) ?: null;
        // Pole "file[]" (multiple) zawsze zwraca tablicę — 1 element = pojedynczy plik
        // (zachowuje własną nazwę + opcjonalną konwersję na PDF), >1 = upload zbiorczy.
        $file_names = $_FILES['file']['name'] ?? null;
        if (is_array($file_names) && count($file_names) > 1) {
            $res = ezd_upload_multi('file', $id, $user_id, $grupa_id);
            $msg = $res['ok'] . ' plik(ów) dodano do repozytorium koszulki.';
            if ($res['errors']) $msg .= ' Nie dodano: ' . implode('; ', $res['errors']);
            flash_set($res['ok'] && !$res['errors'] ? 'success' : ($res['ok'] ? 'warning' : 'error'), $msg);
        } else {
            $custom_name = trim($_POST['custom_name'] ?? '');
            $new_zal_id = null;
            if (is_array($file_names)) {
                // Spłaszcz pojedynczy element tablicy do zwykłego $_FILES['file'], jak przy input bez [].
                foreach (['name','type','tmp_name','error','size'] as $k) $_FILES['file'][$k] = $_FILES['file'][$k][0] ?? null;
            }
            $err = ezd_upload('file', $id, $user_id, null, null, null, null, $grupa_id, $custom_name ?: null, $new_zal_id);
            $msg = $err ?: 'Plik dodany do repozytorium koszulki.';
            if (!$err && $new_zal_id && !empty($_POST['convert_pdf'])) {
                $conv = ezd_convert_to_pdf($new_zal_id, $user_id);
                $msg .= $conv['ok'] ? ' Utworzono też wersję PDF.' : (' Konwersja na PDF nie powiodła się: ' . $conv['error']);
            }
            flash_set($err ? 'error' : 'success', $msg);
        }
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#files'); exit;
    }

    if ($action === 'new_office_file') {
        if (!$can_act) { http_response_code(403); exit; }
        $grupa_id = (int)($_POST['grupa_id'] ?? 0) ?: null;
        $with_znak = !empty($_POST['with_znak']);
        $r = ezd_new_office_file($id, $user_id, $_POST['filetype'] ?? '', trim($_POST['name'] ?? ''), $grupa_id, $with_znak);
        flash_set($r['ok'] ? 'success' : 'error', $r['ok'] ? 'Utworzono nowy plik.' : $r['error']);
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#files'); exit;
    }

    if ($action === 'convert_pdf') {
        if (!$can_act) { http_response_code(403); exit; }
        $conv = ezd_convert_to_pdf((int)($_POST['zal_id'] ?? 0), $user_id);
        flash_set($conv['ok'] ? 'success' : 'error', $conv['ok'] ? 'Utworzono wersję PDF.' : ('Nie udało się przekonwertować: ' . $conv['error']));
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#files'); exit;
    }

    if ($action === 'del_file') {
        if (!$can_act) { http_response_code(403); exit; }
        ezd_zal_delete((int)($_POST['zal_id'] ?? 0), $user_id);
        flash_set('success', 'Plik usunięty.');
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#files'); exit;
    }

    if ($action === 'request_sign') {
        if (!$can_act) { http_response_code(403); exit; }
        $zal_id_s = (int)($_POST['zal_id'] ?? 0);
        $to_user  = (int)($_POST['sign_user_id'] ?? 0);
        $notes_s  = trim($_POST['sign_notes'] ?? '');
        if ($zal_id_s && $to_user) {
            ezd_sign_request_create($zal_id_s, $id, $user_id, $to_user, $notes_s);
            flash_set('success', 'Prośba o podpis wysłana.');
        } else {
            flash_set('error', 'Wybierz dokument i osobę podpisującą.');
        }
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#sign-requests'); exit;
    }

    if ($action === 'pismo_add' && $can_create_pismo) {
        try {
            $pid = ezd_pismo_create([
                'sprawa_id'     => $id,
                'kierunek'      => $_POST['kierunek']     ?? 'przychodzace',
                'title'         => trim($_POST['title']   ?? ''),
                'tresc'         => $_POST['tresc']        ?? '',
                'nadawca'       => trim($_POST['nadawca'] ?? ''),
                'odbiorca'      => trim($_POST['odbiorca']?? ''),
                'data_pisma'    => $_POST['data_pisma']   ?? '',
                'data_wplywu'   => $_POST['data_wplywu']  ?? '',
                'data_wysylki'  => $_POST['data_wysylki'] ?? '',
                'status'        => $_POST['status']       ?? 'nowe',
                'owner_id'      => (int)($_POST['owner_id'] ?? 0) ?: null,
                'rodzaj_medium' => $_POST['rodzaj_medium'] ?? 'papier',
            ], $user_id);
            flash_set('success', 'Pismo dodane.');
            header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#tab-pisma'); exit;
        } catch (\Throwable $e) { flash_set('error', $e->getMessage()); }
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#pisma'); exit;
    }

    if ($action === 'grupa_add' && $can_act) {
        try { ezd_grupa_create($id, $_POST['nazwa'] ?? '', $user_id); flash_set('success','Grupa plików utworzona.'); }
        catch (\Throwable $e) { flash_set('error', $e->getMessage()); }
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#files'); exit;
    }
    if ($action === 'grupa_rename' && $can_act) {
        try { ezd_grupa_rename((int)($_POST['grupa_id'] ?? 0), $_POST['nazwa'] ?? '', $user_id); flash_set('success','Nazwę grupy zmieniono.'); }
        catch (\Throwable $e) { flash_set('error', $e->getMessage()); }
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#files'); exit;
    }
    if ($action === 'grupa_del' && $can_act) {
        ezd_grupa_delete((int)($_POST['grupa_id'] ?? 0), $user_id);
        flash_set('success', 'Grupę usunięto (pliki pozostały bez grupy).');
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#files'); exit;
    }
    if ($action === 'zal_move' && $can_act) {
        ezd_zal_set_grupa((int)($_POST['zal_id'] ?? 0), (int)($_POST['grupa_id'] ?? 0) ?: null, $user_id);
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#files'); exit;
    }

    if ($action === 'zal_przekaz' && $can_act) {
        $przekaz_user_id = (int)($_POST['przekaz_user_id'] ?? 0);
        $przekaz_zal_ids = $_POST['zal_ids'] ?? [];
        if (!$przekaz_user_id || !$przekaz_zal_ids) {
            flash_set('error', 'Wybierz co najmniej jeden plik i osobę.');
        } else {
            $n = ezd_zal_access_grant($przekaz_zal_ids, $przekaz_user_id, $user_id, trim($_POST['przekaz_note'] ?? ''));
            flash_set($n ? 'success' : 'warning', $n ? "Przekazano dostęp do {$n} plik(ów)." : 'Ta osoba miała już dostęp do wybranych plików.');
        }
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#files'); exit;
    }

    if ($action === 'share_add' && $can_manage_share) {
        $su_id = (int)($_POST['share_user_id'] ?? 0);
        $su_upr = $_POST['share_uprawnienie'] ?? 'odczyt';
        if ($su_id) { ezd_sprawa_share_add($id, $su_id, $su_upr, $user_id); flash_set('success', 'Sprawa udostępniona.'); }
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '&share=1'); exit;
    }
    if ($action === 'share_del' && $can_manage_share) {
        ezd_sprawa_share_remove($id, (int)($_POST['share_user_id'] ?? 0), $user_id);
        flash_set('success', 'Odebrano współdzielenie.');
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '&share=1'); exit;
    }

    if ($action === 'ext_share_add' && $can_manage_share) {
        $ext_email     = trim($_POST['ext_email'] ?? '');
        $ext_phone     = trim($_POST['ext_phone'] ?? '');
        $ext_hours     = (int)($_POST['ext_valid_hours'] ?? 48);
        $ext_message   = trim($_POST['ext_message'] ?? '');
        $ext_zal_ids   = array_map('intval', $_POST['ext_zal_ids'] ?? []);
        $ext_grupa_ids = array_map('intval', $_POST['ext_grupa_ids'] ?? []);
        $ext_mode      = (string)($_POST['ext_access_mode'] ?? 'download');
        $ext_otp_mode  = (string)($_POST['ext_otp_mode'] ?? 'sms');
        $ext_manual_code = trim($_POST['ext_manual_code'] ?? '');
        $r = ezd_ext_share_create($id, $user_id, $ext_email, $ext_phone, $ext_message, $ext_hours, $ext_zal_ids, $ext_grupa_ids, $ext_mode, $ext_otp_mode, $ext_manual_code);
        flash_set($r['ok'] ? 'success' : 'error', $r['ok']
            ? ('Udostępniono na zewnątrz.' . ($r['mail_sent'] ? ' E-mail z linkiem został wysłany.' : ' UWAGA: wysyłka e-maila nie powiodła się — użyj „Wyślij ponownie”.'))
            : $r['error']);
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#tab-uczestnicy'); exit;
    }
    if ($action === 'ext_share_resend' && $can_manage_share) {
        $r = ezd_ext_share_resend((int)($_POST['ext_share_id'] ?? 0), $user_id);
        flash_set($r['ok'] ? 'success' : 'error', $r['ok'] ? 'Link wysłany ponownie.' : $r['error']);
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#tab-uczestnicy'); exit;
    }
    if ($action === 'ext_share_revoke' && $can_manage_share) {
        ezd_ext_share_revoke((int)($_POST['ext_share_id'] ?? 0), $user_id);
        flash_set('success', 'Udostępnienie zewnętrzne odwołane.');
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#tab-uczestnicy'); exit;
    }

    if ($action === 'dekretacja' && $can_act) {
        try {
            $unit_id_d    = (int)($_POST['unit_id'] ?? 0) ?: null;
            $wykonawca_id = (int)($_POST['wykonawca_id'] ?? 0);
            $rola_target  = trim($_POST['rola_target'] ?? '');

            if ($rola_target === '') {
                // Dekretacja do osoby
                if ($unit_id_d && !$wykonawca_id && module_enabled('org_enabled')) {
                    $head = org_unit_head($unit_id_d);
                    if ($head) $wykonawca_id = (int)$head['user_id'];
                }
                if (!$wykonawca_id) throw new \RuntimeException('Wybierz osobę lub jednostkę, której przekazujesz koszulkę.');
            }

            $dekr_id = ezd_dekretacja_create([
                'sprawa_id'    => $id,
                'pismo_id'     => null,
                'umowa_id'     => null,
                'wykonawca_id' => $wykonawca_id,
                'unit_id'      => $unit_id_d,
                'rola_target'  => $rola_target,
                'dyspozycja'   => $_POST['dyspozycja'] ?? 'do_zalat',
                'tresc'        => trim($_POST['tresc'] ?? ''),
                'deadline'     => $_POST['deadline'] ?: null,
            ], $user_id);

            // Wyślij powiadomienie e-mail
            if (module_enabled('org_enabled') && $dekr_id) {
                try {
                    if ($unit_id_d) {
                        NotificationService::onUnitAssignment($dekr_id, $unit_id_d);
                    } else {
                        NotificationService::onDekretacja($dekr_id);
                    }
                } catch (\Throwable $e) { /* powiadomienie nie blokuje akcji */ }
            }

            flash_set('success', 'Przekazano.');
        } catch (\Throwable $e) { flash_set('error', $e->getMessage()); }
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#dekretacje'); exit;
    }

    if ($action === 'dekr_done') {
        $did = (int)($_POST['dekr_id'] ?? 0);
        ezd_dekretacja_mark_read($did, $user_id);
        ezd_dekretacja_complete($did, $user_id);
        flash_set('success', 'Zadanie oznaczone jako wykonane.');
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#dekretacje'); exit;
    }
    if ($action === 'dekr_claim') {
        $did = (int)($_POST['dekr_id'] ?? 0);
        if (ezd_dekretacja_claim($did, $user_id)) {
            flash_set('success', 'Przejąłeś(-aś) dekretację.');
        } else {
            flash_set('error', 'Nie można przejąć — rola niezgodna, już przejęta lub zamknięta.');
        }
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#dekretacje'); exit;
    }
    if ($action === 'dekr_read') {
        ezd_dekretacja_mark_read((int)($_POST['dekr_id'] ?? 0), $user_id);
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#dekretacje'); exit;
    }

    if ($action === 'set_etap' && $can_act) {
        try {
            ezd_sprawa_set_etap($id, $_POST['etap'] ?? '', $user_id);
            flash_set('success', 'Zmieniono etap obiegu koszulki.');
        } catch (\Throwable $e) { flash_set('error', $e->getMessage()); }
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#workflow'); exit;
    }

    // ── Notatki ──────────────────────────────────────────────────────────────
    if ($action === 'note_add' && $can_act) {
        $tresc = trim($_POST['tresc'] ?? '');
        if ($tresc !== '') { ezd_notatka_create($id, $tresc, $user_id); flash_set('success', 'Notatka dodana.'); }
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#notatki'); exit;
    }
    if ($action === 'note_edit' && $can_act) {
        $n = ezd_notatka_get((int)($_POST['note_id'] ?? 0));
        if ($n && ($n['created_by'] == $user_id || is_admin())) {
            ezd_notatka_update((int)$n['id'], trim($_POST['tresc'] ?? ''), $user_id);
            flash_set('success', 'Notatka zaktualizowana.');
        } else { flash_set('error', 'Brak uprawnień do edycji notatki.'); }
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#notatki'); exit;
    }
    if ($action === 'note_del' && $can_act) {
        $n = ezd_notatka_get((int)($_POST['note_id'] ?? 0));
        if ($n && ($n['created_by'] == $user_id || is_admin())) {
            ezd_notatka_delete((int)$n['id'], $user_id);
            flash_set('success', 'Notatka usunięta.');
        } else { flash_set('error', 'Brak uprawnień do usunięcia notatki.'); }
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#notatki'); exit;
    }
    if ($action === 'note_pin' && $can_act) {
        ezd_notatka_toggle_pin((int)($_POST['note_id'] ?? 0), $user_id);
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#notatki'); exit;
    }

    if ($action === 'pismo_from_zal' && $can_act) {
        $zal_id = (int)($_POST['zal_id'] ?? 0);
        $zal_row = db()->prepare("SELECT * FROM ezd_zalaczniki WHERE id=? AND sprawa_id=?")->execute([$zal_id, $id]) ? null : null;
        $stmt = db()->prepare("SELECT * FROM ezd_zalaczniki WHERE id=? AND sprawa_id=?");
        $stmt->execute([$zal_id, $id]);
        $zal_row = $stmt->fetch();
        if (!$zal_row) {
            flash_set('error', 'Nie znaleziono pliku w aktach tej koszulki.');
            header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#files'); exit;
        }
        $zfp  = UPLOAD_DIR . EZD_UPLOAD_SUBDIR . $id . '/' . $zal_row['filename'];
        $sig  = ezd_signature_info($zfp, $zal_row['original_name']);
        if (!$sig['signed']) {
            flash_set('error', 'Wybrany plik nie zawiera podpisu elektronicznego.');
            header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#files'); exit;
        }
        $title  = trim($_POST['pismo_title']   ?? '');
        $kier   = $_POST['kierunek']           ?? 'przychodzace';
        $medium = $_POST['rodzaj_medium']      ?? 'inne';
        $dpis   = trim($_POST['data_pisma']    ?? '');
        $nad    = trim($_POST['nadawca']        ?? '');
        $odb    = trim($_POST['odbiorca']       ?? '');
        if (!$title) {
            flash_set('error', 'Podaj tytuł pisma.');
            header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#files'); exit;
        }
        $pid = ezd_pismo_create([
            'sprawa_id'    => $id,
            'kierunek'     => in_array($kier, array_keys(EZD_KIERUNKI), true) ? $kier : 'przychodzace',
            'title'        => $title,
            'nadawca'      => $nad,
            'odbiorca'     => $odb,
            'data_pisma'   => $dpis ?: date('Y-m-d'),
            'rodzaj_medium'=> $medium,
            'status'       => 'nowe',
        ], $user_id);
        db()->prepare("UPDATE ezd_zalaczniki SET pismo_id=? WHERE id=? AND sprawa_id=?")->execute([$pid, $zal_id, $id]);
        ezd_log(null, $id, $pid, null, $user_id, 'pismo_from_zal',
            'Utworzono pismo z pliku: ' . $zal_row['original_name']);
        flash_set('success', 'Pismo zostało utworzone i plik przypisany.');
        header('Location: ' . APP_URL . '/ezd/pisma/view.php?id=' . $pid); exit;
    }

    if ($action === 'send_email_pismo' && $can_act && $signed_pdfs) {
        $recipient = trim($_POST['recipient_email'] ?? '');
        $subject   = trim($_POST['mail_subject']    ?? '');
        $body_raw  = trim($_POST['mail_body']       ?? '');
        $zids      = array_filter(array_map('intval', (array)($_POST['zal_ids'] ?? [])));
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            flash_set('error', 'Podaj prawidłowy adres e-mail odbiorcy.');
        } elseif (!$zids) {
            flash_set('error', 'Wybierz co najmniej jeden plik do wysłania.');
        } else {
            $attachments = [];
            foreach ($signed_pdfs as $z) {
                if (!in_array((int)$z['id'], $zids, true)) continue;
                $attachments[] = [
                    'path' => EZD_UPLOAD_SUBDIR . $id . '/' . $z['filename'],
                    'name' => $z['original_name'],
                    'mime' => 'application/pdf',
                    'size' => (int)$z['file_size'],
                ];
            }
            if (!$attachments) {
                flash_set('error', 'Nie znaleziono podpisanych plików PDF do wysłania.');
            } else {
                $subj      = $subject ?: $sprawa['znak_sprawy'] . ' — ' . $sprawa['title'];
                $body_html = $body_raw ? nl2br(htmlspecialchars($body_raw, ENT_QUOTES, 'UTF-8')) : '';
                mail_queue_add($recipient, $recipient, $subj,
                    $body_html, $body_raw, 'ezd_sprawa', $id, '', false, $attachments);
                ezd_log(null, $id, null, null, $user_id, 'sprawa_email_sent',
                    'Wysłano mailem do: ' . $recipient . '; pliki: ' . implode(', ', array_column($attachments, 'name')));
                flash_set('success', 'Wiadomość e-mail wysłana na adres ' . htmlspecialchars($recipient, ENT_QUOTES) . '.');
            }
        }
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id); exit;
    }

    if ($action === 'strona_add' && $can_manage_share) {
        $strona_name  = trim($_POST['strona_name']  ?? '');
        $strona_crm   = (int)($_POST['strona_crm_id'] ?? 0) ?: null;
        $strona_rola  = trim($_POST['strona_rola']  ?? '');
        if ($strona_name) {
            db()->exec("CREATE TABLE IF NOT EXISTS ezd_strony (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sprawa_id INTEGER NOT NULL,
                name TEXT NOT NULL DEFAULT '',
                crm_id INTEGER DEFAULT NULL,
                rola TEXT NOT NULL DEFAULT '',
                created_by INTEGER NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
            db()->prepare("INSERT INTO ezd_strony (sprawa_id,name,crm_id,rola,created_by) VALUES (?,?,?,?,?)")
               ->execute([$id, $strona_name, $strona_crm, $strona_rola, $user_id]);
            flash_set('success', 'Strona dodana.');
        }
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '?share=1#tab-uczestnicy'); exit;
    }
    if ($action === 'strona_del' && $can_manage_share) {
        $sid = (int)($_POST['strona_id'] ?? 0);
        try { db()->prepare("DELETE FROM ezd_strony WHERE id=? AND sprawa_id=?")->execute([$sid, $id]); } catch (\Throwable $e) {}
        flash_set('success', 'Stronę usunięto.');
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '?share=1#tab-uczestnicy'); exit;
    }

    if ($action === 'close_sprawa' && $can_act) {
        try {
            ezd_sprawa_close($id, $user_id, $_POST['close_reason'] ?? '');
            flash_set('success', 'Koszulka zamknięta.');
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id); exit;
    }

    header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id); exit;
}

// „Ostatnie” w menu bocznym EZD (includes/ezd_sidebar.php) — 5 ostatnio otwartych koszulek w tej sesji
$_SESSION['ezd_recent'] = array_slice(array_merge(
    [['id' => $id, 'znak' => (string)$sprawa['znak_sprawy'], 'title' => mb_substr((string)$sprawa['title'], 0, 80)]],
    array_values(array_filter((array)($_SESSION['ezd_recent'] ?? []), fn($r) => (int)($r['id'] ?? 0) !== $id))
), 0, 5);

include dirname(dirname(__DIR__)) . '/includes/header.php';

// Workflow — zmienne używane w metabarze, stepperze i tabie "Przebieg"
$wf_steps  = ezd_sprawa_workflow($sprawa);
$wf_keys   = array_column($wf_steps, 'key');
$cur_etap  = $sprawa['etap'] ?: ($wf_keys[0] ?? 'wszczeta');
$cur_idx   = array_search($cur_etap, $wf_keys, true);
if ($cur_idx === false) $cur_idx = -1;
$next_etap = $wf_keys[$cur_idx + 1] ?? null;
$next_step = $next_etap !== null ? ($wf_steps[$cur_idx + 1] ?? null) : null;
$wf_custom = (bool) ezd_workflow_get((int)($sprawa['jrwa_id'] ?? 0));
?>

<style>
/* ── Karta koszulki — układ Dynamics 365 (pasek poleceń, pola nagłówka, etapy obiegu, sekcje z białym
   nagłówkiem, małe zakładki) na szacie Metro EZD (includes/ezd_metro.php): zero zaokrągleń i cieni,
   znaczniki w pełnych kolorach. Nazwy klas są zaczepami skryptów i motywu —
   zostają; reguły zakotwiczone w `html body .ezd-main` (wygrywają z Tailwind CDN i ezd-tz-theme.css). ── */
html body .ezd-main .ezx-badge{display:inline-flex;align-items:center;gap:.25rem;padding:.12rem .45rem;font-size:.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap;line-height:1.3;color:#fff;background:var(--m-grey);border:0}
html body .ezd-main .ezx-badge--slate{background:var(--m-grey)}
html body .ezd-main .ezx-badge--sky{background:var(--m-teal)}
html body .ezd-main .ezx-badge--blue{background:var(--m-blue)}
html body .ezd-main .ezx-badge--amber{background:var(--m-orange)}
html body .ezd-main .ezx-badge--emerald{background:var(--m-green)}
html body .ezd-main .ezx-badge--rose,html body .ezd-main .ezx-badge--urgent{background:var(--m-red)}
html body .ezd-main .ezx-badge--violet{background:var(--m-violet)}
/* Nagłówek w układzie Dynamics (.dyn-rec — dynamics.css + blok w ezd_metro.php): znaczniki pod tytułem, opis */
html body .ezd-main .sp-flags{display:flex;flex-wrap:wrap;gap:.35rem;margin-top:.4rem}
html body .ezd-main .sp-desc{margin:.45rem 0 0;font-size:.88rem;color:var(--em-muted);line-height:1.5;max-width:80ch}
html body .ezd-main .sp-closed{display:flex;align-items:center;gap:.6rem;background:var(--em-light);border-left:6px solid var(--m-grey);padding:.75rem 1rem;margin-bottom:1rem;font-size:.86rem}
/* Pasek poleceń Metro: jedna akcja główna (wypełniona), reszta płaska — ikona + etykieta bez ramki */
html body .ezd-main .sp-cmd{--bs-btn-color:var(--em-ink);--bs-btn-bg:transparent;--bs-btn-border-color:transparent;--bs-btn-hover-color:var(--em-ink);--bs-btn-hover-bg:var(--em-light-h);--bs-btn-hover-border-color:transparent;--bs-btn-active-bg:var(--em-line);--bs-btn-active-border-color:transparent;--bs-btn-disabled-color:var(--em-muted);--bs-btn-disabled-bg:transparent;--bs-btn-disabled-border-color:transparent;font-weight:600;font-size:.8rem;display:inline-flex;align-items:center;gap:.35rem;padding:.35rem .6rem}
html body .ezd-main .sp-cmd > .bi{color:var(--em-acc);font-size:1rem}
html body .ezd-main .sp-cmd:disabled > .bi{color:var(--em-muted)}
html body .ezd-main .sp-cmd[aria-expanded="true"]{background:var(--em-light-h)}
html body .ezd-main .sp-cmdbar{display:flex;flex-wrap:wrap;align-items:center;gap:.15rem}
html body .ezd-main .sp-cmdbar .btn-primary{margin-left:.35rem}
html body .ezd-main .sp-cmdbar--start{gap:.25rem}
html body .ezd-main .sp-cmdbar--start .btn-primary{margin:0 .5rem 0 0}
html body .ezd-main .sp-more{min-width:230px;padding:.3rem 0}
html body .ezd-main .sp-more .dropdown-item,html body .ezd-main .sp-pm-menu .dropdown-item{display:flex;align-items:center;gap:.6rem;padding:.5rem 1rem;font-size:.85rem}
html body .ezd-main .sp-more .dropdown-item .bi,html body .ezd-main .sp-pm-menu .dropdown-item .bi{color:var(--em-muted);width:1.1rem;text-align:center}
html body .ezd-main .sp-more .sp-more-danger,html body .ezd-main .sp-more .sp-more-danger .bi{color:var(--m-red)}
html body .ezd-main .sp-pm-menu.show{display:grid;grid-template-columns:1fr 1fr;padding:0;min-width:340px}
html body .ezd-main .sp-pm-col{padding:.35rem 0}
html body .ezd-main .sp-pm-col + .sp-pm-col{border-left:1px solid var(--em-line)}
html body .ezd-main .sp-pm-h{display:flex;align-items:center;gap:.4rem;padding:.4rem 1rem .3rem;font-size:.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:var(--em-muted)}
/* Dokumenty w koszulce (akta): sekcja z białym nagłówkiem (Dynamics) */
html body .ezd-main .sp-akta-hdr{display:flex;flex-wrap:wrap;align-items:center;gap:.75rem;padding:.6rem 1rem;background:#fff;border:1px solid var(--em-line);border-bottom:1px solid var(--em-line)}
html body .ezd-main .sp-akta-title{font-size:.95rem;font-weight:600;color:var(--em-ink)}
html body .ezd-main .sp-akta-title .bi{color:var(--em-acc)}
html body .ezd-main .sp-akta-count{font-size:.8rem;color:var(--em-muted)}
html body .ezd-main .sp-akta-hdr .btn{font-size:.76rem;font-weight:600}
html body .ezd-main .sp-akta-body{background:#fff;border:1px solid var(--em-line);border-top:0;overflow:hidden;margin-bottom:1rem}
html body .ezd-main .sp-file-row{display:flex;align-items:center;gap:.65rem;padding:.6rem 1rem;border-bottom:1px solid var(--em-line);font-size:.82rem;transition:background .1s}
html body .ezd-main .sp-file-row:last-child{border-bottom:0}
html body .ezd-main .sp-file-row:hover{background:var(--em-light)}
/* Zakładki jak w Dynamics 365 */
html body .ezd-main .sp-tabs{display:flex;gap:1.25rem;margin:1rem 0 0!important;padding:0;background:transparent;border:0;border-bottom:1px solid var(--em-line);overflow-x:auto;flex-wrap:nowrap;white-space:nowrap;scrollbar-width:none}
html body .ezd-main .sp-tabs::-webkit-scrollbar{display:none}
html body .ezd-main .sp-tabs .nav-link{display:inline-flex;align-items:center;gap:.4rem;background:none;border:0;border-bottom:3px solid transparent;margin-bottom:-1px;padding:.45rem 0 .55rem;font-size:.9rem;font-weight:400;letter-spacing:0;text-transform:none;color:var(--em-muted);white-space:nowrap}
html body .ezd-main .sp-tabs .nav-link .bi{display:none}
html body .ezd-main .sp-tabs .nav-link:hover{color:var(--em-ink)}
html body .ezd-main .sp-tabs .nav-link.active{color:var(--em-ink);font-weight:600;border-bottom-color:var(--em-acc);background:none}
html body .ezd-main .sp-tabs .nav-link .badge{font-size:.66rem;background:var(--m-red)!important;color:#fff!important;padding:.1rem .4rem}
html body .ezd-main .sp-tab-pane{background:#fff;border:0;padding:1.1rem 0 0}
/* Przekazania */
html body .ezd-main .dekr-row{display:flex;align-items:flex-start;gap:.75rem;padding:.65rem 0;border-bottom:1px solid var(--em-line);font-size:.82rem}
html body .ezd-main .dekr-row:last-child{border-bottom:0}
/* Prawa kolumna: sekcje formularza jak w Dynamics 365 */
html body .ezd-main .sp-side{background:#fff;border:1px solid var(--em-line);min-width:0;overflow:hidden}
html body .ezd-main .sp-side-hd{display:flex;align-items:center;gap:.5rem;padding:.6rem 1rem;background:#fff;border-bottom:1px solid var(--em-line)}
html body .ezd-main .sp-side-hd .bi{color:var(--em-acc)}
html body .ezd-main .sp-side-h{margin:0;font-size:.95rem;font-weight:600;color:var(--em-ink)}
html body .ezd-main .sp-side-bd{padding:.85rem 1rem}
html body .ezd-main .sp-kv{display:grid;grid-template-columns:minmax(96px,38%) 1fr;gap:.45rem .75rem;margin:0;font-size:.82rem}
html body .ezd-main .sp-kv dt{margin:0;min-width:0;font-size:.66rem;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--em-muted);padding-top:.15rem}
html body .ezd-main .sp-kv dd{margin:0;min-width:0;color:var(--em-ink);word-break:break-word}
/* Historia operacji */
html body .ezd-main .sp-log{max-height:380px;overflow-y:auto}
html body .ezd-main .sp-log-row{display:flex;align-items:flex-start;gap:.5rem;padding:.4rem 0;font-size:.78rem;border-bottom:1px solid var(--em-line)}
html body .ezd-main .sp-log-row:last-child{border-bottom:0}
html body .ezd-main .sp-log-dot{flex-shrink:0;margin-top:.4rem;width:8px;height:8px;background:var(--em-field)}
/* Oś czasu pism: kwadratowe znaczniki, karty z grubą lewą krawędzią w kolorze kierunku */
html body .ezd-main .tl-wrap{position:relative;padding-left:2rem}
html body .ezd-main .tl-wrap::before{content:'';position:absolute;left:.55rem;top:0;bottom:0;width:2px;background:var(--em-line)}
html body .ezd-main .tl-item{position:relative;margin-bottom:.6rem}
html body .ezd-main .tl-dot{position:absolute;left:-1.45rem;top:.55rem;width:12px;height:12px;background:var(--m-blue);border:2px solid #fff;border-radius:0!important}
html body .ezd-main .tl-dot.pismo-in{background:var(--m-teal)}
html body .ezd-main .tl-dot.pismo-out{background:var(--m-blue)}
html body .ezd-main .tl-dot.pismo-int{background:var(--m-grey)}
html body .ezd-main .tl-dot.umowa-card{background:var(--m-orange)}
html body .ezd-main .tl-card{display:block;background:var(--em-light);border:0!important;border-left:6px solid var(--m-grey)!important;padding:.7rem 1rem;text-decoration:none;color:inherit;transition:background .1s}
html body .ezd-main .tl-card:hover{background:var(--em-light-h);color:inherit}
html body .ezd-main .tl-card.pismo-in{border-left-color:var(--m-teal)!important}
html body .ezd-main .tl-card.pismo-out{border-left-color:var(--m-blue)!important}
html body .ezd-main .tl-card.umowa-card{border-left-color:var(--m-orange)!important}
html body .ezd-main .tl-syg{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.7rem;font-weight:700;color:var(--em-muted);letter-spacing:.04em}
html body .ezd-main .tl-title{font-weight:600;font-size:.92rem;color:var(--em-ink);margin:.15rem 0}
html body .ezd-main .tl-meta{font-size:.72rem;color:var(--em-muted);display:flex;flex-wrap:wrap;gap:.15rem .75rem}
/* Drag-and-drop plików (bez zmian funkcjonalnych) */
.sp-dz-overlay{position:absolute;inset:0;background:rgba(15,111,168,.08);border:3px dashed #0f6fa8;display:none;align-items:center;justify-content:center;z-index:50;pointer-events:none}
.sp-dz-overlay.active{display:flex}
.sp-dz-msg{text-align:center;color:#0f6fa8;font-size:1.1rem;font-weight:300;pointer-events:none;padding:1.5rem}
.sp-dz-progress{position:fixed;bottom:1.5rem;right:1.5rem;background:#fff;border:1px solid #e1e1e1;border-left:6px solid #0f6fa8;padding:.8rem 1.1rem;box-shadow:0 12px 32px -12px rgba(16,24,40,.35);z-index:9999;min-width:260px;font-size:.82rem;display:none}
.sp-dz-progress.visible{display:block}
</style>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-2">
  <ol class="breadcrumb" style="font-size:.78rem">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">EZD</a></li>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/teczki/view.php?id=<?= $sprawa['teczka_id'] ?>"><?= h($sprawa['teczka_symbol']) ?></a></li>
    <li class="breadcrumb-item active"><?= h($sprawa['znak_sprawy']) ?></li>
  </ol>
</nav>

<?= flash_html() ?>

<?php if ($is_closed): ?>
<div class="sp-closed" role="status">
  <i class="bi bi-lock-fill flex-shrink-0"></i>
  <span>Koszulka <strong>zamknięta</strong> — dodawanie treści i edycja są zablokowane.
  <?php if(!empty($sprawa['close_reason'])): ?> Powód: <em><?= h($sprawa['close_reason']) ?></em>.<?php endif; ?>
  <?php if(is_admin()): ?><a href="<?= APP_URL ?>/ezd/sprawy/edit.php?id=<?= $id ?>" class="ms-2">Przywróć</a><?php endif; ?></span>
</div>
<?php endif; ?>

<!-- ══ Nagłówek koszulki — układ Dynamics 365 (assets/css/dynamics.css, includes/dynRecord.php; kolory EZD w ezd_metro.php):
     pasek poleceń, „Koszulka · znak”, tytuł, pola nagłówka, pasek etapów obiegu (klik = przejście do etapu) ══ -->
<?php
require_once dirname(__DIR__, 2) . '/includes/dynRecord.php';
$TONE_V  = ['secondary' => 'slate', 'info' => 'sky', 'primary' => 'blue', 'warning' => 'amber', 'success' => 'emerald', 'danger' => 'rose', 'dark' => 'slate'];
$TONE_C  = ['secondary' => 'var(--em-ink)', 'info' => 'var(--m-teal)', 'primary' => 'var(--m-blue)', 'warning' => 'var(--m-orange)', 'success' => 'var(--m-green)', 'danger' => 'var(--m-red)', 'dark' => 'var(--em-ink)'];
$st_meta = EZD_STATUSES_SPRAWA[$sprawa['status']] ?? ['label' => $sprawa['status'], 'class' => 'secondary'];
$pr_meta = EZD_PRIORITIES[$sprawa['priority']] ?? ['label' => $sprawa['priority'], 'class' => 'secondary'];
$et_meta = ezd_etap_meta($cur_etap);
$overdue = $sprawa['deadline'] && $sprawa['deadline'] < date('Y-m-d') && !$is_closed && empty($sprawa['ciagla']);
?>
<div class="dyn-cmdbar" role="toolbar" aria-label="Polecenia koszulki">
  <?php if($can_act): ?>
  <button type="button" class="btn btn-sm" data-bs-toggle="modal" data-bs-target="#dekrModal"><i class="bi bi-person-lines-fill" aria-hidden="true"></i>Przekaż</button>
  <button type="button" class="btn btn-sm" data-bs-toggle="modal" data-bs-target="#pismoModal"><i class="bi bi-envelope-plus" aria-hidden="true"></i>Nowe pismo</button>
  <?php if($signed_pdfs): ?>
  <button type="button" class="btn btn-sm" data-bs-toggle="modal" data-bs-target="#sprawaSendMailModal"><i class="bi bi-send" aria-hidden="true"></i>Wyślij mailem</button>
  <?php endif; ?>
  <?php endif; ?>
  <button type="button" class="btn btn-sm" data-bs-toggle="modal" data-bs-target="#notatkiModal"><i class="bi bi-sticky" aria-hidden="true"></i>Notatki<?php if($notatki): ?> <span class="badge rounded-pill bg-secondary"><?= count($notatki) ?></span><?php endif; ?></button>
  <span class="dyn-sep" aria-hidden="true"></span>
  <a href="<?= APP_URL ?>/ezd/sprawy/print.php?id=<?= $id ?>" class="btn btn-sm" onclick="return confirm('Czy na pewno chcesz wydrukować ten dokument?')"><i class="bi bi-printer" aria-hidden="true"></i>Drukuj</a>
  <?php if(!$mini): ?>
  <a href="<?= APP_URL ?>/ezd/sprawy/metryka.php?id=<?= $id ?>" class="btn btn-sm"><i class="bi bi-clipboard-check" aria-hidden="true"></i>Metryka</a>
  <?php endif; ?>
  <?php if($can_edit_case): ?>
  <a href="<?= APP_URL ?>/ezd/sprawy/edit.php?id=<?= $id ?>" class="btn btn-sm"><i class="bi bi-pencil" aria-hidden="true"></i>Edytuj</a>
  <?php endif; ?>
  <?php if($can_edit_case || $can_act): ?>
  <div class="dropdown">
    <button type="button" class="btn btn-sm" data-bs-toggle="dropdown" aria-expanded="false" aria-haspopup="menu"><i class="bi bi-three-dots" aria-hidden="true"></i>Więcej</button>
    <ul class="dropdown-menu dropdown-menu-end sp-more">
      <?php if($can_edit_case): ?>
      <li><a href="<?= APP_URL ?>/ezd/sprawy/przerejestruj.php?id=<?= $id ?>" class="dropdown-item"><i class="bi bi-stars" aria-hidden="true"></i>Przerejestruj (AI)</a></li>
      <?php endif; ?>
      <?php if($can_act): ?>
      <?php if($can_edit_case): ?><li><hr class="dropdown-divider"></li><?php endif; ?>
      <li><button type="button" class="dropdown-item sp-more-danger" data-bs-toggle="modal" data-bs-target="#zamknijModal"><i class="bi bi-lock" aria-hidden="true"></i>Zamknij koszulkę</button></li>
      <?php endif; ?>
    </ul>
  </div>
  <?php endif; ?>
</div>

<section class="sp-hero dyn-rec" aria-labelledby="sp-title">
  <div class="dyn-head">
    <div class="dyn-head__main">
      <div class="dyn-entity"><?= $sprawa['parent_id'] ? 'Podkoszulka' : 'Koszulka' ?> · <code><?= h($sprawa['znak_sprawy']) ?></code></div>
      <h1 id="sp-title" class="dyn-title"><?= h($sprawa['title']) ?></h1>
      <?php if (in_array($sprawa['priority'], ['high', 'urgent'], true) || $overdue || !empty($sprawa['ciagla'])): ?>
      <div class="sp-flags">
        <?php if (in_array($sprawa['priority'], ['high', 'urgent'], true)): ?><span class="ezx-badge ezx-badge--<?= $TONE_V[$pr_meta['class']] ?? 'slate' ?>"><i class="bi bi-flag-fill" aria-hidden="true"></i><?= h($pr_meta['label']) ?></span><?php endif; ?>
        <?php if ($overdue): ?><span class="ezx-badge ezx-badge--urgent"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>Pilne — po terminie</span><?php endif; ?>
        <?php if (!empty($sprawa['ciagla'])): ?><span class="ezx-badge ezx-badge--sky"><i class="bi bi-infinity" aria-hidden="true"></i>Ciągła</span><?php endif; ?>
      </div>
      <?php endif; ?>
      <?php if ($sprawa['description']): ?><p class="sp-desc"><?= nl2br(h($sprawa['description'])) ?></p><?php endif; ?>
    </div>
    <?php
    $_dyn_term = !empty($sprawa['ciagla']) ? ['html' => '<i class="bi bi-infinity" aria-hidden="true"></i> stale otwarta']
               : ($sprawa['deadline'] ? ['html' => '<span' . ($overdue ? ' class="overdue" style="color:var(--m-red)"' : '') . '>' . h(date('d.m.Y', strtotime($sprawa['deadline']))) . ($overdue ? ' <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i>' : '') . '</span>'] : '');
    echo dynHFields([
        ['Status', $st_meta['label'], $TONE_C[$st_meta['class']] ?? ''],
        ['Prowadzący', (string)($sprawa['owner_name'] ?? '')],
        ['Wykaz akt', ['html' => '<a href="' . APP_URL . '/ezd/teczki/view.php?id=' . (int)$sprawa['teczka_id'] . '">' . h($sprawa['teczka_symbol']) . '</a>']],
        ['Termin realizacji', $_dyn_term],
        ['Wszczęta', date('d.m.Y', strtotime($sprawa['created_at']))],
    ]);
    ?>
  </div>
  <?php if (!$mini && $wf_steps):
      // Etapy obiegu wg workflow Wykazu akt. Klik = set_etap (jak lista „Przejdź do etapu”); ostatni etap zamyka
      // koszulkę, więc w pasku jest tylko do odczytu — zamknięcie przez „Zamknij koszulkę” albo „Dalej” w karcie Obieg.
      $_wf_last = end($wf_keys);
      $_wf_post = ($can_act && !$is_closed) ? function (string $k) use ($_wf_last, $sprawa): ?string {
          if ($k === $_wf_last && empty($sprawa['ciagla'])) return null;
          return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="_action" value="set_etap"><input type="hidden" name="etap" value="' . h($k) . '">';
      } : null;
      echo dynStages(array_column($wf_steps, 'label', 'key'), (string)$cur_etap, array_filter([
          'label' => 'Etapy obiegu koszulki',
          'off'   => $cur_idx === -1 ? [$cur_etap => $cur_etap === 'akceptacja' ? 'W akceptacji' : $et_meta['label']] : [],
          'post'  => $_wf_post,
      ]));
  endif; ?>
</section>

<!-- ══ Układ 2-kolumnowy: lewa = akta + pisma/zadania/uczestnicy, prawa = obieg + metadane + historia ══ -->
<div class="tw-grid tw-grid-cols-1 xl:tw-grid-cols-12 tw-gap-4 tw-items-start">
<div class="xl:tw-col-span-8 tw-min-w-0">

<!-- ══ Dokumenty w koszulce ══════════════════════════════════════════════════════ -->
<div id="files">
  <div class="sp-akta-hdr">
    <div class="flex-grow-1">
      <span class="sp-akta-title"><i class="bi bi-folder2-open me-1"></i>Dokumenty w koszulce</span>
      <span class="sp-akta-count">(<?= count($zalaczniki) ?>)</span>
    </div>
    <?php if($can_act): ?>
    <div class="sp-cmdbar">
      <?php if($zalaczniki): ?>
      <button class="btn btn-sm sp-cmd" type="button" data-bs-toggle="modal" data-bs-target="#przekazDokModal"><i class="bi bi-send-check me-1"></i>Przekaż</button>
      <button id="qd-btn" class="btn btn-sm sp-cmd" type="button" data-bs-toggle="modal" data-bs-target="#quickDispatchModal" disabled>
        <i class="bi bi-mailbox2 me-1"></i>Zarejestruj w wychodzących<span id="qd-count" class="badge bg-success ms-1 d-none">0</span>
      </button>
      <?php endif; ?>
      <button class="btn btn-sm sp-cmd" type="button" data-bs-toggle="collapse" data-bs-target="#new-grupa"><i class="bi bi-folder-plus me-1"></i>Nowa grupa</button>
      <a href="<?= APP_URL ?>/ezd/sprawy/spinacz.php?sprawa_id=<?= $id ?>" class="btn btn-sm sp-cmd"><i class="bi bi-paperclip me-1"></i>Spinacz</a>
      <div class="dropdown">
        <button class="btn btn-sm btn-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
          <i class="bi bi-plus-lg me-1"></i>Dodaj
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li>
            <label class="dropdown-item" style="cursor:pointer">
              <i class="bi bi-upload me-2"></i>Prześlij pliki
              <input type="file" class="d-none" id="quick-upload-trigger" multiple
                     accept=".pdf,.doc,.docx,.xls,.xlsx,.odt,.ods,.png,.jpg,.jpeg,.zip,.txt,.csv,.eml,.msg">
            </label>
          </li>
          <li><a class="dropdown-item ezd-new-office-file" href="#" data-type="docx" data-bs-toggle="modal" data-bs-target="#newOfficeFileModal"><i class="bi bi-file-earmark-word text-primary me-2"></i>Nowy Word (.docx)</a></li>
          <li><a class="dropdown-item ezd-new-office-file" href="#" data-type="xlsx" data-bs-toggle="modal" data-bs-target="#newOfficeFileModal"><i class="bi bi-file-earmark-excel text-success me-2"></i>Nowy Excel (.xlsx)</a></li>
        </ul>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <?php if($can_act): ?>
  <div class="collapse border border-top-0 border-bottom-0 px-3 py-2 bg-light" id="new-grupa">
    <form method="post" class="d-flex gap-2">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="grupa_add">
      <input type="text" name="nazwa" class="form-control form-control-sm" placeholder="Nazwa grupy, np. Faktury / Korespondencja" required>
      <button class="btn btn-sm btn-primary flex-shrink-0"><i class="bi bi-check-lg me-1"></i>Utwórz</button>
    </form>
  </div>

  <!-- Ukryty formularz upload (trigger z dropdown) -->
  <form method="post" enctype="multipart/form-data" id="ezd-quick-upload-form" class="d-none">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="_action" value="upload">
    <input type="file" name="file[]" id="ezd-quick-upload-file" multiple>
  </form>
  <?php endif; ?>

  <div class="sp-akta-body">
    <?php
    $grupaOptsPlain = '<option value="0">— bez grupy —</option>';
    foreach ($grupy as $g) $grupaOptsPlain .= '<option value="'.$g['id'].'">'.h($g['nazwa']).'</option>';

    $renderZal = function(array $z) use ($can_act, $grupy) {
        $zext = strtolower(pathinfo($z['original_name'], PATHINFO_EXTENSION));
        $sig  = ['signed'=>false];
        if (in_array($zext, EZD_SIG_EXTS, true)) {
            $sig = ezd_signature_info(UPLOAD_DIR . EZD_UPLOAD_SUBDIR . (int)$z['sprawa_id'] . '/' . $z['filename'], $z['original_name']);
        }
        ?>
        <div class="sp-file-row">
          <input type="checkbox" class="form-check-input flex-shrink-0 qd-check" style="margin:0"
                 value="<?= (int)$z['id'] ?>" data-name="<?= h($z['original_name']) ?>" aria-label="Zaznacz do wysyłki">
          <i class="bi <?= ezd_file_icon($z['original_name']) ?> fs-5 flex-shrink-0 text-muted"></i>
          <div class="flex-grow-1 overflow-hidden">
            <a href="<?= APP_URL ?>/ezd/serve.php?id=<?= $z['id'] ?>"
               class="text-decoration-none fw-semibold text-truncate d-block<?= $zext === 'pdf' ? ' ezd-pdf-btn' : '' ?>"
               <?php if ($zext === 'pdf'): ?>data-url="<?= APP_URL ?>/ezd/serve.php?id=<?= $z['id'] ?>" data-name="<?= h($z['original_name']) ?>"<?php endif; ?>>
              <?= h($z['original_name']) ?>
              <?php if(!empty($sig['signed'])): ?><span class="badge bg-success bg-opacity-15 text-success border border-success ms-1" style="font-size:.6rem"><i class="bi bi-patch-check-fill me-1"></i>Podpis el.</span><?php endif; ?>
              <?php if($z['wersja']>1): ?><span class="badge bg-warning text-dark ms-1" style="font-size:.6rem">v<?= $z['wersja'] ?></span><?php endif; ?>
            </a>
            <div class="text-muted" style="font-size:.68rem"><?= ezd_filesize($z['file_size']) ?> · <?= h($z['uploader'] ?? '—') ?> <?= DocRegistry::badge(DocRegistry::get('ezd_zalacznik', (int)$z['id']) ?: DocRegistry::assign('ezd_zalacznik', (int)$z['id'])) ?></div>
          </div>
          <div class="text-nowrap text-muted" style="font-size:.72rem"><?= date('d.m.Y', strtotime($z['uploaded_at'])) ?></div>
          <?php ezd_zal_menu($z, [
            'can_act'   => $can_act,
            'sprawa_id' => (int)$z['sprawa_id'],
            'sig'       => $sig,
            'del_field' => 'zal_id',
            'grupy'     => (bool)$grupy,
            'allow'     => ['convert_pdf','move_grupa','pismo_from_zal','sign_req','rsign','email_preview','obiegi'],
          ]); ?>
        </div>
        <?php
    };
    ?>

    <?php if(!$zalaczniki && !$grupy): ?>
    <div class="text-center text-muted py-4" style="font-size:.8rem"><i class="bi bi-folder2-open fs-3 d-block mb-2 opacity-25"></i>Brak plików w repozytorium koszulki</div>
    <?php endif; ?>

    <?php foreach ($grupy as $g): ?>
    <div class="px-3 py-2 bg-light border-bottom d-flex align-items-center gap-2" style="font-size:.76rem">
      <i class="bi bi-folder-fill text-warning"></i>
      <span class="fw-bold"><?= h($g['nazwa']) ?></span>
      <span class="badge bg-secondary bg-opacity-15 text-secondary"><?= (int)$g['plik_count'] ?></span>
      <?php if($can_act): ?>
      <div class="ms-auto d-flex gap-1">
        <button class="btn btn-xs btn-link p-0 text-muted" type="button" data-bs-toggle="collapse" data-bs-target="#ge-<?= $g['id'] ?>"><i class="bi bi-pencil"></i></button>
        <form method="post" class="d-inline" onsubmit="return confirm('Usunąć grupę?')">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="grupa_del">
          <input type="hidden" name="grupa_id" value="<?= $g['id'] ?>">
          <button class="btn btn-xs btn-link p-0 text-muted"><i class="bi bi-x-circle"></i></button>
        </form>
      </div>
      <?php endif; ?>
    </div>
    <?php if($can_act): ?>
    <div class="collapse px-3 py-2 bg-light border-bottom" id="ge-<?= $g['id'] ?>">
      <form method="post" class="d-flex gap-2">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="grupa_rename">
        <input type="hidden" name="grupa_id" value="<?= $g['id'] ?>">
        <input type="text" name="nazwa" class="form-control form-control-sm" value="<?= h($g['nazwa']) ?>" required>
        <button class="btn btn-sm btn-primary flex-shrink-0"><i class="bi bi-check-lg me-1"></i>Zapisz</button>
      </form>
    </div>
    <?php endif; ?>
    <?php if($grupy_map[(int)$g['id']]): foreach($grupy_map[(int)$g['id']] as $z) $renderZal($z); else: ?>
    <div class="text-muted px-4 py-2" style="font-size:.74rem">Przenieś tu pliki z listy poniżej.</div>
    <?php endif; ?>
    <?php endforeach; ?>

    <?php if($grupy && $bez_grupy): ?>
    <div class="px-3 py-2 bg-light border-bottom" style="font-size:.76rem"><i class="bi bi-folder2 me-1 text-muted"></i><span class="fw-bold text-muted">Bez grupy</span></div>
    <?php endif; ?>
    <?php foreach ($bez_grupy as $z) $renderZal($z); ?>

    <?php if($can_act): ?>
    <form method="post" enctype="multipart/form-data" class="p-3 border-top" id="ezd-upload-form">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="upload">
      <div class="d-flex gap-2 align-items-center flex-wrap">
        <input type="file" id="ezd-upload-file" name="file[]" multiple class="form-control form-control-sm" style="max-width:260px"
               accept=".pdf,.doc,.docx,.xls,.xlsx,.odt,.ods,.pptx,.png,.jpg,.jpeg,.zip,.txt,.csv,.eml,.msg" required>
        <input type="text" name="custom_name" id="ezd-upload-custom-name" class="form-control form-control-sm" style="max-width:220px"
               placeholder="Własna nazwa (opcjonalnie)" maxlength="200">
        <?php if($grupy): ?>
        <select name="grupa_id" class="form-select form-select-sm" style="max-width:180px">
          <option value="0">— bez grupy —</option>
          <?php foreach($grupy as $g): ?><option value="<?= $g['id'] ?>"><?= h($g['nazwa']) ?></option><?php endforeach; ?>
        </select>
        <?php endif; ?>
        <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-upload me-1"></i>Dodaj do koszulki</button>
        <small class="text-muted">Maks. 25 MB / plik. Można wybrać wiele plików naraz.</small>
      </div>
      <div class="form-check mt-2 d-none" id="ezd-upload-convert-wrap">
        <input type="checkbox" class="form-check-input" name="convert_pdf" value="1" id="ezd-upload-convert">
        <label class="form-check-label" for="ezd-upload-convert" style="font-size:.78rem">Przekonwertować też na PDF?</label>
      </div>
    </form>
    <script>
    (function(){
      var inp = document.getElementById('ezd-upload-file'), wrap = document.getElementById('ezd-upload-convert-wrap'), chk = document.getElementById('ezd-upload-convert'), cname = document.getElementById('ezd-upload-custom-name');
      if (!inp || !wrap || !chk) return;
      inp.addEventListener('change', function(){
        var multi = inp.files && inp.files.length > 1;
        // Własna nazwa i konwersja na PDF mają sens tylko dla pojedynczego pliku
        if (cname) cname.classList.toggle('d-none', multi);
        if (multi) { wrap.classList.add('d-none'); chk.checked = false; return; }
        var e=inp.value.split('.').pop().toLowerCase(); var ok=['doc','docx','xls','xlsx'].includes(e); wrap.classList.toggle('d-none',!ok); if(!ok)chk.checked=false;
      });
      // Quick-upload trigger
      var qt = document.getElementById('quick-upload-trigger'), qf = document.getElementById('ezd-quick-upload-file'), qform = document.getElementById('ezd-quick-upload-form');
      if (qt && qf && qform) {
        qt.addEventListener('change', function(){ qf.files = qt.files; qform.submit(); });
      }
    })();
    </script>
    <?php endif; ?>
  </div>
</div>

<!-- ══ Sekcja podpisów ════════════════════════════════════════════════════════ -->
<?php if($sign_requests): ?>
<div id="sign-requests" class="mt-3">
  <div class="sp-akta-hdr">
    <div class="flex-grow-1">
      <span class="sp-akta-title"><i class="bi bi-pen me-1"></i>Przekazane do podpisu</span>
      <span class="sp-akta-count">(<?= count($sign_requests) ?>)</span>
    </div>
  </div>
  <div class="sp-akta-body">
    <table class="table table-sm mb-0" style="font-size:.8rem">
      <thead class="table-light"><tr><th>Dokument</th><th>Do</th><th>Status</th><th>Data</th><th></th></tr></thead>
      <tbody>
        <?php foreach($sign_requests as $sr): ?>
        <tr>
          <td><?= h($sr['zal_name']) ?></td>
          <td><?= h($sr['requested_to_name']) ?></td>
          <td><?= ezd_sign_status_badge($sr['status']) ?></td>
          <td class="text-muted"><?= h(substr($sr['requested_at'],0,10)) ?></td>
          <td><a href="<?= APP_URL ?>/ezd/podpis/view.php?id=<?= $sr['id'] ?>" class="btn btn-xs btn-outline-secondary btn-sm">Szczegóły</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Modal: Przekaż do podpisu -->
<div class="modal fade" id="signRequestModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-semibold" style="font-size:.95rem"><i class="bi bi-pen me-2 text-warning"></i>Przekaż do podpisu</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="request_sign">
        <input type="hidden" name="zal_id" id="signReqZalId">
        <div class="modal-body">
          <p class="text-muted mb-3" style="font-size:.82rem">Dokument: <strong id="signReqZalName"></strong></p>
          <div class="mb-3">
            <label class="form-label fw-semibold mb-1" style="font-size:.8rem">Osoba podpisująca <span class="text-danger">*</span></label>
            <select name="sign_user_id" class="form-select form-select-sm" required>
              <option value="">— wybierz —</option>
              <?php foreach($users as $u): ?>
              <?php if((int)$u['id'] !== $user_id): ?>
              <option value="<?= $u['id'] ?>"><?= h($u['name']) ?></option>
              <?php endif; ?>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label fw-semibold mb-1" style="font-size:.8rem">Uwagi dla podpisującego</label>
            <textarea name="sign_notes" class="form-control form-control-sm" rows="2" placeholder="np. kwalifikowany podpis elektroniczny, termin…"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-warning btn-sm"><i class="bi bi-pen me-1"></i>Wyślij prośbę o podpis</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
document.querySelectorAll('.ezd-sign-req-btn').forEach(function(btn){
  btn.addEventListener('click',function(){
    document.getElementById('signReqZalId').value = btn.dataset.zalId;
    document.getElementById('signReqZalName').textContent = btn.dataset.zalName;
    new bootstrap.Modal(document.getElementById('signRequestModal')).show();
  });
});
</script>

<!-- ══ Tabs ════════════════════════════════════════════════════════════════════ -->
<ul class="nav sp-tabs mt-3" id="sprawaTabs">
  <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-pisma"><i class="bi bi-envelope me-1"></i>PISMA <span class="badge bg-secondary ms-1"><?= count(array_filter($timeline,fn($t)=>$t['_typ']==='pismo')) ?></span></a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-zadania"><i class="bi bi-person-lines-fill me-1"></i>ZADANIA<?php $pend = count(array_filter($dekretacje,fn($d)=>$d['status']==='oczekuje')); if($pend): ?> <span class="badge bg-warning text-dark ms-1"><?= $pend ?></span><?php endif; ?></a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-uczestnicy"><i class="bi bi-people me-1"></i>UCZESTNICY<?php if($shares||$strony): ?> <span class="badge bg-info ms-1"><?= count($shares)+count($strony) ?></span><?php endif; ?></a></li>
  <?php if($podsprawy): ?><li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-podkoszulki"><i class="bi bi-diagram-3 me-1"></i>PODKOSZULKI <span class="badge bg-secondary ms-1"><?= count($podsprawy) ?></span></a></li><?php endif; ?>
</ul>

<div class="tab-content">

  <!-- ZADANIA: dekretacje -->
  <div class="tab-pane fade sp-tab-pane" id="tab-zadania">
    <?php if($can_act): ?>
    <div class="mb-3">
      <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#dekrModal">
        <i class="bi bi-person-lines-fill me-1"></i>Przekaż osobie
      </button>
    </div>
    <?php endif; ?>
    <?php if($dekretacje): ?>
    <?php foreach($dekretacje as $d): ?>
    <div class="dekr-row">
      <div class="flex-grow-1">
        <div class="d-flex align-items-center gap-1 flex-wrap">
          <span class="badge bg-<?= $d['status']==='oczekuje'?'warning text-dark':'success' ?>" style="font-size:.62rem"><?= h(EZD_DYSPOZYCJE[$d['dyspozycja']] ?? $d['dyspozycja']) ?></span>
          <?php if(!empty($d['rola_target'])): ?>
            <?php if($d['claimed_by']): ?>
              <span class="fw-semibold"><?= h($d['claimed_by_name']??'—') ?></span>
              <span class="badge bg-info text-dark" style="font-size:.58rem" title="Rola: <?= h($d['rola_target']) ?>"><i class="bi bi-people-fill me-1"></i><?= h($d['rola_target']) ?></span>
            <?php else: ?>
              <span class="fw-semibold text-muted fst-italic">Oczekuje na odbiór</span>
              <span class="badge bg-secondary" style="font-size:.58rem"><i class="bi bi-people me-1"></i><?= h($d['rola_target']) ?></span>
            <?php endif; ?>
          <?php else: ?>
            <span class="fw-semibold"><?= h($d['wykonawca_name']??'—') ?></span>
          <?php endif; ?>
          <?php if($d['status']==='oczekuje' && empty($d['read_at'])): ?><span class="badge bg-danger" style="font-size:.58rem" title="Nieodczytane"><i class="bi bi-eye-slash"></i></span><?php endif; ?>
          <?php if($d['status']==='oczekuje' && !empty($d['escalated_at'])): ?><span class="badge bg-warning text-dark" style="font-size:.58rem" title="Eskalacja wysłana"><i class="bi bi-exclamation-triangle"></i></span><?php endif; ?>
          <?php if($d['status']==='oczekuje' && empty($d['rola_target'])): ?><span class="badge bg-secondary bg-opacity-15 text-secondary" style="font-size:.58rem">Niepodjęte</span><?php endif; ?>
        </div>
        <?php if($d['tresc']): ?><div class="text-muted" style="font-size:.72rem"><?= h(mb_substr($d['tresc'],0,80)) ?></div><?php endif; ?>
        <div style="font-size:.7rem;color:#94a3b8">
          od: <?= h($d['zlecajacy_name']??'—') ?>
          <?php if($d['deadline']): ?> · <span class="<?= $d['deadline']<date('Y-m-d')&&$d['status']==='oczekuje'?'text-danger fw-bold':'' ?>"><?= date_pl($d['deadline']) ?></span><?php endif; ?>
          · <?= date('d.m.Y H:i', strtotime($d['created_at'])) ?>
          <?php if($d['read_at']): ?> · <span title="Odczytano <?= date('d.m.Y H:i', strtotime($d['read_at'])) ?>"><i class="bi bi-eye text-success"></i></span><?php endif; ?>
        </div>
      </div>
      <?php if($d['status']==='oczekuje'): ?>
      <div class="d-flex gap-1 flex-shrink-0">
        <?php if(!empty($d['rola_target']) && !$d['claimed_by']): ?>
        <?php $u_role = current_user()['role'] ?? ''; if($u_role === $d['rola_target'] || is_admin()): ?>
        <form method="post" class="d-inline">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="dekr_claim">
          <input type="hidden" name="dekr_id" value="<?= $d['id'] ?>">
          <button class="btn btn-sm btn-primary" title="Przejmij dekretację"><i class="bi bi-hand-index me-1"></i>Przejmij</button>
        </form>
        <?php endif; ?>
        <?php endif; ?>
        <?php if($can_act && (empty($d['rola_target']) || $d['claimed_by'] == $user_id || is_admin())): ?>
        <?php if(empty($d['read_at']) && ((int)$d['wykonawca_id'] === $user_id || (int)($d['claimed_by']??0) === $user_id)): ?>
        <form method="post" class="d-inline">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="dekr_read">
          <input type="hidden" name="dekr_id" value="<?= $d['id'] ?>">
          <button class="btn btn-sm btn-outline-secondary" title="Potwierdź odczytanie"><i class="bi bi-eye"></i></button>
        </form>
        <?php endif; ?>
        <form method="post" class="d-inline">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="dekr_done">
          <input type="hidden" name="dekr_id" value="<?= $d['id'] ?>">
          <button class="btn btn-sm btn-outline-success" title="Oznacz jako wykonane"><i class="bi bi-check-lg"></i></button>
        </form>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php else: ?>
    <div class="text-muted text-center py-3" style="font-size:.84rem">Brak przekazań do tej koszulki</div>
    <?php endif; ?>
  </div>

  <!-- PISMA: timeline -->
  <div class="tab-pane fade show active sp-tab-pane" id="tab-pisma">
    <?php if($can_create_pismo): ?>
    <div class="mb-3">
      <div class="sp-cmdbar sp-cmdbar--start">
        <!-- Quick-pick: kierunek × medium → pismoModal (.ezd-pm-pick, data-kierunek/data-medium) -->
        <?php
        $qk = ['przychodzace' => ['Przychodzące','bi-arrow-down'],
               'wychodzace'   => ['Wychodzące','bi-arrow-up']];
        $qm = ['papier'=>['bi-file-earmark-text','Papier'],
               'email' =>['bi-at','E-mail'],
               'epuap' =>['bi-mailbox2','eDoręczenia'],
               'faks'  =>['bi-printer','Faks']];
        ?>
        <div class="dropdown">
          <button type="button" class="btn btn-sm btn-primary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" aria-haspopup="menu">
            <i class="bi bi-envelope-plus me-1" aria-hidden="true"></i>Zarejestruj pismo
          </button>
          <div class="dropdown-menu sp-pm-menu">
            <?php foreach($qk as $kv=>[$klabel,$kicon]): ?>
            <div class="sp-pm-col" role="group" aria-label="Nowe pismo — <?= $klabel ?>">
              <div class="sp-pm-h"><i class="bi <?= $kicon ?>" aria-hidden="true"></i><?= $klabel ?></div>
              <?php foreach($qm as $mv=>[$micon,$mlabel]): ?>
              <button type="button" class="dropdown-item ezd-pm-pick"
                      data-kierunek="<?= $kv ?>" data-medium="<?= $mv ?>"
                      title="<?= $klabel ?> · <?= $mlabel ?>">
                <i class="bi <?= $micon ?>" aria-hidden="true"></i><?= $mlabel ?>
              </button>
              <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <!-- Notatka wewnętrzna -->
        <button type="button" class="btn btn-sm sp-cmd ezd-notatka-btn">
          <i class="bi bi-sticky me-1" aria-hidden="true"></i>Notatka
        </button>
        <!-- Generator pisma z szablonu -->
        <a href="<?= APP_URL ?>/ezd/pisma/generator.php?sprawa_id=<?= $id ?>" class="btn btn-sm sp-cmd">
          <i class="bi bi-file-earmark-word me-1" aria-hidden="true"></i>Generuj DOCX
        </a>
        <!-- Import korespondencji e-mail do akt (pisma z crm_communications) -->
        <a href="<?= APP_URL ?>/ezd/sprawy/import_mail.php?id=<?= $id ?>" class="btn btn-sm sp-cmd"
           title="Wybierz maile z dostępnych skrzynek i dołącz je do tej koszulki jako pisma">
          <i class="bi bi-envelope-arrow-down me-1" aria-hidden="true"></i>Importuj maile
        </a>
      </div>
      <!-- Notatka inline (ukryta) -->
      <form method="post" class="mt-2 d-none" id="notatkaInlineForm">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="pismo_add">
        <input type="hidden" name="kierunek" value="wewnetrzne">
        <input type="hidden" name="rodzaj_medium" value="inne">
        <input type="hidden" name="title" value="Notatka wewnętrzna">
        <div class="d-flex gap-2 align-items-start">
          <textarea name="tresc" class="form-control form-control-sm" rows="2" placeholder="Treść notatki…" style="font-size:.82rem" required></textarea>
          <div class="d-flex flex-column gap-1">
            <button type="submit" class="btn btn-sm btn-secondary"><i class="bi bi-check-lg"></i></button>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="notatkaInlineCancel"><i class="bi bi-x"></i></button>
          </div>
        </div>
      </form>
    </div>
    <?php endif; ?>
    <?php if($timeline): ?>
    <div class="tl-wrap">
      <?php foreach($timeline as $item):
        $is_pismo = $item['_typ'] === 'pismo';
        $kier = $is_pismo ? ($item['kierunek'] ?? 'wewnetrzne') : 'none';
        $dot_cls = $is_pismo ? 'pismo-'.(in_array($kier,['przychodzace','wychodzace','wewnetrzne']) ? str_replace(['przychodzace','wychodzace','wewnetrzne'],['in','out','int'],$kier) : 'int') : '';
        $card_cls = $is_pismo ? 'pismo-'.str_replace(['przychodzace','wychodzace','wewnetrzne'],['in','out','int'],$kier) : '';
        $href = $is_pismo
          ? APP_URL . '/ezd/pisma/view.php?id=' . $item['id']
          : APP_URL . '/contracts/view.php?id=' . $item['id'];
      ?>
      <div class="tl-item">
        <div class="tl-dot <?= $dot_cls ?>"></div>
        <?php if ($is_pismo): ?>
        <div class="tl-card <?= $card_cls ?> tl-pismo-btn" role="button" tabindex="0"
             data-pismo-id="<?= (int)$item['id'] ?>" data-pismo-href="<?= h($href) ?>"
             style="cursor:pointer"
             aria-label="Podgląd pisma: <?= h($item['title']) ?>">
          <?php if(!empty($item['sygnatura'])): ?><div class="tl-syg"><?= h($item['sygnatura']) ?></div><?php endif; ?>
          <div class="tl-title"><?= h(mb_substr($item['title'],0,70)) ?></div>
          <div class="tl-meta">
            <span><?= h((EZD_KIERUNKI[$kier] ?? ['label'=>$kier])['label']) ?></span>
            <?php if(!empty($item['nadawca'])): ?><span><?= h($item['nadawca']) ?></span><?php endif; ?>
            <?php if(!empty($item['status'])): ?><span class="badge bg-light text-dark border" style="font-size:.6rem"><?= h($item['status']) ?></span><?php endif; ?>
            <span class="ms-auto"><?= ($__d = $item['_date'] ?? $item['data_pisma'] ?? $item['created_at'] ?? '') ? date('d.m.Y', strtotime($__d)) : '—' ?></span>
          </div>
        </div>
        <?php else: ?>
        <a href="<?= $href ?>" class="tl-card <?= $card_cls ?>">
          <?php if(!empty($item['sygnatura'])): ?><div class="tl-syg"><?= h($item['sygnatura']) ?></div><?php endif; ?>
          <div class="tl-title"><?= h(mb_substr($item['title'],0,70)) ?></div>
          <div class="tl-meta">
            <span class="badge bg-warning text-dark" style="font-size:.62rem">Umowa</span>
            <?php if(!empty($item['nr_umowy'])): ?><span class="font-monospace"><?= h($item['nr_umowy']) ?></span><?php endif; ?>
            <span class="ms-auto"><?= ($__d = $item['_date'] ?? $item['data_zawarcia'] ?? $item['created_at'] ?? '') ? date('d.m.Y', strtotime($__d)) : '—' ?></span>
          </div>
        </a>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="text-muted text-center py-3" style="font-size:.84rem">Brak pism w tej koszulce</div>
    <?php endif; ?>
  </div>


  <!-- UCZESTNICY: współdzielenie -->
  <div class="tab-pane fade sp-tab-pane" id="tab-uczestnicy">

    <!-- Strony / uczestnicy zewnętrzni -->
    <div class="fw-semibold mb-2" style="font-size:.8rem"><i class="bi bi-person-vcard me-1 text-primary"></i>Strony / uczestnicy</div>
    <?php if($strony): ?>
    <ul class="list-group list-group-flush mb-3">
      <?php foreach($strony as $st): ?>
      <li class="list-group-item d-flex align-items-center gap-2 px-0" style="font-size:.84rem">
        <i class="bi bi-building text-muted"></i>
        <div class="flex-grow-1">
          <div class="fw-semibold"><?= h($st['name']) ?></div>
          <div class="text-muted" style="font-size:.72rem">
            <?php if($st['rola']): ?><span class="badge bg-light text-dark border me-1"><?= h($st['rola']) ?></span><?php endif; ?>
            <?php if($st['crm_id']): ?><a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= $st['crm_id'] ?>" class="text-decoration-none text-info" style="font-size:.7rem"><i class="bi bi-link-45deg"></i> CRM</a><?php endif; ?>
          </div>
        </div>
        <?php if($can_manage_share): ?>
        <form method="post" onsubmit="return confirm('Usunąć stronę?')">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="strona_del">
          <input type="hidden" name="strona_id" value="<?= (int)$st['id'] ?>">
          <button class="btn btn-sm btn-link text-danger p-0"><i class="bi bi-x-circle"></i></button>
        </form>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php else: ?>
    <div class="text-muted mb-3" style="font-size:.82rem">Brak stron / uczestników w tej koszulce.</div>
    <?php endif; ?>
    <?php if($can_manage_share): ?>
    <form method="post" class="d-flex flex-column gap-2 mb-4" style="max-width:400px">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="strona_add">
      <input type="hidden" name="strona_crm_id" id="stranaCrmId" value="">
      <div>
        <label class="form-label fw-semibold" style="font-size:.8rem">Nazwa strony <span class="text-danger">*</span></label>
        <input type="text" name="strona_name" id="stranaName" class="form-control form-control-sm" required
               placeholder="Wpisz lub zacznij szukać w CRM…" autocomplete="off">
        <div id="stranaCrmSuggestions" class="list-group mt-1" style="position:absolute;z-index:500;min-width:300px;max-height:180px;overflow-y:auto;display:none"></div>
      </div>
      <div><label class="form-label fw-semibold" style="font-size:.8rem">Rola</label>
        <select name="strona_rola" class="form-select form-select-sm">
          <option value="">— brak —</option>
          <option value="Wnioskodawca">Wnioskodawca</option>
          <option value="Strona">Strona</option>
          <option value="Pełnomocnik">Pełnomocnik</option>
          <option value="Świadek">Świadek</option>
          <option value="Uczestnik">Uczestnik</option>
        </select>
      </div>
      <button class="btn btn-sm btn-primary" style="align-self:flex-start"><i class="bi bi-person-plus me-1"></i>Dodaj stronę</button>
    </form>
    <?php endif; ?>

    <hr class="my-3">

    <!-- Dostęp wewnętrzny (współdzielenie) -->
    <div class="fw-semibold mb-2" style="font-size:.8rem"><i class="bi bi-person-lock me-1 text-secondary"></i>Dostęp wewnętrzny</div>
    <?php if($shares): ?>
    <ul class="list-group list-group-flush mb-3">
      <?php foreach($shares as $sh): ?>
      <li class="list-group-item d-flex align-items-center gap-2 px-0" style="font-size:.84rem">
        <i class="bi bi-person-circle text-muted"></i>
        <div class="flex-grow-1">
          <div class="fw-semibold"><?= h($sh['user_name']) ?></div>
          <div class="text-muted" style="font-size:.72rem"><?= h(EZD_SPRAWA_UPRAWNIENIA[$sh['uprawnienie']] ?? $sh['uprawnienie']) ?></div>
        </div>
        <?php if($can_manage_share): ?>
        <form method="post" onsubmit="return confirm('Odebrać dostęp?')">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="share_del">
          <input type="hidden" name="share_user_id" value="<?= (int)$sh['user_id'] ?>">
          <button class="btn btn-sm btn-link text-danger p-0"><i class="bi bi-x-circle"></i></button>
        </form>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php else: ?>
    <div class="text-muted mb-3" style="font-size:.82rem">Koszulka nie jest współdzielona z dodatkowymi osobami.</div>
    <?php endif; ?>
    <?php if($can_manage_share): ?>
    <form method="post" class="d-flex flex-column gap-2" style="max-width:360px">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="share_add">
      <div class="fw-semibold" style="font-size:.82rem">Udostępnij nowej osobie</div>
      <select name="share_user_id" class="form-select form-select-sm" required>
        <option value="">— wybierz osobę —</option>
        <?php foreach($users as $u): ?><option value="<?= $u['id'] ?>"><?= h($u['name']) ?></option><?php endforeach; ?>
      </select>
      <select name="share_uprawnienie" class="form-select form-select-sm">
        <?php foreach(EZD_SPRAWA_UPRAWNIENIA as $uv=>$ul): ?><option value="<?= $uv ?>"><?= h($ul) ?></option><?php endforeach; ?>
      </select>
      <button class="btn btn-sm btn-primary"><i class="bi bi-person-plus me-1"></i>Udostępnij</button>
    </form>
    <?php endif; ?>

    <hr class="my-3">

    <!-- Udostępnianie na zewnątrz (link + kod SMS na telefon) -->
    <div class="fw-semibold mb-2" style="font-size:.8rem"><i class="bi bi-send-plus me-1 text-primary"></i>Udostępnianie na zewnątrz</div>
    <?php if($ext_shares): ?>
    <div class="table-responsive mb-3">
      <table class="table table-sm mb-0" style="font-size:.78rem">
        <thead class="table-light"><tr><th>E-mail</th><th>Telefon</th><th>Zakres</th><th>Tryb</th><th>Ważne do</th><th>Status</th><th>Wyśw.</th><th></th></tr></thead>
        <tbody>
        <?php foreach($ext_shares as $es):
            $es_status = ezd_ext_share_status($es);
            $es_badge  = [
                'active'  => '<span class="badge bg-success bg-opacity-15 text-success border border-success">Aktywne</span>',
                'expired' => '<span class="badge bg-secondary bg-opacity-15 text-secondary border border-secondary">Wygasłe</span>',
                'revoked' => '<span class="badge bg-danger bg-opacity-15 text-danger border border-danger">Odwołane</span>',
            ][$es_status];
            $es_mode_badge = ($es['access_mode'] ?? 'download') === 'view'
                ? '<span class="badge bg-info bg-opacity-15 text-info border border-info">Podgląd</span>'
                : '<span class="badge bg-primary bg-opacity-15 text-primary border border-primary">Pobieranie</span>';
        ?>
        <tr>
          <td><?= h($es['recipient_email']) ?></td>
          <td class="text-muted text-nowrap"><?= ($es['otp_mode'] ?? 'sms') === 'manual' ? 'kod ręczny' : ('••• ••• ' . h(substr($es['recipient_phone'], -3))) ?></td>
          <td style="max-width:180px" class="text-truncate" title="<?= h(ezd_ext_share_scope_label($es)) ?>"><?= h(ezd_ext_share_scope_label($es)) ?></td>
          <td><?= $es_mode_badge ?></td>
          <td class="text-nowrap"><?= date('d.m.Y H:i', strtotime($es['expires_at'])) ?></td>
          <td><?= $es_badge ?></td>
          <td><?= (int)$es['view_count'] ?></td>
          <td class="text-nowrap">
            <button class="btn btn-xs btn-link p-0 me-2" type="button" data-bs-toggle="collapse" data-bs-target="#ext-log-<?= (int)$es['id'] ?>" title="Historia dostępu"><i class="bi bi-clock-history"></i></button>
            <?php if($can_manage_share && $es_status === 'active'): ?>
            <form method="post" class="d-inline">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="ext_share_resend">
              <input type="hidden" name="ext_share_id" value="<?= (int)$es['id'] ?>">
              <button class="btn btn-xs btn-link p-0 me-2" title="Wyślij ponownie e-mail z linkiem"><i class="bi bi-envelope-arrow-up"></i></button>
            </form>
            <form method="post" class="d-inline" onsubmit="return confirm('Odwołać to udostępnienie? Link i kod SMS przestaną działać.')">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="ext_share_revoke">
              <input type="hidden" name="ext_share_id" value="<?= (int)$es['id'] ?>">
              <button class="btn btn-xs btn-link p-0 text-danger" title="Odwołaj"><i class="bi bi-x-circle"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <tr>
          <td colspan="8" class="p-0 border-0">
            <!-- .collapse na <div>, nie na <tr> — animacja wysokości Bootstrapa nie działa poprawnie na wierszach tabeli -->
            <div class="collapse" id="ext-log-<?= (int)$es['id'] ?>">
              <div class="bg-light p-2 border-bottom">
                <?php $es_log = ezd_ext_share_log_list((int)$es['id'], 30); ?>
                <?php if(!$es_log): ?>
                <div class="text-muted py-1" style="font-size:.74rem">Brak zdarzeń.</div>
                <?php else: ?>
                <table class="table table-sm mb-0" style="font-size:.72rem">
                  <thead><tr><th>Czas</th><th>Zdarzenie</th><th>Szczegóły</th><th>IP</th></tr></thead>
                  <tbody>
                  <?php foreach($es_log as $le): ?>
                  <tr>
                    <td class="text-nowrap"><?= date('d.m.Y H:i:s', strtotime($le['created_at'])) ?></td>
                    <td><?= h(EZD_EXT_SHARE_LOG_LABELS[$le['event']] ?? $le['event']) ?></td>
                    <td><?= h($le['detail']) ?></td>
                    <td class="text-muted"><?= h($le['ip']) ?></td>
                  </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
                <?php endif; ?>
              </div>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div class="text-muted mb-3" style="font-size:.82rem">Koszulka nie jest jeszcze udostępniona na zewnątrz.</div>
    <?php endif; ?>

    <?php if($can_manage_share): ?>
      <?php if($zalaczniki):
        $ext_default_msg = '<p>Udostępniamy Państwu dokumenty dotyczące sprawy <strong>' . h($sprawa['znak_sprawy']) . '</strong>.</p>'
                          . '<p>Aby uzyskać dostęp, prosimy kliknąć w link w tej wiadomości i potwierdzić dostęp kodem SMS wysłanym na podany numer telefonu.</p>';
      ?>
    <form method="post" class="d-flex flex-column gap-2" style="max-width:480px">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="ext_share_add">
      <div class="fw-semibold" style="font-size:.82rem">Udostępnij dokumenty nowemu odbiorcy</div>

      <div>
        <label class="form-label fw-semibold" style="font-size:.78rem">Grupy / pliki do udostępnienia <span class="text-danger">*</span></label>
        <div class="border rounded p-2" style="max-height:220px;overflow-y:auto">
          <?php if($grupy): foreach($grupy as $g): ?>
          <div class="form-check mb-1">
            <input class="form-check-input" type="checkbox" name="ext_grupa_ids[]" value="<?= (int)$g['id'] ?>" id="ext-grp-<?= (int)$g['id'] ?>">
            <label class="form-check-label fw-semibold" for="ext-grp-<?= (int)$g['id'] ?>" style="font-size:.8rem">
              <i class="bi bi-folder-fill text-warning me-1"></i>Cała grupa: <?= h($g['nazwa']) ?>
              <span class="badge bg-secondary bg-opacity-15 text-secondary"><?= (int)$g['plik_count'] ?></span>
            </label>
            <div class="text-muted" style="font-size:.7rem;margin-left:1.4rem">Udostępnienie „na żywo" — obejmie też pliki dodane do tej grupy później.</div>
          </div>
          <?php endforeach; endif; ?>
          <?php if($grupy && $bez_grupy): ?>
          <div class="text-muted fw-semibold mt-2 mb-1" style="font-size:.72rem">Pliki bez grupy — wybierz pojedynczo:</div>
          <?php endif; ?>
          <?php foreach($bez_grupy as $z): ?>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="ext_zal_ids[]" value="<?= (int)$z['id'] ?>" id="ext-zal-<?= (int)$z['id'] ?>" <?= $grupy ? '' : 'checked' ?>>
            <label class="form-check-label" for="ext-zal-<?= (int)$z['id'] ?>" style="font-size:.8rem"><?= h($z['original_name']) ?></label>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div>
        <label class="form-label fw-semibold" style="font-size:.78rem">Tryb dostępu</label>
        <select name="ext_access_mode" class="form-select form-select-sm">
          <?php foreach(EZD_EXT_SHARE_ACCESS_MODES as $mv=>$ml): ?>
          <option value="<?= h($mv) ?>" <?= $mv==='download'?'selected':'' ?>><?= h($ml) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-text" style="font-size:.7rem">„Tylko podgląd" nie pozwala pobrać pliku ani całego ZIP-a — pliki (PDF/obrazy) można jedynie obejrzeć w przeglądarce.</div>
      </div>

      <div>
        <label class="form-label fw-semibold" style="font-size:.78rem">E-mail odbiorcy <span class="text-danger">*</span></label>
        <input type="email" name="ext_email" class="form-control form-control-sm" required>
      </div>

      <div>
        <label class="form-label fw-semibold" style="font-size:.78rem">Sposób weryfikacji dostępu</label>
        <select name="ext_otp_mode" id="extOtpMode" class="form-select form-select-sm">
          <?php foreach(EZD_EXT_SHARE_OTP_MODES as $ov=>$ol): ?>
          <option value="<?= h($ov) ?>" <?= $ov==='sms'?'selected':'' ?>><?= h($ol) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div id="extOtpPhoneWrap">
        <label class="form-label fw-semibold" style="font-size:.78rem">Telefon odbiorcy — na ten numer wyślemy kod SMS <span class="text-danger">*</span></label>
        <input type="tel" name="ext_phone" id="extOtpPhone" class="form-control form-control-sm" placeholder="np. 600 100 200" required>
      </div>

      <div id="extOtpManualWrap" class="d-none">
        <label class="form-label fw-semibold" style="font-size:.78rem">Kod dostępu (6 cyfr) — przekażesz go sam/a odbiorcy <span class="text-danger">*</span></label>
        <input type="text" name="ext_manual_code" id="extOtpManualCode" class="form-control form-control-sm" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="np. 482913">
        <div class="form-text" style="font-size:.7rem">Kod nie jest wysyłany przez system — przekaż go odbiorcy telefonicznie lub osobiście.</div>
      </div>

      <div>
        <label class="form-label fw-semibold" style="font-size:.78rem">Ważność linku</label>
        <select name="ext_valid_hours" class="form-select form-select-sm">
          <?php foreach(EZD_EXT_SHARE_VALID_HOURS as $hv=>$hl): ?>
          <option value="<?= $hv ?>" <?= $hv===48?'selected':'' ?>><?= h($hl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label class="form-label fw-semibold" style="font-size:.78rem">Treść wiadomości e-mail</label>
        <textarea name="ext_message" id="extShareMsgEditor" class="form-control form-control-sm" rows="6"><?= $ext_default_msg ?></textarea>
        <div id="extShareMsgFallback" class="form-text" hidden>Edytor treści nie wczytał się (brak dostępu do CDN) — formularz działa dalej ze zwykłym polem tekstowym.</div>
      </div>

      <button class="btn btn-sm btn-primary" style="align-self:flex-start"><i class="bi bi-send-plus me-1"></i>Udostępnij i wyślij e-mail</button>
      <small class="text-muted">Odbiorca będzie musiał potwierdzić dostęp jednorazowym kodem.</small>
    </form>
    <script>
    (function(){
      var sel = document.getElementById('extOtpMode');
      var phoneWrap = document.getElementById('extOtpPhoneWrap'), phoneInp = document.getElementById('extOtpPhone');
      var manualWrap = document.getElementById('extOtpManualWrap'), manualInp = document.getElementById('extOtpManualCode');
      if (!sel) return;
      function sync(){
        var manual = sel.value === 'manual';
        phoneWrap.classList.toggle('d-none', manual);
        manualWrap.classList.toggle('d-none', !manual);
        phoneInp.required = !manual;
        manualInp.required = manual;
      }
      sel.addEventListener('change', sync);
      sync();
    })();
    </script>
    <script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js" referrerpolicy="origin"></script>
    <script>
    if (!window.tinymce) {
      document.getElementById('extShareMsgFallback')?.removeAttribute('hidden');
    } else {
      tinymce.init({
        selector: '#extShareMsgEditor',
        license_key: 'gpl',
        promotion: false,
        branding: false,
        menubar: false,
        toolbar: 'undo redo | bold italic underline | bullist numlist | link | removeformat',
        plugins: 'lists link',
        height: 220,
        entity_encoding: 'raw',
        setup: function(editor){ editor.on('change', () => editor.save()); }
      });
    }
    </script>
      <?php else: ?>
    <div class="text-muted" style="font-size:.82rem">Dodaj najpierw pliki do repozytorium koszulki, aby móc je udostępnić na zewnątrz.</div>
      <?php endif; ?>
    <?php endif; ?>
  </div>


  <!-- PODKOSZULKI -->
  <?php if($podsprawy): ?>
  <div class="tab-pane fade sp-tab-pane" id="tab-podkoszulki">
    <?php if($can_act && !$sprawa['parent_id']): ?>
    <div class="mb-3"><a href="<?= APP_URL ?>/ezd/sprawy/add.php?parent_id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-diagram-3 me-1"></i>Dodaj podkoszulkę</a></div>
    <?php endif; ?>
    <?php foreach($podsprawy as $ps): ?>
    <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $ps['id'] ?>" class="d-flex align-items-center gap-2 py-2 border-bottom text-decoration-none text-reset" style="font-size:.84rem">
      <i class="bi bi-folder2 text-info flex-shrink-0"></i>
      <code class="flex-shrink-0" style="font-size:.72rem;color:#1d4ed8"><?= h($ps['znak_sprawy']) ?></code>
      <span class="flex-grow-1 text-truncate fw-semibold"><?= h($ps['title']) ?></span>
      <?= ezd_priority_badge($ps['priority']) ?>
      <?= ezd_status_badge_sprawa($ps['status']) ?>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div><!-- /tab-content -->

</div><!-- /lewa kolumna -->

<!-- ══ Prawa kolumna ═══════════════════════════════════════════════════════════ -->
<aside class="xl:tw-col-span-4 tw-min-w-0 tw-grid tw-grid-cols-1 tw-gap-4" aria-label="Obieg, metadane i historia koszulki">
  <?php /* Zadania z modułu Zadania powiązane z koszulką (inne niż dekretacje z zakładki ZADANIA) */
        require_once dirname(dirname(__DIR__)) . '/tasks/includes/linked_tasks_box.php';
        echo tasksLinksBox('ezd_sprawa', (string)$id, (int)$user_id, 'bs', '', 'Zadania (moduł Zadania)'); ?>

  <?php if(!$mini): ?>
  <!-- Obieg sprawy -->
  <section class="sp-side" id="tab-przebieg" aria-labelledby="sp-wf-h">
    <div class="sp-side-hd">
      <i class="bi bi-diagram-2 tw-text-blue-600" aria-hidden="true"></i>
      <h2 id="sp-wf-h" class="sp-side-h">Obieg sprawy</h2>
      <?php if($wf_custom): ?><span class="ezx-badge ezx-badge--sky" title="Workflow przypisany do Wykazu akt">wg <?= h($sprawa['teczka_symbol']) ?></span><?php endif; ?>
      <?php if(is_admin()): ?><a href="<?= APP_URL ?>/admin/ezd_workflows.php?jrwa_id=<?= (int)($sprawa['jrwa_id'] ?? 0) ?>" class="tw-ml-auto tw-text-[.7rem] tw-text-slate-500 hover:tw-text-blue-700" title="Edytuj workflow"><i class="bi bi-pencil" aria-hidden="true"></i></a><?php endif; ?>
    </div>
    <div class="sp-side-bd">
      <?php if($wf_steps): ?>
      <?php /* Etapy obiegu są w pasku pod nagłówkiem koszulki (układ Dynamics) — tu bieżący etap i przejścia */ ?>
      <p class="tw-m-0 tw-text-sm"><?php if ($cur_idx >= 0): ?>Etap <strong><?= $cur_idx + 1 ?> z <?= count($wf_steps) ?></strong>: <?= h($wf_steps[$cur_idx]['label']) ?><?php else: ?>Obecnie: <strong><?= $cur_etap === 'akceptacja' ? 'W akceptacji' : h($et_meta['label']) ?></strong><?php endif; ?></p>
      <?php if($can_act): ?>
      <div class="tw-grid tw-grid-cols-1 tw-gap-2 tw-mt-3 tw-pt-3 tw-border-t tw-border-solid tw-border-slate-100">
        <?php if($next_step): ?>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="set_etap">
          <input type="hidden" name="etap" value="<?= h($next_etap) ?>">
          <button class="btn btn-sm btn-primary tw-w-full tw-rounded-lg tw-font-semibold"><i class="bi <?= h($next_step['icon'] ?: 'bi-arrow-right') ?> me-1"></i>Dalej: <?= h($next_step['label']) ?> →</button>
        </form>
        <?php endif; ?>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="set_etap">
          <label class="visually-hidden" for="sp-etap-sel">Przejdź do etapu</label>
          <select id="sp-etap-sel" name="etap" class="form-select form-select-sm tw-rounded-lg" onchange="this.form.submit()">
            <option disabled selected>Przejdź do etapu…</option>
            <?php foreach($wf_steps as $st): ?>
            <option value="<?= h($st['key']) ?>" <?= $st['key']===$cur_etap?'disabled':'' ?>><?= h($st['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
        <button type="button" class="btn btn-sm btn-outline-danger tw-rounded-lg tw-font-semibold" data-bs-toggle="modal" data-bs-target="#zamknijModal"><i class="bi bi-lock me-1"></i>Zamknij koszulkę</button>
      </div>
      <?php endif; ?>
      <?php else: ?>
      <div class="ezx-muted">Brak zdefiniowanego obiegu.</div>
      <?php endif; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- Metadane -->
  <section class="sp-side" id="tab-meta" aria-labelledby="sp-meta-h">
    <div class="sp-side-hd">
      <i class="bi bi-info-circle tw-text-blue-600" aria-hidden="true"></i>
      <h2 id="sp-meta-h" class="sp-side-h">Metadane</h2>
      <?php if($can_edit_case): ?><a href="<?= APP_URL ?>/ezd/sprawy/edit.php?id=<?= $id ?>" class="tw-ml-auto tw-text-[.7rem] tw-text-slate-500 hover:tw-text-blue-700" title="Edytuj metadane" aria-label="Edytuj metadane"><i class="bi bi-pencil" aria-hidden="true"></i></a><?php endif; ?>
    </div>
    <div class="sp-side-bd">
      <dl class="sp-kv">
        <dt>Znak koszulki</dt><dd class="tw-font-mono tw-font-bold"><?= h($sprawa['znak_sprawy']) ?></dd>
        <dt>Wykaz akt</dt><dd><a href="<?= APP_URL ?>/ezd/teczki/view.php?id=<?= $sprawa['teczka_id'] ?>" class="tw-no-underline tw-font-semibold tw-text-blue-700"><?= h($sprawa['teczka_symbol']) ?></a></dd>
        <dt>Właściciel</dt><dd><?= $sprawa['owner_name'] ? h($sprawa['owner_name']) : '<span class="tw-text-slate-400">—</span>' ?></dd>
        <dt>Status</dt><dd><?= ezd_status_badge_sprawa($sprawa['status']) ?></dd>
        <dt>Priorytet</dt><dd><?= ezd_priority_badge($sprawa['priority']) ?></dd>
        <?php if(!$mini): ?><dt>Etap obiegu</dt><dd><?= ezd_etap_badge($sprawa['etap'] ?? 'wszczeta') ?></dd><?php endif; ?>
        <dt>Termin</dt>
        <dd class="<?= !empty($sprawa['ciagla'])?'tw-text-sky-600':($sprawa['deadline']&&$sprawa['deadline']<date('Y-m-d')&&!$is_closed?'tw-text-rose-600 tw-font-bold':'') ?>">
          <?= !empty($sprawa['ciagla']) ? '<i class="bi bi-infinity me-1"></i>stale otwarta' : ($sprawa['deadline'] ? date_pl($sprawa['deadline']) : '—') ?>
        </dd>
        <dt>Otwarto</dt><dd><?= date_pl($sprawa['created_at']) ?></dd>
        <?php if($sprawa['closed_at']): ?><dt>Zamknięto</dt><dd><?= date_pl($sprawa['closed_at']) ?></dd><?php endif; ?>
        <?php if(!empty($sprawa['close_reason'])): ?><dt>Powód zamknięcia</dt><dd class="tw-text-slate-500 tw-whitespace-pre-line tw-text-[.78rem]"><?= h($sprawa['close_reason']) ?></dd><?php endif; ?>
        <dt>Zawartość</dt><dd><?= count($zalaczniki) ?> plik<?= count($zalaczniki) === 1 ? '' : 'ów' ?> · <?= count(array_filter($timeline,fn($t)=>$t['_typ']==='pismo')) ?> pism</dd>
        <?php if($sprawa['description']): ?><dt>Opis</dt><dd class="tw-text-slate-500 tw-whitespace-pre-line tw-text-[.78rem]"><?= h($sprawa['description']) ?></dd><?php endif; ?>
      </dl>
      <?php if($can_edit_case): ?>
      <div class="tw-flex tw-flex-wrap tw-gap-1.5 tw-mt-3 tw-pt-3 tw-border-t tw-border-solid tw-border-slate-100">
        <a href="<?= APP_URL ?>/ezd/sprawy/edit.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary tw-rounded-lg"><i class="bi bi-pencil me-1"></i>Edytuj</a>
        <?php if(!$sprawa['parent_id']): ?>
        <a href="<?= APP_URL ?>/ezd/sprawy/add.php?parent_id=<?= $id ?>" class="btn btn-sm btn-outline-secondary tw-rounded-lg"><i class="bi bi-diagram-3 me-1"></i>Podkoszulka</a>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/ezd/sprawy/przerejestruj.php?id=<?= $id ?>" class="btn btn-sm btn-outline-primary tw-rounded-lg"><i class="bi bi-stars me-1"></i>Przerejestruj (AI)</a>
      </div>
      <?php endif; ?>
    </div>
  </section>

  <?php if(!$mini): ?>
  <!-- Historia operacji -->
  <section class="sp-side" id="historia" aria-labelledby="sp-log-h">
    <div class="sp-side-hd">
      <i class="bi bi-shield-check tw-text-blue-600" aria-hidden="true"></i>
      <h2 id="sp-log-h" class="sp-side-h">Historia operacji</h2>
      <span class="ezx-muted tw-ml-auto"><?= count($log_entries) ?></span>
    </div>
    <div class="sp-side-bd sp-log">
      <?php foreach ($log_entries as $l): ?>
      <div class="sp-log-row">
        <span class="sp-log-dot" aria-hidden="true"></span>
        <div class="tw-min-w-0">
          <div class="tw-text-slate-800 tw-break-words [overflow-wrap:anywhere]"><?= h(mb_substr($l['details'],0,120)) ?></div>
          <div class="ezx-muted"><?= h($l['user_name'] ?? '—') ?> · <?= date('d.m.Y H:i', strtotime($l['created_at'])) ?> · <code class="tw-text-[.62rem] tw-text-slate-500"><?= h($l['action']) ?></code></div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if(!$log_entries): ?><div class="ezx-muted tw-text-center tw-py-2">Brak wpisów</div><?php endif; ?>
    </div>
  </section>
  <?php endif; ?>

</aside>
</div><!-- /układ 2-kolumnowy -->

<script>
(function(){
  // ── Quick-pick kierunek × medium → pismoModal ─────────────────────────────
  document.querySelectorAll('.ezd-pm-pick').forEach(function(btn){
    btn.addEventListener('click', function(){
      var kier   = this.dataset.kierunek;
      var medium = this.dataset.medium;
      // Zaznacz kierunek
      var radio = document.querySelector('[name="kierunek"][value="'+kier+'"]');
      if (radio) radio.checked = true;
      // Ustaw medium
      var sel = document.getElementById('pm-medium');
      if (sel) sel.value = medium;
      // Pokaż ostrzeżenie ePUAP jeśli wychodzące
      epuapWarn(kier, medium);
      // Otwórz modal
      var m = document.getElementById('pismoModal');
      if (m) bootstrap.Modal.getOrCreateInstance(m).show();
      // Focus na title
      setTimeout(function(){ var t=document.getElementById('pm-title'); if(t) t.focus(); }, 300);
    });
  });

  // ── Ostrzeżenie ePUAP ────────────────────────────────────────────────────
  function epuapWarn(kier, medium) {
    var warn = document.getElementById('pm-epuap-warn');
    if (!warn) return;
    var show = (medium === 'epuap') && (kier === 'wychodzace');
    warn.style.display = show ? '' : 'none';
  }
  var pmMed = document.getElementById('pm-medium');
  if (pmMed) {
    pmMed.addEventListener('change', function(){
      var kier = (document.querySelector('[name="kierunek"]:checked')||{}).value||'';
      epuapWarn(kier, this.value);
    });
    document.querySelectorAll('[name="kierunek"]').forEach(function(r){
      r.addEventListener('change', function(){
        epuapWarn(this.value, pmMed.value);
      });
    });
  }

  // ── Notatka inline toggle ────────────────────────────────────────────────
  var notatkaBtn  = document.querySelector('.ezd-notatka-btn');
  var notatkaForm = document.getElementById('notatkaInlineForm');
  var notatkaCancel = document.getElementById('notatkaInlineCancel');
  if (notatkaBtn && notatkaForm) {
    notatkaBtn.addEventListener('click', function(){
      notatkaForm.classList.toggle('d-none');
      if (!notatkaForm.classList.contains('d-none')) {
        var ta = notatkaForm.querySelector('textarea');
        if (ta) ta.focus();
      }
    });
    if (notatkaCancel) notatkaCancel.addEventListener('click', function(){ notatkaForm.classList.add('d-none'); });
  }

  // ── CRM autocomplete dla strony ──────────────────────────────────────────
  var stranaInput = document.getElementById('stranaName');
  var stranaSugg  = document.getElementById('stranaCrmSuggestions');
  var stranaCrmId = document.getElementById('stranaCrmId');
  if (stranaInput && stranaSugg && stranaCrmId) {
    var crmTimer = null;
    stranaInput.addEventListener('input', function(){
      stranaCrmId.value = '';
      clearTimeout(crmTimer);
      var q = this.value.trim();
      if (q.length < 2) { stranaSugg.style.display='none'; stranaSugg.innerHTML=''; return; }
      crmTimer = setTimeout(function(){
        fetch('<?= APP_URL ?>/crm/api/contacts_search.php?q='+encodeURIComponent(q)+'&limit=8', {credentials:'same-origin'})
          .then(function(r){ return r.json(); })
          .then(function(data){
            if (!data.length) { stranaSugg.style.display='none'; return; }
            stranaSugg.innerHTML = data.map(function(c){
              return '<button type="button" class="list-group-item list-group-item-action py-1 px-2 text-start" style="font-size:.8rem" data-id="'+c.id+'" data-name="'+h(c.name)+'">'+
                '<strong>'+h(c.name)+'</strong>'+(c.organizacja?'<span class="text-muted ms-1">'+h(c.organizacja)+'</span>':'')+
              '</button>';
            }).join('');
            stranaSugg.style.display = '';
            stranaSugg.querySelectorAll('button').forEach(function(b){
              b.addEventListener('click', function(){
                stranaInput.value  = this.dataset.name;
                stranaCrmId.value  = this.dataset.id;
                stranaSugg.style.display = 'none';
              });
            });
          }).catch(function(){});
      }, 280);
    });
    document.addEventListener('click', function(e){ if (!stranaInput.contains(e.target)) stranaSugg.style.display='none'; });
    function h(s){ var d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }
  }
})();
</script>

<?php if($can_act): ?>
<!-- Modal: Zamknij koszulkę -->
<div class="modal fade" id="zamknijModal" tabindex="-1" aria-labelledby="zamknijModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title h6 mb-0" id="zamknijModalLabel"><i class="bi bi-lock-fill text-danger me-2"></i>Zamknij koszulkę</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="close_sprawa">
        <div class="modal-body">
          <p class="text-muted mb-3" style="font-size:.84rem">Po zamknięciu dodawanie treści i edycja koszulki zostaną zablokowane. Powód będzie widoczny w nagłówku i metadanych.</p>
          <label class="form-label fw-semibold mb-1" style="font-size:.8rem">Powód zamknięcia <span class="text-danger">*</span></label>
          <textarea name="close_reason" class="form-control form-control-sm" rows="3"
                    placeholder="np. sprawa rozstrzygnięta, zadanie wykonane…" required maxlength="500"></textarea>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-lock-fill me-1"></i>Zamknij koszulkę</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php include dirname(dirname(__DIR__)) . '/includes/ezd_sig_modal.php'; ?>
<?php include dirname(dirname(__DIR__)) . '/includes/ezd_rsign.php'; ?>
<?php include dirname(dirname(__DIR__)) . '/includes/ezd_email_modal.php'; ?>

<!-- Modal: przenieś plik do grupy -->
<div class="modal fade" id="zalMoveGroupModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="zal_move">
        <input type="hidden" name="zal_id" id="zmg-zal-id" value="">
        <div class="modal-header py-2">
          <h2 class="modal-title h6 mb-0"><i class="bi bi-folder-symlink text-primary me-2"></i>Przenieś do grupy</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="mb-2" style="font-size:.85rem">Plik <strong id="zmg-name" class="font-monospace"></strong></p>
          <select name="grupa_id" id="zmg-grupa-id" class="form-select"><?= $grupaOptsPlain ?></select>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Przenieś</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
(function(){
  var idI = document.getElementById('zmg-zal-id'), nameL = document.getElementById('zmg-name'), gsel = document.getElementById('zmg-grupa-id');
  document.addEventListener('click', function(e){
    var btn = e.target.closest('.ezd-move-grupa-btn');
    if (!btn) return;
    idI.value = btn.dataset.zal || ''; nameL.textContent = btn.dataset.name || ''; gsel.value = btn.dataset.grupa || '0';
  });
})();
</script>

<!-- JS: activate tab from URL hash -->
<script>
(function(){
  var hash = location.hash;
  var map = {'#dekretacje':'#tab-zadania','#notatki':'#notatki-modal-trigger','#workflow':'#tab-przebieg','#files':'#tab-meta','#pisma':'#tab-pisma'};
  var tabTarget = null;
  if (hash === '#dekretacje' || hash === '#workflow' || hash === '#pisma') {
    tabTarget = {'#dekretacje':'tab-zadania','#workflow':'tab-przebieg','#pisma':'tab-pisma'}[hash];
  }
  if (hash === '#tab-pisma' || hash === '#tab-zadania' || hash === '#tab-uczestnicy') {
    tabTarget = hash.slice(1);
  }
  // Metadane i Obieg nie są już zakładkami — to karty w prawej kolumnie
  if (hash === '#tab-meta' || hash === '#tab-przebieg' || hash === '#workflow') {
    tabTarget = null;
    var card = document.getElementById(hash === '#tab-meta' ? 'tab-meta' : 'tab-przebieg');
    if (card) card.scrollIntoView({block: 'start'});
  }
  if (tabTarget && window.bootstrap) {
    var el = document.querySelector('[href="#'+tabTarget+'"]');
    if (el) { try { new bootstrap.Tab(el).show(); } catch(e){} }
  }
  // notatki modal from hash
  if (hash === '#notatki') {
    var m = document.getElementById('notatkiModal');
    if (m && window.bootstrap) { new bootstrap.Modal(m).show(); history.replaceState(null,'',location.pathname+location.search); }
  }
  // share=1 auto-tab
  if (new URLSearchParams(location.search).get('share') === '1') {
    var el2 = document.querySelector('[href="#tab-uczestnicy"]');
    if (el2 && window.bootstrap) { try { new bootstrap.Tab(el2).show(); } catch(e){} }
  }
})();
</script>

<!-- ── Modal: notatki ─────────────────────────────────────────────────────── -->
<div class="modal fade" id="notatkiModal" tabindex="-1" aria-labelledby="notatkiModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title h5" id="notatkiModalLabel"><i class="bi bi-sticky text-secondary me-2"></i>Notatki (<?= count($notatki) ?>)</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <?php if($can_act): ?>
        <form method="post" class="mb-3">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="note_add">
          <div class="d-flex gap-2 align-items-start">
            <textarea name="tresc" class="form-control form-control-sm" rows="2" placeholder="Dodaj notatkę…" required></textarea>
            <button class="btn btn-sm btn-primary flex-shrink-0"><i class="bi bi-plus-lg me-1"></i>Dodaj</button>
          </div>
        </form>
        <?php endif; ?>
        <?php foreach($notatki as $n): $own = ($n['created_by']==$user_id || is_admin()); ?>
        <div class="border rounded-3 p-2 mb-2 <?= $n['pinned'] ? 'border-warning bg-warning bg-opacity-10' : '' ?>" style="font-size:.83rem">
          <div class="d-flex align-items-center gap-2 mb-1 text-muted" style="font-size:.7rem">
            <?php if($n['pinned']): ?><i class="bi bi-pin-angle-fill text-warning"></i><?php endif; ?>
            <span class="fw-semibold text-dark"><?= h($n['author'] ?? '—') ?></span>
            <span><?= date('d.m.Y H:i', strtotime($n['created_at'])) ?></span>
            <?php if($can_act): ?>
            <div class="ms-auto d-flex gap-1">
              <form method="post" class="d-inline"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_action" value="note_pin"><input type="hidden" name="note_id" value="<?= $n['id'] ?>">
                <button class="btn btn-xs btn-link p-0 text-muted" title="<?= $n['pinned']?'Odepnij':'Przypnij' ?>"><i class="bi bi-pin-angle<?= $n['pinned']?'-fill text-warning':'' ?>"></i></button>
              </form>
              <?php if($own): ?>
              <button class="btn btn-xs btn-link p-0 text-muted" type="button" data-bs-toggle="collapse" data-bs-target="#note-edit-<?= $n['id'] ?>"><i class="bi bi-pencil"></i></button>
              <form method="post" class="d-inline" onsubmit="return confirm('Usunąć notatkę?')"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_action" value="note_del"><input type="hidden" name="note_id" value="<?= $n['id'] ?>">
                <button class="btn btn-xs btn-link p-0 text-danger"><i class="bi bi-trash3"></i></button>
              </form>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </div>
          <div style="white-space:pre-wrap"><?= h($n['tresc']) ?></div>
          <?php if($own && $can_act): ?>
          <div class="collapse mt-2" id="note-edit-<?= $n['id'] ?>">
            <form method="post"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_action" value="note_edit"><input type="hidden" name="note_id" value="<?= $n['id'] ?>">
              <textarea name="tresc" class="form-control form-control-sm mb-2" rows="2" required><?= h($n['tresc']) ?></textarea>
              <button class="btn btn-xs btn-primary btn-sm">Zapisz</button>
            </form>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if(!$notatki): ?><div class="text-center text-muted py-2" style="font-size:.8rem">Brak notatek</div><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ── Modal: nowe pismo ──────────────────────────────────────────────────── -->
<?php if($can_act): ?>
<div class="modal fade" id="pismoModal" tabindex="-1" aria-labelledby="pismoModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="pismo_add">
        <div class="modal-header">
          <h2 class="modal-title h5" id="pismoModalLabel"><i class="bi bi-envelope-plus text-info me-2"></i>Nowe pismo</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">Kierunek <span class="text-danger">*</span></label>
            <div class="d-flex gap-2 flex-wrap">
              <?php foreach(EZD_KIERUNKI as $kv=>$kl): ?>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="kierunek" id="pm_k_<?= $kv ?>" value="<?= $kv ?>" <?= $kv==='przychodzace'?'checked':'' ?>>
                <label class="form-check-label" for="pm_k_<?= $kv ?>"><i class="bi <?= $kl['icon'] ?> me-1"></i><?= h($kl['label']) ?></label>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold" for="pm-title">Tytuł / przedmiot <span class="text-danger">*</span></label>
            <input type="text" name="title" id="pm-title" class="form-control" required>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-6">
              <label class="form-label fw-semibold" for="pm-medium">Rodzaj medium</label>
              <select name="rodzaj_medium" id="pm-medium" class="form-select">
                <?php foreach(EZD_MEDIA as $mv=>$ml): ?><option value="<?= $mv ?>" <?= $mv==='papier'?'selected':'' ?>><?= h($ml['label']) ?></option><?php endforeach; ?>
              </select>
              <div id="pm-epuap-warn" class="alert alert-warning py-1 px-2 mt-1" style="font-size:.75rem;display:none">
                <i class="bi bi-exclamation-triangle me-1"></i>Pismo przez eDoręczenia wymaga <strong>podpisu elektronicznego</strong> na załączonym pliku PDF.
              </div>
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold" for="pm-owner">Referent</label>
              <select name="owner_id" id="pm-owner" class="form-select">
                <option value="">— brak —</option>
                <?php foreach($users as $u): ?><option value="<?= $u['id'] ?>"><?= h($u['name']) ?></option><?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-6"><label class="form-label fw-semibold" for="pm-nadawca">Nadawca</label><input type="text" name="nadawca" id="pm-nadawca" class="form-control" placeholder="Firma / osoba"></div>
            <div class="col-6"><label class="form-label fw-semibold" for="pm-odbiorca">Odbiorca</label><input type="text" name="odbiorca" id="pm-odbiorca" class="form-control" placeholder="Firma / osoba"></div>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-4"><label class="form-label fw-semibold" for="pm-data-pisma">Data pisma</label><input type="date" name="data_pisma" id="pm-data-pisma" class="form-control" value="<?= date('Y-m-d') ?>"></div>
            <div class="col-4"><label class="form-label fw-semibold" for="pm-data-wplywu">Data wpływu</label><input type="date" name="data_wplywu" id="pm-data-wplywu" class="form-control" value="<?= date('Y-m-d') ?>"></div>
            <div class="col-4"><label class="form-label fw-semibold" for="pm-data-wysylki">Data wysyłki</label><input type="date" name="data_wysylki" id="pm-data-wysylki" class="form-control"></div>
          </div>
          <div class="mb-1"><label class="form-label fw-semibold" for="pm-tresc">Treść / notatka</label><textarea name="tresc" id="pm-tresc" class="form-control" rows="3"></textarea></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-info"><i class="bi bi-check-lg me-1"></i>Dodaj pismo</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── Modal: przekaż osobie (dekretacja) ─────────────────────────────────── -->
<?php if($can_act):
  $org_units_list = [];
  if (module_enabled('org_enabled') && function_exists('org_units_all')) $org_units_list = org_units_all('active');
?>
<div class="modal fade" id="dekrModal" tabindex="-1" aria-labelledby="dekrModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="dekretacja">
        <div class="modal-header">
          <h2 class="modal-title h5" id="dekrModalLabel"><i class="bi bi-person-lines-fill text-warning me-2"></i>Przekaż osobie</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?php
          $avail_roles = db_all("SELECT name, display_name FROM roles WHERE name NOT IN ('admin','viewer','crm_user') ORDER BY display_name");
          ?>
          <!-- Tryb: do osoby / do roli -->
          <div class="btn-group w-100 mb-3" role="group">
            <input type="radio" class="btn-check" name="_dekr_mode" id="dekr-mode-osoba" value="osoba" checked>
            <label class="btn btn-outline-secondary btn-sm" for="dekr-mode-osoba"><i class="bi bi-person me-1"></i>Do osoby</label>
            <input type="radio" class="btn-check" name="_dekr_mode" id="dekr-mode-rola" value="rola">
            <label class="btn btn-outline-secondary btn-sm" for="dekr-mode-rola"><i class="bi bi-people me-1"></i>Do roli</label>
          </div>
          <div id="dekr-osoby-fields">
            <?php if ($org_units_list): ?>
            <div class="mb-3">
              <label class="form-label fw-semibold" for="dekr-unit">Jednostka organizacyjna</label>
              <select name="unit_id" class="form-select" id="dekr-unit" onchange="dekrUnitChange(this)">
                <option value="">— lub wybierz jednostkę —</option>
                <?php foreach($org_units_list as $ou): ?><option value="<?= $ou['id'] ?>"><?= h($ou['name']) ?> (<?= h($ou['code']) ?>)</option><?php endforeach; ?>
              </select>
            </div>
            <?php endif; ?>
            <div class="mb-3">
              <label class="form-label fw-semibold" for="dekr-user">Wykonawca <span class="text-danger">*</span></label>
              <select name="wykonawca_id" class="form-select" id="dekr-user">
                <option value="">— wybierz osobę —</option>
                <?php foreach($users as $u): ?><option value="<?= $u['id'] ?>"><?= h($u['name']) ?></option><?php endforeach; ?>
              </select>
            </div>
          </div>
          <div id="dekr-rola-fields" style="display:none">
            <input type="hidden" name="rola_target" id="dekr-rola-input" value="">
            <div class="mb-3">
              <label class="form-label fw-semibold">Rola docelowa</label>
              <select class="form-select" id="dekr-rola-select" onchange="document.getElementById('dekr-rola-input').value=this.value">
                <option value="">— wybierz rolę —</option>
                <?php foreach($avail_roles as $r): ?>
                <option value="<?= h($r['name']) ?>"><?= h($r['display_name']) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text text-muted">Pierwsza osoba z tej roli, która otworzy sprawę, automatycznie przejmie dekretację.</div>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold" for="dekr-dysp">Dyspozycja</label>
            <select name="dyspozycja" id="dekr-dysp" class="form-select">
              <?php foreach(EZD_DYSPOZYCJE as $k=>$v): ?><option value="<?= $k ?>"><?= h($v) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3"><label class="form-label fw-semibold" for="dekr-tresc">Treść dyspozycji</label><input type="text" name="tresc" id="dekr-tresc" class="form-control"></div>
          <div class="mb-1"><label class="form-label fw-semibold" for="dekr-deadline">Termin</label><input type="date" name="deadline" id="dekr-deadline" class="form-control" min="<?= date('Y-m-d') ?>"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-warning"><i class="bi bi-send me-1"></i>Przekaż</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php if ($org_units_list): ?>
<script>
var dekrHeads = <?= json_encode(array_reduce($org_units_list, function($carry, $u) {
    $head = function_exists('org_unit_head') ? org_unit_head((int)$u['id']) : null;
    if ($head) $carry[(string)$u['id']] = (int)$head['user_id'];
    return $carry;
}, [])) ?>;
function dekrUnitChange(sel) {
    var uid = sel.value, userSel = document.getElementById('dekr-user');
    if (uid && dekrHeads[uid]) userSel.value = dekrHeads[uid];
}
// Przełącznik trybu dekretacji
document.querySelectorAll('input[name="_dekr_mode"]').forEach(function(r) {
    r.addEventListener('change', function() {
        var isRola = this.value === 'rola';
        document.getElementById('dekr-osoby-fields').style.display = isRola ? 'none' : '';
        document.getElementById('dekr-rola-fields').style.display  = isRola ? ''     : 'none';
        if (isRola) {
            document.getElementById('dekr-rola-input').value = document.getElementById('dekr-rola-select').value;
        } else {
            document.getElementById('dekr-rola-input').value = '';
        }
    });
});
</script>
<?php endif; ?>
<?php endif; ?>

<!-- ── Modal: nowy plik Word/Excel ────────────────────────────────────────── -->
<?php if($can_act): ?>
<div class="modal fade" id="newOfficeFileModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="new_office_file">
        <input type="hidden" name="filetype" id="nof-filetype" value="docx">
        <div class="modal-header">
          <h2 class="modal-title h5"><i class="bi bi-file-earmark-plus text-success me-2"></i>Nowy plik <span id="nof-type-label">Word</span></h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold" for="nof-name">Nazwa <span class="text-danger">*</span></label>
            <div class="input-group">
              <input type="text" name="name" id="nof-name" class="form-control" placeholder="np. Notatka służbowa" maxlength="200" required>
              <span class="input-group-text" id="nof-ext">.docx</span>
            </div>
          </div>
          <?php if($grupy): ?>
          <div class="mb-3">
            <label class="form-label fw-semibold" for="nof-grupa">Grupa</label>
            <select name="grupa_id" id="nof-grupa" class="form-select">
              <option value="0">— bez grupy —</option>
              <?php foreach($grupy as $g): ?><option value="<?= $g['id'] ?>"><?= h($g['nazwa']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
          <div class="form-check">
            <input type="checkbox" class="form-check-input" name="with_znak" value="1" id="nof-znak" checked>
            <label class="form-check-label" for="nof-znak" style="font-size:.85rem">Dodaj znak sprawy w nagłówku — <span class="font-monospace"><?= h($sprawa['znak_sprawy']) ?></span></label>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Utwórz</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
(function(){
  var typeI = document.getElementById('nof-filetype'), typeL = document.getElementById('nof-type-label'), extL = document.getElementById('nof-ext');
  var META = {docx:{label:'Word',ext:'.docx'},xlsx:{label:'Excel',ext:'.xlsx'}};
  document.querySelectorAll('.ezd-new-office-file').forEach(function(a){
    a.addEventListener('click', function(){ var t=a.getAttribute('data-type'), m=META[t]||META.docx; typeI.value=t; typeL.textContent=m.label; extL.textContent=m.ext; });
  });
})();
</script>
<?php endif; ?>

<!-- ── Modal: przekaż dokumenty ───────────────────────────────────────────── -->
<?php if($can_act && $zalaczniki): ?>
<div class="modal fade" id="przekazDokModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="zal_przekaz">
        <div class="modal-header">
          <h2 class="modal-title h5"><i class="bi bi-send-check text-warning me-2"></i>Przekaż dokumenty</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">Wybierz dokumenty</label>
            <div class="border rounded-3 p-2" style="max-height:240px;overflow-y:auto">
              <?php foreach($zalaczniki as $z): ?>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="zal_ids[]" value="<?= (int)$z['id'] ?>" id="pz-zal-<?= (int)$z['id'] ?>">
                <label class="form-check-label d-flex align-items-center gap-2" for="pz-zal-<?= (int)$z['id'] ?>" style="font-size:.85rem">
                  <i class="bi <?= ezd_file_icon($z['original_name']) ?>"></i><?= h($z['original_name']) ?>
                </label>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold" for="pz-user">Komu <span class="text-danger">*</span></label>
            <select name="przekaz_user_id" id="pz-user" class="form-select" required>
              <option value="">— wybierz osobę —</option>
              <?php foreach($users as $u): ?><option value="<?= $u['id'] ?>"><?= h($u['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="mb-1"><label class="form-label fw-semibold" for="pz-note">Wiadomość</label><input type="text" name="przekaz_note" id="pz-note" class="form-control"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-warning"><i class="bi bi-send-check me-1"></i>Przekaż</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if($can_act): ?>
<div class="modal fade" id="quickDispatchModal" tabindex="-1" aria-labelledby="qdModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <form method="post" action="<?= APP_URL ?>/ezd/sprawy/quick_dispatch.php" class="modal-content" id="qd-form">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="sprawa_id" value="<?= $id ?>">
      <div id="qd-zal-ids"></div>
      <input type="hidden" name="carrier_id" id="qd-carrier-id" value="">
      <input type="hidden" name="service_id" id="qd-service-id" value="">
      <div class="modal-header">
        <h2 class="modal-title h5" id="qdModalLabel"><i class="bi bi-mailbox2 text-success me-2"></i>Zarejestruj w wychodzących</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label fw-semibold">Zaznaczone pliki (<span id="qd-modal-count">0</span>) — jedna przesyłka</label>
          <ul id="qd-file-list" class="small mb-0 ps-3"></ul>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold" for="qd-title">Tytuł pisma</label>
          <input type="text" name="title" id="qd-title" class="form-control" placeholder="np. Odpowiedź w sprawie…">
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold" for="qd-odbiorca">Odbiorca <span class="text-danger">*</span></label>
          <input type="text" name="odbiorca" id="qd-odbiorca" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold d-block">Jak wysłać?</label>
          <div class="form-check">
            <input class="form-check-input qd-tryb" type="radio" name="tryb" value="wydruk" id="qd-tryb-wydruk" checked>
            <label class="form-check-label" for="qd-tryb-wydruk"><i class="bi bi-printer me-1"></i>Wydruk do podpisu odręcznego</label>
          </div>
          <div class="form-check">
            <input class="form-check-input qd-tryb" type="radio" name="tryb" value="elektroniczny" id="qd-tryb-elektroniczny">
            <label class="form-check-label" for="qd-tryb-elektroniczny"><i class="bi bi-at me-1"></i>Elektronicznie (inny kanał — bez poświadczenia)</label>
          </div>
          <div class="form-check">
            <input class="form-check-input qd-tryb" type="radio" name="tryb" value="postivo" id="qd-tryb-postivo">
            <label class="form-check-label" for="qd-tryb-postivo"><i class="bi bi-send-fill me-1"></i>Postivo.pl — wysyła system, generuje poświadczenie, status synchronizuje się automatycznie</label>
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold" for="qd-sposob">Sposób wysyłki (rejestr RPW-W)</label>
          <select name="sposob" id="qd-sposob" class="form-select">
            <?php foreach (EZD_RPWY_SPOSOBY as $sk => $sv): ?>
            <option value="<?= $sk ?>"><?= h($sv['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-2" id="qd-zpo-wrap">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="qd-zpo-on">
            <label class="form-check-label fw-semibold" for="qd-zpo-on">Z potwierdzeniem odbioru (ZPO)</label>
          </div>
          <div class="form-check ms-4 d-none" id="qd-zpo-epo-wrap">
            <input class="form-check-input" type="checkbox" id="qd-zpo-epo">
            <label class="form-check-label" for="qd-zpo-epo">Elektroniczne (EPO) — Poczta Polska, zamiast papierowej „żółtej kartki"</label>
          </div>
          <div class="form-text">
            Ustawia sposób w rejestrze RPW-W na „Polecony za potwierdzeniem odbioru".
            Dla trybu Postivo dodatkowo dobiera pasującą usługę Poczty Polskiej z listy poniżej (po nazwie) — sprawdź, czy trafiła właściwa, zwłaszcza jeśli to pierwszy raz.
          </div>
        </div>
        </div>
        <div class="collapse" id="qd-postivo-fields">
          <div class="row g-2 p-2 rounded border bg-light mb-2">
            <div class="col-12">
              <label class="form-label small mb-0" for="qd-service">Usługa Postivo (nośnik/przewoźnik)</label>
              <select id="qd-service" class="form-select form-select-sm">
                <option value="">— domyślna (z Administracja → Postivo) —</option>
              </select>
              <div id="qd-service-status" class="form-text"></div>
            </div>
            <div class="col-md-6"><label class="form-label small mb-0">Nazwa odbiorcy (do koperty)</label><input type="text" name="recipient_name" class="form-control form-control-sm"></div>
            <div class="col-md-6"><label class="form-label small mb-0">Powód wysyłki</label><input type="text" name="doc_reason" id="qd-doc-reason" class="form-control form-control-sm" value="Korespondencja urzędowa"></div>
            <div class="col-md-8"><label class="form-label small mb-0">Ulica i numer <span class="text-danger">*</span></label><input type="text" name="address_line1" class="form-control form-control-sm"></div>
            <div class="col-md-2"><label class="form-label small mb-0">Nr domu</label><input type="text" name="home_number" class="form-control form-control-sm"></div>
            <div class="col-md-2"><label class="form-label small mb-0">Nr lokalu</label><input type="text" name="flat_number" class="form-control form-control-sm"></div>
            <div class="col-md-6"><label class="form-label small mb-0">Adres cd. (opcjonalnie)</label><input type="text" name="address_line2" class="form-control form-control-sm"></div>
            <div class="col-md-3"><label class="form-label small mb-0">Kod pocztowy <span class="text-danger">*</span></label><input type="text" name="postcode" id="qd-city-postal" class="form-control form-control-sm" placeholder="00-001"></div>
            <div class="col-md-3"><label class="form-label small mb-0">Miasto <span class="text-danger">*</span></label><input type="text" name="city" id="qd-city" class="form-control form-control-sm"><?php require_once dirname(__DIR__, 2) . '/includes/teryt.php'; echo terytCityAttach('#qd-city', '#qd-city-postal'); ?></div>
            <div class="col-md-6"><label class="form-label small mb-0">Telefon (opcjonalnie)</label><input type="text" name="phone_number" class="form-control form-control-sm"></div>
            <div class="col-md-6"><label class="form-label small mb-0">Kraj</label><input type="text" name="country" class="form-control form-control-sm" value="PL"></div>
            <div class="col-12">
              <button type="button" id="qd-preview-btn" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye me-1"></i>Podgląd wydruku</button>
              <span class="form-text">Otwiera w nowej karcie dokładnie to, co poleci do Postivo — zanim zlecisz wysyłkę.</span>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
        <button type="submit" class="btn btn-success"><i class="bi bi-mailbox2 me-1"></i>Zarejestruj</button>
      </div>
    </form>
  </div>
</div>
<script>
(function () {
    var checks   = () => Array.from(document.querySelectorAll('.qd-check'));
    var btn      = document.getElementById('qd-btn');
    var countEl  = document.getElementById('qd-count');
    var modalCnt = document.getElementById('qd-modal-count');
    var fileList = document.getElementById('qd-file-list');
    var idsHost  = document.getElementById('qd-zal-ids');
    var trybRadios = () => Array.from(document.querySelectorAll('.qd-tryb'));
    var postivoFields = document.getElementById('qd-postivo-fields');
    var sposobSel  = document.getElementById('qd-sposob');
    var previewBtn = document.getElementById('qd-preview-btn');
    var qdForm     = document.getElementById('qd-form');
    if (!btn) return;

    function currentTryb() {
        var r = trybRadios().find(r => r.checked);
        return r ? r.value : 'wydruk';
    }
    var servicesLoaded = false;
    var serviceSel   = document.getElementById('qd-service');
    var serviceStatus = document.getElementById('qd-service-status');
    var carrierIdInp = document.getElementById('qd-carrier-id');
    var serviceIdInp = document.getElementById('qd-service-id');

    var zpoOnChk  = document.getElementById('qd-zpo-on');
    var zpoEpoChk = document.getElementById('qd-zpo-epo');
    var zpoEpoWrap = document.getElementById('qd-zpo-epo-wrap');

    // ZPO oparte na checkboxach: "Z ZPO" włącza drugi checkbox "Elektroniczne
    // (EPO)" (Poczta Polska) — bez niego to papierowa "żółta kartka". Zawsze
    // ustawia sposób RPW-W; dla trybu Postivo dodatkowo próbuje dopasować
    // usługę po nazwie (dopiero gdy lista jest już wczytana).
    function currentZpoChoice() {
        if (!zpoOnChk || !zpoOnChk.checked) return 'brak';
        return (zpoEpoChk && zpoEpoChk.checked) ? 'epo' : 'papier';
    }
    function applyZpoAutoMatch() {
        var choice = currentZpoChoice();
        if (choice === 'brak') return;
        if (sposobSel) sposobSel.value = 'polecony_zpo';
        if (!serviceSel || !servicesLoaded) return;
        var opts = Array.from(serviceSel.querySelectorAll('option')).filter(o => o.value);
        var match = opts.find(function (o) {
            var t = o.textContent.toLowerCase();
            var hasEpo = t.indexOf('epo') !== -1;
            return choice === 'epo' ? hasEpo : (t.indexOf('potw') !== -1 && !hasEpo);
        });
        if (match) { serviceSel.value = match.value; serviceSel.dispatchEvent(new Event('change')); }
    }
    if (zpoOnChk) {
        zpoOnChk.addEventListener('change', function () {
            zpoEpoWrap.classList.toggle('d-none', !this.checked);
            if (!this.checked && zpoEpoChk) zpoEpoChk.checked = false;
            applyZpoAutoMatch();
        });
    }
    if (zpoEpoChk) zpoEpoChk.addEventListener('change', applyZpoAutoMatch);

    function loadPostivoServices() {
        if (servicesLoaded || !serviceSel) return;
        servicesLoaded = true;
        serviceStatus.textContent = 'Wczytuję dostępne usługi…';
        fetch('<?= APP_URL ?>/ezd/sprawy/postivo_services.php')
            .then(r => r.json())
            .then(function (data) {
                if (!data.ok) { serviceStatus.textContent = 'Nie udało się wczytać usług: ' + data.error; return; }
                (data.carriers || []).forEach(function (c) {
                    var grp = document.createElement('optgroup');
                    grp.label = c.carrier_name;
                    (c.services || []).forEach(function (s) {
                        var opt = document.createElement('option');
                        opt.value = c.carrier_id + ':' + s.service_id;
                        opt.textContent = s.service_name + (s.service_return_fee ? ' (zwrot: ' + s.service_return_fee + ' zł)' : '');
                        if (c.carrier_id === data.default_carrier_id && s.service_id === data.default_service_id) opt.selected = true;
                        grp.appendChild(opt);
                    });
                    serviceSel.appendChild(grp);
                });
                serviceStatus.textContent = '';
                serviceSel.dispatchEvent(new Event('change'));
                applyZpoAutoMatch();
            })
            .catch(function () { serviceStatus.textContent = 'Błąd sieci przy wczytywaniu usług.'; });
    }

    if (serviceSel) {
        serviceSel.addEventListener('change', function () {
            var parts = (this.value || '').split(':');
            carrierIdInp.value = parts[0] || '';
            serviceIdInp.value = parts[1] || '';
        });
    }

    function refreshTrybUI() {
        var isPostivo = currentTryb() === 'postivo';
        var bs = bootstrap.Collapse.getOrCreateInstance(postivoFields, {toggle: false});
        isPostivo ? bs.show() : bs.hide();
        if (isPostivo) loadPostivoServices();
    }
    trybRadios().forEach(r => r.addEventListener('change', refreshTrybUI));
    refreshTrybUI();

    if (previewBtn) {
        previewBtn.addEventListener('click', function () {
            var tmp = document.createElement('form');
            tmp.method = 'post';
            tmp.action = '<?= APP_URL ?>/ezd/sprawy/quick_dispatch_preview.php';
            tmp.target = '_blank';
            Array.from(qdForm.elements).forEach(function (el) {
                if (!el.name) return;
                if (el.type === 'checkbox' || el.type === 'radio') { if (!el.checked) return; }
                var inp = document.createElement('input');
                inp.type = 'hidden'; inp.name = el.name; inp.value = el.value;
                tmp.appendChild(inp);
            });
            document.body.appendChild(tmp);
            tmp.submit();
            tmp.remove();
        });
    }

    function refresh() {
        var checked = checks().filter(c => c.checked);
        if (checked.length > 0) {
            btn.removeAttribute('disabled');
            countEl.classList.remove('d-none');
            countEl.textContent = checked.length;
        } else {
            btn.setAttribute('disabled', 'disabled');
            countEl.classList.add('d-none');
        }
    }
    document.addEventListener('change', function (e) {
        if (e.target.classList && e.target.classList.contains('qd-check')) refresh();
    });
    refresh();

    // Przy otwarciu modala: przepisz zaznaczenie do ukrytych pól formularza.
    document.getElementById('quickDispatchModal').addEventListener('show.bs.modal', function () {
        var checked = checks().filter(c => c.checked);
        idsHost.innerHTML = '';
        checked.forEach(function (c) {
            var inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = 'zal_ids[]'; inp.value = c.value;
            idsHost.appendChild(inp);
        });
        modalCnt.textContent = checked.length;
        fileList.innerHTML = checked.map(c => '<li>' + (c.dataset.name || c.value) + '</li>').join('');
    });
})();
</script>
<?php endif; ?>

<?php if($can_act && $signed_pdfs): ?>
<div class="modal fade" id="sprawaSendMailModal" tabindex="-1" aria-labelledby="sprawaSendMailModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <form method="post" class="modal-content">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="send_email_pismo">
      <div class="modal-header">
        <h6 class="modal-title fw-semibold" id="sprawaSendMailModalLabel"><i class="bi bi-send me-1 text-success"></i>Wyślij dokumenty mailem</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label fw-semibold" style="font-size:.84rem">Adres e-mail odbiorcy <span class="text-danger">*</span></label>
          <input type="email" name="recipient_email" class="form-control form-control-sm" required placeholder="odbiorca@example.com">
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold" style="font-size:.84rem">Temat</label>
          <input type="text" name="mail_subject" class="form-control form-control-sm"
                 value="<?= h($sprawa['znak_sprawy'] . ' — ' . $sprawa['title']) ?>">
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold" style="font-size:.84rem">Treść wiadomości</label>
          <textarea name="mail_body" class="form-control form-control-sm" rows="7"
                    placeholder="Treść wiadomości e-mail (opcjonalna)…"></textarea>
        </div>
        <div class="mb-2">
          <label class="form-label fw-semibold" style="font-size:.84rem">Załączniki — podpisane elektronicznie PDF</label>
          <?php foreach($signed_pdfs as $z): ?>
          <div class="form-check mb-1">
            <input class="form-check-input" type="checkbox" name="zal_ids[]" value="<?= $z['id'] ?>" id="ssm<?= $z['id'] ?>" checked>
            <label class="form-check-label d-flex align-items-center gap-2 flex-wrap" for="ssm<?= $z['id'] ?>" style="font-size:.82rem">
              <i class="bi bi-file-earmark-pdf text-danger"></i>
              <span><?= h($z['original_name']) ?></span>
              <span class="text-muted" style="font-size:.72rem"><?= ezd_filesize($z['file_size']) ?></span>
              <span class="badge bg-success bg-opacity-15 text-success border border-success" style="font-size:.62rem"><i class="bi bi-pen-fill me-1"></i>Podpisany elektronicznie</span>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="p-2 rounded border border-secondary border-opacity-25" style="font-size:.72rem;color:#64748b">
          <i class="bi bi-info-circle me-1"></i>Wiadomość zostanie wysłana z domyślnego adresu e-mail organizacji. Wybrany plik PDF zostanie dołączony jako załącznik.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
        <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-send me-1"></i>Wyślij</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if($can_act): ?>
<div class="modal fade" id="pismoFromZalModal" tabindex="-1" aria-labelledby="pismoFromZalModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <form method="post" class="modal-content">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="pismo_from_zal">
      <input type="hidden" name="zal_id" id="pfzZalId" value="">
      <div class="modal-header">
        <h6 class="modal-title fw-semibold" id="pismoFromZalModalLabel"><i class="bi bi-envelope-plus me-1 text-info"></i>Utwórz pismo z pliku</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="pfzFileInfo" class="mb-3 p-2 rounded bg-success bg-opacity-10 border border-success border-opacity-25 d-flex align-items-center gap-2" style="font-size:.82rem">
          <i class="bi bi-patch-check text-success"></i>
          <span id="pfzFileName" class="text-truncate fw-semibold"></span>
          <span class="badge bg-success bg-opacity-15 text-success border border-success ms-auto" style="font-size:.62rem"><i class="bi bi-pen-fill me-1"></i>Podpisany el.</span>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold" style="font-size:.84rem">Tytuł pisma <span class="text-danger">*</span></label>
          <input type="text" name="pismo_title" id="pfzTitle" class="form-control form-control-sm" required>
        </div>
        <div class="row g-3 mb-3">
          <div class="col-md-4">
            <label class="form-label fw-semibold" style="font-size:.84rem">Kierunek</label>
            <div class="d-flex gap-3 flex-wrap pt-1">
              <?php foreach(EZD_KIERUNKI as $kval => $klabel): ?>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="kierunek" id="pfzKier_<?= $kval ?>"
                       value="<?= $kval ?>" <?= $kval === 'przychodzace' ? 'checked' : '' ?>>
                <label class="form-check-label" for="pfzKier_<?= $kval ?>" style="font-size:.82rem"><?= h($klabel['label']) ?></label>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold" style="font-size:.84rem">Rodzaj medium</label>
            <select name="rodzaj_medium" class="form-select form-select-sm">
              <?php foreach(EZD_MEDIA as $mval => $mlabel): ?>
              <option value="<?= $mval ?>" <?= $mval === 'email' ? 'selected' : '' ?>><?= h(is_array($mlabel) ? ($mlabel['label'] ?? $mval) : $mlabel) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold" style="font-size:.84rem">Data pisma</label>
            <input type="date" name="data_pisma" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
          </div>
        </div>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-semibold" style="font-size:.84rem">Nadawca</label>
            <input type="text" name="nadawca" class="form-control form-control-sm" placeholder="Opcjonalnie">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold" style="font-size:.84rem">Odbiorca</label>
            <input type="text" name="odbiorca" class="form-control form-control-sm" placeholder="Opcjonalnie">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
        <button type="submit" class="btn btn-info btn-sm text-white"><i class="bi bi-envelope-plus me-1"></i>Utwórz pismo</button>
      </div>
    </form>
  </div>
</div>
<script>
document.querySelectorAll('[data-bs-target="#pismoFromZalModal"]').forEach(function(btn) {
  btn.addEventListener('click', function() {
    var zalId = this.dataset.zal   || '';
    var name  = this.dataset.name  || '';
    document.getElementById('pfzZalId').value   = zalId;
    document.getElementById('pfzFileName').textContent = name;
    var title = name.replace(/\.[^.]+$/, '');
    document.getElementById('pfzTitle').value = title;
  });
});
</script>
<?php endif; ?>

<!-- Offcanvas: podgląd pisma inline -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="pismoPanel" aria-labelledby="pismoPanelLabel" style="width:min(440px,100vw)">
  <div class="offcanvas-header border-bottom">
    <h6 class="offcanvas-title fw-semibold" id="pismoPanelLabel"><i class="bi bi-envelope me-1 text-primary"></i>Pismo</h6>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Zamknij"></button>
  </div>
  <div class="offcanvas-body" id="pismoPanelBody">
    <div class="text-center text-muted py-5"><div class="spinner-border spinner-border-sm" role="status"></div></div>
  </div>
</div>

<!-- Modal: podgląd PDF inline -->
<div class="modal fade" id="pdfPreviewModal" tabindex="-1" aria-labelledby="pdfPreviewModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-fullscreen-lg-down">
    <div class="modal-content" style="height:90vh">
      <div class="modal-header py-2">
        <h6 class="modal-title fw-semibold text-truncate me-2" id="pdfPreviewModalLabel"><i class="bi bi-file-earmark-pdf text-danger me-1"></i><span id="pdfPreviewName"></span></h6>
        <a id="pdfPreviewDownload" href="#" target="_blank" class="btn btn-sm btn-outline-secondary me-2"><i class="bi bi-download me-1"></i>Pobierz</a>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body p-0 flex-grow-1" style="overflow:hidden">
        <iframe id="pdfPreviewFrame" src="" style="width:100%;height:100%;border:none" loading="lazy"></iframe>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var panel   = document.getElementById('pismoPanel');
  var body    = document.getElementById('pismoPanelBody');
  var oc      = bootstrap.Offcanvas.getOrCreateInstance(panel);
  var lastId  = null;

  function showToast(msg, ok) {
    var t = document.createElement('div');
    t.className = 'position-fixed bottom-0 end-0 m-3 alert alert-' + (ok ? 'success' : 'danger') + ' py-2 px-3 shadow';
    t.style.cssText = 'font-size:.82rem;z-index:9999;max-width:280px';
    t.setAttribute('role', ok ? 'status' : 'alert');
    t.setAttribute('aria-live', ok ? 'polite' : 'assertive');
    t.setAttribute('aria-label', ok ? 'Powiadomienie: Gotowe' : 'Powiadomienie: Błąd');
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(function() { t.remove(); }, 3000);
  }

  function loadPismo(id, force) {
    if (id === lastId && !force) { oc.show(); return; }
    body.innerHTML = '<div class="text-center text-muted py-5"><div class="spinner-border spinner-border-sm" role="status"></div></div>';
    oc.show();
    lastId = null;
    fetch('<?= APP_URL ?>/ezd/pisma/ajax_panel.php?id=' + id, {credentials: 'same-origin'})
      .then(function(r) { return r.text(); })
      .then(function(html) { body.innerHTML = html; lastId = id; })
      .catch(function() { body.innerHTML = '<div class="text-danger p-3">Błąd ładowania danych.</div>'; });
  }

  // Klik w kartę pisma
  document.addEventListener('click', function(e) {
    var btn = e.target.closest('.tl-pismo-btn');
    if (btn) { e.preventDefault(); loadPismo(btn.dataset.pismoId); return; }

    // Akcje z załadowanego panelu (delegacja)
    var statusForm = e.target.closest('.ezd-panel-status-form');
    if (statusForm) return; // handled by submit

    var uploadForm = e.target.closest('.ezd-panel-upload-form');
    if (uploadForm) return; // handled by submit

    // PDF preview z panelu
    var pdfBtn = e.target.closest('.ezd-pdf-btn');
    if (pdfBtn) {
      e.preventDefault();
      document.getElementById('pdfPreviewFrame').src = pdfBtn.dataset.url;
      document.getElementById('pdfPreviewName').textContent = pdfBtn.dataset.name || 'Dokument';
      document.getElementById('pdfPreviewDownload').href = pdfBtn.dataset.url;
      var pdfMod = bootstrap.Modal.getOrCreateInstance(document.getElementById('pdfPreviewModal'));
      pdfMod.show();
      return;
    }

    // Utwórz pismo z pliku — z panelu
    var fromZalBtn = e.target.closest('.ezd-panel-from-zal');
    if (fromZalBtn && document.getElementById('pfzZalId')) {
      e.preventDefault();
      document.getElementById('pfzZalId').value = fromZalBtn.dataset.zal;
      document.getElementById('pfzFileName').textContent = fromZalBtn.dataset.name;
      document.getElementById('pfzTitle').value = fromZalBtn.dataset.name.replace(/\.[^.]+$/, '');
      oc.hide();
      bootstrap.Modal.getOrCreateInstance(document.getElementById('pismoFromZalModal')).show();
      return;
    }
  });

  document.addEventListener('keydown', function(e) {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    var btn = e.target.closest('.tl-pismo-btn');
    if (!btn) return;
    e.preventDefault();
    loadPismo(btn.dataset.pismoId);
  });

  // Submit: status
  document.addEventListener('submit', function(e) {
    var sf = e.target.closest('.ezd-panel-status-form');
    if (sf) {
      e.preventDefault();
      var pismoId = sf.dataset.pismoId;
      var fd = new FormData(sf);
      fetch(sf.dataset.actionUrl, {method: 'POST', credentials: 'same-origin', body: fd})
        .then(function(r) { return r.json(); })
        .then(function(j) {
          showToast(j.msg || j.error || '?', !!j.ok);
          if (j.ok) loadPismo(pismoId, true);
        })
        .catch(function() { showToast('Błąd połączenia.', false); });
      return;
    }

    // Submit: upload
    var uf = e.target.closest('.ezd-panel-upload-form');
    if (uf) {
      e.preventDefault();
      var pismoId2 = uf.dataset.pismoId;
      var fd2 = new FormData(uf);
      var btn2 = uf.querySelector('button[type=submit]');
      if (btn2) { btn2.disabled = true; btn2.textContent = '…'; }
      fetch(uf.dataset.actionUrl, {method: 'POST', credentials: 'same-origin', body: fd2})
        .then(function(r) { return r.json(); })
        .then(function(j) {
          showToast(j.msg || j.error || '?', !!j.ok);
          if (j.ok) loadPismo(pismoId2, true);
          else if (btn2) { btn2.disabled = false; btn2.innerHTML = '<i class="bi bi-upload me-1"></i>Dodaj'; }
        })
        .catch(function() {
          showToast('Błąd połączenia.', false);
          if (btn2) { btn2.disabled = false; btn2.innerHTML = '<i class="bi bi-upload me-1"></i>Dodaj'; }
        });
      return;
    }
  });

  // Czyść iframe po zamknięciu PDF modal (zatrzymuje pobieranie)
  document.getElementById('pdfPreviewModal').addEventListener('hidden.bs.modal', function() {
    document.getElementById('pdfPreviewFrame').src = '';
  });

  panel.addEventListener('hidden.bs.offcanvas', function() { lastId = null; });
})();
</script>

<?php if($can_act): ?>
<script>
(function(){
  var filesDiv = document.getElementById('files');
  if (!filesDiv) return;

  filesDiv.style.position = 'relative';

  // Overlay "Upuść pliki tutaj"
  var overlay = document.createElement('div');
  overlay.className = 'sp-dz-overlay';
  overlay.setAttribute('aria-hidden', 'true');
  overlay.innerHTML = '<div class="sp-dz-msg">'
    + '<i class="bi bi-cloud-arrow-up d-block mb-2" style="font-size:3rem"></i>'
    + 'Upuść pliki, aby dodać do koszulki'
    + '</div>';
  filesDiv.appendChild(overlay);

  // Toast postępu przesyłania
  var prog = document.createElement('div');
  prog.className = 'sp-dz-progress';
  prog.innerHTML = '<div class="d-flex align-items-center gap-2 mb-2">'
    + '<div class="spinner-border spinner-border-sm text-primary" role="status"><span class="visually-hidden">Przesyłanie…</span></div>'
    + '<span id="sp-dz-prog-msg" class="text-truncate" style="max-width:180px">Przesyłanie…</span>'
    + '</div>'
    + '<div class="progress" style="height:5px">'
    + '<div class="progress-bar bg-primary" id="sp-dz-prog-bar" style="width:0%;transition:width .3s"></div>'
    + '</div>';
  document.body.appendChild(prog);

  var progMsg = document.getElementById('sp-dz-prog-msg');
  var progBar = document.getElementById('sp-dz-prog-bar');

  function getCsrf() {
    var el = document.querySelector('#ezd-upload-form input[name="_csrf"]')
          || document.querySelector('#ezd-quick-upload-form input[name="_csrf"]');
    return el ? el.value : '';
  }

  function hasFiles(e) {
    var types = e.dataTransfer ? e.dataTransfer.types : [];
    for (var i = 0; i < types.length; i++) { if (types[i] === 'Files') return true; }
    return false;
  }

  function showToast(msg, ok) {
    var t = document.createElement('div');
    t.className = 'position-fixed bottom-0 end-0 m-3 alert alert-' + (ok ? 'success' : 'danger') + ' py-2 px-3 shadow';
    t.style.cssText = 'font-size:.82rem;z-index:10000;max-width:340px';
    t.innerHTML = '<i class="bi bi-' + (ok ? 'check-circle' : 'exclamation-circle') + '-fill me-2"></i>' + msg;
    document.body.appendChild(t);
    setTimeout(function() { t.remove(); }, 4500);
  }

  var dragCount = 0;
  var uploadUrl = '<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $id ?>';

  filesDiv.addEventListener('dragenter', function(e) {
    if (!hasFiles(e)) return;
    e.preventDefault();
    dragCount++;
    overlay.classList.add('active');
  });

  filesDiv.addEventListener('dragover', function(e) {
    if (!hasFiles(e)) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'copy';
  });

  filesDiv.addEventListener('dragleave', function(e) {
    dragCount--;
    if (dragCount <= 0) { dragCount = 0; overlay.classList.remove('active'); }
  });

  filesDiv.addEventListener('drop', function(e) {
    e.preventDefault();
    dragCount = 0;
    overlay.classList.remove('active');
    var all = Array.from(e.dataTransfer.files);
    var files = all.filter(function(f){ return f.size > 0 && f.size <= 26214400; });
    if (!files.length) {
      showToast('Brak plików do przesłania (maks. 25 MB na plik).', false);
      return;
    }
    if (files.length < all.length) {
      showToast('Pominięto ' + (all.length - files.length) + ' plik(i) przekraczający 25 MB.', false);
    }
    uploadFiles(files);
  });

  // Zapobiega otwarciu pliku przez przeglądarkę przy upuszczeniu poza strefą
  document.addEventListener('dragover', function(e) { e.preventDefault(); });
  document.addEventListener('drop', function(e) { e.preventDefault(); });

  function uploadFiles(files) {
    var total = files.length;
    var i = 0;
    prog.classList.add('visible');

    function uploadNext() {
      if (i >= total) {
        progMsg.textContent = 'Gotowe!';
        progBar.style.width = '100%';
        setTimeout(function() {
          prog.classList.remove('visible');
          window.location.href = uploadUrl + '#files';
        }, 700);
        return;
      }
      var file = files[i];
      progMsg.textContent = (total > 1 ? ((i + 1) + '/' + total + ' — ') : '') + file.name;
      progBar.style.width = Math.round((i / total) * 100) + '%';

      var fd = new FormData();
      fd.append('_csrf', getCsrf());
      fd.append('_action', 'upload');
      fd.append('file', file);

      fetch(uploadUrl, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        redirect: 'follow'
      })
      .then(function() { i++; uploadNext(); })
      .catch(function() {
        showToast('Błąd połączenia: ' + file.name, false);
        i++; uploadNext();
      });
    }

    uploadNext();
  }
})();
</script>
<?php endif; ?>

<?= DocRegistry::assets() ?>
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
