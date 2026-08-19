<?php
/**
 * PDF potwierdzenia odbioru osobistego zaświadczenia.
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
if ($zas['status'] !== 'wydane') { http_response_code(403); exit('Zaświadczenie nie zostało wydane.'); }
if (empty($zas['odbiór_osobisty'])) { http_response_code(403); exit('Brak zarejestrowanego odbioru osobistego.'); }

$user_id  = (int)current_user()['id'];
$can_mgr  = ezd_is_manager() || can_edit();
$is_owner = (int)$zas['created_by'] === $user_id;
if (!$can_mgr && !$is_owner) { http_response_code(403); exit('Brak dostępu.'); }

try {
    require_once dirname(dirname(__DIR__)) . '/vendor/autoload.php';

    $mpdf_tmp = UPLOAD_DIR . 'mpdf_tmp';
    if (!is_dir($mpdf_tmp)) @mkdir($mpdf_tmp, 0755, true);

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
        'margin_top'    => 22,
        'margin_bottom' => 22,
        'default_font'  => 'dejavusans',
        'tempDir'       => $mpdf_tmp,
    ], $ubuntu_cfg));

    $org_name = function_exists('org_setting')
        ? (org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : ''))
        : (defined('ORG_NAME') ? ORG_NAME : '');

    $nr   = $zas['nr_zaswiadczenia'] ?? '—';
    $typ  = $zas['typ_nazwa'] ?? '—';
    $kto  = $zas['odbiór_kto'] ?? '—';
    $data = $zas['odbiór_data'] ? (function_exists('date_pl') ? date_pl($zas['odbiór_data']) : date('d.m.Y', strtotime($zas['odbiór_data']))) : date('d.m.Y');
    $przez = $zas['odbiór_przez_name'] ?? '';
    $printed = date('d.m.Y H:i');

    $mpdf->SetTitle('Potwierdzenie odbioru ' . $nr);
    $mpdf->SetAuthor($org_name);

    $css = '
body { font-family: dejavusans, sans-serif; font-size: 10pt; color: #1a1a1a; }
.org { font-size: 9pt; color: #555; margin-bottom: 4pt; }
.title { font-size: 14pt; font-weight: bold; margin-bottom: 2pt; letter-spacing: .03em; }
.subtitle { font-size: 9pt; color: #555; margin-bottom: 20pt; }
table.grid { width: 100%; border-collapse: collapse; margin-bottom: 18pt; }
table.grid td { padding: 6pt 8pt; font-size: 9.5pt; border: 1px solid #ccc; }
table.grid td.lbl { width: 42%; background: #f5f5f5; color: #333; font-weight: bold; }
.box { border: 1px solid #aaa; border-radius: 2pt; padding: 10pt 14pt; margin-bottom: 18pt; font-size: 9pt; color: #333; background: #fafafa; }
.sig-area { width: 100%; }
.sig-cell { width: 44%; border-top: 1px solid #444; padding-top: 4pt; font-size: 8.5pt; color: #444; text-align: center; }
.sig-spacer { width: 12%; }
.footer { font-size: 7.5pt; color: #aaa; margin-top: 28pt; border-top: 1px solid #ddd; padding-top: 5pt; }
';

    $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>' . $css . '</style></head><body>';

    $html .= '<div class="org">' . htmlspecialchars($org_name) . '</div>';
    $html .= '<div class="title">Potwierdzenie odbioru zaświadczenia</div>';
    $html .= '<div class="subtitle">Niniejszy dokument potwierdza odbiór osobisty zaświadczenia</div>';

    $html .= '<table class="grid">';
    $rows = [
        ['Numer zaświadczenia', htmlspecialchars($nr)],
        ['Typ zaświadczenia',   htmlspecialchars($typ)],
        ['Data odbioru',        htmlspecialchars($data)],
        ['Odebrane przez',      htmlspecialchars($kto)],
    ];
    if ($przez) $rows[] = ['Zarejestrował', htmlspecialchars($przez)];
    foreach ($rows as [$l, $v]) {
        $html .= '<tr><td class="lbl">' . $l . '</td><td>' . $v . '</td></tr>';
    }
    $html .= '</table>';

    $html .= '<div class="box">Potwierdzam odbiór oryginału zaświadczenia nr <strong>' . htmlspecialchars($nr)
           . '</strong> wydanego przez <strong>' . htmlspecialchars($org_name) . '</strong>. '
           . 'Zaświadczenie zostało wydane w jednym egzemplarzu i zostało mi przekazane w stanie nienaruszonym.</div>';

    $html .= '<table class="sig-area"><tr>'
           . '<td class="sig-cell">.....................<br>podpis osoby odbierającej</td>'
           . '<td class="sig-spacer"></td>'
           . '<td class="sig-cell">.....................<br>podpis osoby wydającej</td>'
           . '</tr></table>';

    $html .= '<div class="footer">Wydrukowano: ' . $printed . ' &nbsp;·&nbsp; Dokument wygenerowany automatycznie przez system feerSZO</div>';
    $html .= '</body></html>';

    $mpdf->WriteHTML($html);

    $filename = 'potwierdzenie_odbioru_' . preg_replace('/[^a-zA-Z0-9\-_]/', '_', $nr) . '.pdf';
    $mpdf->Output($filename, \Mpdf\Output\Destination::INLINE);
    exit;

} catch (\Throwable $e) {
    error_log('[pickup_receipt] ' . $e->getMessage());
    http_response_code(500);
    exit('Błąd generowania PDF: ' . htmlspecialchars($e->getMessage()));
}
