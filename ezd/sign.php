<?php
/**
 * ezd/sign.php — AJAX endpoint podpisu rSign (CenCert) dla załączników EZD.
 *
 * GET  ?zal_id=X          → JSON {name, data (base64 PDF), mime, csrf}
 *                            (dokument do podpisania w rSign Desktop)
 * POST ?zal_id=X + JSON   → {ok, new_zal_id, version, signer, integrity}
 *      body: {signed_data: base64, signed_name: string, csrf: token}
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ezd.php';
require_once dirname(__DIR__) . '/includes/sigcheck.php';

require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko');
ezd_require_access();

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

$zal_id = (int)($_GET['zal_id'] ?? 0);
if (!$zal_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Brak parametru zal_id.']);
    exit;
}

$z = ezd_zal_get($zal_id);
if (!$z) {
    http_response_code(404);
    echo json_encode(['error' => 'Załącznik nie istnieje lub brak dostępu.']);
    exit;
}

$path = UPLOAD_DIR . EZD_UPLOAD_SUBDIR . (int)$z['sprawa_id'] . '/' . $z['filename'];

// ── GET — serwuj dokument jako base64 ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $ext = strtolower(pathinfo($z['original_name'], PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        http_response_code(422);
        echo json_encode([
            'error' => 'rSign (PAdES) obsługuje tylko pliki PDF. Ten plik: .' . $ext . '. '
                     . 'Inne formaty (DOCX, XML) podpisuj manualnie i wgraj podpisaną wersję.',
        ]);
        exit;
    }
    if (!is_file($path)) {
        http_response_code(404);
        echo json_encode(['error' => 'Plik fizyczny nie istnieje na serwerze.']);
        exit;
    }
    $bytes = @file_get_contents($path);
    if ($bytes === false || $bytes === '') {
        http_response_code(500);
        echo json_encode(['error' => 'Nie można odczytać pliku.']);
        exit;
    }
    if (strlen($bytes) > 50 * 1024 * 1024) {
        http_response_code(413);
        echo json_encode(['error' => 'Plik przekracza 50 MB — za duży do podpisania przez rSign Desktop.']);
        exit;
    }

    echo json_encode([
        'name'  => $z['original_name'],
        'data'  => base64_encode($bytes),
        'mime'  => 'application/pdf',
        'csrf'  => csrf_token(),
        'size'  => strlen($bytes),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── POST — odbierz podpisany dokument ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Metoda nie jest obsługiwana.']);
    exit;
}

if (!can_edit()) {
    http_response_code(403);
    echo json_encode(['error' => 'Brak uprawnień do edycji.']);
    exit;
}

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Nieprawidłowe dane żądania (oczekiwano JSON).']);
    exit;
}

// CSRF
if (empty($body['csrf']) || !hash_equals(csrf_token(), (string)$body['csrf'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Nieprawidłowy token CSRF — odśwież stronę i spróbuj ponownie.']);
    exit;
}

$signed_b64  = trim((string)($body['signed_data'] ?? ''));
$signed_name = trim((string)($body['signed_name'] ?? ''));

if ($signed_b64 === '') {
    echo json_encode(['error' => 'Brak danych podpisanego dokumentu (signed_data).']);
    exit;
}

$signed_bytes = @base64_decode($signed_b64, true);
if ($signed_bytes === false || $signed_bytes === '') {
    echo json_encode(['error' => 'Nieprawidłowe kodowanie base64.']);
    exit;
}
if (strlen($signed_bytes) > 50 * 1024 * 1024) {
    echo json_encode(['error' => 'Podpisany dokument przekracza 50 MB.']);
    exit;
}

// Sprawdź czy to w ogóle PDF
if (strncmp($signed_bytes, '%PDF', 4) !== 0) {
    echo json_encode(['error' => 'Otrzymany plik nie jest plikiem PDF (brak nagłówka %PDF).']);
    exit;
}

// Walidacja podpisu
$tmpf = tempnam(sys_get_temp_dir(), 'ezdrsign_');
file_put_contents($tmpf, $signed_bytes);

$sig_name = $signed_name ?: $z['original_name'];
$info     = ezd_signature_info($tmpf, $sig_name);
if (!$info['signed']) {
    @unlink($tmpf);
    echo json_encode([
        'error' => 'Otrzymany plik PDF nie zawiera wykrywalnego podpisu elektronicznego. '
                 . 'Upewnij się, że rSign zakończył podpisywanie i spróbuj ponownie.',
    ]);
    exit;
}

// Zapisz jako nowa wersja (prev_id = oryginalny załącznik)
$user_id   = (int)current_user()['id'];
$sprawa_id = (int)$z['sprawa_id'];

if ($signed_name === '') {
    $base        = pathinfo($z['original_name'], PATHINFO_FILENAME);
    $signed_name = $base . '_podpisany.pdf';
}

$dir = UPLOAD_DIR . EZD_UPLOAD_SUBDIR . $sprawa_id . '/';
if (!is_dir($dir)) mkdir($dir, 0755, true);
$stored = 'rsign_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';

if (!rename($tmpf, $dir . $stored)) {
    @unlink($tmpf);
    echo json_encode(['error' => 'Nie udało się zapisać podpisanego pliku.']);
    exit;
}

$new_wersja = ((int)($z['wersja'] ?? 1)) + 1;

db()->prepare(
    "INSERT INTO ezd_zalaczniki
     (sprawa_id, pismo_id, umowa_id, dokument_id, grupa_id,
      filename, original_name, mime_type, file_size, wersja, prev_id, uploaded_by)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
)->execute([
    $sprawa_id,
    $z['pismo_id'],
    $z['umowa_id'],
    $z['dokument_id'],
    $z['grupa_id'],
    $stored,
    $signed_name,
    'application/pdf',
    strlen($signed_bytes),
    $new_wersja,
    $zal_id,
    $user_id,
]);
$new_zal_id = (int)db()->lastInsertId();

// Pełna walidacja kryptograficzna (certyfikat, integralność)
$valid = ezd_validate_signature($dir . $stored, $signed_name);
$cert  = $valid['certs'][0] ?? null;
$cn    = $cert['cn'] ?? $info['signer'] ?? 'nieznany';

// Log EZD
ezd_log(
    null,
    $sprawa_id,
    $z['pismo_id'],
    $z['umowa_id'],
    $user_id,
    'sign_rsign',
    'rSign: podpisano ' . $z['original_name'] . ' → ' . $signed_name . ' v' . $new_wersja . ' (' . $cn . ')'
);

echo json_encode([
    'ok'         => true,
    'new_zal_id' => $new_zal_id,
    'version'    => $new_wersja,
    'signer'     => $cn,
    'integrity'  => $valid['integrity'] ?? 'n/d',
    'cert'       => $cert,
], JSON_UNESCAPED_UNICODE);
