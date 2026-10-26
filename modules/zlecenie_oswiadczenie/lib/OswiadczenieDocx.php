<?php
declare(strict_types=1);

/**
 * modules/zlecenie_oswiadczenie/lib/OswiadczenieDocx.php — wypełnia ORYGINALNY wzór „Oświadczenie dla celów podatkowych
 * i ubezpieczenia ZUS” (lib/wzor/oswiadczenieZus.docx) odpowiedziami z kreatora.
 *
 * Wzór jest NIEMODYFIKOWALNY:
 *  - plik wzoru jest zablokowany sumą SHA-256 (TEMPLATE_SHA256) — inny plik = odmowa wydruku; w systemie nie ma edycji wzoru;
 *  - wypełnianie tylko: wpisuje odpowiedź w miejsce kropek/podkreśleń, zaznacza kratkę (□ → ☒) i skreśla niewłaściwy
 *    wariant („Jestem/ nie jestem”, „DZIEŁO/ZLECENIA*”, końcówki „-a/-ny”) — tekst wzoru zostaje znak w znak;
 *  - po wypełnieniu assertTemplateIntact() porównuje każdy akapit z oryginałem (bez wpisanych odpowiedzi i kropek)
 *    i rzuca wyjątek przy jakiejkolwiek różnicy; pozostałe części pliku DOCX (style, czcionki, ustawienia) są kopiowane bajt w bajt.
 *
 * Model akapitów (paragraphs()) jest wspólny dla DOCX i PDF (OswiadczeniePdf), więc oba wydruki mają tę samą treść.
 */
final class OswiadczenieDocx
{
    public const TEMPLATE        = __DIR__ . '/wzor/oswiadczenieZus.docx';
    public const TEMPLATE_SHA256 = 'bb98add3a0abf9bed510ebb0f561e985ed6fd53de1c0b63f2085c2023ae7de71';
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const PLACEHOLDER = '.…_';
    /** Kolejność elementów w w:rPr (schemat OOXML) — dopisywane właściwości wstawiamy na właściwe miejsce. */
    private const RPR_ORDER = ['rStyle', 'rFonts', 'b', 'bCs', 'i', 'iCs', 'caps', 'smallCaps', 'strike', 'dstrike', 'outline',
        'shadow', 'emboss', 'imprint', 'noProof', 'snapToGrid', 'vanish', 'webHidden', 'color', 'spacing', 'w', 'kern', 'position',
        'sz', 'szCs', 'highlight', 'u', 'effect', 'bdr', 'shd', 'fitText', 'vertAlign', 'rtl', 'cs', 'em', 'lang', 'eastAsianLayout', 'specVanish', 'oMath'];

    /** Czy plik wzoru jest dokładnie tym zatwierdzonym (suma SHA-256). */
    public static function templateOk(): bool
    {
        return is_file(self::TEMPLATE) && hash_file('sha256', self::TEMPLATE) === self::TEMPLATE_SHA256;
    }

