<?php
/**
 * login_notice — informacja na stronie logowania Roundcube o wymaganiach
 * konta Microsoft (moduł Poczta, FEER SZO).
 *
 * Wymaga oauth_login_redirect=false w config.inc.php — inaczej strona
 * logowania nigdy się nie renderuje (od razu przekierowanie do Microsoft),
 * więc ten komunikat nie miałby gdzie się pojawić.
 *
 * Treść komunikatu jest zarządzana CENTRALNIE w głównej aplikacji
 * (admin/poczta_settings.php, pole "Komunikat na stronie logowania Roundcube")
 * — ten plugin pobiera ją przez wewnętrzne API (api/internal/rc_login_notice.php)
 * po sieci Docker "feer" (http://app/...), z cache ~5 min, żeby nie odpytywać
 * głównej aplikacji przy każdym wejściu na stronę logowania. Jeśli API jest
 * nieosiągalne (appka niedostępna, brak APP_KEY), pokazuje krótki, generyczny
 * tekst zapasowy zamiast całkowicie zniknąć.
 */

class login_notice extends rcube_plugin
{
    public $task = 'login';

    private const CACHE_TTL = 300; // 5 min
    private const APP_INTERNAL_URL = 'http://app/api/internal/rc_login_notice.php';

    public function init(): void
    {
        $this->add_hook('template_container', [$this, 'add_notice']);
        $this->add_hook('loginform_content', [$this, 'hide_password_form']);
    }

    /**
     * Skrzynki są tylko przez OAuth (XOAUTH2) — bez tego, przy
     * oauth_login_redirect=false, rdzeń Roundcube pokazuje też zwykły
     * formularz login/hasło (ukrywanie tych pól w rdzeniu jest powiązane
     * wyłącznie z flagą oauth_login_redirect). Czyścimy inputs/hidden, nie
     * dotykając 'buttons' — przycisk logowania Microsoft (dodawany przez
     * rdzeń niezależnie od kolejności hooków) zostaje.
     */
    public function hide_password_form(array $form_content): array
    {
        $form_content['inputs'] = [];
        $form_content['hidden'] = [];

        return $form_content;
    }

    public function add_notice(array $args): array
    {
        if (($args['name'] ?? null) !== 'loginfooter') {
            return $args;
        }

        $args['content'] = '<div class="alert alert-info" style="margin:0 0 1em;text-align:left;">'
            . $this->notice_html()
            . '</div>' . $args['content'];

        return $args;
    }

    private function notice_html(): string
    {
        $rcmail = rcmail::get_instance();
        $cache  = $rcmail->get_cache_shared('login_notice');
        $cached = $cache ? $cache->get('html') : null;

        if (is_array($cached) && ($cached['expires'] ?? 0) > time()) {
            return $cached['html'];
        }

        $html = $this->fetch_from_app() ?? $this->fallback_html();

        if ($cache) {
            $cache->set('html', ['html' => $html, 'expires' => time() + self::CACHE_TTL]);
        }

        return $html;
    }

    /** @return ?string Treść z api/internal/rc_login_notice.php, albo null gdy nieosiągalne. */
    private function fetch_from_app(): ?string
    {
        $app_key = getenv('APP_KEY');
        if (!$app_key) {
            return null;
        }

        $ctx = stream_context_create(['http' => [
            'method'        => 'GET',
            'header'        => "X-Internal-Key: {$app_key}\r\n",
            'timeout'       => 3,
            'ignore_errors' => true,
        ]]);
        $resp = @file_get_contents(self::APP_INTERNAL_URL, false, $ctx);
        $data = json_decode($resp ?: '{}', true) ?? [];

        return !empty($data['html']) ? $data['html'] : null;
    }

    /** Krótki tekst zapasowy, gdy główna aplikacja jest nieosiągalna. */
    private function fallback_html(): string
    {
        return 'To oficjalna poczta <strong>Fundacji Edukacji Empatii Rozwoju (FEER)</strong>. '
            . 'Logowanie odbywa się wyłącznie przez konto Microsoft Twojej organizacji.';
    }
}
