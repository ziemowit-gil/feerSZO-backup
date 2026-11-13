<?php
declare(strict_types=1);

/**
 * modules/sso_logto/lib/GraphDrive.php — odczyt plików OneDrive z Microsoft 365 (Graph, app-only) do kopiowania do chmury.
 *
 * Token jak w DhostingGraphMail: client_credentials z ustawień M365 (m365_tenant_id, m365_graph_client_id,
 * m365_graph_client_secret). Uprawnienie aplikacji: Files.Read.All (application) ze zgodą administratora — bez niego 403.
 * Testy: stałe SSO_GRAPH_BASE / SSO_LOGIN_BASE przestawiają adresy na atrapę.
 */
require_once dirname(__DIR__, 3) . '/includes/m365.php';

class GraphDriveException extends RuntimeException {}
final class GraphDriveUnavailable extends GraphDriveException {}
final class GraphDriveDenied extends GraphDriveException {}
final class GraphDriveGone extends GraphDriveException {}

final class GraphDrive
{
    private ?string $token = null;
    private int $tokenExp = 0;
    private string $base;
    private string $loginBase;

    public function __construct()
    {
        $this->base = rtrim(defined('SSO_GRAPH_BASE') ? (string)SSO_GRAPH_BASE : 'https://graph.microsoft.com/v1.0', '/');
        $this->loginBase = rtrim(defined('SSO_LOGIN_BASE') ? (string)SSO_LOGIN_BASE : 'https://login.microsoftonline.com', '/');
    }

    public static function configured(): bool
    {
        return m365_setting('m365_enabled') === '1' && m365_setting('m365_graph_client_id') !== ''
            && m365_setting('m365_graph_client_secret') !== '' && (m365_setting('m365_tenant_id') !== '' || (defined('MS_TENANT_ID') && MS_TENANT_ID !== ''));
    }

    /**
     * Jedna strona zawartości folderu. $itemId = 'root' albo id folderu; $next = @odata.nextLink z poprzedniej strony.
     * @return array{items: list<array{id:string,name:string,folder:bool,size:int,mtime:int,skip:bool}>, next: ?string}
     */
    public function children(string $user, string $itemId, ?string $next = null): array
    {
        $u = rawurlencode($user);
        $url = $next ?: $this->base . '/users/' . $u . '/drive/' . ($itemId === 'root' ? 'root' : 'items/' . rawurlencode($itemId))
            . '/children?$top=200&$select=id,name,size,folder,file,package,remoteItem,lastModifiedDateTime';
        $j = $this->getJson($url);
        $items = [];
        foreach ((array)($j['value'] ?? []) as $it) {
            $items[] = [
                'id'     => (string)($it['id'] ?? ''),
                'name'   => (string)($it['name'] ?? ''),
                'folder' => isset($it['folder']),
                'size'   => (int)($it['size'] ?? 0),
                'mtime'  => !empty($it['lastModifiedDateTime']) ? (int)strtotime((string)$it['lastModifiedDateTime']) : 0,
                // notesy OneNote (package) i skróty do cudzych plików (remoteItem) nie są zwykłymi plikami — pomijamy
                'skip'   => isset($it['package']) || isset($it['remoteItem']) || (!isset($it['folder']) && !isset($it['file'])),
            ];
        }
        return ['items' => $items, 'next' => isset($j['@odata.nextLink']) ? (string)$j['@odata.nextLink'] : null];
    }

