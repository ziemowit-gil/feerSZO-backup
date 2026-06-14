<?php
/**
 * api/ajax.php — Ogólny endpoint AJAX dla szybkich akcji in-page
 *
 * POST: action, id, type, value, _csrf
 * Response: {ok: bool, msg: string, data: object}
 *
 * Obsługiwane akcje:
 *   set_status         — zmiana statusu umowy
 *   set_favorite       — toggle ulubione/przypięte
 *   read_message       — oznacz wątek jako przeczytany
 *   dismiss_notification — oznacz powiadomienie jako przeczytane
 */
define('SKIP_CONSENT_CHECK', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/approval.php';
require_once dirname(__DIR__) . '/includes/messages.php';
require_once dirname(__DIR__) . '/includes/notifications.php';
require_once dirname(__DIR__) . '/includes/rozliczenia.php';
require_once dirname(__DIR__) . '/includes/ksiegowy_email.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

// ── Auth ──────────────────────────────────────────────────────────────────────
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Method not allowed']);
    exit;
}

csrf_check();

$action = trim($_POST['action'] ?? '');
$id     = (int)($_POST['id'] ?? 0);
$type   = trim($_POST['type'] ?? '');
$value  = trim($_POST['value'] ?? '');

// ── Helpers ───────────────────────────────────────────────────────────────────
function ajax_ok(array $data = [], string $msg = ''): void {
    echo json_encode(['ok' => true, 'msg' => $msg] + $data);
    exit;
}

function ajax_err(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'msg' => $msg]);
    exit;
}

