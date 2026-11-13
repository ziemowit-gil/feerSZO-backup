<?php
declare(strict_types=1);

/**
 * modules/sso_logto/lib/CloudApi.php — łącznik chmury plików Nextcloud / ownCloud (osobny serwer).
 *
 * Konta: OCS Provisioning API v2 (/ocs/v2.php/cloud/users, format JSON, nagłówek OCS-APIRequest) na koncie administratora
 * chmury (najlepiej hasło aplikacji). Oba produkty mają to samo API; różnice (np. e-mail przy zakładaniu w ownCloud)
 * omijamy, ustawiając pola osobnym PUT po założeniu konta.
 * Pliki: WebDAV /remote.php/dav/files/{uid}/… na koncie użytkownika (MKCOL, PUT z X-OC-Mtime) — używa go kopiowanie
 * plików z OneDrive (logic/CloudFilesCopy.php), bo administrator chmury nie może pisać do cudzych plików.
 */

class CloudApiException extends RuntimeException
{
    public int $http = 0;
}
/** Chmura chwilowo niedostępna (sieć, 5xx, 429) — ponowić później. */
final class CloudUnavailable extends CloudApiException {}
/** Logowanie odrzucone (401/403) — bez nowego hasła ponawianie nic nie da. */
final class CloudDenied extends CloudApiException {}
/** Brak miejsca w chmurze (507). */
final class CloudFull extends CloudApiException {}

final class CloudApi
{
    public int $lastHttp = 0;
    public string $lastRaw = '';

    public function __construct(private string $base, private string $adminUser, private string $adminPass, private string $kind = 'nextcloud')
    {
        $this->base = rtrim($base, '/');
    }

    public function base(): string
    {
        return $this->base;
    }

    /** status.php (publiczny): ['productname','versionstring','installed','maintenance']. */
    public function status(): array
    {
        [$code, $raw] = self::http('GET', $this->base . '/status.php', [], null, null);
        $j = json_decode($raw, true);
        if ($code !== 200 || !is_array($j)) throw new CloudUnavailable('Chmura nie odpowiada pod ' . $this->base . ' (HTTP ' . $code . ').');
        return $j;
    }

    /** Test konta administratora: odczyt listy kont (1 pozycja). */
    public function ping(): string
    {
        $st = $this->status();
        $this->ocs('GET', 'users', ['limit' => 1]);
        return trim(($st['productname'] ?? self::label($this->kind)) . ' ' . ($st['versionstring'] ?? $st['version'] ?? ''));
    }

    public static function label(string $kind): string
    {
        return $kind === 'owncloud' ? 'ownCloud' : 'Nextcloud';
    }

    /** Dane konta (id, email, displayname, quota…) albo null, gdy konta nie ma. */
    public function user(string $uid): ?array
    {
        try {
            return $this->ocs('GET', 'users/' . rawurlencode($uid));
        } catch (CloudDenied | CloudUnavailable $e) {
            throw $e;
        } catch (CloudApiException $e) {
            if ($this->lastHttp === 404 || (int)$e->getCode() === 404 || (int)$e->getCode() === 998) return null;
            throw $e;
        }
    }

    public function userExists(string $uid): bool
    {
        return $this->user($uid) !== null;
    }

    /** Nowe konto z hasłem; e-mail, nazwa, limit i grupa ustawiane po założeniu (działa tak samo w Nextcloud i ownCloud). */
    public function createUser(string $uid, string $password, string $display = '', string $email = '', string $group = '', string $quota = ''): void
    {
        $this->ocs('POST', 'users', ['userid' => $uid, 'password' => $password]);
        if ($display !== '') $this->trySet($uid, 'displayname', $display);
        if ($email !== '') $this->trySet($uid, 'email', $email);
        if ($quota !== '') $this->trySet($uid, 'quota', $quota);
        if ($group !== '') {
            try { $this->ocs('POST', 'users/' . rawurlencode($uid) . '/groups', ['groupid' => $group]); }
            catch (CloudApiException $e) {
                // grupy jeszcze nie ma — zakładamy i dodajemy ponownie
                try { $this->ocs('POST', 'groups', ['groupid' => $group]); $this->ocs('POST', 'users/' . rawurlencode($uid) . '/groups', ['groupid' => $group]); }
                catch (\Throwable $e2) { error_log('[sso_cloud] grupa ' . $group . ': ' . $e2->getMessage()); }
            }
        }
    }

