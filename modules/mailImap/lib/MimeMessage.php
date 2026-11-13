<?php
declare(strict_types=1);

/**
 * modules/mailImap/lib/MimeMessage.php — rozbiór wiadomości RFC 822 / MIME pobranej przez ImapReader.
 *
 * Wynik: nadawca (adres, nazwa), temat, data, Message-ID, nagłówki pętli (Auto-Submitted, Precedence), treść tekstowa
 * (text/plain, a gdy jej nie ma — HTML zamieniony na tekst), treść HTML oraz załączniki (nazwa, typ, bajty).
 * Obsługuje multipart (zagnieżdżony), base64 / quoted-printable, kodowanie nagłówków (RFC 2047) i nazw plików (RFC 2231),
 * dowolne kodowanie znaków przez mbstring/iconv. Wiadomość dołączona (message/rfc822) zostaje załącznikiem .eml.
 */
final class MimeMessage
{
    public string $fromEmail = '';
    public string $fromName = '';
    public string $subject = '';
    public string $messageId = '';
    public ?int $date = null;
    public string $text = '';
    public string $html = '';
    /** @var list<array{name:string,mime:string,bytes:string}> */
    public array $attachments = [];
    /** @var array<string,string> nagłówki najwyższego poziomu, klucze małymi literami */
    public array $headers = [];

    private const MAX_DEPTH = 8;

    public static function parse(string $raw): self
    {
        $m = new self();
        [$hdr, $body] = self::split($raw);
        $m->headers = self::headers($hdr);
        $m->subject = self::decodeHeader($m->headers['subject'] ?? '');
        [$m->fromEmail, $m->fromName] = self::address($m->headers['from'] ?? '');
        $m->messageId = trim((string)($m->headers['message-id'] ?? ''));
        $d = trim((string)($m->headers['date'] ?? ''));
        // Bez dnia tygodnia („Thu,”) i komentarza („(CET)”) — strtotime traktuje nazwę dnia jak przesunięcie daty
        $d = trim((string)preg_replace(['/^[A-Za-z]{3},\s*/', '/\s*\([^)]*\)\s*$/'], '', $d));
        $m->date = $d !== '' ? (strtotime($d) ?: null) : null;
        $m->walk($m->headers, $body, 0);
        if ($m->text === '' && $m->html !== '') $m->text = self::htmlToText($m->html);
        return $m;
    }

    /** Wiadomość automatyczna (autoresponder, lista, raport doręczenia)? */
    public function isAutomatic(): bool
    {
        $as = strtolower(trim($this->headers['auto-submitted'] ?? ''));
        if ($as !== '' && $as !== 'no') return true;
        $prec = strtolower(trim($this->headers['precedence'] ?? ''));
        if (in_array($prec, ['bulk', 'junk', 'list', 'auto_reply'], true)) return true;
        if (isset($this->headers['x-autoreply']) || isset($this->headers['x-autorespond'])) return true;
        $ct = strtolower($this->headers['content-type'] ?? '');
        return str_contains($ct, 'multipart/report');
    }

    // ── rozbiór ──────────────────────────────────────────────────────────────────────────────

    private function walk(array $h, string $body, int $depth): void
    {
        [$type, $params] = self::contentType($h['content-type'] ?? 'text/plain');
        if ($depth > self::MAX_DEPTH) return;
        if (str_starts_with($type, 'multipart/')) {
            $b = $params['boundary'] ?? '';
            if ($b === '') { $this->addText('text/plain', $body, $h, $params); return; }
            foreach (self::parts($body, $b) as $part) {
                [$ph, $pb] = self::split($part);
                $this->walk(self::headers($ph), $pb, $depth + 1);
            }
            return;
        }
        $disp = strtolower(trim(explode(';', (string)($h['content-disposition'] ?? ''))[0]));
        $name = self::filename($h);
        $data = self::decodeBody($body, (string)($h['content-transfer-encoding'] ?? ''));
        if ($type === 'message/rfc822') {
            $inner = self::parse($data);
            $this->attachments[] = ['name' => self::safeName(($inner->subject !== '' ? $inner->subject : 'wiadomosc') . '.eml'), 'mime' => 'message/rfc822', 'bytes' => $data];
            return;
        }
        $isText = in_array($type, ['text/plain', 'text/html'], true);
        if ($isText && $disp !== 'attachment' && $name === '') {
            $this->addText($type, $data, $h, $params, true);
            return;
        }
        if ($data === '') return;
        if ($name === '') $name = 'zalacznik' . (count($this->attachments) + 1) . self::extFor($type);
        $this->attachments[] = ['name' => self::safeName($name), 'mime' => $type ?: 'application/octet-stream', 'bytes' => $data];
    }

