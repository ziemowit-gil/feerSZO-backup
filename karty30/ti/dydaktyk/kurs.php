<?php
/**
 * karty30/ti/dydaktyk/kurs.php — Podgląd grupy (kierownik, nowe UI).
 *
 * Odpowiednik karty30/ti/course.php w panelu dydaktyka: uczestnicy (zapis/
 * wypis, indywidualne rozliczanie 9999, stały Zoom per kursant), stały link
 * Zoom kursu, coProwadzący. Lekcjami zarządza zakładka Zajęcia w panelu
 * (index.php?course=N&tab=lekcje); pełna edycja metadanych kursu i operacje
 * administracyjne zostają w module admina (course.php / index.php?edit=).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/zoom.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_price_changes.php';

$me = dyd_require();
if (!dyd_is_staff()) { header('Location: index.php'); exit; }   // ekran kierownika
$uid      = (int)$me['user_id'];
$dyd_name = (string)($me['name'] ?? '');
karty30_migrate();

/** Synchronizuje alternative_hosts Zoom dla kursu i wszystkich zapisów. */
function _dyd_zoom_sync_alt_hosts(int $course_id, array $course): void {
    if (!zoom_enabled()) return;
    try {
        $api    = new ZoomAPI();
        $emails = k30_ti_course_zoom_alt_hosts($course_id);
        if (!empty($course['zoom_meeting_id'])) $api->update_alternative_hosts((string)$course['zoom_meeting_id'], $emails);
        foreach (db_all("SELECT zoom_meeting_id FROM k30_ti_enrollments WHERE course_id=? AND zoom_meeting_id!=''", [$course_id]) as $e) {
            $api->update_alternative_hosts((string)$e['zoom_meeting_id'], $emails);
        }
    } catch (\Throwable $e) {}
}

// id kursu: podstawowo ?id=, awaryjnie ?course= (spójnie z index.php?course=N)
$id     = (int)($_GET['id'] ?? ($_GET['course'] ?? 0));
$course = $id ? k30_ti_course_get($id) : null;
if (!$course) {
    error_log('[dyd kurs.php] brak kursu — id=' . $id . ' uri=' . ($_SERVER['REQUEST_URI'] ?? '')
        . ' ref=' . ($_SERVER['HTTP_REFERER'] ?? '-'));
    flash_set('danger', $id > 0
        ? 'Kurs #' . $id . ' nie istnieje w bazie panelu.'
        : 'Nie przekazano identyfikatora kursu (id) — link był niepełny.');
    header('Location: index.php?tab=kursy'); exit;
}

