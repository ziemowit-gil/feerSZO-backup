<?php
/**
 * includes/zoom.php — lekki klient Zoom REST (Server-to-Server OAuth).
 *
 * Wymaga aplikacji „Server-to-Server OAuth" w Zoom Marketplace
 * (scopes: meeting:read:admin / meeting:read, user:read).
 * Ustawienia w tabeli settings: zoom_enabled, zoom_account_id,
 * zoom_client_id, zoom_client_secret, zoom_user_id (domyślnie „me").
 *
 * Błędy nie są propagowane do widoku — metody „read" zwracają puste tablice,
 * test_connection() zwraca status do strony admina.
 */

require_once __DIR__ . '/functions.php'; // org_setting()

function zoom_setting(string $key): string {
    return org_setting('zoom_' . $key);
}

/** Czy integracja Zoom jest włączona i skonfigurowana. */
function zoom_enabled(): bool {
    return zoom_setting('enabled') === '1'
        && zoom_setting('account_id') !== ''
        && zoom_setting('client_id') !== ''
        && zoom_setting('client_secret') !== '';
}

class ZoomAPI {
    private string $accountId;
    private string $clientId;
    private string $clientSecret;
    private string $userId;
    private string $token = '';

    public function __construct() {
        $this->accountId    = zoom_setting('account_id');
        $this->clientId     = zoom_setting('client_id');
        $this->clientSecret = zoom_setting('client_secret');
        $this->userId       = zoom_setting('user_id') ?: 'me';
    }

    public function is_configured(): bool {
        return $this->accountId !== '' && $this->clientId !== '' && $this->clientSecret !== '';
    }

    /** Pobiera token Server-to-Server OAuth (account_credentials, Basic auth). */
    private function token(): string {
        if ($this->token !== '') return $this->token;
        $url = 'https://zoom.us/oauth/token?' . http_build_query([
            'grant_type' => 'account_credentials',
            'account_id' => $this->accountId,
        ]);
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Authorization: Basic " . base64_encode($this->clientId . ':' . $this->clientSecret)
                             . "\r\nContent-Type: application/x-www-form-urlencoded\r\n",
            'content'       => '',
            'ignore_errors' => true,
            'timeout'       => 10,
        ]]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) throw new \RuntimeException('Brak połączenia z Zoom OAuth.');
        $data = json_decode($resp, true) ?: [];
        if (empty($data['access_token'])) {
            throw new \RuntimeException('Zoom OAuth: ' . ($data['reason'] ?? $data['error'] ?? 'brak tokenu'));
        }
        return $this->token = $data['access_token'];
    }

    private function get(string $path): array {
        $ctx = stream_context_create(['http' => [
            'method'        => 'GET',
            'header'        => "Authorization: Bearer " . $this->token() . "\r\n",
            'ignore_errors' => true,
            'timeout'       => 10,
        ]]);
        $resp = @file_get_contents('https://api.zoom.us/v2' . $path, false, $ctx);
        if ($resp === false) throw new \RuntimeException('Brak połączenia z Zoom API.');
        $data = json_decode($resp, true) ?: [];
        if (isset($data['code']) && (int)$data['code'] !== 0 && empty($data['meetings'])) {
            // np. 124 invalid token, 1001 user nie istnieje
            throw new \RuntimeException('Zoom API: ' . ($data['message'] ?? ('kod ' . $data['code'])));
        }
        return $data;
    }

    /**
     * Nadchodzące spotkania użytkownika. Zwraca [{title,start,join_url}].
     * Błędy łapane → pusta lista (nie wywala panelu kursanta).
     */
    public function upcoming_meetings(): array {
        try {
            $data = $this->get('/users/' . rawurlencode($this->userId) . '/meetings?type=upcoming&page_size=30');
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($data['meetings'] ?? [] as $m) {
            if (empty($m['join_url'])) continue;
            $out[] = [
                'title'    => $m['topic'] ?? 'Spotkanie Zoom',
                'start'    => $m['start_time'] ?? '',
                'join_url' => $m['join_url'],
            ];
        }
        return $out;
    }

    private function post(string $path, array $body): array {
        $json = json_encode($body);
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Authorization: Bearer " . $this->token() . "\r\n"
                             . "Content-Type: application/json\r\n",
            'content'       => $json,
            'ignore_errors' => true,
            'timeout'       => 15,
        ]]);
        $resp = @file_get_contents('https://api.zoom.us/v2' . $path, false, $ctx);
        if ($resp === false) throw new \RuntimeException('Brak połączenia z Zoom API.');
        $data   = json_decode($resp, true) ?: [];
        $status = 0;
        if (!empty($http_response_header)) {
            preg_match('/HTTP\/\S+ (\d+)/', $http_response_header[0] ?? '', $sm);
            $status = (int)($sm[1] ?? 0);
        }
        if ($status >= 400) {
            throw new \RuntimeException('Zoom API: ' . ($data['message'] ?? ('HTTP ' . $status)));
        }
        return $data;
    }

    private function request_delete(string $path): void {
        $ctx = stream_context_create(['http' => [
            'method'        => 'DELETE',
            'header'        => "Authorization: Bearer " . $this->token() . "\r\n",
            'ignore_errors' => true,
            'timeout'       => 10,
        ]]);
        @file_get_contents('https://api.zoom.us/v2' . $path, false, $ctx);
    }

    /**
     * Tworzy spotkanie cykliczne bez stałego terminu (typ 3).
     * Generuje stały join_url — idealny jako link per kurs.
     * Zwraca ['meeting_id'=>string, 'join_url'=>string].
     */
    public function create_meeting(string $topic, string $agenda = ''): array {
        $body = [
            'topic'    => $topic,
            'type'     => 3,
            'settings' => [
                'host_video'        => true,
                'participant_video' => true,
                'join_before_host'  => true,
                'mute_upon_entry'   => false,
                'approval_type'     => 0,
                'audio'             => 'both',
                'auto_recording'    => 'none',
            ],
        ];
        if ($agenda !== '') $body['agenda'] = $agenda;
        $data = $this->post('/users/' . rawurlencode($this->userId) . '/meetings', $body);
        return [
            'meeting_id' => (string)($data['id'] ?? ''),
            'join_url'   => (string)($data['join_url'] ?? ''),
        ];
    }

    /** Usuwa spotkanie Zoom. Brak spotkania traktuje jako OK. */
    public function delete_meeting(string $meeting_id): void {
        if ($meeting_id === '') return;
        try {
            $this->request_delete('/meetings/' . rawurlencode($meeting_id));
        } catch (\Throwable $e) {}
    }

    /** Test połączenia — zwraca ['ok'=>bool,'msg'=>string]. */
    public function test_connection(): array {
        try {
            $u = $this->get('/users/' . rawurlencode($this->userId));
            $who = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: ($u['email'] ?? $this->userId);
            return ['ok' => true, 'msg' => 'Połączenie OK. Użytkownik: ' . $who];
        } catch (\Throwable $e) {
            return ['ok' => false, 'msg' => $e->getMessage()];
        }
    }
}
