<?php
declare(strict_types=1);

/**
 * modules/mailImap/lib/ImapReader.php — lekki klient IMAP4rev1 (RFC 3501) do ODCZYTU skrzynki: wspólna funkcjonalność
 * (pierwszy użytkownik: moduł Zadań — odpowiedzi e-mailem i skrzynka obszaru, polecenie 2026-11-13 „w zadaniach wywal
 * Microsoft”, zamiast Microsoft Graph).
 *
 * Bez ext-imap (usunięte z rdzenia PHP 8.4, brak w obrazach Docker) — gniazdo ssl:// (domyślnie, z weryfikacją certyfikatu),
 * tcp:// + STARTTLS albo czyste tcp:// (tylko localhost — atrapy w testach). Polecenia: CAPABILITY, LOGIN, SELECT, UID SEARCH,
 * UID FETCH (BODY.PEEK[] — nie oznacza jako przeczytane), UID STORE +FLAGS, LOGOUT. Nigdy nie usuwa wiadomości.
 *
 * Bezpieczeństwo: hasło nie trafia do wyjątków ani dzienników.
 * Wyjątki: ImapUnavailable (sieć, limit czasu, BYE), ImapAuthFailed (złe hasło), ImapCommandFailed (NO/BAD).
 */
class ImapException extends RuntimeException {}
final class ImapUnavailable extends ImapException {}
final class ImapAuthFailed extends ImapException {}
final class ImapCommandFailed extends ImapException {}

final class ImapReader
{
    public const SECURITY = ['ssl' => 'SSL/TLS (port 993)', 'starttls' => 'STARTTLS (port 143)'];
    /** Największa wiadomość pobierana w całości (większe są pomijane przez wywołującego). */
    public const MAX_BYTES = 26214400;   // 25 MB

    /** @var resource|null */
    private $sock = null;
    private int $tag = 0;

    public function __construct(private string $host, private int $port = 993, private string $security = 'ssl', private int $timeout = 30)
    {
        $this->host = strtolower(trim($host));
        if (!isset(self::SECURITY[$this->security]) && !($this->security === 'none' && self::isLocal($this->host))) {
            throw new ImapException('Nieznany sposób szyfrowania połączenia IMAP.');
        }
    }

    /** Czyste tcp:// tylko dla atrap na tym komputerze. */
    public static function isLocal(string $host): bool
    {
        return in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
    }