/* ── POST ──────────────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['_op'] ?? '';
    if ($_cc_msg = ti_course_closed_guard($_POST, [(int)($_GET['id'] ?? 0)])) {
        flash_set('danger', $_cc_msg);
        header('Location: kurs.php?id=' . (int)($_GET['id'] ?? 0)); exit;
    }

    if ($op === 'enroll') {
        $cid  = (int)($_POST['client_id'] ?? 0);
        $rate = max(0, (float)str_replace(',', '.', $_POST['hourly_rate'] ?? '0'));
        $rate_on = max(0, (float)str_replace(',', '.', $_POST['hourly_rate_online'] ?? '0'));   // 0 = jak stacjonarna
        if ($cid) {
            try {
                db()->prepare(
                    "INSERT INTO k30_ti_enrollments (course_id,client_id,hourly_rate,hourly_rate_online,start_date,status)
                     VALUES (?,?,?,?,?,?)
                     ON CONFLICT(course_id,client_id) DO UPDATE SET hourly_rate=excluded.hourly_rate, hourly_rate_online=excluded.hourly_rate_online,
                         status='active', start_date=excluded.start_date"
                )->execute([$id, $cid, $rate, $rate_on, date('Y-m-d'), 'active']);
            } catch (\Throwable $e) {}
            flash_set('success', 'Uczestnik zapisany.');
        }
        header('Location: kurs.php?id=' . $id . '#uczestnicy'); exit;
    }

    if ($op === 'unenroll') {
        $cid = (int)($_POST['client_id'] ?? 0);
        db()->prepare("UPDATE k30_ti_enrollments SET status='inactive' WHERE course_id=? AND client_id=?")->execute([$id, $cid]);
        flash_set('success', 'Uczestnik wypisany.');
        header('Location: kurs.php?id=' . $id . '#uczestnicy'); exit;
    }

    // ── Zoom: link na poziomie kursu ─────────────────────────────────────────
    if ($op === 'gen_course_zoom' || $op === 'clear_course_zoom') {
        if (!zoom_enabled()) { flash_set('warning', 'Integracja Zoom nie jest skonfigurowana.'); header('Location: kurs.php?id=' . $id . '#zoom'); exit; }
        $api = new ZoomAPI();
        if (!empty($course['zoom_meeting_id'])) {
            $api->delete_meeting($course['zoom_meeting_id']);
            $api->log('delete', $course['zoom_meeting_id'], 'ok', 'clear before regenerate', $id, $uid);
        }
        if ($op === 'clear_course_zoom') {
            db()->prepare("UPDATE k30_ti_courses SET zoom_meeting_id='', default_meeting_url='', zoom_host_email='' WHERE id=?")->execute([$id]);
            $api->log('delete', $course['zoom_meeting_id'] ?? '', 'ok', 'cleared by kierownik', $id, $uid);
            flash_set('success', 'Link Zoom kursu usunięty.');
        } else {
            $altEmails = k30_ti_course_zoom_alt_hosts($id);
            $hostEmail = db_one("SELECT u.email FROM k30_ti_courses c JOIN users u ON u.id=c.instructor_id WHERE c.id=?", [$id])['email'] ?? '';
            if ($hostEmail !== '') {
                try { $api->validate_user_email($hostEmail); }
                catch (\RuntimeException $ex) {
                    flash_set('warning', $ex->getMessage() . ' Link Zoom zostanie utworzony bez alternative_hosts.');
                    $altEmails = ''; $hostEmail = '';
                }
            }
            try {
                $m = $api->create_meeting($course['name'], 'Kurs TI — stały link grupy', $altEmails);
                db()->prepare("UPDATE k30_ti_courses SET zoom_meeting_id=?, default_meeting_url=?, zoom_host_email=? WHERE id=?")
                   ->execute([$m['meeting_id'], $m['join_url'], $hostEmail, $id]);
                $api->log('create', $m['meeting_id'], 'ok', 'alt_hosts=' . $altEmails, $id, $uid);
                flash_set('success', 'Stały link Zoom kursu wygenerowany.');
            } catch (\RuntimeException $ex) {
                $api->log('create', '', 'error', $ex->getMessage(), $id, $uid);
                flash_set('danger', 'Błąd Zoom: ' . $ex->getMessage());
            }
        }
        header('Location: kurs.php?id=' . $id . '#zoom'); exit;
    }

    // ── Zoom: link per kursant ───────────────────────────────────────────────
    if ($op === 'gen_student_zoom' || $op === 'clear_student_zoom') {
        $cid = (int)($_POST['client_id'] ?? 0);
        $en  = $cid ? db_one("SELECT * FROM k30_ti_enrollments WHERE course_id=? AND client_id=?", [$id, $cid]) : null;
        if (!$en) { flash_set('danger', 'Uczestnik nie znaleziony.'); header('Location: kurs.php?id=' . $id . '#uczestnicy'); exit; }
        if (!zoom_enabled()) { flash_set('warning', 'Integracja Zoom nie jest skonfigurowana.'); header('Location: kurs.php?id=' . $id . '#uczestnicy'); exit; }
        $api = new ZoomAPI();
        if (!empty($en['zoom_meeting_id'])) $api->delete_meeting($en['zoom_meeting_id']);
        if ($op === 'clear_student_zoom') {
            db()->prepare("UPDATE k30_ti_enrollments SET zoom_meeting_id='', zoom_meeting_url='' WHERE course_id=? AND client_id=?")->execute([$id, $cid]);
            $api->log('delete', $en['zoom_meeting_id'] ?? '', 'ok', 'student clear client_id=' . $cid, $id, $uid);
            flash_set('success', 'Stały link Zoom uczestnika usunięty.');
        } else {
            $cname     = db_one("SELECT name FROM k30_clients WHERE id=?", [$cid])['name'] ?? (string)$cid;
            $altEmails = k30_ti_course_zoom_alt_hosts($id);
            $hostEmail = db_one("SELECT u.email FROM k30_ti_courses c JOIN users u ON u.id=c.instructor_id WHERE c.id=?", [$id])['email'] ?? '';
            if ($hostEmail !== '') {
                try { $api->validate_user_email($hostEmail); }
                catch (\RuntimeException $ex) { flash_set('warning', $ex->getMessage() . ' Link bez alternative_hosts.'); $altEmails = ''; }
            }
            try {
                $m = $api->create_meeting($course['name'] . ' — ' . $cname, 'Zajęcia TI', $altEmails);
                db()->prepare("UPDATE k30_ti_enrollments SET zoom_meeting_id=?, zoom_meeting_url=? WHERE course_id=? AND client_id=?")
                   ->execute([$m['meeting_id'], $m['join_url'], $id, $cid]);
                $api->log('create', $m['meeting_id'], 'ok', 'student client_id=' . $cid, $id, $uid);
                flash_set('success', 'Stały link Zoom wygenerowany dla uczestnika ' . $cname . '.');
            } catch (\RuntimeException $ex) {
                $api->log('create', '', 'error', $ex->getMessage(), $id, $uid);
                flash_set('danger', 'Błąd Zoom: ' . $ex->getMessage());
            }
        }
        header('Location: kurs.php?id=' . $id . '#uczestnicy'); exit;
    }

    // Indywidualny model rozliczania kursanta (override). model=0 → dziedziczy z kursu.
    if ($op === 'set_billing') {
        $cid   = (int)($_POST['client_id'] ?? 0);
        $model = (int)($_POST['billing_model'] ?? 0);
        if (!in_array($model, [0, 1, 2, 3], true)) $model = 0;
        $amount = max(0, (float)str_replace(',', '.', (string)($_POST['billing_amount'] ?? '0')));
        $rate   = max(0, (float)str_replace(',', '.', (string)($_POST['hourly_rate'] ?? '0')));
        $rate_on = max(0, (float)str_replace(',', '.', (string)($_POST['hourly_rate_online'] ?? '0')));
        if ($cid) {
            db()->prepare("UPDATE k30_ti_enrollments SET billing_model=?, billing_amount=?, hourly_rate=?, hourly_rate_online=?, pay_account=?, pay_title=?, pay_due_days=? WHERE course_id=? AND client_id=?")
               ->execute([$model, $amount, $rate, $rate_on, trim($_POST['pay_account'] ?? ''), trim($_POST['pay_title'] ?? ''),
                          ((int)($_POST['pay_due_days'] ?? 0)) ?: null, $id, $cid]);
            flash_set('success', $model > 0 ? 'Ustawiono indywidualny model rozliczania (kod 9999).' : 'Przywrócono model rozliczania kursu.');
        }
        header('Location: kurs.php?id=' . $id . '#uczestnicy'); exit;
    }

    // Zaplanowana zmiana ceny — grupowa (cały kurs) albo indywidualna (jeden
    // kursant), procentowo albo kwotowo, z zakresem dat i uzasadnieniem.
    // Patrz includes/ti_price_changes.php.
    if ($op === 'price_change_create') {
        require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_price_changes.php';
        $scope     = ($_POST['scope'] ?? '') === 'client' ? 'client' : 'course';
        $pc_client = $scope === 'client' ? (int)($_POST['client_id'] ?? 0) : 0;
        $type      = ($_POST['change_type'] ?? '') === 'percent' ? 'percent' : 'amount';
        $value     = (float)str_replace(',', '.', (string)($_POST['change_value'] ?? '0'));
        $date_from = trim((string)($_POST['date_from'] ?? ''));
        $date_to   = trim((string)($_POST['date_to'] ?? ''));
        $reason    = trim((string)($_POST['reason'] ?? ''));
        $subject   = trim((string)($_POST['email_subject'] ?? ''));
        $body      = trim((string)($_POST['email_body'] ?? ''));
        $send_now  = isset($_POST['send_now']);

        if ($scope === 'client' && !$pc_client) {
            flash_set('danger', 'Wybierz kursanta dla zmiany indywidualnej.');
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
            flash_set('danger', 'Podaj poprawną datę „od".');
        } elseif ($date_to !== '' && $date_to < $date_from) {
            flash_set('danger', 'Data „do" nie może być wcześniejsza niż data „od".');
        } elseif ($reason === '') {
            flash_set('danger', 'Uzasadnienie jest wymagane — kursanci i opiekunowie je zobaczą.');
        } else {
            $pc_id = ti_price_change_create([
                'scope' => $scope, 'course_id' => $id, 'client_id' => $pc_client,
                'change_type' => $type, 'change_value' => $value,
                'date_from' => $date_from, 'date_to' => $date_to ?: null,
                'reason' => $reason, 'email_subject' => $subject, 'email_body' => $body,
                'created_by' => $uid,
            ]);
            $msg = 'Zmiana ceny zapisana.';
            // Zmiana obejmująca miesiące już rozliczone → przelicz wystawione rozliczenia
            try { $msg .= ti_price_change_rebill_msg(ti_price_change_rebill($pc_id)); }
            catch (\Throwable $ex) { $msg .= ' Nie udało się przeliczyć wystawionych rozliczeń: ' . $ex->getMessage(); }
            if ($send_now) {
                $n = ti_price_change_notify($pc_id);
                $msg .= $n > 0 ? " Wysłano powiadomienie e-mail ({$n})." : ' Nie znaleziono adresów e-mail do powiadomienia.';
            }
            flash_set('success', $msg);
        }
        header('Location: kurs.php?id=' . $id . '&pc=1'); exit;
    }

    if ($op === 'price_change_cancel') {
        require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_price_changes.php';
        ti_price_change_cancel((int)($_POST['pc_id'] ?? 0));
        $msg = 'Zmiana ceny anulowana.';
        try { $msg .= ti_price_change_rebill_msg(ti_price_change_rebill((int)($_POST['pc_id'] ?? 0))); }
        catch (\Throwable $ex) { $msg .= ' Nie udało się przeliczyć wystawionych rozliczeń: ' . $ex->getMessage(); }
        flash_set('success', $msg);
        header('Location: kurs.php?id=' . $id . '&pc=1'); exit;
    }

    if ($op === 'price_change_resend') {
        require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_price_changes.php';
        $n = ti_price_change_notify((int)($_POST['pc_id'] ?? 0));
        flash_set($n > 0 ? 'success' : 'warning', $n > 0 ? "Powiadomienie wysłane ponownie ({$n})." : 'Brak adresów e-mail do powiadomienia.');
        header('Location: kurs.php?id=' . $id . '&pc=1'); exit;
    }

    // ── Edycja metadanych kursu przez kierownika (pełny zestaw pól admina) ───
    if ($op === 'update_course') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            flash_set('danger', 'Nazwa kursu jest wymagana.');
            header('Location: kurs.php?id=' . $id . '#edytuj'); exit;
        }
        $up_oneoff      = isset($_POST['is_oneoff']) ? 1 : 0;
        $up_oneoff_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($_POST['oneoff_date'] ?? ''))
            ? trim($_POST['oneoff_date']) : null;
        // Status planowania (informacyjny, nie wpływa na is_active): wymaga uzasadnienia
        $up_plan_status = (string)($_POST['plan_status'] ?? '');
        if (!array_key_exists($up_plan_status, K30_TI_COURSE_PLAN_STATUSES)) $up_plan_status = '';
        $up_plan_note   = trim($_POST['plan_note'] ?? '');
        if ($up_plan_status !== '' && $up_plan_note === '') {
            flash_set('danger', 'Status planowania wymaga uzasadnienia — opisz decyzję kilkoma słowami.');
            header('Location: kurs.php?id=' . $id . '#edytuj'); exit;
        }
        if ($up_plan_status === '') $up_plan_note = '';   // brak statusu = brak uzasadnienia do pokazania
        $data = [
            'name'                => $name,
            'display_name'        => mb_substr(trim($_POST['display_name'] ?? ''), 0, 160),
            'description'         => trim($_POST['description'] ?? ''),
            'instructor_id'       => ((int)($_POST['instructor_id'] ?? 0)) ?: null,
            'location'            => trim($_POST['location'] ?? ''),
            'default_meeting_url' => trim($_POST['default_meeting_url'] ?? ''),
            'billing_model'       => in_array((int)($_POST['billing_model'] ?? 2), [1, 2, 3], true) ? (int)$_POST['billing_model'] : 2,
            'billing_amount'      => max(0, (float)str_replace(',', '.', (string)($_POST['billing_amount'] ?? '0'))),
            // Konto z listy rozwijanej rachunków organizacji ('' = konto dla TI z ustawień)
            'pay_account'         => (function () {
                $sel = preg_replace('/\s+/', '', trim((string)($_POST['pay_account'] ?? '')));
                if ($sel === '') return '';
                $raw = org_setting('org_rachunki_bankowe');
                foreach (($raw ? (json_decode($raw, true) ?: []) : []) as $a) {
                    $n = preg_replace('/\D/', '', (string)($a['nrb'] ?? ''));
                    if ($n !== '' && ($sel === 'PL' . $n || $sel === $n)) {
                        return strlen($n) === 26 ? implode(' ', str_split('PL' . $n, 4)) : (string)$a['nrb'];
                    }
                }
                // Wartość spoza listy: zachowaj dotychczasowe konto kursu (np. wpis historyczny)
                return (string)($GLOBALS['course']['pay_account'] ?? '');
            })(),
            'pay_title'           => trim($_POST['pay_title'] ?? ''),
            'pay_due_days'        => ((int)($_POST['pay_due_days'] ?? 0)) ?: null,
            'lesson_payout_bb'    => max(0, (float)str_replace(',', '.', (string)($_POST['lesson_payout_bb'] ?? '0'))),
            'is_active'           => isset($_POST['is_active']) ? 1 : 0,
            'track_attendance'    => isset($_POST['track_attendance']) ? 1 : 0,
            'is_subgroup'         => isset($_POST['is_subgroup']) ? 1 : 0,
            'is_online'           => isset($_POST['is_online']) ? 1 : 0,
            'no_invoice'          => isset($_POST['no_invoice']) ? 1 : 0,
            'wup_exclude'         => isset($_POST['wup_exclude']) ? 1 : 0,
            'subject_type_id'     => ((int)($_POST['subject_type_id'] ?? 0)) ?: null,
            'class_type'          => in_array($_POST['class_type'] ?? '', ['individual', 'group'], true) ? $_POST['class_type'] : 'individual',
            'is_oneoff'           => $up_oneoff,
            'oneoff_date'         => $up_oneoff ? $up_oneoff_date : null,
            'plan_status'         => $up_plan_status,
            'plan_note'           => mb_substr($up_plan_note, 0, 500),
        ];
        $set = []; $par = [];
        foreach ($data as $k => $v) { $set[] = "$k=?"; $par[] = $v; }
        $par[] = $id;
        db()->prepare("UPDATE k30_ti_courses SET " . implode(',', $set) . " WHERE id=?")->execute($par);

        // Betterfly: ProductId dla pozycji tego kursu (nadpisuje domyślny produkt TI).
        $bf_pid = (int)($_POST['betterfly_product_id'] ?? 0);
        org_setting_set('betterfly_ti_product_course_' . $id, $bf_pid > 0 ? (string)$bf_pid : '');

        // Kurs jednorazowy ↔ sekcja Działania: dosync istniejącego działania,
        // a przy świeżym włączeniu bez działania — utwórz je.
        $extra = '';
        try {
            require_once dirname(dirname(dirname(__DIR__))) . '/includes/grants.php';
            $aid = (int)($course['action_id'] ?? 0);
            if ($up_oneoff && $aid > 0) {
                db()->prepare("UPDATE actions SET nazwa=?, data_od=?, data_do=?, lokalizacja=?, forma=?, link_online=?, koordynator_id=? WHERE id=?")
                   ->execute([$data['display_name'] !== '' ? $data['display_name'] : $name,
                              $up_oneoff_date, $up_oneoff_date, $data['location'],
                              $data['is_online'] ? 'online' : 'stacjonarne',
                              $data['default_meeting_url'], $data['instructor_id'], $aid]);
                $extra = ' Zaktualizowano powiązane działanie w Strategii.';
            } elseif ($up_oneoff && $aid === 0) {
                $aid = db_insert('actions', [
                    'nazwa'            => $data['display_name'] !== '' ? $data['display_name'] : $name,
                    'typ'              => 'szkolenie',
                    'opis'             => 'Kurs jednorazowy TI — grupa ' . $name,
                    'status'           => 'planowane',
                    'koordynator_id'   => $data['instructor_id'],
                    'data_od'          => $up_oneoff_date,
                    'data_do'          => $up_oneoff_date,
                    'cykliczne'        => 0,
                    'lokalizacja'      => $data['location'],
                    'forma'            => $data['is_online'] ? 'online' : 'stacjonarne',
                    'link_online'      => $data['default_meeting_url'],
                    'wlasne_dzialanie' => 1,
                    'created_by'       => $uid,
                ]);
                db()->prepare("UPDATE k30_ti_courses SET action_id=? WHERE id=?")->execute([$aid, $id]);
                $extra = ' Utworzono działanie w Strategii (sekcja Działania).';
            }
        } catch (\Throwable $e) {}

        // Log operacji: podsumowanie WSZYSTKICH zmienionych pól (porównanie do
        // stanu $course sprzed zapisu) — nie tylko wybranych, żeby log faktycznie
        // odzwierciedlał każdą zmianę wprowadzoną w tym formularzu.
        $ku_diff_labels = [
            'name'                => 'Nazwa',
            'display_name'        => 'Nazwa dla kursanta',
            'description'         => 'Opis',
            'instructor_id'       => 'Prowadzący',
            'location'            => 'Lokalizacja',
            'default_meeting_url' => 'Link online',
            'billing_model'       => 'Model rozliczania',
            'billing_amount'      => 'Kwota rozliczenia',
            'pay_account'         => 'Konto do wpłat',
            'pay_title'           => 'Tytuł wpłaty',
            'pay_due_days'        => 'Termin płatności (dni)',
            'lesson_payout_bb'    => 'Stawka BB prowadzącego',
            'is_active'           => 'Aktywny',
            'track_attendance'    => 'Liczy frekwencję',
            'is_subgroup'         => 'Podgrupa',
            'is_online'           => 'Zajęcia online',
            'no_invoice'          => 'Bez fakturowania',
            'wup_exclude'         => 'Poza raportem WUP',
            'subject_type_id'     => 'Rodzaj zajęć',
            'class_type'          => 'Typ zajęć',
            'is_oneoff'           => 'Kurs jednorazowy',
            'oneoff_date'         => 'Termin realizacji',
            'plan_status'         => 'Status planowania',
        ];
        $ku_bool_fields  = ['is_active', 'track_attendance', 'is_subgroup', 'is_online', 'no_invoice', 'wup_exclude', 'is_oneoff'];
        $ku_float_fields = ['billing_amount', 'lesson_payout_bb'];
        $ku_int_fields   = ['pay_due_days', 'instructor_id', 'subject_type_id', 'billing_model'];
        $ku_changes = [];
        foreach ($ku_diff_labels as $ku_f => $ku_lbl) {
            $ku_old = $course[$ku_f] ?? null;
            $ku_new = $data[$ku_f] ?? null;
            // Porównanie po typie pola — string vs float/int z bazy (np. "150.0" vs 150)
            // dawałoby fałszywe „zmiany" przy niezmienionej wartości.
            if (in_array($ku_f, $ku_float_fields, true)) {
                if (abs((float)$ku_old - (float)$ku_new) < 0.005) continue;
            } elseif (in_array($ku_f, $ku_int_fields, true) || in_array($ku_f, $ku_bool_fields, true)) {
                if ((int)$ku_old === (int)$ku_new) continue;
            } elseif ((string)$ku_old === (string)$ku_new) {
                continue;
            }
            if ($ku_f === 'plan_status') {
                $ku_ov = K30_TI_COURSE_PLAN_STATUSES[(string)$ku_old]['label'] ?? '—';
                $ku_nv = K30_TI_COURSE_PLAN_STATUSES[(string)$ku_new]['label'] ?? '—';
                $ku_changes[] = "$ku_lbl: $ku_ov → $ku_nv" . ($up_plan_note !== '' ? " ($up_plan_note)" : '');
            } elseif (in_array($ku_f, $ku_bool_fields, true)) {
                $ku_changes[] = "$ku_lbl: " . ((int)$ku_new ? 'tak' : 'nie');
            } elseif ($ku_f === 'instructor_id') {
                $ku_iname = $ku_new ? (string)(db_one("SELECT name FROM users WHERE id=?", [(int)$ku_new])['name'] ?? ('#' . $ku_new)) : '—';
                $ku_changes[] = "$ku_lbl: $ku_iname";
            } elseif ($ku_f === 'subject_type_id') {
                $ku_sname = $ku_new ? (string)(db_one("SELECT abbreviation FROM k30_ti_subject_types WHERE id=?", [(int)$ku_new])['abbreviation'] ?? ('#' . $ku_new)) : '—';
                $ku_changes[] = "$ku_lbl: $ku_sname";
            } elseif ($ku_f === 'billing_model') {
                $ku_changes[] = "$ku_lbl: " . k30_ti_billing_model_label((int)$ku_new);
            } elseif ($ku_f === 'class_type') {
                $ku_changes[] = "$ku_lbl: " . ($ku_new === 'group' ? 'Grupowy' : 'Indywidualny');
            } elseif ($ku_f === 'description') {
                $ku_changes[] = "$ku_lbl zmieniony";   // pełna treść bez sensu w jednowierszowym logu
            } else {
                $ku_changes[] = "$ku_lbl: " . ($ku_old !== null && $ku_old !== '' ? $ku_old : '—')
                              . ' → ' . ($ku_new !== null && $ku_new !== '' ? $ku_new : '—');
            }
        }
        if ($ku_changes) {
            ti_course_log($id, 'update', implode('; ', $ku_changes), $uid, (string)($me['name'] ?? ''));
        }

        flash_set('success', 'Kurs zaktualizowany.' . $extra);
        header('Location: kurs.php?id=' . $id); exit;
    }

    // ── CoProwadzący ─────────────────────────────────────────────────────────
    if ($op === 'coinstr_add') {
        $cu = (int)($_POST['coinstr_user_id'] ?? 0);
        if ($cu && $cu !== (int)$course['instructor_id']) {
            k30_ti_coinstruct_add($id, $cu, $uid);
            _dyd_zoom_sync_alt_hosts($id, $course);
            flash_set('success', 'CoProwadzący dodany.');
        }
        header('Location: kurs.php?id=' . $id . '#coinstructors'); exit;
    }
    if ($op === 'coinstr_remove') {
        $cu = (int)($_POST['coinstr_user_id'] ?? 0);
        if ($cu) {
            k30_ti_coinstruct_remove($id, $cu);
            _dyd_zoom_sync_alt_hosts($id, $course);
            flash_set('success', 'CoProwadzący usunięty.');
        }
        header('Location: kurs.php?id=' . $id . '#coinstructors'); exit;
    }
}

/* ── DANE ──────────────────────────────────────────────────────────────────── */
$enrollments   = k30_ti_enrollments($id);
$all_clients   = db_all("SELECT id, name FROM k30_clients ORDER BY name");
$not_enrolled  = array_filter($all_clients, fn($c) => !in_array((int)$c['id'], array_column($enrollments, 'client_id')));
$coinstructors = k30_ti_course_coinstructors($id);
$coinstr_ids   = array_column($coinstructors, 'user_id');
$consultants   = k30_get_consultants();
$coinstr_available = array_filter($consultants, fn($u) =>
    (int)$u['id'] !== (int)$course['instructor_id'] && !in_array((int)$u['id'], $coinstr_ids));
