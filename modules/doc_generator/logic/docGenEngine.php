<?php
/**
 * modules/doc_generator/logic/docGenEngine.php — silnik generatora dokumentów z wzorów.
 *
 * Wzór = tekst z prostą składnią + definicje pól (klucz, etykieta, typ, wymagane, domyślna wartość, grupa):
 *   {{klucz}}                         wartość pola (puste i niewymagane → linia do ręcznego uzupełnienia „______”),
 *   [forma żeńska|forma męska@pole]   odmiana wg pola z wartością K (żeńska) albo M (męska),
 *   {{#if pole}}…{{#else}}…{{/if}}    fragment warunkowy: pole niepuste albo {{#if pole=wartość}}; można zagnieżdżać,
 *   # Tytuł                           tytuł (środek, pogrubiony), ## Nagłówek (środek, pogrubiony), ### Podtytuł (pogrubiony),
 *   1. Ustęp                          akapit numerowany (wcięcie wiszące),  a) Punkt   podpunkt (głębsze wcięcie),  ☐ Pole wyboru,
 *   [[podpisy: wiersz~wiersz || wiersz~wiersz]]   tabela podpisów (kolumny rozdziela ||, wiersze ~),
 *   [[strona]]                        podział strony,  **tekst**  pogrubienie,  pozostałe wiersze — akapity wyjustowane.
 * Dane NIE są interpretowane jako składnia (wartości pól są wstawiane po rozwinięciu warunków), a HTML jest zawsze escapowany.
 */
final class DocGenEngine
{
    public const TYPES = ['text' => 'Tekst', 'textarea' => 'Tekst wielowierszowy', 'date' => 'Data', 'select' => 'Lista wyboru', 'gender' => 'Płeć (K/M) — do odmiany'];

    /** Klucze pól użytych w treści wzoru (kolejność pierwszego użycia). @return list<string> */
    public static function fieldKeys(string $body): array
    {
        preg_match_all('/\{\{#if\s+([a-z0-9_]+)|\{\{([a-z0-9_]+)\}\}|@([a-z0-9_]+)\]/', $body, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $x) { $k = $x[1] !== '' ? $x[1] : ($x[2] !== '' ? $x[2] : ($x[3] ?? '')); if ($k !== '' && !in_array($k, $out, true)) $out[] = $k; }
        return $out;
    }

    /** Normalizuje definicje pól (klucz => definicja). */
    public static function normalizeFields(array $fields): array
    {
        $out = [];
        foreach ($fields as $k => $f) {
            if (is_int($k)) { $k = (string)($f['key'] ?? ''); }
            if (!preg_match('/^[a-z0-9_]{1,40}$/', (string)$k)) continue;
            $type = isset(self::TYPES[$f['type'] ?? '']) ? $f['type'] : 'text';
            $out[$k] = ['key' => $k, 'label' => (string)($f['label'] ?? $k) ?: $k, 'type' => $type, 'required' => !empty($f['required']),
                        'default' => (string)($f['default'] ?? ''), 'group' => (string)($f['group'] ?? 'Dane'), 'hint' => (string)($f['hint'] ?? ''),
                        'options' => is_array($f['options'] ?? null) ? $f['options'] : (is_string($f['options'] ?? null) && $f['options'] !== '' ? array_map('trim', explode(',', $f['options'])) : [])];
        }
        return $out;
    }

