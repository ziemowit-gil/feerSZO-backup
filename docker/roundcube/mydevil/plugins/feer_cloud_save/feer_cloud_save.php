<?php
/**
 * feer_cloud_save — poczta.feer.org.pl ↔ chmura FEER (nc.feer.org.pl).
 *
 * 1) „Zapisz w chmurze” dla załączników odebranych wiadomości: menu załącznika
 *    (przycisk ▾ przy pliku) → „Zapisz w chmurze” i, przy kilku plikach,
 *    „Zapisz wszystkie w chmurze”. WebDAV do
 *      <feer_cloud_save_folder>/RRRR-MM/<nazwa>   (domyślnie „Załączniki z poczty/Odebrane”)
 *    zajęta nazwa dostaje dopisek „ (2)”, „ (3)”…
 *
 * 2) Połączenie z chmurą: Nextcloud Login Flow v2. W chmurze logowanie idzie kontem FEER
 *    (Logto), więc hasło do poczty (inne) nie jest potrzebne. Wynik (loginName +
 *    appPassword) trafia do preferencji nextcloud_login — tej samej, której używa wtyczka
 *    nextcloud_attachments (duże załączniki jako link).
 *    Po zalogowaniu do poczty (tylko konta z feer_cloud_prompt_hosts, raz na sesję)
 *    okienko zachęca do połączenia: Połącz / Przypomnij za tydzień / Nie pokazuj więcej
 *    (preferencja feer_cloud_prompt = 'never' albo znacznik czasu „nie pytaj do”).
 */
class feer_cloud_save extends rcube_plugin
{
    public $task = 'mail';

    private const MAX_BYTES = 200 * 1024 * 1024;
    private const FLOW_TTL  = 1200;   // Login Flow v2 ważny 20 min

    public function init(): void
    {
        $rc = rcmail::get_instance();
        $this->register_action('plugin.feer_cloud_save', [$this, 'action_save']);
        $this->register_action('plugin.feer_cloud_connect', [$this, 'action_connect']);
        $this->register_action('plugin.feer_cloud_poll', [$this, 'action_poll']);
        $this->register_action('plugin.feer_cloud_prompt', [$this, 'action_prompt']);

        if ($rc->output->type !== 'html') {
            return;
        }
        $this->add_texts('localization/', true);
        $this->include_script('feer_cloud_save.js');

        // Główny widok poczty: czy zaproponować połączenie z chmurą
        if (($rc->action === '' || $rc->action === 'index') && empty($_SESSION['feer_cloud_prompted'])) {
            $_SESSION['feer_cloud_prompted'] = 1;
            if ($this->should_prompt()) {
                $rc->output->set_env('feer_cloud_prompt', true);
            }
        }

        if (in_array($rc->action, ['show', 'preview'], true)) {
            $this->add_button([
                'command'  => 'plugin.feer_cloud_save',
                'type'     => 'link-menuitem',
                'label'    => 'feer_cloud_save.save',
                'class'    => 'feer-cloud disabled',
                'classact' => 'feer-cloud active',
                'id'       => 'attachmenucloud',
            ], 'attachmentmenu');
            $this->add_button([
                'command'  => 'plugin.feer_cloud_save_all',
                'type'     => 'link-menuitem',
                'label'    => 'feer_cloud_save.saveall',
                'class'    => 'feer-cloud-all disabled',
                'classact' => 'feer-cloud-all active',
                'id'       => 'attachmenucloudall',
            ], 'attachmentmenu');
        }
    }

    /** Połączone z chmurą? (preferencja z Login Flow) */
    private function connected(): bool
    {
        $login = (array) (rcmail::get_instance()->user->get_prefs()['nextcloud_login'] ?? []);
        return !empty($login['loginName']) && !empty($login['appPassword']);
    }

    private function should_prompt(): bool
    {
        $rc = rcmail::get_instance();
        if ($this->connected() || (string) $rc->config->get('nextcloud_attachment_server', '') === '') {
            return false;
        }
        $hosts = (array) $rc->config->get('feer_cloud_prompt_hosts', []);
        if ($hosts && !in_array((string) ($_SESSION['storage_host'] ?? ''), $hosts, true)) {
            return false;
        }
        $p = (string) ($rc->user->get_prefs()['feer_cloud_prompt'] ?? '');
        return $p !== 'never' && (int) $p < time();
    }

    /** Odpowiedź z okienka zachęty: later (7 dni) albo never. */
    public function action_prompt(): void
    {
        $rc  = rcmail::get_instance();
        $ans = rcube_utils::get_input_string('_answer', rcube_utils::INPUT_POST);
        if ($ans === 'never' || $ans === 'later') {
            $rc->user->save_prefs(['feer_cloud_prompt' => $ans === 'never' ? 'never' : (string) (time() + 7 * 86400)]);
        }
        $rc->output->send();
    }