// ── Routing ───────────────────────────────────────────────────────────────────
switch ($action) {

    // ── set_status ────────────────────────────────────────────────────────────
    case 'set_status': {
        if (!can_edit()) ajax_err('Brak uprawnień', 403);
        if (!$id)        ajax_err('Brak id');
        if (!$type)      ajax_err('Brak type');
        if (!$value)     ajax_err('Brak value');

        if (!isset(STATUS_LABELS[$value])) {
            ajax_err('Nieznany status: ' . $value);
        }

        $table = 'umowy_' . $type;
        if (!isset(CONTRACT_TYPES[$type])) {
            ajax_err('Nieznany typ umowy: ' . $type);
        }

        try {
            $row = db_one("SELECT status FROM {$table} WHERE id=?", [$id]);
        } catch (\Throwable $e) {
            ajax_err('Nie znaleziono umowy');
        }

        if (!$row) ajax_err('Nie znaleziono umowy');

        $old_status = $row['status'];
        if ($old_status === $value) {
            // Nic do zmiany — zwróć aktualny stan
            $st = STATUS_LABELS[$value];
            ajax_ok([
                'label'       => $st['label'],
                'badge_class' => $st['class'],
            ], 'Status niezmieniony');
        }

        // Walidacja dozwolonych przejść — edytorzy tylko do przodu, admini bez ograniczeń
        $is_admin = (current_user()['role'] ?? '') === 'admin';
        if (!$is_admin) {
            $allowed_next = STATUS_TRANSITIONS[$old_status] ?? [];
            if (!in_array($value, $allowed_next, true)) {
                $from_lbl = STATUS_LABELS[$old_status]['label'] ?? $old_status;
                $to_lbl   = STATUS_LABELS[$value]['label']      ?? $value;
                ajax_err('Niedozwolona zmiana: ' . $from_lbl . ' → ' . $to_lbl, 403);
            }
        }

        try {
            db_update($table, ['status' => $value], $id);
            log_contract_action(
                $type, $id,
                (int)current_user()['id'],
                'status_change',
                'Zmiana statusu: ' . $old_status . ' → ' . $value
            );
        } catch (\Throwable $e) {
            ajax_err('Błąd zapisu: ' . $e->getMessage());
        }

        $st = STATUS_LABELS[$value];
        $extra = [
            'label'       => $st['label'],
            'badge_class' => $st['class'],
        ];

        // Proces „Umowa do rozliczenia” — sygnał do otwarcia okienka rozliczenia
        if ($type === 'zlecenie' && $value === 'do rozliczenia') {
            $full = db_one("SELECT imie_nazwisko, data_zawarcia, wynagrodzenie_brutto,
                                   liczba_godzin_planowana, data_rachunku, okres_rachunku
                            FROM umowy_zlecenie WHERE id=?", [$id]) ?: [];
            $extra['open_rozliczenie'] = true;
            $extra['prefill'] = [
                'imie_nazwisko'  => $full['imie_nazwisko'] ?? '',
                'data_umowy'     => !empty($full['data_zawarcia']) ? date_pl($full['data_zawarcia']) : '',
                'kwota_brutto'   => $full['wynagrodzenie_brutto'] ?? '',
                'liczba_godzin'  => $full['liczba_godzin_planowana'] ?? '',
                'data_rachunku'  => $full['data_rachunku'] ?? '',
                'okres'          => $full['okres_rachunku'] ?? '',
            ];
        }
        ajax_ok($extra, 'Status zaktualizowany');
    }

    // ── rozliczenie_create — utwórz rekord rozliczenia + zapisz dane na umowie ───
    case 'rozliczenie_create': {
        if (!can_edit())        ajax_err('Brak uprawnień', 403);
        if ($type !== 'zlecenie') ajax_err('Nieobsługiwany typ umowy');
        if (!$id)               ajax_err('Brak id umowy');

        $contract = db_one("SELECT * FROM umowy_zlecenie WHERE id=?", [$id]);
        if (!$contract) ajax_err('Nie znaleziono umowy');

        $data_rachunku = trim($_POST['data_rachunku'] ?? '');
        $okres         = trim($_POST['okres'] ?? '');
        $kwota         = trim($_POST['kwota_brutto'] ?? '');
        $godziny       = trim($_POST['liczba_godzin'] ?? '');
        $uwagi         = trim($_POST['uwagi'] ?? '');

        $uid = (int)current_user()['id'];
        $rid = create_rozliczenie([
            'contract_id'   => $id,
            'data_rachunku' => $data_rachunku ?: null,
            'okres'         => $okres ?: null,
            'kwota_brutto'  => $kwota,
            'liczba_godzin' => $godziny ?: null,
            'uwagi'         => $uwagi ?: null,
        ], $uid);

        // Przepisz najświeższe dane rachunku na umowę (dla spójności widoku/PDF)
        db_update('umowy_zlecenie', [
            'data_rachunku'        => $data_rachunku ?: null,
            'okres_rachunku'       => $okres ?: null,
            'wynagrodzenie_brutto' => $kwota === '' ? $contract['wynagrodzenie_brutto'] : (float)$kwota,
        ], $id);

        log_contract_action('zlecenie', $id, $uid, 'rozliczenie_create',
            'Rozliczenie #' . $rid . ($okres ? ' za ' . $okres : ''));

        $rozl  = get_rozliczenie($rid);
        $tekst = ksiegowy_rachunek_email_text(rozliczenie_email_row($contract, $rozl));

        ajax_ok(['rozliczenie_id' => $rid, 'email_text' => $tekst], 'Rozliczenie zapisane');
    }

    // ── rozliczenie_send — wyślij blok e-mail do księgowego (kolejka) ───────────
    case 'rozliczenie_send': {
        if (!can_edit()) ajax_err('Brak uprawnień', 403);
        $rid = (int)($_POST['rozliczenie_id'] ?? 0);
        if (!$rid) ajax_err('Brak id rozliczenia');

        $rozl = get_rozliczenie($rid);
        if (!$rozl) ajax_err('Nie znaleziono rozliczenia');

        $ksieg = trim(db_one("SELECT value FROM settings WHERE key_='ksiegowy_email'")['value'] ?? '');
        if (!$ksieg || !filter_var($ksieg, FILTER_VALIDATE_EMAIL)) {
            ajax_err('Brak poprawnego adresu księgowego — uzupełnij go w Ustawieniach organizacji → Poczta.');
        }

        $contract = db_one("SELECT * FROM umowy_zlecenie WHERE id=?", [(int)$rozl['contract_id']]);
        if (!$contract) ajax_err('Nie znaleziono umowy');

        require_once dirname(__DIR__) . '/includes/mail_queue.php';
        $tekst   = ksiegowy_rachunek_email_text(rozliczenie_email_row($contract, $rozl));
        $org     = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'Fundacja');
        $subject = 'Dane do rachunku — ' . ($contract['imie_nazwisko'] ?? '')
                 . ($contract['numer_umowy'] ? ' (' . $contract['numer_umowy'] . ')' : '');
        $body_html = '<div style="font-family:Segoe UI,Arial,sans-serif;white-space:pre-wrap;font-size:14px;color:#212529">'
                   . nl2br(h($tekst)) . '</div>';

        try {
            $mail_id = mail_queue_add($ksieg, '', $subject, $body_html, $tekst, 'zlecenie', (int)$rozl['contract_id'], '', true);
        } catch (\Throwable $e) {
            ajax_err('Błąd wysyłki: ' . $e->getMessage());
        }

        $uid = (int)current_user()['id'];
        rozliczenie_mark_sent($rid, $uid, $ksieg, $mail_id);
        log_contract_action('zlecenie', (int)$rozl['contract_id'], $uid, 'rozliczenie_sent',
            'Rozliczenie #' . $rid . ' → ' . $ksieg);

        ajax_ok(['sent_to' => $ksieg], 'Wysłano do księgowego: ' . $ksieg);
    }

    // ── rozliczenie_settle — oznacz rozliczenie jako rozliczone ─────────────────
    case 'rozliczenie_settle': {
        if (!can_edit()) ajax_err('Brak uprawnień', 403);
        $rid = (int)($_POST['rozliczenie_id'] ?? 0);
        if (!$rid) ajax_err('Brak id rozliczenia');

        $rozl = get_rozliczenie($rid);
        if (!$rozl) ajax_err('Nie znaleziono rozliczenia');

        $uid = (int)current_user()['id'];
        rozliczenie_mark_settled($rid, $uid);
        log_contract_action('zlecenie', (int)$rozl['contract_id'], $uid, 'rozliczenie_settled',
            'Rozliczenie #' . $rid . ' oznaczone jako rozliczone');

        ajax_ok([], 'Oznaczono jako rozliczone');
    }

    // ── set_favorite ──────────────────────────────────────────────────────────
    case 'set_favorite': {
        if (!can_edit()) ajax_err('Brak uprawnień', 403);
        if (!$id)        ajax_err('Brak id');
        if (!$type)      ajax_err('Brak type');

        if (!isset(CONTRACT_TYPES[$type])) {
            ajax_err('Nieznany typ umowy: ' . $type);
        }

        $table = 'umowy_' . $type;

        // Dodaj kolumnę jeśli nie istnieje (bezpieczny try/catch)
        try {
            db()->exec("ALTER TABLE {$table} ADD COLUMN is_favorite INTEGER NOT NULL DEFAULT 0");
        } catch (\Throwable $e) {
            // Kolumna już istnieje — ignoruj
        }

        try {
            $row = db_one("SELECT is_favorite FROM {$table} WHERE id=?", [$id]);
        } catch (\Throwable $e) {
            ajax_err('Nie znaleziono umowy');
        }

        if (!$row) ajax_err('Nie znaleziono umowy');

        $new_fav = $row['is_favorite'] ? 0 : 1;

        try {
            db_update($table, ['is_favorite' => $new_fav], $id);
        } catch (\Throwable $e) {
            ajax_err('Błąd zapisu: ' . $e->getMessage());
        }

        ajax_ok(['is_favorite' => (bool)$new_fav], $new_fav ? 'Dodano do ulubionych' : 'Usunięto z ulubionych');
    }

    // ── read_message ──────────────────────────────────────────────────────────
    case 'read_message': {
        $ctx_type = trim($_POST['context_type'] ?? '');
        $ctx_id   = (int)($_POST['context_id'] ?? 0);

        if (!$ctx_type || !$ctx_id) ajax_err('Brak context_type lub context_id');

        $u = current_user();
        // Admini czytają wiadomości od userów (sender_type='user')
        msg_mark_read($ctx_type, $ctx_id, 'admin');

        $unread = msg_unread_admin();
        ajax_ok(['unread_count' => $unread], 'Oznaczono jako przeczytane');
    }

    // ── dismiss_notification ──────────────────────────────────────────────────
    case 'dismiss_notification': {
        $notif_id = (int)($_POST['notification_id'] ?? 0);
        if (!$notif_id) ajax_err('Brak notification_id');

        $u = current_user();
        notif_migrate();
        notif_mark_read((int)$u['id'], $notif_id);

        ajax_ok([], 'Powiadomienie odrzucone');
    }

    default:
        ajax_err('Nieznana akcja: ' . $action);
}