    public function connect(): void
    {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $this->host, 'SNI_enabled' => true]]);
        $scheme = $this->security === 'ssl' ? 'ssl' : 'tcp';
        $errno = 0; $errstr = '';
        $s = @stream_socket_client($scheme . '://' . $this->host . ':' . $this->port, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$s) throw new ImapUnavailable('Nie udało się połączyć z serwerem poczty ' . $this->host . ':' . $this->port . '.');
        stream_set_timeout($s, $this->timeout);
        $this->sock = $s;
        [$greet] = $this->readLine();
        if (!str_starts_with($greet, '* OK') && !str_starts_with($greet, '* PREAUTH')) {
            throw new ImapUnavailable('Serwer poczty odpowiedział nieoczekiwanie.');
        }
        if ($this->security === 'starttls') {
            $this->command('STARTTLS');
            if (!@stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new ImapUnavailable('Nie udało się włączyć szyfrowania (STARTTLS) na ' . $this->host . '.');
            }
        }
    }

    public function login(string $user, string $pass): void
    {
        if ($user === '' || $pass === '') throw new ImapAuthFailed('Brak loginu albo hasła do skrzynki.');
        $tag = $this->nextTag();
        // Hasło spoza ASCII albo z cudzysłowem — jako literał (bez wpisywania go do komunikatów)
        $this->write($tag . ' LOGIN ' . $this->astring($user) . ' ');
        if (preg_match('/^[\x20-\x21\x23-\x5b\x5d-\x7e]*$/', $pass)) {
            $this->write('"' . $pass . "\"\r\n");
        } else {
            $this->write('{' . strlen($pass) . "}\r\n");
            [$cont] = $this->readLine();
            if (!str_starts_with($cont, '+')) throw new ImapAuthFailed('Serwer poczty odrzucił logowanie.');
            $this->write($pass . "\r\n");
        }
        try {
            $this->readTagged($tag);
        } catch (ImapCommandFailed $e) {
            throw new ImapAuthFailed('Serwer poczty odrzucił login albo hasło (' . $user . ').');
        }
    }

    /** SELECT; zwraca ['exists' => n, 'uidvalidity' => n, 'uidnext' => n]. */
    public function select(string $mailbox = 'INBOX'): array
    {
        $out = ['exists' => 0, 'uidvalidity' => 0, 'uidnext' => 0];
        foreach ($this->command('SELECT ' . $this->astring($mailbox)) as [$line]) {
            if (preg_match('/^\* (\d+) EXISTS/i', $line, $m)) $out['exists'] = (int)$m[1];
            if (preg_match('/\[UIDVALIDITY (\d+)\]/i', $line, $m)) $out['uidvalidity'] = (int)$m[1];
            if (preg_match('/\[UIDNEXT (\d+)\]/i', $line, $m)) $out['uidnext'] = (int)$m[1];
        }
        return $out;
    }

    /** UID-y wiadomości większych niż $afterUid (rosnąco). */
    public function uidsAfter(int $afterUid): array
    {
        $uids = [];
        foreach ($this->command('UID SEARCH UID ' . ($afterUid + 1) . ':*') as [$line]) {
            if (preg_match('/^\* SEARCH\b(.*)$/i', $line, $m)) {
                foreach (preg_split('/\s+/', trim($m[1])) as $u) if ($u !== '' && (int)$u > $afterUid) $uids[] = (int)$u;
            }
        }
        sort($uids);
        return array_values(array_unique($uids));
    }

    /** Rozmiary wiadomości: [uid => bajty]. */
    public function sizes(array $uids): array
    {
        if (!$uids) return [];
        $out = [];
        foreach ($this->command('UID FETCH ' . self::uidSet($uids) . ' (UID RFC822.SIZE)') as [$line]) {
            if (preg_match('/UID (\d+)/i', $line, $u) && preg_match('/RFC822\.SIZE (\d+)/i', $line, $s)) $out[(int)$u[1]] = (int)$s[1];
        }
        return $out;
    }

    /** Cała wiadomość (RFC 822) bez oznaczania jako przeczytana; null, gdy jej nie ma. */
    public function fetchRaw(int $uid): ?string
    {
        foreach ($this->command('UID FETCH ' . $uid . ' (UID BODY.PEEK[])') as [$line, $lits]) {
            if (preg_match('/^\* \d+ FETCH/i', $line) && $lits) return (string)$lits[0];
        }
        return null;
    }

    /** Dodaje flagę (np. \Seen) wybranym wiadomościom. */
    public function addFlag(array $uids, string $flag = '\\Seen'): void
    {
        if (!$uids || !preg_match('/^\\\\?[A-Za-z$]+$/', $flag)) return;
        $this->command('UID STORE ' . self::uidSet($uids) . ' +FLAGS.SILENT (' . $flag . ')');
    }

    public function logout(): void
    {
        if (!$this->sock) return;
        try { $this->command('LOGOUT'); } catch (\Throwable $e) {}
        @fclose($this->sock);
        $this->sock = null;
    }

    public function __destruct()
    {
        if ($this->sock) @fclose($this->sock);
    }

    /** Zbiór UID-ów w zapisie IMAP (1,3:5,9). */
    public static function uidSet(array $uids): string
    {
        $uids = array_values(array_unique(array_map('intval', $uids)));
        sort($uids);
        $out = []; $start = $prev = null;
        foreach ($uids as $u) {
            if ($start === null) { $start = $prev = $u; continue; }
            if ($u === $prev + 1) { $prev = $u; continue; }
            $out[] = $start === $prev ? (string)$start : $start . ':' . $prev;
            $start = $prev = $u;
        }
        if ($start !== null) $out[] = $start === $prev ? (string)$start : $start . ':' . $prev;
        return implode(',', $out);
    }

    // ── protokół ─────────────────────────────────────────────────────────────────────────────

    private function nextTag(): string
    {
        return 'Z' . (++$this->tag);
    }

    /** Polecenie z tagiem; zwraca odpowiedzi nieoznaczone [[linia, literały]]. */
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
                if (stripos($rest, 'OK') === 0) return $untagged;
                throw new ImapCommandFailed('Serwer poczty odmówił: ' . mb_substr(preg_replace('/^(NO|BAD)\s*/i', '', $rest), 0, 200));
            }
            $untagged[] = [$line, $lits];
        }
    }

    /** Jedna odpowiedź serwera; literały {n} wczytywane w całości. @return array{0:string,1:list<string>} */
    private function readLine(): array
    {
        if (!$this->sock) throw new ImapUnavailable('Brak połączenia z serwerem poczty.');
        $line = ''; $lits = [];
        while (true) {
            $chunk = fgets($this->sock, 65536);
            if ($chunk === false) {
                $meta = stream_get_meta_data($this->sock);
                throw new ImapUnavailable(!empty($meta['timed_out']) ? 'Serwer poczty nie odpowiedział na czas.' : 'Serwer poczty zerwał połączenie.');
            }
            $line .= $chunk;
            if (!str_ends_with($chunk, "\n")) continue;
            $trim = rtrim($line, "\r\n");
            if (preg_match('/\{(\d+)\}$/', $trim, $m)) {
                $n = (int)$m[1];
                if ($n > self::MAX_BYTES + 1048576) throw new ImapUnavailable('Wiadomość jest za duża do pobrania.');
                $buf = '';
                while (strlen($buf) < $n) {
                    $d = fread($this->sock, min(65536, $n - strlen($buf)));
                    if ($d === false || $d === '') {
                        $meta = stream_get_meta_data($this->sock);
                        if (!empty($meta['timed_out']) || feof($this->sock)) throw new ImapUnavailable('Serwer poczty przerwał przesyłanie wiadomości.');
                        continue;
                    }
                    $buf .= $d;
                }
                $lits[] = $buf;
                $line = preg_replace('/\{\d+\}$/', '{}', $trim);
                continue;
            }
            return [$trim, $lits];
        }
    }

    private function write(string $data): void
    {
        if (!$this->sock || @fwrite($this->sock, $data) === false) throw new ImapUnavailable('Serwer poczty zerwał połączenie.');
    }

    private function astring(string $s): string
    {
        return '"' . addcslashes($s, "\"\\") . '"';
    }
}