    private function addText(string $type, string $data, array $h, array $params, bool $decoded = false): void
    {
        if (!$decoded) $data = self::decodeBody($data, (string)($h['content-transfer-encoding'] ?? ''));
        $data = self::toUtf8($data, (string)($params['charset'] ?? ''));
        if ($type === 'text/html') {
            if ($this->html === '') $this->html = $data;
        } elseif ($this->text === '') {
            $this->text = str_replace(["\r\n", "\r"], "\n", $data);
        }
    }

    /** [nagłówki, treść] — rozdzielone pierwszą pustą linią. */
    private static function split(string $raw): array
    {
        $raw = ltrim($raw, "\r\n");
        if (preg_match('/\r?\n\r?\n/', $raw, $m, PREG_OFFSET_CAPTURE)) {
            return [substr($raw, 0, $m[0][1]), substr($raw, $m[0][1] + strlen($m[0][0]))];
        }
        return [$raw, ''];
    }

    /** Nagłówki (rozwinięte; przy powtórzeniu zostaje pierwszy). */
    private static function headers(string $hdr): array
    {
        $out = [];
        $hdr = preg_replace('/\r?\n[ \t]+/', ' ', $hdr);
        foreach (preg_split('/\r?\n/', $hdr) as $line) {
            $p = strpos($line, ':');
            if ($p === false || $p === 0) continue;
            $k = strtolower(trim(substr($line, 0, $p)));
            if (!isset($out[$k])) $out[$k] = trim(substr($line, $p + 1));
        }
        return $out;
    }

    /** Części multipart (bez preambuły i epilogu). */
    private static function parts(string $body, string $boundary): array
    {
        $q = preg_quote($boundary, '/');
        // Wszystko od zamykającej granicy (--boundary--) to epilog
        if (preg_match('/^--' . $q . '--[ \t]*\r?$/m', $body, $m, PREG_OFFSET_CAPTURE)) $body = substr($body, 0, $m[0][1]);
        $chunks = preg_split('/^--' . $q . '[ \t]*\r?\n/m', $body);
        if (!$chunks || count($chunks) < 2) return [];
        array_shift($chunks);   // preambuła
        // Znak nowej linii przed granicą należy do granicy (RFC 2046)
        return array_map(fn($c) => (string)preg_replace('/\r?\n$/', '', $c), $chunks);
    }

    /** [typ, parametry] z Content-Type. */
    private static function contentType(string $v): array
    {
        $params = self::params($v);
        $type = strtolower(trim(explode(';', $v)[0]));
        return [$type !== '' ? $type : 'text/plain', $params];
    }

    /** Parametry nagłówka (z RFC 2231: name*=UTF-8''…, name*0*=…). */
    private static function params(string $v): array
    {
        $out = []; $cont = [];
        if (!preg_match_all('/;\s*([A-Za-z0-9_.*-]+)\s*=\s*("(?:[^"\\\\]|\\\\.)*"|[^;]*)/', $v, $mm, PREG_SET_ORDER)) return $out;
        foreach ($mm as $m) {
            $k = strtolower($m[1]);
            $val = trim($m[2]);
            if (str_starts_with($val, '"')) $val = stripcslashes(substr($val, 1, -1));
            if (preg_match('/^(.+?)\*(\d+)(\*?)$/', $k, $c)) { $cont[$c[1]][(int)$c[2]] = [$val, $c[3] === '*']; continue; }
            if (str_ends_with($k, '*')) { $out[rtrim($k, '*')] = self::rfc2231($val); continue; }
            $out[$k] = $val;
        }
        foreach ($cont as $k => $pieces) {
            ksort($pieces);
            $joined = ''; $enc = false;
            foreach ($pieces as $i => [$val, $isEnc]) {
                if ($i === 0 && $isEnc) $enc = true;
                $joined .= $val;
            }
            $out[$k] = $enc ? self::rfc2231($joined) : $joined;
        }
        return $out;
    }

