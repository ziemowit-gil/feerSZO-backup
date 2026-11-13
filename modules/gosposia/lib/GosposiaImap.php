<?php
declare(strict_types=1);

/**
 * modules/gosposia/lib/GosposiaImap.php — lekki klient IMAP4rev1 (RFC 3501) dla Gosposi: tylko odczyt nagłówków i przeniesienie do Kosza.
 *
 * Bez ext-imap (brak w obrazach Docker i w PHP 8.4) — gniazdo ssl://imap.dpoczta.pl:993 z weryfikacją certyfikatu.
 * Polecenia: CAPABILITY, LOGIN, LIST, SELECT, UID SEARCH, UID FETCH (BODY.PEEK — nie oznacza jako przeczytane),
 * UID MOVE (RFC 6851) albo UID COPY + \Deleted + UID EXPUNGE (UIDPLUS) tylko dla przeniesionych UID-ów, LOGOUT.
 * Nigdy nie wywołuje gołego EXPUNGE ani CLOSE (te usuwałyby na stałe cudze wiadomości z flagą \Deleted).
 *
 * Bezpieczeństwo: hasło nie trafia do wyjątków ani dzienników; treść wiadomości nie jest pobierana (tylko wybrane nagłówki i rozmiar).
 * Wyjątki: GosposiaImapUnavailable (sieć, limit czasu), GosposiaImapAuthFailed (złe hasło), GosposiaImapCommandFailed (NO/BAD).
 */
class GosposiaImapException extends RuntimeException {}
final class GosposiaImapUnavailable extends GosposiaImapException {}
final class GosposiaImapAuthFailed extends GosposiaImapException {}
final class GosposiaImapCommandFailed extends GosposiaImapException {}

final class GosposiaImap
{
    public const HOST = 'imap.dpoczta.pl';
    public const PORT = 993;
    /** Nagłówki pobierane do klasyfikacji (minimalizacja danych). */
    private const FIELDS = 'FROM SUBJECT DATE LIST-UNSUBSCRIBE LIST-ID PRECEDENCE';
    /** Nazwy folderu kosza spotykane na Dovecot/dPoczta, gdy serwer nie zgłasza atrybutu \Trash. */
    private const TRASH_NAMES = ['trash', 'kosz', 'deleted items', 'deleted messages', 'deleted', 'usunięte', 'elementy usunięte'];

    /** @var resource|null */
    private $sock = null;
    private int $tag = 0;
    /** @var list<string> */
    private array $caps = [];

    public function __construct(private string $address = '', private int $timeout = 30)
    {
        if ($this->address === '') $this->address = 'ssl://' . self::HOST . ':' . self::PORT;
    }

