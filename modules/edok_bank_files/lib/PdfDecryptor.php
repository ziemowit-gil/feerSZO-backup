<?php
/**
 * modules/edok_bank_files/lib/PdfDecryptor.php — zdejmuje szyfrowanie „Standard” z PDF-ów bankowych (iPKO biznes szyfruje potwierdzenia AES-128
 * z PUSTYM hasłem użytkownika i zezwoleniem na kopiowanie), żeby biblioteka smalot/pdfparser (nie obsługuje szyfrowania) mogła odczytać tekst.
 *
 * Obsługa: Standard Security Handler rewizje 2–4 (RC4 40/128 bit, AES-128 „AESV2”), hasło użytkownika puste. NIE obsługujemy AES-256 (R5/R6)
 * ani PDF-ów chronionych hasłem — wtedy decrypt() zwraca null, a wywołujący pokazuje „nie da się odczytać, dopasuj ręcznie”.
 * Wynik to ten sam PDF z odszyfrowanymi strumieniami i bez /Encrypt w trailerze (tylko do odczytu tekstu — nie do serwowania użytkownikowi).
 * Plik oryginalny zostaje w niezmienionej postaci.
 */
final class PdfDecryptor
{
    private const PAD = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";

    public static function isEncrypted(string $pdf): bool
    {
        return (bool)preg_match('~/Encrypt\s+\d+\s+\d+\s+R~', $pdf);
    }

