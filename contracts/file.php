<?php
/**
 * contracts/file.php — plik umowy, także gdy brak go lokalnie (uploads/).
 *
 * GET ?f=<ścieżka względna w uploads/, jak w kolumnach plik_umowy / plik_potwierdzenia / …>
 * Plik jest na serwerze → przekierowanie na zwykły adres /uploads/… Pliku nie ma → szukamy go na SharePoincie
 * i przywracamy (modules/sp_files: ścieżka dokładna, potem nazwa pliku w bibliotece), po czym to samo przekierowanie.
 * Linki do tego skryptu buduje upload_url() wyłącznie dla plików, których lokalnie brakuje.
 *
 * Tylko zalogowani i tylko ścieżki wskazane przez rekordy umów — z biblioteki SharePoint nie da się tędy pobrać
 * dowolnego pliku.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/modules/sp_files/logic/spFiles.php';
require_login();

function contract_file_page(int $code, string $title, string $text, string $extra = ''): never {
    http_response_code($code);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>' . h($title) . '</title>'
       . '<style>body{font:1rem/1.6 system-ui,Arial,sans-serif;max-width:40rem;margin:3rem auto;padding:0 1rem;color:#111}h1{font-size:1.4rem}.d{color:#333;font-size:.9rem}</style></head>'
       . '<body><main><h1>' . h($title) . '</h1><p>' . h($text) . '</p>' . $extra
       . '<p><a href="javascript:history.back()">Wróć</a></p></main></body></html>';
    exit;
}

if (!can_read('umowy') && !can_read('pisma') && !is_admin()) contract_file_page(403, 'Brak dostępu', 'Nie masz uprawnień do plików umów.');

$rel = SpFiles::normalize((string)($_GET['f'] ?? ''));
if ($rel === null) contract_file_page(400, 'Nieprawidłowa ścieżka pliku', 'Adres pliku jest niepoprawny.');
if (!SpFiles::isReferenced($rel)) contract_file_page(404, 'Nie znaleziono pliku', 'Żadna umowa nie wskazuje tego pliku.');

$url = rtrim(APP_URL, '/') . '/uploads/' . implode('/', array_map('rawurlencode', explode('/', $rel)));
if (is_file(SpFiles::localPath($rel))) { header('Location: ' . $url, true, 302); exit; }

if (!SpFiles::canRestore()) {
    contract_file_page(404, 'Plik niedostępny', 'Pliku nie ma na serwerze, a integracja SharePoint jest wyłączona, więc nie można go odzyskać.');
}
$r = SpFiles::restore($rel);
if (in_array($r['status'], ['restored', 'exists'], true)) { header('Location: ' . $url, true, 302); exit; }

$admin = is_admin()
    ? '<p class="d">Szczegóły: ' . h($r['status'] . ($r['message'] !== '' ? ' — ' . $r['message'] : '')) . '. '
      . '<a href="' . h(rtrim(APP_URL, '/') . '/admin/sp_contract_files.php') . '">Narzędzie dopasowania plików z SharePointa</a>.</p>'
    : '<p class="d">Zgłoś brak pliku administratorowi.</p>';
contract_file_page(404, 'Nie znaleziono pliku umowy', match ($r['status']) {
    'ambiguous' => 'Pliku nie ma na serwerze, a na SharePoincie jest kilka plików o tej nazwie — administrator musi wskazać właściwy.',
    'error'     => 'Pliku nie ma na serwerze i nie udało się sprawdzić SharePointa.',
    default     => 'Pliku nie ma na serwerze i nie znaleziono go na SharePoincie.',
}, $admin);