    /** Wartość pola do tekstu (data → d.m.Y). */
    private static function val(array $values, array $fields, string $k): string
    {
        $v = trim((string)($values[$k] ?? ''));
        if ($v !== '' && ($fields[$k]['type'] ?? '') === 'date' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return date('d.m.Y', strtotime($v));
        return $v;
    }

    /** Rozwija wzór do czystego tekstu (jeszcze ze znacznikami blokowymi). */
    public static function render(string $body, array $values, array $fields): string
    {
        $t = str_replace("\r", '', $body);
        // 1) warunki — od najgłębszych
        $re = '/\{\{#if\s+([a-z0-9_]+)(?:=([^}]*))?\}\}((?:(?!\{\{#if).)*?)(?:\{\{#else\}\}((?:(?!\{\{#if).)*?))?\{\{\/if\}\}/s';
        for ($i = 0; $i < 50 && preg_match($re, $t); $i++) {
            $t = preg_replace_callback($re, function ($m) use ($values, $fields) {
                $v = self::val($values, $fields, $m[1]);
                $raw = trim((string)($values[$m[1]] ?? ''));
                $ok = ($m[2] ?? '') !== '' ? ($raw === trim($m[2]) || $v === trim($m[2])) : $v !== '';
                return $ok ? $m[3] : ($m[4] ?? '');
            }, $t);
        }
        // 2) odmiana płciowa
        $t = preg_replace_callback('/\[([^\[\]|@]*)\|([^\[\]|@]*)@([a-z0-9_]+)\]/u', fn($m) => (($values[$m[3]] ?? 'K') === 'M') ? $m[2] : $m[1], $t);
        // 3) pola
        return preg_replace_callback('/\{\{([a-z0-9_]+)\}\}/', function ($m) use ($values, $fields) {
            $v = self::val($values, $fields, $m[1]);
            return $v !== '' ? $v : '________________';
        }, $t);
    }

    /** Tekst po rozwinięciu → bloki. @return list<array{t:string,text?:string,n?:string,cols?:array}> */
    public static function blocks(string $text): array
    {
        $out = [];
        foreach (explode("\n", $text) as $line) {
            $l = rtrim($line);
            if (trim($l) === '') continue;
            if (preg_match('/^\[\[strona\]\]$/', trim($l))) { $out[] = ['t' => 'pagebreak']; continue; }
            if (preg_match('/^\[\[podpisy:(.*)\]\]$/u', trim($l), $m)) {
                $cols = array_map(fn($c) => array_values(array_filter(array_map('trim', explode('~', $c)), fn($x) => $x !== '')), explode('||', $m[1]));
                $out[] = ['t' => 'signs', 'cols' => $cols]; continue;
            }
            if (preg_match('/^###\s+(.*)$/u', $l, $m)) { $out[] = ['t' => 'h3', 'text' => $m[1]]; continue; }
            if (preg_match('/^##\s+(.*)$/u', $l, $m)) { $out[] = ['t' => 'h2', 'text' => $m[1]]; continue; }
            if (preg_match('/^#\s+(.*)$/u', $l, $m)) { $out[] = ['t' => 'title', 'text' => $m[1]]; continue; }
            if (preg_match('/^☐\s*(.*)$/u', $l, $m)) { $out[] = ['t' => 'box', 'text' => $m[1]]; continue; }
            if (preg_match('/^([a-z])\)\s+(.*)$/u', $l, $m)) { $out[] = ['t' => 'sub', 'n' => $m[1] . ')', 'text' => $m[2]]; continue; }
            if (preg_match('/^(\d{1,3})\.\s+(.*)$/u', $l, $m)) { $out[] = ['t' => 'num', 'n' => $m[1] . '.', 'text' => $m[2]]; continue; }
            $out[] = ['t' => 'p', 'text' => $l];
        }
        return $out;
    }

    /** Pogrubienie **x** → HTML (tekst escapowany). */
    private static function inlineHtml(string $s): string
    {
        $e = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        return preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $e);
    }

    /** Dokument jako HTML (podgląd / wydruk). $codesHtml — stopka z kodami (DocRegistry::footerHtml) albo ''. */
    public static function html(array $blocks, string $title, string $codesHtml = ''): string
    {
        return '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . htmlspecialchars($title) . '</title><style>' . self::css() . '</style></head><body>'
             . '<div class="dg-page">' . self::bodyHtml($blocks) . ($codesHtml !== '' ? '<div class="dg-foot">' . $codesHtml . '</div>' : '') . '</div></body></html>';
    }

