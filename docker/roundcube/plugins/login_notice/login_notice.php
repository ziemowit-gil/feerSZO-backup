<?php
/**
 * login_notice — informacja na stronie logowania Roundcube o wymaganiach
 * konta Microsoft (moduł Poczta, FEER SZO).
 *
 * Wymaga oauth_login_redirect=false w config.inc.php — inaczej strona
 * logowania nigdy się nie renderuje (od razu przekierowanie do Microsoft),
 * więc ten komunikat nie miałby gdzie się pojawić.
 */

class login_notice extends rcube_plugin
{
    public $task = 'login';

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
            . 'Z uwagi na problemy z logowaniem do kont Microsoft w domenie <strong>feer.org.pl</strong>, '
            . 'od 1 sierpnia logowanie do poczty będzie możliwe wyłącznie przez '
            . '<strong>poczta.feer.org.pl</strong> lub <strong>rc.feer.org.pl</strong> — tymi samymi danymi co dotychczas.<br>'
            . 'Do 1 sierpnia możesz również korzystać z '
            . '<a href="https://outlook.office.com" target="_blank" rel="noopener">outlook.office.com</a>.'
            . '</div>' . $args['content'];

        return $args;
    }
}
