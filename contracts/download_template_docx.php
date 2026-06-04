<?php
/**
 * contracts/download_template_docx.php
 * Generuje dokument Word (DOCX) z szablonu, podstawiając dane umowy.
 * Nagłówek taki sam jak w zaświadczeniach.
 */
if (!defined('APP_INSTALLED')) require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/contract_template_engine.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\Shared\Html as PhpWordHtml;

require_login();

$template_id = (int)($_GET['template_id'] ?? 0);
$contract_id = (int)($_GET['contract_id'] ?? 0);
$type        = preg_replace('/[^a-z]/', '', $_GET['type'] ?? 'wolontariat');

if (!$template_id) { http_response_code(400); die('Brak template_id.'); }

cte_migrate();
$tpl = db_one("SELECT * FROM contract_doc_templates WHERE id=?", [$template_id]);
if (!$tpl) { http_response_code(404); die('Szablon nie istnieje.'); }

$row = [];
$table_map = ['wolontariat'=>'umowy_wolontariat','zlecenie'=>'umowy_zlecenie',
              'dzielo'=>'umowy_dzielo','praca'=>'umowy_praca','uslugi'=>'umowy_uslugi','inne'=>'umowy_inne'];
if ($contract_id && isset($table_map[$type])) {
    $row = db_one("SELECT * FROM {$table_map[$type]} WHERE id=?", [$contract_id]) ?: [];
}

$map   = cte_build_map($type, $row);
$html  = cte_render($tpl['body'], $map);

// Org data
$stored  = org_setting('org_name');
$const   = defined('ORG_NAME') ? ORG_NAME : '';
$org     = (strlen($const) > strlen($stored)) ? $const : ($stored ?: $const);
$org_adres = org_setting('org_adres') ?: '';
$org_nip   = org_setting('org_nip') ?: '';
$org_krs   = org_setting('org_krs') ?: '';
$org_city  = org_setting('org_miejscowosc') ?: '';

// Logo
$logo_path = '';
$_lf = org_setting('org_logo');
if ($_lf) {
    $lp = dirname(__DIR__) . '/assets/logo/' . basename($_lf);
    if (file_exists($lp) && filesize($lp) < 500_000) $logo_path = $lp;
}

// ── PHPWord ───────────────────────────────────────────────────────────────────
$phpWord = new PhpWord();
$phpWord->setDefaultFontName('Calibri');
$phpWord->setDefaultFontSize(11);

$phpWord->addParagraphStyle('pNormal', ['spaceAfter' => 100, 'alignment' => Jc::BOTH, 'lineHeight' => 1.2]);
$phpWord->addParagraphStyle('pCenter', ['spaceAfter' => 0,   'alignment' => Jc::CENTER]);

$section = $phpWord->addSection([
    'paperSize'    => 'A4',
    'marginTop'    => Converter::cmToTwip(2.0),
    'marginBottom' => Converter::cmToTwip(1.8),
    'marginLeft'   => Converter::cmToTwip(2.5),
    'marginRight'  => Converter::cmToTwip(2.0),
]);

// ── Nagłówek org (tabela: logo+org | numer+data) ──────────────────────────────
$hdrTable = $section->addTable(['borderSize' => 0, 'cellMargin' => 0]);
$hdrTable->addRow();
$cellL = $hdrTable->addCell(8000, ['borderSize' => 0]);
$cellR = $hdrTable->addCell(3000, ['borderSize' => 0]);

if ($logo_path) {
    try { $cellL->addImage($logo_path, ['height' => 26, 'wrappingStyle' => 'inline']); } catch (\Throwable $e) {}
}
$cellL->addText($org, ['bold' => true, 'size' => 10, 'name' => 'Calibri'], ['spaceAfter' => 0]);
$meta = trim(($org_adres ?: '') . ($org_nip ? "\nNIP: {$org_nip}" . ($org_krs ? " · KRS: {$org_krs}" : '') : ''));
if ($meta) {
    $cellL->addText($meta, ['size' => 8, 'color' => '444444'], ['spaceAfter' => 0]);
}

if (!empty($row['numer_umowy'])) {
    $cellR->addText($row['numer_umowy'], ['bold' => true, 'size' => 9], ['spaceAfter' => 0, 'alignment' => Jc::END]);
}
$cellR->addText(trim(($org_city ? $org_city . ', ' : '') . 'dnia ' . date('d.m.Y')),
    ['size' => 8, 'color' => '555555'], ['spaceAfter' => 0, 'alignment' => Jc::END]);

$section->addTextBreak(1);

// ── Tytuł szablonu ────────────────────────────────────────────────────────────
$section->addText($tpl['name'],
    ['bold' => true, 'size' => 14, 'name' => 'Calibri', 'allCaps' => true],
    ['alignment' => Jc::CENTER, 'spaceAfter' => 160, 'spaceBefore' => 60]);

// ── Treść — konwertuj HTML → PHPWord z zachowaniem formatowania ──────────────
// Html::addHtml obsługuje: <p>, <h1-h6>, <strong>, <b>, <em>, <i>, <u>, <ul>, <ol>, <li>, <br>
$htmlBody = '<div style="text-align:justify;font-family:Calibri;font-size:11pt">' . $html . '</div>';
PhpWordHtml::addHtml($section, $htmlBody, false, false);

// ── Wyślij DOCX ───────────────────────────────────────────────────────────────
$safe_name = preg_replace('/[^A-Za-z0-9_-]/', '_', $tpl['name']);
$filename  = 'Dokument_' . $safe_name . '_' . date('Ymd') . '.docx';

header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store');

IOFactory::createWriter($phpWord, 'Word2007')->save('php://output');
exit;
