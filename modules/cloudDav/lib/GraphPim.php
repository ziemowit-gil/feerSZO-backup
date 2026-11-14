<?php
declare(strict_types=1);

/**
 * modules/cloudDav/lib/GraphPim.php — odczyt kalendarzy i kontaktów z Microsoft 365 (Graph, app-only) do przeniesienia do chmury.
 *
 * Token jak w GraphDrive i DhostingGraphMail: client_credentials z ustawień M365 (m365_tenant_id, m365_graph_client_id,
 * m365_graph_client_secret). Uprawnienia aplikacji: Calendars.Read i Contacts.Read ze zgodą administratora (14.11.2026 nadane).
 * Czasy wydarzeń prosimy w strefie Europe/Warsaw (Prefer: outlook.timezone), treść jako zwykły tekst (outlook.body-content-type).
 * Testy: stałe PIM_GRAPH_BASE / PIM_LOGIN_BASE (albo SSO_GRAPH_BASE / SSO_LOGIN_BASE) przestawiają adresy na atrapę.
 */
require_once dirname(__DIR__, 3) . '/includes/m365.php';

class PimGraphException extends RuntimeException {}
final class PimGraphUnavailable extends PimGraphException {}
final class PimGraphDenied extends PimGraphException {}
final class PimGraphGone extends PimGraphException {}

final class GraphPim
{
    public const TZ = 'Europe/Warsaw';
    public const EVENT_FIELDS = 'id,iCalUId,subject,body,start,end,isAllDay,location,categories,sensitivity,showAs,isCancelled,'
        . 'isReminderOn,reminderMinutesBeforeStart,organizer,attendees,recurrence,type,seriesMasterId,originalStart,onlineMeeting,'
        . 'createdDateTime,lastModifiedDateTime';
    public const CONTACT_FIELDS = 'id,displayName,givenName,middleName,surname,nickName,title,emailAddresses,businessPhones,homePhones,'
        . 'mobilePhone,jobTitle,companyName,department,homeAddress,businessAddress,otherAddress,birthday,personalNotes,businessHomePage,'
        . 'categories,lastModifiedDateTime';

    private ?string $token = null;
    private int $tokenExp = 0;
    private string $base;
    private string $loginBase;

    public function __construct()
    {
        $g = defined('PIM_GRAPH_BASE') ? (string)PIM_GRAPH_BASE : (defined('SSO_GRAPH_BASE') ? (string)SSO_GRAPH_BASE : 'https://graph.microsoft.com/v1.0');
        $l = defined('PIM_LOGIN_BASE') ? (string)PIM_LOGIN_BASE : (defined('SSO_LOGIN_BASE') ? (string)SSO_LOGIN_BASE : 'https://login.microsoftonline.com');
        $this->base = rtrim($g, '/');
        $this->loginBase = rtrim($l, '/');
    }

    public static function configured(): bool
    {
        return m365_setting('m365_graph_client_id') !== '' && m365_setting('m365_graph_client_secret') !== ''
            && (m365_setting('m365_tenant_id') !== '' || (defined('MS_TENANT_ID') && MS_TENANT_ID !== ''));
    }

    /** @return list<array{id:string,name:string,default:bool,color:string,owner:string}> */
    public function calendars(string $upn): array
    {
        $out = [];
        $url = $this->user($upn) . '/calendars?$top=100&$select=id,name,hexColor,isDefaultCalendar,canEdit,owner';
        while ($url) {
            $j = $this->getJson($url);
            foreach ((array)($j['value'] ?? []) as $c) {
                $out[] = ['id' => (string)$c['id'], 'name' => (string)($c['name'] ?? ''), 'default' => !empty($c['isDefaultCalendar']),
                          'color' => (string)($c['hexColor'] ?? ''), 'owner' => strtolower((string)($c['owner']['address'] ?? ''))];
            }
            $url = $j['@odata.nextLink'] ?? null;
        }
        return $out;
    }

    /** Jedna strona wydarzeń kalendarza (pojedyncze i serie; wyjątki serii przez instances()). @return array{items:list<array>, next:?string} */
    public function events(string $upn, string $calendarId, ?string $next = null): array
    {
        $url = $next ?: $this->user($upn) . '/calendars/' . rawurlencode($calendarId) . '/events?$top=50&$select=' . self::EVENT_FIELDS;
        $j = $this->getJson($url, true);
        return ['items' => array_values((array)($j['value'] ?? [])), 'next' => $j['@odata.nextLink'] ?? null];
    }

    /** Wystąpienia serii w oknie (UTC, 'Y-m-d\TH:i:s'), wszystkie strony; typy occurrence i exception. @return list<array> */
    public function instances(string $upn, string $masterId, string $fromUtc, string $toUtc): array
    {
        $out = [];
        $url = $this->user($upn) . '/events/' . rawurlencode($masterId) . '/instances?startDateTime=' . rawurlencode($fromUtc . 'Z')
             . '&endDateTime=' . rawurlencode($toUtc . 'Z') . '&$top=200&$select=' . self::EVENT_FIELDS;
        $guard = 0;
        while ($url && $guard++ < 100) {
            $j = $this->getJson($url, true);
            foreach ((array)($j['value'] ?? []) as $e) $out[] = $e;
            $url = $j['@odata.nextLink'] ?? null;
        }
        return $out;
    }

