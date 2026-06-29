<?php
/**
 * konsultacje/zip.php — Eksport kart konsultacyjnych do archiwum ZIP.
 *
 * Tryby:
 *   • ?id=N   — pojedyncza karta. Dostępne dla zalogowanego (nie-viewer) ALBO dla
 *               osoby, która właśnie wypełniła tę kartę (ID na liście w sesji).
 *   • from,to — zakres dat (Y-m-d, opcjonalne). Tylko dla zalogowanych.
 *
 * Tworzy archiwum z pojedynczymi plikami .txt (czytelny protokół) dla każdej
 * karty z zakresu. Pliki w archiwum nazwane wg schematu:
 *   RRRR-MM-DD_[Nazwa_Organizacji]_ID.txt
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/consultations.php';

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit('Rozszerzenie PHP „zip" nie jest dostępne na tym serwerze.');
}

cc_migrate();
auth_start();

$id     = (int)($_GET['id'] ?? 0);
$pub_ok = $id > 0 && in_array($id, $_SESSION['cc_pub_pdf'] ?? [], true);

if ($pub_ok) {
    // Publiczny eksport pojedynczej, świeżo wypełnionej karty.
    $c = cc_get($id);
    if (!$c) { http_response_code(404); exit('Karta konsultacyjna nie istnieje.'); }
    $rows  = [$c];
    $label = $c['consultation_date'] . '_karta_' . $id;
} else {
    // Pełny eksport zakresu — tylko dla zalogowanych.
    require_login();
    if (is_viewer()) { http_response_code(403); exit('Brak dostępu.'); }
    require_module_enabled('consultations_enabled', 'Moduł kart konsultacyjnych');

    if ($id > 0) {
        // Zalogowany użytkownik prosi o konkretną kartę.
        $c = cc_get($id);
        if (!$c) { http_response_code(404); exit('Karta konsultacyjna nie istnieje.'); }
        $rows  = [$c];
        $label = $c['consultation_date'] . '_karta_' . $id;
    } else {
        $from = trim($_GET['from'] ?? '');
        $to   = trim($_GET['to']   ?? '');
        if ($from !== '' && !cc_valid_date($from)) $from = '';
        if ($to   !== '' && !cc_valid_date($to))   $to   = '';

        $rows = cc_list($from, $to);
        if (!$rows) {
            flash_set('warning', 'Brak kart do wyeksportowania w wybranym zakresie.');
            header('Location: ' . APP_URL . '/konsultacje/admin.php');
            exit;
        }
        $label = ($from !== '' || $to !== '')
            ? ($from !== '' ? $from : 'poczatek') . '_do_' . ($to !== '' ? $to : date('Y-m-d'))
            : 'wszystkie';
    }
}

// Plik tymczasowy archiwum.
$tmp = tempnam(sys_get_temp_dir(), 'cc_zip_');
if ($tmp === false) { http_response_code(500); exit('Nie udało się utworzyć pliku tymczasowego.'); }

$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    exit('Nie udało się utworzyć archiwum ZIP.');
}

$used = [];
foreach ($rows as $c) {
    $base = cc_filename_base($c);          // RRRR-MM-DD_Nazwa_ID
    if (isset($used[$base])) { $base .= '_' . $c['id']; } // ochrona przed kolizją
    $used[$base] = true;

    // Oficjalny dokument PDF (budowany serwerowo) + czytelny protokół tekstowy.
    $zip->addFromString($base . '.pdf', cc_render_pdf_file($c, 'S'));
    $zip->addFromString($base . '.txt', cc_to_text($c));
}

$zip->close();

$download = 'karty-konsultacyjne_' . $label . '.zip';

$size = filesize($tmp);
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $download . '"');
header('Content-Length: ' . $size);
header('Cache-Control: no-store');
readfile($tmp);
@unlink($tmp);
exit;
