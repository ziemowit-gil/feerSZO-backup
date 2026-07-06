<?php
/**
 * owncloud_picker — dołączanie plików z ownCloud przy pisaniu maila (Roundcube).
 *
 * Analogiczny do onedrive_picker, ale przez WebDAV (Basic Auth) na WSPÓLNE
 * konto integracyjne ownCloud Fundacji (to samo konto, którego główna aplikacja
 * używa do magazynu plików lekcji — zob. includes/owncloud.php), NIE na osobiste
 * konto każdego użytkownika poczty. Powód: pracownicy logują się do poczty przez
 * Microsoft 365, a nie każdy ma (albo powinien mieć) własne konto ownCloud —
 * ownCloud w tym projekcie jest kontem serwisowym/magazynem współdzielonym, nie
 * per-user SSO. Przycisk "ownCloud" w compose pokazuje więc wspólny katalog
 * bazowy (RC_OWNCLOUD_BASE_FOLDER), tak jak każdy inny magazyn zespołowy.
 *
 * Dane logowania podawane osobno dla Roundcube (RC_OWNCLOUD_*, docker-compose.rc.yml)
 * — kontener "rc" nie ma dostępu do bazy głównej aplikacji, więc nie może odczytać
 * ustawień z tabeli settings (owncloud_url/username/password) bezpośrednio.
 *
 * UWAGA: pisane wg dokumentowanego Roundcube Plugin API i WebDAV, ale bez
 * możliwości przetestowania na żywym Roundcube/ownCloud w tym środowisku — ten
 * sam zastrzeżenie co przy onedrive_picker, zob. docker/roundcube/README.md.
 */

class owncloud_picker extends rcube_plugin
{
    public $task = 'mail';

    public function init(): void
    {
        $this->add_hook('template_object_composeAttachmentsForm', [$this, 'compose_attachments_form']);
        $this->register_action('plugin.owncloud_list', [$this, 'action_list']);
        $this->register_action('plugin.owncloud_attach', [$this, 'action_attach']);

        $this->include_script('owncloud_picker.js');
        $this->include_stylesheet('owncloud_picker.css');
    }

    /** Dopisuje przycisk "ownCloud" pod standardowym formularzem załączników compose. */
    public function compose_attachments_form(array $args): array
    {
        if (!$this->configured()) {
            return $args;
        }
        $button = html::a(
            [
                'id'    => 'owncloud-picker-btn',
                'href'  => '#',
                'class' => 'button',
                'title' => 'Dołącz plik z ownCloud',
            ],
            'ownCloud'
        );
        $args['content'] .= $button;
        return $args;
    }

    /** GET ?_task=mail&_action=plugin.owncloud_list&path=... — lista plików/folderów. */
    public function action_list(): void
    {
        if (!$this->configured()) {
            $this->json_response(['ok' => false, 'message' => 'Integracja ownCloud nie jest skonfigurowana.']);
        }

        $path = rcube_utils::get_input_string('path', rcube_utils::INPUT_GET) ?: '';
        [$ok, $data] = $this->webdav_propfind($path);
        if (!$ok) {
            $this->json_response(['ok' => false, 'message' => $data]);
        }

        $this->json_response(['ok' => true, 'items' => $data]);
    }

    /** GET ?_task=mail&_action=plugin.owncloud_attach&path=...&id=<compose_id> — pobierz i dołącz. */
    public function action_attach(): void
    {
        if (!$this->configured()) {
            $this->json_response(['ok' => false, 'message' => 'Integracja ownCloud nie jest skonfigurowana.']);
        }

        $path       = rcube_utils::get_input_string('path', rcube_utils::INPUT_GET);
        $compose_id = rcube_utils::get_input_string('id', rcube_utils::INPUT_GET);
        if (!$path || !$compose_id) {
            $this->json_response(['ok' => false, 'message' => 'Brak path lub id (sesji compose).']);
        }

        [$ok, $resp] = $this->webdav_request('GET', $this->webdav_url($path));
        if (!$ok) {
            $this->json_response(['ok' => false, 'message' => 'Nie udało się pobrać pliku z ownCloud (' . $resp['http'] . ').']);
        }

        $name     = rawurldecode(basename($path));
        $mimetype = $resp['content_type'] ?: 'application/octet-stream';

        $rcmail   = rcmail::get_instance();
        $tmp_dir  = $rcmail->config->get('temp_dir', sys_get_temp_dir());
        $tmp_path = tempnam($tmp_dir, 'owncloud_');
        file_put_contents($tmp_path, $resp['body']);

        $attachment = [
            'name'     => $name,
            'mimetype' => $mimetype,
            'size'     => filesize($tmp_path),
            'path'     => $tmp_path,
            'group'    => $compose_id,
        ];

        // Standardowy wewnętrzny kształt listy załączników compose — jak w onedrive_picker,
        // patrz program/steps/mail/compose.php w rdzeniu Roundcube.
        $key = $rcmail->plugins->exec_hook('attachment_save', $attachment);
        if (empty($key['status'])) {
            @unlink($tmp_path);
            $this->json_response(['ok' => false, 'message' => 'Nie udało się zapisać załącznika.']);
        }

        $this->json_response([
            'ok'   => true,
            'item' => [
                'id'       => $key['id'] ?? $name,
                'name'     => $name,
                'mimetype' => $mimetype,
                'size'     => $attachment['size'],
                'complete' => true,
            ],
        ]);
    }

