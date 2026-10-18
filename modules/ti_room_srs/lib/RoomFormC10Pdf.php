<?php
/**
 * modules/ti_room_srs/lib/RoomFormC10Pdf.php — wypełnia wniosek „WYNAJEM SAL W CENTRUM OBYWATELSKIM CENTRUM C 10”.
 *
 * Wzór (lib/templates/wniosekC10.pdf, 2 strony, bez pól formularza) jest oryginałem operatora: strony importujemy
 * przez FPDI (mPDF) i nakładamy tylko tekst w komórki prawej kolumny oraz krzyżyki w pola ☐ — układ się nie zmienia.
 * Współrzędne w punktach PDF (A4 595×842, początek u góry), zmierzone z pdftotext -bbox; prawa kolumna x 320–547.
 */
final class RoomFormC10Pdf
{
    /** Prawa kolumna tabeli (pt). */
    private const COL_X0 = 322.0;
    private const COL_X1 = 545.0;

    /** Komórki wartości: klucz danych => [góra, dół] (pt). */
    private const CELLS = [
        'terms'        => [166.5, 192.5],
        'orgName'      => [236.0, 272.5],
        'orgKrs'       => [273.0, 299.5],
        'orgAddress'   => [300.0, 325.5],
        'orgPhone'     => [326.0, 351.5],
        'orgEmail'     => [352.0, 377.0],
        'topic'        => [377.5, 401.0],
        'participants' => [401.5, 416.5],
        'equipment'    => [527.0, 568.0],
        'firstName'    => [599.5, 620.0],
        'lastName'     => [620.5, 641.5],
        'address'      => [642.0, 663.0],
        'pesel'        => [663.5, 684.5],
        'phone'        => [685.0, 706.5],
        'email'        => [707.0, 729.5],
    ];

    /** Pola ☐ (strona, x0, y0, x1, y1 glifu) dla opcji. */
    private const BOXES = [
        'room'       => ['2' => [1, 325.8, 137.6, 335.3, 151.9], '6' => [1, 325.8, 151.8, 335.3, 166.0]],
        'entityType' => ['ngo' => [1, 325.8, 193.2, 335.3, 207.4], 'grupa' => [1, 325.8, 207.5, 335.3, 221.7], 'mieszkaniec' => [1, 325.8, 221.6, 335.3, 235.9]],
        'minors'     => ['tak' => [1, 324.4, 418.5, 333.9, 432.7], 'nie' => [1, 324.4, 433.1, 333.9, 447.3]],
        'statement'  => ['tak' => [1, 324.4, 465.4, 333.9, 479.6], 'nie' => [1, 324.4, 480.0, 333.9, 494.3], 'nie_dotyczy' => [1, 324.4, 496.8, 333.9, 511.1]],
        'newsletter' => ['1' => [2, 70.8, 512.3, 79.4, 525.1]],
    ];

    public static function templatePath(): string
    {
        return __DIR__ . '/templates/wniosekC10.pdf';
    }

    /** $d — jak RoomFormDocx::render() plus orgKrs, entityType (ngo|grupa|mieszkaniec), newsletter ('1'|''). Zwraca PDF. */
    public static function render(array $d): string
    {
        $tmp = defined('UPLOAD_DIR') && is_dir(UPLOAD_DIR) && is_writable(UPLOAD_DIR) ? rtrim(UPLOAD_DIR, '/') . '/tmp' : sys_get_temp_dir() . '/mpdf';
        if (!is_dir($tmp)) @mkdir($tmp, 0775, true);
        $pdf = new \Mpdf\Mpdf(['format' => 'A4', 'margin_left' => 0, 'margin_right' => 0, 'margin_top' => 0, 'margin_bottom' => 0,
                               'margin_header' => 0, 'margin_footer' => 0, 'tempDir' => $tmp]);
        $pdf->SetAutoPageBreak(false);
        $pages = $pdf->setSourceFile(self::templatePath());
        for ($p = 1; $p <= $pages; $p++) {
            if ($p > 1) $pdf->AddPage();
            $pdf->useTemplate($pdf->importPage($p));
            $pdf->SetTextColor(20, 40, 120);
            $pdf->SetDrawColor(20, 40, 120);
            if ($p === 1) {
                foreach (self::CELLS as $k => [$top, $bottom]) {
                    $val = $k === 'terms' ? implode("\n", (array)($d['terms'] ?? [])) : (string)($d[$k] ?? '');
                    self::cell($pdf, $top, $bottom, $val);
                }
            }
            foreach (self::BOXES as $k => $opts) {
                $want = (string)($d[$k] ?? '');
                if ($want !== '' && isset($opts[$want]) && $opts[$want][0] === $p) self::cross($pdf, ...array_slice($opts[$want], 1));
            }
        }
        return $pdf->Output('', 'S');
    }

    private static function mm(float $pt): float { return $pt * 25.4 / 72; }

    /** Tekst w komórce: zawijanie po słowach, czcionka zmniejszana od 10 do 6 pt aż się zmieści, wyśrodkowany w pionie. */
    private static function cell(\Mpdf\Mpdf $pdf, float $top, float $bottom, string $text): void
    {
        $text = trim($text);
        if ($text === '') return;
        $width = self::mm(self::COL_X1 - self::COL_X0);
        $height = $bottom - $top - 2;
        for ($size = 10.0; $size >= 5.0; $size -= 0.5) {
            $pdf->SetFont('dejavusans', '', $size);
            $lines = self::wrap($pdf, $text, $width);
            if (count($lines) * $size * 1.12 <= $height || $size <= 5.0) break;
        }
        $lh = $size * 1.12;
        $y = $top + 1 + max(0, ($height - count($lines) * $lh) / 2) + $size * 0.95;
        foreach ($lines as $line) {
            if ($y > $bottom) break;
            $pdf->Text(self::mm(self::COL_X0), self::mm($y), $line);
            $y += $lh;
        }
    }

    private static function wrap(\Mpdf\Mpdf $pdf, string $text, float $width): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $text) as $para) {
            $line = '';
            foreach (preg_split('/\s+/u', trim($para)) as $word) {
                $try = $line === '' ? $word : $line . ' ' . $word;
                if ($line !== '' && $pdf->GetStringWidth($try) > $width) { $out[] = $line; $line = $word; }
                else $line = $try;
            }
            $out[] = $line;
        }
        return $out;
    }

    /** Krzyżyk w polu ☐ o podanym prostokącie glifu (pt). */
    private static function cross(\Mpdf\Mpdf $pdf, float $x0, float $y0, float $x1, float $y1): void
    {
        $cx = ($x0 + $x1) / 2;
        $cy = $y0 + ($y1 - $y0) * 0.53;
        $h = min($x1 - $x0, $y1 - $y0) * 0.30;
        $pdf->SetLineWidth(self::mm(1.1));
        $pdf->Line(self::mm($cx - $h), self::mm($cy - $h), self::mm($cx + $h), self::mm($cy + $h));
        $pdf->Line(self::mm($cx - $h), self::mm($cy + $h), self::mm($cx + $h), self::mm($cy - $h));
    }
}
