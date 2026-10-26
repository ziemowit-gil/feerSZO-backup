<?php
declare(strict_types=1);

/**
 * modules/ti_pdf/logic/tiCitStyle.php — układ „jak dokumenty AM” (formularz w stylu CIT) dla dokumentów TI generowanych mPDF:
 * nagłówek z numerem i kolorowym symbolem dokumentu (np. KT1), tytuł, legenda, sekcje A–F z numerowanymi polami i stopka
 * (symbol, wersja wzoru, numer dokumentu, strona). Każdy dokument tworzy własny obiekt, bo numery pól liczone są od 1 w obrębie dokumentu.
 */
final class TiCit
{
    public const VERSION = '1/2026';
    private int $n = 0;

    public static function e($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }

    /** Wartość albo podpowiedź wzoru z kropkami. */
    public static function val(string $v, string $hint): string
    {
        $v = trim($v);
        return $v === '' ? '<span class="ph">' . self::e($hint) . '</span><div class="dots">&nbsp;</div>' : nl2br(self::e($v));
    }

    public static function css(): string
    {
        return '<style>
          body{font-family:dejavusans;font-size:8.6pt;color:#000}
          table.t{width:100%;border-collapse:collapse;margin:0}
          table.t td{border:0.25mm solid #000;vertical-align:top}
          td.f{padding:0.6mm 1.4mm 0.9mm;background:#fff}
          td.pc{padding:1mm 1.6mm 1.2mm;background:#fff}
          .pt{font-size:8.8pt;text-align:justify;line-height:1.4}
          .lb{font-size:6.4pt;color:#222;margin-bottom:0.4mm}
          .v{font-size:9pt;font-weight:bold}
          .ph{font-weight:normal;font-size:7pt;color:#666;font-style:italic}
          .dots{border-bottom:0.2mm dotted #000;height:4mm}
          .big{font-size:10.5pt;letter-spacing:0.2mm}
          .mono{font-family:dejavusansmono;font-size:7pt;font-weight:normal}
          td.sec{background:#bfbfbf;padding:1mm 1.6mm;font-size:8.6pt;letter-spacing:0.15mm}
          td.sym{text-align:center;vertical-align:middle;color:#fff}
          .symb{font-size:22pt;font-weight:bold;letter-spacing:0.5mm}
          .symp{font-size:11pt;font-weight:bold}
          .symv{font-size:6.5pt}
          td.ttl{text-align:center;padding:1.6mm 2mm;background:#fff}
          .ttl1{font-size:12pt;font-weight:bold;letter-spacing:0.2mm}
          .ttl2{font-size:7.6pt;margin-top:0.8mm}
          td.lgd{font-size:6.8pt;padding:1mm 1.6mm;background:#f2f2f2}
        </style>';
    }

    /** Pole z numerem: <td> z etykietą (numer kolejny) i wartością. */
    public function fld(string $label, string $valueHtml, string $extra = ''): string
    {
        $this->n++;
        return '<td class="f"' . $extra . '><div class="lb"><b>' . $this->n . '.</b> ' . $label . '</div><div class="v">' . ($valueHtml !== '' ? $valueHtml : '&nbsp;') . '</div></td>';
    }

    public static function sec(string $l, string $t, int $cols = 1): string
    {
        return '<tr><td class="sec" colspan="' . $cols . '"><b>' . $l . '. ' . self::e($t) . '</b></td></tr>';
    }

    /** Nagłówek: pole z numerem dokumentu, drugie pole i kolorowy symbol (np. KT1). */
    public function header(string $numberLabel, string $number, string $label2, string $value2Html, string $symbol, string $color, string $part = ''): string
    {
        return '<table class="t"><tr>'
            . $this->fld($numberLabel, '<span class="big">' . self::e($number) . '</span>', ' width="45%"')
            . $this->fld($label2, $value2Html, ' width="35%"')
            . '<td class="sym" width="20%" style="background:' . self::e($color) . '"><div class="symb">' . self::e($symbol) . '</div><div class="symp">' . ($part !== '' ? self::e($part) : '&nbsp;') . '</div><div class="symv">wersja wzoru ' . self::VERSION . '</div></td>'
            . '</tr></table>';
    }

    /** Tytuł dokumentu z podtytułem i legendą (kto wystawia, etap). */
    public static function title(string $t1, string $t2, string $legendHtml): string
    {
        return '<table class="t"><tr><td class="ttl"><div class="ttl1">' . self::e($t1) . '</div><div class="ttl2">' . self::e($t2) . '</div></td></tr>'
            . '<tr><td class="lgd">' . $legendHtml . '</td></tr></table>';
    }

    /** Stopka mPDF: symbol (wersja) · numer · strona. Kod Code128 dokłada ti_student_doc_footer(). */
    public static function footer(string $symbol, string $number): string
    {
        return '<table width="100%" style="font-family:dejavusans;font-size:7pt;border-top:0.25mm solid #000"><tr>'
            . '<td width="33%"><b>' . self::e($symbol) . '</b> (' . self::VERSION . ')</td><td width="34%" style="text-align:center">' . self::e($number) . '</td>'
            . '<td width="33%" style="text-align:right">Strona {PAGENO}/{nbpg}</td></tr></table>';
    }
}
