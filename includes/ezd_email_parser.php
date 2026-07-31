<?php
/**
 * EZD — parser plików e-mail (.eml / .msg) do ujednoliconej struktury.
 *
 * Klasy:
 *   OleReader        – odczyt formatu OLE2 Compound File Binary (MSG)
 *   EzdEmailParser   – publiczne API: parse(), sanitizeHtml()
 */

// ─────────────────────────────────────────────────────────────────────────────
// OLE2 Compound File Binary reader (pure PHP, bez zależności zewnętrznych)
// ─────────────────────────────────────────────────────────────────────────────
class OleReader
{
    private string $data;
    private int    $sectorSize;
    private int    $miniFATCutoff;
    private array  $fat        = [];
    private array  $miniFAT    = [];
    private array  $directory  = [];
    private string $miniStream = '';

    public function __construct(string $data)
    {
        $this->data = $data;
        $this->parse();
    }

    private function parse(): void
    {
        if (strlen($this->data) < 512) {
            throw new \RuntimeException('Plik zbyt krótki dla formatu OLE2');
        }

        // ── Nagłówek ────────────────────────────────────────────────────────
        $ssizePow            = unpack('v', substr($this->data, 30, 2))[1];
        $this->sectorSize    = 1 << $ssizePow;                         // zwykle 512
        $this->miniFATCutoff = unpack('V', substr($this->data, 56, 4))[1]; // zwykle 4096

        $firstDirSector  = unpack('V', substr($this->data, 48, 4))[1];
        $firstMiniFATSec = unpack('V', substr($this->data, 60, 4))[1];
        $firstDIFATSec   = unpack('V', substr($this->data, 68, 4))[1];

        // ── DIFAT → lista sektorów FAT ────────────────────────────────────
        $fatSectors = [];
        for ($i = 0; $i < 109; $i++) {
            $v = unpack('V', substr($this->data, 76 + $i * 4, 4))[1];
            if ($v >= 0xFFFFFFFD) break;
            $fatSectors[] = $v;
        }
        // Dodatkowe sektory DIFAT (bardzo duże pliki)
        $difatSec = $firstDIFATSec;
        $seen = [];
        while ($difatSec < 0xFFFFFFFD && !isset($seen[$difatSec])) {
            $seen[$difatSec] = true;
            $base  = ($difatSec + 1) * $this->sectorSize;
            $slots = ($this->sectorSize / 4) - 1;
            for ($i = 0; $i < $slots; $i++) {
                $v = unpack('V', substr($this->data, $base + $i * 4, 4))[1];
                if ($v >= 0xFFFFFFFD) break;
                $fatSectors[] = $v;
            }
            $difatSec = unpack('V', substr($this->data, $base + $slots * 4, 4))[1];
        }

        // ── Buduj FAT ────────────────────────────────────────────────────
        $ePerSec = $this->sectorSize / 4;
        foreach ($fatSectors as $sec) {
            $base = ($sec + 1) * $this->sectorSize;
            for ($i = 0; $i < $ePerSec; $i++) {
                $this->fat[] = unpack('V', substr($this->data, $base + $i * 4, 4))[1];
            }
        }

        // ── Mini-FAT ─────────────────────────────────────────────────────
        $sec  = $firstMiniFATSec;
        $seen = [];
        while ($sec < 0xFFFFFFFD && !isset($seen[$sec])) {
            $seen[$sec] = true;
            $base = ($sec + 1) * $this->sectorSize;
            for ($i = 0; $i < $ePerSec; $i++) {
                $this->miniFAT[] = unpack('V', substr($this->data, $base + $i * 4, 4))[1];
            }
            $sec = $this->fat[$sec] ?? 0xFFFFFFFE;
        }

        // ── Katalog (WSZYSTKIE wpisy — indeks musi być spójny) ────────────
        $sec  = $firstDirSector;
        $seen = [];
        while ($sec < 0xFFFFFFFD && !isset($seen[$sec])) {
            $seen[$sec] = true;
            $base = ($sec + 1) * $this->sectorSize;
            for ($i = 0; $i < $this->sectorSize / 128; $i++) {
                $this->directory[] = $this->parseDirEntry(
                    substr($this->data, $base + $i * 128, 128)
                );
            }
            $sec = $this->fat[$sec] ?? 0xFFFFFFFE;
        }

        // ── Mini-stream z wpisu root (katalog[0]) ─────────────────────────
        $root = $this->directory[0] ?? null;
        if ($root && $root['start'] < 0xFFFFFFFD) {
            $this->miniStream = $this->readNormal($root['start'], $root['size']);
        }
    }

