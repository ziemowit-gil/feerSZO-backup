<?php
/**
 * feer_accounts — kilka skrzynek w jednej poczcie (poczta.feer.org.pl), przełączanie bez wylogowania.
 *
 * Ustawienia → „Moje skrzynki” (plugin.feer_accounts): lista, „Przełącz”, dodawanie (adres + hasło, sprawdzane od razu
 * logowaniem IMAP na serwerze z feer_login_domains), usuwanie. Ikona „Skrzynki” w lewym pasku prowadzi do tej strony.
 *
 * Dane: dodatkowe skrzynki w preferencjach skrzynki GŁÓWNEJ (feer_accounts: [{email, pass: rcmail->encrypt()}]).
 * W sesji grupa skrzynek (feer_acc: [{email, pass}] — pierwsza = główna, hasło z $_SESSION['password']), przenoszona
 * przy przełączeniu, więc ze skrzynki dodatkowej wraca się bez hasła. Zarządzanie listą tylko na skrzynce głównej.
 *
 * Przełączenie (plugin.feer_accounts-switch, POST + token): najpierw test IMAP z hasłem docelowej skrzynki (przy błędzie
 * zostajesz w obecnej), potem jak w public_html/index.php: kill_session → login → regenerate_id → set_auth_cookie →
 * log_login → hook login_after (stopka SZO, kalendarz z chmury) → przekierowanie.
 */
class feer_accounts extends rcube_plugin
{
    public $task = 'mail|settings|addressbook|calendar|login';
    private const MAX = 5;
    private static bool $switching = false;

    public function init(): void
    {
        $this->add_texts('localization/', false);
        $this->add_hook('login_after', [$this, 'login_after']);
        $this->add_hook('startup', [$this, 'startup']);
        $this->add_hook('settings_actions', [$this, 'settings_actions']);
        $this->register_action('plugin.feer_accounts', [$this, 'action_page']);
        $this->register_action('plugin.feer_accounts-add', [$this, 'action_add']);
        $this->register_action('plugin.feer_accounts-remove', [$this, 'action_remove']);
        $this->register_action('plugin.feer_accounts-switch', [$this, 'action_switch']);

        $rc = rcmail::get_instance();
        if (!empty($_SESSION['user_id']) && $rc->task !== 'login') {
            $this->add_button([
                'command'    => 'plugin.feer_accounts',
                'href'       => './?_task=settings&_action=plugin.feer_accounts',
                'class'      => 'feer-accounts',
                'classsel'   => 'feer-accounts selected',
                'innerclass' => 'inner',
                'label'      => 'feer_accounts.taskbar',
                'title'      => 'feer_accounts.title',
                'type'       => 'link',
            ], 'taskbar');
            $this->include_stylesheet('feer_accounts.css');
        }
    }

    // ── Grupa skrzynek w sesji ───────────────────────────────────────────────

    /** Po zwykłym zalogowaniu: grupa = ta skrzynka (główna) + dodatkowe z jej preferencji. */
    public function login_after(array $args): array
    {
        if (!self::$switching) {
            $this->build_group();
        }
        return $args;
    }

    /** Sesja bez grupy (np. powrót przez „zapamiętaj mnie”) — odbuduj, jeśli to skrzynka główna. */
    public function startup(array $args): array
    {
        if (!empty($_SESSION['user_id']) && empty($_SESSION['feer_acc'])) {
            $this->build_group();
        }
        return $args;
    }

    private function build_group(): void
    {
        $rc = rcmail::get_instance();
        $email = strtolower((string) $rc->get_user_name());
        if ($email === '' || empty($_SESSION['password'])) {
            return;
        }
        $group = [['email' => $email, 'pass' => (string) $_SESSION['password']]];
        foreach ((array) ($rc->user->get_prefs()['feer_accounts'] ?? []) as $a) {
            if (!empty($a['email']) && !empty($a['pass']) && strtolower($a['email']) !== $email) {
                $group[] = ['email' => strtolower((string) $a['email']), 'pass' => (string) $a['pass']];
            }
        }
        $_SESSION['feer_acc'] = $group;
    }

