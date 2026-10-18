<?php
/**
 * modules/ti_room_srs/lib/RoomFormDocx.php — wypełnia wzór „FORMULARZ REZERWACJI SALI” (Centrum Obywatelskie, Reymonta 20).
 *
 * Wzór (lib/templates/formularzRezerwacjiSali.docx) jest oryginałem operatora — układ dokumentu zostaje nietknięty:
 * dopisujemy tylko tekst w puste komórki wartości (z formatowaniem akapitu komórki) i zamieniamy pola ⬜ na ☒
 * przy wybranych opcjach. Nagłówek, stopka, czcionki i klauzula RODO idą bez zmian.
 *
 * Wiersze tabeli rozpoznajemy po etykiecie w pierwszej komórce; „Telefon”/„E-mail” występują dwa razy —
 * przed wierszem „Dane osoby odpowiedzialnej…” dotyczą podmiotu, po nim osoby odpowiedzialnej.
 */
final class RoomFormDocx
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const BOX_EMPTY = "\u{2B1C}";
    private const BOX_CHECKED = "\u{2612}";

    public static function templatePath(): string
    {
        return __DIR__ . '/templates/formularzRezerwacjiSali.docx';
    }

    /**
     * $d: room (1W040|1W014|1W038), terms (string[] — linie), orgName, orgAddress, orgPhone, orgEmail,
     * purpose (coworking|wydarzenie), topic, participants, minors (tak|nie), statement (tak|nie|nie_dotyczy),
     * firstName, lastName, address, pesel, phone, email, equipment. Puste wartości zostawiają komórkę pustą.
     * Zwraca zawartość pliku DOCX.
     */
    public static function render(array $d): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'trs');
        copy(self::templatePath(), $tmp);
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) throw new RuntimeException('Nie można otworzyć wzoru formularza.');
        $xml = $zip->getFromName('word/document.xml');
        $zip->addFromString('word/document.xml', self::fillXml($xml, $d));
        $zip->close();
        $out = (string)file_get_contents($tmp);
        @unlink($tmp);
        return $out;
    }

    public static function fillXml(string $xml, array $d): string
    {
        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = true;
        $doc->loadXML($xml);
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('w', self::W);

        $person = false;
        foreach ($xp->query('//w:body/w:tbl//w:tr') as $tr) {
            $cells = $xp->query('./w:tc', $tr);
            $label = self::norm(self::text($xp, $cells->item(0)));
            if (str_starts_with($label, 'dane osoby odpowiedzialnej')) { $person = true; continue; }
            if ($cells->length < 2) continue;
            $cell = $cells->item($cells->length - 1);

            if ($label === 'sala') {
                self::check($xp, $cell, fn($t) => ($d['room'] ?? '') !== '' && str_contains($t, mb_strtolower((string)$d['room'])));
            } elseif (str_starts_with($label, 'termin rezerwacji')) {
                self::setText($doc, $xp, $cell, (array)($d['terms'] ?? []));
            } elseif (str_starts_with($label, 'nazwa podmiotu')) {
                self::setText($doc, $xp, $cell, [$d['orgName'] ?? '']);
            } elseif (str_starts_with($label, 'adres siedziby')) {
                self::setText($doc, $xp, $cell, [$d['orgAddress'] ?? '']);
            } elseif ($label === 'telefon') {
                self::setText($doc, $xp, $cell, [$person ? ($d['phone'] ?? '') : ($d['orgPhone'] ?? '')]);
            } elseif ($label === 'e-mail') {
                self::setText($doc, $xp, $cell, [$person ? ($d['email'] ?? '') : ($d['orgEmail'] ?? '')]);
            } elseif ($label === 'cel rezerwacji') {
                $want = ($d['purpose'] ?? '') === 'coworking' ? 'coworking' : (($d['purpose'] ?? '') === 'wydarzenie' ? 'organizacja wydarzenia' : '');
                self::check($xp, $cell, fn($t) => $want !== '' && $t === $want);
            } elseif (str_starts_with($label, 'temat spotkania')) {
                self::setText($doc, $xp, $cell, preg_split('/\R/u', (string)($d['topic'] ?? '')));
            } elseif (str_starts_with($label, 'liczba uczestników')) {
                self::setText($doc, $xp, $cell, [(string)($d['participants'] ?? '')]);
            } elseif (str_starts_with($label, 'planowane spotkanie')) {
                self::check($xp, $cell, fn($t) => $t === ($d['minors'] ?? ''));
            } elseif (str_starts_with($label, 'wynajmujący salę oświadcza')) {
                $want = str_replace('_', ' ', (string)($d['statement'] ?? ''));
                self::check($xp, $cell, fn($t) => $want !== '' && $t === $want);
            } elseif ($label === 'imię') {
                self::setText($doc, $xp, $cell, [$d['firstName'] ?? '']);
            } elseif ($label === 'nazwisko') {
                self::setText($doc, $xp, $cell, [$d['lastName'] ?? '']);
            } elseif ($label === 'adres zamieszkania') {
                self::setText($doc, $xp, $cell, [$d['address'] ?? '']);
            } elseif ($label === 'pesel') {
                self::setText($doc, $xp, $cell, [$d['pesel'] ?? '']);
            } elseif (str_starts_with($label, 'oczekiwana pomoc sprzętowa')) {
                self::setText($doc, $xp, $cell, preg_split('/\R/u', (string)($d['equipment'] ?? '')));
            }
        }
        return $doc->saveXML();
    }

    private static function text(DOMXPath $xp, ?DOMNode $n): string
    {
        if (!$n) return '';
        $s = '';
        foreach ($xp->query('.//w:t', $n) as $t) $s .= $t->textContent;
        return $s;
    }

    /** Tekst bez pól wyboru i selektorów wariantu, małymi literami, z pojedynczymi spacjami. */
    private static function norm(string $s): string
    {
        $s = str_replace([self::BOX_EMPTY, self::BOX_CHECKED, "\u{FE0E}", "\u{FE0F}", "\u{00A0}"], ['', '', '', '', ' '], $s);
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($s)));
    }

    /** Zaznacza ☒ w każdym akapicie komórki, którego tekst spełnia warunek. */
    private static function check(DOMXPath $xp, DOMNode $cell, callable $match): void
    {
        foreach ($xp->query('.//w:p', $cell) as $p) {
            if (!$match(self::norm(self::text($xp, $p)))) continue;
            foreach ($xp->query('.//w:t', $p) as $t) {
                if (str_contains($t->textContent, self::BOX_EMPTY)) {
                    $t->nodeValue = str_replace(self::BOX_EMPTY, self::BOX_CHECKED, $t->textContent);
                    break;
                }
            }
        }
    }

    /** Wpisuje linie w pierwszy akapit komórki (formatowanie z w:pPr/w:rPr akapitu), linie rozdziela w:br. */
    private static function setText(DOMDocument $doc, DOMXPath $xp, DOMNode $cell, array $lines): void
    {
        $lines = array_values(array_filter(array_map(fn($l) => trim((string)$l), $lines), fn($l) => $l !== ''));
        if (!$lines) return;
        $p = $xp->query('./w:p', $cell)->item(0);
        if (!$p) return;
        $r = $doc->createElementNS(self::W, 'w:r');
        $rPr = $xp->query('./w:pPr/w:rPr', $p)->item(0);
        if ($rPr) $r->appendChild($rPr->cloneNode(true));
        foreach ($lines as $i => $line) {
            if ($i > 0) $r->appendChild($doc->createElementNS(self::W, 'w:br'));
            $t = $doc->createElementNS(self::W, 'w:t');
            $t->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
            $t->appendChild($doc->createTextNode($line));
            $r->appendChild($t);
        }
        $p->appendChild($r);
    }
}
