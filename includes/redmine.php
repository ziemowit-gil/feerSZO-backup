<?php
/**
 * includes/redmine.php — integracja z Redmine przez REST API.
 *
 * Cienki wrapper na bibliotekę kbsali/redmine-api (NativeCurlClient — bez PSR).
 * Integracja JEDNOKIERUNKOWA: zgłoszenie utworzone w Helpdesku SZO tworzy issue
 * w Redmine i zapisuje jego numer (helpdesk_tickets.redmine_issue_id).
 *
 * Konfiguracja (tabela settings, prefiks redmine_*, GUI: admin/redmine_settings.php):
 *   redmine_enabled             '1' = integracja aktywna
 *   redmine_url                 np. https://feer.usermd.net
 *   redmine_api_key             klucz API (Redmine → Moje konto → Klucz API)
 *   redmine_default_project_id  ID lub identyfikator projektu docelowego
 *   redmine_default_tracker_id  (opcjonalnie) ID trackera (np. Support/Bug)
 *
 * Wzorowane na includes/betterfly.php (settings + logowanie error_log('[redmine]')).
 */

require_once __DIR__ . '/db.php';

$__redmine_autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($__redmine_autoload)) require_once $__redmine_autoload;

class RedmineException extends \RuntimeException {}

// ── OAuth2 per użytkownik (Doorkeeper w Redmine) ─────────────────────────────
// Pozwala wykonywać akcje w Redmine jako KONKRETNY użytkownik (autor = realna
// osoba). Rejestracja aplikacji: Redmine → Administracja → Applications; scope'y
// ustawiane po stronie Redmine. Klucz API (wyżej) zostaje dla crona/webhooka/
// mini-helpdesku (bezgłowe), OAuth dla zalogowanych użytkowników SZO.

/** Tabela tokenów OAuth per użytkownik (samonaprawa). */
function redmine_migrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS redmine_user_tokens (
        user_id         INTEGER PRIMARY KEY,
        redmine_user_id INTEGER NOT NULL DEFAULT 0,
        access_token    TEXT    NOT NULL DEFAULT '',
        refresh_token   TEXT    NOT NULL DEFAULT '',
        expires_at      INTEGER NOT NULL DEFAULT 0,
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME
    )");
}

function redmine_oauth_configured(): bool
{
    return trim(org_setting('redmine_url')) !== ''
        && trim(org_setting('redmine_oauth_client_id')) !== ''
        && trim(org_setting('redmine_oauth_client_secret')) !== '';
}

function redmine_oauth_redirect_uri(): string
{
    return rtrim(APP_URL, '/') . '/auth/redmine_callback.php';
}

function redmine_oauth_authorize_url(string $state): string
{
    $q = http_build_query([
        'response_type' => 'code',
        'client_id'     => trim(org_setting('redmine_oauth_client_id')),
        'redirect_uri'  => redmine_oauth_redirect_uri(),
        'state'         => $state,
    ]);
    return rtrim(trim(org_setting('redmine_url')), '/') . '/oauth/authorize?' . $q;
}

/** Wymiana code→token lub odświeżenie (grant_type). Zwraca dekodowaną odpowiedź. */
function redmine_oauth_token_request(array $params): array
{
    $url = rtrim(trim(org_setting('redmine_url')), '/') . '/oauth/token';
    $params += [
        'client_id'     => trim(org_setting('redmine_oauth_client_id')),
        'client_secret' => trim(org_setting('redmine_oauth_client_secret')),
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
    ]);
    $raw  = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new RedmineException('OAuth: błąd połączenia — ' . $err);
    $data = json_decode((string)$raw, true);
    if ($http < 200 || $http >= 300 || !is_array($data) || empty($data['access_token'])) {
        $msg = is_array($data) ? ($data['error_description'] ?? $data['error'] ?? '') : '';
        throw new RedmineException('OAuth: uzyskanie tokenu nieudane (HTTP ' . $http . ') ' . $msg, $http);
    }
    return $data;
}

/** Zapisuje tokeny użytkownika + dociąga jego redmine_user_id. */
function redmine_oauth_store(int $uid, array $tok): void
{
    redmine_migrate();
    $expires = time() + (int)($tok['expires_in'] ?? 3600);
    $rmUid   = 0;
    // Dociągnij id użytkownika Redmine (autoryzacja tokenem).
    try {
        $me = redmine_user_api_request($uid, 'GET', '/users/current.json', null, [], (string)$tok['access_token']);
        $rmUid = (int)($me['user']['id'] ?? 0);
    } catch (\Throwable $e) { /* zapiszemy bez id */ }

    $exists  = db_one("SELECT user_id FROM redmine_user_tokens WHERE user_id=?", [$uid]);
    $refresh = (string)($tok['refresh_token'] ?? '');
    $now     = date('Y-m-d H:i:s');
    // PK tabeli to user_id (nie id) — dlatego ręczny UPSERT zamiast db_update().
    if ($exists) {
        db()->prepare("UPDATE redmine_user_tokens SET redmine_user_id=?, access_token=?, refresh_token=?, expires_at=?, updated_at=? WHERE user_id=?")
            ->execute([$rmUid, (string)$tok['access_token'], $refresh, $expires, $now, $uid]);
    } else {
        db()->prepare("INSERT INTO redmine_user_tokens (user_id, redmine_user_id, access_token, refresh_token, expires_at, updated_at) VALUES (?,?,?,?,?,?)")
            ->execute([$uid, $rmUid, (string)$tok['access_token'], $refresh, $expires, $now]);
    }
}