    private function group(): array
    {
        return is_array($_SESSION['feer_acc'] ?? null) ? $_SESSION['feer_acc'] : [];
    }

    private function is_primary(): bool
    {
        $g = $this->group();
        return $g && strtolower((string) rcmail::get_instance()->get_user_name()) === $g[0]['email'];
    }

    // ── Serwer po domenie (jak feer_login) ───────────────────────────────────

    private function host_for(string $email): ?string
    {
        $rc = rcmail::get_instance();
        $domains = (array) $rc->config->get('feer_login_domains', []);
        $domain = substr($email, (int) strrpos($email, '@') + 1);
        return $domains[$domain] ?? null;
    }

    /** Test logowania IMAP bez naruszania bieżącej sesji. */
    private function imap_ok(string $email, string $pass): bool
    {
        $host = $this->host_for($email);
        if (!$host) {
            return false;
        }
        $u = parse_url($host);
        $imap = new rcube_imap_generic();
        $ok = $imap->connect((string) ($u['host'] ?? $host), $email, $pass, [
            'port'     => (int) ($u['port'] ?? 993),
            'ssl_mode' => ($u['scheme'] ?? '') === 'ssl' ? 'ssl' : (($u['scheme'] ?? '') === 'tls' ? 'tls' : null),
            'timeout'  => 12,
        ]);
        $imap->closeConnection();
        return (bool) $ok;
    }

    // ── Strona ustawień ──────────────────────────────────────────────────────

    public function settings_actions(array $args): array
    {
        $args['actions'][] = [
            'action' => 'plugin.feer_accounts',
            'class'  => 'feer-accounts',
            'label'  => 'title',
            'domain' => 'feer_accounts',
            'title'  => 'title',
        ];
        return $args;
    }

    public function action_page(): void
    {
        $rc = rcmail::get_instance();
        $rc->output->set_pagetitle($this->gettext('title'));
        $rc->output->add_handlers(['plugin.body' => [$this, 'page_body']]);
        $rc->output->send('plugin');
    }