    /** Sam kod HTML bloków (do osadzenia na stronie). */
    public static function bodyHtml(array $blocks): string
    {
        $h = '';
        foreach ($blocks as $b) {
            switch ($b['t']) {
                case 'title': $h .= '<h1 class="dg-title">' . self::inlineHtml($b['text']) . '</h1>'; break;
                case 'h2': $h .= '<h2 class="dg-h2">' . self::inlineHtml($b['text']) . '</h2>'; break;
                case 'h3': $h .= '<h3 class="dg-h3">' . self::inlineHtml($b['text']) . '</h3>'; break;
                case 'num': $h .= '<p class="dg-num"><span>' . htmlspecialchars($b['n']) . '</span><span>' . self::inlineHtml($b['text']) . '</span></p>'; break;
                case 'sub': $h .= '<p class="dg-num dg-sub"><span>' . htmlspecialchars($b['n']) . '</span><span>' . self::inlineHtml($b['text']) . '</span></p>'; break;
                case 'box': $h .= '<p class="dg-num"><span>☐</span><span>' . self::inlineHtml($b['text']) . '</span></p>'; break;
                case 'pagebreak': $h .= '<div style="page-break-after:always"></div>'; break;
                case 'signs':
                    $h .= '<table class="dg-signs"><tr>';
                    foreach ($b['cols'] as $col) { $h .= '<td>'; foreach ($col as $i => $ln) $h .= ($i === 0 ? '<div class="dg-sl">' . htmlspecialchars($ln) . '</div><div class="dg-line"></div>' : '<div class="dg-sc">' . htmlspecialchars($ln) . '</div>'); $h .= '</td>'; }
                    $h .= '</tr></table>'; break;
                default: $h .= '<p class="dg-p">' . self::inlineHtml($b['text']) . '</p>';
            }
        }
        return $h;
    }

    public static function css(): string
    {
        return 'body{font:11pt/1.35 "Times New Roman",serif;margin:0;color:#111}.dg-page{max-width:190mm;margin:10mm auto;padding:0 12mm}'
             . '.dg-title{font-size:13pt;text-align:center;margin:10pt 0 8pt}.dg-h2{font-size:11pt;text-align:center;margin:12pt 0 4pt}.dg-h3{font-size:11pt;margin:8pt 0 3pt}'
             . '.dg-p{text-align:justify;margin:0 0 6pt}.dg-num{display:flex;gap:8pt;text-align:justify;margin:0 0 6pt}.dg-num span:first-child{flex:0 0 20pt}.dg-sub{margin-left:22pt}'
             . '.dg-signs{width:100%;margin-top:28pt;border-collapse:collapse}.dg-signs td{width:50%;text-align:center;vertical-align:bottom;padding:0 8pt}.dg-line{border-top:1px solid #000;margin:34pt 10pt 2pt}.dg-sl{font-size:9pt}.dg-sc{font-size:9pt}'
             . '.dg-foot{margin-top:18pt;padding-top:6pt;border-top:1px solid #bbb}@media print{.dg-page{margin:0;padding:0 5mm}.dg-bar{display:none}}';
    }