$cbm = (int)($course['billing_model'] ?? 2) ?: 2;

// Zmiany cen (istniejące + dane bazowe do podglądu przed/po w JS formularza —
// odzwierciedla k30_ti_effective_billing(): override > domyślne kursu, ale
// hourly_rate jest ZAWSZE per-zapis, nawet bez override).
$price_changes  = ti_price_changes_for_course($id);
$pc_course_base = ['model' => $cbm, 'amount' => (float)($course['billing_amount'] ?? 0)];
$pc_client_base = [];
foreach ($enrollments as $e) {
    if ($e['status'] !== 'active') continue;
    $has_override = (int)($e['billing_model'] ?? 0) > 0;
    $pc_client_base[(int)$e['client_id']] = [
        'name'        => $e['client_name'],
        'model'       => $has_override ? (int)$e['billing_model'] : $cbm,
        'amount'      => $has_override ? (float)$e['billing_amount'] : (float)($course['billing_amount'] ?? 0),
        'hourly_rate' => (float)$e['hourly_rate'],
        'override'    => $has_override,
    ];
}

// Dane do modala edycji kursu
$ed_instructors = k30_ti_instructors();
$ed_subjects    = k30_ti_subject_types(false);
$ed_accounts    = [];
$ed_acc_raw     = org_setting('org_rachunki_bankowe');
foreach (($ed_acc_raw ? (json_decode($ed_acc_raw, true) ?: []) : []) as $a) {
    $n = preg_replace('/\D/', '', (string)($a['nrb'] ?? ''));
    if (strlen($n) !== 26) continue;
    $ed_accounts[] = ['iban' => implode(' ', str_split('PL' . $n, 4)),
                      'opis' => trim((string)($a['opis'] ?? '')),
                      'ti'   => !empty($a['dla_ti'])];
}
$ed_cur_acc  = trim((string)($course['pay_account'] ?? ''));
$ed_acc_ibans = array_column($ed_accounts, 'iban');
// Konto historyczne spoza rejestru — pokaż jako opcję, żeby edycja go nie gubiła
if ($ed_cur_acc !== '' && !in_array($ed_cur_acc, $ed_acc_ibans, true)) {
    $ed_accounts[] = ['iban' => $ed_cur_acc, 'opis' => 'konto spoza rejestru organizacji', 'ti' => false];
}

