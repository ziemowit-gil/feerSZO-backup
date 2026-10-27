<?php
/**
 * modules/whatsapp/logic/WhatsappController.php — panel administratora: bramka WhatsApp, szablony, test wysyłki, dziennik.
 */
require_once __DIR__ . '/WhatsappService.php';

final class WhatsappController
{
    private const VIEW = __DIR__ . '/../view/';

    public function index(): string
    {
        WhatsappService::migrate();
        $templates = WhatsappService::templates();
        $editId = (int)($_GET['edit'] ?? 0);
        $edit = null;
        foreach ($templates as $t) if ((int)$t['id'] === $editId) $edit = $t;
        return $this->page('Ustawienia WhatsApp', Router::view(self::VIEW . 'index.php', [
            'enabled' => WhatsappService::isEnabled(),
            's' => [
                'url' => WhatsappService::setting('whatsapp_api_url'),
                'auth_header' => WhatsappService::setting('whatsapp_auth_header') ?: 'Authorization',
                'auth_prefix' => WhatsappService::setting('whatsapp_auth_prefix') !== '' ? WhatsappService::setting('whatsapp_auth_prefix') : 'Bearer ',
                'has_token' => WhatsappService::setting('whatsapp_api_token') !== '',
                'body_template' => WhatsappService::setting('whatsapp_body_template'),
                'ok_path' => WhatsappService::setting('whatsapp_ok_path'),
                'id_path' => WhatsappService::setting('whatsapp_id_path'),
            ],
            'templates' => $templates, 'edit' => $edit,
            'messages' => WhatsappService::recent(30),
            'smsSettingsUrl' => APP_URL . '/admin/sms_settings.php',
        ]));
    }

    public function saveSettings(): ?string
    {
        WhatsappService::migrate();
        $url = trim((string)($_POST['whatsapp_api_url'] ?? ''));
        $header = trim((string)($_POST['whatsapp_auth_header'] ?? '')) ?: 'Authorization';
        $tplBody = trim((string)($_POST['whatsapp_body_template'] ?? ''));
        if ($url !== '' && ($e = WhatsappHttpGateway::urlError($url))) return $this->fail($e);
        if (!preg_match('/^[A-Za-z0-9-]+$/', $header)) return $this->fail('Nazwa nagłówka uwierzytelnienia: litery, cyfry i myślnik.');
        if ($tplBody !== '') {
            try {   // próbny render z przykładowymi wartościami — wychwytuje błąd składni JSON przed zapisem
                WhatsappHttpGateway::renderBody($tplBody, ['to' => '48600700800', 'template' => 'x', 'language' => 'pl', 'text' => 'a "b" {c}'], ['Anna', '12:00']);
            } catch (WhatsappGatewayException $e) {
                return $this->fail($e->getMessage());
            }
        }
        $token = trim((string)($_POST['whatsapp_api_token'] ?? ''));
        if ($token !== '') WhatsappService::settingSet('whatsapp_api_token', $token);   // puste pole = zostaw dotychczasowy token
        if (!empty($_POST['clear_token'])) WhatsappService::settingSet('whatsapp_api_token', '');
        WhatsappService::settingSet('whatsapp_api_url', $url);
        WhatsappService::settingSet('whatsapp_auth_header', $header);
        WhatsappService::settingSet('whatsapp_auth_prefix', (string)($_POST['whatsapp_auth_prefix'] ?? 'Bearer '));
        WhatsappService::settingSet('whatsapp_body_template', $tplBody);
        WhatsappService::settingSet('whatsapp_ok_path', mb_substr(trim((string)($_POST['whatsapp_ok_path'] ?? '')), 0, 100));
        WhatsappService::settingSet('whatsapp_id_path', mb_substr(trim((string)($_POST['whatsapp_id_path'] ?? '')), 0, 100));
        WhatsappService::settingSet('whatsapp_enabled', !empty($_POST['whatsapp_enabled']) ? '1' : '0');
        audit_log('whatsapp.settings_save', ['enabled' => !empty($_POST['whatsapp_enabled']), 'token_changed' => $token !== '']);
        flash_set('success', 'Ustawienia WhatsApp zapisane.');
        return Router::redirect(route('whatsapp.index'));
    }

    public function saveTemplate(): ?string
    {
        try {
            WhatsappService::saveTemplate($_POST);
            flash_set('success', 'Szablon zapisany.');
        } catch (\InvalidArgumentException $e) {
            flash_set('danger', $e->getMessage());
        }
        return Router::redirect(route('whatsapp.index'));
    }

    public function deleteTemplate(int $id): ?string
    {
        WhatsappService::deleteTemplate($id);
        audit_log('whatsapp.template_delete', ['id' => $id]);
        flash_set('success', 'Szablon usunięty (dziennik wysyłek zostaje).');
        return Router::redirect(route('whatsapp.index'));
    }

    /** Wysyła próbną wiadomość na wskazany numer; zapasowy SMS tylko za zgodą (pole), żeby test sprawdzał sam WhatsApp. */
    public function test(): ?string
    {
        $text = trim((string)($_POST['params_text'] ?? ''));
        $params = $text === '' ? [] : array_map('trim', preg_split('/\R/', $text));   // jeden parametr w wierszu: {{1}}, {{2}}…
        try {
            $r = WhatsappService::send((string)($_POST['phone'] ?? ''), (string)($_POST['tpl_key'] ?? ''), $params, [
                'consent' => !empty($_POST['consent']),
                'smsFallback' => !empty($_POST['allow_sms']),
                'userId' => (int)current_user()['id'],
            ]);
            flash_set($r['channel'] === 'whatsapp' ? 'success' : 'warning',
                $r['channel'] === 'whatsapp'
                    ? 'Wysłano przez WhatsApp' . ($r['id'] !== '' ? ' (id ' . $r['id'] . ').' : '.')
                    : 'WhatsApp zawiódł (' . $r['error'] . ') — wiadomość poszła zapasowym SMS-em.');
        } catch (\Throwable $e) {
            flash_set('danger', 'Test nieudany: ' . $e->getMessage());
        }
        return Router::redirect(route('whatsapp.index'));
    }

    private function fail(string $msg): ?string
    {
        flash_set('danger', $msg);
        return Router::redirect(route('whatsapp.index'));
    }

    private function page(string $title, string $body): string
    {
        $PAGE_TITLE = $title;
        ob_start();
        include dirname(__DIR__, 3) . '/includes/header.php';
        echo $body;
        include dirname(__DIR__, 3) . '/includes/footer.php';
        return (string)ob_get_clean();
    }
}
