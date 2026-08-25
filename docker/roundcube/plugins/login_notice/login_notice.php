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
        $this->add_hook('template_container', [$this, 'add_title']);
        $this->add_hook('loginform_content', [$this, 'hide_password_form']);
    }

    /**
     * Nagłówek nad formularzem: „Poczta <organizacja>".
     *
     * Pozostałe wejścia do systemu (SZO, CRM, panel TI) przedstawiają się nazwą
     * modułu i organizacji. Roundcube pokazywał samo logo, więc po wejściu na
     * rc.feer.org.pl nie było wiadomo, czyja to poczta — ma to znaczenie, bo
     * ekran logowania jest tym, na co patrzy się przed podaniem hasła.
     *
     * Nazwę organizacji bierzemy z tej samej odpowiedzi API co komunikat
     * (api/internal/rc_login_notice.php); gdy jej nie ma, zostaje sama „Poczta".
     */
    public function add_title(array $args): array
    {
        if (($args['name'] ?? null) !== 'loginfooter') {
            return $args;
        }

        $org = trim((string)($this->notice_data()['org'] ?? ''));
        $args['content'] = '<h1 class="feer-login-title">Poczta</h1>'
            . ($org !== '' ? '<p class="feer-login-sub">' . rcube::Q($org) . '</p>' : '')
            . $args['content'];

        return $args;
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
        return (string)($this->notice_data()['html'] ?? $this->fallback_html());
    }

    /**
     * Cała odpowiedź API (html + nazwa organizacji), z jednym wspólnym cache.
     *
     * Dwa hooki na tej samej stronie (komunikat i nagłówek) pytały niezależnie,
     * co przy pustym cache dawało dwa wywołania po sieci przy jednym wejściu
     * na stronę logowania.
     *
     * @return array{html:string,org:string}
     */
    private function notice_data(): array
    {
        static $memo = null;
        if ($memo !== null) return $memo;

        $rcmail = rcmail::get_instance();
        $cache  = $rcmail->get_cache_shared('login_notice');
        $cached = $cache ? $cache->get('data') : null;

        if (is_array($cached) && ($cached['expires'] ?? 0) > time()) {
            return $memo = ['html' => (string)$cached['html'], 'org' => (string)($cached['org'] ?? '')];
        }

        $fetched = $this->fetch_from_app();
        $data = [
            'html' => $fetched['html'] ?? $this->fallback_html(),
            'org'  => $fetched['org']  ?? '',
        ];

        if ($cache) {
            $cache->set('data', $data + ['expires' => time() + self::CACHE_TTL]);
        }

        return $memo = $data;
    }

    /** @return ?array{html:string,org:string} Dane z API, albo null gdy nieosiągalne. */
    private function fetch_from_app(): ?array
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

        if (empty($data['html'])) return null;
        return ['html' => (string)$data['html'], 'org' => (string)($data['org'] ?? '')];
    }

    /** Krótki tekst zapasowy, gdy główna aplikacja jest nieosiągalna. */
    private function fallback_html(): string
    {
        return 'To oficjalna poczta <strong>Fundacji Edukacji Empatii Rozwoju (FEER)</strong>. '
            . 'Logowanie odbywa się wyłącznie przez konto Microsoft Twojej organizacji.';
    }
}