    /** Start Login Flow v2: adres strony logowania w chmurze. */
    public function action_connect(): void
    {
        $rc  = rcmail::get_instance();
        $srv = rtrim((string) $rc->config->get('nextcloud_attachment_server', ''), '/');
        $res = $this->http('POST', $srv . '/index.php/login/v2', '');
        $j   = json_decode((string) $res['body'], true);
        if ($res['code'] !== 200 || empty($j['login']) || empty($j['poll']['token'])) {
            rcube::raise_error(['code' => 500, 'message' => "feer_cloud_save: login/v2 HTTP {$res['code']}"], true, false);
            $rc->output->command('plugin.feer_cloud_flow', ['status' => 'error']);
            $rc->output->send();
            return;
        }
        $_SESSION['feer_cloud_flow'] = [
            'endpoint' => (string) $j['poll']['endpoint'],
            'token'    => (string) $j['poll']['token'],
            'until'    => time() + self::FLOW_TTL,
        ];
        $rc->output->command('plugin.feer_cloud_flow', ['status' => 'ok', 'url' => (string) $j['login']]);
        $rc->output->send();
    }

    /** Sprawdzenie, czy użytkownik zatwierdził dostęp w chmurze. */
    public function action_poll(): void
    {
        $rc   = rcmail::get_instance();
        $flow = $_SESSION['feer_cloud_flow'] ?? null;
        $srv  = rtrim((string) $rc->config->get('nextcloud_attachment_server', ''), '/');

        if (!$flow || $flow['until'] < time() || strpos($flow['endpoint'], $srv . '/') !== 0) {
            unset($_SESSION['feer_cloud_flow']);
            $rc->output->command('plugin.feer_cloud_flow_done', ['status' => 'expired']);
            $rc->output->send();
            return;
        }
        $res = $this->http('POST', $flow['endpoint'], 'token=' . rawurlencode($flow['token']));
        if ($res['code'] === 200) {
            $j = json_decode((string) $res['body'], true);
            if (!empty($j['loginName']) && !empty($j['appPassword'])) {
                $prefs = $rc->user->get_prefs();
                $prefs['nextcloud_login'] = [
                    'server'      => (string) ($j['server'] ?? $srv),
                    'loginName'   => (string) $j['loginName'],
                    'appPassword' => (string) $j['appPassword'],
                ];
                $rc->user->save_prefs($prefs);
                unset($_SESSION['feer_cloud_flow'], $_SESSION['plugins']['nextcloud_attachments']);
                $rc->output->command('plugin.feer_cloud_flow_done', ['status' => 'ok']);
                $rc->output->send();
                return;
            }
        }
        $rc->output->command('plugin.feer_cloud_flow_done', ['status' => $res['code'] === 404 ? 'wait' : 'error']);
        $rc->output->send();
    }

