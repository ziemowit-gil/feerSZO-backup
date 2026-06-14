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
