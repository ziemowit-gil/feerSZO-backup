<?php
/**
 * outlook_contacts_sync — jednorazowa (przy każdym logowaniu, z throttlingiem)
 * synchronizacja kontaktów z Outlooka (Microsoft Graph /me/contacts) do
 * lokalnego adresownika Roundcube — TYLKO jednostronnie (Outlook → Roundcube),
 * żeby dane kontaktów Outlooka były dostępne przy autouzupełnianiu adresata.
 *
 * Bez zapisu z Roundcube do Outlooka — dwukierunkowa synchronizacja wymagałaby
 * rozstrzygania konfliktów i śledzenia usunięć, czego ten mały projekt nie
 * potrzebuje. Dopasowanie istniejący/nowy kontakt odbywa się po adresie e-mail
 * (bez tabeli mapowań ID) — prościej, ale kontakt zmieniony ręcznie w Roundcube
 * pod tym samym adresem e-mail zostanie przy kolejnym sync nadpisany danymi
 * z Outlooka.
 *
 * Token Microsoft Graph — jak w onedrive_picker: WŁASNY token (grant_type=
 * refresh_token, scope Contacts.Read), bo token logowania jest wystawiony dla
 * audience outlook.office365.com i Graph go odrzuci. Wymaga tego samego
 * "offline_access" w oauth_scope (config.inc.php) i dodania uprawnienia
 * Microsoft Graph → Contacts.Read (Delegated) w rejestracji aplikacji Azure AD
 * używanej przez Roundcube (z admin consent) — zob. docker/roundcube/README.md.
 *
 * UWAGA: pisane wg dokumentowanego Roundcube Plugin API, ale bez możliwości
 * przetestowania na żywym Roundcube (brak Azure AD/M365 pod ręką podczas
 * developmentu) — ten sam zastrzeżenie co przy onedrive_picker/owncloud_picker.
 */

class outlook_contacts_sync extends rcube_plugin
{
    public $task = 'login';

    private const GRAPH_BASE   = 'https://graph.microsoft.com/v1.0';
    private const THROTTLE_SEC = 6 * 3600; // nie częściej niż raz na 6h per user
    private const MAX_PAGES    = 10;       // bezpiecznik — max ~1000 kontaktów na sync

    public function init(): void
    {
        $this->add_hook('login_after', [$this, 'sync_after_login']);
    }

    public function sync_after_login(array $args): array
    {
        try {
            $this->run_sync();
        } catch (\Throwable $e) {
            // Błąd synchronizacji nigdy nie ma blokować logowania do poczty.
            rcube::write_log('outlook_contacts_sync', 'Sync error: ' . $e->getMessage());
        }
        return $args;
    }

    private function run_sync(): void
    {
        $rcmail = rcmail::get_instance();
        $prefs  = $rcmail->user->get_prefs();
        $last   = (int) ($prefs['outlook_contacts_last_sync'] ?? 0);
        if (time() - $last < self::THROTTLE_SEC) {
            return;
        }

        $token = $this->access_token();
        if (!$token) {
            return;
        }

        $CONTACTS = $rcmail->get_address_book('', true);
        if (!$CONTACTS) {
            return;
        }

        $synced = 0;
        $path   = '/me/contacts?$top=100&$select=givenName,surname,displayName,emailAddresses,mobilePhone,businessPhones';
        for ($page = 0; $path && $page < self::MAX_PAGES; $page++) {
            [$ok, $data] = $this->graph_get($path, $token);
            if (!$ok) {
                break;
            }
            foreach ($data['value'] ?? [] as $c) {
                if ($this->upsert_contact($CONTACTS, $c)) {
                    $synced++;
                }
            }
            $path = $data['@odata.nextLink'] ?? null;
            if ($path) {
                $path = substr($path, strlen(self::GRAPH_BASE)); // graph_get() dokleja bazowy URL
            }
        }

        $rcmail->user->save_prefs(['outlook_contacts_last_sync' => time()]);
        rcube::write_log('outlook_contacts_sync', "Zsynchronizowano {$synced} kontaktów dla " . $rcmail->user->get_username() . '.');
    }

    /** @return bool true, jeśli kontakt miał adres e-mail i został zapisany (insert/update). */
    private function upsert_contact(rcube_addressbook $CONTACTS, array $c): bool
    {
        $email = $c['emailAddresses'][0]['address'] ?? null;
        if (!$email) {
            return false; // bez e-maila kontakt jest bezużyteczny do autouzupełniania adresata
        }

        $save_data = [
            'firstname' => $c['givenName'] ?? '',
            'surname'   => $c['surname'] ?? '',
            'email:work'=> [$email],
        ];
        if (!empty($c['mobilePhone'])) {
            $save_data['phone:mobile'] = [$c['mobilePhone']];
        }
        if (!empty($c['businessPhones'][0])) {
            $save_data['phone:work'] = [$c['businessPhones'][0]];
        }

        $existing = $CONTACTS->search('email', $email, 1, true, true, ['email']);
        $row      = $existing->count > 0 ? $existing->get(0) : null;

        if ($row) {
            return (bool) $CONTACTS->update($row['ID'], $save_data);
        }
        return (bool) $CONTACTS->insert($save_data);
    }

    /** Token Microsoft Graph — zob. komentarz na górze pliku i onedrive_picker.php. */
    private function access_token(): ?string
    {
        if (!empty($_SESSION['outlook_contacts_graph_token']['access_token'])
            && ($_SESSION['outlook_contacts_graph_token']['expires'] ?? 0) > time()) {
            return $_SESSION['outlook_contacts_graph_token']['access_token'];
        }

        $refresh_token_enc = $_SESSION['oauth_token']['refresh_token'] ?? null;
        if (!$refresh_token_enc) {
            return null;
        }

        $rcmail        = rcmail::get_instance();
        $token_uri     = $rcmail->config->get('oauth_token_uri');
        $client_id     = $rcmail->config->get('oauth_client_id');
        $client_secret = $rcmail->config->get('oauth_client_secret');
        $refresh_token = $rcmail->decrypt($refresh_token_enc);
        if (!$token_uri || !$client_id || !$client_secret || !$refresh_token) {
            return null;
        }

        $form = http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh_token,
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
            'scope'         => 'offline_access https://graph.microsoft.com/Contacts.Read',
        ]);
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content'       => $form,
            'ignore_errors' => true,
            'timeout'       => 15,
        ]]);
        $resp = @file_get_contents($token_uri, false, $ctx);
        $data = json_decode($resp ?: '{}', true) ?? [];
        if (empty($data['access_token'])) {
            return null;
        }

        $_SESSION['outlook_contacts_graph_token'] = [
            'access_token' => $data['access_token'],
            'expires'      => time() + (int) ($data['expires_in'] ?? 3600) - 60,
        ];
        return $data['access_token'];
    }

    /** @return array{0: bool, 1: mixed} [ok, dane_lub_komunikat_bledu] */
    private function graph_get(string $path, string $token): array
    {
        $ctx = stream_context_create(['http' => [
            'method'        => 'GET',
            'header'        => "Authorization: Bearer {$token}\r\n",
            'ignore_errors' => true,
            'timeout'       => 20,
        ]]);
        $resp = @file_get_contents(self::GRAPH_BASE . $path, false, $ctx);
        $data = json_decode($resp ?: '{}', true) ?? [];
        if (isset($data['error'])) {
            return [false, $data['error']['message'] ?? 'Błąd Microsoft Graph.'];
        }
        return [true, $data];
    }
}
