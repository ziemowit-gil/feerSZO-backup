<?php
/**
 * AJAX: Kreator SharePoint — test połączenia + zapis ustawień.
 *
 * POST { action: 'test', site_url, library?, base_folder? }
 *   → { ok, site_id?, site_name?, drives?, error? }
 *
 * POST { action: 'save', sp_enabled, site_url, library, base_folder }
 *   → { ok, error? }
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/m365.php';

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
    $sp_enabled  = ($body['sp_enabled']  ?? false) ? '1' : '0';
    $site_url    = trim($body['site_url']    ?? '');
    $library     = trim($body['library']     ?? '');
    $base_folder = trim($body['base_folder'] ?? '', '/ ');

    if (!$site_url) {
        echo json_encode(['ok' => false, 'error' => 'URL witryny jest wymagany.']);
        exit;
    }

    try {
        m365_save_setting('sp_enabled',          $sp_enabled);
        m365_save_setting('sp_site_url',         $site_url);
        m365_save_setting('sp_library',          $library);
        m365_save_setting('sp_base_folder',      $base_folder);
        m365_save_setting('sp_cached_site_id',   '');
        m365_save_setting('sp_cached_drive_id',  '');
        echo json_encode(['ok' => true]);
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'Błąd zapisu: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Nieznana akcja.']);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
