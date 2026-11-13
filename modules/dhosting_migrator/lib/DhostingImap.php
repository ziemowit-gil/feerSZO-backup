<?php
declare(strict_types=1);

/**
 * modules/dhosting_migrator/lib/DhostingImap.php — minimalny klient IMAP4rev1 (RFC 3501) do wgrywania poczty na dhosting.
 *
 * Bez rozszerzenia ext-imap (usunięte z rdzenia PHP 8.4, brak w obrazach Docker) — gniazdo ssl:// albo tcp:// i kilka
 * poleceń: LOGIN, LIST, CREATE, SUBSCRIBE, APPEND (literał synchroniczny, strumieniowo z pliku), LOGOUT.
 * Nazwy folderów w modified UTF-7 (mb_convert_encoding 'UTF7-IMAP') — polskie nazwy folderów z Outlooka przechodzą.
 *
 * Wyjątki: DhostingImapUnavailable (brak połączenia, zerwane gniazdo, BYE) — ponawiamy później;
 *          DhostingImapAuthFailed (NO na LOGIN) — złe hasło albo skrzynka jeszcze nieaktywna;
 *          DhostingImapCommandFailed (NO/BAD na innym poleceniu) — np. wiadomość za duża.
 */
class DhostingImapException extends RuntimeException {}
final class DhostingImapUnavailable extends DhostingImapException {}
final class DhostingImapAuthFailed extends DhostingImapException {}
final class DhostingImapCommandFailed extends DhostingImapException {}

final class DhostingImap
{
    /** @var resource|null */
    private $sock = null;
    private int $tag = 0;
    private string $delim = '/';
    private ?string $examined = null;   // folder otwarty tylko do odczytu (EXAMINE) — do porównania przed APPEND

    /** $server: „host” (= ssl://host:993), „host:port” albo pełny adres „ssl://host:993” / „tcp://127.0.0.1:1143” (testy). */
    public function __construct(private string $server, private int $timeout = 30) {}

    public static function address(string $host, int $port = 993): string
    {
        $host = trim($host);
        if ($host === '') return '';
        if (preg_match('~^(ssl|tls|tcp)://~i', $host)) return $host;
        return 'ssl://' . $host . ':' . ($port > 0 ? $port : 993);
    }

