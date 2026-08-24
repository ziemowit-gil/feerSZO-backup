<?php
/**
 * crm/api/attachment.php — wydanie załącznika wiadomości ze Skrzynki CRM.
 *
 * Załączniki są zapisywane na dysk tylko wtedy, gdy skanowanie poczty ma
 * włączone pobieranie (`poczta_download_attachments`) i plik mieści się w limicie.
 * Reszta miała w Skrzynce CRM napis „niepobrany" i nie dawało się jej otworzyć —
 * a to najczęściej dokładnie te załączniki, po które ktoś wchodzi: podpisana
 * umowa, skan wniosku.
 *
 * Ten endpoint dociąga brakujący plik z Microsoft Graph NA ŻĄDANIE, zapisuje go
 * obok pozostałych i wydaje. Kolejne otwarcie idzie już z dysku.
 *
 * GET ?id=<poczta_attachments.id>[&dl=1]
 *   dl=1 → pobranie (Content-Disposition: attachment)
 *   bez  → podgląd w przeglądarce dla typów, które da się bezpiecznie pokazać
 *
 * Dostęp: zalogowany, obszar „Skrzynka" i uprawnienie do TEJ skrzynki pocztowej.
 * Ścieżka pliku nigdy nie pochodzi z adresu — wyłącznie z bazy.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/poczta.php';
// Uprawnienia do skrzynek mieszkają w osobnym pliku i poczta.php go nie wciąga —
// bez tego sprawdzenie dostępu poniżej po cichu by się nie wykonało.
require_once dirname(dirname(__DIR__)) . '/includes/poczta_acl.php';

/* Endpoint sięga po plik na dysku i po Microsoft Graph. Gdy któreś z tych źródeł
   zawiedzie w sposób, którego nie przewidzieliśmy, przeglądarka dostaje gołe 500 —
   a wtedy nie wiadomo, czy problem jest w uprawnieniach, w koncie M365, czy
   w samym pliku. Dlatego każdy błąd krytyczny ląduje w logu serwera i wraca
   jako czytelny komunikat z identyfikatorem, po którym da się go w logu znaleźć. */
$ATT_TRACE = substr(bin2hex(random_bytes(4)), 0, 8);
$ATT_LAST_ERR = '';   // treść ostatniego błędu — pokazywana administratorowi

// Ostrzeżenia tylko logujemy. Zamiana ich w wyjątki zrobiłaby z drobiazgu
// (np. ostrzeżenia przy zapisie pliku) kolejne 500 — a plik i tak dałoby się wydać.
set_error_handler(static function (int $no, string $msg, string $file = '', int $line = 0) use ($ATT_TRACE): bool {
    if (!(error_reporting() & $no)) return false;          // wyciszone przez @
    error_log("[crm attachment {$ATT_TRACE}] ostrzeżenie: {$msg} @ {$file}:{$line}");
    return false;                                           // niech PHP obsłuży jak zwykle
});

register_shutdown_function(static function () use ($ATT_TRACE) {
    $e = error_get_last();
    if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true)) return;
    error_log("[crm attachment {$ATT_TRACE}] FATAL {$e['message']} @ {$e['file']}:{$e['line']}");
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><meta charset="utf-8"><body style="font:14px/1.6 system-ui;padding:2rem">'
       . '<p>Nie udało się wydać załącznika — błąd po stronie serwera.</p>'
       . '<p style="color:#6B7280">Identyfikator zgłoszenia: <code>' . $ATT_TRACE . '</code> '
       . '— po nim administrator znajdzie szczegóły w logu.</p>';
    // Administrator widzi treść błędu od razu. Odsyłanie po nią do logu serwera
    // przy jednoosobowej administracji oznacza po prostu, że nikt jej nie przeczyta.
    if (function_exists('is_admin') && is_admin()) {
        echo '<pre style="background:#F3F4F6;padding:1rem;border-radius:8px;white-space:pre-wrap;font-size:12px">'
           . htmlspecialchars($e['message'] . "\n" . $e['file'] . ':' . $e['line'], ENT_QUOTES, 'UTF-8')
           . '</pre>';
    }
});

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');

/** Kończy odpowiedź czytelnym komunikatem zamiast pustej strony. */
function att_fail(string $msg, int $code = 404): never
{
    global $ATT_TRACE, $ATT_LAST_ERR;
    if ($code >= 500) error_log("[crm attachment {$ATT_TRACE}] {$code}: {$msg}");
    if ($code >= 500 && $ATT_LAST_ERR && function_exists('is_admin') && is_admin()) {
        $msg .= ' — ' . $ATT_LAST_ERR;
    }
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Załącznik</title>'
       . '<body style="font:14px/1.6 system-ui;padding:2rem;color:#374151">'
       . '<p>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>'
       . '<p><a href="javascript:history.back()">Wróć</a></p>';
    exit;
}

if (!crm_can('inbox', 'read')) att_fail('Twoja rola nie ma dostępu do Skrzynki CRM.', 403);

$att_id = (int)($_GET['id'] ?? 0);
if ($att_id <= 0) att_fail('Nie wskazano załącznika.');