    /** @return array{code:int,body:string} */
    private function http(string $method, string $url, string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
            // nazwa urządzenia widoczna w chmurze: Ustawienia → Bezpieczeństwo
            CURLOPT_USERAGENT      => 'Poczta FEER (poczta.feer.org.pl)',
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        ]);
        $out  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['code' => $code, 'body' => is_string($out) ? $out : ''];
    }

    public function action_save(): void
    {
        $rc = rcmail::get_instance();
        $this->add_texts('localization/');

        $uid  = rcube_utils::get_input_string('_uid', rcube_utils::INPUT_POST);
        $mbox = rcube_utils::get_input_string('_mbox', rcube_utils::INPUT_POST);
        $part = rcube_utils::get_input_string('_part', rcube_utils::INPUT_POST);
        $all  = (bool) rcube_utils::get_input_value('_all', rcube_utils::INPUT_POST);

        $prefs = $rc->user->get_prefs();
        $login = (array) ($prefs['nextcloud_login'] ?? []);
        $user  = (string) ($login['loginName'] ?? '');
        $pass  = (string) ($login['appPassword'] ?? '');
        $srv   = rtrim((string) $rc->config->get('nextcloud_attachment_server', ''), '/');

        if ($srv === '' || $user === '' || $pass === '') {
            $rc->output->command('plugin.feer_cloud_save_connect');
            $rc->output->send();
            return;
        }
        if ($uid === '' || $mbox === '' || (!$all && $part === '')) {
            $rc->output->show_message('feer_cloud_save.error', 'error');
            $rc->output->send();
            return;
        }

        $message = new rcube_message($uid, $mbox);
        $parts = [];
        foreach ($message->attachments as $att) {
            if ($all || (string) $att->mime_id === $part) {
                $parts[] = $att;
            }
        }
        if (!$parts) {
            $rc->output->show_message('feer_cloud_save.error', 'error');
            $rc->output->send();
            return;
        }

        $base   = trim((string) $rc->config->get('feer_cloud_save_folder', 'Załączniki z poczty/Odebrane'), '/');
        $folder = $base . '/' . date('Y-m');
        $dav    = $srv . '/remote.php/dav/files/' . rawurlencode($user) . '/';

        try {
            $this->mkcol_path($dav, $folder, $user, $pass);
            $saved = [];
            foreach ($parts as $att) {
                $saved[] = $this->save_part($message, $att, $dav, $folder, $user, $pass);
            }
        } catch (\Throwable $e) {
            rcube::raise_error(['code' => 500, 'message' => 'feer_cloud_save: ' . $e->getMessage()], true, false);
            $msg = $e->getCode() === 401 || $e->getCode() === 403 ? 'feer_cloud_save.autherror' : 'feer_cloud_save.error';
            $rc->output->show_message($msg, 'error');
            $rc->output->send();
            return;
        }

        $text = count($saved) === 1
            ? $this->gettext(['name' => 'savedone', 'vars' => ['path' => $folder . '/' . $saved[0]]])
            : $this->gettext(['name' => 'savedmany', 'vars' => ['n' => count($saved), 'path' => $folder]]);
        $rc->output->show_message($text, 'confirmation', null, false, 8);
        $rc->output->send();
    }

    private function save_part(rcube_message $message, rcube_message_part $att, string $dav, string $folder, string $user, string $pass): string
    {
        $name = $this->safe_name((string) ($att->filename ?: rcmail_action_mail_index::attachment_name($att)));

        $tmp = tmpfile();
        if (!$tmp || !$message->get_part_body($att->mime_id, false, 0, $tmp)) {
            throw new \RuntimeException('nie udało się pobrać części ' . $att->mime_id);
        }
        $size = fstat($tmp)['size'] ?? 0;
        if ($size > self::MAX_BYTES) {
            throw new \RuntimeException('plik za duży: ' . $size);
        }

        $final = $this->free_name($dav, $folder, $name, $user, $pass);
        rewind($tmp);
        $code = $this->dav('PUT', $dav . $this->enc_path($folder . '/' . $final), $user, $pass, [
            CURLOPT_UPLOAD     => true,
            CURLOPT_INFILE     => $tmp,
            CURLOPT_INFILESIZE => $size,
        ], ['Content-Type: ' . ($att->mimetype ?: 'application/octet-stream')]);
        fclose($tmp);

        if ($code !== 201 && $code !== 204) {
            throw new \RuntimeException("PUT HTTP {$code}", $code);
        }
        return $final;
    }

    /** Wolna nazwa w folderze: plik.pdf, plik (2).pdf, plik (3).pdf… */
    private function free_name(string $dav, string $folder, string $name, string $user, string $pass): string
    {
        $dot  = strrpos($name, '.');
        $stem = $dot > 0 ? substr($name, 0, $dot) : $name;
        $ext  = $dot > 0 ? substr($name, $dot) : '';
        for ($i = 1; $i <= 50; $i++) {
            $try  = $i === 1 ? $name : "{$stem} ({$i}){$ext}";
            $code = $this->dav('HEAD', $dav . $this->enc_path($folder . '/' . $try), $user, $pass, [CURLOPT_NOBODY => true]);
            if ($code === 404) {
                return $try;
            }
            if ($code === 401 || $code === 403) {
                throw new \RuntimeException("HEAD HTTP {$code}", $code);
            }
        }
        return $stem . ' (' . date('His') . ')' . $ext;
    }

    private function mkcol_path(string $dav, string $path, string $user, string $pass): void
    {
        $acc = '';
        foreach (explode('/', $path) as $seg) {
            $acc .= ($acc === '' ? '' : '/') . $seg;
            $code = $this->dav('MKCOL', $dav . $this->enc_path($acc), $user, $pass);
            // 201 utworzony, 405 już jest
            if ($code !== 201 && $code !== 405) {
                throw new \RuntimeException("MKCOL HTTP {$code}", $code);
            }
        }
    }

    private function dav(string $method, string $url, string $user, string $pass, array $opts = [], array $headers = []): int
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, $opts + [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_USERPWD        => $user . ':' . $pass,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HTTPHEADER     => array_merge(['OCS-APIRequest: true'], $headers),
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $code;
    }

    private function enc_path(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /** Nazwa bezpieczna dla Nextclouda: bez ukośników, znaków sterujących i kropek na początku. */
    private function safe_name(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '_', $name);
        $name = trim(ltrim((string) $name, '.'));
        if ($name === '') {
            $name = 'zalacznik';
        }
        return mb_substr($name, 0, 200);
    }
}
