<?php
/**
 * feer_directory — książka adresowa „Fundacja FEER” (tylko do odczytu) z katalogu SZO.
 *
 * Źródło: https://szo.feer.org.pl/api/internal/rc_directory.php (te same osoby i dane co
 * /katalog; telefon tylko publiczny), klucz jak w feer_signature (secrets/szo-key).
 * Dane w pliku podręcznym temp_dir/feer_directory.json (wspólny dla wszystkich, TTL z
 * feer_directory_ttl, domyślnie 1 h); przy awarii SZO zostaje ostatnia dobra kopia.
 * Widoczna tylko dla skrzynek z serwerów feer_directory_hosts (@feer.org.pl), także w
 * autouzupełnianiu adresów przy pisaniu.
 */
require_once __DIR__ . '/feer_directory_backend.php';

class feer_directory extends rcube_plugin
{
    public const ABOOK_ID = 'feer_directory';

    public function init(): void
    {
        if (!$this->allowed()) {
            return;
        }
        $this->add_hook('addressbooks_list', [$this, 'addressbooks_list']);
        $this->add_hook('addressbook_get', [$this, 'addressbook_get']);

        // autouzupełnianie adresów: dołóż do listy źródeł
        $rc = rcmail::get_instance();
        $sources = (array) $rc->config->get('autocomplete_addressbooks', ['sql']);
        if (!in_array(self::ABOOK_ID, $sources, true)) {
            $sources[] = self::ABOOK_ID;
            $rc->config->set('autocomplete_addressbooks', $sources);
        }
    }

    private function allowed(): bool
    {
        $rc = rcmail::get_instance();
        if (empty($rc->user) || empty($rc->user->ID)) {
            return false;
        }
        $hosts = (array) $rc->config->get('feer_directory_hosts', []);
        return !$hosts || in_array((string) ($_SESSION['storage_host'] ?? ''), $hosts, true);
    }

    public function addressbooks_list(array $p): array
    {
        $p['sources'][self::ABOOK_ID] = [
            'id'       => self::ABOOK_ID,
            'name'     => 'Fundacja FEER',
            'readonly' => true,
            'groups'   => false,
        ];
        return $p;
    }

    public function addressbook_get(array $p): array
    {
        if (($p['id'] ?? '') === self::ABOOK_ID) {
            $p['instance'] = new feer_directory_backend(self::people());
        }
        return $p;
    }

    /** @return array<int, array<string, mixed>> */
    public static function people(): array
    {
        static $people = null;
        if ($people !== null) {
            return $people;
        }
        $rc   = rcmail::get_instance();
        $file = rtrim((string) $rc->config->get('temp_dir', sys_get_temp_dir()), '/') . '/feer_directory.json';
        $ttl  = (int) $rc->config->get('feer_directory_ttl', 3600);

        $cached = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        if (is_array($cached) && filemtime($file) > time() - $ttl) {
            return $people = (array) ($cached['people'] ?? []);
        }

        $fresh = self::fetch();
        if ($fresh !== null) {
            @file_put_contents($file . '.tmp', json_encode(['people' => $fresh], JSON_UNESCAPED_UNICODE), LOCK_EX);
            @chmod($file . '.tmp', 0600);
            @rename($file . '.tmp', $file);
            return $people = $fresh;
        }
        // SZO nie odpowiada: ostatnia dobra kopia (nawet przeterminowana)
        return $people = is_array($cached) ? (array) ($cached['people'] ?? []) : [];
    }

    private static function fetch(): ?array
    {
        $rc  = rcmail::get_instance();
        $url = (string) $rc->config->get('feer_directory_url', '');
        $key = trim((string) @file_get_contents((string) $rc->config->get('feer_signature_key_file', '')));
        if ($url === '' || $key === '') {
            return null;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['X-Internal-Key: ' . $key, 'Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 8,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code !== 200 || !is_string($body)) {
            rcube::write_log('errors', "feer_directory: SZO HTTP {$code}");
            return null;
        }
        $j = json_decode($body, true);
        return is_array($j) && isset($j['people']) && is_array($j['people']) ? $j['people'] : null;
    }
}