    private function configured(): bool
    {
        $c = rcmail::get_instance()->config;
        return $c->get('owncloud_url') && $c->get('owncloud_username') && $c->get('owncloud_password');
    }

    /** Pełny URL WebDAV dla ścieżki względnej katalogu bazowego (jak includes/owncloud.php głównej apki). */
    private function webdav_url(string $relative_path): string
    {
        $c        = rcmail::get_instance()->config;
        $base     = trim((string) $c->get('owncloud_base_folder'), '/');
        $username = (string) $c->get('owncloud_username');
        $full     = trim($base . '/' . ltrim($relative_path, '/'), '/');
        $segments = $full === '' ? [] : array_map('rawurlencode', explode('/', $full));
        return rtrim((string) $c->get('owncloud_url'), '/')
            . '/remote.php/dav/files/' . rawurlencode($username) . '/'
            . implode('/', $segments);
    }

    /**
     * PROPFIND (Depth: 1) na $relative_path — zwraca [ok, lista_elementów_lub_komunikat_bledu].
     * Pierwszy <d:response> to zawsze sam katalog (self) — pomijany.
     */
    private function webdav_propfind(string $relative_path): array
    {
        $body = '<?xml version="1.0"?>'
            . '<d:propfind xmlns:d="DAV:"><d:prop>'
            . '<d:resourcetype/><d:getcontentlength/><d:getcontenttype/>'
            . '</d:prop></d:propfind>';

        [$ok, $resp] = $this->webdav_request('PROPFIND', $this->webdav_url($relative_path), $body, [
            'Content-Type: application/xml; charset=utf-8',
            'Depth: 1',
        ]);
        if (!$ok) {
            return [false, 'Błąd połączenia z ownCloud (HTTP ' . $resp['http'] . ').'];
        }

        $xml = @simplexml_load_string($resp['body']);
        if ($xml === false) {
            return [false, 'Nieprawidłowa odpowiedź WebDAV ownCloud.'];
        }
        $xml->registerXPathNamespace('d', 'DAV:');
        $responses = $xml->xpath('//d:response');
        if (!$responses) {
            return [true, []];
        }

        $items = [];
        foreach (array_slice($responses, 1) as $r) { // pomiń pierwszy — to sam katalog
            $r->registerXPathNamespace('d', 'DAV:');
            $href    = (string) ($r->xpath('d:href')[0] ?? '');
            $is_dir  = count($r->xpath('d:propstat/d:prop/d:resourcetype/d:collection')) > 0;
            $size    = (string) ($r->xpath('d:propstat/d:prop/d:getcontentlength')[0] ?? '0');
            $name    = rawurldecode(basename(rtrim($href, '/')));
            if ($name === '') {
                continue;
            }
            $rel_path = trim($relative_path, '/') . '/' . $name;
            $items[]  = [
                'name'      => $name,
                'path'      => trim($rel_path, '/'),
                'is_folder' => $is_dir,
                'size'      => (int) $size,
            ];
        }

        return [true, $items];
    }

    /** @return array{0: bool, 1: array{http:int, body:string, content_type:string}} */
    private function webdav_request(string $method, string $url, string $body = '', array $extra_headers = []): array
    {
        $c    = rcmail::get_instance()->config;
        $auth = base64_encode($c->get('owncloud_username') . ':' . $c->get('owncloud_password'));
        $headers = array_merge(["Authorization: Basic {$auth}"], $extra_headers);

        $ctx = stream_context_create(['http' => [
            'method'        => $method,
            'header'        => implode("\r\n", $headers) . "\r\n",
            'content'       => $body,
            'ignore_errors' => true,
            'timeout'       => 60,
        ]]);

        $raw = @file_get_contents($url, false, $ctx);
        $resp_headers = $http_response_header ?? [];
        $code = 0;
        $content_type = '';
        foreach ($resp_headers as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $code = (int) $m[1];
            } elseif (stripos($h, 'Content-Type:') === 0) {
                $content_type = trim(substr($h, strlen('Content-Type:')));
            }
        }

        if ($raw === false) {
            return [false, ['http' => $code, 'body' => '', 'content_type' => '']];
        }
        $ok = $code >= 200 && $code < 300;
        return [$ok, ['http' => $code, 'body' => $raw, 'content_type' => $content_type]];
    }

    private function json_response(array $data): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
        exit;
    }
}