    private static function rfc2231(string $v): string
    {
        if (preg_match("/^([^']*)'[^']*'(.*)$/", $v, $m)) return self::toUtf8(rawurldecode($m[2]), $m[1]);
        return rawurldecode($v);
    }

    private static function filename(array $h): string
    {
        $d = self::params((string)($h['content-disposition'] ?? ''));
        $c = self::params((string)($h['content-type'] ?? ''));
        $n = (string)($d['filename'] ?? $c['name'] ?? '');
        return $n !== '' ? self::decodeHeader($n) : '';
    }

    private static function decodeBody(string $body, string $cte): string
    {
        return match (strtolower(trim($cte))) {
            'base64'           => (string)base64_decode(preg_replace('/[^A-Za-z0-9+\/=]/', '', $body)),
            'quoted-printable' => quoted_printable_decode($body),
            default            => $body,
        };
    }

    public static function toUtf8(string $s, string $charset): string
    {
        $cs = strtolower(trim($charset, " \"'"));
        if ($cs === '' || $cs === 'utf-8' || $cs === 'utf8' || $cs === 'us-ascii') {
            return mb_check_encoding($s, 'UTF-8') ? $s : (string)mb_convert_encoding($s, 'UTF-8', 'ISO-8859-2');
        }
        if (in_array($cs, ['windows-1250', 'cp1250'], true)) $cs = 'Windows-1250';
        $out = @mb_convert_encoding($s, 'UTF-8', $cs);
        if ($out === false || $out === '') $out = @iconv($cs, 'UTF-8//TRANSLIT//IGNORE', $s);
        return is_string($out) && $out !== '' ? $out : $s;
    }

    /** Nagłówek z kodowaniem RFC 2047 (=?charset?B|Q?…?=) → UTF-8. */
    public static function decodeHeader(string $v): string
    {
        $v = trim($v);
        if (!str_contains($v, '=?')) return self::toUtf8($v, '');
        // Spacje między sąsiednimi słowami kodowanymi nie należą do tekstu
        $v = preg_replace('/(=\?[^?]+\?[BbQq]\?[^?]*\?=)\s+(?==\?)/', '$1', $v);
        return (string)preg_replace_callback('/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/', function ($m) {
            $cs = preg_replace('/\*.*$/', '', $m[1]);
            $bytes = strtoupper($m[2]) === 'B' ? (string)base64_decode($m[3]) : quoted_printable_decode(str_replace('_', ' ', $m[3]));
            return self::toUtf8($bytes, $cs);
        }, $v);
    }

    /** [adres małymi literami, nazwa] z nagłówka From. */
    public static function address(string $v): array
    {
        $v = trim($v);
        if (preg_match('/^(.*)<([^<>@\s]+@[^<>\s]+)>\s*$/', $v, $m)) {
            $name = trim(self::decodeHeader(trim($m[1])), " \"'");
            return [strtolower(trim($m[2])), $name];
        }
        if (preg_match('/([^\s<>"]+@[^\s<>"]+)/', $v, $m)) {
            $name = '';
            if (preg_match('/\(([^)]*)\)/', $v, $c)) $name = self::decodeHeader($c[1]);
            return [strtolower(trim($m[1], '<>')), $name];
        }
        return ['', self::decodeHeader($v)];
    }

    public static function htmlToText(string $html): string
    {
        $html = preg_replace('#<(script|style|head)\b[^>]*>.*?</\1>#is', '', $html);
        $html = preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = preg_replace('#</(p|div|li|tr|h[1-6]|blockquote)>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = preg_replace("/[ \t]+\n/", "\n", $text);
        return trim(preg_replace("/\n{3,}/", "\n\n", $text));
    }

    private static function safeName(string $n): string
    {
        $n = preg_replace('/[\x00-\x1f\/\\\\:*?"<>|]+/u', '_', $n);
        $n = trim($n, " .");
        return mb_substr($n !== '' ? $n : 'zalacznik', 0, 180);
    }

    private static function extFor(string $type): string
    {
        return match ($type) {
            'application/pdf' => '.pdf', 'image/jpeg' => '.jpg', 'image/png' => '.png', 'image/gif' => '.gif',
            'text/plain' => '.txt', 'text/html' => '.html', 'text/calendar' => '.ics', default => '.bin',
        };
    }
}