    public function page_body(): string
    {
        $rc = rcmail::get_instance();
        $q = fn($s) => rcube::Q($s);
        $current = strtolower((string) $rc->get_user_name());
        $group = $this->group();
        $token = html::tag('input', ['type' => 'hidden', 'name' => '_token', 'value' => $rc->get_request_token()]);

        $rows = '';
        foreach ($group as $i => $a) {
            $isCur = $a['email'] === $current;
            $meta = [];
            if ($i === 0) $meta[] = $this->gettext('primary');
            if ($isCur) $meta[] = $this->gettext('current');
            $actions = '';
            if (!$isCur) {
                $actions .= html::tag('form', ['method' => 'post', 'action' => './?_task=settings&_action=plugin.feer_accounts-switch', 'class' => 'feer-acc-inline'],
                    $token . html::tag('input', ['type' => 'hidden', 'name' => '_email', 'value' => $a['email']])
                    . html::tag('button', ['type' => 'submit', 'class' => 'button btn btn-primary mainaction',
                        'aria-label' => $this->gettext('switch') . ': ' . $a['email']], $q($this->gettext('switch'))));
            }
            if ($i > 0 && $this->is_primary()) {
                $actions .= html::tag('form', ['method' => 'post', 'action' => './?_task=settings&_action=plugin.feer_accounts-remove', 'class' => 'feer-acc-inline'],
                    $token . html::tag('input', ['type' => 'hidden', 'name' => '_email', 'value' => $a['email']])
                    . html::tag('button', ['type' => 'submit', 'class' => 'button btn btn-secondary',
                        'aria-label' => $this->gettext('remove') . ': ' . $a['email']], $q($this->gettext('remove'))));
            }
            $rows .= html::tag('li', ['class' => 'feer-acc-row' . ($isCur ? ' is-current' : '')],
                html::span(['class' => 'feer-acc-email'], $q($a['email']))
                . ($meta ? html::span(['class' => 'feer-acc-meta'], $q(implode(' · ', $meta))) : '')
                . html::span(['class' => 'feer-acc-actions'], $actions));
        }
        if (!$rows) {
            $rows = html::tag('li', ['class' => 'feer-acc-row'], html::span(['class' => 'feer-acc-email'], $q($current)));
        }

        $manage = '';
        if ($this->is_primary() || !$group) {
            $manage = html::tag('h2', ['class' => 'feer-acc-h', 'id' => 'feer-acc-add-h'], $q($this->gettext('add')))
                . html::tag('form', ['method' => 'post', 'action' => './?_task=settings&_action=plugin.feer_accounts-add',
                        'class' => 'propform feer-acc-form', 'aria-labelledby' => 'feer-acc-add-h'],
                    $token
                    . html::div(['class' => 'form-group row'],
                        html::label(['for' => 'feer-acc-email', 'class' => 'col-sm-4 col-form-label'], $q($this->gettext('email')))
                        . html::div(['class' => 'col-sm-8'], html::tag('input', ['type' => 'email', 'name' => '_email', 'id' => 'feer-acc-email',
                            'class' => 'form-control', 'required' => 'required', 'autocomplete' => 'off', 'spellcheck' => 'false', 'inputmode' => 'email'])))
                    . html::div(['class' => 'form-group row'],
                        html::label(['for' => 'feer-acc-pass', 'class' => 'col-sm-4 col-form-label'], $q($this->gettext('password')))
                        . html::div(['class' => 'col-sm-8'],
                            html::tag('input', ['type' => 'password', 'name' => '_pass', 'id' => 'feer-acc-pass', 'class' => 'form-control',
                                'required' => 'required', 'autocomplete' => 'new-password', 'aria-describedby' => 'feer-acc-pass-hint'])
                            . html::div(['class' => 'form-text text-muted', 'id' => 'feer-acc-pass-hint'], $q($this->gettext('passhint')))))
                    . html::div(['class' => 'formbuttons'],
                        html::tag('button', ['type' => 'submit', 'class' => 'button mainaction submit btn btn-primary'], $q($this->gettext('add')))));
        } elseif ($group) {
            $manage = html::p(['class' => 'feer-acc-note'], $q(sprintf($this->gettext('manageonprimary'), $group[0]['email'])));
        }

        return html::div(['class' => 'box formcontainer scroller'],
            html::div(['class' => 'boxcontent formcontent feer-acc'],
                html::tag('h1', ['class' => 'feer-acc-title'], $q($this->gettext('title')))
                . html::p([], $q($this->gettext('lead')))
                . html::tag('ul', ['class' => 'feer-acc-list', 'aria-label' => $this->gettext('title')], $rows)
                . $manage));
    }

    // ── Akcje ────────────────────────────────────────────────────────────────

    private function back(string $msg, string $type = 'confirmation'): void
    {
        $rc = rcmail::get_instance();
        $rc->output->show_message($msg, $type, null, true, 8);
        $rc->output->redirect(['_task' => 'settings', '_action' => 'plugin.feer_accounts']);
    }

    private function check_token(): void
    {
        $rc = rcmail::get_instance();
        if (!$rc->check_request(rcube_utils::INPUT_POST)) {
            $this->back($rc->gettext('invalidrequest'), 'error');
        }
    }

