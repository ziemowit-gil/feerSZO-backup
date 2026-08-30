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

$org_name  = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
$org_adres = org_setting('org_adres') ?: '';
$org_nip   = org_setting('org_nip') ?: '';
$org_krs   = org_setting('org_krs') ?: '';
$org_city  = org_setting('org_miejscowosc') ?: '';

$logo_b64 = ''; $logo_mime = 'image/png';
$logo_file = org_setting('org_logo');
if ($logo_file) {
    $lpath = dirname(dirname(__DIR__)) . '/assets/logo/' . basename($logo_file);
    if (file_exists($lpath) && filesize($lpath) < 500_000) {
        $logo_b64  = base64_encode(file_get_contents($lpath));
        $logo_mime = str_ends_with(strtolower($logo_file), '.svg') ? 'image/svg+xml'
                   : (str_ends_with(strtolower($logo_file), '.jpg') ? 'image/jpeg' : 'image/png');
    }
}

$doc_ref  = $row['numer_umowy'] ?? '';
$doc_date = date('d.m.Y', strtotime($doc['updated_at'] ?? $doc['created_at']));

$meta = trim(($org_adres ?: '') . ($org_nip ? "\nNIP: {$org_nip}" . ($org_krs ? " · KRS: {$org_krs}" : '') : ''));

$html = '<!DOCTYPE html><html lang="pl"><head><meta charset="UTF-8"><style>
body { font-family: dejavusans, sans-serif; color:#111; }
.doc-org-header { width:100%; border-bottom:1.5pt solid #000; padding-bottom:8pt; margin-bottom:14pt; }
.doc-org-header table { width:100%; border-collapse:collapse; }
.doc-org-header td { vertical-align:top; }
.doc-org-name { font-size:11pt; font-weight:bold; text-transform:uppercase; }
.doc-org-meta { font-size:8pt; color:#444; white-space:pre-line; }
.doc-org-ref { text-align:right; font-size:8pt; color:#555; }
.doc-body { font-size:11pt; line-height:1.4; text-align:justify; }
.doc-body p { margin:0 0 8pt; }
</style></head><body>';

$html .= '<div class="doc-org-header"><table><tr>';
$html .= '<td style="width:70%">';
if ($logo_b64) $html .= '<img src="data:' . $logo_mime . ';base64,' . $logo_b64 . '" style="height:30pt;margin-bottom:4pt"><br>';
$html .= '<span class="doc-org-name">' . htmlspecialchars($org_name) . '</span><br>';
if ($meta) $html .= '<span class="doc-org-meta">' . nl2br(htmlspecialchars($meta)) . '</span>';
$html .= '</td>';
$html .= '<td class="doc-org-ref">';
if ($doc_ref) $html .= htmlspecialchars($doc_ref) . '<br>';
$html .= ($org_city ? htmlspecialchars($org_city) . ', ' : '') . 'dnia ' . $doc_date;
$html .= '</td></tr></table></div>';

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
