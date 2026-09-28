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
 *   • paleta panelu (2026-09-29): stałe kolory wpisane w wydrukach (kilkanaście
 *     odcieni) mapowane przy rysowaniu na barwy skórki USOS — paski tytułowe
 *     na granat #10335c ze złotą kreską pod pełnoszerokim tytułem, nagłówki
 *     tabel #dde6f0, zebra #f4f7fa, siatka #b9c6d6, tekst #1c1c1c, szarości
 *     w dwóch stopniach. Kolory dynamiczne (np. statusy lekcji) bez zmian.
 * Kursywy DejaVu nie ma w projekcie — styl I jest pomijany (zostaje B/U).
 */
require_once dirname(__DIR__, 3) . '/includes/fpdf/fpdf.php';

class TiPdf extends FPDF
{
    /** Stopka z numeracją stron (wyłączalna dla nietypowych wydruków). */
    public bool $tiFooter = true;
    public string $tiOrg = '';

    /** Barwy skórki (ti_skin.css) — wspólne dla wydruków. */
    public const NAVY = [16, 51, 92], GOLD = [200, 161, 26], BAR = [221, 230, 240],
                 ZEBRA = [244, 247, 250], GRID = [185, 198, 214], TEXT = [28, 28, 28],
                 MUTED = [91, 102, 119], SOFT = [69, 81, 107], RED = [176, 42, 55], GREEN = [31, 122, 77];
    private const FILL_MAP = [
        '15,80,150' => 'NAVY', '30,58,95' => 'NAVY', '30,41,59' => 'NAVY',
        '224,232,244' => 'BAR', '220,232,248' => 'BAR', '230,236,245' => 'BAR',
        '241,245,249' => 'ZEBRA', '248,250,252' => 'ZEBRA', '245,248,255' => 'ZEBRA', '240,244,250' => 'ZEBRA',
        '238,240,244' => 'ZEBRA', '240,240,240' => 'ZEBRA', '238,238,238' => 'ZEBRA',
    ];
    private const DRAW_MAP = ['190,205,225' => 'GRID', '180,195,215' => 'GRID', '180,190,200' => 'GRID', '208,208,216' => 'GRID', '226,232,240' => 'GRID'];
    private const TEXT_MAP = [
        '0,0,0' => 'TEXT', '130,130,130' => 'MUTED', '120,120,120' => 'MUTED', '110,110,110' => 'MUTED', '148,163,184' => 'MUTED',
        '100,100,100' => 'SOFT', '90,90,90' => 'SOFT', '80,80,80' => 'SOFT', '71,85,105' => 'SOFT', '51,65,85' => 'SOFT', '30,41,59' => 'NAVY',
        '170,0,0' => 'RED', '0,130,0' => 'GREEN',
    ];
    /** Czy bieżące wypełnienie to pasek tytułowy (granat) — pod pełnoszerokim dostaje złotą kreskę. */
    private bool $tiTitleFill = false;
    private array $tiFillRgb = [255, 255, 255];

    private static function mapRgb(array $map, $r, $g, $b): array
    {
        if ($g === null) { $g = $b = $r; }
        $k = (int)$r . ',' . (int)$g . ',' . (int)$b;
        return isset($map[$k]) ? constant('self::' . $map[$k]) : [(int)$r, (int)$g, (int)$b];
    }

    public function SetFillColor($r, $g = null, $b = null)
    {
        $rgb = self::mapRgb(self::FILL_MAP, $r, $g, $b);
        $this->tiTitleFill = $rgb === self::NAVY;
        $this->tiFillRgb = $rgb;
        parent::SetFillColor($rgb[0], $rgb[1], $rgb[2]);
    }

    public function SetDrawColor($r, $g = null, $b = null)
    {
        $rgb = self::mapRgb(self::DRAW_MAP, $r, $g, $b);
        parent::SetDrawColor($rgb[0], $rgb[1], $rgb[2]);
    }

    public function SetTextColor($r, $g = null, $b = null)
    {
        $rgb = self::mapRgb(self::TEXT_MAP, $r, $g, $b);
        parent::SetTextColor($rgb[0], $rgb[1], $rgb[2]);
    }

    /** Pod pełnoszerokim paskiem tytułowym (granat) — złota kreska jak w nagłówku panelu. */
    public function Cell($w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false, $link = '')
    {
        $x = $this->x; $y = $this->y; $page = $this->page;
        parent::Cell($w, $h, $txt, $border, $ln, $align, $fill, $link);
        // Komórka przeniesiona na nową stronę (auto page break) — pozycja sprzed niej nieaktualna
        if (!$fill || !$this->tiTitleFill || $h <= 0 || $this->page !== $page) return;
        $cw = $w == 0 ? $this->w - $this->rMargin - $x : $w;
        if ($cw < ($this->w - $this->lMargin - $this->rMargin) * 0.85) return;
        parent::SetFillColor(self::GOLD[0], self::GOLD[1], self::GOLD[2]);
        $this->Rect($x, $y + $h, $cw, 0.7, 'F');
        parent::SetFillColor($this->tiFillRgb[0], $this->tiFillRgb[1], $this->tiFillRgb[2]);
    }

    public function __construct($orientation = 'P', $unit = 'mm', $size = 'A4')
    {
        parent::__construct($orientation, $unit, $size);
        $dir = dirname(__DIR__, 3) . '/includes/fpdf/font/';
        $this->AddFont('DejaVu', '',  'dejavusans.json',  $dir);
        $this->AddFont('DejaVu', 'B', 'dejavusansb.json', $dir);
        $this->AliasNbPages('{nb}');
        // Domyślne linie (ramki tabel bez jawnego koloru) w kolorze siatki zamiast czerni
        parent::SetDrawColor(self::GRID[0], self::GRID[1], self::GRID[2]);
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