    /** Wyłącza konto (pliki zostają; włączenie: PUT users/{uid}/enable). */
    public function disableUser(string $uid): void
    {
        $this->ocs('PUT', 'users/' . rawurlencode($uid) . '/disable');
    }

    public function setPassword(string $uid, string $password): void
    {
        $this->ocs('PUT', 'users/' . rawurlencode($uid), ['key' => 'password', 'value' => $password]);
    }

    private function trySet(string $uid, string $key, string $value): void
    {
        try { $this->ocs('PUT', 'users/' . rawurlencode($uid), ['key' => $key, 'value' => $value]); }
        catch (\Throwable $e) { error_log('[sso_cloud] ' . $key . ' dla ' . $uid . ': ' . $e->getMessage()); }
    }

    /** Wywołanie OCS v2; zwraca ocs.data. Błąd: wyjątek z komunikatem chmury (meta.message), kod = meta.statuscode. */
    public function ocs(string $method, string $path, array $params = []): array
    {
        $url = $this->base . '/ocs/v2.php/cloud/' . $path;
        $body = null;
        if ($method === 'GET') {
            $url .= '?' . http_build_query($params + ['format' => 'json']);
        } else {
            $url .= '?format=json';
            $body = http_build_query($params);
        }
        [$code, $raw] = self::http($method, $url, ['OCS-APIRequest: true', 'Accept: application/json'], $body, [$this->adminUser, $this->adminPass]);
        $this->lastHttp = $code;
        $this->lastRaw = mb_substr($raw, 0, 2000);
        $j = json_decode($raw, true);
        $meta = is_array($j) ? ($j['ocs']['meta'] ?? []) : [];
        $status = (int)($meta['statuscode'] ?? 0);
        if ($code >= 200 && $code < 300 && in_array($status, [100, 200], true)) return (array)($j['ocs']['data'] ?? []);
        $msg = trim((string)($meta['message'] ?? ''));
        if ($code === 0) throw new CloudUnavailable('Chmura niedostępna: ' . $raw);
        if ($code === 401 || ($code === 403 && $status === 997)) {
            $e = new CloudDenied('Chmura odrzuciła logowanie administratora (sprawdź login i hasło aplikacji).', $status);
            $e->http = $code; throw $e;
        }
        if ($code >= 500 || $code === 429) { $e = new CloudUnavailable('Chmura chwilowo niedostępna (HTTP ' . $code . ').', $status); $e->http = $code; throw $e; }
        $e = new CloudApiException(self::label($this->kind) . ': ' . ($msg !== '' ? $msg : 'HTTP ' . $code) . ($status ? ' (kod ' . $status . ')' : ''), $status ?: $code);
        $e->http = $code;
        throw $e;
    }

    // ── WebDAV na koncie użytkownika ───────────────────────────────────────────────────────

    public function davUrl(string $uid, string $path): string
    {
        $parts = array_filter(explode('/', $path), fn($p) => $p !== '');
        return $this->base . '/remote.php/dav/files/' . rawurlencode($uid) . '/' . implode('/', array_map('rawurlencode', $parts));
    }

    /** Folder (z rodzicami). Istniejący folder (405) to nie błąd. */
    public function davMkdirs(string $uid, string $pass, string $path): void
    {
        $acc = '';
        foreach (array_filter(explode('/', $path), fn($p) => $p !== '') as $part) {
            $acc .= '/' . $part;
            [$code, $raw] = self::http('MKCOL', $this->davUrl($uid, $acc), [], null, [$uid, $pass]);
            if (in_array($code, [201, 405], true)) continue;
            $this->davFail($code, $raw, 'Nie można utworzyć folderu ' . $acc);
        }
    }

