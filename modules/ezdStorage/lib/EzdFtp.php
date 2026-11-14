<?php
declare(strict_types=1);

/**
 * modules/ezdStorage/lib/EzdFtp.php — serwer FTP na kopie dokumentów EZD (przez cURL: działa bez rozszerzenia ftp,
 * obsługuje FTPS jawne i niejawne).
 *
 * Katalog bazowy: zaczyna się od „/” — ścieżka bezwzględna na serwerze; bez „/” — względem katalogu po zalogowaniu.
 * Wysyłka: najpierw „<plik>.part”, po przesłaniu zmiana nazwy (RNFR/RNTO), więc na serwerze nie zostaje połowa pliku
 * pod właściwą nazwą. Brakujące katalogi powstają same.
 */

final class EzdFtpException extends RuntimeException
{
    /** Serwer nieosiągalny (DNS, połączenie, przekroczony czas, TLS) — wtedy EzdStorage robi przerwę w próbach. */
    public bool $unavailable = false;
}

final class EzdFtp
{
    public const TLS_MODES = ['explicit' => 'FTPS jawne (AUTH TLS, port 21)', 'implicit' => 'FTPS niejawne (zwykle port 990)', 'none' => 'Bez szyfrowania (zwykły FTP)'];

    /** Kody cURL oznaczające, że serwer jest nieosiągalny (a nie, że odrzucił konkretny plik). */
    private const DOWN_CODES = [5, 6, 7, 28, 35, 52, 55, 56];   // proxy/DNS, połączenie, czas, TLS, pusta odpowiedź, wysyłka/odbiór

    public function __construct(
        private string $host,
        private int $port,
        private string $user,
        private string $pass,
        private string $dir = '/',
        private string $tls = 'explicit',
        private bool $passive = true,
        private int $connectTimeout = 8,
    ) {
        if (!isset(self::TLS_MODES[$this->tls])) $this->tls = 'explicit';
        if ($this->port <= 0) $this->port = $this->tls === 'implicit' ? 990 : 21;
    }

    /** Ścieżka na serwerze (do zapisania w bazie): katalog bazowy + ścieżka względna. */
    public function remotePath(string $rel): string
    {
        $segs = self::segments($this->dir . '/' . $rel);
        return (str_starts_with(trim($this->dir), '/') ? '/' : '') . implode('/', $segs);
    }

    /** Wysyła plik pod ścieżkę względną (katalogi powstają same). Zwraca liczbę bajtów. */
    public function put(string $rel, string $file): int
    {
        $size = (int)@filesize($file);
        $fh = @fopen($file, 'rb');
        if (!$fh) throw new EzdFtpException('Nie można otworzyć pliku do wysyłki.');
        $segs = self::segments($rel);
        $name = (string)array_pop($segs);
        $part = $name . '.part';
        $ch = $this->handle(implode('/', array_merge($segs, [$part])));
        curl_setopt_array($ch, [
            CURLOPT_UPLOAD => true, CURLOPT_INFILE => $fh, CURLOPT_INFILESIZE => $size,
            CURLOPT_FTP_CREATE_MISSING_DIRS => CURLFTP_CREATE_DIR_RETRY,
            // Po przesłaniu (w katalogu pliku): stary plik o tej nazwie znika (gdy był), .part dostaje właściwą nazwę.
            CURLOPT_POSTQUOTE => ['*DELE ' . $name, 'RNFR ' . $part, 'RNTO ' . $name],
        ]);
        $this->exec($ch, 'Wysyłka ' . $name);
        $sent = (int)curl_getinfo($ch, CURLINFO_SIZE_UPLOAD_T);
        unset($ch);
        fclose($fh);
        if ($sent !== $size) throw new EzdFtpException('Wysyłka ' . $name . ' niepełna (' . $sent . ' z ' . $size . ' B).');
        return $size;
    }

    /** Pobiera plik ze ścieżki względnej do pliku lokalnego. Zwraca liczbę bajtów. */
    public function get(string $rel, string $toFile): int
    {
        $fh = @fopen($toFile, 'wb');
        if (!$fh) throw new EzdFtpException('Nie można utworzyć pliku tymczasowego.');
        $ch = $this->handle(implode('/', self::segments($rel)));
        curl_setopt($ch, CURLOPT_FILE, $fh);
        try {
            $this->exec($ch, 'Pobranie ' . basename($rel));
        } finally {
            unset($ch);
            fclose($fh);
        }
        return (int)filesize($toFile);
    }

