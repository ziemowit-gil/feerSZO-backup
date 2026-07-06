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
            . 'Logowanie wymaga aktywnego konta Microsoft w organizacji Fundacji Edukacji Empatii Rozwoju '
            . '(domena <strong>feer.org.pl</strong>) oraz uprawnień do korzystania z tego konta.<br>'
            . 'Jeżeli wolisz, możesz też skorzystać z '
            . '<a href="https://outlook.com" target="_blank" rel="noopener">outlook.com</a> '
            . '— ten interfejs jest prostszy.'
            . '</div>' . $args['content'];

        return $args;
    }
}
