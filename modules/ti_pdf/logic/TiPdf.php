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
 *   • jednolita stopka na każdej stronie: cyjanowa linia, nazwa
 *     organizacji i „Strona X z Y” (poniżej wiersza „Wygenerowano…”, który
 *     wydruki stawiają na −15 mm).
 *   • paleta METRO (2026-10-22): stałe kolory wpisane w wydrukach (kilkanaście
 *     odcieni) mapowane przy rysowaniu na barwy METRO — paski tytułowe
 *     grafit #1d1d1d z cyjanową kreską #1ba1e2 pod pełnoszerokim tytułem,
 *     nagłówki tabel #f3f4f6, zebra #f9fafb, siatka #d1d5db, tekst #1d1d1d,
 *     szarości #4b5563 / #374151. Stałe NAVY/GOLD zachowały nazwy (używane
 *     w wydrukach), zmieniły wartości. Kolory dynamiczne (np. statusy lekcji) bez zmian.
 * Kursywy DejaVu nie ma w projekcie — styl I jest pomijany (zostaje B/U).
 */
require_once dirname(__DIR__, 3) . '/includes/fpdf/fpdf.php';

class TiPdf extends FPDF
{
    /** Stopka z numeracją stron (wyłączalna dla nietypowych wydruków). */
    public bool $tiFooter = true;
    public string $tiOrg = '';

    /** Barwy skórki (ti_skin.css) — wspólne dla wydruków. */
    // Kafle METRO: tytuł = kafel #0f6fa8 (biały 5,4:1) z grafitową listwą; tabele bez ramek pionowych, tylko linie poziome.
    // METRO (jak ti_metro_kursant.css / Tożsamość): grafit, cyjan #1ba1e2, płasko, bez zaokrągleń.
    // Kontrasty na białym: TEXT 16:1, SOFT 10:1, MUTED 7,6:1, RED 6,9:1, GREEN 5,6:1; biały na NAVY 16:1.
    public const NAVY = [15, 111, 168], GOLD = [29, 29, 29], BAR = [243, 244, 246],
                 ZEBRA = [249, 250, 251], GRID = [209, 213, 219], TEXT = [29, 29, 29],
                 MUTED = [75, 85, 99], SOFT = [55, 65, 81], RED = [162, 0, 37], GREEN = [0, 122, 0], LINK = [15, 111, 168], AMBER = [138, 74, 0];
    private const FILL_MAP = [
        '15,80,150' => 'NAVY', '30,58,95' => 'NAVY', '30,64,120' => 'NAVY', '233,238,245' => 'BAR', '236,240,246' => 'BAR', '210,221,236' => 'BAR', '233,245,248' => 'BAR', '220,230,245' => 'BAR', '247,249,253' => 'ZEBRA', '245,245,245' => 'ZEBRA', '37,99,235' => 'NAVY', '8,145,178' => 'NAVY', '30,41,59' => 'NAVY',
        '224,232,244' => 'BAR', '220,232,248' => 'BAR', '230,236,245' => 'BAR',
        '241,245,249' => 'ZEBRA', '248,250,252' => 'ZEBRA', '245,248,255' => 'ZEBRA', '240,244,250' => 'ZEBRA',
        '238,240,244' => 'ZEBRA', '240,240,240' => 'ZEBRA', '238,238,238' => 'ZEBRA',
    ];
    private const DRAW_MAP = ['190,205,225' => 'GRID', '180,195,215' => 'GRID', '180,190,200' => 'GRID', '208,208,216' => 'GRID', '200,210,220' => 'GRID', '150,165,185' => 'GRID', '226,232,240' => 'GRID'];
    private const TEXT_MAP = [
        '0,0,0' => 'TEXT', '130,130,130' => 'MUTED', '120,120,120' => 'MUTED', '110,110,110' => 'MUTED', '148,163,184' => 'MUTED',
        '100,100,100' => 'SOFT', '90,90,90' => 'SOFT', '80,80,80' => 'SOFT', '71,85,105' => 'SOFT', '51,65,85' => 'SOFT', '30,41,59' => 'NAVY',
        '170,0,0' => 'RED', '0,130,0' => 'GREEN', '15,80,150' => 'LINK', '15,40,80' => 'NAVY', '40,40,40' => 'TEXT', '150,150,150' => 'MUTED',
        '180,60,60' => 'RED', '160,30,30' => 'RED', '0,120,0' => 'GREEN', '0,110,0' => 'GREEN', '0,100,20' => 'GREEN', '180,100,0' => 'AMBER', '150,60,0' => 'AMBER',
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
        // Styl kafli: pełna ramka komórki → tylko dolna linia (bez siatki pionowej)
        if ($border === 1 || $border === '1' || (is_string($border) && $border !== '' && strpbrk($border, 'LRT') !== false)) $border = 'B';
        $x = $this->x; $y = $this->y; $page = $this->page;
        parent::Cell($w, $h, $txt, $border, $ln, $align, $fill, $link);
        // Komórka przeniesiona na nową stronę (auto page break) — pozycja sprzed niej nieaktualna
        if (!$fill || !$this->tiTitleFill || $h <= 0 || $this->page !== $page) return;
        $cw = $w == 0 ? $this->w - $this->rMargin - $x : $w;
        if ($cw < ($this->w - $this->lMargin - $this->rMargin) * 0.85) return;
        parent::SetFillColor(self::GOLD[0], self::GOLD[1], self::GOLD[2]);
        $this->Rect($x, $y + $h, $cw, 1.4, 'F');
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
        parent::SetFillColor(self::NAVY[0], self::NAVY[1], self::NAVY[2]);
        $this->Rect($this->lMargin, $this->GetY() + 0.6, 3.2, 3.2, 'F');
        $this->SetX($this->lMargin + 5);
        $this->SetFont('DejaVu', 'B', 7);
        $this->SetTextColor(75, 85, 99);
        $w = $this->GetPageWidth() - $this->lMargin - $this->rMargin;
        $this->Cell($w / 2 - 5, 4.4, self::pl($this->tiOrg), 0, 0, 'L');
        $this->SetFont('DejaVu', '', 7);
        $this->Cell($w / 2, 4.4, self::pl('Strona ' . $this->PageNo() . ' z {nb}'), 0, 0, 'R');
        $this->SetTextColor(0, 0, 0);
        if ($keepFont[0] !== '') parent::SetFont($keepFont[0], $keepFont[1], $keepFont[2]);
    }
}