    public function connect(): void
    {
        $host = (string)parse_url($this->address, PHP_URL_HOST);
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host, 'SNI_enabled' => true]]);
        $errno = 0; $errstr = '';
        $s = @stream_socket_client($this->address, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$s) throw new GosposiaImapUnavailable('Nie udało się połączyć z serwerem poczty (' . $host . '). Spróbuj za chwilę.');
        stream_set_timeout($s, $this->timeout);
        $this->sock = $s;
        [$greet] = $this->readLine();
        if (!str_starts_with($greet, '* OK') && !str_starts_with($greet, '* PREAUTH')) {
            throw new GosposiaImapUnavailable('Serwer poczty odpowiedział nieoczekiwanie.');
        }
    }

    public function login(string $user, string $pass): void
    {
        $tag = $this->nextTag();
        $this->write($tag . ' LOGIN ' . $this->quote($user) . ' ');
        if (preg_match('/^[\x20-\x7e]*$/', $pass) && $pass !== '') {
            $this->write($this->quote($pass) . "\r\n");
        } else {
            $this->write('{' . strlen($pass) . "}\r\n");
            [$cont] = $this->readLine();
            if (!str_starts_with($cont, '+')) throw new GosposiaImapAuthFailed('Serwer poczty odrzucił logowanie.');
            $this->write($pass . "\r\n");
        }
        try {
            $this->readTagged($tag);
        } catch (GosposiaImapCommandFailed) {
            throw new GosposiaImapAuthFailed('Serwer poczty nie przyjął adresu albo hasła. Sprawdź hasło do poczty (to nie jest hasło logowania jednokrotnego).');
        }
        $this->caps = [];
    }

    /** @return list<string> możliwości serwera (wielkie litery) */
    public function capabilities(): array
    {
        if ($this->caps) return $this->caps;
        foreach ($this->command('CAPABILITY') as [$line]) {
            if (str_starts_with($line, '* CAPABILITY ')) $this->caps = array_map('strtoupper', preg_split('/\s+/', trim(substr($line, 13))) ?: []);
        }
        return $this->caps;
    }

    /**
     * Folder Kosza: najpierw atrybut \Trash (RFC 6154), potem znane nazwy (Trash, INBOX.Trash, Kosz…).
     * @return array{raw:string, name:string}|null  raw — nazwa do poleceń IMAP, name — UTF-8 do wyświetlenia
     */
    public function findTrash(): ?array
    {
        $byName = null;
        foreach ($this->command('LIST "" "*"') as [$line, $lits]) {
            if (!preg_match('/^\* LIST \(([^)]*)\) (NIL|"(?:[^"\\\\]|\\\\.)*") (.+)$/i', $line, $m)) continue;
            $raw = $this->atom(trim($m[3]), $lits);
            $attrs = strtolower($m[1]);
            if (str_contains($attrs, '\\noselect') || str_contains($attrs, '\\nonexistent')) continue;
            $item = ['raw' => $raw, 'name' => self::decodeName($raw)];
            if (str_contains($attrs, '\\trash')) return $item;
            $delim = $m[2] === 'NIL' ? '' : stripcslashes(substr($m[2], 1, -1));
            $leaf = $delim !== '' && str_contains($item['name'], $delim) ? substr($item['name'], strrpos($item['name'], $delim) + strlen($delim)) : $item['name'];
            if ($byName === null && in_array(mb_strtolower($leaf), self::TRASH_NAMES, true)) $byName = $item;
        }
        return $byName;
    }

    /** SELECT folderu; @return array{exists:int, uidvalidity:int} */
    public function select(string $raw = 'INBOX'): array
    {
        $out = ['exists' => 0, 'uidvalidity' => 0];
        foreach ($this->command('SELECT ' . $this->quote($raw)) as [$line]) {
            if (preg_match('/^\* (\d+) EXISTS/i', $line, $m)) $out['exists'] = (int)$m[1];
            if (preg_match('/\[UIDVALIDITY (\d+)\]/i', $line, $m)) $out['uidvalidity'] = (int)$m[1];
        }
        return $out;
    }

    /** @return list<int> UID-y wszystkich wiadomości w wybranym folderze, rosnąco */
    public function uids(): array
    {
        $uids = [];
        foreach ($this->command('UID SEARCH ALL') as [$line]) {
            if (str_starts_with(strtoupper($line), '* SEARCH')) {
                foreach (preg_split('/\s+/', trim(substr($line, 8))) ?: [] as $u) if (ctype_digit($u)) $uids[] = (int)$u;
            }
        }
        sort($uids);
        return $uids;
    }

    /**
     * Metadane wiadomości (bez treści): uid, size, from_name, from_email, subject, date (Y-m-d H:i), list_unsubscribe, precedence, list_id.
     * BODY.PEEK nie ustawia flagi \Seen.
     * @param list<int> $uids
     * @return list<array<string, mixed>>
     */
    public function headers(array $uids): array
    {
        $out = [];
        foreach (array_chunk($uids, 250) as $chunk) {
            $resp = $this->command('UID FETCH ' . self::uidSet($chunk) . ' (UID RFC822.SIZE BODY.PEEK[HEADER.FIELDS (' . self::FIELDS . ')])');
            foreach ($resp as [$line, $lits]) {
                if (!preg_match('/^\* \d+ FETCH \(/i', $line)) continue;
                if (!preg_match('/\bUID (\d+)/i', $line, $mu)) continue;
                $size = preg_match('/RFC822\.SIZE (\d+)/i', $line, $ms) ? (int)$ms[1] : 0;
                $hdr = '';
                if (preg_match('/BODY\[HEADER\.FIELDS[^\]]*\] (\x00L(\d+)\x00|"((?:[^"\\\\]|\\\\.)*)"|NIL)/i', $line, $mh)) {
                    $hdr = isset($mh[2]) && $mh[2] !== '' ? (string)($lits[(int)$mh[2]] ?? '') : (isset($mh[3]) ? stripcslashes($mh[3]) : '');
                }
                $out[] = ['uid' => (int)$mu[1], 'size' => $size] + self::parseHeaders($hdr);
            }
        }
        return $out;
    }

    /**
     * Przenosi wiadomości (UID-y z wybranego folderu) do folderu $trashRaw. Bez trwałego usuwania:
     * MOVE, a gdy serwer go nie zna — COPY, \Deleted i UID EXPUNGE tylko tych UID-ów (UIDPLUS); bez UIDPLUS zostaje sama flaga \Deleted.
     * @param list<int> $uids
     * @return string metoda: move | copy+expunge | copy+flag
     */
    public function moveToTrash(array $uids, string $trashRaw): string
    {
        if (!$uids) return 'move';
        $caps = $this->capabilities();
        $method = in_array('MOVE', $caps, true) ? 'move' : (in_array('UIDPLUS', $caps, true) ? 'copy+expunge' : 'copy+flag');
        foreach (array_chunk($uids, 200) as $chunk) {
            $set = self::uidSet($chunk);
            if ($method === 'move') { $this->command('UID MOVE ' . $set . ' ' . $this->quote($trashRaw)); continue; }
            $this->command('UID COPY ' . $set . ' ' . $this->quote($trashRaw));
            $this->command('UID STORE ' . $set . ' +FLAGS.SILENT (\\Deleted)');
            if ($method === 'copy+expunge') $this->command('UID EXPUNGE ' . $set);
        }
        return $method;
    }

    public function logout(): void
    {
        if (!$this->sock) return;
        try { $this->command('LOGOUT'); } catch (\Throwable) {}
        @fclose($this->sock);
        $this->sock = null;
    }

    public function __destruct() { if ($this->sock) @fclose($this->sock); }

    // ── Nagłówki ───────────────────────────────────────────────────────────────────────────────

    /** @return array{from_name:string, from_email:string, subject:string, date:string, list_unsubscribe:bool, precedence:string, list_id:string} */
    public static function parseHeaders(string $raw): array
    {
        $raw = (string)preg_replace("/\r?\n[ \t]+/", ' ', $raw);   // rozwinięcie zawiniętych linii
        $h = [];
        foreach (preg_split("/\r?\n/", $raw) ?: [] as $l) {
            if (!str_contains($l, ':')) continue;
            [$k, $v] = explode(':', $l, 2);
            $k = strtolower(trim($k));
            if (!isset($h[$k])) $h[$k] = trim($v);
        }
        [$name, $email] = self::parseFrom(self::decodeMime($h['from'] ?? ''));
        $ts = isset($h['date']) ? strtotime(preg_replace('/\s*\([^)]*\)\s*$/', '', $h['date']) ?? '') : false;
        return [
            'from_name'        => $name,
            'from_email'       => $email,
            'subject'          => mb_substr(self::decodeMime($h['subject'] ?? ''), 0, 300),
            'date'             => $ts ? date('Y-m-d H:i', $ts) : '',
            'list_unsubscribe' => ($h['list-unsubscribe'] ?? '') !== '',
            'precedence'       => strtolower(trim($h['precedence'] ?? '')),
            'list_id'          => mb_substr(self::decodeMime($h['list-id'] ?? ''), 0, 120),
        ];
    }

    /** @return array{0:string, 1:string} [nazwa, adres małymi literami] */
    public static function parseFrom(string $from): array
    {
        if (preg_match('/^(.*)<\s*([^<>\s]+@[^<>\s]+)\s*>/s', $from, $m)) {
            return [trim(trim($m[1]), "\"' "), strtolower(trim($m[2]))];
        }
        if (preg_match('/([^\s<>"]+@[^\s<>"]+)/', $from, $m)) return ['', strtolower(trim($m[1], '.,;'))];
        return [trim($from), ''];
    }

    public static function decodeMime(string $v): string
    {
        if ($v === '') return '';
        $d = function_exists('iconv_mime_decode') ? @iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') : false;
        if ($d === false || $d === '') $d = @mb_decode_mimeheader($v);
        $d = (string)$d;
        if (!mb_check_encoding($d, 'UTF-8')) $d = (string)mb_convert_encoding($d, 'UTF-8', 'ISO-8859-2');
        return trim((string)preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $d));
    }

    public static function decodeName(string $raw): string
    {
        $d = @mb_convert_encoding($raw, 'UTF-8', 'UTF7-IMAP');
        return is_string($d) && $d !== '' ? $d : $raw;
    }

    /** Zwarty zbiór UID-ów: 1,2,3,7 → "1:3,7". @param list<int> $uids */
    public static function uidSet(array $uids): string
    {
        $uids = array_values(array_unique(array_map('intval', $uids)));
        sort($uids);
        $parts = [];
        for ($i = 0, $n = count($uids); $i < $n; $i++) {
            $start = $uids[$i];
            while ($i + 1 < $n && $uids[$i + 1] === $uids[$i] + 1) $i++;
            $parts[] = $start === $uids[$i] ? (string)$start : $start . ':' . $uids[$i];
        }
        return implode(',', $parts);
    }

    // ── Protokół ───────────────────────────────────────────────────────────────────────────────

    private function nextTag(): string { return 'G' . (++$this->tag); }

    /** @return list<array{0:string, 1:list<string>}> odpowiedzi nietagowane [linia z \0L{n}\0 w miejscu literałów, literały] */
    private function command(string $cmd): array
    {
        $tag = $this->nextTag();
        $this->write($tag . ' ' . $cmd . "\r\n");
        return $this->readTagged($tag);
    }

    private function readTagged(string $tag): array
    {
        $untagged = [];
        while (true) {
            [$line, $lits] = $this->readLine();
            if (str_starts_with($line, $tag . ' ')) {
                $rest = substr($line, strlen($tag) + 1);
                if (strncasecmp($rest, 'OK', 2) === 0) return $untagged;
                throw new GosposiaImapCommandFailed('Serwer poczty odrzucił polecenie: ' . mb_substr($rest, 0, 160));
            }
            if (str_starts_with($line, '* BYE')) throw new GosposiaImapUnavailable('Serwer poczty zakończył połączenie.');
            $untagged[] = [$line, $lits];
        }
    }

    /** Jedna logiczna odpowiedź; literały {n} wycięte do tablicy, w linii znacznik \0L{indeks}\0. @return array{0:string, 1:list<string>} */
    private function readLine(): array
    {
        if (!$this->sock) throw new GosposiaImapUnavailable('Brak połączenia z serwerem poczty.');
        $line = '';
        $lits = [];
        while (true) {
            $part = fgets($this->sock, 65536);
            if ($part === false) {
                $meta = stream_get_meta_data($this->sock);
                throw new GosposiaImapUnavailable(!empty($meta['timed_out']) ? 'Serwer poczty nie odpowiada (limit czasu).' : 'Połączenie z serwerem poczty zostało przerwane.');
            }
            $line .= $part;
            if (!str_ends_with($line, "\n")) continue;
            if (preg_match('/\{(\d+)\}\r?\n$/', $line, $m)) {
                $line = (string)preg_replace('/\{\d+\}\r?\n$/', '', $line);
                $need = (int)$m[1];
                $lit = '';
                while (strlen($lit) < $need) {
                    $chunk = fread($this->sock, $need - strlen($lit));
                    if ($chunk === false || $chunk === '') throw new GosposiaImapUnavailable('Połączenie z serwerem poczty zostało przerwane.');
                    $lit .= $chunk;
                }
                $line .= "\x00L" . count($lits) . "\x00";
                $lits[] = $lit;
                continue;
            }
            return [rtrim($line, "\r\n"), $lits];
        }
    }

    private function write(string $data): void
    {
        if (!$this->sock) throw new GosposiaImapUnavailable('Brak połączenia z serwerem poczty.');
        for ($off = 0, $len = strlen($data); $off < $len; ) {
            $n = @fwrite($this->sock, substr($data, $off, 65536));
            if ($n === false || $n === 0) throw new GosposiaImapUnavailable('Połączenie z serwerem poczty zostało przerwane.');
            $off += $n;
        }
    }

    private function quote(string $s): string { return '"' . addcslashes($s, "\"\\") . '"'; }

    /** Nazwa z LIST: "cytowana", atom albo literał. */
    private function atom(string $tok, array $lits): string
    {
        if (preg_match('/^\x00L(\d+)\x00$/', $tok, $m)) return (string)($lits[(int)$m[1]] ?? '');
        if (strlen($tok) >= 2 && $tok[0] === '"' && substr($tok, -1) === '"') return stripcslashes(substr($tok, 1, -1));
        return $tok;
    }
}
