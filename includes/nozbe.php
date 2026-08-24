<?php
/**
 * includes/nozbe.php — Nozbe Teams/Personal REST API v4 client.
 * Dokumentacja: https://api4.nozbe.com/v1/api
 * Auth: nagłówek  Authorization: apikey <token>
 */

class NozbeAPI {
    private const BASE = 'https://api4.nozbe.com/v1/api';
    private string $token;

    public function __construct(string $token) {
        $this->token = trim($token);
    }

    public function is_configured(): bool {
        return $this->token !== '';
    }

    // ── HTTP ──────────────────────────────────────────────────────────────────

    private function request(string $method, string $path, array $body = []): array {
        $url = self::BASE . $path;
        $headers = [
            'Authorization: apikey ' . $this->token,
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        $ctx = stream_context_create([
            'http' => [
                'method'        => strtoupper($method),
                'header'        => implode("\r\n", $headers),
                'content'       => $body ? json_encode($body) : null,
                'timeout'       => 8,
                'ignore_errors' => true,
            ],
        ]);
        $raw  = @file_get_contents($url, false, $ctx);
        $code = 0;
        if (isset($http_response_header)) {
            preg_match('/HTTP\/\S+ (\d+)/', $http_response_header[0] ?? '', $m);
            $code = (int)($m[1] ?? 0);
        }
        if ($raw === false || ($code >= 400)) {
            throw new \RuntimeException("Nozbe API error {$code}: " . ($raw ?: 'brak odpowiedzi'));
        }
        return json_decode($raw, true) ?? [];
    }

    // ── Projekty ──────────────────────────────────────────────────────────────

    /** Pobierz listę projektów (workspaces). */
    public function get_projects(): array {
        return $this->request('GET', '/projects') ?: [];
    }

    // ── Zadania ───────────────────────────────────────────────────────────────

    /**
     * Utwórz zadanie w Nozbe.
     *
     * @param string      $name        Nazwa zadania
     * @param string      $project_id  ID projektu (section) — wymagane
     * @param string      $description Opis (pojawi się jako komentarz)
     * @param string|null $due_date    Format ISO: 2026-06-15
     * @param bool        $is_priority Oznacz jako priorytet
     */
    public function create_task(
        string  $name,
        string  $project_id,
        string  $description = '',
        ?string $due_date    = null,
        bool    $is_priority = false
    ): array {
        $data = [
            'name'       => $name,
            'project_id' => $project_id,
        ];
        if ($due_date) {
            $data['due_at'] = $due_date . 'T00:00:00.000Z';
        }
        if ($is_priority) {
            $data['is_starred'] = true;
        }
        $task = $this->request('POST', '/tasks', $data);
        // Dodaj opis jako komentarz jeśli podano
        if (!empty($task['id']) && $description !== '') {
            try {
                $this->add_comment($task['id'], $description);
            } catch (\Throwable $e) {}
        }
        return $task;
    }

    /** Dodaj komentarz tekstowy do zadania. */
    public function add_comment(string $task_id, string $body): array {
        return $this->request('POST', '/comments', [
            'task_id' => $task_id,
            'body'    => $body,
        ]);
    }

    /** Pobierz zadania projektu. */
    public function get_tasks(string $project_id = ''): array {
        $path = $project_id ? '/tasks?project_id=' . urlencode($project_id) : '/tasks';
        return $this->request('GET', $path) ?: [];
    }

    /** Oznacz zadanie jako ukończone. */
    public function complete_task(string $task_id): array {
        return $this->request('PUT', '/tasks/' . $task_id, ['is_completed' => true]);
    }

    // ── Settings helpers ──────────────────────────────────────────────────────

    /** Odczytaj ustawienia Nozbe z tabeli settings. */
    public static function from_settings(): self {
        $token = nozbe_setting('nozbe_api_token');
        return new self($token);
    }
}

function nozbe_setting(string $key): string {
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $r = db_one("SELECT value FROM settings WHERE key_=?", [$key]);
        $cache[$key] = $r['value'] ?? '';
    } catch (\Throwable $e) {
        $cache[$key] = '';
    }
    return $cache[$key];
}