    private function parseDirEntry(string $raw): array
    {
        $nameLen = min(unpack('v', substr($raw, 64, 2))[1], 64);
        $name    = $nameLen >= 2
            ? mb_convert_encoding(substr($raw, 0, $nameLen - 2), 'UTF-8', 'UTF-16LE')
            : '';

        return [
            'name'  => $name,
            'type'  => ord($raw[66]),
            'left'  => unpack('V', substr($raw, 68, 4))[1],
            'right' => unpack('V', substr($raw, 72, 4))[1],
            'child' => unpack('V', substr($raw, 76, 4))[1],
            'start' => unpack('V', substr($raw, 116, 4))[1],
            'size'  => unpack('V', substr($raw, 120, 4))[1],
        ];
    }

    private function readNormal(int $startSec, int $size): string
    {
        $out  = '';
        $left = $size;
        $sec  = $startSec;
        $seen = [];
        while ($sec < 0xFFFFFFFD && $left > 0 && !isset($seen[$sec])) {
            $seen[$sec] = true;
            $chunk = min($this->sectorSize, $left);
            $out  .= substr($this->data, ($sec + 1) * $this->sectorSize, $chunk);
            $left -= $chunk;
            $sec   = $this->fat[$sec] ?? 0xFFFFFFFE;
        }
        return $out;
    }

    private function readMini(int $startSec, int $size): string
    {
        $out  = '';
        $left = $size;
        $sec  = $startSec;
        $seen = [];
        while ($sec < 0xFFFFFFFD && $left > 0 && !isset($seen[$sec])) {
            $seen[$sec] = true;
            $chunk = min(64, $left);
            $out  .= substr($this->miniStream, $sec * 64, $chunk);
            $left -= $chunk;
            $sec   = $this->miniFAT[$sec] ?? 0xFFFFFFFE;
        }
        return $out;
    }

    private function readEntry(array $e): string
    {
        if ($e['start'] >= 0xFFFFFFFD) return '';
        return ($e['size'] > 0 && $e['size'] < $this->miniFATCutoff)
            ? $this->readMini($e['start'], $e['size'])
            : $this->readNormal($e['start'], $e['size']);
    }

    // Przejście po rodzeństwie drzewa czerwono-czarnego → flat map [idx => entry]
    private function walk(int $idx, array &$out): void
    {
        if ($idx >= 0xFFFFFFFF || $idx >= count($this->directory) || isset($out[$idx])) return;
        $e = $this->directory[$idx];
        $out[$idx] = $e;
        $this->walk($e['left'],  $out);
        $this->walk($e['right'], $out);
    }

    private function children(int $parentIdx): array
    {
        $parent = $this->directory[$parentIdx] ?? null;
        if (!$parent || $parent['child'] >= 0xFFFFFFFF) return [];
        $out = [];
        $this->walk($parent['child'], $out);
        return $out;
    }

    // ─── Publiczne API ────────────────────────────────────────────────────────

    public function getRootChildren(): array
    {
        return $this->children(0);
    }

    /** Pobiera właściwość MAPI jako string (najpierw Unicode 001F, potem ANSI 001E). */
    public function getMAPIString(array $streams, int $propId): string
    {
        foreach (['001F', '001E'] as $t) {
            $name = sprintf('__substg1.0_%04X%s', $propId, $t);
            foreach ($streams as $e) {
                if ($e['name'] === $name && $e['type'] === 2) {
                    $raw = $this->readEntry($e);
                    return $t === '001F'
                        ? mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE')
                        : $raw;
                }
            }
        }
        return '';
    }