function redmine_user_connected(int $uid): bool
{
    redmine_migrate();
    $r = db_one("SELECT access_token FROM redmine_user_tokens WHERE user_id=?", [$uid]);
    return $r && trim((string)$r['access_token']) !== '';
}

/** Rozłącza konto (usuwa tokeny). */
function redmine_user_disconnect(int $uid): void
{
    redmine_migrate();
    db()->prepare("DELETE FROM redmine_user_tokens WHERE user_id=?")->execute([$uid]);
}

/** Zwraca ważny access_token użytkownika (odświeża przez refresh_token). Null gdy brak/niepowodzenie. */
function redmine_user_access_token(int $uid): ?string
{
    redmine_migrate();
    $r = db_one("SELECT * FROM redmine_user_tokens WHERE user_id=?", [$uid]);
    if (!$r || trim((string)$r['access_token']) === '') return null;
    if ((int)$r['expires_at'] > time() + 60) return (string)$r['access_token'];

    // Wygasa/wygasł — odśwież.
    if (trim((string)$r['refresh_token']) === '') return null;
    try {
        $tok = redmine_oauth_token_request([
            'grant_type'    => 'refresh_token',
            'refresh_token' => (string)$r['refresh_token'],
        ]);
    } catch (\Throwable $e) {
        error_log('[redmine] refresh token uid ' . $uid . ': ' . $e->getMessage());
        return null;
    }
    $expires = time() + (int)($tok['expires_in'] ?? 3600);
    db()->prepare("UPDATE redmine_user_tokens SET access_token=?, refresh_token=?, expires_at=?, updated_at=? WHERE user_id=?")
        ->execute([(string)$tok['access_token'], (string)($tok['refresh_token'] ?? $r['refresh_token']), $expires, date('Y-m-d H:i:s'), $uid]);
    return (string)$tok['access_token'];
}

/**
 * Wywołanie REST API Redmine jako użytkownik (Bearer OAuth). $forceToken pozwala
 * użyć świeżo uzyskanego tokenu (np. tuż po wymianie code, przed zapisem).
 * @return array zdekodowana odpowiedź (puste [] dla 204)
 */
function redmine_user_api_request(int $uid, string $method, string $path, ?array $body = null, array $query = [], string $forceToken = ''): array
{
    $token = $forceToken !== '' ? $forceToken : redmine_user_access_token($uid);
    if ($token === null || $token === '') {
        throw new RedmineException('Użytkownik nie ma połączenia OAuth z Redmine.');
    }
    $url = rtrim(trim(org_setting('redmine_url')), '/') . $path;
    if ($query) $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);

    $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
    $payload = null;
    if ($body !== null) { $payload = json_encode($body, JSON_UNESCAPED_UNICODE); $headers[] = 'Content-Type: application/json'; }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    $raw  = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) throw new RedmineException("Redmine (Bearer) {$method} {$path}: {$err}");
    if ($http < 200 || $http >= 300) {
        throw new RedmineException("Redmine (Bearer) {$method} {$path} — HTTP {$http}", $http);
    }
    $data = ($raw === '') ? [] : json_decode((string)$raw, true);
    return is_array($data) ? $data : [];
}

/** Czy integracja Redmine jest aktywna i skonfigurowana. */
function redmine_is_enabled(): bool
{
    return org_setting('redmine_enabled') === '1'
        && trim(org_setting('redmine_url')) !== ''
        && trim(org_setting('redmine_api_key')) !== '';
}

/** Tworzy klienta Redmine (NativeCurlClient). Rzuca RedmineException gdy brak konfiguracji/biblioteki. */
function redmine_client(): \Redmine\Client\NativeCurlClient
{
    $url = rtrim(trim(org_setting('redmine_url')), '/');
    $key = trim(org_setting('redmine_api_key'));
    if ($url === '' || $key === '') {
        throw new RedmineException('Brak adresu URL lub klucza API Redmine.');
    }
    if (!class_exists(\Redmine\Client\NativeCurlClient::class)) {
        throw new RedmineException('Biblioteka kbsali/redmine-api nie jest załadowana (vendor/autoload.php).');
    }
    return new \Redmine\Client\NativeCurlClient($url, $key);
}

