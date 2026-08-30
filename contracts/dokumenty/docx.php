<?php
/**
 * contracts/dokumenty/docx.php
 * Krok 3 — eksport wygenerowanego (i ewentualnie doedytowanego) dokumentu
 * do Word (DOCX) za pomocą PHPWord.
 */
if (!defined('APP_INSTALLED')) require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/contract_document_engine.php';
require_once dirname(dirname(__DIR__)) . '/vendor/autoload.php';

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\Shared\Html as PhpWordHtml;

require_login();

$id  = (int)($_GET['id'] ?? 0);
$doc = $id ? cgd_get($id) : null;
if (!$doc) { http_response_code(404); exit('Dokument nie istnieje.'); }

$tpl      = db_one("SELECT name FROM contract_doc_templates WHERE id=?", [$doc['template_id']]);
$row      = cgd_source_row($doc['contract_type'], (int)$doc['contract_id']);
$doc_name = $tpl['name'] ?? 'Dokument';

$org       = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
$org_adres = org_setting('org_adres') ?: '';
$org_nip   = org_setting('org_nip') ?: '';
$org_krs   = org_setting('org_krs') ?: '';
$org_city  = org_setting('org_miejscowosc') ?: '';

$logo_path = '';
$lf = org_setting('org_logo');
if ($lf) {
    $lp = dirname(dirname(__DIR__)) . '/assets/logo/' . basename($lf);
    if (file_exists($lp) && filesize($lp) < 500_000) $logo_path = $lp;
}

$phpWord = new PhpWord();
$phpWord->setDefaultFontName('Calibri');
$phpWord->setDefaultFontSize(11);
$phpWord->addParagraphStyle('pNormal', ['spaceAfter' => 100, 'alignment' => Jc::BOTH, 'lineHeight' => 1.2]);

$section = $phpWord->addSection([
    'paperSize'    => 'A4',
    'marginTop'    => Converter::cmToTwip(2.0),
    'marginBottom' => Converter::cmToTwip(1.8),
    'marginLeft'   => Converter::cmToTwip(2.5),
    'marginRight'  => Converter::cmToTwip(2.0),
]);

$hdrTable = $section->addTable(['borderSize' => 0, 'cellMargin' => 0]);
$hdrTable->addRow();
$cellL = $hdrTable->addCell(8000, ['borderSize' => 0]);
$cellR = $hdrTable->addCell(3000, ['borderSize' => 0]);

if ($logo_path) {
    try { $cellL->addImage($logo_path, ['height' => 26, 'wrappingStyle' => 'inline']); } catch (\Throwable $e) {}
}
$cellL->addText($org, ['bold' => true, 'size' => 10], ['spaceAfter' => 0]);
$meta = trim(($org_adres ?: '') . ($org_nip ? "\nNIP: {$org_nip}" . ($org_krs ? " · KRS: {$org_krs}" : '') : ''));
if ($meta) $cellL->addText($meta, ['size' => 8, 'color' => '444444'], ['spaceAfter' => 0]);

if (!empty($row['numer_umowy'])) {
    $cellR->addText($row['numer_umowy'], ['bold' => true, 'size' => 9], ['spaceAfter' => 0, 'alignment' => Jc::END]);
}
$cellR->addText(
    trim(($org_city ? $org_city . ', ' : '') . 'dnia ' . date('d.m.Y', strtotime($doc['updated_at'] ?? $doc['created_at']))),
    ['size' => 8, 'color' => '555555'], ['spaceAfter' => 0, 'alignment' => Jc::END]
);

$section->addTextBreak(1);
$section->addText($doc_name, ['bold' => true, 'size' => 14, 'allCaps' => true],
    ['alignment' => Jc::CENTER, 'spaceAfter' => 160, 'spaceBefore' => 60]);

$htmlBody = '<div style="text-align:justify;font-family:Calibri;font-size:11pt">' . $doc['tresc_finalna'] . '</div>';
PhpWordHtml::addHtml($section, $htmlBody, false, false);

$safe_name = preg_replace('/[^A-Za-z0-9_-]/', '_', $doc_name);
$filename  = 'Umowa_' . $safe_name . '_' . $id . '.docx';

header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store');

IOFactory::createWriter($phpWord, 'Word2007')->save('php://output');
exit;