    /** Pobiera właściwość binarną (0102). */
    public function getMAPIBinary(array $streams, int $propId): ?string
    {
        $name = sprintf('__substg1.0_%04X0102', $propId);
        foreach ($streams as $e) {
            if ($e['name'] === $name && $e['type'] === 2) return $this->readEntry($e);
        }
        return null;
    }

    /**
     * Odczytuje właściwość stałego rozmiaru z __properties_version1.0.
     *
     * @param int $headerSkip  32 dla roota, 8 dla sub-storage (odbiorcy/załączniki)
     * @return string|null  8 bajtów wartości (FILETIME) lub 4 bajty (PT_LONG) lub null
     */
    public function getMAPIFixed(array $streams, int $propId, int $propType, int $headerSkip = 32): ?string
    {
        foreach ($streams as $e) {
            if ($e['name'] !== '__properties_version1.0' || $e['type'] !== 2) continue;
            $data   = $this->readEntry($e);
            $offset = $headerSkip;
            while ($offset + 16 <= strlen($data)) {
                $tag = unpack('V', substr($data, $offset, 4))[1];
                if (($tag & 0xFFFF) === $propType && ($tag >> 16 & 0xFFFF) === $propId) {
                    return substr($data, $offset + 8, 8);
                }
                $offset += 16;
            }
            break;
        }
        return null;
    }

    /** Przelicza FILETIME (8 bajtów LE) na Unix timestamp. */
    public static function filetimeToUnix(string $b8): int
    {
        $lo = unpack('V', substr($b8, 0, 4))[1];
        $hi = unpack('V', substr($b8, 4, 4))[1];
        return (int)((($hi * 4294967296.0 + $lo) / 10000000.0) - 11644473600.0);
    }

    /** Zwraca tablicę odbiorców [name, email, type] (1=TO, 2=CC, 3=BCC). */
    public function getRecipients(): array
    {
        $list = [];
        foreach ($this->directory as $idx => $e) {
            if ($e['type'] !== 1 || !preg_match('/^__recip_version1\.0_#/i', $e['name'])) continue;
            $ch    = $this->children($idx);
            $name  = $this->getMAPIString($ch, 0x3001);
            $email = $this->getMAPIString($ch, 0x39FE) ?: $this->getMAPIString($ch, 0x3003);
            $tr    = $this->getMAPIFixed($ch, 0x0C15, 0x0003, 8);
            $type  = $tr ? (unpack('V', substr($tr, 0, 4))[1]) : 1;
            $list[] = compact('name', 'email', 'type');
        }
        return $list;
    }

    /** Zwraca tablicę załączników [name, mime, size, data (base64)]. */
    public function getAttachments(): array
    {
        $list = [];
        foreach ($this->directory as $idx => $e) {
            if ($e['type'] !== 1 || !preg_match('/^__attach_version1\.0_#/i', $e['name'])) continue;
            $ch   = $this->children($idx);
            $name = $this->getMAPIString($ch, 0x3707) ?: $this->getMAPIString($ch, 0x3704);
            if (!$name) continue;
            $data = $this->getMAPIBinary($ch, 0x3701);
            if ($data === null) continue;
            $mime = $this->getMAPIString($ch, 0x370E) ?: 'application/octet-stream';
            $list[] = [
                'name' => trim($name),
                'mime' => $mime,
                'size' => strlen($data),
                'data' => base64_encode($data),
            ];
        }
        return $list;
    }
}


// ─────────────────────────────────────────────────────────────────────────────
// Publiczne API parsowania e-maili
// ─────────────────────────────────────────────────────────────────────────────
class EzdEmailParser
{
    private const TEMPLATE = [
        'from_name'   => '',
        'from_email'  => '',
        'to'          => [],
        'cc'          => [],
        'bcc'         => [],
        'reply_to'    => [],
        'subject'     => '',
        'date'        => null,
        'body_html'   => '',
        'body_text'   => '',
        'attachments' => [],
        'cid_map'     => [],   // 'content-id' => 'data:image/...' URI
        'error'       => null,
    ];

    // ─── Publiczne API ─────────────────────────────────────────────────────────

    public static function parse(string $path, string $ext): array
    {
        return match (strtolower($ext)) {
            'eml'   => self::parseEml($path),
            'msg'   => self::parseMsg($path),
            default => array_merge(self::TEMPLATE, ['error' => 'Nieobsługiwany format: ' . htmlspecialchars($ext)]),
        };
    }

