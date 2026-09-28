<?php
/**
 * modules/ti_pdf/logic/TiPdf.php — wspólna baza wydruków PDF modułu TI (FPDF).
 *
 * Po co: wydruki TI (plan zajęć, harmonogram, lista obecności, raport
 * miesięczny, karta lekcji, rozliczenia, dni wolne, siatki Librus…) pisały
 * wbudowaną Helveticą z przekodowaniem na CP1252 — ta strona kodowa NIE MA
 * ą ę ś ć ź ż ł ń, więc na wydrukach było „Zajecia”, „Obecnosc”, „Pazdziernik”.
 *
 * TiPdf:
 *   • osadza DejaVu Sans (zwykła + pogrubiona, ISO-8859-2 — wszystkie polskie
 *     litery; pliki includes/fpdf/font/dejavusans*.json, jak w rozpisce godzin)
 *     i po cichu mapuje na nią Helvetica/Arial z istniejącego kodu wydruków;
 *   • TiPdf::pl($tekst) — UTF-8 → ISO-8859-2, typografia spoza strony kodowej
 *     („ ” — – … · › €) zamieniana na czytelne odpowiedniki zamiast znikać;
 *   • jednolita stopka na każdej stronie: cienka granatowa linia, nazwa
 *     organizacji i „Strona X z Y” (poniżej wiersza „Wygenerowano…”, który
 *     wydruki stawiają na −15 mm).
 * Kursywy DejaVu nie ma w projekcie — styl I jest pomijany (zostaje B/U).
 */
require_once dirname(__DIR__, 3) . '/includes/fpdf/fpdf.php';

class TiPdf extends FPDF
{
    /** Stopka z numeracją stron (wyłączalna dla nietypowych wydruków). */
    public bool $tiFooter = true;
    public string $tiOrg = '';

    public function __construct($orientation = 'P', $unit = 'mm', $size = 'A4')
    {
        parent::__construct($orientation, $unit, $size);
        $dir = dirname(__DIR__, 3) . '/includes/fpdf/font/';
        $this->AddFont('DejaVu', '',  'dejavusans.json',  $dir);
        $this->AddFont('DejaVu', 'B', 'dejavusansb.json', $dir);
        $this->AliasNbPages('{nb}');
        $this->tiOrg = (string)(function_exists('org_setting') ? (org_setting('org_name') ?: '') : '')
                     ?: (defined('ORG_NAME') ? ORG_NAME : '');
    }

    /** Helvetica/Arial → DejaVu (polskie znaki); kursywa pomijana. Courier (kody) bez zmian. */
    public function SetFont($family, $style = '', $size = 0)
    {
        $f = strtolower((string)$family);
        if ($f === '' || in_array($f, ['helvetica', 'arial', 'times', 'dejavu'], true)) {
            $family = 'DejaVu';
            $style  = str_replace('I', '', strtoupper((string)$style));
        }
        parent::SetFont($family, $style, $size);
    }

    /** UTF-8 → ISO-8859-2 (kodowanie osadzonej DejaVu). */
    public static function pl($s): string
    {
        $s = strtr((string)$s, [
            '„' => '"', '”' => '"', '“' => '"', '‘' => "'", '’' => "'",
            '—' => '-', '–' => '-', '…' => '...', '·' => '-', '›' => '>', '‹' => '<',
            '€' => 'EUR', '✓' => 'v', '✔' => 'v', '✗' => 'x', '→' => '->', '←' => '<-',
            "\u{00A0}" => ' ',
        ]);
        $out = @iconv('UTF-8', 'ISO-8859-2//TRANSLIT//IGNORE', $s);
        return $out !== false ? $out : $s;
    }

    public function Footer()
    {
        if (!$this->tiFooter) return;
        $keepFont = [$this->FontFamily, $this->FontStyle, $this->FontSizePt];
        $this->SetY(-10);
        $this->SetDrawColor(16, 51, 92);
        $this->SetLineWidth(0.2);
        $this->Line($this->lMargin, $this->GetY(), $this->GetPageWidth() - $this->rMargin, $this->GetY());
        $this->SetY(-9);
        $this->SetFont('DejaVu', '', 7);
        $this->SetTextColor(110, 118, 130);
        $w = $this->GetPageWidth() - $this->lMargin - $this->rMargin;
        $this->Cell($w / 2, 4, self::pl($this->tiOrg), 0, 0, 'L');
        $this->Cell($w / 2, 4, self::pl('Strona ' . $this->PageNo() . ' z {nb}'), 0, 0, 'R');
        $this->SetTextColor(0, 0, 0);
        if ($keepFont[0] !== '') parent::SetFont($keepFont[0], $keepFont[1], $keepFont[2]);
    }
}