/* ── HTML ──────────────────────────────────────────────────────────────────── */
$KP_TITLE  = 'Grupa: ' . $course['name'] . ' — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => $dyd_name, 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">

<?php $KIER_CUR = ''; $KIER_LABEL = $course['name'];
   include __DIR__ . '/_kierownik_bar.php'; ?>

<main id="main" class="dyd-wrap">

<div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
  <div>
    <h1 class="h4 fw-bold mb-0">
      <i class="bi bi-pc-display me-2 text-primary" aria-hidden="true"></i><?= h($course['name']) ?>
      <?php if (!empty($course['subject_abbr'])): ?>
      <span class="badge text-bg-primary font-monospace ms-1 align-middle" style="font-size:.7rem"><?= h($course['subject_abbr']) ?></span>
      <?php endif; ?>
      <?php if (!empty($course['group_code'])): ?>
      <span class="badge bg-light text-secondary border font-monospace ms-1 align-middle" style="font-size:.7rem"><?= h($course['group_code']) ?></span>
      <?php endif; ?>
      <?php if (!empty($course['is_oneoff'])): ?>
      <span class="badge text-bg-info ms-1 align-middle" style="font-size:.7rem">jednorazowy<?=
        !empty($course['oneoff_date']) ? ' · ' . h(date('d.m.Y', strtotime((string)$course['oneoff_date']))) : '' ?></span>
      <?php endif; ?>
      <?php if (!empty($course['plan_status']) && isset(K30_TI_COURSE_PLAN_STATUSES[$course['plan_status']])):
        $pln = K30_TI_COURSE_PLAN_STATUSES[$course['plan_status']]; ?>
      <span class="badge text-bg-<?= h($pln['badge']) ?> ms-1 align-middle" style="font-size:.7rem"
            title="Uzasadnienie: <?= h((string)($course['plan_note'] ?? '')) ?>">
        <i class="bi bi-signpost-split me-1" aria-hidden="true"></i><?= h($pln['label']) ?></span>
      <?php endif; ?>
    </h1>
    <p class="text-body-secondary small mb-0">
      <?php if ($course['instructor_name']): ?><i class="bi bi-person me-1" aria-hidden="true"></i><?= h($course['instructor_name']) ?><?php endif; ?>
      <?php if ($course['location']): ?> · <i class="bi bi-geo-alt me-1" aria-hidden="true"></i><?= h($course['location']) ?><?php endif; ?>
      · <i class="bi bi-cash-coin me-1" aria-hidden="true"></i>Rozliczanie: <?= h(k30_ti_billing_model_label($cbm)) ?> (kod <?= $cbm ?>)<?php
        if ($cbm !== 2 && (float)($course['billing_amount'] ?? 0) > 0): ?> · <?= number_format((float)$course['billing_amount'], 2, ',', '') ?> zł<?php endif; ?>
    </p>
    <?php if (!empty($course['plan_status']) && trim((string)($course['plan_note'] ?? '')) !== ''): ?>
    <p class="text-body-secondary small mb-0 fst-italic">
      <i class="bi bi-chat-left-text me-1" aria-hidden="true"></i><?= h($course['plan_note']) ?>
    </p>
    <?php endif; ?>
  </div>
  <div class="ms-auto d-flex gap-2 flex-wrap">
    <a href="index.php?course=<?= $id ?>&tab=lekcje" class="btn btn-sm btn-outline-primary">
      <i class="bi bi-calendar-week me-1" aria-hidden="true"></i>Zajęcia</a>
    <a href="index.php?course=<?= $id ?>&tab=uczestnicy" class="btn btn-sm btn-outline-primary">
      <i class="bi bi-people me-1" aria-hidden="true"></i>Kartoteka grupy</a>
    <a href="index.php?course=<?= $id ?>&tab=rozliczenia" class="btn btn-sm btn-outline-primary">
      <i class="bi bi-receipt me-1" aria-hidden="true"></i>Rozliczenia grupy</a>
    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#courseEditModal">
      <i class="bi bi-pencil me-1" aria-hidden="true"></i>Edytuj kurs</button>
    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#priceChangeModal">
      <i class="bi bi-tag me-1" aria-hidden="true"></i>Zmiana ceny
      <?php if ($price_changes): ?><span class="badge bg-secondary ms-1"><?= count($price_changes) ?></span><?php endif; ?>
    </button>
    <a href="log_grup.php?course=<?= $id ?>" class="btn btn-sm btn-outline-secondary" title="Log operacji tej grupy">
      <i class="bi bi-clock-history me-1" aria-hidden="true"></i>Log operacji</a>
  </div>
</div>

<?= flash_html() ?>