    // ─── EML (RFC 2822 / MIME) ─────────────────────────────────────────────────

    public static function parseEml(string $path): array
    {
        $result = self::TEMPLATE;
        $raw    = @file_get_contents($path);

        if ($raw === false) {
            return array_merge($result, ['error' => 'Błąd odczytu pliku']);
        }

        $raw = str_replace(["\r\n", "\r"], ["\n", "\n"], $raw);
        $sep = strpos($raw, "\n\n");

        if ($sep === false) {
            return array_merge($result, ['error' => 'Nieprawidłowy format EML (brak separatora nagłówek/treść)']);
        }

        $headers = self::parseHeaders(substr($raw, 0, $sep));
        $body    = substr($raw, $sep + 2);

        if (!empty($headers['from'])) {
            $a = self::parseAddr(self::rfc2047($headers['from']));
            $result['from_name']  = $a['name'];
            $result['from_email'] = $a['email'];
        }
        $result['to']       = self::addrList($headers['to']       ?? '');
        $result['cc']       = self::addrList($headers['cc']       ?? '');
        $result['bcc']      = self::addrList($headers['bcc']      ?? '');
        $result['reply_to'] = self::addrList($headers['reply-to'] ?? '');
        $result['subject']  = self::rfc2047($headers['subject']   ?? '');

        if (!empty($headers['date'])) {
            $ts = strtotime($headers['date']);
            if ($ts !== false) $result['date'] = $ts;
        }

        self::parsePart($headers, $body, $result);

        return $result;
    }

    private static function parsePart(array $hdr, string $body, array &$r): void
    {
        $ct   = self::parseCT($hdr['content-type'] ?? 'text/plain');
        $enc  = strtolower($hdr['content-transfer-encoding'] ?? '7bit');
        $disp = self::parseCT($hdr['content-disposition'] ?? '');
        $cid  = trim($hdr['content-id'] ?? '', ' <>');

        // Multipart — rekurencja po częściach
        if (str_starts_with($ct['type'], 'multipart/')) {
            $boundary = $ct['params']['boundary'] ?? '';
            if (!$boundary) return;
            foreach (self::splitMultipart($body, $boundary) as $part) {
                $nl = strpos($part, "\n\n");
                if ($nl === false) continue;
                $ph = self::parseHeaders(substr($part, 0, $nl));
                self::parsePart($ph, substr($part, $nl + 2), $r);
            }
            return;
        }

        // Dekodowanie treści
        $decoded = match ($enc) {
            'base64'           => base64_decode(str_replace(["\r", "\n"], '', $body)),
            'quoted-printable' => quoted_printable_decode($body),
            default            => $body,
        };

        $charset = $ct['params']['charset'] ?? 'utf-8';

        if ($ct['type'] === 'text/html') {
            if ($r['body_html'] === '') $r['body_html'] = self::toUtf8($decoded, $charset);
            return;
        }

        if ($ct['type'] === 'text/plain') {
            if ($r['body_text'] === '') $r['body_text'] = self::toUtf8($decoded, $charset);
            return;
        }

        // Obraz inline z Content-ID → mapa CID, nie jako załącznik
        if ($cid && str_starts_with($ct['type'], 'image/') && ($disp['type'] ?? '') !== 'attachment') {
            $r['cid_map'][$cid] = 'data:' . $ct['type'] . ';base64,' . base64_encode($decoded);
            return;
        }

        // Załącznik
        $name = self::rfc2047($disp['params']['filename'] ?? $ct['params']['name'] ?? '');
        if (!$name && $ct['type'] === 'message/rfc822') $name = 'embedded.eml';
        if (!$name) return;

        $r['attachments'][] = [
            'name' => $name,
            'mime' => $ct['type'],
            'size' => strlen($decoded),
            'data' => base64_encode($decoded),
        ];
    }

