<?php
/**
 * includes/nozbe.php — Integracja z Nozbe (https://nozbe.com).
 *
 * Model: organizacja wpisuje JEDEN token API Nozbe (Ustawienia → API tokens
 * w Nozbe) w panelu admina. Z poziomu SZO można wtedy wypchnąć rzecz do zrobienia
 * do Nozbe — zadanie z modułu Zadań, wiadomość ze Skrzynki CRM albo sprawę CRM —
 * i mieć w jednym miejscu to, czym i tak zarządza się w Nozbe.
 *
 * Kierunek jest JEDNOSTRONNY (SZO → Nozbe). Nozbe nie odsyła statusów, więc nie
 * udajemy dwustronnej synchronizacji: zamknięcie zadania w Nozbe nie zamyka go
 * w SZO i odwrotnie. Powiązanie zapisujemy w tabeli `nozbe_links`, żeby drugie
 * kliknięcie nie zrobiło duplikatu i żeby dało się wrócić do zadania w Nozbe.
 *
 * API (stan na 2026-08): baza https://api4.nozbe.com/v1/api
 *   Authorization: apikey <TOKEN>
 *   GET  /projects              — lista projektów
 *   POST /tasks                 — {name, project_id?, responsible_id?, due_at?, is_all_day?}
 *   POST /comments              — {task_id, body}   ← opis idzie komentarzem,
 *                                  bo zadanie w Nozbe nie ma pola opisu
 *
 * Ustawienia (tabela settings):
 *   nozbe_enabled      '1'/'0'  — master switch
 *   nozbe_api_key      token API
 *   nozbe_project_id   domyślny projekt (puste = skrzynka Nozbe)
 *   nozbe_projects     JSON cache listy projektów (po teście połączenia)
 *   nozbe_account      opis konta/zespołu (podgląd w adminie)
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

const NOZBE_API_BASE = 'https://api4.nozbe.com/v1/api';
const NOZBE_APP_URL  = 'https://app.nozbe.com';

// ── Self-migracja: master switch + tabela powiązań ──────────────────────────
(function () {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        if (!db_one("SELECT 1 FROM settings WHERE key_='nozbe_enabled'")) {
            db()->prepare("INSERT INTO settings (key_, value) VALUES ('nozbe_enabled','0')")->execute();
        }
    } catch (\Throwable $e) {}
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS nozbe_links (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            local_type    TEXT    NOT NULL,          -- task | crm_message | crm_case | manual
            local_id      INTEGER,
            nozbe_task_id TEXT    NOT NULL,
            name          TEXT    NOT NULL DEFAULT '',
            project_id    TEXT    NOT NULL DEFAULT '',
            created_by    INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_nozbe_local ON nozbe_links(local_type, local_id)");
    } catch (\Throwable $e) {}
})();

function nozbe_setting(string $key): string {
    try {
        return (string)(db_one("SELECT value FROM settings WHERE key_=?", [$key])['value'] ?? '');
    } catch (\Throwable $e) { return ''; }
}

/** Czy integracja jest włączona i ma token. */
function nozbe_configured(): bool {
    return nozbe_setting('nozbe_enabled') === '1' && trim(nozbe_setting('nozbe_api_key')) !== '';
}

/**
 * Surowe wywołanie API.
 *
 * @param  string     $method GET|POST
 * @param  string     $path   np. '/tasks'
 * @param  array|null $body   ciało żądania (dla POST)
 * @return array ['ok'=>bool, 'status'=>int, 'data'=>array, 'error'=>string]
 */
function nozbe_request(string $method, string $path, ?array $body = null): array {
    $key = trim(nozbe_setting('nozbe_api_key'));
    if ($key === '') return ['ok' => false, 'status' => 0, 'data' => [], 'error' => 'Brak tokenu API Nozbe.'];

    $url  = NOZBE_API_BASE . '/' . ltrim($path, '/');
    $opts = [
        'http' => [
            'method'        => $method,
            'header'        => "Authorization: apikey {$key}\r\n"
                             . "Content-Type: application/json\r\n"
                             . "Accept: application/json\r\n",
            'ignore_errors' => true,
            'timeout'       => 15,
        ],
    ];
    if ($body !== null) $opts['http']['content'] = json_encode($body, JSON_UNESCAPED_UNICODE);

    $raw = @file_get_contents($url, false, stream_context_create($opts));

    $status = 0;
    foreach (($http_response_header ?? []) as $hline) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $hline, $m)) { $status = (int)$m[1]; break; }
    }
    if ($raw === false) {
        return ['ok' => false, 'status' => $status, 'data' => [], 'error' => 'Brak połączenia z API Nozbe.'];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) $data = [];

    if ($status < 200 || $status >= 300) {
        $err = (string)($data['message'] ?? $data['error'] ?? mb_substr(trim($raw), 0, 200));
        if ($status === 401 || $status === 403) $err = 'Nozbe odrzuciło token API (' . $status . '). ' . $err;
        return ['ok' => false, 'status' => $status, 'data' => $data, 'error' => $err ?: 'Błąd API Nozbe (' . $status . ').'];
    }
    return ['ok' => true, 'status' => $status, 'data' => $data, 'error' => ''];
}