    /** @return string|null odszyfrowany PDF; null = nieobsługiwane szyfrowanie / nie udało się. */
    public static function decrypt(string $pdf): ?string
    {
        if (!preg_match('~/Encrypt\s+(\d+)\s+\d+\s+R~', $pdf, $m)) return $pdf;
        $encObj = self::objectBody($pdf, (int)$m[1]);
        if ($encObj === null) return null;
        $dict = $encObj;
        if (!preg_match('~/Filter\s*/Standard~', $dict)) return null;
        $v = (int)(preg_match('~/V\s+(\d+)~', $dict, $x) ? $x[1] : 0);
        $r = (int)(preg_match('~/R\s+(\d+)~', $dict, $x) ? $x[1] : 0);
        if ($r < 2 || $r > 4 || $v < 1 || $v > 4) return null;
        $bits = (int)(preg_match('~/Length\s+(\d+)\s*(?:/|>>)~', $dict, $x) ? $x[1] : 40);
        if ($v === 4) $bits = 128;
        $n = $r === 2 ? 5 : intdiv($bits, 8);
        if (!preg_match('~/P\s+(-?\d+)~', $dict, $x)) return null;
        $p = (int)$x[1];
        $o = self::stringAfter($dict, '/O');
        if ($o === null || strlen($o) < 32) return null;
        $o = substr($o, 0, 32);
        if (!preg_match('~/ID\s*\[\s*<([0-9A-Fa-f]*)>~', $pdf, $x)) return null;
        $id0 = hex2bin($x[1]) ?: '';
        $encryptMeta = !preg_match('~/EncryptMetadata\s+false~', $dict);

        // Algorytm 2 (klucz z pustego hasła użytkownika)
        $h = md5(self::PAD . $o . pack('V', $p & 0xFFFFFFFF) . $id0 . (($r >= 4 && !$encryptMeta) ? "\xFF\xFF\xFF\xFF" : ''), true);
        if ($r >= 3) for ($i = 0; $i < 50; $i++) $h = md5(substr($h, 0, $n), true);
        $key = substr($h, 0, $n);

        $aes = false;
        if ($v === 4) {
            // metoda szyfrowania strumieni z /CF → /StmF
            $stmf = preg_match('~/StmF\s*/(\w+)~', $dict, $x) ? $x[1] : 'Identity';
            if ($stmf === 'Identity') return $pdf;
            if (!preg_match('~/' . preg_quote($stmf, '~') . '\s*<<(.*?)>>~s', $dict, $x)) return null;
            if (preg_match('~/CFM\s*/AESV2~', $x[1])) $aes = true;
            elseif (!preg_match('~/CFM\s*/V2~', $x[1])) return null;
            if ($aes && !function_exists('openssl_decrypt')) return null;
        }

        if (preg_match('~/Type\s*/(ObjStm|XRef)~', $pdf)) return null;   // strumienie obiektów/xref — nieobsługiwane (przebudowa tabeli xref poniżej jest klasyczna)
        if (!preg_match_all('~(\d+)\s+(\d+)\s+obj(.*?)endobj~s', $pdf, $all, PREG_SET_ORDER)) return null;
        if (!preg_match_all('~/Root\s+(\d+)\s+(\d+)\s+R~', $pdf, $rm)) return null;
        $root = (int)end($rm[1]);

        // Odszyfrowane obiekty składamy w NOWY plik z nową tabelą xref — zmiana długości strumieni unieważnia stare offsety, a smalot je czyta.
        $out = "%PDF-1.7\n"; $offsets = []; $maxNum = 0;
        foreach ($all as $mm) {
            $num = (int)$mm[1]; $gen = (int)$mm[2]; $body = $mm[3];
            if ($num === (int)$m[1]) continue;   // sam słownik /Encrypt
            if (preg_match('~stream\r?\n~', $body, $sm, PREG_OFFSET_CAPTURE) && ($end = strrpos($body, 'endstream')) !== false) {
                $start = $sm[0][1] + strlen($sm[0][0]);
                $data = preg_replace('~\r?\n$~', '', substr($body, $start, $end - $start));   // EOL przed endstream nie należy do strumienia
                $objKey = substr(md5($key . substr(pack('V', $num), 0, 3) . substr(pack('V', $gen), 0, 2) . ($aes ? 'sAlT' : ''), true), 0, min(strlen($key) + 5, 16));
                if ($aes) {
                    if (strlen($data) < 32) return null;
                    $dec = @openssl_decrypt(substr($data, 16), 'aes-128-cbc', $objKey, OPENSSL_RAW_DATA, substr($data, 0, 16));
                    if ($dec === false) return null;
                } else {
                    $dec = self::rc4($objKey, $data);
                }
                $head = preg_replace('~/Length\s+\d+(?!\s+\d+\s+R)~', '/Length ' . strlen($dec), substr($body, 0, $sm[0][1]), 1);
                $body = $head . "stream\n" . $dec . "\nendstream" . substr($body, $end + 9);
            }
            $offsets[$num] = strlen($out);
            $maxNum = max($maxNum, $num);
            $out .= $num . ' ' . $gen . ' obj' . $body . "endobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 " . ($maxNum + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $maxNum; $i++) {
            $out .= isset($offsets[$i]) ? sprintf("%010d 00000 n \n", $offsets[$i]) : "0000000000 65535 f \n";
        }
        return $out . "trailer\n<< /Size " . ($maxNum + 1) . " /Root {$root} 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    /** Treść obiektu „N 0 obj … endobj” (bez strumienia) albo null. */
    private static function objectBody(string $pdf, int $num): ?string
    {
        return preg_match('~(?:^|[\r\n\s])' . $num . '\s+\d+\s+obj(.*?)endobj~s', $pdf, $x) ? $x[1] : null;
    }

    /** Wartość klucza-łańcucha (hex <…> albo literał (…)) po nazwie w słowniku. */
    private static function stringAfter(string $dict, string $name): ?string
    {
        if (preg_match('~' . preg_quote($name, '~') . '\s*<([0-9A-Fa-f\s]*)>~', $dict, $x)) return hex2bin(preg_replace('/\s+/', '', $x[1])) ?: null;
        $pos = strpos($dict, $name . '(');
        if ($pos === false && preg_match('~' . preg_quote($name, '~') . '\s+\(~', $dict, $x, PREG_OFFSET_CAPTURE)) $pos = $x[0][1];
        if ($pos === false) return null;
        $i = strpos($dict, '(', $pos) + 1; $out = ''; $depth = 1; $len = strlen($dict);
        for (; $i < $len && $depth > 0; $i++) {
            $c = $dict[$i];
            if ($c === '\\' && $i + 1 < $len) {
                $nx = $dict[++$i];
                $map = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0C", '(' => '(', ')' => ')', '\\' => '\\'];
                if (isset($map[$nx])) { $out .= $map[$nx]; continue; }
                if (ctype_digit($nx)) { $oct = $nx; while (strlen($oct) < 3 && $i + 1 < $len && ctype_digit($dict[$i + 1])) $oct .= $dict[++$i]; $out .= chr(octdec($oct) & 255); continue; }
                continue;   // „\” + EOL — kontynuacja
            }
            if ($c === '(') $depth++;
            elseif ($c === ')') { if (--$depth === 0) break; }
            $out .= $c;
        }
        return $out;
    }

    private static function rc4(string $key, string $data): string
    {
        $s = range(0, 255); $j = 0; $kl = strlen($key);
        for ($i = 0; $i < 256; $i++) { $j = ($j + $s[$i] + ord($key[$i % $kl])) & 255; [$s[$i], $s[$j]] = [$s[$j], $s[$i]]; }
        $i = $j = 0; $out = '';
        for ($k = 0, $n = strlen($data); $k < $n; $k++) {
            $i = ($i + 1) & 255; $j = ($j + $s[$i]) & 255; [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
            $out .= chr(ord($data[$k]) ^ $s[($s[$i] + $s[$j]) & 255]);
        }
        return $out;
    }
}