function nozbe_save_setting(string $key, string $value): void {
    if (!function_exists('db')) return;
    if (DB_TYPE === 'sqlite') {
        db()->prepare("INSERT OR REPLACE INTO settings (key_,value) VALUES (?,?)")->execute([$key,$value]);
    } else {
        db()->prepare("INSERT INTO settings (key_,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=?")->execute([$key,$value,$value]);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Warstwa „wyślij to do Nozbe" — zadania SZO, wiadomości ze Skrzynki CRM, sprawy.
//
// Nadbudowa nad klasą NozbeAPI: kierunek jest JEDNOSTRONNY (SZO → Nozbe), bo
// Nozbe nie odsyła statusów. Powiązania trzyma tabela `nozbe_links`, żeby drugie
// kliknięcie nie zrobiło duplikatu, tylko otworzyło istniejące zadanie.
//
// Ustawienia: nozbe_api_token, nozbe_default_project_id (te same, których używa
// crm/settings/nozbe.php — jedna konfiguracja, nie dwie).
// ─────────────────────────────────────────────────────────────────────────────

const NOZBE_APP_URL = 'https://app.nozbe.com';

(function () {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS nozbe_links (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            local_type    TEXT    NOT NULL,          -- task | crm_message | crm_case | manual
            local_id      INTEGER,
            nozbe_task_id TEXT    NOT NULL,
            name          TEXT    NOT NULL DEFAULT '',
            project_id    TEXT    NOT NULL DEFAULT '',
            created_by    INTEGER,
            created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_nozbe_local ON nozbe_links(local_type, local_id)");
    } catch (\Throwable $e) {}
})();

/** Czy integracja jest gotowa do użycia (token zapisany). */
function nozbe_configured(): bool {
    return trim(nozbe_setting('nozbe_api_token')) !== '';
}

/** Lista projektów Nozbe: [['id'=>…, 'name'=>…], …]; cache w ustawieniach. */
function nozbe_projects(bool $refresh = false): array {
    if (!$refresh) {
        $cached = json_decode(nozbe_setting('nozbe_projects') ?: '[]', true);
        if (is_array($cached) && $cached) return $cached;
    }
    if (!nozbe_configured()) return [];

    try {
        $rows = NozbeAPI::from_settings()->get_projects();
    } catch (\Throwable $e) { return []; }
    if (isset($rows['data']) && is_array($rows['data'])) $rows = $rows['data'];

    $out = [];
    foreach ((array)$rows as $p) {
        if (!is_array($p) || empty($p['id'])) continue;
        $out[] = ['id' => (string)$p['id'], 'name' => (string)($p['name'] ?? $p['id'])];
    }
    usort($out, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
    if ($out) nozbe_save_setting('nozbe_projects', json_encode($out, JSON_UNESCAPED_UNICODE));
    return $out;
}

/** Test połączenia — ['ok'=>bool,'error'=>string,'projects'=>int]. */
function nozbe_test(): array {
    if (!nozbe_configured()) return ['ok' => false, 'error' => 'Brak tokenu API Nozbe.', 'projects' => 0];
    try {
        $rows = NozbeAPI::from_settings()->get_projects();
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Nozbe: ' . mb_substr($e->getMessage(), 0, 160), 'projects' => 0];
    }
    if (isset($rows['data']) && is_array($rows['data'])) $rows = $rows['data'];
    return ['ok' => true, 'error' => '', 'projects' => is_array($rows) ? count($rows) : 0];
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

/**
 * Tworzy zadanie w Nozbe i zapisuje powiązanie.
 *
 * @param array $opt ['project_id','due_date' (Y-m-d),'comment','local_type','local_id']
 * @return array ['ok','error','id','url']
 */
function nozbe_create_task(string $name, array $opt = []): array {
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    if ($name === '') return ['ok' => false, 'error' => 'Zadanie musi mieć nazwę.', 'id' => '', 'url' => ''];
    if (!nozbe_configured()) return ['ok' => false, 'error' => 'Brak tokenu API Nozbe.', 'id' => '', 'url' => ''];

    $project = trim((string)($opt['project_id'] ?? nozbe_setting('nozbe_default_project_id')));

    try {
        $api  = NozbeAPI::from_settings();
        $task = $api->create_task(
            mb_substr($name, 0, 255),
            $project,
            (string)($opt['comment'] ?? ''),
            !empty($opt['due_date']) ? (string)$opt['due_date'] : null
        );
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Nozbe: ' . mb_substr($e->getMessage(), 0, 160), 'id' => '', 'url' => ''];
    }

    $id = (string)($task['id'] ?? '');
    if ($id === '') return ['ok' => false, 'error' => 'Nozbe nie zwróciło identyfikatora zadania.', 'id' => '', 'url' => ''];

    try {
        db_insert('nozbe_links', [
            'local_type'    => (string)($opt['local_type'] ?? 'manual'),
            'local_id'      => (int)($opt['local_id'] ?? 0) ?: null,
            'nozbe_task_id' => $id,
            'name'          => $name,
            'project_id'    => $project,
            'created_by'    => function_exists('current_user') ? ((int)(current_user()['id'] ?? 0) ?: null) : null,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
    } catch (\Throwable $e) {
        error_log('[nozbe_create_task] log: ' . $e->getMessage());
    }

    return ['ok' => true, 'error' => '', 'id' => $id, 'url' => nozbe_task_url($id)];
}

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

    return nozbe_create_task((string)$t['title'], [
        'project_id' => $project_id,
        'due_date'   => !empty($t['due_date']) ? substr((string)$t['due_date'], 0, 10) : null,
        'comment'    => trim((string)($t['description'] ?? ''))
                        . "\n\nZadanie w SZO: " . APP_URL . '/tasks/detail.php?id=' . $task_id,
        'local_type' => 'task',
        'local_id'   => $task_id,
    ]);
}

/** Wiadomość ze Skrzynki CRM → Nozbe. */
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
        $c = db_one("SELECT id, title, description, case_number, due_date FROM crm_cases WHERE id=?", [$case_id]);
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
        'due_date'   => !empty($c['due_date']) ? substr((string)$c['due_date'], 0, 10) : null,
        'comment'    => trim((string)($c['description'] ?? ''))
                        . "\n\nSprawa w SZO: " . APP_URL . '/crm/cases/view.php?id=' . $case_id,
        'local_type' => 'crm_case',
        'local_id'   => $case_id,
    ]);
}
