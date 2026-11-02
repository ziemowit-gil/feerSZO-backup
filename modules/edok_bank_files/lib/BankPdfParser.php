<?php
/**
 * modules/edok_bank_files/lib/BankPdfParser.php — odczyt tekstu i pól z PDF-ów z banku (PKO BP / iPKO biznes).
 *
 * Rozpoznajemy dwa rodzaje (układ sprawdzony na prawdziwych plikach z 2026-11):
 *   • „wyciag”        — WYCIĄG za okres …, Nr: 3/2026, Nr IBAN, lista operacji: data, identyfikator operacji (np. 6779FE04000162374), typ, kwota, saldo;
 *   • „potwierdzenie” — iPKO „OPER-DETAILS-TRANSFER…”: Z rachunku, Na rachunek, Tytuł, Kwota, Data realizacji, Identyfikator transakcji.
 * Inne dokumenty (kind = 'inny') zapisujemy, ale nie dopasowujemy automatycznie. Tekst wyciągamy biblioteką smalot/pdfparser; PDF-y zaszyfrowane
 * (iPKO daje potwierdzenia z AES i pustym hasłem) odszyfrowuje PdfDecryptor. Skan bez warstwy tekstowej → pusty tekst → kind 'inny'.
 */
require_once __DIR__ . '/PdfDecryptor.php';

final class BankPdfParser
{
    /** @throws \RuntimeException gdy PDF nie da się odczytać (uszkodzony / szyfrowanie nieobsługiwane) */
    public static function text(string $pdf): string
    {
        if (!str_starts_with(ltrim($pdf), '%PDF')) throw new \RuntimeException('To nie jest plik PDF.');
        if (PdfDecryptor::isEncrypted($pdf)) {
            $dec = PdfDecryptor::decrypt($pdf);
            if ($dec === null) throw new \RuntimeException('PDF jest zaszyfrowany w sposób, którego nie obsługujemy.');
            $pdf = $dec;
        }
        require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
        try {
            $t = (new \Smalot\PdfParser\Parser())->parseContent($pdf)->getText();
        } catch (\Throwable $e) {
            throw new \RuntimeException('Nie udało się odczytać PDF: ' . $e->getMessage());
        }
        return self::fixPolish((string)$t);
    }

    /**
     * Wyciągi PKO mają czcionki w CP1250 bez mapy ToUnicode — biblioteka czyta je jak CP1252 („P£ATNOŒÆ” zamiast „PŁATNOŚĆ”).
     * Gdy w tekście są znaki typowe dla takiego odczytu, a nie ma żadnej polskiej litery, odkodowujemy jeszcze raz.
     */
    private static function fixPolish(string $t): string
    {
        if (preg_match('/[ąćęłńśźżĄĆĘŁŃŚŹŻ]/u', $t)) return $t;   // litery poza CP1252 = tekst już poprawny (ó/Ó są w obu stronach kodowych)
        if (!preg_match('/[¹³êæñœ¿Ÿ£¥ÊÆÑŒ¯]/u', $t)) return $t;
        if (!function_exists('iconv')) return $t;
        $b = @iconv('UTF-8', 'CP1252//IGNORE', $t);                       // z powrotem do bajtów, które były w PDF-ie
        $f = $b !== false ? @iconv('CP1250', 'UTF-8//IGNORE', $b) : false;   // i poprawny odczyt jako CP1250
        return ($f !== false && $f !== '') ? $f : $t;
    }

    /** „-1 229,46” → -1229.46 */
    public static function amount(string $s): float
    {
        return (float)str_replace([' ', "\xC2\xA0", ','], ['', '', '.'], trim($s));
    }

