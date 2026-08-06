<?php
/**
 * Generuje PDF zaświadczenia za pomocą mPDF i streamuje do przeglądarki.
 * Dostępne tylko dla wydanych zaświadczeń (status='wydane').
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_once dirname(dirname(__DIR__)) . '/includes/zaswiadczenia_ezd.php';
require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko');
ezd_require_access();

$id  = (int)($_GET['id'] ?? 0);
$zas = ezd_zas_get($id);

if (!$zas) { http_response_code(404); exit('Nie znaleziono.'); }
if ($zas['status'] !== 'wydane') { http_response_code(403); exit('Zaświadczenie nie zostało jeszcze wydane.'); }

$user_id  = (int)current_user()['id'];
$can_mgr  = ezd_is_manager() || can_edit();
$is_owner = (int)$zas['created_by'] === $user_id;
if (!$can_mgr && !$is_owner) { http_response_code(403); exit('Brak dostępu.'); }

try {
    require_once dirname(dirname(__DIR__)) . '/vendor/autoload.php';

    $mpdf_tmp = UPLOAD_DIR . 'mpdf_tmp';
    if (!is_dir($mpdf_tmp)) @mkdir($mpdf_tmp, 0755, true);

    // Szukaj czcionki Ubuntu (Linux/macOS/lokalny override)
    $ubuntu_cfg = [];
    foreach ([
        dirname(dirname(__DIR__)) . '/assets/fonts/ubuntu',
        '/usr/share/fonts/truetype/ubuntu',
        '/usr/share/fonts/truetype/ubuntu-font-family',
        '/usr/share/fonts/ubuntu',
    ] as $_udir) {
        if (is_dir($_udir) && file_exists($_udir . '/Ubuntu-R.ttf')) {
            $ubuntu_cfg = [
                'fontDir'  => [$_udir],
                'fontdata' => ['ubuntu' => [
                    'R'  => 'Ubuntu-R.ttf',
                    'B'  => 'Ubuntu-B.ttf',
                    'I'  => 'Ubuntu-RI.ttf',
                    'BI' => 'Ubuntu-BI.ttf',
                ]],
                'default_font' => 'ubuntu',
            ];
            break;
        }
    }

    $mpdf = new \Mpdf\Mpdf(array_merge([
        'mode'          => 'utf-8',
        'format'        => 'A4',
        'margin_left'   => 25,
        'margin_right'  => 25,
        'margin_top'    => 20,
        'margin_bottom' => 20,
        'default_font'  => 'dejavusans',
        'tempDir'       => $mpdf_tmp,
    ], $ubuntu_cfg));

    $nr = $zas['nr_zaswiadczenia'] ?? 'zaswiadczenie';
    $mpdf->SetTitle('Zaświadczenie ' . $nr);
    $mpdf->SetAuthor(
        function_exists('org_setting')
            ? (org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : ''))
            : (defined('ORG_NAME') ? ORG_NAME : '')
    );

    $html = ezd_zas_pdf_html($zas);
    $mpdf->WriteHTML($html);

    $filename = preg_replace('/[^a-zA-Z0-9\-_]/', '_', $nr) . '.pdf';
    $mpdf->Output($filename, \Mpdf\Output\Destination::INLINE);
    exit;

} catch (\Throwable $e) {
    error_log('[ezd_zas_pdf] ' . $e->getMessage());
    http_response_code(500);
    exit('Błąd generowania PDF: ' . htmlspecialchars($e->getMessage()));
}