    private static function splitMultipart(string $body, string $boundary): array
    {
        $parts  = [];
        $delim  = '--' . $boundary;
        $pos    = 0;
        $len    = strlen($body);

        while ($pos < $len) {
            $d = strpos($body, $delim, $pos);
            if ($d === false) break;

            $after = $d + strlen($delim);

            // Zakończenie multipart
            if (substr($body, $after, 2) === '--') break;

            // Pomiń CRLF/LF po granicy
            if (substr($body, $after, 1) === "\r") $after++;
            if (substr($body, $after, 1) === "\n") $after++;

            $next = strpos($body, $delim, $after);
            if ($next === false) {
                $parts[] = substr($body, $after);
                break;
            }

            // Obetnij CRLF przed kolejną granicą
            $end = $next;
            if ($end > 0 && $body[$end - 1] === "\n") $end--;
            if ($end > 0 && $body[$end - 1] === "\r") $end--;

            $parts[] = substr($body, $after, $end - $after);
            $pos     = $next;
        }

        return $parts;
    }

    private static function parseHeaders(string $str): array
    {
        // Rozwijanie nagłówków wielowierszowych (RFC 2822)
        $str     = preg_replace("/\n([ \t])/", ' ', $str) ?? $str;
        $headers = [];
        foreach (explode("\n", $str) as $line) {
            $line = rtrim($line, "\r");
            if (!str_contains($line, ':')) continue;
            [$k, $v] = explode(':', $line, 2);
            $k = strtolower(trim($k));
            if ($k !== '') $headers[$k] = ltrim($v);
        }
        return $headers;
    }

