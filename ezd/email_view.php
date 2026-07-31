<?php
/**
 * EZD — podgląd pliku e-mail (.eml / .msg).
 *
 * Tryby:
 *   ?id=N&_ajax=1  → JSON z metadanymi wiadomości
 *   ?id=N&_body=1  → bezpieczny HTML treści (do <iframe srcdoc>)
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ezd.php';
require_once dirname(__DIR__) . '/includes/ezd_email_parser.php';

require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko');
ezd_require_access();

$id  = (int)($_GET['id'] ?? 0);
$zal = $id ? ezd_zal_get($id) : null;

if (!$zal) {
    http_response_code(404);
    if (!empty($_GET['_ajax'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Załącznik nie istnieje']);
    } else {
        echo 'Załącznik nie istnieje.';
    }
    exit;
}

$path = UPLOAD_DIR . EZD_UPLOAD_SUBDIR . (int)$zal['sprawa_id'] . '/' . $zal['filename'];
$ext  = strtolower(pathinfo($zal['original_name'] ?? $zal['filename'], PATHINFO_EXTENSION));

if (!in_array($ext, ['eml', 'msg'], true)) {
    http_response_code(415);
    if (!empty($_GET['_ajax'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Nieobsługiwany format: ' . htmlspecialchars($ext)]);
    } else {
        echo 'Nieobsługiwany format.';
    }
    exit;
}

if (!is_file($path)) {
    http_response_code(404);
    if (!empty($_GET['_ajax'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Plik nie istnieje na serwerze']);
    } else {
        echo 'Plik nie istnieje na serwerze.';
    }
    exit;
}

// Parsowanie (jedno wywołanie — wynik cache'owany w sesji przy _body)
$parsed = EzdEmailParser::parse($path, $ext);

// ── Tryb: HTML treści (dla iframe) ────────────────────────────────────────────
if (!empty($_GET['_body'])) {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    // CSP: blokuje zewnętrzne zasoby, skrypty i ramki; dopuszcza inline CSS i data: URI
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data: cid:; font-src 'none'; frame-src 'none'; script-src 'none'; object-src 'none'");

    if ($parsed['error']) {
        echo '<!doctype html><html><head><meta charset="utf-8"></head><body>'
           . '<p style="color:#dc2626;font-family:system-ui;padding:1rem">'
           . htmlspecialchars($parsed['error']) . '</p></body></html>';
        exit;
    }

    $html = $parsed['body_html'];
    if ($html !== '') {
        $html = EzdEmailParser::sanitizeHtml($html, $parsed['cid_map']);
    } else {
        // Fallback: plain text z zachowaniem białych znaków
        $html = '<pre style="white-space:pre-wrap;word-wrap:break-word;margin:0">'
              . htmlspecialchars($parsed['body_text'])
              . '</pre>';
    }

    echo <<<HTML
    <!doctype html>
    <html>
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width,initial-scale=1">
      <style>
        body { margin:0; padding:1rem 1.25rem; font-family:system-ui,-apple-system,sans-serif;
               font-size:.875rem; line-height:1.6; color:#1e293b; background:#fff }
        img  { max-width:100%; height:auto }
        a    { color:#2563eb }
        blockquote { border-left:3px solid #cbd5e1; margin:0; padding-left:1rem; color:#64748b }
        pre  { background:#f8fafc; border:1px solid #e2e8f0; border-radius:.375rem;
               padding:.75rem; overflow-x:auto; font-size:.8rem }
        table { border-collapse:collapse; max-width:100% }
      </style>
    </head>
    <body>{$html}</body>
    </html>
    HTML;
    exit;
}

// ── Tryb: JSON z metadanymi ───────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');

$attachMeta = array_map(static fn ($a) => [
    'name' => $a['name'],
    'mime' => $a['mime'],
    'size' => $a['size'],
], $parsed['attachments']);

echo json_encode([
    'ok'          => $parsed['error'] === null,
    'error'       => $parsed['error'],
    'from_name'   => $parsed['from_name'],
    'from_email'  => $parsed['from_email'],
    'to'          => $parsed['to'],
    'cc'          => $parsed['cc'],
    'bcc'         => $parsed['bcc'],
    'reply_to'    => $parsed['reply_to'],
    'subject'     => $parsed['subject'],
    'date'        => $parsed['date'],
    'has_html'    => $parsed['body_html'] !== '',
    'has_text'    => $parsed['body_text'] !== '',
    'attachments' => $attachMeta,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
