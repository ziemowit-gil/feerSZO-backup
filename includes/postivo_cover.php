<?php
/**
 * Generator strony tytułowej do wysyłki Postivo.pl.
 *
 * Tworzy 1-stronicowy PDF (A4) z informacją co to jest dokument
 * i dlaczego odbiorca go otrzymuje, a następnie scala go z właściwym PDF.
 *
 * Wymaga: mpdf/mpdf (Composer).
 */

/**
 * Generuje scalony PDF: strona tytułowa + oryginalny dokument.
 *
 * @param string $original_pdf_path  Ścieżka do oryginalnego PDF
 * @param array  $cover              Dane strony tytułowej:
 *   - doc_title    string  Tytuł dokumentu (co to jest)
 *   - doc_reason   string  Powód wysyłki (dlaczego otrzymujesz)
 *   - recipient    string  Imię i nazwisko / nazwa odbiorcy
 *   - sygnatura    string  Sygnatura pisma
 *   - data_pisma   string  Data pisma (Y-m-d lub pusty)
 *   - referent     string  Referent
 *   - org_name     string  Nazwa organizacji
 *   - org_address  string  Adres organizacji
 *   - org_email    string  E-mail kontaktowy organizacji
 * @return string  Ścieżka do tymczasowego scalonego pliku PDF (do usunięcia przez wywołującego)
 * @throws \RuntimeException
 */
