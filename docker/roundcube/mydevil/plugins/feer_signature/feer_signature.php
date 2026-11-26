<?php
/**
 * feer_signature — stopka e-mail z SZO w tożsamościach Roundcube (poczta.feer.org.pl).
 *
 * Przy każdym logowaniu pobiera stopkę z SZO (api/internal/rc_signature.php, ta sama
 * co w module Stopka e-mail) i wstawia ją jako podpis HTML w tożsamości o adresie
 * zalogowanego. Zmiana stanowiska, telefonu albo wyglądu w SZO trafia tu przy
 * następnym logowaniu.
 *
 * Własny podpis użytkownika zostaje: nadpisujemy tylko pusty podpis albo taki, który
 * jest dokładnie poprzednią stopką z SZO (skrót w preferencji feer_signature_hash).
 * Gdy SZO nie odpowiada albo nie zna adresu (np. @stud), nic się nie zmienia.
 *
 * Konfiguracja: feer_signature_url, feer_signature_key_file (config.inc.php).
 */
class feer_signature extends rcube_plugin
{
    public $task = 'login';

    public function init(): void
    {
        $this->add_hook('login_after', [$this, 'login_after']);
    }

    public function login_after(array $args): array
    {
        try {
            $this->sync();
        } catch (\Throwable $e) {
            rcube::raise_error(['code' => 500, 'message' => 'feer_signature: ' . $e->getMessage()], true, false);
        }
        return $args;
    }

    private function sync(): void
    {
        $rc    = rcmail::get_instance();
        $email = strtolower((string) $rc->get_user_email());
        if ($email === '' || !$rc->user) {
            return;
        }

        $sig = $this->fetch($email);
        if ($sig === null || $sig['html'] === '') {
            return;
        }
        $newHash = hash('sha256', $sig['html']);

        $prefs   = $rc->user->get_prefs();
        $oldHash = (string) ($prefs['feer_signature_hash'] ?? '');

        foreach ($rc->user->list_identities() as $ident) {
            if (strtolower((string) $ident['email']) !== $email) {
                continue;
            }
            $current = trim((string) $ident['signature']);
            $isOurs  = $current === '' || ($oldHash !== '' && hash('sha256', $current) === $oldHash);
            if (!$isOurs || $current === $sig['html']) {
                continue;
            }
            $data = ['signature' => $sig['html'], 'html_signature' => 1];
            if (trim((string) $ident['name']) === '' && $sig['name'] !== '') {
                $data['name'] = $sig['name'];
            }
            $rc->user->update_identity($ident['identity_id'], $data);
        }

        if ($oldHash !== $newHash) {
            $rc->user->save_prefs(['feer_signature_hash' => $newHash]);
        }
    }

    /** @return array{name:string,html:string}|null */
    private function fetch(string $email): ?array
    {
        $rc  = rcmail::get_instance();
        $url = (string) $rc->config->get('feer_signature_url', '');
        $key = trim((string) @file_get_contents((string) $rc->config->get('feer_signature_key_file', '')));
        if ($url === '' || $key === '') {
            return null;
        }

        $ch = curl_init($url . '?email=' . rawurlencode($email));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['X-Internal-Key: ' . $key, 'Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 5,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($code !== 200 || !is_string($body)) {
            if ($code !== 404) {
                rcube::write_log('errors', "feer_signature: SZO HTTP {$code} dla {$email}");
            }
            return null;
        }
        $j = json_decode($body, true);
        if (!is_array($j) || !isset($j['html'])) {
            return null;
        }
        return ['name' => trim((string) ($j['name'] ?? '')), 'html' => trim((string) $j['html'])];
    }
}
