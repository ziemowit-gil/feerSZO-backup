<?php
/**
 * certificates/download_docx.php
 * Generuje zaświadczenie w formacie DOCX (PHPWord).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/certificates.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Style\Font;
use PhpOffice\PhpWord\SimpleType\Jc;

require_login();

$req_id = intval($_GET['id'] ?? 0);
$req    = get_certificate_request($req_id);
if (!$req || $req['status'] !== 'wydane') {
    http_response_code(404); die('Brak zaświadczenia.');
}

$type  = $req['contract_type'];
$cid   = $req['contract_id'];
$TABLE = table_for_type($type);
$row   = db_one("SELECT * FROM {$TABLE} WHERE id = ?", [$cid]);

$stored = org_setting('org_name');
$const  = defined('ORG_NAME') ? ORG_NAME : '';
$org    = (strlen($const) > strlen($stored)) ? $const : ($stored ?: $const);
$org_city  = org_setting('org_miejscowosc') ?: '';
$org_nip   = org_setting('org_nip') ?: '';
$org_krs   = org_setting('org_krs') ?: '';
$org_adres = org_setting('org_adres') ?: '';

$issued_date = $req['issued_at'] ? date('d.m.Y', strtotime($req['issued_at'])) : date('d.m.Y');
$cert_number = $req['cert_number'] ?: ('ZAWOL/' . str_pad($req_id, 4, '0', STR_PAD_LEFT) . '/' . date('Y'));
$sign_type   = $req['sign_type'] ?? 'papierowe';
$issuer_name = $req['issued_by_name'] ?? '';

// Czyść treść ze starych artefaktów
$raw = trim($req['certificate_content'] ?? '');
$raw = preg_replace('/^ZAŚWIADCZENIE\s+/u', '', $raw);
$raw = preg_replace('/\s*\.{10,}.*$/su', '', $raw);
$raw = preg_replace('/\s*Podpis osoby.*$/su', '', $raw);
$raw = preg_replace('/\s*\d{1,2}\s+\w+\s+\d{4}\s*$/u', '', $raw);
if ($org && str_ends_with(rtrim($raw), $org)) {
    $raw = substr($raw, 0, strrpos($raw, $org));
}
$raw = trim($raw);

// Akapity
$paragraphs = [];
foreach (preg_split('/\n{2,}/', $raw) as $p) {
    $p = trim($p);
    if ($p !== '') $paragraphs[] = $p;
}

// ── PHPWord ───────────────────────────────────────────────────────────────────
$phpWord = new PhpWord();
$phpWord->getSettings()->setThemeFontLang(new \PhpOffice\PhpWord\Style\Language('pl-PL'));

// Domyślna czcionka Calibri (Word default — Lato nie jest embeddowane domyślnie)
$phpWord->setDefaultFontName('Calibri');
$phpWord->setDefaultFontSize(11);

// Style akapitów
$phpWord->addParagraphStyle('pNormal', ['spaceAfter' => 120, 'alignment' => Jc::BOTH, 'lineHeight' => 1.2]);
$phpWord->addParagraphStyle('pCenter', ['spaceAfter' => 0,   'alignment' => Jc::CENTER]);
$phpWord->addParagraphStyle('pRight',  ['spaceAfter' => 0,   'alignment' => Jc::END]);
$phpWord->addParagraphStyle('pSign',   ['spaceAfter' => 0,   'alignment' => Jc::CENTER, 'spaceBefore' => 120]);

// Sekcja A4
$section = $phpWord->addSection([
    'paperSize' => 'A4',
    'marginTop'    => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(2.0),
    'marginBottom' => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(1.8),
    'marginLeft'   => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(2.5),
    'marginRight'  => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(2.0),
]);

// ── Logo + nagłówek org ───────────────────────────────────────────────────────
$logo_path = '';
$_logo_file = org_setting('org_logo');
if ($_logo_file) {
    $lpath = dirname(__DIR__) . '/assets/logo/' . basename($_logo_file);
    if (file_exists($lpath) && filesize($lpath) < 500_000) {
        $logo_path = $lpath;
    }
}

// Tabela nagłówkowa: logo+org | numer
$headerTable = $section->addTable(['borderSize' => 0, 'cellMargin' => 0]);
$headerTable->addRow();
$cellLeft  = $headerTable->addCell(8000, ['borderSize' => 0]);
$cellRight = $headerTable->addCell(3000, ['borderSize' => 0]);

if ($logo_path) {
    try {
        $cellLeft->addImage($logo_path, ['height' => 28, 'wrappingStyle' => 'inline']);
    } catch (\Throwable $e) {}
}
$cellLeft->addText($org, ['bold' => true, 'size' => 10, 'name' => 'Calibri'], ['spaceAfter' => 0]);
$meta = trim(($org_adres ? $org_adres : '') . ($org_nip ? "\nNIP: {$org_nip}" . ($org_krs ? " · KRS: {$org_krs}" : '') : ''));
if ($meta) {
    $cellLeft->addText($meta, ['size' => 8, 'color' => '444444', 'name' => 'Calibri'], ['spaceAfter' => 0]);
}

$cellRight->addText($cert_number, ['bold' => true, 'size' => 9, 'name' => 'Calibri'], ['spaceAfter' => 0, 'alignment' => Jc::END]);
$cellRight->addText(trim(($org_city ? $org_city . ', ' : '') . 'dnia ' . $issued_date),
    ['size' => 8, 'color' => '444444'], ['spaceAfter' => 0, 'alignment' => Jc::END]);

// Linia oddzielająca
$section->addTextBreak(1);

// ── Tytuł ─────────────────────────────────────────────────────────────────────
$section->addText('ZAŚWIADCZENIE O WOLONTARIACIE',
    ['bold' => true, 'size' => 16, 'name' => 'Calibri', 'allCaps' => true],
    ['alignment' => Jc::CENTER, 'spaceAfter' => 60, 'spaceBefore' => 60]);
$section->addText($cert_number, ['size' => 9, 'color' => '555555'], ['alignment' => Jc::CENTER, 'spaceAfter' => 200]);

// ── Treść ─────────────────────────────────────────────────────────────────────
foreach ($paragraphs as $p) {
    // Obsłuż single \n wewnątrz akapitu
    $lines = explode("\n", $p);
    $first = true;
    $textRun = $section->addTextRun(['pNormal']);
    foreach ($lines as $line) {
        if (!$first) $textRun->addTextBreak();
        $textRun->addText($line, ['size' => 11, 'name' => 'Calibri']);
        $first = false;
    }
}

// ── ePodpis adnotacja ─────────────────────────────────────────────────────────
if ($sign_type === 'elektroniczne') {
    $section->addTextBreak(1);
    $section->addText('Podpisano elektronicznie (ePodpis kwalifikowany).',
        ['bold' => true, 'size' => 9], ['pNormal']);
}

// ── Podpis ────────────────────────────────────────────────────────────────────
$section->addTextBreak(1);
$section->addText(trim(($org_city ? $org_city . ', ' : '') . 'dnia ' . $issued_date),
    ['size' => 10], ['spaceAfter' => 120]);
$section->addTextBreak(2);

// Tabela podpisu: pusta lewa | linia prawa
$signTable = $section->addTable(['borderSize' => 0, 'cellMargin' => 0]);
$signTable->addRow();
$signTable->addCell(5500, ['borderSize' => 0]); // puste miejsce
$signCell = $signTable->addCell(5500, ['borderSize' => 0]);
if ($sign_type === 'papierowe') {
    // Linia na podpis
    $signCell->addText('', [], ['spaceAfter' => 0, 'borderBottom' => ['size' => 6, 'color' => '000000']]);
    $signCell->addTextBreak(1);
    $signCell->addText('Podpis osoby upoważnionej', ['size' => 8, 'color' => '444444'], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
    $signCell->addText('do wystawienia zaświadczenia', ['size' => 8, 'color' => '444444'], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
    if ($issuer_name) {
        $signCell->addText($issuer_name, ['bold' => true, 'size' => 9], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
    }
} else {
    $signCell->addText('Podpisano elektronicznie', ['size' => 9, 'italic' => true], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
    if ($issuer_name) {
        $signCell->addText($issuer_name, ['bold' => true, 'size' => 9], ['alignment' => Jc::CENTER]);
    }
}

// ── Stopka ────────────────────────────────────────────────────────────────────
$section->addTextBreak(2);
$footerTable = $section->addTable(['borderSize' => 0, 'cellMargin' => 0]);
$footerTable->addRow();
$fLeft  = $footerTable->addCell(6000, ['borderTop' => ['size' => 6, 'color' => 'AAAAAA']]);
$fRight = $footerTable->addCell(5000, ['borderTop' => ['size' => 6, 'color' => 'AAAAAA']]);
$fLeft->addText($org,  ['size' => 7, 'color' => '888888'], ['spaceAfter' => 0]);
$fRight->addText($cert_number . ' · wydano ' . $issued_date, ['size' => 7, 'color' => '888888'], ['alignment' => Jc::END, 'spaceAfter' => 0]);

// ── Wyślij plik ───────────────────────────────────────────────────────────────
$filename = 'Zaswiadczenie_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $cert_number) . '.docx';

header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store');

$writer = IOFactory::createWriter($phpWord, 'Word2007');
$writer->save('php://output');
exit;
