<?php
/**
 * Klient REST API systemu FEER NGO.
 *
 * Odpowiada za pobieranie danych o wolontariuszach z endpointu
 * /api/v1/moodle_sync.php i obsługę paginacji.
 */

namespace local_feer_sync\api;

defined('MOODLE_INTERNAL') || die();

class feer_client {

    private string $base_url;
    private string $api_key;
    private int    $timeout;

    public function __construct(string $base_url = '', string $api_key = '', int $timeout = 15) {
        $this->base_url = rtrim($base_url ?: get_config('local_feer_sync', 'feer_url'), '/');
        $this->api_key  = $api_key  ?: get_config('local_feer_sync', 'feer_api_key');
        $this->timeout  = $timeout;
    }

    /**
     * Pobierz wszystkich aktywnych wolontariuszy (ze wszystkich stron).
     * Opcjonalnie: tylko zmienione od $since (ISO datetime).
     *
     * @return array<array{id,email,firstname,lastname,fullname,status,
     *                     contract_status_group,project,action_id,action_name,
     *                     start_date,end_date,updated_at}>
     */
    public function get_active_volunteers(string $since = '', bool $include_standalone = false): array {
        return $this->fetch_all('active', $since, $include_standalone);
    }

    /**
     * Pobierz wolontariuszy z zakończonymi umowami.
     * Przydatne do zawieszania kont Moodle.
     */
    public function get_ended_volunteers(string $since = ''): array {
        return $this->fetch_all('ended', $since);
    }

    /**
     * Pobierz WSZYSTKICH wolontariuszy (aktywni + zakończeni) od danej daty.
     * Idealne do synchronizacji przyrostowej.
     */
    public function get_all_since(string $since): array {
        return $this->fetch_all('all', $since);
    }

    /**
     * Sprawdź połączenie z FEER — zwraca meta z pierwszej strony lub rzuca wyjątek.
     */
    public function ping(): array {
        $data = $this->get_page(1, 1, 'active');
        return $data['meta'] ?? [];
    }

    // ── Prywatne ──────────────────────────────────────────────────────────────

    /**
     * Wyślij dane konta Moodle z powrotem do FEER (writeback).
     * Pozwala wolontariuszowi zobaczyć login Moodle w panelu FEER.
     */
    public function writeback_moodle_user(string $email, string $moodle_username, int $moodle_user_id): void {
        $url  = $this->base_url . '/api/v1/moodle_user_update.php';
        $body = json_encode([
            'email'           => $email,
            'moodle_username' => $moodle_username,
            'moodle_user_id'  => $moodle_user_id,
            'moodle_url'      => $this->base_url,  // URL Moodle = base_url wtyczki... actually Moodle URL
        ]);
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->api_key,
                'Content-Type: application/json',
            ],
        ]);
        curl_exec($curl);
        curl_close($curl);
    }

    private function fetch_all(string $include, string $since = '', bool $include_standalone = false): array {
        $result   = [];
        $page     = 1;
        $per_page = 100;

        do {
            $data     = $this->get_page($page, $per_page, $include, $since, $include_standalone);
            $result   = array_merge($result, $data['data'] ?? []);
            $pages    = (int)($data['meta']['pages'] ?? 1);
            $page++;
        } while ($page <= $pages);

        return $result;
    }

    private function get_page(int $page, int $per_page, string $include, string $since = '', bool $include_standalone = false): array {
        $params = [
            'page'     => $page,
            'per_page' => $per_page,
            'include'  => $include,
        ];
        if ($since !== '') {
            $params['since'] = $since;
        }
        if ($include_standalone) {
            $params['include_standalone'] = '1';
        }

        $url = $this->base_url . '/api/v1/moodle_sync.php?' . http_build_query($params);

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->api_key,
                'Accept: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
        ]);
        $resp    = curl_exec($curl);
        $http    = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $err     = curl_error($curl);
        curl_close($curl);

        if ($err) {
            throw new \moodle_exception('cannotconnect', 'local_feer_sync', '', $err);
        }
        if ($http !== 200) {
            throw new \moodle_exception('apierror', 'local_feer_sync', '', "HTTP {$http}: {$resp}");
        }

        $data = json_decode($resp, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \moodle_exception('invalidjson', 'local_feer_sync');
        }

        return $data;
    }
}
