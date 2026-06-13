<?php
/**
 * contracts/wolontariat/dyspo_action.php
 *
 * Wspólny endpoint akcji dla dyspozycyjności wolontariusza (sloty) i urlopów.
 * Obsługuje POST z dwóch miejsc:
 *   - admin/edytor: edit.php (AJAX/fetch) oraz view.php (zwykły POST)
 *   - wolontariusz: panel/dyspozycyjnosc.php (zwykły POST)
 *
 * Autoryzacja:
 *   - admin/edytor (can_edit) → dowolna umowa wolontariatu
 *   - wolontariusz → tylko własna umowa (dyspo_contract_for_user)
 *   - decyzja o urlopie (urlop_decide) → tylko admin/edytor (akceptacja formalna)
 *
 * Odpowiedź:
 *   - AJAX (_ajax=1 lub Accept: application/json) → JSON {ok, slots, urlopy, error}
 *   - zwykły POST → redirect na ?return= (lub Referer) z flashem
 */
declare(strict_types=1);

require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/approval.php';        // log_contract_action()
require_once dirname(dirname(__DIR__)) . '/includes/dyspozycyjnosc.php';

require_login();
dyspo_migrate();

$is_ajax = ($_POST['_ajax'] ?? '') === '1'
        || stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;

function dyspo_respond(bool $ok, ?int $contract_id, string $msg, string $flash_type, bool $is_ajax): void {
    if ($is_ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'     => $ok,
            'error'  => $ok ? null : $msg,
            'slots'  => $contract_id ? dyspo_slots($contract_id) : [],
            'urlopy' => $contract_id ? urlop_list($contract_id) : [],
        ]);
        exit;
    }
    flash_set($ok ? $flash_type : 'danger', $msg);
    $return = $_POST['return'] ?? ($_SERVER['HTTP_REFERER'] ?? (APP_URL . '/panel/index.php'));
    // Tylko ścieżki lokalne — nie pozwól na open-redirect.
    if (!preg_match('#^' . preg_quote(APP_URL, '#') . '#', $return) && $return[0] !== '/') {
        $return = APP_URL . '/panel/index.php';
    }
    header('Location: ' . $return);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    dyspo_respond(false, null, 'Nieprawidłowe żądanie.', 'danger', $is_ajax);
}
csrf_check();

if (!module_enabled('dyspozycyjnosc_enabled')) {
    dyspo_respond(false, null, 'Moduł dyspozycyjności jest wyłączony.', 'danger', $is_ajax);
}

$action      = $_POST['action'] ?? '';
$contract_id = (int)($_POST['contract_id'] ?? 0);
$user        = current_user();
$uid         = (int)($user['id'] ?? 0);
$is_editor   = can_edit();

// ── Autoryzacja umowy ─────────────────────────────────────────────────────────
$contract = $contract_id
    ? db_one("SELECT * FROM umowy_wolontariat WHERE id=?", [$contract_id])
    : null;

if (!$contract) {
    dyspo_respond(false, null, 'Nie znaleziono umowy.', 'danger', $is_ajax);
}

if (!$is_editor) {
    // Wolontariusz — musi to być jego własna umowa.
    $user['microsoft_id'] = $user['microsoft_id']
        ?? (db_one("SELECT microsoft_id FROM users WHERE id=?", [$uid])['microsoft_id'] ?? '');
    $own = dyspo_contract_for_user($user);
    if (!$own || (int)$own['id'] !== $contract_id) {
        dyspo_respond(false, null, 'Brak dostępu do tej umowy.', 'danger', $is_ajax);
    }
}

$source = $is_editor ? 'admin' : 'wolontariusz';

// ── Akcje ───────────────────────────────────────────────────────────────────
switch ($action) {

    case 'slot_add': {
        $new = dyspo_add_slot($contract_id, [
            'data'    => $_POST['data']    ?? '',
            'czas_od' => $_POST['czas_od'] ?? '',
            'czas_do' => $_POST['czas_do'] ?? '',
            'notatka' => $_POST['notatka'] ?? '',
        ], $source, $uid);
        if (!$new) {
            dyspo_respond(false, $contract_id, 'Nieprawidłowe dane slotu (sprawdź datę i godziny).', 'danger', $is_ajax);
        }
        log_contract_action('wolontariat', $contract_id, $uid, 'edit', 'Dodano slot dyspozycyjności');
        dyspo_respond(true, $contract_id, 'Dodano dyspozycyjność.', 'success', $is_ajax);
        break;
    }

    case 'slot_del': {
        $sid = (int)($_POST['id'] ?? 0);
        dyspo_delete_slot($sid, $contract_id);
        log_contract_action('wolontariat', $contract_id, $uid, 'edit', 'Usunięto slot dyspozycyjności');
        dyspo_respond(true, $contract_id, 'Usunięto dyspozycyjność.', 'success', $is_ajax);
        break;
    }

    case 'urlop_add': {
        $new = urlop_add($contract_id, [
            'data_od' => $_POST['data_od'] ?? '',
            'data_do' => $_POST['data_do'] ?? '',
            'powod'   => $_POST['powod']   ?? '',
        ], $source, $uid);
        if (!$new) {
            dyspo_respond(false, $contract_id, 'Nieprawidłowy zakres dat urlopu.', 'danger', $is_ajax);
        }
        log_contract_action('wolontariat', $contract_id, $uid, 'edit', 'Zgłoszono urlop / niedostępność');
        dyspo_respond(true, $contract_id, 'Urlop zgłoszony — oczekuje na akceptację.', 'success', $is_ajax);
        break;
    }

    case 'urlop_del': {
        $uidel = (int)($_POST['id'] ?? 0);
        if (!$is_editor) {
            // Wolontariusz może usunąć tylko własny, jeszcze nierozpatrzony wpis.
            $u = db_one("SELECT status FROM wol_urlopy WHERE id=? AND contract_id=?", [$uidel, $contract_id]);
            if (!$u || $u['status'] !== 'oczekuje') {
                dyspo_respond(false, $contract_id, 'Można wycofać tylko wniosek oczekujący na akceptację.', 'danger', $is_ajax);
            }
        }
        urlop_delete($uidel, $contract_id);
        log_contract_action('wolontariat', $contract_id, $uid, 'edit', 'Usunięto wpis urlopu');
        dyspo_respond(true, $contract_id, 'Usunięto urlop.', 'success', $is_ajax);
        break;
    }

    case 'urlop_decide': {
        if (!$is_editor) {
            dyspo_respond(false, $contract_id, 'Tylko opiekun/administrator może akceptować urlopy.', 'danger', $is_ajax);
        }
        $uidel    = (int)($_POST['id'] ?? 0);
        $decision = $_POST['decision'] ?? '';
        $note     = trim($_POST['decision_note'] ?? '');
        // Wpis musi należeć do tej umowy.
        $u = db_one("SELECT id FROM wol_urlopy WHERE id=? AND contract_id=?", [$uidel, $contract_id]);
        if (!$u || !urlop_decide($uidel, $decision, $note, $uid)) {
            dyspo_respond(false, $contract_id, 'Nie udało się zapisać decyzji.', 'danger', $is_ajax);
        }
        $label = $decision === 'zaakceptowany' ? 'Zaakceptowano urlop' : 'Odrzucono urlop';
        log_contract_action('wolontariat', $contract_id, $uid, 'edit', $label . ($note ? ' — ' . $note : ''));
        dyspo_respond(true, $contract_id, $label . '.', 'success', $is_ajax);
        break;
    }

    default:
        dyspo_respond(false, $contract_id, 'Nieznana akcja.', 'danger', $is_ajax);
}