/** Lista projektów Nozbe: [['id'=>…, 'name'=>…], …]. */
function nozbe_projects(bool $refresh = false): array {
    if (!$refresh) {
        $cached = json_decode(nozbe_setting('nozbe_projects') ?: '[]', true);
        if (is_array($cached) && $cached) return $cached;
    }
    $r = nozbe_request('GET', '/projects?limit=200');
    if (!$r['ok']) return [];

    $rows = $r['data'];
    if (isset($rows['data']) && is_array($rows['data'])) $rows = $rows['data'];   // gdyby API opakowało listę

    $out = [];
    foreach ($rows as $p) {
        if (!is_array($p) || empty($p['id'])) continue;
        $out[] = ['id' => (string)$p['id'], 'name' => (string)($p['name'] ?? $p['id'])];
    }
    usort($out, static fn($a, $b) => strcasecmp($a['name'], $b['name']));

    try {
        db()->prepare("INSERT INTO settings (key_, value) VALUES ('nozbe_projects', ?)
                       ON CONFLICT(key_) DO UPDATE SET value=excluded.value")
            ->execute([json_encode($out, JSON_UNESCAPED_UNICODE)]);
    } catch (\Throwable $e) {}

    return $out;
}

/** Test połączenia — zwraca ['ok'=>bool, 'error'=>string, 'projects'=>int]. */
function nozbe_test(): array {
    $r = nozbe_request('GET', '/projects?limit=5');
    if (!$r['ok']) return ['ok' => false, 'error' => $r['error'], 'projects' => 0];
    $rows = isset($r['data']['data']) && is_array($r['data']['data']) ? $r['data']['data'] : $r['data'];
    return ['ok' => true, 'error' => '', 'projects' => is_array($rows) ? count($rows) : 0];
}

/**
 * Tworzy zadanie w Nozbe.
 *
 * @param string $name Tytuł (Nozbe przycina do 255 znaków)
 * @param array  $opt  ['project_id'=>string, 'due_at'=>int|null (unix, sekundy),
 *                      'is_all_day'=>bool, 'comment'=>string,
 *                      'local_type'=>string, 'local_id'=>int]
 * @return array ['ok'=>bool, 'error'=>string, 'id'=>string, 'url'=>string]
 */
function nozbe_create_task(string $name, array $opt = []): array {
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    if ($name === '') return ['ok' => false, 'error' => 'Zadanie musi mieć nazwę.', 'id' => '', 'url' => ''];
    $name = mb_substr($name, 0, 255);

    $project = trim((string)($opt['project_id'] ?? nozbe_setting('nozbe_project_id')));

    $body = ['name' => $name];
    if ($project !== '') $body['project_id'] = $project;
    if (!empty($opt['due_at'])) {
        // Nozbe przyjmuje znacznik czasu w milisekundach
        $body['due_at']     = (int)$opt['due_at'] * 1000;
        $body['is_all_day'] = (bool)($opt['is_all_day'] ?? true);
    }

    $r = nozbe_request('POST', '/tasks', $body);
    if (!$r['ok']) return ['ok' => false, 'error' => $r['error'], 'id' => '', 'url' => ''];

    $data = isset($r['data']['data']) && is_array($r['data']['data']) ? $r['data']['data'] : $r['data'];
    $id   = (string)($data['id'] ?? '');
    if ($id === '') return ['ok' => false, 'error' => 'Nozbe nie zwróciło identyfikatora zadania.', 'id' => '', 'url' => ''];

    // Opis idzie komentarzem — zadanie w Nozbe nie ma pola treści
    $comment = trim((string)($opt['comment'] ?? ''));
    if ($comment !== '') {
        nozbe_request('POST', '/comments', ['task_id' => $id, 'body' => mb_substr($comment, 0, 4000)]);
    }

    try {
        db_insert('nozbe_links', [
            'local_type'    => (string)($opt['local_type'] ?? 'manual'),
            'local_id'      => (int)($opt['local_id'] ?? 0) ?: null,
            'nozbe_task_id' => $id,
            'name'          => $name,
            'project_id'    => $project,
            'created_by'    => (int)(current_user()['id'] ?? 0) ?: null,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
    } catch (\Throwable $e) {
        error_log('[nozbe_create_task] log: ' . $e->getMessage());
    }

    return ['ok' => true, 'error' => '', 'id' => $id, 'url' => nozbe_task_url($id)];
}

/** Adres zadania w aplikacji Nozbe. */
function nozbe_task_url(string $task_id): string {
    return NOZBE_APP_URL . '/#task-' . rawurlencode($task_id);
}

/** Powiązanie lokalnego obiektu z zadaniem Nozbe (null = jeszcze nie wysłane). */
function nozbe_link_for(string $local_type, int $local_id): ?array {
    if ($local_id <= 0) return null;
    try {
        $r = db_one("SELECT * FROM nozbe_links WHERE local_type=? AND local_id=? ORDER BY id DESC LIMIT 1",
            [$local_type, $local_id]);
    } catch (\Throwable $e) { return null; }
    if (!$r) return null;
    $r['url'] = nozbe_task_url((string)$r['nozbe_task_id']);
    return $r;
}

// ── Gotowe ścieżki wysyłki ──────────────────────────────────────────────────

/** Zadanie z modułu Zadań → Nozbe. */
function nozbe_push_local_task(int $task_id, string $project_id = ''): array {
    try {
        $t = db_one("SELECT id, title, description, due_date FROM tasks WHERE id=?", [$task_id]);
    } catch (\Throwable $e) { $t = null; }
    if (!$t) return ['ok' => false, 'error' => 'Zadanie nie istnieje.', 'id' => '', 'url' => ''];

    $existing = nozbe_link_for('task', $task_id);
    if ($existing) {
        return ['ok' => true, 'error' => '', 'id' => (string)$existing['nozbe_task_id'],
                'url' => $existing['url'], 'existing' => true];
    }

    $due = !empty($t['due_date']) ? strtotime((string)$t['due_date']) : null;
    return nozbe_create_task((string)$t['title'], [
        'project_id' => $project_id,
        'due_at'     => $due ?: null,
        'comment'    => trim((string)($t['description'] ?? ''))
                        . "\n\nZadanie w SZO: " . APP_URL . '/tasks/detail.php?id=' . $task_id,
        'local_type' => 'task',
        'local_id'   => $task_id,
    ]);
}

/** Wiadomość ze Skrzynki CRM → Nozbe (jako „do zrobienia" z linkiem do wiadomości). */
function nozbe_push_crm_message(int $comm_id, string $project_id = ''): array {
    try {
        $m = db_one("SELECT id, subject, body, from_name, from_email, sent_at, msg_no
                     FROM crm_communications WHERE id=?", [$comm_id]);
    } catch (\Throwable $e) { $m = null; }
    if (!$m) return ['ok' => false, 'error' => 'Wiadomość nie istnieje.', 'id' => '', 'url' => ''];

    $existing = nozbe_link_for('crm_message', $comm_id);
    if ($existing) {
        return ['ok' => true, 'error' => '', 'id' => (string)$existing['nozbe_task_id'],
                'url' => $existing['url'], 'existing' => true];
    }

    $who     = trim((string)($m['from_name'] ?: $m['from_email']));
    $name    = 'Odpowiedz: ' . ((string)$m['subject'] ?: '(bez tematu)') . ($who !== '' ? ' — ' . $who : '');
    $comment = 'Wiadomość ze Skrzynki CRM'
             . (!empty($m['msg_no']) ? ' nr ' . $m['msg_no'] : '') . "\n"
             . 'Od: ' . $who . ' <' . (string)$m['from_email'] . ">\n"
             . 'Data: ' . date('d.m.Y H:i', strtotime((string)$m['sent_at'])) . "\n\n"
             . mb_substr(trim((string)$m['body']), 0, 1500) . "\n\n"
             . APP_URL . '/crm/inbox.php?view=all&msg=' . $comm_id;

    return nozbe_create_task($name, [
        'project_id' => $project_id,
        'comment'    => $comment,
        'local_type' => 'crm_message',
        'local_id'   => $comm_id,
    ]);
}

/** Sprawa CRM → Nozbe. */
function nozbe_push_crm_case(int $case_id, string $project_id = ''): array {
    try {
        // crm_cases żyje w GŁÓWNEJ bazie (inaczej niż tabele ofert) i nie ma terminu
        $c = db_one("SELECT id, title, description, case_number FROM crm_cases WHERE id=?", [$case_id]);
    } catch (\Throwable $e) { $c = null; }
    if (!$c) return ['ok' => false, 'error' => 'Sprawa nie istnieje.', 'id' => '', 'url' => ''];

    $existing = nozbe_link_for('crm_case', $case_id);
    if ($existing) {
        return ['ok' => true, 'error' => '', 'id' => (string)$existing['nozbe_task_id'],
                'url' => $existing['url'], 'existing' => true];
    }

    return nozbe_create_task('Sprawa CRM: ' . (string)$c['title']
                            . (!empty($c['case_number']) ? ' (' . $c['case_number'] . ')' : ''), [
        'project_id' => $project_id,
        'comment'    => trim((string)($c['description'] ?? ''))
                        . "\n\nSprawa w SZO: " . APP_URL . '/crm/cases/view.php?id=' . $case_id,
        'local_type' => 'crm_case',
        'local_id'   => $case_id,
    ]);
}