function postivo_build_cover_pdf(string $original_pdf_path, array $cover): string
{
    if (!file_exists($original_pdf_path)) {
        throw new \RuntimeException('Nie znaleziono pliku PDF: ' . $original_pdf_path);
    }

    $tmp_dir = sys_get_temp_dir();
    $merged  = $tmp_dir . '/postivo_merged_' . bin2hex(random_bytes(8)) . '.pdf';

    $org_name    = h($cover['org_name']    ?? (defined('ORG_NAME') ? ORG_NAME : 'Organizacja'));
    $org_address = h($cover['org_address'] ?? (defined('ORG_ADDRESS') ? ORG_ADDRESS : ''));
    $org_email   = h($cover['org_email']   ?? (defined('ORG_EMAIL') ? ORG_EMAIL : ''));
    $doc_title   = h($cover['doc_title']   ?? '');
    $doc_reason  = h($cover['doc_reason']  ?? '');
    $recipient   = h($cover['recipient']   ?? '');
    $sygnatura   = h($cover['sygnatura']   ?? '');
    $data_pisma  = $cover['data_pisma']
                    ? date('d.m.Y', strtotime($cover['data_pisma']))
                    : date('d.m.Y');
    $data_wysylki = date('d.m.Y');
    $referent    = h($cover['referent']    ?? '');

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  body{font-family:DejaVu Sans,sans-serif;font-size:10pt;color:#1a1a1a;margin:0;padding:0}
  .page{padding:18mm 20mm 12mm}
  .header-bar{border-bottom:2px solid #1E6DFF;padding-bottom:10pt;margin-bottom:10pt}
  .org-name{font-size:12pt;font-weight:bold;color:#1a1a1a}
  .org-sub{font-size:8pt;color:#555;margin-top:2pt}
  .hybrid-badge{display:inline-block;font-size:7pt;font-weight:bold;text-transform:uppercase;
    letter-spacing:.05em;padding:2pt 8pt;background:#F0FDF4;color:#16A34A;
    border-radius:3pt;margin:6pt 0 4pt}
  .doc-title{font-size:13pt;font-weight:bold;color:#1a1a1a;margin:4pt 0 3pt;line-height:1.3}
  .syg{font-size:8pt;color:#666;font-family:DejaVu Sans Mono,monospace}
  .meta-table{width:100%;border-collapse:collapse;margin:6pt 0;background:#F8FAFC;
    border:0.5pt solid #E2E8F0;border-radius:4pt;font-size:8.5pt}
  .meta-table td{padding:4pt 8pt}
  .meta-label{color:#777;width:28%;white-space:nowrap}
  .meta-val{color:#1a1a1a;font-weight:bold}
  .section{margin:10pt 0}
  .section-q{font-size:8pt;font-weight:bold;text-transform:uppercase;letter-spacing:.06em;
    color:#1E6DFF;margin-bottom:4pt}
  .section-a{font-size:9.5pt;color:#1a1a1a;line-height:1.55;padding:7pt 10pt;
    background:#F8FAFC;border-left:2.5pt solid #1E6DFF;border-radius:2pt}
  .divider{border:none;border-top:0.5pt solid #E2E8F0;margin:9pt 0}
  .footer-note{font-size:8pt;color:#666;line-height:1.5}
  .page-footer{position:fixed;bottom:12mm;left:20mm;right:20mm;
    border-top:0.5pt solid #E2E8F0;padding-top:5pt;
    display:flex;justify-content:space-between;font-size:7.5pt;color:#999}
  .postivo-mark{color:#0052CC}
</style>
</head>
<body>
<div class="page">
  <div class="header-bar">
    <div class="org-name">{$org_name}</div>
    <div class="org-sub">{$org_address}</div>
    <br>
    <div class="hybrid-badge">&#9993; Poczta hybrydowa — list fizyczny</div>
    <div class="doc-title">{$doc_title}</div>
    <div class="syg">{$sygnatura}</div>
  </div>

  <table class="meta-table">
    <tr>
      <td class="meta-label">Data pisma</td><td class="meta-val">{$data_pisma}</td>
      <td class="meta-label">Data wysyłki</td><td class="meta-val">{$data_wysylki}</td>
    </tr>
    <tr>
      <td class="meta-label">Odbiorca</td><td class="meta-val">{$recipient}</td>
      <td class="meta-label">Referent</td><td class="meta-val">{$referent}</td>
    </tr>
  </table>

  <div class="section">
    <div class="section-q">Co to jest ten dokument?</div>
    <div class="section-a">{$doc_title}</div>
  </div>
  <div class="section">
    <div class="section-q">Dlaczego otrzymujesz ten dokument?</div>
    <div class="section-a">{$doc_reason}</div>
  </div>
  <hr class="divider">
  <div class="footer-note">
    Dalsze strony tego listu zawierają właściwy dokument.<br>
    W razie pytań prosimy o kontakt: <strong>{$org_email}</strong>
  </div>
  <hr class="divider">
  <div class="footer-note" style="background:#F8FAFC;border:0.5pt solid #E2E8F0;border-radius:3pt;padding:6pt 10pt">
    <strong>Czym jest poczta hybrydowa?</strong><br>
    Poczta hybrydowa to usługa, w której dokument przygotowany w formie cyfrowej jest automatycznie
    drukowany, kopertowany i dostarczany przez operatora pocztowego jako tradycyjna przesyłka listowa.
    Ten list został nadany za pośrednictwem serwisu <strong>Postivo.pl</strong>, który przekazał go
    do fizycznej dostawy przez pocztę. Jeśli masz pytania dotyczące wysyłki, skontaktuj się
    bezpośrednio z organizacją.
  </div>
</div>
<div class="page-footer">
  <span class="postivo-mark">&#9679; Wysłano przez Postivo.pl</span>
  <span>strona 1 z N</span>
</div>
</body>
</html>
HTML;

    // Generuj cover jako PDF do bufora
    $mpdf_cover = new \Mpdf\Mpdf([
        'format'        => 'A4',
        'margin_top'    => 0,
        'margin_bottom' => 0,
        'margin_left'   => 0,
        'margin_right'  => 0,
        'tempDir'       => $tmp_dir,
    ]);
    $mpdf_cover->SetTitle('Strona tytułowa — ' . strip_tags($sygnatura));
    $mpdf_cover->WriteHTML($html);
    $cover_pdf = $mpdf_cover->Output('', \Mpdf\Output\Destination::STRING_RETURN);

    // Scala: cover page + oryginał
    $cover_tmp = $tmp_dir . '/postivo_cover_' . bin2hex(random_bytes(8)) . '.pdf';
    file_put_contents($cover_tmp, $cover_pdf);
    unset($mpdf_cover, $cover_pdf);

    try {
        $mpdf = new \Mpdf\Mpdf([
            'format'        => 'A4',
            'margin_top'    => 0,
            'margin_bottom' => 0,
            'margin_left'   => 0,
            'margin_right'  => 0,
            'tempDir'       => $tmp_dir,
        ]);

        // Strona 1 — cover
        $cnt = $mpdf->setSourceFile($cover_tmp);
        for ($p = 1; $p <= $cnt; $p++) {
            $pid = $mpdf->importPage($p);
            $mpdf->AddPage('P', '', 0, '', 0, 0, 0, 0, 0, 0);
            $mpdf->useImportedPage($pid, 0, 0, 210, 297);
        }

        // Strony N — oryginalny PDF
        $cnt = $mpdf->setSourceFile($original_pdf_path);
        for ($p = 1; $p <= $cnt; $p++) {
            $pid = $mpdf->importPage($p);
            $mpdf->AddPage('P', '', 0, '', 0, 0, 0, 0, 0, 0);
            $mpdf->useImportedPage($pid, 0, 0, 210, 297);
        }

        $mpdf->Output($merged, \Mpdf\Output\Destination::FILE);
    } finally {
        @unlink($cover_tmp);
    }

    if (!file_exists($merged)) {
        throw new \RuntimeException('Nie udało się wygenerować scalonego PDF.');
    }

    return $merged;
}