    /** Usuwa plik (ścieżka względna). Brak pliku nie jest błędem. */
    public function delete(string $rel): void
    {
        $ch = $this->handle('', true, true);
        // QUOTE idzie zaraz po zalogowaniu, w katalogu domowym — ścieżka pełna jak w remotePath(); „*” = błąd nie przerywa.
        curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_QUOTE => ['*DELE ' . $this->remotePath($rel)]]);
        $this->exec($ch, 'Usunięcie ' . basename($rel));
        unset($ch);
    }

    /** Test: zapis, odczyt i usunięcie małego pliku w katalogu bazowym. Zwraca opis. */
    public function test(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ezdftp');
        $back = $tmp . '.b';
        $body = 'Test zapisu SZO ' . date('c') . "\n";
        file_put_contents($tmp, $body);
        $rel = '.szo-test-' . bin2hex(random_bytes(4)) . '.txt';
        try {
            $this->put($rel, $tmp);
            $this->get($rel, $back);
            if ((string)@file_get_contents($back) !== $body) throw new EzdFtpException('Plik testowy wrócił z serwera zmieniony.');
            $this->delete($rel);
        } finally {
            @unlink($tmp);
            @unlink($back);
        }
        return 'Serwer ' . $this->host . ':' . $this->port . ' przyjął zapis, odczyt i usunięcie pliku w katalogu ' . ($this->remotePath('') ?: '(domowy)') . '.';
    }

    // ── wewnętrzne ───────────────────────────────────────────────────────────────────────────

    /** Elementy ścieżki bez pustych, „.” i „..” (z „..” nie wyjdziemy poza katalog bazowy). */
    private static function segments(string $path): array
    {
        $out = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $s) {
            $s = str_replace(["\r", "\n", "\0"], '', $s);
            if ($s === '' || $s === '.' || $s === '..') continue;
            $out[] = $s;
        }
        return $out;
    }

    /** Uchwyt cURL do ścieżki względnej względem katalogu bazowego ($home — sam katalog domowy, bez wchodzenia do bazowego). */
    private function handle(string $rel, bool $isDir = false, bool $home = false)
    {
        $host = str_contains($this->host, ':') && !str_starts_with($this->host, '[') ? '[' . $this->host . ']' : $this->host;
        $segs = $home ? [] : array_merge(self::segments($this->dir), self::segments($rel));
        $path = implode('/', array_map('rawurlencode', $segs));
        // %2F na początku = ścieżka bezwzględna (cURL robi wtedy CWD od „/”).
        $abs = !$home && str_starts_with(trim($this->dir), '/') ? '%2F' : '';
        $url = ($this->tls === 'implicit' ? 'ftps' : 'ftp') . '://' . $host . ':' . $this->port . '/' . $abs . $path
             . ($isDir && $path !== '' ? '/' : '');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_USERPWD => $this->user . ':' . $this->pass,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => 1800,
            CURLOPT_LOW_SPEED_LIMIT => 512, CURLOPT_LOW_SPEED_TIME => 60,   // zawieszony transfer, a nie wolne łącze
            CURLOPT_FTP_USE_EPSV => true,
            CURLOPT_USE_SSL => $this->tls === 'none' ? CURLUSESSL_NONE : CURLUSESSL_ALL,
            CURLOPT_FTP_FILEMETHOD => CURLFTPMETHOD_MULTICWD,
        ]);
        if (!$this->passive) curl_setopt($ch, CURLOPT_FTPPORT, '-');
        return $ch;
    }

    private function exec($ch, string $what): void
    {
        $ok = curl_exec($ch);
        if ($ok !== false) return;
        $no = curl_errno($ch);
        $err = trim(curl_error($ch));
        // Hasło nie trafia do komunikatu: cURL podaje tylko kod i opis.
        $e = new EzdFtpException('FTP: ' . $what . ' — ' . ($no === 67 ? 'serwer odrzucił login lub hasło' : $err) . ' (cURL ' . $no . ').');
        $e->unavailable = in_array($no, self::DOWN_CODES, true);
        throw $e;
    }
}