    /** Wysyła plik (strumieniowo). Zwraca liczbę bajtów. */
    public function davPut(string $uid, string $pass, string $path, string $file, ?int $mtime = null): int
    {
        $size = (int)filesize($file);
        $fh = fopen($file, 'rb');
        if (!$fh) throw new RuntimeException('Nie można otworzyć pliku tymczasowego.');
        $headers = ['Content-Type: application/octet-stream'];
        if ($mtime) $headers[] = 'X-OC-Mtime: ' . $mtime;
        $ch = curl_init($this->davUrl($uid, $path));
        curl_setopt_array($ch, [
            CURLOPT_UPLOAD => true, CURLOPT_INFILE => $fh, CURLOPT_INFILESIZE => $size, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $uid . ':' . $pass, CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 3600,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        unset($ch);
        fclose($fh);
        if ($raw === false) throw new CloudUnavailable('Chmura niedostępna przy wysyłce: ' . $err);
        if (in_array($code, [200, 201, 204], true)) return $size;
        $this->davFail($code, (string)$raw, 'Nie udało się zapisać ' . $path);
    }

    /** Od tej wielkości plik idzie w częściach (duże PUT-y odrzuca serwer WWW/PHP; pliki > 2 GB — polecenie 2026-11-13). */
    public const CHUNK_FROM = 268435456;      // 256 MB
    public const CHUNK_BYTES = 67108864;      // 64 MB (Nextcloud: 5 MB–5 GB na część, najwyżej 10000 części)

    /** Próg i wielkość części — w testach stałe SSO_CHUNK_FROM / SSO_CHUNK_BYTES. */
    public static function chunkFrom(): int { return defined('SSO_CHUNK_FROM') ? (int)SSO_CHUNK_FROM : self::CHUNK_FROM; }
    public static function chunkBytes(): int { return defined('SSO_CHUNK_BYTES') ? (int)SSO_CHUNK_BYTES : self::CHUNK_BYTES; }

    /**
     * Wysyła plik w częściach (WebDAV „chunking v2”: /remote.php/dav/uploads/{uid}/{id}, PUT kolejnych części, MOVE .file → cel).
     * Nextcloud: części 1, 2, 3…; ownCloud: nazwy z przesunięciem (porządek leksykalny). Błąd → sprząta katalog części. Zwraca rozmiar.
     */
    public function davPutChunked(string $uid, string $pass, string $path, string $file, ?int $mtime = null): int
    {
        $size = (int)filesize($file);
        $dest = $this->davUrl($uid, $path);
        $upl = $this->base . '/remote.php/dav/uploads/' . rawurlencode($uid) . '/szo-' . bin2hex(random_bytes(8));
        $auth = [$uid, $pass];
        [$code, $raw] = self::http('MKCOL', $upl, ['Destination: ' . $dest], null, $auth);
        if (!in_array($code, [201, 405], true)) $this->davFail($code, $raw, 'Nie można rozpocząć wysyłki w częściach ' . $path);
        $fh = fopen($file, 'rb');
        if (!$fh) throw new RuntimeException('Nie można otworzyć pliku tymczasowego.');
        try {
            $sent = 0; $n = 0;
            while ($sent < $size) {
                $len = (int)min(self::chunkBytes(), $size - $sent);
                $n++;
                $name = $this->kind === 'owncloud' ? sprintf('%015d', $sent) : (string)$n;
                $left = $len;
                $ch = curl_init($upl . '/' . $name);
                curl_setopt_array($ch, [
                    CURLOPT_UPLOAD => true, CURLOPT_INFILESIZE => $len, CURLOPT_RETURNTRANSFER => true, CURLOPT_USERPWD => $uid . ':' . $pass,
                    CURLOPT_READFUNCTION => function ($c, $fd, $want) use ($fh, &$left) {
                        if ($left <= 0) return '';
                        $d = fread($fh, (int)min($want, $left));
                        if ($d === false) return '';
                        $left -= strlen($d);
                        return $d;
                    },
                    CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream', 'Destination: ' . $dest, 'OC-Total-Length: ' . $size],
                    CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 1800,
                ]);
                $raw = curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err = curl_error($ch);
                unset($ch);
                if ($raw === false) { self::http('DELETE', $upl, [], null, $auth); throw new CloudUnavailable('Chmura niedostępna przy wysyłce części ' . $n . ': ' . $err); }
                if (!in_array($code, [200, 201, 204], true)) { self::http('DELETE', $upl, [], null, $auth); $this->davFail($code, (string)$raw, 'Nie udało się zapisać części ' . $n . ' pliku ' . $path); }
                $sent += $len;
            }
        } finally { fclose($fh); }
        $h = ['Destination: ' . $dest, 'OC-Total-Length: ' . $size, 'Overwrite: T'];
        if ($mtime) $h[] = 'X-OC-Mtime: ' . $mtime;
        [$code, $raw] = self::http('MOVE', $upl . '/.file', $h, null, $auth, 1800);   // składanie dużego pliku trwa
        if (!in_array($code, [200, 201, 204], true)) { self::http('DELETE', $upl, [], null, $auth); $this->davFail($code, $raw, 'Nie udało się złożyć pliku ' . $path); }
        return $size;
    }

    // ── Pliki na koncie API (np. foldery obszarów Zadań) i udostępnienia ──────────────────────
    // Konto API zapisane w SZO jest właścicielem plików; osoby dostają do nich udostępnienia (OCS files_sharing).

    /** Login konta API (właściciela plików zapisywanych przez SZO). */
    public function apiUid(): string
    {
        return $this->adminUser;
    }

    public function ownMkdirs(string $path): void
    {
        $this->davMkdirs($this->adminUser, $this->adminPass, $path);
    }

    /** Wysyła plik na konto API (duży — w częściach). Zwraca liczbę bajtów. */
    public function ownPut(string $path, string $file): int
    {
        return (int)filesize($file) >= self::chunkFrom()
            ? $this->davPutChunked($this->adminUser, $this->adminPass, $path, $file)
            : $this->davPut($this->adminUser, $this->adminPass, $path, $file);
    }

    /** PROPFIND (Depth 0): ['fileid' => int, 'size' => int, 'dir' => bool, 'mime' => string] albo null, gdy nie ma. */
    public function ownStat(string $path): ?array
    {
        $xml = '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop>'
             . '<oc:fileid/><oc:size/><d:getcontentlength/><d:resourcetype/><d:getcontenttype/></d:prop></d:propfind>';
        [$code, $raw] = self::http('PROPFIND', $this->davUrl($this->adminUser, $path), ['Depth: 0'], $xml,
                                   [$this->adminUser, $this->adminPass]);
        if ($code === 404) return null;
        if ($code !== 207) $this->davFail($code, $raw, 'Nie można odczytać ' . $path);
        $tag = fn(string $n) => preg_match('~<[a-z0-9]*:?' . $n . '>([^<]*)</~i', $raw, $m) ? trim($m[1]) : '';
        $dir = (bool)preg_match('~<[a-z0-9]*:?collection\s*/>~i', $raw);
        return ['fileid' => (int)$tag('fileid'), 'size' => (int)($tag('getcontentlength') ?: $tag('size')), 'dir' => $dir, 'mime' => $tag('getcontenttype')];
    }

    /** Pobiera plik z konta API do pliku lokalnego. Zwraca liczbę bajtów. */
    public function ownDownload(string $path, string $toFile): int
    {
        $fh = fopen($toFile, 'wb');
        if (!$fh) throw new RuntimeException('Nie można utworzyć pliku tymczasowego.');
        $ch = curl_init($this->davUrl($this->adminUser, $path));
        curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_USERPWD => $this->adminUser . ':' . $this->adminPass, CURLOPT_CONNECTTIMEOUT => 10,
                                CURLOPT_TIMEOUT => 1800, CURLOPT_FAILONERROR => false]);
        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        unset($ch);
        fclose($fh);
        if ($ok === false) throw new CloudUnavailable('Chmura niedostępna przy pobieraniu: ' . $err);
        if ($code !== 200) $this->davFail($code, (string)@file_get_contents($toFile, false, null, 0, 4000), 'Nie można pobrać ' . $path);
        return (int)filesize($toFile);
    }