    /** Wypełniony DOCX (bajty). $a — odpowiedzi kreatora, $umowa — ['numer' => …, 'data' => 'd.m.Y' | ''] */
    public static function fill(array $a, array $umowa): string
    {
        [$dom, $paras, $orig] = self::build($a, $umowa);
        self::assertTemplateIntact($orig, $paras);   // strażnik treści — PRZED dodaniem stopki; stopka to osobna część pliku, poza treścią wzoru
        $foot = self::footerParts($dom, $a, $umowa);
        $zipIn = new ZipArchive();
        if ($zipIn->open(self::TEMPLATE) !== true) throw new RuntimeException('Nie można otworzyć wzoru oświadczenia.');
        $tmp = tempnam(sys_get_temp_dir(), 'osw');
        $zipOut = new ZipArchive();
        if ($zipOut->open($tmp, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Nie można utworzyć pliku DOCX.');
        for ($i = 0; $i < $zipIn->numFiles; $i++) {
            $name = (string)$zipIn->getNameIndex($i);
            $data = $name === 'word/document.xml' ? $dom->saveXML() : (string)$zipIn->getFromIndex($i);
            if ($foot) {
                if ($name === '[Content_Types].xml') $data = str_replace('</Types>', $foot['types'] . '</Types>', $data);
                if ($name === 'word/_rels/document.xml.rels') $data = str_replace('</Relationships>', $foot['rels'] . '</Relationships>', $data);
            }
            $zipOut->addFromString($name, $data);
        }
        if ($foot) foreach ($foot['files'] as $fn => $bytes) $zipOut->addFromString($fn, $bytes);
        $zipIn->close();
        $zipOut->close();
        $bytes = (string)file_get_contents($tmp);
        @unlink($tmp);
        return $bytes;
    }

    /**
     * Identyfikacja dokumentu do kodów w stopce (rejestr dokumentów) — wspólna dla DOCX i PDF: jeden kod na oświadczenie ZUS/PIT do umowy.
     * Tytuł bez danych osobowych; bez numeru umowy tożsamość pochodzi z skrótu nazwiska (nie jest zapisywane jawnie).
     * @return array{0: string, 1: string} [ref, title]
     */
    public static function footerRef(array $a, array $umowa): array
    {
        $nr = trim((string)($umowa['numer'] ?? ''));
        $id = $nr !== '' ? $nr : 'h' . substr(sha1((string)($a['nazwisko_imiona'] ?? '')), 0, 12);
        return ['zus|' . $id, 'Oświadczenie dla celów podatkowych i ubezpieczenia ZUS' . ($nr !== '' ? ' — umowa ' . $nr : '')];
    }

    /**
     * Stopka z kodami Code128 + QR w pliku DOCX: osobna część word/footer1.xml + obrazy + powiązania; do document.xml dopisywany jest tylko
     * <w:footerReference> w sectPr — żaden akapit treści wzoru nie jest ruszany. Pusta tablica, gdy kodów nie udało się zbudować
     * (brak stopki nie blokuje wydruku).
     * @return array{files: array<string,string>, rels: string, types: string}|array{}
     */
    private static function footerParts(DOMDocument $dom, array $a, array $umowa): array
    {
        try {
            require_once dirname(__DIR__, 2) . '/doc_registry/lib/DocRegistry.php';
            [$ref, $title] = self::footerRef($a, $umowa);
            $f = DocRegistry::printCodes('zlecenie_osw', $ref, $title, 'modules/zlecenie_oswiadczenie/router.php');
            if (!$f) return [];
            $xp = new DOMXPath($dom);
            $xp->registerNamespace('w', self::W);
            $sect = $xp->query('//w:body/w:sectPr')->item(0);
            if (!$sect instanceof DOMElement) return [];
            $R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
            $files = []; $rels = ''; $runs = ''; $n = 0;
            foreach (['bar' => ['Kod kreskowy', 0.19], 'qr' => ['Kod QR', 0.34]] as $k => [$alt, $hMm]) {
                if (empty($f[$k]) || !($sz = @getimagesizefromstring($f[$k]))) continue;
                $n++;
                $cy = (int)round($hMm * 1000 * 36); $cx = (int)round($cy * $sz[0] / max(1, $sz[1]));   // wysokość w EMU (1 mm = 36000), proporcje obrazu
                $files['word/media/kod' . $n . '.png'] = $f[$k];
                $rels .= '<Relationship Id="rIdKod' . $n . '" Type="' . $R . '/image" Target="media/kod' . $n . '.png"/>';
                $runs .= '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="72000"><wp:extent cx="' . $cx . '" cy="' . $cy . '"/>'
                       . '<wp:docPr id="' . (900 + $n) . '" name="' . $alt . '" descr="' . $alt . ' ' . htmlspecialchars($f['code'], ENT_QUOTES) . '"/>'
                       . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
                       . '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:nvPicPr><pic:cNvPr id="' . (900 + $n) . '" name="kod' . $n . '.png"/><pic:cNvPicPr/></pic:nvPicPr>'
                       . '<pic:blipFill><a:blip r:embed="rIdKod' . $n . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
                       . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic>'
                       . '</a:graphicData></a:graphic></wp:inline></w:drawing></w:r>';
            }
            if (!$n) return [];
            $txt = htmlspecialchars($f['code'] . ' · ' . $f['doc_number'], ENT_XML1, 'UTF-8');
            $files['word/footer1.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<w:ftr xmlns:w="' . self::W . '" xmlns:r="' . $R . '" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing">'
                . '<w:p><w:pPr><w:spacing w:before="0" w:after="0"/><w:jc w:val="left"/></w:pPr>' . $runs
                . '<w:r><w:rPr><w:rFonts w:ascii="Consolas" w:hAnsi="Consolas"/><w:color w:val="555555"/><w:sz w:val="13"/></w:rPr><w:t xml:space="preserve">' . $txt . '</w:t></w:r></w:p></w:ftr>';
            $rels .= '<Relationship Id="rIdKodFooter" Type="' . $R . '/footer" Target="footer1.xml"/>';
            $fr = $dom->createElementNS(self::W, 'w:footerReference');
            $fr->setAttributeNS(self::W, 'w:type', 'default');
            $fr->setAttributeNS($R, 'r:id', 'rIdKodFooter');
            $sect->insertBefore($fr, $sect->firstChild);
            return ['files' => $files, 'rels' => $rels,
                    'types' => '<Default Extension="png" ContentType="image/png"/><Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>'];
        } catch (\Throwable $e) { error_log('[zlecenie_oswiadczenie] stopka DOCX: ' . $e->getMessage()); return []; }
    }

    /**
     * Akapity wypełnionego wzoru do PDF: [['align' => 'center'|'left', 'runs' => [['text', bold, strike, value, check]]]].
     * 'row' — wiersz tabeli wzoru (komórki jednego wiersza leżą obok siebie), null poza tabelą.
     */
    public static function paragraphs(array $a, array $umowa): array
    {
        [, $paras, $orig] = self::build($a, $umowa);
        self::assertTemplateIntact($orig, $paras);
        $out = [];
        foreach ($paras as $p) {
            $runs = [];
            foreach (self::group($p['chars']) as $g) {
                $runs[] = ['text' => $g['text'], 'bold' => $g['bold'] || $g['value'], 'strike' => $g['strike'],
                           'value' => $g['value'], 'check' => $g['check']];
            }
            $out[] = ['align' => $p['align'], 'runs' => $runs, 'row' => $p['row']];
        }
        return $out;
    }

    // ── Silnik ────────────────────────────────────────────────────────────────

    /** @return array{0: DOMDocument, 1: array, 2: array} dokument po zmianach, akapity po zmianach, akapity oryginalne */
    private static function build(array $a, array $umowa): array
    {
        if (!self::templateOk()) {
            throw new RuntimeException('Wzór oświadczenia został zmieniony lub go brakuje (niezgodna suma kontrolna) — wydruk zablokowany.');
        }
        $zip = new ZipArchive();
        if ($zip->open(self::TEMPLATE) !== true) throw new RuntimeException('Nie można otworzyć wzoru oświadczenia.');
        $xml = (string)$zip->getFromName('word/document.xml');
        $zip->close();
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = true;
        if (!$dom->loadXML($xml)) throw new RuntimeException('Uszkodzony wzór oświadczenia.');
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', self::W);

        $paras = [];
        foreach ($xp->query('//w:body//w:p') as $pNode) {
            /** @var DOMElement $pNode */
            $chars = [];
            foreach ($xp->query('w:r', $pNode) as $r) {
                $rPr = $xp->query('w:rPr', $r)->item(0);
                $bold = $rPr && $xp->query('w:b', $rPr)->length > 0;
                foreach ($r->childNodes as $c) {
                    if (!$c instanceof DOMElement) continue;
                    if ($c->localName === 't') {
                        foreach (mb_str_split($c->textContent) as $ch) $chars[] = self::ch($ch, $rPr, $bold);
                    } elseif ($c->localName === 'tab') {
                        $chars[] = self::ch("\t", $rPr, $bold);
                    }
                }
            }
            $jc = $xp->query('w:pPr/w:jc', $pNode)->item(0);
            $tr = $xp->query('ancestor::w:tr', $pNode)->item(0);
            $paras[] = ['node' => $pNode, 'chars' => $chars, 'align' => $jc ? (string)$jc->getAttributeNS(self::W, 'val') : 'left', 'dirty' => false,
                        'row' => $tr ? spl_object_id($tr) : null];
        }
        $orig = array_map(fn($p) => $p['chars'], $paras);

        self::applyAnswers($paras, $a, $umowa);

        foreach ($paras as $p) if ($p['dirty']) self::rewrite($dom, $xp, $p);
        return [$dom, $paras, $orig];
    }

    private static function ch(string $c, ?DOMElement $rPr, bool $bold, array $flags = []): array
    {
        return ['c' => $c, 'rPr' => $rPr, 'bold' => $bold, 'value' => $flags['value'] ?? false,
                'strike' => false, 'check' => false, 'orig' => $flags['orig'] ?? $c];
    }

    private static function text(array $chars): string { return implode('', array_column($chars, 'c')); }

    /** Indeks akapitu zaczynającego się od $prefix (po przycięciu). */
    private static function find(array $paras, string $prefix): int
    {
        foreach ($paras as $i => $p) if (str_starts_with(ltrim(self::text($p['chars'])), $prefix)) return $i;
        throw new RuntimeException('Wzór oświadczenia nie zawiera akapitu „' . $prefix . '”.');
    }

    /** Pozycja (w znakach) pierwszego wystąpienia $needle od $from albo wyjątek. */
    private static function pos(array $chars, string $needle, int $from = 0): int
    {
        $t = self::text($chars);
        $p = mb_strpos($t, $needle, $from);
        if ($p === false) throw new RuntimeException('Wzór oświadczenia nie zawiera „' . $needle . '”.');
        return $p;
    }

    /** Zakres pola (kropki/podkreślenia, także z odstępami „_ _ _”) zaraz po etykiecie $label. */
    private static function slot(array $chars, string $label, int $from = 0): array
    {
        $i = self::pos($chars, $label, $from) + mb_strlen($label);
        $n = count($chars);
        while ($i < $n && $chars[$i]['c'] === ' ') $i++;
        $start = $i;
        $end = $i;   // ostatni znak pola + 1
        while ($i < $n && (str_contains(self::PLACEHOLDER, $chars[$i]['c']) || $chars[$i]['c'] === ' ')) {
            $i++;
            if (str_contains(self::PLACEHOLDER, $chars[$i - 1]['c'])) $end = $i;
        }
        if ($end === $start) throw new RuntimeException('Brak pola do wypełnienia po „' . $label . '”.');
        return [$start, $end];
    }

    /** Wpisuje wartość w miejsce pola; część kropek zostaje na końcu, żeby linia miała podobną długość. */
    private static function fillSlot(array &$p, string $label, string $value, int $from = 0): void
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if ($value === '') return;
        [$s, $e] = self::slot($p['chars'], $label, $from);
        $len = $e - $s;
        // Kropka jest ok. 2,5× węższa od pogrubionej litery; zapas 3 znaki na dodane odstępy — linia nie może się zawinąć
        $keep = max(0, $len - (int)ceil(mb_strlen($value) * 2.5) - 3);
        $base = $p['chars'][$s];
        $ins = [];
        if ($s > 0 && $p['chars'][$s - 1]['c'] !== ' ') $ins[] = self::ch(' ', $base['rPr'], $base['bold'], ['value' => true, 'orig' => '']);
        foreach (mb_str_split($value) as $c) $ins[] = self::ch($c, $base['rPr'], $base['bold'], ['value' => true, 'orig' => '']);
        $tail = $keep > 0 ? array_slice($p['chars'], $e - $keep, $keep) : [];
        if ($tail) array_unshift($tail, self::ch(' ', $base['rPr'], $base['bold'], ['value' => true, 'orig' => '']));
        $after = $p['chars'][$e]['c'] ?? '';
        if (!$tail && $after !== '' && $after !== ' ') $ins[] = self::ch(' ', $base['rPr'], $base['bold'], ['value' => true, 'orig' => '']);
        array_splice($p['chars'], $s, $len, array_merge($ins, $tail));
        $p['dirty'] = true;
    }

    /** PESEL: cyfry zastępują kolejne podkreślenia „_ _ _”, odstępy zostają. */
    private static function fillDigits(array &$p, string $label, string $digits): void
    {
        [$s, $e] = self::slot($p['chars'], $label);
        $d = str_split($digits);
        for ($i = $s; $i < $e && $d; $i++) {
            if ($p['chars'][$i]['c'] !== '_') continue;
            $p['chars'][$i]['c'] = array_shift($d);
            $p['chars'][$i]['value'] = true;
        }
        $p['dirty'] = true;
    }

    /** Zaznacza kratkę □ znajdującą się po $anchor (□ → ☒). */
    private static function check(array &$p, string $anchor = ''): void
    {
        $i = self::pos($p['chars'], '□', $anchor === '' ? 0 : self::pos($p['chars'], $anchor));
        $p['chars'][$i]['c'] = '☒';
        $p['chars'][$i]['check'] = true;
        $p['dirty'] = true;
    }

    /** Skreśla fragment $needle (n-te wystąpienie od $from). */
    private static function strike(array &$p, string $needle, int $from = 0): void
    {
        $i = self::pos($p['chars'], $needle, $from);
        for ($k = $i; $k < $i + mb_strlen($needle); $k++) $p['chars'][$k]['strike'] = true;
        $p['dirty'] = true;
    }

    /** Odpowiedzi kreatora → operacje na akapitach wzoru. */
    private static function applyAnswers(array &$P, array $a, array $umowa): void
    {
        $v = fn(string $k) => trim((string)($a[$k] ?? ''));
        $f = static function (string $k) use ($a): bool { return !empty($a[$k]) && $a[$k] !== '0'; };

        $i = self::find($P, 'DO UMOWY O');
        self::strike($P[$i], 'DZIEŁO/');
        self::fillSlot($P[$i], ' NR', (string)($umowa['numer'] ?? ''));
        self::fillSlot($P[$i], ' Z DNIA', (string)($umowa['data'] ?? ''));

        self::fillSlot($P[self::find($P, 'Nazwisko i imiona')], 'Nazwisko i imiona', $v('nazwisko_imiona'));
        $i = self::find($P, 'data  urodzenia');
        $dob = $v('data_urodzenia');
        self::fillSlot($P[$i], 'data  urodzenia', $dob !== '' ? date('d.m.Y', (int)strtotime($dob)) : '');
        self::fillSlot($P[$i], 'obywatelstwo', $v('obywatelstwo'));

        $i = self::find($P, 'PESEL');
        if ($f('brak_pesel')) self::fillSlot($P[$i], 'PESEL', 'nie posiadam – ' . $v('dokument'));
        elseif ($v('pesel') !== '') self::fillDigits($P[$i], 'PESEL', preg_replace('/\D/', '', $v('pesel')));
        self::fillSlot($P[self::find($P, 'Kod zawodu')], 'Kod zawodu', $v('kod_zawodu'));

        $i = self::find($P, 'Adres zamieszkania');
        self::fillSlot($P[$i], 'miejscowość', $v('adr_miejscowosc'));
        self::fillSlot($P[$i], 'kod', $v('adr_kod'), self::pos($P[$i]['chars'], 'miejscowość') + 11);
        $i = self::find($P, 'ulica');
        self::fillSlot($P[$i], 'ulica', $v('adr_ulica'));
        self::fillSlot($P[$i], 'nr domu', $v('adr_nr_domu'));
        self::fillSlot($P[$i], 'nr mieszkania', $v('adr_nr_mieszkania'));
        $i = self::find($P, 'województwo');
        self::fillSlot($P[$i], 'województwo', $v('adr_wojewodztwo'));
        self::fillSlot($P[$i], 'kraj', $v('adr_kraj'));
        $i = self::find($P, 'powiat');
        self::fillSlot($P[$i], 'powiat', $v('adr_powiat'));
        self::fillSlot($P[$i], 'gmina', $v('adr_gmina'));
        self::fillSlot($P[self::find($P, 'Urząd Skarbowy')], 'Urząd Skarbowy w', $v('urzad_skarbowy'));

        // 1. Jestem / nie jestem zatrudniona/ny…
        $i = self::find($P, '1. □');
        $zatr = $v('zatrudniony');
        if ($zatr === 'tak' || $zatr === 'nie') {
            self::check($P[$i]);
            self::strike($P[$i], $zatr === 'tak' ? 'nie jestem' : 'Jestem');
            if ($zatr === 'tak') {
                $w = $v('wynagrodzenie_min');
                if ($w === 'co_najmniej') self::check($P[self::find($P, 'a) □')]);
                if ($w === 'mniej')       self::check($P[self::find($P, 'b) □')]);
            }
        }
        $i2 = self::find($P, '2. □');
        if ($f('inny_tytul')) {
            self::check($P[$i2]);
            self::fillSlot($P[$i2], 'KRUS)', $v('inny_tytul_opis'));
        }
        $i3 = self::find($P, '3. □');
        if ($f('emeryt')) self::check($P[$i3]);
        $i4 = self::find($P, '4. □');
        if ($f('student')) self::check($P[$i4]);
        $i5 = self::find($P, '5. □');
        if ($f('bez_tytulu')) self::check($P[$i5]);

        $iE = self::find($P, '□ Chcę podlegać');
        if ($f('dobr_emerytalne')) self::check($P[$iE]);
        $iC = self::find($P, '□ Chcę być objęta');
        if ($f('dobr_chorobowe')) self::check($P[$iC]);

        // Formy gramatyczne: skreślenie niewłaściwej końcówki (k — forma żeńska, m — męska)
        $plec = $v('plec');
        if ($plec === 'k' || $plec === 'm') {
            $forms = [   // [akapit, słowo z wariantami, skreślenie dla k, skreślenie dla m]
                [$i,  'zatrudniona/ny', '/ny', 'a/'],
                [$i2, 'ubezpieczona/ny', '/ny', 'a/'],
                [$i4, 'ukończyłam/łem', '/łem', 'łam/'],
                [$i5, 'zarejestrowana/ny', '/ny', 'a/'],
                [$i5, 'objęta/ty', '/ty', 'ta/'],
                [$iC, 'objęta/y', '/y', 'a/'],
            ];
            foreach ($forms as [$pi, $word, $k, $m]) {
                $at = self::pos($P[$pi]['chars'], $word);
                self::strike($P[$pi], $plec === 'k' ? $k : $m, $at);
            }
        }
    }

    /** Grupuje znaki w przebiegi o jednakowych właściwościach. */
    private static function group(array $chars): array
    {
        $out = [];
        foreach ($chars as $c) {
            $key = spl_object_id($c['rPr'] ?? new stdClass()) . '|' . (int)$c['value'] . (int)$c['strike'] . (int)$c['check'];
            if ($c['rPr'] === null) $key = 'null|' . (int)$c['value'] . (int)$c['strike'] . (int)$c['check'];
            $last = array_key_last($out);
            if ($last !== null && $out[$last]['key'] === $key) { $out[$last]['text'] .= $c['c']; continue; }
            $out[] = ['key' => $key, 'text' => $c['c'], 'rPr' => $c['rPr'], 'bold' => $c['bold'],
                      'value' => $c['value'], 'strike' => $c['strike'], 'check' => $c['check']];
        }
        return $out;
    }

    /** Przepisuje przebiegi zmienionego akapitu (właściwości akapitu, zakładki i oznaczenia pozostają). */
    private static function rewrite(DOMDocument $dom, DOMXPath $xp, array $p): void
    {
        $pNode = $p['node'];
        foreach (iterator_to_array($xp->query('w:r | w:proofErr', $pNode)) as $old) $pNode->removeChild($old);
        foreach (self::group($p['chars']) as $g) {
            $r = $dom->createElementNS(self::W, 'w:r');
            $rPr = $g['rPr'] ? $g['rPr']->cloneNode(true) : $dom->createElementNS(self::W, 'w:rPr');
            if ($g['value'] && !$g['bold']) { self::rPrAdd($dom, $rPr, 'b'); self::rPrAdd($dom, $rPr, 'bCs'); }
            if ($g['strike']) self::rPrAdd($dom, $rPr, 'strike');
            if ($g['check']) {
                $f = self::rPrAdd($dom, $rPr, 'rFonts');
                foreach (['ascii' => 'Segoe UI Symbol', 'hAnsi' => 'Segoe UI Symbol', 'eastAsia' => 'MS Gothic', 'cs' => 'Segoe UI Symbol'] as $k => $val) {
                    $f->setAttributeNS(self::W, 'w:' . $k, $val);
                }
            }
            if ($rPr->hasChildNodes()) $r->appendChild($rPr);
            foreach (preg_split('/(\t)/', $g['text'], -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $part) {
                if ($part === "\t") { $r->appendChild($dom->createElementNS(self::W, 'w:tab')); continue; }
                $t = $dom->createElementNS(self::W, 'w:t');
                $t->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
                $t->appendChild($dom->createTextNode($part));
                $r->appendChild($t);
            }
            $pNode->appendChild($r);
        }
    }

    /** Dodaje (lub zwraca istniejący) element właściwości przebiegu w kolejności wymaganej przez schemat. */
    private static function rPrAdd(DOMDocument $dom, DOMElement $rPr, string $name): DOMElement
    {
        foreach ($rPr->childNodes as $c) if ($c instanceof DOMElement && $c->localName === $name) return $c;
        $el = $dom->createElementNS(self::W, 'w:' . $name);
        $rank = array_search($name, self::RPR_ORDER, true);
        foreach ($rPr->childNodes as $c) {
            if (!$c instanceof DOMElement) continue;
            $cr = array_search($c->localName, self::RPR_ORDER, true);
            if ($cr !== false && $cr > $rank) { $rPr->insertBefore($el, $c); return $el; }
        }
        $rPr->appendChild($el);
        return $el;
    }

    /**
     * Strażnik niezmienności wzoru: każdy akapit po wypełnieniu, bez wpisanych odpowiedzi, kropek, podkreśleń i odstępów,
     * z kratkami ☒ czytanymi jak □, musi być identyczny z oryginałem. Liczba akapitów też.
     */
    public static function assertTemplateIntact(array $orig, array $paras): void
    {
        if (count($orig) !== count($paras)) throw new RuntimeException('Wypełnianie zmieniło liczbę akapitów wzoru — wydruk zablokowany.');
        $norm = static function (array $chars, bool $filled): string {
            $s = '';
            foreach ($chars as $c) {
                if ($filled && $c['value']) continue;
                $s .= $filled ? $c['orig'] : $c['c'];
            }
            return preg_replace('/[\s.…_]+/u', '', $s) ?? '';
        };
        foreach ($paras as $i => $p) {
            if ($norm($orig[$i], false) !== $norm($p['chars'], true)) {
                throw new RuntimeException('Wypełnianie zmieniło treść wzoru (akapit ' . ($i + 1) . ') — wydruk zablokowany.');
            }
        }
    }
}
