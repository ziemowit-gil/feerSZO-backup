<?php
/**
 * ezd/pisma/docx.php — generuje i wysyła plik DOCX z pisma EZD lub szablonu.
 *
 * Tryby:
 *   GET/POST ?id=X                          → eksport istniejącego pisma
 *   GET/POST ?szablon_id=X&sprawa_id=Y      → renderuje szablon z kontekstem sprawy
 *   GET/POST ?szablon_id=X                  → renderuje szablon bez sprawy (org context)
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/vendor/autoload.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Shared\Converter;

require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko');
ezd_require_access();

$p = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_check();

// Obsługa tokenu sesji z generator.php (_op=docx)
if (isset($_GET['_gen'])) {
    $tok = preg_replace('/[^0-9a-f]/', '', (string)$_GET['_gen']);
    $key = 'ezd_docx_gen_' . $tok;
    if (!isset($_SESSION[$key]) || $_SESSION[$key]['expires'] < time()) {
        http_response_code(400); die('Token wygasł lub nieprawidłowy. Wróć i spróbuj ponownie.');
    }
    $p = $_SESSION[$key];
    unset($_SESSION[$key]);
}

$pismo_id   = (int)($p['id']          ?? 0);
$szablon_id = (int)($p['szablon_id']  ?? 0);
$sprawa_id  = (int)($p['sprawa_id']   ?? 0);
$odbiorca_p = trim($p['odbiorca'] ?? '');
$znak_obcy  = trim($p['znak_obcy']  ?? '');

$title      = '';
$tresc      = '';
$odbiorca   = '';
$nadawca    = '';
$data_pisma = date('Y-m-d');
$sygnatura  = '';
$owner_name = '';
$uid        = (int)current_user()['id'];

if ($pismo_id) {
    $pismo = ezd_pismo_get($pismo_id);
    if (!$pismo) { http_response_code(404); die('Pismo nie istnieje.'); }
    $sp = ezd_sprawa_get((int)$pismo['sprawa_id']);
    if (!$sp || !ezd_sprawa_access($sp, $uid)) { http_response_code(403); die('Brak dostępu.'); }

    $title      = $pismo['title'];
    $tresc      = $pismo['tresc'];
    $odbiorca   = $pismo['odbiorca'] ?? '';
    $nadawca    = $pismo['nadawca']  ?? '';
    $data_pisma = $pismo['data_pisma'] ?: date('Y-m-d');
    $sygnatura  = $pismo['sygnatura'];
    $owner_name = $pismo['owner_name'] ?? '';

    ezd_log(null, (int)$pismo['sprawa_id'], $pismo_id, null, $uid, 'pismo_docx', 'Pobrano DOCX: ' . $pismo['sygnatura']);

} elseif ($szablon_id) {
    $sz = ezd_szablon_get($szablon_id);
    if (!$sz) { http_response_code(404); die('Szablon nie istnieje.'); }

    $sprawa = $sprawa_id ? ezd_sprawa_get($sprawa_id) : null;
    if ($sprawa_id && (!$sprawa || !ezd_sprawa_access($sprawa, $uid))) {
        http_response_code(403); die('Brak dostępu do sprawy.');
    }

    $ctx = ezd_szablon_context($sprawa, ['odbiorca' => $odbiorca_p, 'znak_obcy' => $znak_obcy]);
    $title      = ezd_szablon_render($sz['tytul_wzor'], $ctx) ?: $sz['nazwa'];
    $tresc      = ezd_szablon_render($sz['tresc_wzor'], $ctx);
    $odbiorca   = $odbiorca_p;
    $nadawca    = (string)(org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : ''));
    $data_pisma = date('Y-m-d');
    $sygnatura  = $sprawa['znak_sprawy'] ?? '';
    $owner_name = current_user()['name'] ?? '';

    ezd_log(null, $sprawa_id ?: null, null, null, $uid, 'szablon_docx',
        'Wygenerowano DOCX z szablonu „' . $sz['nazwa'] . '"' . ($sprawa_id ? ' dla sprawy #' . $sprawa_id : ''));

} else {
    http_response_code(400); die('Podaj id lub szablon_id.');
}

// ── Dane organizacji ──────────────────────────────────────────────────────────
$org_nazwa = (string)(org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : ''));
$org_adres = (string)org_setting('org_adres');
$org_nip   = (string)org_setting('org_nip');
$org_krs   = (string)org_setting('org_krs');
$org_city  = (string)(org_setting('org_miejscowosc') ?: org_setting('org_miasto') ?: '');

$logo_path = '';
$_lf = org_setting('org_logo');
if ($_lf) {
    $lp = dirname(dirname(__DIR__)) . '/assets/logo/' . basename($_lf);
    if (is_file($lp) && filesize($lp) < 500_000) $logo_path = $lp;
}

// ── PhpWord ───────────────────────────────────────────────────────────────────
$pw = new PhpWord();
$pw->setDefaultFontName('Calibri');
$pw->setDefaultFontSize(11);

$fNorm  = ['name' => 'Calibri', 'size' => 11];
$fSmall = ['name' => 'Calibri', 'size' => 8,  'color' => '444444'];
$fBold  = ['name' => 'Calibri', 'size' => 11, 'bold' => true];
$fTitle = ['name' => 'Calibri', 'size' => 13, 'bold' => true];

$pNorm   = ['spaceAfter' => 80,  'alignment' => Jc::BOTH,   'lineHeight' => 1.15];
$pLeft   = ['spaceAfter' => 40,  'alignment' => Jc::START];
$pCenter = ['spaceAfter' => 0,   'alignment' => Jc::CENTER];
$pEnd    = ['spaceAfter' => 0,   'alignment' => Jc::END];

$sec = $pw->addSection([
    'paperSize'    => 'A4',
    'marginTop'    => Converter::cmToTwip(2.0),
    'marginBottom' => Converter::cmToTwip(2.0),
    'marginLeft'   => Converter::cmToTwip(2.5),
    'marginRight'  => Converter::cmToTwip(2.0),
]);

// ── Nagłówek: org | sygnatura + data ─────────────────────────────────────────
$hdr = $sec->addTable(['borderSize' => 0, 'cellMargin' => 0]);
$hdr->addRow();
$cL = $hdr->addCell(8500, ['borderSize' => 0]);
$cR = $hdr->addCell(2500, ['borderSize' => 0]);

if ($logo_path) {
    try { $cL->addImage($logo_path, ['height' => 26, 'wrappingStyle' => 'inline']); } catch (\Throwable) {}
}
$cL->addText($org_nazwa, ['bold' => true, 'size' => 10, 'name' => 'Calibri'], ['spaceAfter' => 0]);
$metaParts = array_filter([$org_adres,
    $org_nip ? ('NIP: ' . $org_nip . ($org_krs ? ' · KRS: ' . $org_krs : '')) : '']);
foreach ($metaParts as $mp) {
    $cL->addText($mp, $fSmall, ['spaceAfter' => 0]);
}

if ($sygnatura) {
    $cR->addText($sygnatura, ['bold' => true, 'size' => 9, 'name' => 'Calibri'], $pEnd);
}
$cityDate = trim(($org_city ? $org_city . ', ' : '') . 'dnia ' . date('d.m.Y', strtotime($data_pisma ?: 'now')));
$cR->addText($cityDate, $fSmall, $pEnd);

$sec->addTextBreak(1);

// ── Blok adresata (prawy) ─────────────────────────────────────────────────────
if ($odbiorca !== '') {
    $odbT = $sec->addTable(['borderSize' => 0, 'cellMargin' => 0]);
    $odbT->addRow();
    $odbT->addCell(6500, ['borderSize' => 0]);
    $odbC = $odbT->addCell(4500, ['borderSize' => 0]);
    foreach (explode("\n", $odbiorca) as $ln) {
        $odbC->addText(trim($ln), $fNorm, ['spaceAfter' => 0]);
    }
    $sec->addTextBreak(1);
}

// ── Tytuł ─────────────────────────────────────────────────────────────────────
if ($title !== '') {
    $sec->addText(mb_strtoupper($title), $fTitle,
        ['alignment' => Jc::CENTER, 'spaceBefore' => 60, 'spaceAfter' => 200]);
}

// ── Treść ─────────────────────────────────────────────────────────────────────
if ($tresc !== '') {
    $lines = explode("\n", str_replace("\r\n", "\n", str_replace("\r", "\n", $tresc)));
    foreach ($lines as $ln) {
        $ln = rtrim($ln);
        if ($ln === '') {
            $sec->addTextBreak(1);
        } else {
            $sec->addText($ln, $fNorm, $pNorm);
        }
    }
} else {
    $sec->addTextBreak(3);
}

$sec->addTextBreak(2);

// ── Blok podpisu ─────────────────────────────────────────────────────────────
$sigNadawca = $nadawca ?: $org_nazwa;
$sigReferent = ($owner_name && $owner_name !== $sigNadawca) ? $owner_name : '';

$sigT = $sec->addTable(['borderSize' => 0, 'cellMargin' => 0]);
$sigT->addRow();
$sigT->addCell(6500, ['borderSize' => 0]);
$sigC = $sigT->addCell(4500, ['borderSize' => 0]);
if ($sigNadawca) {
    $sigC->addText($sigNadawca, ['bold' => true, 'size' => 10, 'name' => 'Calibri'],
        ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
}
if ($sigReferent) {
    $sigC->addText($sigReferent, ['size' => 10, 'name' => 'Calibri'],
        ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
}

// ── Wyślij ────────────────────────────────────────────────────────────────────
$base = $sygnatura ?: preg_replace('/[^A-Za-z0-9]/u', '', mb_substr($title, 0, 40));
$safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $base);
$filename = 'EZD_' . $safe . '_' . date('Ymd') . '.docx';

while (ob_get_level()) ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

IOFactory::createWriter($pw, 'Word2007')->save('php://output');
exit;
