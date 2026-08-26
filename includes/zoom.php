<?php
/**
 * includes/zoom.php — klient Zoom REST (Server-to-Server OAuth).
 *
 * Wymaga aplikacji „Server-to-Server OAuth" w Zoom Marketplace
 * (scopes: meeting:read:admin, meeting:write:admin, user:read:admin).
 *
 * Ustawienia w tabeli settings:
 *   zoom_enabled, zoom_account_id, zoom_client_id, zoom_client_secret,
 *   zoom_user_id (domyślnie edukacja@feer.org.pl),
 *   zoom_webhook_secret (do weryfikacji HMAC na api/zoom_webhook.php).
 *
 * Token jest cache'owany między requestami w tabeli settings
 * (klucze zoom_token_cache / zoom_token_expires). Przy wygaśnięciu
 * (kod Zoom 124 lub HTTP 401) token jest automatycznie odświeżany
 * i żądanie ponawiane raz.
 */

require_once __DIR__ . '/functions.php'; // org_setting(), org_setting_set()

function zoom_setting(string $key): string {
    return org_setting('zoom_' . $key);
}

/** Czy integracja Zoom jest włączona i skonfigurowana. */
function zoom_enabled(): bool {
    return zoom_setting('enabled') === '1'
        && zoom_setting('account_id')    !== ''
        && zoom_setting('client_id')     !== ''
        && zoom_setting('client_secret') !== '';
}

class ZoomAPI {
    private string $accountId;
    private string $clientId;
    private string $clientSecret;
    private string $userId;
    private string $token = '';   // in-memory cache dla bieżącego requestu

    public function __construct() {
        $this->accountId    = zoom_setting('account_id');
        $this->clientId     = zoom_setting('client_id');
        $this->clientSecret = zoom_setting('client_secret');
        $this->userId       = zoom_setting('user_id') ?: 'edukacja@feer.org.pl';
    }

    public function is_configured(): bool {
        return $this->accountId !== '' && $this->clientId !== '' && $this->clientSecret !== '';
    }

    // ── Token ─────────────────────────────────────────────────────────────────

    /**
     * Zwraca ważny access token (TTL ~1h).
     * Sprawdza kolejno: in-memory → cache DB → nowy OAuth.
     */
    private function token(): string {
        if ($this->token !== '') return $this->token;

        // Cache w DB (omija static cache org_setting — może być nieaktualny)
        $row     = db_one("SELECT value FROM settings WHERE key_='zoom_token_cache'");
        $rowExp  = db_one("SELECT value FROM settings WHERE key_='zoom_token_expires'");
        $cached  = $row['value']    ?? '';
        $expires = (int)($rowExp['value'] ?? '0');
        if ($cached !== '' && time() < $expires - 60) {
            return $this->token = $cached;
        }

        return $this->token = $this->fetch_new_token();
    }

    /** Pobiera nowy token z Zoom OAuth i zapisuje w DB. */
    private function fetch_new_token(): string {
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
        $newExpires = time() + (int)($data['expires_in'] ?? 3600);
        $this->cache_token_set($data['access_token'], $newExpires);
        return $data['access_token'];
    }

    /** Zapisuje token do DB bezpośrednio (z pominięciem static cache org_setting). */
    private function cache_token_set(string $token, int $expires): void {
        foreach (['zoom_token_cache' => $token, 'zoom_token_expires' => (string)$expires] as $k => $v) {
            $ex = db_one("SELECT 1 FROM settings WHERE key_=?", [$k]);
            if ($ex) {
                db()->prepare("UPDATE settings SET value=? WHERE key_=?")->execute([$v, $k]);
            } else {
                db()->prepare("INSERT INTO settings (key_, value) VALUES (?,?)")->execute([$k, $v]);
            }
        }
    }

    /** Czyści cache tokenu — wywołane po HTTP 401 / kodzie 124. */
    private function token_invalidate(): void {
        $this->token = '';
        $this->cache_token_set('', '0');
    }

