<?php
/**
 * AJAX: Kreator SharePoint — test połączenia + zapis ustawień.
 *
 * POST { action: 'test', site_url, library?, base_folder? }
 *   → { ok, site_id?, site_name?, drives?, error? }
 *
 * POST { action: 'save', sp_enabled, site_url, library, base_folder, _csrf }
 *   → { ok, error?, locked? }   (zmiana witryny / biblioteki / folderu bazowego bywa zablokowana — SpConnection)
 *
 * POST { action: 'unlock', confirm: true, _csrf }  → { ok, until, until_h }   odblokowanie edycji połączenia na 30 min
 * POST { action: 'lock', _csrf }                   → { ok }                   włączenie blokady z powrotem
 * Akcje zapisujące wymagają tokenu CSRF (_csrf z sesji administratora).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/m365.php';
require_once dirname(dirname(__DIR__)) . '/modules/sp_connection/logic/spConnection.php';

header('Content-Type: application/json; charset=utf-8');

if (!current_user() || !is_admin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Brak dostępu.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metoda niedozwolona.']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

// Akcje zapisujące (save / unlock / lock) tylko z tokenem CSRF sesji — inaczej blokadę połączenia dałoby się obejść żądaniem z obcej strony
if (in_array($action, ['save', 'unlock', 'lock'], true)) {
    auth_start();
    $sess = (string)($_SESSION['csrf'] ?? '');
    if ($sess === '' || !hash_equals($sess, (string)($body['_csrf'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Błąd CSRF — odśwież stronę i spróbuj ponownie.']);
        exit;
    }
}
$uid = (int)(current_user()['id'] ?? 0);

try {

// ── LIST SITES ────────────────────────────────────────────────────────────────
if ($action === 'list_sites') {
    $graph = new M365Graph();
    if (!$graph->is_configured()) {
        echo json_encode(['ok' => false, 'error' => 'Brak konfiguracji M365.', 'goto_m365' => true]);
        exit;
    }
    try {
        $search = trim($body['search'] ?? '*') ?: '*';
        $sites  = $graph->sp_list_sites($search, 60);
        // Normalizuj: usuń root (portal SharePoint) i artefakty systemowe
        $sites = array_values(array_filter($sites, static function ($s) {
            $url  = $s['webUrl'] ?? '';
            $name = $s['name']   ?? '';
            // Pomiń root tenant i ukryte/systemowe witryny
            return $url !== '' && $name !== '' && !str_ends_with(rtrim($url, '/'), '.sharepoint.com');
        }));
        echo json_encode(['ok' => true, 'sites' => $sites, 'count' => count($sites)]);
    } catch (\Throwable $e) {
        $msg  = $e->getMessage();
        $hint = '';
        if (str_contains($msg, '403') || str_contains($msg, 'Forbidden')) {
            $hint = 'Brak uprawnienia Sites.Read.All. Dodaj je w Azure AD → API permissions i udziel admin consent.';
        }
        echo json_encode(['ok' => false, 'error' => $msg, 'hint' => $hint]);
    }
    exit;
}

// ── TEST ─────────────────────────────────────────────────────────────────────
if ($action === 'test') {
    $site_url = trim($body['site_url'] ?? '');
    if (!$site_url) {
        echo json_encode(['ok' => false, 'error' => 'Podaj URL witryny SharePoint.']);
        exit;
    }

    $graph = new M365Graph();
    if (!$graph->is_configured()) {
        echo json_encode([
            'ok'        => false,
            'error'     => 'Brak konfiguracji Microsoft 365. Skonfiguruj najpierw integrację M365 (Client ID / Secret).',
            'goto_m365' => true,
        ]);
        exit;
    }

    try {
        $site_id   = $graph->sp_site_id($site_url);
        $drives    = $graph->sp_list_drives($site_id);
        $parts     = explode(',', $site_id);
        $site_name = $parts[0] ?? null;

        echo json_encode([
            'ok'        => true,
            'site_id'   => $site_id,
            'site_name' => $site_name,
            'drives'    => array_values($drives),
        ]);
    } catch (\Throwable $e) {
        $msg  = $e->getMessage();
        $hint = '';
        if (str_contains($msg, '403') || str_contains($msg, 'Forbidden')) {
            $hint = 'Sprawdź uprawnienie Sites.ReadWrite.All w Azure AD i czy admin consent jest udzielony.';
        } elseif (str_contains($msg, '404') || str_contains($msg, 'Not Found')) {
            $hint = 'Witryna nie istnieje lub URL jest niepoprawny. Sprawdź adres SharePoint.';
        } elseif (str_contains($msg, 'token')) {
            $hint = 'Błąd uwierzytelniania — sprawdź Client ID i Client Secret w ustawieniach M365.';
        }
        echo json_encode(['ok' => false, 'error' => $msg, 'hint' => $hint]);
    }
    exit;
}

// ── SAVE ─────────────────────────────────────────────────────────────────────
if ($action === 'save') {
    $site_url = trim($body['site_url'] ?? '');
    if (!$site_url) {
        echo json_encode(['ok' => false, 'error' => 'URL witryny jest wymagany.']);
        exit;
    }
    try {
        // jedyna droga zapisu: SpConnection::save() pilnuje blokady zmiany połączenia, czyści cache id witryny i zapisuje audyt
        echo json_encode(SpConnection::save([
            'sp_enabled'     => (bool)($body['sp_enabled'] ?? false),
            'sp_site_url'    => $site_url,
            'sp_library'     => trim($body['library'] ?? ''),
            'sp_base_folder' => trim($body['base_folder'] ?? '', '/ '),
        ], $uid));
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'Błąd zapisu: ' . $e->getMessage()]);
    }
    exit;
}

// ── UNLOCK / LOCK — blokada zmiany połączenia ────────────────────────────────
if ($action === 'unlock') {
    if (empty($body['confirm'])) {
        echo json_encode(['ok' => false, 'error' => 'Potwierdź, że rozumiesz skutki zmiany połączenia.']);
        exit;
    }
    $until = SpConnection::unlock($uid);
    echo json_encode(['ok' => true, 'until' => $until, 'until_h' => date('H:i', $until)]);
    exit;
}
if ($action === 'lock') {
    SpConnection::relock($uid, 'ręcznie');
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Nieznana akcja.']);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
