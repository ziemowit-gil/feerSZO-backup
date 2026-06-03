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