    /** Dokument jako DOCX (bajty). $codes — wynik DocRegistry::footerCodes() albo null. */
    public static function docx(array $blocks, string $title, ?array $codes = null): string
    {
        $er = error_reporting();
        error_reporting($er & ~E_DEPRECATED & ~E_USER_DEPRECATED);
        $w = new \PhpOffice\PhpWord\PhpWord();
        $w->getDocInfo()->setTitle($title);
        $w->setDefaultFontName('Times New Roman');
        $w->setDefaultFontSize(11);
        $J = \PhpOffice\PhpWord\SimpleType\Jc::class;
        $just = ['alignment' => $J::BOTH, 'spaceAfter' => 100, 'lineHeight' => 1.2];
        $ctr = ['alignment' => $J::CENTER, 'spaceAfter' => 80, 'keepNext' => true];
        $num = $just + ['indentation' => ['left' => 400, 'hanging' => 400]];
        $s = $w->addSection(['marginLeft' => 1250, 'marginRight' => 1250, 'marginTop' => 1100, 'marginBottom' => 1100]);
        $footer = $s->addFooter();
        $footer->addPreserveText('{PAGE} / {NUMPAGES}', ['size' => 8], ['alignment' => $J::CENTER]);
        $tmpFiles = [];
        if ($codes) {
            $tmp = function (?string $png) use (&$tmpFiles) { if (!$png) return null; $p = tempnam(sys_get_temp_dir(), 'dgimg'); file_put_contents($p, $png); return $tmpFiles[] = $p; };
            $run = $footer->addTextRun(['alignment' => $J::CENTER]);
            if ($p = $tmp($codes['bar'] ?? null)) $run->addImage($p, ['width' => 130, 'height' => 17, 'wrappingStyle' => 'inline']);
            if ($p = $tmp($codes['qr'] ?? null)) $run->addImage($p, ['width' => 26, 'height' => 26, 'wrappingStyle' => 'inline']);
            $footer->addText(($codes['code'] ?? '') . ' · ' . ($codes['doc_number'] ?? '') . ' · weryfikacja: ' . ($codes['url'] ?? ''), ['size' => 6.5], ['alignment' => $J::CENTER]);
        }
        $runs = function ($cell, string $text, array $para, array $font = []) {
            $tr = $cell->addTextRun($para);
            foreach (preg_split('/(\*\*.+?\*\*)/s', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $part) {
                if (preg_match('/^\*\*(.+)\*\*$/s', $part, $m)) $tr->addText($m[1], $font + ['bold' => true]); else $tr->addText($part, $font);
            }
        };
        foreach ($blocks as $b) {
            switch ($b['t']) {
                case 'title': $runs($s, $b['text'], $ctr, ['bold' => true, 'size' => 12]); break;
                case 'h2': $runs($s, $b['text'], $ctr, ['bold' => true]); break;
                case 'h3': $runs($s, $b['text'], $just + ['keepNext' => true], ['bold' => true]); break;
                case 'num': $runs($s, $b['n'] . "\t" . $b['text'], $num + ['tabs' => [new \PhpOffice\PhpWord\Style\Tab('left', 400)]]); break;
                case 'sub': $runs($s, $b['n'] . "\t" . $b['text'], $just + ['indentation' => ['left' => 850, 'hanging' => 400], 'tabs' => [new \PhpOffice\PhpWord\Style\Tab('left', 850)]]); break;
                case 'box': $runs($s, "☐\t" . $b['text'], $num + ['tabs' => [new \PhpOffice\PhpWord\Style\Tab('left', 400)]]); break;
                case 'pagebreak': $s->addPageBreak(); break;
                case 'signs':
                    $s->addTextBreak(2);
                    $t = $s->addTable(['alignment' => \PhpOffice\PhpWord\SimpleType\JcTable::CENTER]);
                    $t->addRow();
                    foreach ($b['cols'] as $col) {
                        $c = $t->addCell(4500);
                        foreach ($col as $i => $ln) {
                            if ($i === 0) { $c->addText($ln, ['size' => 9], ['alignment' => $J::CENTER]); $c->addTextBreak(1); $c->addText('____________________________', [], ['alignment' => $J::CENTER]); }
                            else $c->addText($ln, ['size' => 9], ['alignment' => $J::CENTER]);
                        }
                    }
                    break;
                default: $runs($s, $b['text'], $just);
            }
        }
        $tmp = tempnam(sys_get_temp_dir(), 'dgdoc');
        \PhpOffice\PhpWord\IOFactory::createWriter($w, 'Word2007')->save($tmp);
        $bytes = (string)file_get_contents($tmp);
        @unlink($tmp);
        foreach ($tmpFiles as $f) @unlink($f);
        error_reporting($er);
        return $bytes;
    }
}