    // ── HTTP helpers ──────────────────────────────────────────────────────────

    private function get(string $path, bool $retry = true): array {
        $ctx = stream_context_create(['http' => [
            'method'        => 'GET',
            'header'        => "Authorization: Bearer " . $this->token() . "\r\n",
            'ignore_errors' => true,
            'timeout'       => 10,
        ]]);
        $resp = @file_get_contents('https://api.zoom.us/v2' . $path, false, $ctx);
        if ($resp === false) throw new \RuntimeException('Brak połączenia z Zoom API.');
        $data = json_decode($resp, true) ?: [];
        $code = (int)($data['code'] ?? 0);

        if ($code === 124 && $retry) {
            $this->token_invalidate();
            return $this->get($path, false);
        }
        if ($code !== 0 && empty($data['meetings']) && empty($data['email'])) {
            throw new \RuntimeException('Zoom API: ' . ($data['message'] ?? ('kod ' . $code)));
        }
        return $data;
    }

    private function post(string $path, array $body, bool $retry = true): array {
        $json = json_encode($body);
        $ctx  = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Authorization: Bearer " . $this->token() . "\r\n"
                             . "Content-Type: application/json\r\n",
            'content'       => $json,
            'ignore_errors' => true,
            'timeout'       => 15,
        ]]);
        $resp   = @file_get_contents('https://api.zoom.us/v2' . $path, false, $ctx);
        if ($resp === false) throw new \RuntimeException('Brak połączenia z Zoom API.');
        $data   = json_decode($resp, true) ?: [];
        $status = 0;
        if (!empty($http_response_header)) {
            preg_match('/HTTP\/\S+ (\d+)/', $http_response_header[0] ?? '', $sm);
            $status = (int)($sm[1] ?? 0);
        }
        if ($status === 401 && $retry) {
            $this->token_invalidate();
            return $this->post($path, $body, false);
        }
        if ($status >= 400) {
            throw new \RuntimeException('Zoom API: ' . ($data['message'] ?? ('HTTP ' . $status)));
        }
        return $data;
    }

    private function request_patch(string $path, array $body, bool $retry = true): void {
        $json = json_encode($body);
        $ctx  = stream_context_create(['http' => [
            'method'        => 'PATCH',
            'header'        => "Authorization: Bearer " . $this->token() . "\r\n"
                             . "Content-Type: application/json\r\n",
            'content'       => $json,
            'ignore_errors' => true,
            'timeout'       => 15,
        ]]);
        $resp   = @file_get_contents('https://api.zoom.us/v2' . $path, false, $ctx);
        if ($resp === false) throw new \RuntimeException('Brak połączenia z Zoom API.');
        $data   = json_decode($resp, true) ?: [];
        $status = 0;
        if (!empty($http_response_header)) {
            preg_match('/HTTP\/\S+ (\d+)/', $http_response_header[0] ?? '', $sm);
            $status = (int)($sm[1] ?? 0);
        }
        if ($status === 401 && $retry) {
            $this->token_invalidate();
            $this->request_patch($path, $body, false);
            return;
        }
        if ($status >= 400) {
            $code = (int)($data['code'] ?? 0);
            throw new \RuntimeException(
                'Zoom API PATCH: ' . ($data['message'] ?? ('HTTP ' . $status))
                . ($code ? " (kod $code)" : '')
            );
        }
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

    // ── Publiczne metody API ──────────────────────────────────────────────────

    /**
     * Sprawdza czy e-mail istnieje jako aktywny użytkownik Zoom w tej organizacji.
     * Rzuca RuntimeException z przyjaznym komunikatem gdy konto nie istnieje.
     * Należy wywołać przed create_meeting() — w razie błędu utwórz bez alt_host.
     */
    public function validate_user_email(string $email): void {
        try {
            $this->get('/users/' . rawurlencode($email));
        } catch (\RuntimeException $e) {
            throw new \RuntimeException(
                'Adres "' . $email . '" nie jest kontem Zoom w tej organizacji. '
                . 'Prowadzacy musi sie zalogowac na zoom.us i aktywowac konto '
                . 'w ramach licencji fundacji.'
            );
        }
    }

    /**
     * Tworzy spotkanie cykliczne bez stałego terminu (typ 3).
     * Generuje stały join_url — jeden link na cały kurs.
     *
     * @param string $alternative_hosts  E-maile prowadzących oddzielone przecinkiem.
     * @return array{meeting_id: string, join_url: string}
     */
    public function create_meeting(string $topic, string $agenda = '', string $alternative_hosts = ''): array {
        $settings = [
            'host_video'        => true,
            'participant_video' => true,
            'join_before_host'  => true,
            'mute_upon_entry'   => false,
            'approval_type'     => 0,
            'audio'             => 'both',
            'auto_recording'    => 'none',
        ];
        if ($alternative_hosts !== '') {
            $settings['alternative_hosts']              = $alternative_hosts;
            $settings['alternative_host_update_polls'] = true;
        }
        $body = ['topic' => $topic, 'type' => 3, 'settings' => $settings];
        if ($agenda !== '') $body['agenda'] = $agenda;

        $data = $this->post('/users/' . rawurlencode($this->userId) . '/meetings', $body);
        return [
            'meeting_id' => (string)($data['id'] ?? ''),
            'join_url'   => (string)($data['join_url'] ?? ''),
        ];
    }

    /**
     * Aktualizuje alternative_hosts istniejącego spotkania.
     *
     * @return bool  false gdy spotkanie nie istnieje (kod 3001 — należy regenerować link),
     *               true przy powodzeniu.
     */
    public function update_alternative_hosts(string $meeting_id, string $emails): bool {
        if ($meeting_id === '') return false;
        try {
            $this->request_patch('/meetings/' . rawurlencode($meeting_id), [
                'settings' => ['alternative_hosts' => $emails],
            ]);
            return true;
        } catch (\RuntimeException $e) {
            // Kod 3001 = spotkanie usunięte po stronie Zoom — sygnał do regeneracji
            return false;
        }
    }

    /** Usuwa spotkanie Zoom. Brak spotkania traktuje jako OK (idempotentne). */
    public function delete_meeting(string $meeting_id): void {
        if ($meeting_id === '') return;
        try {
            $this->request_delete('/meetings/' . rawurlencode($meeting_id));
        } catch (\Throwable $e) {}
    }

    /**
     * Nadchodzące spotkania użytkownika hosta. Zwraca [{title,start,join_url}].
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


    /**
     * Zajętość konta hosta: spotkania Zoom z ustalonym terminem.
     * Zwraca [['meeting_id','topic','start','end']] — czas LOKALNY (Y-m-d H:i:s).
     *
     * OGRANICZENIE API ZOOM: spotkania cykliczne bez stałego terminu (typ 3 —
     * takie tworzy create_meeting() dla kursów i kursantów) nie mają w Zoomie
     * żadnych godzin, więc nie występują w tym wykazie. Zajętość wynikającą
     * z lekcji zaplanowanych w SZO liczy ti_zoom_slot_check() z k30_ti_sessions;
     * ta metoda odpowiada za spotkania z terminem, w tym utworzone poza SZO.
     *
     * Zwraca tylko spotkania nieprzedawnione i przyszłe (type=scheduled) —
     * dla terminów wstecz Zoom nie udostępnia tej listy.
     */
    public function busy_slots(int $max_pages = 5): array {
        $out = [];  $next = '';  $page = 0;
        do {
            $path = '/users/' . rawurlencode($this->userId) . '/meetings?type=scheduled&page_size=300'
                  . ($next !== '' ? '&next_page_token=' . rawurlencode($next) : '');
            $data = $this->get($path);
            foreach ($data['meetings'] ?? [] as $m) {
                $type = (int)($m['type'] ?? 2);
                if ($type === 3) continue;                    // bez stałego terminu — brak godzin
                if ($type === 8) {                            // cykliczne ze stałym terminem
                    foreach ($this->occurrence_slots($m) as $s) $out[] = $s;
                    continue;
                }
                $s = $this->slot_from(
                    (string)($m['start_time'] ?? ''), (int)($m['duration'] ?? 0),
                    (string)($m['topic'] ?? ''),      (string)($m['id'] ?? '')
                );
                if ($s) $out[] = $s;
            }
            $next = (string)($data['next_page_token'] ?? '');
            $page++;
        } while ($next !== '' && $page < $max_pages);
        return $out;
    }

    /**
     * Wystąpienia spotkania cyklicznego ze stałym terminem (typ 8).
     * Lista spotkań zwraca tylko najbliższe wystąpienie — pełny wykaz jest
     * w szczegółach spotkania (pole occurrences). Gdy szczegóły są niedostępne,
     * bierzemy najbliższe wystąpienie z listy (lepsze niż nic).
     */
    private function occurrence_slots(array $m): array {
        $id       = (string)($m['id'] ?? '');
        $fallback = $this->slot_from(
            (string)($m['start_time'] ?? ''), (int)($m['duration'] ?? 0),
            (string)($m['topic'] ?? ''),      $id
        );
        if ($id === '') return $fallback ? [$fallback] : [];

        try {
            $d = $this->get('/meetings/' . rawurlencode($id));
        } catch (\Throwable $e) {
            return $fallback ? [$fallback] : [];
        }
        $topic = (string)($d['topic'] ?? $m['topic'] ?? '');
        $out   = [];
        foreach ($d['occurrences'] ?? [] as $o) {
            if (($o['status'] ?? 'available') === 'deleted') continue;
            $s = $this->slot_from(
                (string)($o['start_time'] ?? ''),
                (int)($o['duration'] ?? $d['duration'] ?? 0),
                $topic, $id
            );
            if ($s) $out[] = $s;
        }
        if (!$out && $fallback) $out[] = $fallback;
        return $out;
    }

    /** Slot z pary (start UTC, czas trwania) na czas lokalny; null gdy brak startu. */
    private function slot_from(string $start_utc, int $duration_min, string $topic, string $meeting_id): ?array {
        if (trim($start_utc) === '') return null;
        if ($duration_min <= 0) $duration_min = 60;
        try {
            $dt = new \DateTime($start_utc, new \DateTimeZone('UTC'));
            $dt->setTimezone(new \DateTimeZone(date_default_timezone_get() ?: 'Europe/Warsaw'));
        } catch (\Throwable $e) {
            return null;
        }
        $start = $dt->format('Y-m-d H:i:s');
        $dt->modify('+' . $duration_min . ' minutes');
        return [
            'meeting_id' => $meeting_id,
            'topic'      => $topic !== '' ? $topic : 'Spotkanie Zoom',
            'start'      => $start,
            'end'        => $dt->format('Y-m-d H:i:s'),
        ];
    }

    /** Test połączenia — zwraca ['ok'=>bool, 'msg'=>string]. */
    public function test_connection(): array {
        try {
            $u   = $this->get('/users/' . rawurlencode($this->userId));
            $who = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''))
                ?: ($u['email'] ?? $this->userId);
            return ['ok' => true, 'msg' => 'Połączenie OK. Użytkownik: ' . $who];
        } catch (\Throwable $e) {
            return ['ok' => false, 'msg' => $e->getMessage()];
        }
    }

    /**
     * Zapisuje operację do k30_ti_zoom_log.
     * Ciche błędy — tabela może nie istnieć przed pierwszym karty30_migrate().
     */
    public function log(
        string $action,
        string $meeting_id = '',
        string $status     = 'ok',
        string $detail     = '',
        ?int   $course_id  = null,
        ?int   $user_id    = null
    ): void {
        try {
            db()->prepare(
                "INSERT INTO k30_ti_zoom_log
                    (course_id, action, meeting_id, detail, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)"
            )->execute([$course_id, $action, $meeting_id, $detail, $status, $user_id]);
        } catch (\Throwable $e) {}
    }
}