    public function connect(): void
    {
        $addr = self::address($this->server);
        $host = (string)parse_url($addr, PHP_URL_HOST);
        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host, 'SNI_enabled' => true,
        ]]);
        $errno = 0; $errstr = '';
        $s = @stream_socket_client($addr, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$s) throw new DhostingImapUnavailable('Brak połączenia z serwerem IMAP ' . $addr . ': ' . ($errstr !== '' ? $errstr : 'błąd ' . $errno));
        stream_set_timeout($s, $this->timeout);
        $this->sock = $s;
        $greet = $this->readLine();
        if (!str_starts_with($greet, '* OK') && !str_starts_with($greet, '* PREAUTH')) {
            throw new DhostingImapUnavailable('Serwer IMAP odpowiedział nieoczekiwanie: ' . mb_substr($greet, 0, 120));
        }
    }

    public function login(string $user, string $pass): void
    {
        try {
            $this->command('LOGIN ' . $this->astring($user) . ' ' . $this->astring($pass), true);
        } catch (DhostingImapCommandFailed $e) {
            throw new DhostingImapAuthFailed('Serwer IMAP odrzucił login lub hasło (' . $e->getMessage() . ').');
        }
    }

    /**
     * Foldery: [['name' => UTF-8, 'raw' => nazwa IMAP, 'attrs' => ['\\Sent', …]], …]; ustawia separator hierarchii.
     */
    public function listFolders(): array
    {
        $out = [];
        foreach ($this->command('LIST "" "*"') as $line) {
            if (!preg_match('/^\* LIST \(([^)]*)\) (NIL|"(?:[^"\\\\]|\\\\.)*") (.+)$/s', $line, $m)) continue;
            if ($m[2] !== 'NIL') $this->delim = stripcslashes(substr($m[2], 1, -1));
            $raw = $this->unquote(trim($m[3]));
            $out[] = [
                'raw'   => $raw,
                'name'  => self::decodeName($raw),
                'attrs' => array_values(array_filter(preg_split('/\s+/', trim($m[1])) ?: [])),
            ];
        }
        return $out;
    }

    public function delimiter(): string { return $this->delim; }

    /** Tworzy folder (nazwa UTF-8, separator już w nazwie); istniejący nie jest błędem. Zwraca nazwę IMAP. */
    public function create(string $nameUtf8): string
    {
        $raw = self::encodeName($nameUtf8);
        try { $this->command('CREATE ' . $this->astring($raw)); }
        catch (DhostingImapCommandFailed $e) {
            if (!preg_match('/ALREADYEXISTS|already exists|exists/i', $e->getMessage())) throw $e;
        }
        try { $this->command('SUBSCRIBE ' . $this->astring($raw)); } catch (DhostingImapCommandFailed $e) { /* bez subskrypcji też działa */ }
        return $raw;
    }

    /**
     * Wgrywa wiadomość z pliku (pełny MIME) do folderu $raw (nazwa IMAP). $flags np. ['\\Seen'], $date = znacznik czasu.
     */
    public function appendFile(string $raw, string $file, array $flags, ?int $date): void
    {
        $size = (int)@filesize($file);
        if ($size <= 0) throw new DhostingImapCommandFailed('pusta wiadomość');
        $fl = $flags ? '(' . implode(' ', $flags) . ') ' : '';
        $dt = $date ? '"' . gmdate('d-M-Y H:i:s', $date) . ' +0000" ' : '';
        $tag = $this->nextTag();
        $this->write($tag . ' APPEND ' . $this->astring($raw) . ' ' . $fl . $dt . '{' . $size . "}\r\n");
        $cont = $this->readLine();
        if (!str_starts_with($cont, '+')) {
            throw new DhostingImapCommandFailed('serwer nie przyjął wiadomości: ' . mb_substr($cont, 0, 160));
        }
        $fh = fopen($file, 'rb');
        if (!$fh) throw new DhostingImapCommandFailed('nie można odczytać pliku tymczasowego');
        try {
            while (!feof($fh)) {
                $chunk = fread($fh, 65536);
                if ($chunk === false) break;
                if ($chunk !== '') $this->write($chunk);
            }
        } finally { fclose($fh); }
        $this->write("\r\n");
        $this->readTagged($tag);
    }

    /**
     * Czy w folderze $raw jest już wiadomość z tym nagłówkiem Message-ID. Folder otwieramy przez EXAMINE (tylko odczyt:
     * nie zmienia flag ani \\Recent), więc porównanie niczego w nowej skrzynce nie rusza. Polecenie odrzucone → wyjątek.
     */
    public function hasMessageId(string $raw, string $messageId): bool
    {
        $messageId = trim($messageId);
        if ($messageId === '' || preg_match('/[\x00-\x1f"\\\\]/', $messageId)) return false;
        if ($this->examined !== $raw) {
            $this->command('EXAMINE ' . $this->astring($raw));
            $this->examined = $raw;
        }
        foreach ($this->command('UID SEARCH HEADER Message-ID ' . $this->astring($messageId)) as $line) {
            if (preg_match('/^\* SEARCH(?: (.*))?$/', $line, $m)) return trim((string)($m[1] ?? '')) !== '';
        }
        return false;
    }

    public function logout(): void
    {
        if (!$this->sock) return;
        try { $this->command('LOGOUT'); } catch (\Throwable $e) {}
        @fclose($this->sock);
        $this->sock = null;
    }

    public function __destruct() { if ($this->sock) @fclose($this->sock); }

    // ── Nazwy folderów ─────────────────────────────────────────────────────────────────────────

    public static function encodeName(string $utf8): string
    {
        return function_exists('mb_convert_encoding') ? (string)mb_convert_encoding($utf8, 'UTF7-IMAP', 'UTF-8') : $utf8;
    }

    public static function decodeName(string $raw): string
    {
        return function_exists('mb_convert_encoding') ? (string)mb_convert_encoding($raw, 'UTF-8', 'UTF7-IMAP') : $raw;
    }

    // ── Protokół ───────────────────────────────────────────────────────────────────────────────

    private function nextTag(): string { return 'D' . (++$this->tag); }

    /** Wysyła polecenie, zwraca linie nietagowane; NO/BAD → DhostingImapCommandFailed. $secret: nie logujemy treści. */
    private function command(string $cmd, bool $secret = false): array
    {
        $tag = $this->nextTag();
        // Literały synchroniczne w poleceniu (astring dla nazw/haseł spoza ASCII) — wysyłamy kawałkami po „+”.
        $parts = preg_split('/(\{\d+\}\r\n)/', $cmd, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$cmd];
        $first = true;
        for ($i = 0; $i < count($parts); $i++) {
            $p = $parts[$i];
            if ($first) { $p = $tag . ' ' . $p; $first = false; }
            if (isset($parts[$i + 1]) && preg_match('/^\{\d+\}\r\n$/', $parts[$i + 1])) {
                $this->write($p . $parts[$i + 1]);
                $i++;
                $cont = $this->readLine();
                if (!str_starts_with($cont, '+')) throw new DhostingImapCommandFailed($secret ? 'odrzucono' : mb_substr($cont, 0, 160));
                continue;
            }
            $this->write($p . "\r\n");
        }
        return $this->readTagged($tag);
    }

    private function readTagged(string $tag): array
    {
        $untagged = [];
        while (true) {
            $line = $this->readLine();
            if (str_starts_with($line, $tag . ' ')) {
                $rest = substr($line, strlen($tag) + 1);
                if (str_starts_with($rest, 'OK')) return $untagged;
                throw new DhostingImapCommandFailed(mb_substr($rest, 0, 200));
            }
            if (str_starts_with($line, '* BYE')) throw new DhostingImapUnavailable('Serwer IMAP zakończył połączenie: ' . mb_substr($line, 0, 160));
            $untagged[] = $line;
        }
    }

    /** Jedna logiczna linia odpowiedzi (z doklejonymi literałami {n}). */
    private function readLine(): string
    {
        if (!$this->sock) throw new DhostingImapUnavailable('Brak połączenia IMAP.');
        $line = '';
        while (true) {
            $part = fgets($this->sock, 65536);
            if ($part === false) {
                $meta = stream_get_meta_data($this->sock);
                throw new DhostingImapUnavailable(!empty($meta['timed_out']) ? 'Serwer IMAP nie odpowiada (limit czasu).' : 'Połączenie IMAP zerwane.');
            }
            $line .= $part;
            if (!str_ends_with($line, "\n")) continue;
            if (preg_match('/\{(\d+)\}\r?\n$/', $line, $m)) {
                $line = (string)preg_replace('/\{\d+\}\r?\n$/', '', $line);
                $need = (int)$m[1];
                $lit = '';
                while (strlen($lit) < $need) {
                    $chunk = fread($this->sock, $need - strlen($lit));
                    if ($chunk === false || $chunk === '') throw new DhostingImapUnavailable('Połączenie IMAP zerwane.');
                    $lit .= $chunk;
                }
                $line .= '"' . addcslashes($lit, "\"\\") . '"';
                continue;
            }
            return rtrim($line, "\r\n");
        }
    }

    private function write(string $data): void
    {
        if (!$this->sock) throw new DhostingImapUnavailable('Brak połączenia IMAP.');
        $len = strlen($data);
        for ($off = 0; $off < $len; ) {
            $n = @fwrite($this->sock, substr($data, $off, 65536));
            if ($n === false || $n === 0) throw new DhostingImapUnavailable('Połączenie IMAP zerwane podczas wysyłania.');
            $off += $n;
        }
    }

    /** Ciąg IMAP: w cudzysłowie, gdy to drukowalne ASCII; inaczej literał {n}. */
    private function astring(string $s): string
    {
        if ($s !== '' && preg_match('/^[\x20-\x7e]*$/', $s)) return '"' . addcslashes($s, "\"\\") . '"';
        return '{' . strlen($s) . "}\r\n" . $s;
    }

    private function unquote(string $s): string
    {
        if (strlen($s) >= 2 && $s[0] === '"' && substr($s, -1) === '"') return stripcslashes(substr($s, 1, -1));
        return $s;
    }
}