/** Bazowy URL do konkretnego issue (pusty gdy brak konfiguracji). */
function redmine_issue_url(int $issue_id): string
{
    $u = rtrim(trim(org_setting('redmine_url')), '/');
    return ($u !== '' && $issue_id > 0) ? $u . '/issues/' . $issue_id : '';
}

/**
 * Tworzy issue w Redmine.
 *
 * @param array $params subject (wymagane), description, project_id (dom. z ustawień),
 *                      tracker_id, priority_id
 * @return array{id:int, url:string}
 */
function redmine_create_issue(array $params): array
{
    $client    = redmine_client();
    $projectId = (int)($params['project_id'] ?? 0) ?: (int)org_setting('redmine_default_project_id');
    if ($projectId <= 0) {
        throw new RedmineException('Nie ustawiono projektu Redmine (redmine_default_project_id).');
    }
    $subject = trim((string)($params['subject'] ?? ''));
    if ($subject === '') {
        throw new RedmineException('Zgłoszenie wymaga tematu (subject).');
    }

    $data = [
        'project_id'  => $projectId,
        'subject'     => $subject,
        'description' => (string)($params['description'] ?? ''),
    ];
    $tracker = (int)($params['tracker_id'] ?? 0) ?: (int)org_setting('redmine_default_tracker_id');
    if ($tracker > 0)  $data['tracker_id']  = $tracker;
    if (!empty($params['priority_id'])) $data['priority_id'] = (int)$params['priority_id'];

    try {
        $res = $client->getApi('issue')->create($data);
    } catch (\Throwable $e) {
        error_log('[redmine] create issue: ' . $e->getMessage());
        throw new RedmineException('Błąd API Redmine przy tworzeniu zgłoszenia: ' . $e->getMessage(), 0, $e);
    }

    $id = ($res instanceof \SimpleXMLElement) ? (int)$res->id : 0;
    if ($id <= 0) {
        $code = 0;
        try { $code = $client->getLastResponseStatusCode(); } catch (\Throwable $e) {}
        error_log('[redmine] brak ID w odpowiedzi create (HTTP ' . $code . ')');
        throw new RedmineException('Redmine nie zwrócił numeru zgłoszenia' . ($code ? " (HTTP {$code})" : '') . '.');
    }

    return ['id' => $id, 'url' => redmine_issue_url($id)];
}

/**
 * Pobiera issue z Redmine wraz z dziennikiem (journals — notatki/zmiany).
 * @return array|null tablica danych issue (bez opakowania 'issue') albo null.
 */
function redmine_get_issue(int $issue_id, array $include = ['journals']): ?array
{
    if ($issue_id <= 0) return null;
    $client = redmine_client();
    try {
        $res = $client->getApi('issue')->show($issue_id, ['include' => $include]);
    } catch (\Throwable $e) {
        error_log('[redmine] get issue #' . $issue_id . ': ' . $e->getMessage());
        throw new RedmineException('Błąd API Redmine przy pobieraniu zgłoszenia #' . $issue_id . ': ' . $e->getMessage(), 0, $e);
    }
    if (is_array($res) && isset($res['issue']) && is_array($res['issue'])) return $res['issue'];
    return is_array($res) ? $res : null;
}

/**
 * Lista trackerów Redmine [id => nazwa]. Pusta tablica przy błędzie/braku konfiguracji.
 */
function redmine_trackers(): array
{
    try {
        $client = redmine_client();
        $names  = $client->getApi('tracker')->listNames();
        return is_array($names) ? $names : [];
    } catch (\Throwable $e) {
        error_log('[redmine] trackers: ' . $e->getMessage());
        return [];
    }
}

/** Mapa kategoria SZO → tracker_id Redmine (settings JSON redmine_category_trackers). */
function redmine_category_tracker_map(): array
{
    $raw = org_setting('redmine_category_trackers');
    $map = $raw ? json_decode($raw, true) : [];
    return is_array($map) ? $map : [];
}

/** Tracker dla kategorii — z mapy, a gdy brak → domyślny tracker. */
function redmine_tracker_for_category(string $category): int
{
    $map = redmine_category_tracker_map();
    $tid = (int)($map[$category] ?? 0);
    return $tid > 0 ? $tid : (int)org_setting('redmine_default_tracker_id');
}

/**
 * Test połączenia — pobiera bieżącego użytkownika po kluczu API.
 * @return array{ok:bool, error?:string, user?:string}
 */
function redmine_test(): array
{
    try {
        $client = redmine_client();
        $me = $client->getApi('user')->getCurrentUser();
        $code = $client->getLastResponseStatusCode();
        if (is_array($me) && !empty($me['user'])) {
            $u = $me['user'];
            return ['ok' => true, 'user' => trim(($u['firstname'] ?? '') . ' ' . ($u['lastname'] ?? '')) ?: ($u['login'] ?? '')];
        }
        return ['ok' => false, 'error' => 'Nieoczekiwana odpowiedź' . ($code ? " (HTTP {$code})" : '') . ' — sprawdź URL i klucz API.'];
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
