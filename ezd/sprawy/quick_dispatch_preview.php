<?php
/**
 * ezd/sprawy/quick_dispatch_preview.php — podgląd PDF (strona tytułowa +
 * scalone dokumenty), DOKŁADNIE ten sam plik, który poleciałby do Postivo.pl,
 * ale bez rejestrowania czegokolwiek ani wysyłki — czysty podgląd przed
 * ostatecznym zleceniem (patrz ezd/sprawy/quick_dispatch.php).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_once dirname(dirname(__DIR__)) . '/includes/postivo_cover.php';

require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko');
ezd_require_access();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); die('POST wymagane.'); }
csrf_check();

$sprawa_id = (int)($_POST['sprawa_id'] ?? 0);
$sprawa    = $sprawa_id ? ezd_sprawa_get($sprawa_id) : null;
if (!$sprawa) { http_response_code(404); die('Koszulka nie istnieje.'); }

$user_id = (int)current_user()['id'];
$access  = ezd_sprawa_access($sprawa, $user_id);
if ($access !== 'write' || $sprawa['status'] === 'closed') { http_response_code(403); die('Brak uprawnień do koszulki.'); }

$zal_ids = array_map('intval', (array)($_POST['zal_ids'] ?? []));
if (!$zal_ids) { http_response_code(400); die('Nie zaznaczono żadnego pliku.'); }

$placeholders = implode(',', array_fill(0, count($zal_ids), '?'));
$pdf_files = db_all(
    "SELECT filename FROM ezd_zalaczniki WHERE sprawa_id=? AND mime_type='application/pdf' AND id IN ($placeholders) ORDER BY id",
    array_merge([$sprawa_id], $zal_ids)
);
$pdf_paths = array_map(fn($z) => UPLOAD_DIR . EZD_UPLOAD_SUBDIR . $sprawa_id . '/' . $z['filename'], $pdf_files);
$pdf_paths = array_values(array_filter($pdf_paths, 'file_exists'));

if (!$pdf_paths) { http_response_code(400); die('Żaden z zaznaczonych plików nie jest dostępnym PDF-em.'); }

$title      = trim($_POST['title']       ?? '') ?: 'Przesyłka wychodząca';
$doc_reason = trim($_POST['doc_reason']  ?? '') ?: 'Korespondencja urzędowa';
$recipient  = trim($_POST['recipient_name'] ?? $_POST['odbiorca'] ?? '');

$referent_name = db_one("SELECT name FROM users WHERE id=?", [$sprawa['owner_id'] ?? 0])['name'] ?? '';

try {
    $merged = postivo_build_cover_pdf($pdf_paths, [
        'doc_title'   => $title,
        'doc_reason'  => $doc_reason,
        'recipient'   => $recipient,
        'sygnatura'   => $sprawa['znak_sprawy'] ?? '',
        'data_pisma'  => date('Y-m-d'),
        'referent'    => $referent_name,
        'org_name'    => defined('ORG_NAME')    ? ORG_NAME    : '',
        'org_address' => defined('ORG_ADDRESS') ? ORG_ADDRESS : '',
        'org_email'   => defined('ORG_EMAIL')   ? ORG_EMAIL   : '',
    ]);
} catch (\Throwable $e) {
    http_response_code(500); die('Nie udało się wygenerować podglądu: ' . h($e->getMessage()));
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="podglad-postivo.pdf"');
header('Content-Length: ' . filesize($merged));
readfile($merged);
@unlink($merged);
exit;