<?php if (zoom_enabled()): ?>
<div class="card mb-3" id="zoom">
  <div class="card-body py-2 px-3 d-flex align-items-center gap-3 flex-wrap">
    <span class="fw-semibold text-nowrap"><i class="bi bi-camera-video-fill text-primary me-1" aria-hidden="true"></i>Zoom — link kursu</span>
    <?php if (!empty($course['default_meeting_url'])): ?>
      <a href="<?= h($course['default_meeting_url']) ?>" target="_blank" rel="noopener"
         class="text-break small font-monospace"><?= h($course['default_meeting_url']) ?></a>
      <span class="text-body-secondary" style="font-size:.72rem">ID: <?= h($course['zoom_meeting_id']) ?></span>
      <?php if (!empty($course['zoom_host_email'])): ?>
      <span class="badge bg-light text-secondary border" style="font-size:.7rem"><i class="bi bi-person me-1" aria-hidden="true"></i><?= h($course['zoom_host_email']) ?></span>
      <?php endif; ?>
    <?php else: ?>
      <span class="text-body-secondary small">Brak stałego linku — kursanci i prowadzący dołączają przez indywidualne linki lub wpisują URL ręcznie.</span>
    <?php endif; ?>
    <div class="ms-auto d-flex gap-2 flex-shrink-0">
      <form method="post" class="d-inline">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_op"   value="gen_course_zoom">
        <button type="submit" class="btn btn-sm btn-outline-primary"
                onclick="return confirm('<?= empty($course['default_meeting_url']) ? 'Wygenerować stały link Zoom dla kursu?' : 'Zregenerować stały link Zoom? Stary link przestanie działać.' ?>')">
          <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i><?= empty($course['default_meeting_url']) ? 'Wygeneruj link Zoom' : 'Regeneruj link' ?>
        </button>
      </form>
      <?php if (!empty($course['default_meeting_url'])): ?>
      <form method="post" class="d-inline">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_op"   value="clear_course_zoom">
        <button type="submit" class="btn btn-sm btn-outline-danger"
                onclick="return confirm('Usunąć stały link Zoom kursu? Operacja jest nieodwracalna.')">
          <i class="bi bi-trash me-1" aria-hidden="true"></i>Usuń link
        </button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card mb-4" id="coinstructors">
  <div class="card-header fw-semibold bg-white d-flex align-items-center py-2">
    <i class="bi bi-people-fill me-2 text-secondary" aria-hidden="true"></i>CoProwadzący
    <span class="badge bg-secondary ms-2"><?= count($coinstructors) ?></span>
    <span class="ms-2 text-body-secondary fw-normal" style="font-size:.8rem">Dostęp do kursu w panelu dydaktyka — bez rozliczenia</span>
  </div>
  <div class="card-body py-2 px-3">
    <?php if ($coinstructors): ?>
    <div class="d-flex flex-wrap gap-2 align-items-center">
      <?php foreach ($coinstructors as $ci): ?>
      <div class="d-flex align-items-center gap-1 border rounded px-2 py-1" style="font-size:.85rem">
        <i class="bi bi-person-badge text-secondary me-1" aria-hidden="true"></i>
        <span><?= h($ci['user_name']) ?></span>
        <span class="text-body-secondary" style="font-size:.75rem"><?= h($ci['user_email']) ?></span>
        <form method="post" class="d-inline ms-1">
          <input type="hidden" name="_csrf"           value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op"             value="coinstr_remove">
          <input type="hidden" name="coinstr_user_id" value="<?= (int)$ci['user_id'] ?>">
          <button type="submit" class="btn btn-sm btn-outline-danger border-0 p-0 px-1" title="Usuń coProwadzącego"
                  onclick="return confirm('Usunąć <?= h(addslashes($ci['user_name'])) ?> z listy coProwadzących?')">
            <i class="bi bi-x-lg" style="font-size:.7rem" aria-hidden="true"></i><span class="visually-hidden">Usuń</span>
          </button>
        </form>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <span class="text-body-secondary" style="font-size:.85rem">Brak coProwadzących — kurs dostępny wyłącznie dla głównego prowadzącego.</span>
    <?php endif; ?>

    <?php if ($coinstr_available): ?>
    <form method="post" class="d-flex gap-2 align-items-end mt-2 flex-wrap">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_op"   value="coinstr_add">
      <div>
        <label class="form-label small fw-semibold mb-1" for="coinstr-sel">Dodaj coProwadzącego</label>
        <select id="coinstr-sel" name="coinstr_user_id" class="form-select form-select-sm" required style="min-width:220px">
          <option value="">— wybierz prowadzącego —</option>
          <?php foreach ($coinstr_available as $u): ?>
          <option value="<?= (int)$u['id'] ?>"><?= h($u['display_name'] ?? $u['name']) ?> (<?= h($u['email']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-person-plus me-1" aria-hidden="true"></i>Dodaj</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<div class="card" id="uczestnicy">
  <div class="card-header fw-semibold bg-white d-flex align-items-center">
    <i class="bi bi-people me-2 text-primary" aria-hidden="true"></i>Uczestnicy i rozliczanie
    <span class="badge bg-secondary ms-2"><?= count(array_filter($enrollments, fn($e) => $e['status'] === 'active')) ?></span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <caption class="visually-hidden">Uczestnicy kursu z modelem rozliczania i akcjami</caption>
      <thead class="table-light">
        <tr><th scope="col">Kursant</th><th scope="col">Rozliczanie</th><th scope="col">Status</th><th scope="col" class="text-end">Akcje</th></tr>
      </thead>
      <tbody>
        <?php foreach ($enrollments as $e):
          $eff = k30_ti_effective_billing($e, $course);
          // Cena obowiązująca DZIŚ (po zaplanowanej zmianie ceny, jeśli trwa)
          $eff_base = $eff; $eff = ti_price_eff_on($eff, (int)$course['id'], (int)$e['client_id'], date('Y-m-d')); ?>
        <tr class="<?= $e['status'] !== 'active' ? 'text-muted opacity-75' : '' ?>">
          <th scope="row" class="fw-semibold"><?= h($e['client_name']) ?></th>
          <td>
            <?php if ($eff['individual']): ?>
            <span class="badge bg-warning text-dark" title="Indywidualne ustalenia">9999 · indyw.</span>
            <?php else: ?>
            <span class="badge bg-light text-secondary border" title="Kod modelu">kod <?= (int)$eff['code'] ?></span>
            <?php endif; ?>
            <span class="small"><?= h($eff['label']) ?>:</span>
            <span class="fw-semibold small">
              <?php if ($eff['model'] === 2): ?><?= number_format($eff['hourly_rate'], 2, ',', '') ?> zł/h
                <?php if ((float)($e['hourly_rate_online'] ?? 0) > 0.005): ?><span class="text-body-secondary fw-normal" title="Stawka za lekcje online">· online <?= number_format((float)$e['hourly_rate_online'], 2, ',', '') ?> zł/h</span><?php endif; ?>
              <?php else: ?><?= number_format($eff['amount'], 2, ',', '') ?> zł<?php endif; ?>
            </span>
            <?php if (!empty($eff['price_change_id'])): ?>
            <span class="badge text-bg-info" style="font-size:.62rem" title="Zmiana ceny #<?= (int)$eff['price_change_id'] ?> — cena bazowa <?= $eff_base['model'] === 2 ? number_format($eff_base['hourly_rate'], 2, ',', '') . ' zł/h' : number_format($eff_base['amount'], 2, ',', '') . ' zł' ?>">po zmianie ceny</span>
            <?php endif; ?>
            <button type="button" class="btn btn-sm btn-link p-0 ms-1 align-baseline" data-bs-toggle="modal"
                    data-bs-target="#bill<?= (int)$e['client_id'] ?>" title="Zmień rozliczanie">
              <i class="bi bi-pencil" aria-hidden="true"></i><span class="visually-hidden">Zmień rozliczanie</span></button>
          </td>
          <td><span class="badge <?= $e['status'] === 'active' ? 'text-bg-success' : 'bg-secondary' ?>"><?= $e['status'] === 'active' ? 'Aktywny' : 'Nieaktywny' ?></span></td>
          <td class="text-end text-nowrap">
            <?php if ($e['status'] === 'active' && zoom_enabled()): ?>
            <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2 me-1"
                    data-bs-toggle="modal" data-bs-target="#zoomStu<?= (int)$e['client_id'] ?>"
                    title="<?= !empty($e['zoom_meeting_url']) ? 'Regeneruj / usuń stały link Zoom' : 'Wygeneruj stały link Zoom' ?>">
              <i class="bi bi-camera-video" aria-hidden="true"></i>
              <?php if (!empty($e['zoom_meeting_url'])): ?><span class="badge text-bg-success ms-1" style="font-size:.6rem">Zoom</span><?php endif; ?>
            </button>
            <?php endif; ?>
            <?php if ($e['status'] === 'active'): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Wypisać uczestnika?')">
              <input type="hidden" name="_csrf"     value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op"       value="unenroll">
              <input type="hidden" name="client_id" value="<?= (int)$e['client_id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" title="Wypisz">
                <i class="bi bi-x-lg" aria-hidden="true"></i><span class="visually-hidden">Wypisz</span>
              </button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$enrollments): ?>
        <tr><td colspan="4" class="text-body-secondary text-center py-3">Brak uczestników.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($not_enrolled): ?>
  <div class="card-footer bg-white">
    <form method="post" class="d-flex gap-2 align-items-end flex-wrap">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_op"   value="enroll">
      <div>
        <label class="form-label small fw-semibold mb-1" for="enroll-sel">Dodaj uczestnika</label>
        <select id="enroll-sel" name="client_id" class="form-select form-select-sm" required style="min-width:220px">
          <option value="">— wybierz —</option>
          <?php foreach ($not_enrolled as $c): ?>
          <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label small fw-semibold mb-1" for="enroll-rate">Stawka stacjonarna (zł/h)</label>
        <input type="number" class="form-control form-control-sm" id="enroll-rate" name="hourly_rate" step="0.01" min="0" value="0" style="width:90px">
      </div>
      <div>
        <label class="form-label small fw-semibold mb-1" for="enroll-rate-on">Stawka online (zł/h)</label>
        <input type="number" class="form-control form-control-sm" id="enroll-rate-on" name="hourly_rate_online" step="0.01" min="0" value="0" style="width:90px"
               title="0 = taka sama jak stacjonarna" aria-describedby="enroll-rate-on-help">
        <div id="enroll-rate-on-help" class="visually-hidden">Zero oznacza taką samą stawkę jak stacjonarna.</div>
      </div>
      <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-person-plus me-1" aria-hidden="true"></i>Zapisz</button>
      <span class="text-body-secondary small align-self-center">Przenosiny między grupami: zakładka
        <a href="index.php?course=<?= $id ?>&tab=uczestnicy">Uczestnicy</a>.</span>
    </form>
  </div>
  <?php endif; ?>
</div>

<div class="modal fade" id="priceChangeModal" tabindex="-1" aria-labelledby="priceChangeModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="priceChangeModalLabel"><i class="bi bi-tag text-primary me-2" aria-hidden="true"></i>Zmiana ceny zajęć</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
    <p class="text-body-secondary small mb-3">
      Zmiana obowiązuje tylko w podanym zakresie dat — nie nadpisuje ceny kursu ani indywidualnego rozliczenia
      na stałe. Wymaga uzasadnienia; kursanci i opiekunowie (jeśli małoletni) mogą dostać o niej e-mail.
    </p>

    <form method="post" id="pcForm" class="row g-2 align-items-end mb-2">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_op" value="price_change_create">

      <div class="col-sm-3">
        <label class="form-label small mb-1">Zasięg</label>
        <select name="scope" id="pc_scope" class="form-select form-select-sm" onchange="pcUpdate()">
          <option value="course">Cały kurs</option>
          <option value="client">Wybrany kursant</option>
        </select>
      </div>
      <div class="col-sm-3" id="pc_client_wrap" style="display:none">
        <label class="form-label small mb-1">Kursant</label>
        <select name="client_id" id="pc_client" class="form-select form-select-sm" onchange="pcUpdate()">
          <?php foreach ($enrollments as $e): if ($e['status'] !== 'active') continue; ?>
          <option value="<?= (int)$e['client_id'] ?>"><?= h($e['client_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-3">
        <label class="form-label small mb-1">Typ zmiany</label>
        <select name="change_type" id="pc_type" class="form-select form-select-sm" onchange="pcUpdate()">
          <?php foreach (TI_PRICE_CHANGE_TYPES as $tk => $tl): ?>
          <option value="<?= h($tk) ?>"><?= h($tl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-3">
        <label class="form-label small mb-1" id="pc_value_label">Wartość</label>
        <input type="number" name="change_value" id="pc_value" class="form-control form-control-sm" step="0.01" oninput="pcUpdate()">
      </div>

      <div class="col-sm-3">
        <label class="form-label small mb-1">Obowiązuje od</label>
        <input type="date" name="date_from" id="pc_from" class="form-control form-control-sm" required value="<?= date('Y-m-d') ?>" onchange="pcUpdate()">
      </div>
      <div class="col-sm-3">
        <label class="form-label small mb-1">Do (opcjonalnie)</label>
        <input type="date" name="date_to" id="pc_to" class="form-control form-control-sm" onchange="pcUpdate()">
      </div>
      <div class="col-12">
        <div class="alert alert-light border py-1 px-2 mb-0 small" id="pc_preview">Wypełnij pola, żeby zobaczyć podgląd.</div>
      </div>

      <div class="col-12">
        <label class="form-label small mb-1">Uzasadnienie <span class="text-danger">*</span></label>
        <textarea name="reason" id="pc_reason" class="form-control form-control-sm" rows="2" required
                  placeholder="np. Wzrost kosztów wynajmu sali od nowego roku szkolnego" oninput="pcUpdate()"></textarea>
      </div>

      <div class="col-12"><hr class="my-1"></div>
      <div class="col-12 d-flex align-items-center gap-2">
        <i class="bi bi-envelope text-body-secondary" aria-hidden="true"></i>
        <span class="fw-semibold small">Wiadomość e-mail do kursanta/opiekuna</span>
        <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" onclick="pcRegenerateEmail()">
          <i class="bi bi-magic me-1" aria-hidden="true"></i>Wygeneruj / odśwież treść
        </button>
      </div>
      <div class="col-12">
        <label class="form-label small mb-1" for="pc_subject">Temat</label>
        <input type="text" name="email_subject" id="pc_subject" class="form-control form-control-sm">
      </div>
      <div class="col-12">
        <label class="form-label small mb-1" for="pc_body">Treść</label>
        <textarea name="email_body" id="pc_body" class="form-control form-control-sm" rows="7"></textarea>
        <div class="form-text mt-0">Treść w pełni edytowalna — przycisk wyżej tylko proponuje punkt wyjścia.</div>
      </div>

      <div class="col-12 d-flex align-items-center gap-3 mt-2">
        <div class="form-check form-switch m-0">
          <input class="form-check-input" type="checkbox" name="send_now" id="pc_send" checked>
          <label class="form-check-label small" for="pc_send">Wyślij e-mail od razu po zapisaniu</label>
        </div>
        <button type="submit" class="btn btn-sm btn-primary ms-auto">
          <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Zapisz zmianę ceny
        </button>
      </div>
    </form>

    <?php if ($price_changes): ?>
    <hr class="my-3">
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead>
          <tr>
            <th>Zasięg</th><th>Zmiana</th><th>Okres</th><th>Uzasadnienie</th><th>Powiadomienie</th><th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($price_changes as $pc): ?>
          <tr class="<?= $pc['status'] === 'cancelled' ? 'opacity-50' : '' ?>">
            <td><?= $pc['client_name'] ? h($pc['client_name']) : '<span class="text-body-secondary">Cały kurs</span>' ?></td>
            <td><?= h(ti_price_change_value_label($pc['change_type'], (float)$pc['change_value'])) ?></td>
            <td class="text-nowrap small">
              <?= date('d.m.Y', strtotime($pc['date_from'])) ?> –
              <?= $pc['date_to'] ? date('d.m.Y', strtotime($pc['date_to'])) : 'bezterminowo' ?>
            </td>
            <td class="small" style="max-width:220px"><?= h(mb_strimwidth($pc['reason'], 0, 80, '…')) ?></td>
            <td class="small">
              <?php if ($pc['notified_at']): ?>
              <span class="badge text-bg-success">wysłano (<?= (int)$pc['notified_count'] ?>)</span>
              <?php else: ?>
              <span class="badge text-bg-secondary">nie wysłano</span>
              <?php endif; ?>
            </td>
            <td class="text-end">
              <?php if ($pc['status'] === 'active'): ?>
              <form method="post" class="d-inline">
                <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="_op" value="price_change_resend">
                <input type="hidden" name="pc_id" value="<?= (int)$pc['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-secondary" title="Wyślij e-mail ponownie">
                  <i class="bi bi-envelope" aria-hidden="true"></i>
                </button>
              </form>
              <form method="post" class="d-inline" onsubmit="return confirm('Anulować tę zmianę ceny?')">
                <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="_op" value="price_change_cancel">
                <input type="hidden" name="pc_id" value="<?= (int)$pc['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" title="Anuluj">
                  <i class="bi bi-x-lg" aria-hidden="true"></i>
                </button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var courseBase  = <?= json_encode($pc_course_base, JSON_UNESCAPED_UNICODE) ?>;
  var clientBase  = <?= json_encode($pc_client_base, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT) ?>;
  var courseName  = <?= json_encode($course['name'], JSON_UNESCAPED_UNICODE) ?>;
  var emailEdited = false;

  document.getElementById('pc_body').addEventListener('input', function () { emailEdited = true; });
  document.getElementById('pc_subject').addEventListener('input', function () { emailEdited = true; });

  function fmt(n) { return n.toLocaleString('pl-PL', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }

  function diffLabel(before, after, unitFull) {
    // unitFull = " zł/h" albo " zł" (już ze spacją i "zł" — patrz zmienna `unit` w pcUpdate).
    var diff = after - before;
    var label = (diff >= 0 ? '+' : '') + fmt(diff) + unitFull;
    if (Math.abs(before) > 0.0001) {
      var pct = diff / before * 100;
      label += ' (' + (pct >= 0 ? '+' : '') + (Math.round(pct * 10) / 10) + '%)';
    }
    return label;
  }

  window.pcUpdate = function () {
    var scope  = document.getElementById('pc_scope').value;
    var isClient = scope === 'client';
    document.getElementById('pc_client_wrap').style.display = isClient ? '' : 'none';

    var type  = document.getElementById('pc_type').value;
    document.getElementById('pc_value_label').textContent = type === 'percent' ? 'Wartość (%, ujemna = obniżka)' : 'Nowa kwota (zł)';

    var value = parseFloat(document.getElementById('pc_value').value.replace(',', '.')) || 0;
    var previewEl = document.getElementById('pc_preview');

    var base = isClient ? clientBase[document.getElementById('pc_client').value] : courseBase;
    if (!base) { previewEl.textContent = 'Wypełnij pola, żeby zobaczyć podgląd.'; return; }

    var isHourly = base.model !== 1 && base.model !== 3;
    var unit = isHourly ? ' zł/h' : ' zł';

    function computeAfter(before) {
      return type === 'percent' ? before * (1 + value / 100) : Math.max(0, value);
    }

    if (!isClient && isHourly) {
      // Zasięg "Cały kurs" + model godzinowy: każdy zapis ma własną stawkę
      // (bez rabatu/override) — pokaż rozbicie zamiast jednej mylącej liczby.
      var rows = Object.keys(clientBase)
        .map(function (cid) { return clientBase[cid]; })
        .filter(function (c) { return !c.override; });
      if (!rows.length) {
        previewEl.textContent = 'Brak kursantów bez indywidualnego rozliczenia w tym kursie.';
      } else {
        previewEl.innerHTML = 'Każdy dostanie dokładną kwotę w swoim e-mailu. Podgląd:<br>' +
          rows.map(function (c) {
            var after = computeAfter(c.hourly_rate);
            return c.name + ': ' + fmt(c.hourly_rate) + unit + ' → <strong>' + fmt(after) + unit + '</strong>' +
              ' &nbsp;<span class="text-body-secondary">' + diffLabel(c.hourly_rate, after, unit) + '</span>';
          }).join('<br>');
      }
    } else {
      var before = isHourly ? base.hourly_rate : base.amount;
      var after  = computeAfter(before);
      previewEl.innerHTML = 'Podgląd: <strong>' + fmt(before) + unit + '</strong> → <strong>' + fmt(after) + unit + '</strong>' +
        ' &nbsp;<span class="text-body-secondary">' + diffLabel(before, after, unit) + '</span>';
    }
  };

  window.pcRegenerateEmail = function () {
    if (emailEdited && !confirm('Treść była już edytowana ręcznie — nadpisać ją nową propozycją?')) return;
    var type   = document.getElementById('pc_type').value;
    var value  = parseFloat(document.getElementById('pc_value').value.replace(',', '.')) || 0;
    var from   = document.getElementById('pc_from').value;
    var to     = document.getElementById('pc_to').value;
    var reason = document.getElementById('pc_reason').value.trim();
    if (!from) { alert('Podaj datę "od" przed wygenerowaniem treści.'); return; }

    var valueLabel = type === 'percent' ? ((value > 0 ? '+' : '') + value + '%') : (fmt(value) + ' zł');
    var range = new Date(from).toLocaleDateString('pl-PL') + (to ? ' – ' + new Date(to).toLocaleDateString('pl-PL') : ' (bezterminowo)');
    var org = <?= json_encode(defined('ORG_NAME') ? ORG_NAME : 'Zajęcia TI', JSON_UNESCAPED_UNICODE) ?>;

    document.getElementById('pc_subject').value = org + ': zmiana ceny zajęć — ' + courseName;
    document.getElementById('pc_body').value =
      'Dzień dobry,\n\n' +
      'informujemy o zmianie ceny zajęć „' + courseName + '”, obowiązującej od ' + range + '.\n\n' +
      'Zmiana: ' + valueLabel + '\n\n' +
      (reason ? ('Uzasadnienie: ' + reason + '\n\n') : '') +
      'Dokładną cenę (przed i po zmianie) znajdziesz pod tą wiadomością. W razie pytań prosimy o kontakt ' +
      'z prowadzącym albo z biurem placówki.\n\n' +
      'Pozdrawiamy,\n' + org;
    emailEdited = false;
  };

  pcUpdate();

  // Otwórz modal automatycznie po zapisaniu/anulowaniu/wysyłce (redirect z ?pc=1).
  if ((new URLSearchParams(window.location.search)).get('pc') === '1') {
    new bootstrap.Modal(document.getElementById('priceChangeModal')).show();
  }
})();
</script>

<!-- Modale: indywidualne rozliczanie kursanta -->
<?php $course_due = (int)($course['pay_due_days'] ?? 0) ?: K30_TI_PAY_DUE_DAYS_DEFAULT;
foreach ($enrollments as $e): ?>
<div class="modal fade" id="bill<?= (int)$e['client_id'] ?>" tabindex="-1" aria-labelledby="billLbl<?= (int)$e['client_id'] ?>" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h2 class="modal-title h6 mb-0" id="billLbl<?= (int)$e['client_id'] ?>">
          <i class="bi bi-cash-coin text-success me-2" aria-hidden="true"></i>Rozliczanie — <?= h($e['client_name']) ?>
        </h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <form method="post">
        <div class="modal-body">
          <input type="hidden" name="_csrf"     value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op"       value="set_billing">
          <input type="hidden" name="client_id" value="<?= (int)$e['client_id'] ?>">
          <div class="mb-3">
            <label class="form-label small fw-semibold mb-1" for="bm<?= (int)$e['client_id'] ?>">Model (override)</label>
            <select name="billing_model" id="bm<?= (int)$e['client_id'] ?>" class="form-select form-select-sm" onchange="billToggle(this)">
              <option value="0" <?= (int)$e['billing_model'] === 0 ? 'selected' : '' ?>>— jak kurs (<?= h(k30_ti_billing_model_label((int)($course['billing_model'] ?: 2))) ?>) —</option>
              <?php foreach ([1, 2, 3] as $code): ?>
              <option value="<?= $code ?>" <?= (int)$e['billing_model'] === $code ? 'selected' : '' ?>>Indywidualny: <?= h(k30_ti_billing_model_label($code)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-6 bill-rate" style="<?= in_array((int)$e['billing_model'], [1, 3], true) ? 'display:none' : '' ?>">
              <label class="form-label small mb-0" for="hr<?= (int)$e['client_id'] ?>">Stawka stacjonarna (zł/h)</label>
              <input type="number" name="hourly_rate" id="hr<?= (int)$e['client_id'] ?>" class="form-control form-control-sm" step="0.01" min="0" value="<?= h(number_format((float)$e['hourly_rate'], 2, '.', '')) ?>">
            </div>
            <div class="col-6 bill-rate" style="<?= in_array((int)$e['billing_model'], [1, 3], true) ? 'display:none' : '' ?>">
              <label class="form-label small mb-0" for="hro<?= (int)$e['client_id'] ?>">Stawka online (zł/h)</label>
              <input type="number" name="hourly_rate_online" id="hro<?= (int)$e['client_id'] ?>" class="form-control form-control-sm" step="0.01" min="0"
                     value="<?= h(number_format((float)($e['hourly_rate_online'] ?? 0), 2, '.', '')) ?>" aria-describedby="hro-help<?= (int)$e['client_id'] ?>">
              <div class="form-text" id="hro-help<?= (int)$e['client_id'] ?>">0 = jak stacjonarna. Online: lekcja Zoom / zdalna, a bez wybranej metody — gdy grupa jest online.</div>
            </div>
            <div class="col-6 bill-amount" style="<?= in_array((int)$e['billing_model'], [1, 3], true) ? '' : 'display:none' ?>">
              <label class="form-label small mb-0">Kwota (zł)</label>
              <input type="number" name="billing_amount" class="form-control form-control-sm" step="0.01" min="0" value="<?= h(number_format((float)$e['billing_amount'], 2, '.', '')) ?>">
            </div>
          </div>
          <div class="row g-2">
            <div class="col-12">
              <label class="form-label small mb-0">Nr konta (indyw., gdy kod 9999)</label>
              <input type="text" name="pay_account" class="form-control form-control-sm font-monospace" value="<?= h($e['pay_account'] ?? '') ?>" placeholder="puste = domyślny kursu / organizacji">
            </div>
            <div class="col-sm-8">
              <label class="form-label small mb-0">Tytuł wpłaty (indyw.)</label>
              <input type="text" name="pay_title" class="form-control form-control-sm" value="<?= h($e['pay_title'] ?? '') ?>" placeholder="puste = automatyczny">
            </div>
            <div class="col-sm-4">
              <label class="form-label small mb-0">Termin płatn. (dni)</label>
              <input type="number" name="pay_due_days" class="form-control form-control-sm" min="0" max="365" value="<?= !empty($e['pay_due_days']) ? (int)$e['pay_due_days'] : '' ?>" placeholder="<?= $course_due ?>" title="puste = jak kurs (<?= $course_due ?> dni)">
            </div>
          </div>
          <p class="form-text mt-2 mb-0">Wybór indywidualnego modelu nadaje kursantowi kod 9999. Dane do wpłat działają tylko przy kodzie 9999 (inaczej obowiązują domyślne kursu, a przy pustych — konto organizacji „dla TI" i tytuł automatyczny).</p>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1" aria-hidden="true"></i>Zapisz</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endforeach; ?>

<!-- Modale: stały link Zoom per kursant -->
<?php if (zoom_enabled()): foreach ($enrollments as $e): if ($e['status'] !== 'active') continue; ?>
<div class="modal fade" id="zoomStu<?= (int)$e['client_id'] ?>" tabindex="-1" aria-labelledby="zoomStuLbl<?= (int)$e['client_id'] ?>" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h2 class="modal-title h6 mb-0" id="zoomStuLbl<?= (int)$e['client_id'] ?>">
          <i class="bi bi-camera-video text-primary me-2" aria-hidden="true"></i>Stały link Zoom — <?= h($e['client_name']) ?>
        </h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <?php if (!empty($e['zoom_meeting_url'])): ?>
        <div class="alert alert-success py-2 mb-3">
          <i class="bi bi-check-circle me-1" aria-hidden="true"></i>Aktywny link:<br>
          <a href="<?= h($e['zoom_meeting_url']) ?>" target="_blank" rel="noopener" class="small"><?= h($e['zoom_meeting_url']) ?></a>
          <div class="text-body-secondary" style="font-size:.72rem">ID spotkania: <?= h($e['zoom_meeting_id']) ?></div>
        </div>
        <p class="mb-0">Generowanie nowego linku <strong>usunie</strong> bieżące spotkanie Zoom i stworzy nowe (stały URL się zmieni).</p>
        <?php else: ?>
        <p>Zostanie utworzone nowe spotkanie Zoom (cykliczne bez stałego terminu) dla kursanta <strong><?= h($e['client_name']) ?></strong>.</p>
        <p class="text-body-secondary small mb-0">Link będzie widoczny kursantowi przy każdej zaplanowanej lekcji oraz w zakładce Szkolenia online.</p>
        <?php endif; ?>
      </div>
      <div class="modal-footer py-2">
        <?php if (!empty($e['zoom_meeting_url'])): ?>
        <form method="post" class="me-auto">
          <input type="hidden" name="_csrf"     value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op"       value="clear_student_zoom">
          <input type="hidden" name="client_id" value="<?= (int)$e['client_id'] ?>">
          <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1" aria-hidden="true"></i>Usuń link</button>
        </form>
        <?php endif; ?>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
        <form method="post" class="d-inline">
          <input type="hidden" name="_csrf"     value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op"       value="gen_student_zoom">
          <input type="hidden" name="client_id" value="<?= (int)$e['client_id'] ?>">
          <button type="submit" class="btn btn-primary btn-sm">
            <i class="bi bi-camera-video me-1" aria-hidden="true"></i><?= !empty($e['zoom_meeting_url']) ? 'Regeneruj link' : 'Wygeneruj link' ?>
          </button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endforeach; endif; ?>

<!-- Modal: edycja metadanych kursu przez kierownika -->
<div class="modal fade" id="courseEditModal" tabindex="-1" aria-labelledby="courseEditLbl" aria-hidden="true">
 <div class="modal-dialog modal-lg modal-dialog-scrollable">
  <div class="modal-content">
   <div class="modal-header py-2">
     <h2 class="modal-title h6 mb-0" id="courseEditLbl"><i class="bi bi-pencil me-2 text-primary" aria-hidden="true"></i>Edytuj kurs — <?= h($course['name']) ?></h2>
     <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
   </div>
   <form method="post">
   <div class="modal-body">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="_op"   value="update_course">
    <div class="row g-2">
      <div class="col-sm-8">
        <label class="form-label small fw-semibold mb-1" for="ed_name">Nazwa grupy <span class="text-danger">*</span></label>
        <input type="text" id="ed_name" name="name" class="form-control form-control-sm" required maxlength="200"
               value="<?= h($course['name']) ?>">
      </div>
      <div class="col-sm-4">
        <label class="form-label small fw-semibold mb-1" for="ed_gc">Kod grupy</label>
        <input type="text" id="ed_gc" class="form-control form-control-sm font-monospace" value="<?= h((string)($course['group_code'] ?? '')) ?>" readonly>
        <div class="form-text mt-0">Niezmienny po utworzeniu.</div>
      </div>

      <div class="col-sm-8">
        <label class="form-label small fw-semibold mb-1" for="ed_display">Nazwa dla kursanta</label>
        <input type="text" id="ed_display" name="display_name" class="form-control form-control-sm" maxlength="160"
               value="<?= h((string)($course['display_name'] ?? '')) ?>" placeholder="np. Informatyka — grupa 1">
      </div>
      <div class="col-sm-4">
        <label class="form-label small fw-semibold mb-1" for="ed_class">Typ</label>
        <select id="ed_class" name="class_type" class="form-select form-select-sm">
          <option value="individual" <?= ($course['class_type'] ?? 'individual') === 'individual' ? 'selected' : '' ?>>Indywidualny</option>
          <option value="group" <?= ($course['class_type'] ?? '') === 'group' ? 'selected' : '' ?>>Grupowy</option>
        </select>
      </div>

      <div class="col-sm-4">
        <label class="form-label small fw-semibold mb-1" for="ed_subject">Rodzaj zajęć</label>
        <select id="ed_subject" name="subject_type_id" class="form-select form-select-sm">
          <option value="">— nie określono —</option>
          <?php foreach ($ed_subjects as $st):
            if (empty($st['is_active']) && (int)($course['subject_type_id'] ?? 0) !== (int)$st['id']) continue; ?>
          <option value="<?= (int)$st['id'] ?>" <?= (int)($course['subject_type_id'] ?? 0) === (int)$st['id'] ? 'selected' : '' ?>>
            <?= h($st['abbreviation']) ?> — <?= h($st['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-4">
        <label class="form-label small fw-semibold mb-1" for="ed_instr">Prowadzący</label>
        <select id="ed_instr" name="instructor_id" class="form-select form-select-sm">
          <option value="0">— brak przypisania —</option>
          <?php foreach ($ed_instructors as $ins): ?>
          <option value="<?= (int)$ins['id'] ?>" <?= (int)($course['instructor_id'] ?? 0) === (int)$ins['id'] ? 'selected' : '' ?>><?= h($ins['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-4">
        <label class="form-label small fw-semibold mb-1" for="ed_bb">Stawka BB <span class="text-body-secondary fw-normal">(zł/lekcja)</span></label>
        <input type="number" id="ed_bb" name="lesson_payout_bb" step="0.01" min="0" class="form-control form-control-sm"
               value="<?= h(number_format((float)($course['lesson_payout_bb'] ?? 0), 2, '.', '')) ?>">
      </div>

      <div class="col-sm-4">
        <label class="form-label small fw-semibold mb-1" for="ed_bm">Model rozliczania</label>
        <select id="ed_bm" name="billing_model" class="form-select form-select-sm"
                onchange="document.getElementById('ed_bm_amount_wrap').style.display = this.value==='2' ? 'none' : ''">
          <?php foreach ([1, 2, 3] as $bm_code): ?>
          <option value="<?= $bm_code ?>" <?= $cbm === $bm_code ? 'selected' : '' ?>><?= h(k30_ti_billing_model_label($bm_code)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-4" id="ed_bm_amount_wrap" style="<?= $cbm === 2 ? 'display:none' : '' ?>">
        <label class="form-label small fw-semibold mb-1" for="ed_bm_amount">Kwota (zł)</label>
        <input type="number" id="ed_bm_amount" name="billing_amount" step="0.01" min="0" class="form-control form-control-sm"
               value="<?= h(number_format((float)($course['billing_amount'] ?? 0), 2, '.', '')) ?>">
      </div>
      <div class="col-sm-4">
        <label class="form-label small fw-semibold mb-1" for="ed_due">Termin płatności <span class="text-body-secondary fw-normal">(dni)</span></label>
        <input type="number" id="ed_due" name="pay_due_days" min="0" max="365" class="form-control form-control-sm"
               value="<?= !empty($course['pay_due_days']) ? (int)$course['pay_due_days'] : '' ?>" placeholder="<?= K30_TI_PAY_DUE_DAYS_DEFAULT ?>">
      </div>

      <div class="col-sm-7">
        <label class="form-label small fw-semibold mb-1" for="ed_pay_acc">Nr konta do wpłat <span class="text-body-secondary fw-normal">(domyślny)</span></label>
        <select id="ed_pay_acc" name="pay_account" class="form-select form-select-sm">
          <option value="">— domyślne: konto dla TI z Ustawień organizacji —</option>
          <?php foreach ($ed_accounts as $ka): ?>
          <option value="<?= h($ka['iban']) ?>" <?= $ed_cur_acc === $ka['iban'] ? 'selected' : '' ?>>
            <?= h($ka['iban']) ?><?= $ka['opis'] !== '' ? ' · ' . h($ka['opis']) : '' ?><?= $ka['ti'] ? ' · TI' : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-5">
        <label class="form-label small fw-semibold mb-1" for="ed_pay_title">Tytuł wpłaty <span class="text-body-secondary fw-normal">(domyślny)</span></label>
        <input type="text" id="ed_pay_title" name="pay_title" class="form-control form-control-sm"
               value="<?= h((string)($course['pay_title'] ?? '')) ?>" placeholder="puste = automatyczny TI/nr/kod">
      </div>

      <div class="col-sm-6">
        <label class="form-label small fw-semibold mb-1" for="ed_loc">Lokalizacja / sala</label>
        <input type="text" id="ed_loc" name="location" class="form-control form-control-sm" value="<?= h((string)($course['location'] ?? '')) ?>">
      </div>
      <div class="col-sm-6">
        <label class="form-label small fw-semibold mb-1" for="ed_url">Stały link do zajęć online</label>
        <input type="url" id="ed_url" name="default_meeting_url" class="form-control form-control-sm"
               value="<?= h((string)($course['default_meeting_url'] ?? '')) ?>" placeholder="https://… (Teams/Zoom/Meet)">
      </div>

      <div class="col-12">
        <label class="form-label small fw-semibold mb-1" for="ed_desc">Opis</label>
        <textarea id="ed_desc" name="description" class="form-control form-control-sm" rows="2"><?= h((string)($course['description'] ?? '')) ?></textarea>
      </div>

      <div class="col-12 d-flex flex-wrap gap-3 mt-1">
        <div class="form-check form-switch m-0">
          <input class="form-check-input" type="checkbox" name="is_active" id="ed_active" <?= !empty($course['is_active']) ? 'checked' : '' ?>>
          <label class="form-check-label small" for="ed_active">Kurs aktywny</label>
        </div>
        <div class="form-check form-switch m-0">
          <input class="form-check-input" type="checkbox" name="is_oneoff" id="ed_oneoff" <?= !empty($course['is_oneoff']) ? 'checked' : '' ?>
                 onchange="document.getElementById('ed_oneoff_wrap').style.display = this.checked ? '' : 'none'">
          <label class="form-check-label small" for="ed_oneoff">Kurs jednorazowy <span class="text-body-secondary">(sekcja Działania)</span></label>
        </div>
        <div class="form-check form-switch m-0">
          <input class="form-check-input" type="checkbox" name="track_attendance" id="ed_att" <?= !isset($course['track_attendance']) || $course['track_attendance'] ? 'checked' : '' ?>>
          <label class="form-check-label small" for="ed_att">Licz frekwencję</label>
        </div>
        <div class="form-check form-switch m-0">
          <input class="form-check-input" type="checkbox" name="is_subgroup" id="ed_sub" <?= !empty($course['is_subgroup']) ? 'checked' : '' ?>>
          <label class="form-check-label small" for="ed_sub">Podgrupa <span class="text-body-secondary">(lekcje zawsze 1I)</span></label>
        </div>
        <div class="form-check form-switch m-0">
          <input class="form-check-input" type="checkbox" name="is_online" id="ed_onl" <?= !empty($course['is_online']) ? 'checked' : '' ?>>
          <label class="form-check-label small" for="ed_onl">Zajęcia online</label>
        </div>
        <div class="form-check form-switch m-0">
          <input class="form-check-input" type="checkbox" name="no_invoice" id="ed_noinv" <?= !empty($course['no_invoice']) ? 'checked' : '' ?>>
          <label class="form-check-label small" for="ed_noinv">Bez fakturowania</label>
        </div>
        <?php $bf_course_pid = (int)org_setting('betterfly_ti_product_course_' . $id); ?>
        <div class="m-0">
          <label class="form-label small mb-1" for="ed_bf_pid">Betterfly ProductId <span class="text-body-secondary">(opcjonalnie)</span></label>
          <input type="number" class="form-control form-control-sm" name="betterfly_product_id" id="ed_bf_pid"
                 style="width:9rem" value="<?= $bf_course_pid > 0 ? $bf_course_pid : '' ?>" placeholder="domyślny">
          <div class="form-text" style="font-size:.68rem">Nadpisuje domyślny produkt TI dla pozycji tego kursu na fakturze Betterfly.</div>
        </div>
        <div class="form-check form-switch m-0">
          <input class="form-check-input" type="checkbox" name="wup_exclude" id="ed_wup" <?= !empty($course['wup_exclude']) ? 'checked' : '' ?>>
          <label class="form-check-label small" for="ed_wup">Poza raportem WUP</label>
        </div>
      </div>

      <div class="col-sm-5" id="ed_oneoff_wrap" style="<?= !empty($course['is_oneoff']) ? '' : 'display:none' ?>">
        <label class="form-label small fw-semibold mb-1" for="ed_oneoff_date">Termin realizacji (kurs jednorazowy)</label>
        <input type="date" id="ed_oneoff_date" name="oneoff_date" class="form-control form-control-sm"
               value="<?= h((string)($course['oneoff_date'] ?? '')) ?>">
        <div class="form-text mt-0">Termin synchronizuje się z powiązanym działaniem w Strategii.</div>
      </div>

      <div class="col-12"><hr class="my-1"></div>
      <div class="col-sm-5">
        <label class="form-label small fw-semibold mb-1" for="ed_plan_status">
          <i class="bi bi-signpost-split me-1 text-primary" aria-hidden="true"></i>Status planowania</label>
        <select id="ed_plan_status" name="plan_status" class="form-select form-select-sm"
                onchange="var w=document.getElementById('ed_plan_note_wrap'),n=document.getElementById('ed_plan_note');w.style.display=this.value?'':'none';n.required=!!this.value;">
          <option value="">— brak (kurs działa normalnie) —</option>
          <?php foreach (K30_TI_COURSE_PLAN_STATUSES as $pk => $pl): ?>
          <option value="<?= h($pk) ?>" <?= (string)($course['plan_status'] ?? '') === $pk ? 'selected' : '' ?>><?= h($pl['label']) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-text mt-0">Informacja dla zespołu — nie wyłącza kursu ani nie zmienia rozliczeń.</div>
      </div>
      <div class="col-sm-7" id="ed_plan_note_wrap" style="<?= !empty($course['plan_status']) ? '' : 'display:none' ?>">
        <label class="form-label small fw-semibold mb-1" for="ed_plan_note">Uzasadnienie <span class="text-danger">*</span></label>
        <textarea id="ed_plan_note" name="plan_note" class="form-control form-control-sm" rows="2"
                  maxlength="500" <?= !empty($course['plan_status']) ? 'required' : '' ?>
                  placeholder="np. Niska liczba zapisów, decyzja o wygaszeniu od nowego roku szkolnego"><?= h((string)($course['plan_note'] ?? '')) ?></textarea>
      </div>
    </div>
   </div>
   <div class="modal-footer py-2">
     <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
     <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1" aria-hidden="true"></i>Zapisz zmiany</button>
   </div>
   </form>
  </div>
 </div>
</div>

<script>
function billToggle(sel) {
  var body   = sel.closest('.modal-body');
  var model  = parseInt(sel.value, 10);
  var fixed  = (model === 1 || model === 3);
  body.querySelector('.bill-rate').style.display   = fixed ? 'none' : '';
  body.querySelector('.bill-amount').style.display = fixed ? '' : 'none';
}
</script>

</main>
<?php $PRINT_TITLE = 'Grupa: ' . $course['name']; include __DIR__ . '/_print_page.php'; ?>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
