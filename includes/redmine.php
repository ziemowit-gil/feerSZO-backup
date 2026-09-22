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