    public function action_add(): void
    {
        $this->check_token();
        $rc = rcmail::get_instance();
        if (!$this->is_primary()) {
            $this->back(sprintf($this->gettext('manageonprimary'), $this->group()[0]['email'] ?? ''), 'warning');
        }
        $email = strtolower(trim((string) rcube_utils::get_input_string('_email', rcube_utils::INPUT_POST)));
        $pass = (string) rcube_utils::get_input_string('_pass', rcube_utils::INPUT_POST, true, $rc->config->get('password_charset', 'UTF-8'));
        if (strpos($email, '@') === false) {
            $email .= '@' . (string) $rc->config->get('feer_login_default_domain', 'feer.org.pl');
        }
        if (!$this->host_for($email)) {
            $this->back($this->gettext('baddomain'), 'error');
        }
        $group = $this->group();
        foreach ($group as $a) {
            if ($a['email'] === $email) $this->back($this->gettext('duplicate'), 'warning');
        }
        if (count($group) - 1 >= self::MAX) {
            $this->back($this->gettext('limit'), 'warning');
        }
        if ($pass === '' || !$this->imap_ok($email, $pass)) {
            $this->back(sprintf($this->gettext('badlogin'), $email), 'error');
        }
        $enc = $rc->encrypt($pass);
        $prefs = (array) ($rc->user->get_prefs()['feer_accounts'] ?? []);
        $prefs[] = ['email' => $email, 'pass' => $enc, 'added' => date('c')];
        $rc->user->save_prefs(['feer_accounts' => $prefs]);
        $group[] = ['email' => $email, 'pass' => $enc];
        $_SESSION['feer_acc'] = $group;
        $this->back(sprintf($this->gettext('added'), $email));
    }

    public function action_remove(): void
    {
        $this->check_token();
        $rc = rcmail::get_instance();
        if (!$this->is_primary()) {
            $this->back(sprintf($this->gettext('manageonprimary'), $this->group()[0]['email'] ?? ''), 'warning');
        }
        $email = strtolower(trim((string) rcube_utils::get_input_string('_email', rcube_utils::INPUT_POST)));
        $prefs = array_values(array_filter((array) ($rc->user->get_prefs()['feer_accounts'] ?? []),
            fn($a) => strtolower((string) ($a['email'] ?? '')) !== $email));
        $rc->user->save_prefs(['feer_accounts' => $prefs]);
        $_SESSION['feer_acc'] = array_values(array_filter($this->group(), fn($a, $i) => $i === 0 || $a['email'] !== $email, ARRAY_FILTER_USE_BOTH));
        $this->back($this->gettext('removed'));
    }

    public function action_switch(): void
    {
        $this->check_token();
        $rc = rcmail::get_instance();
        $email = strtolower(trim((string) rcube_utils::get_input_string('_email', rcube_utils::INPUT_POST)));
        $group = $this->group();
        $target = null;
        foreach ($group as $a) {
            if ($a['email'] === $email) { $target = $a; break; }
        }
        if (!$target || $email === strtolower((string) $rc->get_user_name())) {
            $rc->output->redirect(['_task' => 'mail']);
        }
        $pass = (string) $rc->decrypt($target['pass']);
        $host = $this->host_for($email);
        // Najpierw test — przy złym haśle zostajesz w obecnej skrzynce (bez wylogowania).
        if ($pass === '' || !$host || !$this->imap_ok($email, $pass)) {
            $this->back(sprintf($this->gettext('switchfail'), $email), 'error');
        }

        // Jak w public_html/index.php przy logowaniu.
        self::$switching = true;
        $rc->kill_session();
        if (!$rc->login($email, $pass, $host, false)) {
            $rc->output->redirect(['_task' => 'login', '_err' => 'session']);
        }
        $rc->session->remove('temp');
        $rc->session->regenerate_id(false);
        $rc->session->set_auth_cookie();
        $rc->log_login();
        $_SESSION['feer_acc'] = $group;

        $redir = $rc->plugins->exec_hook('login_after', ['_task' => 'mail']);
        unset($redir['abort'], $redir['_err']);
        $rc->output->show_message(sprintf($this->gettext('switched'), $email), 'confirmation', null, true, 6);
        $rc->output->redirect($redir, 0, true);
    }
}