    /** Usuwa plik albo folder z konta API (trafia do kosza chmury). Brak pliku to nie błąd. */
    public function ownDelete(string $path): void
    {
        [$code, $raw] = self::http('DELETE', $this->davUrl($this->adminUser, $path), [], null, [$this->adminUser, $this->adminPass]);
        if (in_array($code, [200, 204, 404], true)) return;
        $this->davFail($code, $raw, 'Nie można usunąć ' . $path);
    }

    /** Udostępnienia ścieżki na koncie API: lista ['id','share_type','share_with','permissions']. */
    public function shares(string $path): array
    {
        $d = $this->ocsAt('GET', '/ocs/v2.php/apps/files_sharing/api/v1/shares', ['path' => '/' . trim($path, '/')]);
        return array_values(array_filter(array_map(fn($s) => is_array($s) ? [
            'id' => (int)($s['id'] ?? 0), 'share_type' => (int)($s['share_type'] ?? -1),
            'share_with' => (string)($s['share_with'] ?? ''), 'permissions' => (int)($s['permissions'] ?? 0),
        ] : null, $d)));
    }

    /** Udostępnia ścieżkę osobie (share_type 0) z uprawnieniami (1 odczyt, 2 zmiana, 4 dodawanie, 8 usuwanie, 16 dalsze udostępnianie). */
    public function shareCreate(string $path, string $uid, int $permissions): int
    {
        $d = $this->ocsAt('POST', '/ocs/v2.php/apps/files_sharing/api/v1/shares',
                          ['path' => '/' . trim($path, '/'), 'shareType' => 0, 'shareWith' => $uid, 'permissions' => $permissions]);
        return (int)($d['id'] ?? 0);
    }