    /** Pobiera plik do $file (strumieniowo, za przekierowaniem na adres pobierania). Zwraca rozmiar. */
    public function download(string $user, string $itemId, string $file): int
    {
        $url = $this->base . '/users/' . rawurlencode($user) . '/drive/items/' . rawurlencode($itemId) . '/content';
        for ($try = 0; ; $try++) {
            $fh = fopen($file, 'wb');
            if (!$fh) throw new RuntimeException('Nie można utworzyć pliku tymczasowego.');
            $retryAfter = 0;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 3600,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->token()],
                CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$retryAfter) {
                    if (stripos($h, 'Retry-After:') === 0) $retryAfter = (int)trim(substr($h, 12));
                    return strlen($h);
                },
            ]);
            $ok = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            unset($ch);
            fclose($fh);
            if ($ok === false) throw new GraphDriveUnavailable('Microsoft 365 niedostępny: ' . $err);
            if ($code >= 200 && $code < 300) return (int)filesize($file);
            $body = (string)@file_get_contents($file, false, null, 0, 2000);
            if ($code === 401 && $try === 0) { $this->token = null; continue; }
            if ($this->retryable($code, $retryAfter, $try)) continue;
            $this->fail($code, $body, $retryAfter);
        }
    }

    private function getJson(string $url): array
    {
        for ($try = 0; ; $try++) {
            $retryAfter = 0;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 60,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->token(), 'Accept: application/json'],
                CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$retryAfter) {
                    if (stripos($h, 'Retry-After:') === 0) $retryAfter = (int)trim(substr($h, 12));
                    return strlen($h);
                },
            ]);
            $raw = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            unset($ch);
            if ($raw === false) throw new GraphDriveUnavailable('Microsoft 365 niedostępny: ' . $err);
            if ($code >= 200 && $code < 300) {
                $j = json_decode((string)$raw, true);
                if (!is_array($j)) throw new GraphDriveUnavailable('Microsoft 365: nieczytelna odpowiedź.');
                return $j;
            }
            if ($code === 401 && $try === 0) { $this->token = null; continue; }
            if ($this->retryable($code, $retryAfter, $try)) continue;
            $this->fail($code, (string)$raw, $retryAfter);
        }
    }

    private function retryable(int $code, int $retryAfter, int $try): bool
    {
        if (!in_array($code, [429, 503, 504], true) || $try >= 3) return false;
        $wait = $retryAfter > 0 ? $retryAfter : 5 * ($try + 1);
        if ($wait > 30) return false;
        sleep($wait);
        return true;
    }

    private function fail(int $code, string $body, int $retryAfter): never
    {
        $j = json_decode($body, true);
        $msg = is_array($j) ? (string)($j['error']['message'] ?? $j['error']['code'] ?? '') : '';
        $msg = mb_substr($msg !== '' ? $msg : 'HTTP ' . $code, 0, 300);
        if ($code === 404) throw new GraphDriveGone('Microsoft 365: nie znaleziono (' . $msg . ').');
        if ($code === 401 || $code === 403) {
            throw new GraphDriveDenied('Microsoft 365 odmówił dostępu do plików (' . $code . ': ' . $msg . '). Aplikacja SZO potrzebuje uprawnienia aplikacji Files.Read.All ze zgodą administratora.');
        }
        if ($code === 400 || $code === 410) throw new GraphDriveException('Microsoft 365: niepoprawne zapytanie (' . $code . ': ' . $msg . ').');
        throw new GraphDriveUnavailable('Microsoft 365 chwilowo niedostępny (' . $code . ': ' . $msg . ')' . ($retryAfter > 0 ? ', Retry-After ' . $retryAfter . ' s' : '') . '.');
    }

    private function token(): string
    {
        if ($this->token !== null && time() < $this->tokenExp - 60) return $this->token;
        $tenant = m365_setting('m365_tenant_id') ?: (defined('MS_TENANT_ID') ? (string)MS_TENANT_ID : '');
        $ch = curl_init($this->loginBase . '/' . rawurlencode($tenant) . '/oauth2/v2.0/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 30,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'client_credentials', 'client_id' => m365_setting('m365_graph_client_id'),
                'client_secret' => m365_setting('m365_graph_client_secret'), 'scope' => 'https://graph.microsoft.com/.default',
            ]),
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        unset($ch);
        if ($raw === false || $code >= 500) throw new GraphDriveUnavailable('Logowanie Microsoft niedostępne: ' . ($err ?: 'HTTP ' . $code));
        $j = json_decode((string)$raw, true);
        if (empty($j['access_token'])) throw new GraphDriveDenied('Microsoft odrzucił dane aplikacji SZO: ' . mb_substr((string)($j['error_description'] ?? $j['error'] ?? 'HTTP ' . $code), 0, 200));
        $this->token = (string)$j['access_token'];
        $this->tokenExp = time() + (int)($j['expires_in'] ?? 3600);
        return $this->token;
    }
}