    /** Foldery kontaktów: domyślny (id ''), foldery i ich podfoldery (dwa poziomy). @return list<array{id:string,name:string}> */
    public function contactFolders(string $upn): array
    {
        $out = [['id' => '', 'name' => '']];
        $walk = function (string $url, string $prefix, int $depth) use (&$walk, &$out): void {
            while ($url) {
                $j = $this->getJson($url);
                foreach ((array)($j['value'] ?? []) as $f) {
                    $name = trim($prefix . (string)($f['displayName'] ?? ''));
                    $out[] = ['id' => (string)$f['id'], 'name' => $name];
                    if ($depth < 2) $walk($this->base . '/users/' . rawurlencode($this->lastUpn) . '/contactFolders/' . rawurlencode((string)$f['id'])
                        . '/childFolders?$top=100&$select=id,displayName', $name . ' / ', $depth + 1);
                }
                $url = $j['@odata.nextLink'] ?? null;
            }
        };
        $this->lastUpn = $upn;
        $walk($this->user($upn) . '/contactFolders?$top=100&$select=id,displayName', '', 1);
        return $out;
    }

    private string $lastUpn = '';

    /** Jedna strona kontaktów folderu ('' = folder domyślny). @return array{items:list<array>, next:?string} */
    public function contacts(string $upn, string $folderId, ?string $next = null): array
    {
        $url = $next ?: $this->user($upn) . ($folderId === '' ? '/contacts' : '/contactFolders/' . rawurlencode($folderId) . '/contacts')
            . '?$top=100&$select=' . self::CONTACT_FIELDS;
        $j = $this->getJson($url);
        return ['items' => array_values((array)($j['value'] ?? [])), 'next' => $j['@odata.nextLink'] ?? null];
    }

    private function user(string $upn): string
    {
        return $this->base . '/users/' . rawurlencode($upn);
    }

    private function getJson(string $url, bool $prefer = false): array
    {
        for ($try = 0; ; $try++) {
            $retryAfter = 0;
            $h = ['Authorization: Bearer ' . $this->token(), 'Accept: application/json'];
            if ($prefer) { $h[] = 'Prefer: outlook.timezone="' . self::TZ . '"'; $h[] = 'Prefer: outlook.body-content-type="text"'; }
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $h,
                CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$retryAfter) {
                    if (stripos($line, 'Retry-After:') === 0) $retryAfter = (int)trim(substr($line, 12));
                    return strlen($line);
                },
            ]);
            $raw = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            unset($ch);
            if ($raw === false) throw new PimGraphUnavailable('Microsoft 365 niedostępny: ' . $err);
            if ($code >= 200 && $code < 300) {
                $j = json_decode((string)$raw, true);
                if (!is_array($j)) throw new PimGraphUnavailable('Microsoft 365: nieczytelna odpowiedź.');
                return $j;
            }
            if ($code === 401 && $try === 0) { $this->token = null; continue; }
            if (in_array($code, [429, 503, 504], true) && $try < 3) {
                $wait = $retryAfter > 0 ? $retryAfter : 5 * ($try + 1);
                if ($wait <= 30) { sleep($wait); continue; }
            }
            $j = json_decode((string)$raw, true);
            $msg = mb_substr(is_array($j) ? (string)($j['error']['message'] ?? $j['error']['code'] ?? '') : '', 0, 300) ?: 'HTTP ' . $code;
            $ecode = is_array($j) ? (string)($j['error']['code'] ?? '') : '';
            // konto bez skrzynki Exchange (bez licencji) albo usunięte — ponawianie nic nie da
            if (in_array($ecode, ['MailboxNotEnabledForRESTAPI', 'MailboxNotSupportedForRESTAPI', 'ResourceNotFound', 'Request_ResourceNotFound', 'ErrorInvalidUser'], true)) {
                throw new PimGraphGone('Microsoft 365: konto bez skrzynki albo nie istnieje (' . $ecode . ').');
            }
            if ($code === 404) throw new PimGraphGone('Microsoft 365: nie znaleziono (' . $msg . ').');
            if ($code === 401 || $code === 403) throw new PimGraphDenied('Microsoft 365 odmówił dostępu (' . $code . ': ' . $msg . '). Aplikacja SZO potrzebuje uprawnień aplikacji Calendars.Read i Contacts.Read.');
            if ($code === 400) throw new PimGraphException('Microsoft 365: niepoprawne zapytanie (' . $msg . ').');
            throw new PimGraphUnavailable('Microsoft 365 chwilowo niedostępny (' . $code . ': ' . $msg . ').');
        }
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
        if ($raw === false || $code >= 500) throw new PimGraphUnavailable('Logowanie Microsoft niedostępne: ' . ($err ?: 'HTTP ' . $code));
        $j = json_decode((string)$raw, true);
        if (empty($j['access_token'])) throw new PimGraphDenied('Microsoft odrzucił dane aplikacji SZO: ' . mb_substr((string)($j['error_description'] ?? $j['error'] ?? 'HTTP ' . $code), 0, 200));
        $this->token = (string)$j['access_token'];
        $this->tokenExp = time() + (int)($j['expires_in'] ?? 3600);
        return $this->token;
    }
}
