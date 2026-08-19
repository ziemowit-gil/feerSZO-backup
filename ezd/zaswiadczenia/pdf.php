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

$id         = (int)($_GET['id'] ?? 0);
$duplikat   = isset($_GET['duplikat']) && $_GET['duplikat'] === '1';
$egzemplarz = max(1, (int)($_GET['egzemplarz'] ?? 0));
$z_egz      = max(1, (int)($_GET['z'] ?? 0));
$zas        = ezd_zas_get($id);

if (!$zas) { http_response_code(404); exit('Nie znaleziono.'); }
if ($zas['status'] !== 'wydane') { http_response_code(403); exit('Zaświadczenie nie zostało jeszcze wydane.'); }

$user_id  = (int)current_user()['id'];
$can_mgr  = ezd_is_manager() || can_edit();
$is_owner = (int)$zas['created_by'] === $user_id;
if (!$can_mgr && !$is_owner) { http_response_code(403); exit('Brak dostępu.'); }

// Zaświadczenie wydane jako plik własny — serwuj bezpośrednio
if (!empty($zas['plik_path']) && file_exists($zas['plik_path'])) {
    $mime     = $zas['plik_mime'] ?: 'application/pdf';
    $ext      = pathinfo($zas['plik_path'], PATHINFO_EXTENSION) ?: 'pdf';
    $safe_nr  = preg_replace('/[^a-zA-Z0-9\-_]/', '_', $zas['nr_zaswiadczenia'] ?? 'zaswiadczenie');
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . $safe_nr . '.' . $ext . '"');
    header('Content-Length: ' . filesize($zas['plik_path']));
    header('Cache-Control: private, max-age=3600');
    readfile($zas['plik_path']);
    exit;
}

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

    $html = ezd_zas_pdf_html($zas, false, $egzemplarz > 0 && $z_egz > 0 ? ['egzemplarz' => $egzemplarz, 'z' => $z_egz] : []);

    if ($duplikat) {
        $dup_date = date('d.m.Y H:i');
        $dup_banner = '<div style="text-align:center;border:1px solid #aaa;background:#fef9c3;'
            . 'padding:5pt 10pt;margin-bottom:14pt;font-size:8.5pt;color:#555;letter-spacing:.03em">'
            . '<strong style="color:#92400e;letter-spacing:.08em">DUPLIKAT</strong>'
            . ' &nbsp;·&nbsp; wydrukowano dnia <strong>' . $dup_date . '</strong>'
            . '</div>';
        $html = str_replace('<body>', '<body>' . $dup_banner, $html);
        $mpdf->SetWatermarkText('DUPLIKAT', 0.07);
        $mpdf->showWatermarkText = true;
        $mpdf->watermarkTextAlpha = 0.07;
    }

    $mpdf->WriteHTML($html);

    $filename = preg_replace('/[^a-zA-Z0-9\-_]/', '_', $nr) . ($duplikat ? '_DUPLIKAT' : '') . '.pdf';
    $mpdf->Output($filename, \Mpdf\Output\Destination::INLINE);
    exit;

} catch (\Throwable $e) {
    error_log('[ezd_zas_pdf] ' . $e->getMessage());
    http_response_code(500);
    exit('Błąd generowania PDF: ' . htmlspecialchars($e->getMessage()));
}