    /** 31.10.2026 albo 2026-09-21 → 2026-10-31 (null gdy to nie data). */
    public static function isoDate(string $s): ?string
    {
        $s = trim($s);
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $s, $m)) return "{$m[3]}-{$m[2]}-{$m[1]}";
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
        return null;
    }

    /** Same cyfry numeru rachunku (bez „PL”). */
    public static function nrb(string $s): string
    {
        $d = preg_replace('/\D/', '', $s);
        return strlen($d) >= 26 ? substr($d, -26) : $d;
    }

    /** @return array<string,mixed> ['kind' => 'wyciag'|'potwierdzenie'|'inny', …pola…] */
    public static function parse(string $text): array
    {
        $text = str_replace("\xC2\xA0", ' ', $text);
        if (preg_match('/WYCI\S{1,2}G\s+za\s+okres\s+(\d{2}\.\d{2}\.\d{4})\s*-\s*(\d{2}\.\d{2}\.\d{4})/iu', $text)) return self::parseStatement($text);
        if (preg_match('/Identyfikator\s+transakcji/u', $text) || (preg_match('/Data\s+realizacji/u', $text) && preg_match('/Na\s+rachunek/u', $text) && preg_match('/\bKwota\b/u', $text))) {
            return self::parseConfirmation($text);
        }
        return ['kind' => 'inny'];
    }

    private static function parseStatement(string $text): array
    {
        preg_match('/WYCI\S{1,2}G\s+za\s+okres\s+(\d{2}\.\d{2}\.\d{4})\s*-\s*(\d{2}\.\d{2}\.\d{4})/iu', $text, $p);
        $no = preg_match('/Nr:\s*(\d+\s*\/\s*\d{4})/u', $text, $m) ? preg_replace('/\s+/', '', $m[1]) : '';
        $acc = '';
        if (preg_match('/Nr\s+IBAN:\s*PL\s*((?:\d\s*){26})/u', $text, $m) || preg_match('/Nr\s+rachunku[^:\n]*:\s*((?:\d\s*){26})/u', $text, $m)) $acc = self::nrb($m[1]);

        $ops = []; $cur = null;
        foreach (preg_split('/\R/u', $text) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (preg_match('/^(\d{2}\.\d{2}\.\d{4})\s+((?=[0-9A-Z]*\d)[0-9A-Z]{14,20})\s+(.+?)\s+(-?\d[\d ]*,\d{2})\s+(-?\d[\d ]*,\d{2})$/u', $line, $m)) {
                if ($cur) $ops[] = $cur;
                $cur = ['id' => $m[2], 'date' => self::isoDate($m[1]), 'typ' => trim($m[3]), 'kwota' => self::amount($m[4]), 'saldo' => self::amount($m[5]), 'opis' => ''];
                continue;
            }
            if ($cur && (preg_match('/^(Saldo do przeniesienia|Saldo ko\S{1,2}cowe|strona\s+\d+\/\d+|Niniejszy dokument)/iu', $line))) { $ops[] = $cur; $cur = null; continue; }
            if ($cur) $cur['opis'] .= ($cur['opis'] === '' ? '' : ' ') . preg_replace('/^\d{2}\.\d{2}\.\d{4}\s*/', '', $line);   // 2. wiersz operacji: data waluty + opis
        }
        if ($cur) $ops[] = $cur;
        foreach ($ops as &$o) $o['opis'] = trim(preg_replace('/\s+/', ' ', $o['opis']));
        unset($o);

        return ['kind' => 'wyciag', 'account' => $acc, 'statement_no' => $no,
                'period_from' => self::isoDate($p[1]), 'period_to' => self::isoDate($p[2]), 'ops' => $ops];
    }

    private static function parseConfirmation(string $text): array
    {
        $val = static function (string $label) use ($text): string {
            return preg_match('/^\s*' . $label . '[ \t]+(\S[^\r\n]*)$/mu', $text, $m) ? trim($m[1]) : '';
        };
        $kwota = null; $waluta = 'PLN';
        if (preg_match('/^\s*Kwota[ \t]+(-?\d[\d ]*,\d{2})\s*([A-Z]{3})?/mu', $text, $m)) { $kwota = self::amount($m[1]); $waluta = $m[2] ?? 'PLN'; }
        $tytul = '';
        if (preg_match('/Tytu\S{1,2}[ \t]+(.+?)\s*\R\s*Kwota/su', $text, $m)) $tytul = trim(preg_replace('/\s+/', ' ', $m[1]));
        return [
            'kind' => 'potwierdzenie',
            'account_from' => self::nrb($val('Z rachunku')),
            'account_to' => self::nrb($val('Na rachunek')),
            'nazwa' => $val('Nazwa i adres Kontrahenta'),
            'nip' => preg_replace('/\D/', '', $val('NIP Kontrahenta')),
            'tytul' => $tytul,
            'kwota' => $kwota, 'waluta' => $waluta,
            'data' => self::isoDate($val('Data realizacji')),
            'id_transakcji' => preg_replace('/\D/', '', $val('Identyfikator transakcji')),
            'status' => $val('Status'),
            'typ' => $val('Typ'),
        ];
    }
}