    private static function parseCT(string $value): array
    {
        $parts  = explode(';', $value);
        $type   = strtolower(trim((string)array_shift($parts)));
        $params = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if (!str_contains($p, '=')) continue;
            [$k, $v] = explode('=', $p, 2);
            $params[strtolower(trim($k))] = trim($v, " \t\"'");
        }
        return ['type' => $type, 'params' => $params];
    }

    /** Dekodowanie nagłówków RFC 2047 (=?charset?B/Q?...?=). */
    private static function rfc2047(string $v): string
    {
        return preg_replace_callback(
            '/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/',
            static function ($m) {
                $decoded = strtoupper($m[2]) === 'B'
                    ? base64_decode($m[3])
                    : quoted_printable_decode(str_replace('_', ' ', $m[3]));
                return self::toUtf8($decoded, $m[1]);
            },
            $v
        ) ?? $v;
    }

    private static function toUtf8(string $str, string $charset): string
    {
        $c = strtoupper(trim($charset));
        if ($c === '' || $c === 'UTF-8') return $str;
        $r = @iconv($c, 'UTF-8//TRANSLIT//IGNORE', $str);
        return $r !== false ? $r : $str;
    }

    private static function parseAddr(string $raw): array
    {
        $raw = trim($raw);
        if (preg_match('/^"?(.+?)"?\s*<([^>]+)>$/', $raw, $m)) {
            return ['name' => trim($m[1], '" '), 'email' => strtolower(trim($m[2]))];
        }
        if (filter_var($raw, FILTER_VALIDATE_EMAIL)) {
            return ['name' => '', 'email' => strtolower($raw)];
        }
        return ['name' => $raw, 'email' => ''];
    }

    private static function addrList(string $raw): array
    {
        if (trim($raw) === '') return [];
        $list = [];
        // Split po przecinku z pominięciem przecinków wewnątrz <> lub ""
        $parts = preg_split('/,(?=[^>]*(?:<|$))/', self::rfc2047($raw)) ?: [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') $list[] = self::parseAddr($p);
        }
        return $list;
    }

    // ─── MSG (OLE2/MAPI) ──────────────────────────────────────────────────────

    public static function parseMsg(string $path): array
    {
        $result = self::TEMPLATE;
        $raw    = @file_get_contents($path);

        if ($raw === false) {
            return array_merge($result, ['error' => 'Błąd odczytu pliku']);
        }

        if (substr($raw, 0, 8) !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            return array_merge($result, ['error' => 'Nieprawidłowy format MSG (brak sygnatury OLE2)']);
        }

        try {
            $ole     = new OleReader($raw);
            $streams = $ole->getRootChildren();

            $result['subject']    = $ole->getMAPIString($streams, 0x0037)
                                 ?: $ole->getMAPIString($streams, 0x0070);
            $result['from_name']  = $ole->getMAPIString($streams, 0x0C1A);
            $result['from_email'] = $ole->getMAPIString($streams, 0x5D01)
                                 ?: $ole->getMAPIString($streams, 0x0C1F);

            // PR_CLIENT_SUBMIT_TIME (0x0039) lub PR_MESSAGE_DELIVERY_TIME (0x0E06)
            $ft = $ole->getMAPIFixed($streams, 0x0039, 0x0040, 32)
               ?: $ole->getMAPIFixed($streams, 0x0E06, 0x0040, 32);
            if ($ft) $result['date'] = OleReader::filetimeToUnix($ft);

            foreach ($ole->getRecipients() as $rec) {
                $entry = ['name' => $rec['name'], 'email' => $rec['email']];
                if ($rec['type'] === 2)     $result['cc'][]  = $entry;
                elseif ($rec['type'] === 3) $result['bcc'][] = $entry;
                else                        $result['to'][]  = $entry;
            }

            // Fallback dla pola To gdy sub-storage odbiorcy są puste
            if (empty($result['to'])) {
                $displayTo = $ole->getMAPIString($streams, 0x0E04);
                foreach (array_filter(array_map('trim', explode(';', $displayTo))) as $n) {
                    $result['to'][] = ['name' => $n, 'email' => ''];
                }
            }

            $result['body_text'] = $ole->getMAPIString($streams, 0x1000);

            $htmlRaw = $ole->getMAPIBinary($streams, 0x1013);
            if ($htmlRaw !== null) {
                if (str_starts_with($htmlRaw, "\xEF\xBB\xBF")) {
                    $htmlRaw = substr($htmlRaw, 3);
                } elseif (str_starts_with($htmlRaw, "\xFF\xFE")) {
                    $htmlRaw = mb_convert_encoding(substr($htmlRaw, 2), 'UTF-8', 'UTF-16LE');
                }
                $result['body_html'] = $htmlRaw;
            }

            $result['attachments'] = $ole->getAttachments();

        } catch (\Throwable $e) {
            $result['error'] = 'Błąd parsowania MSG: ' . $e->getMessage();
        }

        return $result;
    }

    // ─── Sanitizer HTML (XSS) ─────────────────────────────────────────────────

    /**
     * Usuwa niebezpieczne elementy z HTML e-maila.
     *
     * @param string   $html    Surowy HTML z wiadomości
     * @param string[] $cidMap  Mapa 'content-id' => 'data:...' (cid: → data URI)
     */
    public static function sanitizeHtml(string $html, array $cidMap = []): string
    {
        // CID → data URI (inline obrazy z EML)
        if ($cidMap) {
            $html = preg_replace_callback(
                '/\bsrc=["\']cid:([^"\']+)["\']/i',
                static function ($m) use ($cidMap) {
                    $id = trim($m[1], '<>');
                    return isset($cidMap[$id]) ? 'src="' . $cidMap[$id] . '"' : $m[0];
                },
                $html
            ) ?? $html;
        }

        // Usuń <script> i <style>
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? $html;
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is',  '', $html) ?? $html;

        // Usuń atrybuty on* (event handlers)
        $html = preg_replace('/\s+on\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|\S+)/i', '', $html) ?? $html;

        // Zablokuj javascript: w href/src/action
        $html = preg_replace('/\b(href|src|action)\s*=\s*["\']javascript:/i', '$1="data:blocked:', $html) ?? $html;

        // Zastąp zewnętrzne src obrazów przezroczystym pikselowym GIF-em
        $html = preg_replace_callback(
            '/<img\b([^>]*)>/is',
            static function ($m) {
                // Przepuść data: i cid: (już przetworzone wyżej)
                if (preg_match('/\bsrc\s*=\s*["\'](?:data:|cid:)/i', $m[1])) return $m[0];
                $attr = preg_replace(
                    '/\bsrc\s*=\s*(?:"[^"]*"|\'[^\']*\')/i',
                    'src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw=="',
                    $m[1]
                ) ?? $m[1];
                return '<img' . $attr . '>';
            },
            $html
        ) ?? $html;

        return $html;
    }
}
