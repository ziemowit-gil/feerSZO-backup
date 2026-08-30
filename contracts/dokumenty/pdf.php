<?php
/**
 * contracts/dokumenty/pdf.php
 * Krok 3 — eksport wygenerowanego (i ewentualnie doedytowanego) dokumentu
 * do PDF za pomocą mPDF (UTF-8, polskie znaki).
 */
if (!defined('APP_INSTALLED')) require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/contract_document_engine.php';
require_once dirname(dirname(__DIR__)) . '/vendor/autoload.php';

require_login();

$id  = (int)($_GET['id'] ?? 0);
$doc = $id ? cgd_get($id) : null;
if (!$doc) { http_response_code(404); exit('Dokument nie istnieje.'); }

$tpl      = db_one("SELECT name FROM contract_doc_templates WHERE id=?", [$doc['template_id']]);
$row      = cgd_source_row($doc['contract_type'], (int)$doc['contract_id']);
$doc_name = $tpl['name'] ?? 'Dokument';
$org_name = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');

$html = '<!DOCTYPE html><html lang="pl"><head><meta charset="UTF-8"><style>
body { font-family: dejavusans, sans-serif; color:#111; }
.doc-body { font-size:11pt; line-height:1.4; text-align:justify; }
.doc-body p { margin:0 0 8pt; }
</style></head><body>';

$html .= cgd_org_header_html($doc, $row);
$html .= '<div class="doc-body">' . $doc['tresc_finalna'] . '</div>';
$html .= '</body></html>';

$mpdf_tmp = UPLOAD_DIR . 'mpdf_tmp';
if (!is_dir($mpdf_tmp)) @mkdir($mpdf_tmp, 0755, true);

$mpdf = new \Mpdf\Mpdf([
    'mode'          => 'utf-8',
    'format'        => 'A4',
    'margin_left'   => 25,
    'margin_right'  => 20,
    'margin_top'    => 20,
    'margin_bottom' => 18,
    'default_font'  => 'dejavusans',
    'tempDir'       => $mpdf_tmp,
]);
$mpdf->SetTitle($doc_name);
$mpdf->SetAuthor($org_name);
$mpdf->WriteHTML($html);

$safe_name = preg_replace('/[^A-Za-z0-9_-]/', '_', $doc_name);
$filename  = 'Umowa_' . $safe_name . '_' . $id . '.pdf';
$mpdf->Output($filename, \Mpdf\Output\Destination::INLINE);
exit;
