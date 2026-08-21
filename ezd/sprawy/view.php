<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd_kopia.php';
require_once dirname(dirname(__DIR__)) . '/includes/mail_queue.php';
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
        $custom_name = trim($_POST['custom_name'] ?? '');
        $new_zal_id = null;
        $err = ezd_upload('file', $id, $user_id, null, null, null, null, $grupa_id, $custom_name ?: null, $new_zal_id);
        $msg = $err ?: 'Plik dodany do repozytorium koszulki.';
        if (!$err && $new_zal_id && !empty($_POST['convert_pdf'])) {
            $conv = ezd_convert_to_pdf($new_zal_id, $user_id);
            $msg .= $conv['ok'] ? ' Utworzono też wersję PDF.' : (' Konwersja na PDF nie powiodła się: ' . $conv['error']);
        }
        flash_set($err ? 'error' : 'success', $msg);
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
/* ── Slim metadata bar ───────────────────── */
.sp-meta-bar{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:.7rem 1.1rem;margin-bottom:1rem;display:flex;align-items:center;gap:1.2rem;flex-wrap:wrap}
.sp-meta-field{display:flex;flex-direction:column;min-width:0}
.sp-meta-label{font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;white-space:nowrap}
.sp-meta-val{font-size:.82rem;font-weight:600;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sp-meta-val.overdue{color:#dc2626}
.sp-meta-divider{width:1px;height:2rem;background:#e2e8f0;flex-shrink:0}
.sp-meta-actions{margin-left:auto;display:flex;gap:.4rem;flex-wrap:wrap;flex-shrink:0}

/* ── Akta sprawy ─────────────────────────── */
.sp-akta-hdr{display:flex;align-items:center;gap:.75rem;padding:.7rem 1rem;background:#fff;border:1px solid #e2e8f0;border-radius:10px 10px 0 0;border-bottom:none}
.sp-akta-title{font-size:.9rem;font-weight:700;color:#1e293b}
.sp-akta-count{font-size:.75rem;color:#94a3b8;font-weight:400}
.sp-akta-body{background:#fff;border:1px solid #e2e8f0;border-radius:0 0 10px 10px;overflow:hidden;margin-bottom:1rem}
.sp-file-row{display:flex;align-items:center;gap:.6rem;padding:.55rem 1rem;border-bottom:1px solid #f8fafc;font-size:.8rem}
.sp-file-row:last-child{border-bottom:none}
.sp-file-row:hover{background:#f8fafc}

/* ── Tabs EZD RP style ────────────────────── */
.sp-tabs{border-bottom:2px solid #e2e8f0;margin-bottom:0;overflow-x:auto;flex-wrap:nowrap;white-space:nowrap}
.sp-tabs .nav-link{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#64748b;border:none;border-bottom:3px solid transparent;border-radius:0;padding:.6rem .85rem;white-space:nowrap}
.sp-tabs .nav-link.active,.sp-tabs .nav-link:hover{color:#1e3a6e;border-bottom-color:#1e3a6e;background:none}
.sp-tab-pane{background:#fff;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 10px 10px;padding:1rem}

/* ── Dekretacja rows ──────────────────────── */
.dekr-row{display:flex;align-items:flex-start;gap:.65rem;padding:.5rem 0;border-bottom:1px solid #f8fafc;font-size:.8rem}
.dekr-row:last-child{border-bottom:none}

/* ── Stepper ──────────────────────────────── */
.ezd-stepper{display:flex;align-items:flex-start;gap:.25rem;overflow-x:auto;padding:.25rem 0}
.ezd-step{flex:1 1 0;min-width:64px;text-align:center;position:relative}
.ezd-step::before{content:'';position:absolute;top:14px;left:-50%;width:100%;height:2px;background:#e2e8f0;z-index:0}
.ezd-step:first-child::before{display:none}
.ezd-step-dot{position:relative;z-index:1;width:30px;height:30px;border-radius:50%;margin:0 auto .35rem;display:flex;align-items:center;justify-content:center;background:#e2e8f0;color:#94a3b8;font-size:.85rem;border:2px solid #fff;box-shadow:0 0 0 1px #e2e8f0}
.ezd-step-lbl{font-size:.66rem;color:#94a3b8;line-height:1.15}
.ezd-step-done .ezd-step-dot{background:#22c55e;color:#fff;box-shadow:0 0 0 1px #22c55e}
.ezd-step-done::before{background:#22c55e}
.ezd-step-done .ezd-step-lbl{color:#16a34a}
.ezd-step-current .ezd-step-dot{background:#1e3a6e;color:#fff;box-shadow:0 0 0 3px #bfdbfe}
.ezd-step-current .ezd-step-lbl{color:#1e3a6e;font-weight:700}

/* ── Timeline pisma ──────────────────────── */
.tl-wrap{position:relative;padding-left:2rem}
.tl-wrap::before{content:'';position:absolute;left:.55rem;top:0;bottom:0;width:2px;background:#e2e8f0;border-radius:2px}
.tl-item{position:relative;margin-bottom:1rem}
.tl-dot{position:absolute;left:-1.45rem;top:.3rem;width:14px;height:14px;border-radius:50%;border:2.5px solid #fff;box-shadow:0 0 0 2px #2563eb;background:#2563eb}
.tl-dot.pismo-in{background:#0891b2;box-shadow:0 0 0 2px #0891b2}
.tl-dot.pismo-out{background:#2563eb;box-shadow:0 0 0 2px #2563eb}
.tl-dot.pismo-int{background:#94a3b8;box-shadow:0 0 0 2px #94a3b8}
.tl-card{background:#fff;border:1.5px solid #e2e8f0;border-radius:10px;padding:.7rem 1rem;text-decoration:none;color:inherit;display:block;transition:border-color .15s}
.tl-card:hover{border-color:#93c5fd}
.tl-card.pismo-in{border-left:3px solid #0891b2}
.tl-card.pismo-out{border-left:3px solid #2563eb}
.tl-syg{font-family:monospace;font-size:.7rem;color:#64748b;font-weight:600}
.tl-title{font-weight:700;font-size:.86rem;color:#1e293b;margin:.1rem 0}
.tl-meta{font-size:.7rem;color:#94a3b8;display:flex;flex-wrap:wrap;gap:.3rem .7rem}

/* ── Pasek postępu etapu obiegu ─────────────── */
.sp-etap-bar{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:.55rem 1.1rem;margin-bottom:.75rem}

/* ── Drag-and-Drop pliki koszulki ────────────── */
.sp-dz-overlay{position:absolute;inset:0;background:rgba(37,99,235,.08);border:2.5px dashed #2563eb;border-radius:10px;display:none;align-items:center;justify-content:center;z-index:50;pointer-events:none;backdrop-filter:blur(1px)}
.sp-dz-overlay.active{display:flex}
.sp-dz-msg{text-align:center;color:#2563eb;font-size:.95rem;font-weight:700;pointer-events:none;padding:1.5rem;text-shadow:0 1px 3px rgba(255,255,255,.8)}
.sp-dz-progress{position:fixed;bottom:1.5rem;right:1.5rem;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:.8rem 1.1rem;box-shadow:0 4px 24px rgba(0,0,0,.13);z-index:9999;min-width:260px;font-size:.82rem;display:none}
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
<div class="alert alert-secondary d-flex align-items-center gap-2 py-2 mb-2" style="font-size:.82rem">
  <i class="bi bi-lock-fill flex-shrink-0"></i>
  <span>Koszulka <strong>zamknięta</strong> — dodawanie treści i edycja są zablokowane.
  <?php if(!empty($sprawa['close_reason'])): ?> Powód: <em><?= h($sprawa['close_reason']) ?></em>.<?php endif; ?>
  <?php if(is_admin()): ?><a href="<?= APP_URL ?>/ezd/sprawy/edit.php?id=<?= $id ?>" class="ms-2">Przywróć</a><?php endif; ?></span>
</div>
<?php endif; ?>

<!-- ══ Nagłówek koszulki ═══════════════════════════════════════════════════════ -->
<div class="d-flex align-items-start gap-2 mb-1 flex-wrap">
  <code class="bg-white px-2 py-0 rounded fw-bold border" style="font-size:.82rem;color:#1e3a6e"><?= h($sprawa['znak_sprawy']) ?></code>
  <?= ezd_status_badge_sprawa($sprawa['status']) ?>
  <?= ezd_priority_badge($sprawa['priority']) ?>
  <?php if(!empty($sprawa['ciagla'])): ?><span class="badge bg-info bg-opacity-15 text-info border border-info" style="font-size:.67rem"><i class="bi bi-infinity me-1"></i>Ciągła</span><?php endif; ?>
  <?php if($sprawa['parent_id']): ?><span class="badge bg-light text-muted border" style="font-size:.67rem"><i class="bi bi-diagram-3 me-1"></i>Podkoszulka</span><?php endif; ?>
</div>
<h1 class="h5 fw-bold mb-2" style="color:#0f172a"><?= h($sprawa['title']) ?></h1>

<!-- Metadata bar -->
<div class="sp-meta-bar">
  <div class="sp-meta-field">
    <span class="sp-meta-label">Prowadzący</span>
    <span class="sp-meta-val"><?= $sprawa['owner_name'] ? h($sprawa['owner_name']) : '—' ?></span>
  </div>
  <div class="sp-meta-divider"></div>
  <div class="sp-meta-field">
    <span class="sp-meta-label">Wykaz akt</span>
    <span class="sp-meta-val"><a href="<?= APP_URL ?>/ezd/teczki/view.php?id=<?= $sprawa['teczka_id'] ?>" class="text-decoration-none fw-semibold text-dark"><?= h($sprawa['teczka_symbol']) ?></a></span>
  </div>
  <div class="sp-meta-divider"></div>
  <div class="sp-meta-field">
    <span class="sp-meta-label"><i class="bi bi-calendar3 me-1"></i>Data wszczęcia</span>
    <span class="sp-meta-val"><?= date('d.m.Y', strtotime($sprawa['created_at'])) ?></span>
  </div>
  <?php if(!empty($sprawa['ciagla'])): ?>
  <div class="sp-meta-divider"></div>
  <div class="sp-meta-field">
    <span class="sp-meta-label"><i class="bi bi-calendar-event me-1"></i>Termin</span>
    <span class="sp-meta-val text-info"><i class="bi bi-infinity me-1"></i>stale otwarta</span>
  </div>
  <?php elseif($sprawa['deadline']): ?>
  <div class="sp-meta-divider"></div>
  <div class="sp-meta-field">
    <span class="sp-meta-label"><i class="bi bi-calendar-event me-1"></i>Termin realizacji</span>
    <span class="sp-meta-val <?= $sprawa['deadline']<date('Y-m-d')&&!$is_closed?'overdue':'' ?>">
      <?= date_pl($sprawa['deadline']) ?>
      <?php if($sprawa['deadline']<date('Y-m-d')&&!$is_closed): ?><i class="bi bi-exclamation-circle-fill text-danger ms-1"></i><?php endif; ?>
    </span>
  </div>
  <?php endif; ?>

  <div class="sp-meta-actions">
    <?php if($can_act): ?>
    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#dekrModal">
      <i class="bi bi-person-lines-fill me-1"></i>Przekaż
    </button>
    <button type="button" class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#pismoModal">
      <i class="bi bi-envelope-plus me-1"></i>Pismo
    </button>
    <?php if($signed_pdfs): ?>
    <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#sprawaSendMailModal">
      <i class="bi bi-send me-1"></i>Wyślij mailem
    </button>
    <?php endif; ?>
    <?php endif; ?>
    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#notatkiModal">
      <i class="bi bi-sticky me-1"></i>Notatki<?php if($notatki): ?><span class="badge rounded-pill bg-secondary ms-1"><?= count($notatki) ?></span><?php endif; ?>
    </button>
    <a href="<?= APP_URL ?>/ezd/sprawy/print.php?id=<?= $id ?>" class="btn btn-sm btn-outline-dark">
      <i class="bi bi-printer me-1"></i>Drukuj
    </a>
    <?php if(!$mini): ?>
    <a href="<?= APP_URL ?>/ezd/sprawy/metryka.php?id=<?= $id ?>" class="btn btn-sm btn-outline-primary">
      <i class="bi bi-clipboard-check me-1"></i>Metryka
    </a>
    <?php endif; ?>
    <?php if($can_edit_case): ?>
    <a href="<?= APP_URL ?>/ezd/sprawy/przerejestruj.php?id=<?= $id ?>" class="btn btn-sm btn-outline-primary" title="Przerejestruj (AI)">
      <i class="bi bi-stars me-1"></i>Przerejestruj
    </a>
    <a href="<?= APP_URL ?>/ezd/sprawy/edit.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
    <?php endif; ?>
    <?php if($can_act): ?>
    <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#zamknijModal" title="Zamknij koszulkę">
      <i class="bi bi-lock me-1"></i>Zamknij
    </button>
    <?php endif; ?>
  </div>
</div>

<?php if(!$mini && $wf_steps): ?>
<div class="sp-etap-bar mb-2">
  <div class="ezd-stepper">
    <?php foreach ($wf_steps as $i => $st):
      $state = $i < $cur_idx ? 'done' : ($i === $cur_idx ? 'current' : 'todo'); ?>
    <div class="ezd-step ezd-step-<?= $state ?>">
      <div class="ezd-step-dot"><i class="bi <?= $state === 'done' ? 'bi-check-lg' : ($st['icon'] ?: 'bi-record-circle') ?>"></i></div>
      <div class="ezd-step-lbl"><?= h($st['label']) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Opis koszulki -->
<?php if($sprawa['description']): ?>
<div class="mb-3 text-muted" style="font-size:.84rem;line-height:1.55"><?= nl2br(h($sprawa['description'])) ?></div>
<?php endif; ?>

<!-- ══ Dokumenty w koszulce ══════════════════════════════════════════════════════ -->
<div id="files">
  <div class="sp-akta-hdr">
    <div class="flex-grow-1">
      <span class="sp-akta-title"><i class="bi bi-folder2-open me-1"></i>Dokumenty w koszulce</span>
      <span class="sp-akta-count">(<?= count($zalaczniki) ?>)</span>
    </div>
    <?php if($can_act): ?>
    <div class="d-flex gap-2 flex-wrap">
      <?php if($zalaczniki): ?>
      <button class="btn btn-sm btn-outline-warning" type="button" data-bs-toggle="modal" data-bs-target="#przekazDokModal"><i class="bi bi-send-check me-1"></i>Przekaż</button>
      <?php endif; ?>
      <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#new-grupa"><i class="bi bi-folder-plus me-1"></i>Nowa grupa</button>
      <a href="<?= APP_URL ?>/ezd/sprawy/spinacz.php?sprawa_id=<?= $id ?>" class="btn btn-sm btn-outline-info"><i class="bi bi-paperclip me-1"></i>Spinacz</a>
      <div class="dropdown">
        <button class="btn btn-sm btn-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
          <i class="bi bi-plus-lg me-1"></i>DODAJ
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li>
            <label class="dropdown-item" style="cursor:pointer">
              <i class="bi bi-upload me-2"></i>Prześlij plik
              <input type="file" class="d-none" id="quick-upload-trigger"
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
    <input type="file" name="file" id="ezd-quick-upload-file">
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
          <input type="checkbox" class="form-check-input flex-shrink-0" style="margin:0">
          <i class="bi <?= ezd_file_icon($z['original_name']) ?> fs-5 flex-shrink-0 text-muted"></i>
          <div class="flex-grow-1 overflow-hidden">
            <a href="<?= APP_URL ?>/ezd/serve.php?id=<?= $z['id'] ?>"
               class="text-decoration-none fw-semibold text-truncate d-block<?= $zext === 'pdf' ? ' ezd-pdf-btn' : '' ?>"
               <?php if ($zext === 'pdf'): ?>data-url="<?= APP_URL ?>/ezd/serve.php?id=<?= $z['id'] ?>" data-name="<?= h($z['original_name']) ?>"<?php endif; ?>>
              <?= h($z['original_name']) ?>
              <?php if(!empty($sig['signed'])): ?><span class="badge bg-success bg-opacity-15 text-success border border-success ms-1" style="font-size:.6rem"><i class="bi bi-patch-check-fill me-1"></i>Podpis el.</span><?php endif; ?>
              <?php if($z['wersja']>1): ?><span class="badge bg-warning text-dark ms-1" style="font-size:.6rem">v<?= $z['wersja'] ?></span><?php endif; ?>
            </a>
            <div class="text-muted" style="font-size:.68rem"><?= ezd_filesize($z['file_size']) ?> · <?= h($z['uploader'] ?? '—') ?></div>
          </div>
          <div class="text-nowrap text-muted" style="font-size:.72rem"><?= date('d.m.Y', strtotime($z['uploaded_at'])) ?></div>
          <div class="d-flex gap-1 flex-shrink-0">
            <?php if(!empty($sig['signed'])): ?>
            <button type="button" class="btn btn-xs btn-outline-success btn-sm ezd-sig-btn"
              title="Weryfikuj podpis elektroniczny"
              data-zal="<?= (int)$z['id'] ?>" data-file="<?= h($z['original_name']) ?>"
              data-type="<?= h((string)$sig['type']) ?>" data-signer="<?= h((string)($sig['signer'] ?? '')) ?>"
              data-date="<?= h((string)($sig['signed_at'] ?? '')) ?>" data-reason="<?= h((string)($sig['reason'] ?? '')) ?>"
              data-location="<?= h((string)($sig['location'] ?? '')) ?>" data-note="<?= h((string)($sig['note'] ?? '')) ?>">
              <i class="bi bi-patch-check"></i>
            </button>
            <?php if($can_act && $zext === 'pdf'): ?>
            <button type="button" class="btn btn-xs btn-outline-info btn-sm"
                    title="Utwórz pismo z tego pliku"
                    data-bs-toggle="modal" data-bs-target="#pismoFromZalModal"
                    data-zal="<?= (int)$z['id'] ?>" data-name="<?= h($z['original_name']) ?>">
              <i class="bi bi-envelope-plus"></i>
            </button>
            <?php endif; ?>
            <?php endif; ?>
            <?php if($can_act && $grupy): ?>
            <button type="button" class="btn btn-xs btn-outline-secondary btn-sm ezd-move-grupa-btn"
                    title="Przenieś do grupy"
                    data-bs-toggle="modal" data-bs-target="#zalMoveGroupModal"
                    data-zal="<?= (int)$z['id'] ?>" data-name="<?= h($z['original_name']) ?>" data-grupa="<?= (int)($z['grupa_id'] ?? 0) ?>">
              <i class="bi bi-folder-symlink"></i>
            </button>
            <?php endif; ?>
            <?php if (in_array($zext, EZD_OFFICE_ONLINE_EXT, true)): ?>
            <a href="<?= APP_URL ?>/ezd/office_online.php?id=<?= $z['id'] ?>" target="_blank" rel="noopener" class="btn btn-xs btn-outline-primary btn-sm" title="Otwórz w Office Online"><i class="bi bi-microsoft"></i></a>
            <?php if (!empty($z['sp_web_url'])): $_s = in_array($zext, ['xls','xlsx'], true) ? 'ms-excel' : 'ms-word'; ?>
            <a href="<?= h($_s) ?>:ofe|u|<?= rawurlencode($z['sp_web_url']) ?>" class="btn btn-xs btn-outline-primary btn-sm" title="Otwórz w aplikacji desktop (Word/Excel)"><i class="bi bi-window-desktop"></i></a>
            <button type="button" class="btn btn-xs btn-outline-success btn-sm ezd-oop-btn" title="Pobierz zmiany z Office Online"
                    data-bs-toggle="modal" data-bs-target="#officeOnlinePullModal"
                    data-zal="<?= $z['id'] ?>" data-name="<?= h($z['original_name']) ?>"><i class="bi bi-cloud-arrow-down"></i></button>
            <?php endif; ?>
            <?php elseif (!empty($z['sp_web_url'])): ?>
            <a href="<?= h($z['sp_web_url']) ?>" target="_blank" rel="noopener" class="btn btn-xs btn-outline-secondary btn-sm" title="Otwórz na SharePoint"><i class="bi bi-cloud-check"></i></a>
            <?php endif; ?>
            <?php if ($zext === 'pdf'): ?>
            <a href="<?= APP_URL ?>/ezd/serve.php?id=<?= $z['id'] ?>" class="btn btn-xs btn-outline-secondary btn-sm ezd-pdf-btn"
               title="Podgląd PDF"
               data-url="<?= APP_URL ?>/ezd/serve.php?id=<?= $z['id'] ?>" data-name="<?= h($z['original_name']) ?>"><i class="bi bi-eye"></i></a>
            <?php if($can_act): ?>
            <button type="button" class="btn btn-xs btn-sm ezd-rsign-btn flex-shrink-0"
                    title="Podpisz rSign (kwalifikowany PAdES)"
                    style="background:#6d28d9;border-color:#6d28d9;color:#fff"
                    data-zal-id="<?= (int)$z['id'] ?>"
                    data-zal-name="<?= h($z['original_name']) ?>">
              <i class="bi bi-pen-fill me-1"></i>rSign
            </button>
            <?php endif; ?>
            <?php endif; ?>
            <?php if (in_array($zext, ['eml', 'msg'], true)): ?>
            <button type="button" class="btn btn-xs btn-outline-primary btn-sm ezd-email-btn"
                    title="Podgląd wiadomości e-mail"
                    data-id="<?= (int)$z['id'] ?>" data-name="<?= h($z['original_name']) ?>"><i class="bi bi-envelope-open"></i></button>
            <?php endif; ?>
            <?php if ($can_act && in_array($zext, EZD_PDF_CONVERTIBLE_EXT, true)): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Przekonwertować na PDF?')">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="convert_pdf">
              <input type="hidden" name="zal_id" value="<?= $z['id'] ?>">
              <button class="btn btn-xs btn-outline-danger btn-sm" title="Konwertuj na PDF"><i class="bi bi-filetype-pdf"></i></button>
            </form>
            <?php endif; ?>
            <?php if(module_enabled('obiegi_enabled')): ?>
            <a href="<?= APP_URL ?>/obiegi/new.php?ezd_sprawa_id=<?= $id ?>&ezd_zalacznik_id=<?= $z['id'] ?>"
               class="btn btn-xs btn-outline-primary btn-sm" title="Uruchom obieg dokumentu"><i class="bi bi-diagram-2"></i></a>
            <?php endif; ?>
            <?= ezd_kopia_btn('zalacznik', (int)$z['id'], $z['original_name'], 'icon', 'btn-xs btn-sm') ?>
            <a href="<?= APP_URL ?>/ezd/serve.php?id=<?= $z['id'] ?>&dl=1" class="btn btn-xs btn-outline-secondary btn-sm" title="Pobierz plik"><i class="bi bi-download"></i></a>
            <?php if($can_act): ?>
            <button type="button" class="btn btn-xs btn-outline-warning btn-sm ezd-sign-req-btn"
                    title="Przekaż do podpisu"
                    data-zal-id="<?= (int)$z['id'] ?>"
                    data-zal-name="<?= h($z['original_name']) ?>">
              <i class="bi bi-pen"></i>
            </button>
            <form method="post" class="d-inline" onsubmit="return confirm('Usunąć plik?')">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="del_file">
              <input type="hidden" name="zal_id" value="<?= $z['id'] ?>">
              <button class="btn btn-xs btn-outline-danger btn-sm" title="Usuń plik"><i class="bi bi-trash3"></i></button>
            </form>
            <?php endif; ?>
          </div>
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
        <input type="file" id="ezd-upload-file" name="file" class="form-control form-control-sm" style="max-width:260px"
               accept=".pdf,.doc,.docx,.xls,.xlsx,.odt,.ods,.pptx,.png,.jpg,.jpeg,.zip,.txt,.csv,.eml,.msg" required>
        <input type="text" name="custom_name" class="form-control form-control-sm" style="max-width:220px"
               placeholder="Własna nazwa (opcjonalnie)" maxlength="200">
        <?php if($grupy): ?>
        <select name="grupa_id" class="form-select form-select-sm" style="max-width:180px">
          <option value="0">— bez grupy —</option>
          <?php foreach($grupy as $g): ?><option value="<?= $g['id'] ?>"><?= h($g['nazwa']) ?></option><?php endforeach; ?>
        </select>
        <?php endif; ?>
        <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-upload me-1"></i>Dodaj do koszulki</button>
        <small class="text-muted">Maks. 25 MB</small>
      </div>
      <div class="form-check mt-2 d-none" id="ezd-upload-convert-wrap">
        <input type="checkbox" class="form-check-input" name="convert_pdf" value="1" id="ezd-upload-convert">
        <label class="form-check-label" for="ezd-upload-convert" style="font-size:.78rem">Przekonwertować też na PDF?</label>
      </div>
    </form>
    <script>
    (function(){
      var inp = document.getElementById('ezd-upload-file'), wrap = document.getElementById('ezd-upload-convert-wrap'), chk = document.getElementById('ezd-upload-convert');
      if (!inp || !wrap || !chk) return;
      inp.addEventListener('change', function(){ var e=inp.value.split('.').pop().toLowerCase(); var ok=['doc','docx','xls','xlsx'].includes(e); wrap.classList.toggle('d-none',!ok); if(!ok)chk.checked=false; });
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
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-meta"><i class="bi bi-info-circle me-1"></i>METADANE</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-uczestnicy"><i class="bi bi-people me-1"></i>UCZESTNICY<?php if($shares||$strony): ?> <span class="badge bg-info ms-1"><?= count($shares)+count($strony) ?></span><?php endif; ?></a></li>
  <?php if(!$mini): ?><li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-przebieg"><i class="bi bi-diagram-2 me-1"></i>PRZEBIEG SPRAWY</a></li><?php endif; ?>
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
      <div class="d-flex align-items-start gap-2 flex-wrap">
        <!-- Quick-pick: kierunek × medium -->
        <div class="d-flex flex-column gap-2">
          <?php
          $qk = ['przychodzace' => ['↓ Przychodzące','info'],
                 'wychodzace'   => ['↑ Wychodzące','primary']];
          $qm = ['papier'=>['bi-file-earmark-text','Papier'],
                 'email' =>['bi-at','E-mail'],
                 'epuap' =>['bi-mailbox2','eDoręczenia'],
                 'faks'  =>['bi-printer','Faks']];
          foreach($qk as $kv=>[$klabel,$kclass]): ?>
          <div class="d-flex align-items-center gap-2">
            <span class="badge bg-<?= $kclass ?> text-nowrap fw-semibold" style="font-size:.72rem;min-width:100px;text-align:center"><?= $klabel ?></span>
            <div class="btn-group btn-group-sm" role="group" aria-label="Nowe pismo <?= $klabel ?>">
              <?php foreach($qm as $mv=>[$micon,$mlabel]): ?>
              <button type="button" class="btn btn-outline-secondary ezd-pm-pick"
                      data-kierunek="<?= $kv ?>" data-medium="<?= $mv ?>"
                      title="<?= $klabel ?> · <?= $mlabel ?>">
                <i class="bi <?= $micon ?>" aria-hidden="true"></i> <?= $mlabel ?>
              </button>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <!-- Notatka wewnętrzna -->
        <button type="button" class="btn btn-sm btn-outline-secondary ezd-notatka-btn align-self-start">
          <i class="bi bi-sticky me-1" aria-hidden="true"></i>Notatka
        </button>
        <!-- Generator pisma z szablonu -->
        <a href="<?= APP_URL ?>/ezd/pisma/generator.php?sprawa_id=<?= $id ?>"
           class="btn btn-sm btn-outline-primary align-self-start">
          <i class="bi bi-file-earmark-word me-1" aria-hidden="true"></i>Generuj DOCX
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
            <span class="ms-auto"><?= date('d.m.Y', strtotime($item['_date'])) ?></span>
          </div>
        </div>
        <?php else: ?>
        <a href="<?= $href ?>" class="tl-card <?= $card_cls ?>">
          <?php if(!empty($item['sygnatura'])): ?><div class="tl-syg"><?= h($item['sygnatura']) ?></div><?php endif; ?>
          <div class="tl-title"><?= h(mb_substr($item['title'],0,70)) ?></div>
          <div class="tl-meta">
            <span class="badge bg-warning text-dark" style="font-size:.62rem">Umowa</span>
            <?php if(!empty($item['nr_umowy'])): ?><span class="font-monospace"><?= h($item['nr_umowy']) ?></span><?php endif; ?>
            <span class="ms-auto"><?= date('d.m.Y', strtotime($item['_date'])) ?></span>
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

  <!-- METADANE -->
  <div class="tab-pane fade sp-tab-pane" id="tab-meta">
    <div class="row g-3">
      <div class="col-md-6">
        <dl class="row mb-0" style="font-size:.84rem;row-gap:.4rem">
          <dt class="col-5 text-muted fw-normal">Znak koszulki</dt><dd class="col-7 mb-0 font-monospace fw-bold"><?= h($sprawa['znak_sprawy']) ?></dd>
          <dt class="col-5 text-muted fw-normal">Wykaz akt</dt><dd class="col-7 mb-0"><a href="<?= APP_URL ?>/ezd/teczki/view.php?id=<?= $sprawa['teczka_id'] ?>" class="text-decoration-none fw-semibold"><?= h($sprawa['teczka_symbol']) ?></a></dd>
          <dt class="col-5 text-muted fw-normal">Właściciel</dt><dd class="col-7 mb-0"><?= $sprawa['owner_name'] ? h($sprawa['owner_name']) : '<span class="text-muted">—</span>' ?></dd>
          <dt class="col-5 text-muted fw-normal">Status</dt><dd class="col-7 mb-0"><?= ezd_status_badge_sprawa($sprawa['status']) ?></dd>
          <dt class="col-5 text-muted fw-normal">Priorytet</dt><dd class="col-7 mb-0"><?= ezd_priority_badge($sprawa['priority']) ?></dd>
          <dt class="col-5 text-muted fw-normal">Etap obiegu</dt><dd class="col-7 mb-0"><?= ezd_etap_badge($sprawa['etap'] ?? 'wszczeta') ?></dd>
        </dl>
      </div>
      <div class="col-md-6">
        <dl class="row mb-0" style="font-size:.84rem;row-gap:.4rem">
          <dt class="col-5 text-muted fw-normal">Termin</dt>
          <dd class="col-7 mb-0 <?= !empty($sprawa['ciagla'])?'text-info':($sprawa['deadline']&&$sprawa['deadline']<date('Y-m-d')&&!$is_closed?'text-danger fw-bold':'') ?>">
            <?= !empty($sprawa['ciagla']) ? '<i class="bi bi-infinity me-1"></i>stale otwarta' : ($sprawa['deadline'] ? date_pl($sprawa['deadline']) : '—') ?>
          </dd>
          <dt class="col-5 text-muted fw-normal">Otwarto</dt><dd class="col-7 mb-0"><?= date_pl($sprawa['created_at']) ?></dd>
          <?php if($sprawa['closed_at']): ?><dt class="col-5 text-muted fw-normal">Zamknięto</dt><dd class="col-7 mb-0"><?= date_pl($sprawa['closed_at']) ?></dd><?php endif; ?>
          <?php if(!empty($sprawa['close_reason'])): ?><dt class="col-5 text-muted fw-normal">Powód zamknięcia</dt><dd class="col-7 mb-0 text-muted" style="white-space:pre-line;font-size:.79rem"><?= h($sprawa['close_reason']) ?></dd><?php endif; ?>
          <dt class="col-5 text-muted fw-normal">Pliki</dt><dd class="col-7 mb-0"><?= count($zalaczniki) ?></dd>
          <dt class="col-5 text-muted fw-normal">Pisma</dt><dd class="col-7 mb-0"><?= count(array_filter($timeline,fn($t)=>$t['_typ']==='pismo')) ?></dd>
          <?php if($sprawa['description']): ?>
          <dt class="col-5 text-muted fw-normal">Opis</dt><dd class="col-7 mb-0 text-muted" style="white-space:pre-line;font-size:.79rem"><?= h($sprawa['description']) ?></dd>
          <?php endif; ?>
        </dl>
      </div>
    </div>
    <?php if($can_edit_case): ?>
    <div class="mt-3 pt-3 border-top d-flex gap-2 flex-wrap">
      <a href="<?= APP_URL ?>/ezd/sprawy/edit.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil me-1"></i>Edytuj metadane</a>
      <?php if(!$sprawa['parent_id']): ?>
      <a href="<?= APP_URL ?>/ezd/sprawy/add.php?parent_id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-diagram-3 me-1"></i>Dodaj podkoszulkę</a>
      <?php endif; ?>
      <a href="<?= APP_URL ?>/ezd/sprawy/przerejestruj.php?id=<?= $id ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-stars me-1"></i>Przerejestruj (AI)</a>
    </div>
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
  </div>

  <!-- PRZEBIEG SPRAWY: stepper + log -->
  <?php if(!$mini): ?>
  <div class="tab-pane fade sp-tab-pane" id="tab-przebieg">
    <?php if($wf_steps): ?>
    <div class="mb-3">
      <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
        <span class="fw-semibold" style="font-size:.82rem">Etap obiegu:</span>
        <?= ezd_etap_badge($cur_etap) ?>
        <?php if($wf_custom): ?><span class="badge bg-info bg-opacity-15 text-info border border-info" style="font-size:.6rem">wg Wykazu <?= h($sprawa['teczka_symbol']) ?></span><?php endif; ?>
        <?php if(is_admin()): ?><a href="<?= APP_URL ?>/admin/ezd_workflows.php?jrwa_id=<?= (int)($sprawa['jrwa_id'] ?? 0) ?>" class="text-muted" style="font-size:.72rem"><i class="bi bi-pencil me-1"></i>Edytuj workflow</a><?php endif; ?>
      </div>
      <div class="ezd-stepper">
        <?php foreach ($wf_steps as $i => $st):
          $state = $i < $cur_idx ? 'done' : ($i === $cur_idx ? 'current' : 'todo'); ?>
        <div class="ezd-step ezd-step-<?= $state ?>">
          <div class="ezd-step-dot"><i class="bi <?= $state==='done'?'bi-check-lg':($st['icon'] ?: 'bi-record-circle') ?>"></i></div>
          <div class="ezd-step-lbl"><?= h($st['label']) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php if($can_act): ?>
      <div class="d-flex gap-2 flex-wrap align-items-center mt-3 pt-3 border-top">
        <?php if($next_step): ?>
        <form method="post" class="d-inline">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="set_etap">
          <input type="hidden" name="etap" value="<?= h($next_etap) ?>">
          <button class="btn btn-sm btn-primary"><i class="bi <?= h($next_step['icon'] ?: 'bi-arrow-right') ?> me-1"></i>Dalej: <?= h($next_step['label']) ?> →</button>
        </form>
        <?php endif; ?>
        <form method="post" class="d-inline d-flex gap-1">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="set_etap">
          <select name="etap" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
            <option disabled selected>Przejdź do etapu…</option>
            <?php foreach($wf_steps as $st): ?>
            <option value="<?= h($st['key']) ?>" <?= $st['key']===$cur_etap?'disabled':'' ?>><?= h($st['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
        <div class="ms-auto">
          <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#zamknijModal">
            <i class="bi bi-lock me-1"></i>Zamknij koszulkę
          </button>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Historia operacji -->
    <div>
      <div class="fw-semibold mb-2" style="font-size:.8rem;color:#374151"><i class="bi bi-shield-check me-1"></i>Historia operacji</div>
      <div class="border rounded" style="max-height:320px;overflow-y:auto">
        <table class="table table-sm mb-0" style="font-size:.74rem">
          <thead class="table-light sticky-top"><tr><th>Czas</th><th>Użytkownik</th><th>Operacja</th><th>Szczegóły</th></tr></thead>
          <tbody>
          <?php foreach ($log_entries as $l): ?>
          <tr>
            <td class="text-nowrap text-muted"><?= date('d.m.Y H:i', strtotime($l['created_at'])) ?></td>
            <td><?= h($l['user_name'] ?? '—') ?></td>
            <td><code style="font-size:.66rem"><?= h($l['action']) ?></code></td>
            <td><?= h(mb_substr($l['details'],0,80)) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if(!$log_entries): ?><tr><td colspan="4" class="text-center text-muted py-2">Brak wpisów</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>

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
  if (hash === '#tab-pisma' || hash === '#tab-zadania' || hash === '#tab-uczestnicy' || hash === '#tab-meta') {
    tabTarget = hash.slice(1);
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
              <option value="<?= $mval ?>" <?= $mval === 'email' ? 'selected' : '' ?>><?= h($mlabel) ?></option>
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

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
