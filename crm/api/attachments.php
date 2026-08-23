<?php
/**
 * crm/api/attachments.php — poczekalnia załączników wysyłki e-mail z CRM.
 *
 * Wszystkie akcje zwracają JSON i wymagają CSRF-a.
 *
 *  POST multipart  a=upload            files[]                → {ok, items:[{token,name,size,mime,source}]}
 *  POST JSON       a=onedrive_list     {folder, q}            → {ok, items:[{id,name,size,folder,mime}]}
 *  POST JSON       a=onedrive_pick     {ids:[itemId,…]}       → {ok, items:[…], errors:[…]}
 *  POST JSON       a=drop              {token}                → {ok}
 *
 * Klient nigdy nie widzi ścieżki pliku — operuje wyłącznie tokenem z poczekalni
 * (includes/crm_attachments.php).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_attachments.php';

header('Content-Type: application/json; charset=utf-8');

function att_out(array $d): void { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

if (!current_user()) {
    http_response_code(401);
    att_out(['ok' => false, 'error' => 'Wymagane logowanie.']);
}
if (!can_write('crm') && !is_admin()) {
    http_response_code(403);
    att_out(['ok' => false, 'error' => 'Brak uprawnień.']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    att_out(['ok' => false, 'error' => 'Tylko POST.']);
}

// Multipart (upload) niesie pola w $_POST, reszta akcji leci JSON-em.
$ct   = $_SERVER['CONTENT_TYPE'] ?? '';
$json = str_contains($ct, 'application/json') ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];
$in   = $json ?: $_POST;

if (($in['_csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) {
    http_response_code(403);
    att_out(['ok' => false, 'error' => 'Nieprawidłowy token CSRF.']);
}

$action = (string)($in['a'] ?? '');

/** Deskryptor dla klienta — bez ścieżki na dysku. */
function att_public(string $token, array $att, string $source): array {
    return [
        'token'  => $token,
        'name'   => $att['name'],
        'size'   => (int)$att['size'],
        'mime'   => $att['mime'],
        'source' => $source,
    ];
}

// ── Upload z dysku użytkownika ─────────────────────────────────────────────
if ($action === 'upload') {
    if (empty($_FILES['files']['name'][0])) {
        att_out(['ok' => false, 'error' => 'Nie wybrano pliku.']);
    }
    $f = $_FILES['files'];
    $items = $errors = [];
    for ($i = 0, $n = count($f['name']); $i < $n; $i++) {
        $res = crm_att_stage_upload([
            'name'     => $f['name'][$i],
            'tmp_name' => $f['tmp_name'][$i],
            'error'    => $f['error'][$i],
            'size'     => $f['size'][$i],
        ]);
        if ($res['ok']) $items[] = att_public($res['token'], $res['att'], 'upload');
        else            $errors[] = $res['error'];
    }
    att_out(['ok' => (bool)$items, 'items' => $items, 'errors' => $errors,
             'error' => $items ? '' : implode(' ', $errors)]);
}

// ── Usunięcie z poczekalni ─────────────────────────────────────────────────
if ($action === 'drop') {
    att_out(['ok' => crm_att_drop((string)($in['token'] ?? ''))]);
}

// ── OneDrive (tylko konta połączone z Microsoft 365) ───────────────────────
if ($action === 'onedrive_list' || $action === 'onedrive_pick') {
    if (!crm_att_onedrive_available()) {
        att_out(['ok' => false, 'error' => 'Twoje konto nie jest połączone z Microsoft 365 — import z OneDrive niedostępny.']);
    }
    require_once dirname(dirname(__DIR__)) . '/includes/m365.php';

    $upn = crm_att_onedrive_upn();
    try {
        $g = new M365Graph();

        // Drive ID zapamiętujemy w sesji — inaczej każde kliknięcie w folder to dodatkowe zapytanie.
        $drive_id = (string)($_SESSION['crm_att_od_drive'][$upn] ?? '');
        if ($drive_id === '') {
            $drive = $g->od_drive($upn);
            if (empty($drive['id'])) {
                $st = $g->last_status();
                att_out(['ok' => false, 'error' => $st === 403
                    ? 'Brak uprawnienia Files.Read.All w aplikacji Graph — poproś administratora o nadanie go w Azure.'
                    : 'Nie udało się otworzyć OneDrive (' . ($st ?: 'brak odpowiedzi') . ').']);
            }
            $drive_id = (string)$drive['id'];
            $_SESSION['crm_att_od_drive'][$upn] = $drive_id;
        }

        if ($action === 'onedrive_list') {
            $q      = trim((string)($in['q'] ?? ''));
            $folder = (string)($in['folder'] ?? '');
            $raw    = $q !== '' ? $g->od_search($drive_id, $q) : $g->od_list($drive_id, $folder);

            $allowed = crm_att_allowed_ext();
            $items   = [];
            foreach ($raw as $it) {
                $is_folder = isset($it['folder']);
                $name      = (string)($it['name'] ?? '');
                $ext       = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (!$is_folder && !in_array($ext, $allowed, true)) continue;   // typy, których i tak nie dołączymy
                $items[] = [
                    'id'     => (string)($it['id'] ?? ''),
                    'name'   => $name,
                    'size'   => (int)($it['size'] ?? 0),
                    'folder' => $is_folder,
                    'ext'    => $is_folder ? '' : $ext,
                    'big'    => !$is_folder && (int)($it['size'] ?? 0) > CRM_ATT_MAX_BYTES,
                ];
            }
            att_out(['ok' => true, 'items' => $items, 'search' => $q !== '']);
        }

        // onedrive_pick — pobierz wskazane pliki i odłóż w poczekalni
        $ids   = array_slice(array_filter((array)($in['ids'] ?? []), 'is_string'), 0, CRM_ATT_MAX_FILES);
        $items = $errors = [];
        foreach ($ids as $item_id) {
            $meta = $g->od_item($drive_id, $item_id);
            if (empty($meta['id']) || isset($meta['folder'])) {
                $errors[] = 'Nie znaleziono pliku w OneDrive.';
                continue;
            }
            if ((int)($meta['size'] ?? 0) > CRM_ATT_MAX_BYTES) {
                $errors[] = 'Plik „' . ($meta['name'] ?? '') . '" przekracza 15 MB.';
                continue;
            }
            try {
                $bytes = $g->sp_download_file($drive_id, $item_id);
            } catch (\Throwable $e) {
                $errors[] = 'Nie udało się pobrać „' . ($meta['name'] ?? '') . '" z OneDrive.';
                continue;
            }
            $res = crm_att_stage_bytes((string)$meta['name'], $bytes, 'onedrive');
            if ($res['ok']) $items[] = att_public($res['token'], $res['att'], 'onedrive');
            else            $errors[] = $res['error'];
        }
        att_out(['ok' => (bool)$items, 'items' => $items, 'errors' => $errors,
                 'error' => $items ? '' : (implode(' ', $errors) ?: 'Nie wybrano pliku.')]);

    } catch (\Throwable $e) {
        att_out(['ok' => false, 'error' => 'Błąd Microsoft 365: ' . mb_substr($e->getMessage(), 0, 160)]);
    }
}

att_out(['ok' => false, 'error' => 'Nieznana akcja.']);
