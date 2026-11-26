<?php
/**
 * feer_login — wybór serwera poczty po domenie loginu (poczta.feer.org.pl).
 *
 *   jan.kowalski            → jan.kowalski@feer.org.pl (dPoczta, dhosting)
 *   x@feer.org.pl           → ssl://imap.dpoczta.pl:993
 *   x@stud.feer.org.pl      → ssl://titan.sdata.net.pl:993 (poczta kursanta, sdata)
 *   każda inna domena       → odmowa z czytelnym komunikatem
 *
 * Mapa domen jest w config.inc.php: $config['feer_login_domains']
 * (domena => host IMAP, host MUSI być też w $config['imap_host']).
 * SMTP wybiera rdzeń z $config['smtp_host'] (tablica host IMAP => SMTP).
 */
class feer_login extends rcube_plugin
{
    public $task = 'login|logout';

    public function init(): void
    {
        $this->add_hook('authenticate', [$this, 'authenticate']);
        $this->add_hook('template_object_loginform', [$this, 'loginform']);
    }

    /**
     * Formularz logowania: bez listy „Serwer” (wybiera authenticate()),
     * nagłówek z podpowiedzią o domenach, pola z autocomplete (WCAG 1.3.5).
     */
    public function loginform(array $args): array
    {
        $html = (string) $args['content'];

        // wiersz z wyborem serwera
        $html = preg_replace('#<tr[^>]*>(?:(?!</tr>).)*rcmloginhost.*?</tr>#s', '', $html);

        $html = str_replace(
            ['>Nazwa użytkownika</label>', 'placeholder="Nazwa użytkownika"'],
            ['>Adres e-mail</label>', 'placeholder="Adres e-mail" aria-describedby="feer-login-hint"'],
            $html
        );
        $html = preg_replace('#(id="rcmloginuser"[^>]*?)autocomplete="off"#', '$1autocomplete="username"', $html);
        $html = preg_replace('#(id="rcmloginpwd"[^>]*?)autocomplete="off"#', '$1autocomplete="current-password"', $html);
        $html = str_replace('id="rcmloginuser"', 'id="rcmloginuser" spellcheck="false" inputmode="email"', $html);

        $intro = '<div class="feer-login-intro">'
            . '<h1 class="feer-login-title">Poczta FEER</h1>'
            . '<p class="feer-login-hint" id="feer-login-hint">Adres @feer.org.pl albo @stud.feer.org.pl. '
            . 'Sam login, np. jan.kowalski, oznacza @feer.org.pl.</p>'
            . '</div>';
        $html = preg_replace('#<table>#', $intro . '<table>', $html, 1);

        $args['content'] = $html;
        return $args;
    }

    public function authenticate(array $args): array
    {
        $rc      = rcmail::get_instance();
        $domains = (array) $rc->config->get('feer_login_domains', []);
        $default = (string) $rc->config->get('feer_login_default_domain', 'feer.org.pl');

        $user = strtolower(trim((string) $args['user']));
        if ($user === '') {
            return $args;
        }
        if (strpos($user, '@') === false) {
            $user .= '@' . $default;
        }
        $domain = substr($user, strrpos($user, '@') + 1);

        if (!isset($domains[$domain])) {
            $args['abort'] = true;
            $args['error'] = 'Tu logują się tylko skrzynki @' . implode(', @', array_keys($domains)) . '.';
            return $args;
        }

        $args['user'] = $user;
        $args['host'] = $domains[$domain];
        return $args;
    }
}