    public function shareUpdate(int $id, int $permissions): void
    {
        $this->ocsAt('PUT', '/ocs/v2.php/apps/files_sharing/api/v1/shares/' . $id, ['permissions' => $permissions]);
    }

    public function shareDelete(int $id): void
    {
        $this->ocsAt('DELETE', '/ocs/v2.php/apps/files_sharing/api/v1/shares/' . $id);
    }

    /** OCS spoza /cloud/ (np. files_sharing) — te same zasady błędów co ocs(). */
    private function ocsAt(string $method, string $endpoint, array $params = []): array
    {
        $url = $this->base . $endpoint;
        $body = null;
        if ($method === 'GET' || $method === 'DELETE') {
            $url .= '?' . http_build_query($params + ['format' => 'json']);
        } else {
            $url .= '?format=json';
            $body = http_build_query($params);
        }
        [$code, $raw] = self::http($method, $url, ['OCS-APIRequest: true', 'Accept: application/json'], $body, [$this->adminUser, $this->adminPass]);
        $this->lastHttp = $code;
        $this->lastRaw = mb_substr($raw, 0, 2000);
        $j = json_decode($raw, true);
        $meta = is_array($j) ? ($j['ocs']['meta'] ?? []) : [];
        $status = (int)($meta['statuscode'] ?? 0);
        if ($code >= 200 && $code < 300 && in_array($status, [100, 200], true)) return (array)($j['ocs']['data'] ?? []);
        $msg = trim((string)($meta['message'] ?? ''));
        if ($code === 0) throw new CloudUnavailable('Chmura niedostępna: ' . $raw);
        if ($code === 401) { $e = new CloudDenied('Chmura odrzuciła logowanie konta API.', $status); $e->http = $code; throw $e; }
        if ($code >= 500 || $code === 429) { $e = new CloudUnavailable('Chmura chwilowo niedostępna (HTTP ' . $code . ').', $status); $e->http = $code; throw $e; }
        $e = new CloudApiException(self::label($this->kind) . ': ' . ($msg !== '' ? $msg : 'HTTP ' . $code) . ($status ? ' (kod ' . $status . ')' : ''), $status ?: $code);
        $e->http = $code;
        throw $e;
    }

    private function davFail(int $code, string $raw, string $what): never
    {
        $msg = '';
        if (preg_match('~<s:message>(.*?)</s:message>~s', $raw, $m)) $msg = trim(html_entity_decode($m[1]));
        $text = $what . ' (HTTP ' . $code . ($msg !== '' ? ': ' . mb_substr($msg, 0, 200) : '') . ').';
        $e = match (true) {
            $code === 401 || $code === 403 => new CloudDenied($text . ' Chmura odrzuciła hasło użytkownika.'),
            $code === 507                  => new CloudFull($text . ' Brak miejsca w chmurze.'),
            $code === 0 || $code >= 500 || $code === 429 => new CloudUnavailable($text),
            default                        => new CloudApiException($text),
        };
        $e->http = $code;
        throw $e;
    }

    /** @return array{0:int,1:string} [kod HTTP, treść] */
    private static function http(string $method, string $url, array $headers, ?string $body, ?array $auth, int $timeout = 60): array
    {
        $ch = curl_init($url);
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => $timeout,
                 CURLOPT_HTTPHEADER => $headers];
        if ($auth) $opts[CURLOPT_USERPWD] = $auth[0] . ':' . $auth[1];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
            $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        unset($ch);
        return $raw === false ? [0, $err] : [$code, (string)$raw];
    }
}