try {
    $a = db_one(
        "SELECT pa.*, c.mailbox_id, c.outlook_message_id, c.contact_id, c.id AS comm_id, m.mailbox
           FROM poczta_attachments pa
           JOIN crm_communications c ON c.id = pa.communication_id
      LEFT JOIN poczta_mailboxes m   ON m.id = c.mailbox_id
          WHERE pa.id = ?", [$att_id]
    );
} catch (\Throwable $e) {
    // Najczęściej: brak tabeli skrzynek na instancji, która nie używa modułu Poczta
    $ATT_LAST_ERR = $e->getMessage();
    error_log("[crm attachment {$ATT_TRACE}] zapytanie: " . $e->getMessage());
    try {
        $a = db_one("SELECT pa.*, c.mailbox_id, c.outlook_message_id, c.contact_id, c.id AS comm_id,
                            '' AS mailbox
                       FROM poczta_attachments pa
                       JOIN crm_communications c ON c.id = pa.communication_id
                      WHERE pa.id = ?", [$att_id]);
    } catch (\Throwable $e2) {
        att_fail('Nie udało się odczytać danych załącznika (' . $ATT_TRACE . ').', 500);
    }
}
if (!$a) att_fail('Taki załącznik nie istnieje.');

// Dostęp do skrzynki, na którą wpłynęła wiadomość — ten sam warunek co na liście
if (!empty($a['mailbox_id'])) {
    if (!function_exists('poczta_can_access')) {
        att_fail('Nie można sprawdzić uprawnień do skrzynki (' . $ATT_TRACE . ').', 500);
    }
    if (!poczta_can_access((int)$a['mailbox_id'], 'read')) {
        att_fail('Nie masz dostępu do skrzynki, na którą wpłynęła ta wiadomość.', 403);
    }
}

$root = rtrim(UPLOAD_DIR, '/');
$path = '';

// ── 1. Plik już na dysku ───────────────────────────────────────────────────
if (!empty($a['stored_path'])) {
    $cand = $root . '/' . ltrim((string)$a['stored_path'], '/');
    // Ścieżka pochodzi z bazy, ale realpath i tak sprawdzamy: wpis mógł powstać
    // przed zmianą katalogu uploadów albo zostać ręcznie poprawiony w bazie.
    $real = realpath($cand);
    if ($real && str_starts_with($real, (string)realpath($root)) && is_readable($real)) $path = $real;
}

// ── 2. Dociągnięcie ze skrzynki na żądanie ─────────────────────────────────
if ($path === '' && !empty($a['graph_attachment_id']) && !empty($a['outlook_message_id']) && !empty($a['mailbox'])) {
    try {
        require_once dirname(dirname(__DIR__)) . '/includes/m365.php';
        $graph = new M365Graph();
        $bytes = $graph->get_attachment_content(
            (string)$a['mailbox'], (string)$a['outlook_message_id'], (string)$a['graph_attachment_id']
        );
        if ($bytes !== '') {
            $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$a['original_name']) ?: 'plik';
            $dir  = $root . '/poczta/' . (int)$a['contact_id'] . '/' . (int)$a['comm_id'] . '/';
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            $abs = $dir . $safe;
            if (@file_put_contents($abs, $bytes) !== false) {
                // Zapisujemy ścieżkę, żeby kolejne otwarcie nie wołało już Graph API
                db()->prepare("UPDATE poczta_attachments SET stored_path=? WHERE id=?")
                    ->execute(['poczta/' . (int)$a['contact_id'] . '/' . (int)$a['comm_id'] . '/' . $safe, $att_id]);
                $path = $abs;
            } else {
                // Nie udało się zapisać (prawa do katalogu) — wydajemy z pamięci,
                // bo użytkownikowi zależy na pliku, nie na tym, gdzie leży
                att_send_bytes($bytes, (string)$a['original_name'], (string)$a['mime_type'], !empty($_GET['dl']));
            }
        }
    } catch (\Throwable $e) {
        $ATT_LAST_ERR = $e->getMessage();
        error_log("[crm attachment {$ATT_TRACE}] Graph: " . $e->getMessage());
        att_fail('Nie udało się pobrać załącznika ze skrzynki pocztowej. '
               . 'Plik jest w wiadomości, ale serwer nie może go teraz odczytać.', 502);
    }
}

if ($path === '') {
    att_fail('Tego załącznika nie ma na serwerze i nie udało się go pobrać ze skrzynki. '
           . 'Otwórz wiadomość w programie pocztowym.');
}

att_send_file($path, (string)$a['original_name'], (string)$a['mime_type'], !empty($_GET['dl']));

// ── Wysyłka ────────────────────────────────────────────────────────────────

/**
 * Czy typ wolno pokazać w ramce przeglądarki.
 *
 * Wszystko poza tą listą idzie jako pobranie z `Content-Type` ogólnym —
 * inaczej HTML z załącznika wykonałby się w naszej domenie razem ze skryptami.
 */
function att_inline_ok(string $mime, string $name): bool
{
    $mime = strtolower(trim($mime));
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($mime === 'application/pdf' || $ext === 'pdf') return true;
    if (str_starts_with($mime, 'image/') && !str_contains($mime, 'svg')) return true;
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true)) return true;
    if (str_starts_with($mime, 'text/plain') || in_array($ext, ['txt', 'log', 'csv', 'md'], true)) return true;
    return false;
}

function att_headers(string $name, string $mime, bool $force_dl): void
{
    $inline = !$force_dl && att_inline_ok($mime, $name);
    $type   = $inline && $mime !== '' ? $mime : 'application/octet-stream';
    if ($inline && $mime === '') $type = 'text/plain; charset=utf-8';

    header('Content-Type: ' . $type);
    header('X-Content-Type-Options: nosniff');
    // Ramka tylko nasza — załącznik nie ma prawa osadzać się gdzie indziej
    header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; frame-ancestors 'self'");
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
         . '; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name) . '"'
         . "; filename*=UTF-8''" . rawurlencode($name));
    header('Cache-Control: private, max-age=600');
}

function att_send_file(string $path, string $name, string $mime, bool $force_dl): never
{
    att_headers($name, $mime, $force_dl);
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

function att_send_bytes(string $bytes, string $name, string $mime, bool $force_dl): never
{
    att_headers($name, $mime, $force_dl);
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
}
