<?php
/**
 * konsultacje/pdf.php — Generuje PRAWDZIWY plik PDF „Karta konsultacji".
 *
 * GET:
 *   id (int)  — ID karty.
 *   dl (1)    — wymuś pobranie pliku (Content-Disposition: attachment).
 *               Bez parametru dokument wyświetla się w przeglądarce jako PDF.
 *
 * Dokument budowany jest po stronie serwera (FPDF + font DejaVu, kodowanie
 * ISO-8859-2 dla polskich znaków) — to faktyczny plik PDF, nie wydruk z okna
 * przeglądarki.
 *
 * Dostęp: zalogowany (nie-viewer) ALBO autor świeżego wpisu z publicznego
 * formularza (ID na liście dozwolonych w sesji — cc_pub_pdf).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/consultations.php';

$id = (int)($_GET['id'] ?? 0);

auth_start();
$pub_ok = $id > 0 && in_array($id, $_SESSION['cc_pub_pdf'] ?? [], true);
if (!$pub_ok) {
    require_login();
    if (is_viewer()) { http_response_code(403); exit('Brak dostępu.'); }
}

$c = $id ? cc_get($id) : null;
if (!$c) { http_response_code(404); exit('Karta konsultacyjna nie istnieje.'); }

cc_render_pdf_file($c, isset($_GET['dl']) ? 'D' : 'I');

