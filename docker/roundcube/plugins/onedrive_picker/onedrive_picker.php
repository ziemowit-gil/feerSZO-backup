<?php
/**
 * onedrive_picker — dołączanie plików z OneDrive przy pisaniu maila (Roundcube).
 *
 * Nie istnieje żaden aktywnie wspierany, gotowy plugin "OneDrive" dla Roundcube —
 * ten plugin jest własnym, minimalnym rozwiązaniem napisanym pod ten projekt.
 * Reużywa token OAuth, który Roundcube dostaje przy logowaniu do Microsoft 365
 * (config.inc.php musi mieć w oauth_scope dodane "https://graph.microsoft.com/Files.Read")
 * — bez tego scope Graph odrzuci zapytania poniżej.
 *
 * UWAGA: pisane wg dokumentowanego Roundcube Plugin API, ale bez możliwości
 * przetestowania na żywym Roundcube w tym środowisku (brak Azure AD/M365 pod ręką
 * podczas developmentu). Kształt $_SESSION['compose_data_*'] i dokładny callback
 * OAuth trzeba zweryfikować po wdrożeniu — zob. docker/roundcube/README.md.
 */

class onedrive_picker extends rcube_plugin
{
    public $task = 'mail';

    private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';

    public function init(): void
    {
        $this->add_hook('template_object_composeAttachmentsForm', [$this, 'compose_attachments_form']);
        $this->register_action('plugin.onedrive_list', [$this, 'action_list']);
        $this->register_action('plugin.onedrive_attach', [$this, 'action_attach']);

        $this->include_script('onedrive_picker.js');
        $this->include_stylesheet('onedrive_picker.css');
    }

    /** Dopisuje przycisk "OneDrive" pod standardowym formularzem załączników compose. */
    public function compose_attachments_form(array $args): array
    {
        $button = html::a(
            [
                'id'    => 'onedrive-picker-btn',
                'href'  => '#',
                'class' => 'button',
                'title' => 'Dołącz plik z OneDrive',
            ],
            'OneDrive'
        );
        $args['content'] .= $button;
        return $args;
    }

    /** GET ?_task=mail&_action=plugin.onedrive_list&folder_id=... — lista plików/folderów. */
    public function action_list(): void
    {
        $token = $this->access_token();
        if (!$token) {
            $this->json_response(['ok' => false, 'message' => 'Brak sesji OAuth — zaloguj się ponownie.']);
        }

        $folder_id = rcube_utils::get_input_string('folder_id', rcube_utils::INPUT_GET) ?: 'root';
        $path = $folder_id === 'root'
            ? '/me/drive/root/children'
            : '/me/drive/items/' . rawurlencode($folder_id) . '/children';

        [$ok, $data] = $this->graph_get($path . '?$select=id,name,folder,file,size', $token);
        if (!$ok) {
            $this->json_response(['ok' => false, 'message' => $data]);
        }

        $items = array_map(static function ($it) {
            return [
                'id'       => $it['id'] ?? '',
                'name'     => $it['name'] ?? '(bez nazwy)',
                'is_folder'=> isset($it['folder']),
                'size'     => $it['size'] ?? 0,
            ];
        }, $data['value'] ?? []);

        $this->json_response(['ok' => true, 'items' => $items]);
    }

    /** GET ?_task=mail&_action=plugin.onedrive_attach&item_id=...&id=<compose_id> — pobierz i dołącz. */
    public function action_attach(): void
    {
        $token = $this->access_token();
        if (!$token) {
            $this->json_response(['ok' => false, 'message' => 'Brak sesji OAuth — zaloguj się ponownie.']);
        }

        $item_id    = rcube_utils::get_input_string('item_id', rcube_utils::INPUT_GET);
        $compose_id = rcube_utils::get_input_string('id', rcube_utils::INPUT_GET);
        if (!$item_id || !$compose_id) {
            $this->json_response(['ok' => false, 'message' => 'Brak item_id lub id (sesji compose).']);
        }

        [$ok, $meta] = $this->graph_get('/me/drive/items/' . rawurlencode($item_id) . '?$select=name,size,file', $token);
        if (!$ok) {
            $this->json_response(['ok' => false, 'message' => $meta]);
        }
        $name      = $meta['name'] ?? 'plik';
        $mimetype  = $meta['file']['mimeType'] ?? 'application/octet-stream';

        $rcmail  = rcmail::get_instance();
        $tmp_dir = $rcmail->config->get('temp_dir', sys_get_temp_dir());
        $tmp_path = tempnam($tmp_dir, 'onedrive_');

        $bytes = $this->graph_get_binary('/me/drive/items/' . rawurlencode($item_id) . '/content', $token);
        if ($bytes === null) {
            @unlink($tmp_path);
            $this->json_response(['ok' => false, 'message' => 'Nie udało się pobrać pliku z OneDrive.']);
        }
        file_put_contents($tmp_path, $bytes);

        $attachment = [
            'name'     => $name,
            'mimetype' => $mimetype,
            'size'     => filesize($tmp_path),
            'path'     => $tmp_path,
            'group'    => $compose_id,
        ];

        // Standardowy wewnętrzny kształt listy załączników compose (jak przy zwykłym
        // uploadzie pliku) — patrz program/steps/mail/compose.php w rdzeniu Roundcube.
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

    /** Token OAuth zapisany przez rdzeń Roundcube przy logowaniu Microsoft 365. */
    private function access_token(): ?string
    {
        return $_SESSION['oauth_token']['access_token'] ?? null;
    }

    /** @return array{0: bool, 1: mixed} [ok, dane_lub_komunikat_bledu] */
    private function graph_get(string $path, string $token): array
    {
        $ctx = stream_context_create(['http' => [
            'method'        => 'GET',
            'header'        => "Authorization: Bearer {$token}\r\n",
            'ignore_errors' => true,
            'timeout'       => 15,
        ]]);
        $resp = @file_get_contents(self::GRAPH_BASE . $path, false, $ctx);
        $data = json_decode($resp ?: '{}', true) ?? [];
        if (isset($data['error'])) {
            return [false, $data['error']['message'] ?? 'Błąd Microsoft Graph.'];
        }
        return [true, $data];
    }

    private function graph_get_binary(string $path, string $token): ?string
    {
        $ctx = stream_context_create(['http' => [
            'method'        => 'GET',
            'header'        => "Authorization: Bearer {$token}\r\n",
            'ignore_errors' => true,
            'timeout'       => 60,
        ]]);
        $resp = @file_get_contents(self::GRAPH_BASE . $path, false, $ctx);
        return $resp === false ? null : $resp;
    }

    private function json_response(array $data): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
        exit;
    }
}
