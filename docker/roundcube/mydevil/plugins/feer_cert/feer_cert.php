<?php
/**
 * feer_cert — certyfikaty S/MIME z wewnętrznego urzędu FEER (FEER S/MIME CA 1 ← FEER Root CA).
 *
 * Ustawienia → „Certyfikat FEER”: zalogowana osoba (skrzynka z feer_cert_hosts, czyli @feer.org.pl)
 * wydaje sobie certyfikat na adres SWOJEJ skrzynki — logowanie do niej potwierdza adres.
 * Klucz RSA 3072 powstaje na serwerze, certyfikat podpisuje FEER S/MIME CA 1, a całość
 * (klucz + certyfikat + łańcuch) trafia od razu do magazynu kluczy mimeshield (szyfrowany kluczem
 * głównym). Po podaniu hasła serwer oddaje też plik .p12 (Outlook, Thunderbird, telefon); klucz
 * nie jest nigdzie zapisywany poza mimeshield.
 *
 * Urząd: feer_cert_ca_dir (issuing.key/issuing.crt/root.crt), tworzy tools/feer_ca_init.php.
 * Rejestr: tabela feer_cert_issued. Certyfikat główny dla odbiorców: feer_cert_root_url (SZO /assets/ca),
 * kopia w plugins/feer_cert/feer-root-ca.crt służy do pokazania odcisku SHA-256.
 */
class feer_cert extends rcube_plugin
{
    public $task = 'settings';

    private const ORG = 'Fundacja Edukacji Empatii Rozwoju FEER';

    public function init(): void
    {
        $this->add_texts('localization/', false);
        $this->add_hook('settings_actions', [$this, 'settings_actions']);
        $this->register_action('plugin.feer_cert', [$this, 'action_page']);
        $this->register_action('plugin.feer_cert-issue', [$this, 'action_issue']);
    }

    private function allowed(): bool
    {
        $rc = rcmail::get_instance();
        $hosts = (array) $rc->config->get('feer_cert_hosts', []);
        return !$hosts || in_array((string) ($_SESSION['storage_host'] ?? ''), $hosts, true);
    }

    public function settings_actions(array $args): array
    {
        if ($this->allowed()) {
            $args['actions'][] = [
                'action' => 'plugin.feer_cert',
                'class'  => 'feer-cert',
                'label'  => 'title',
                'domain' => 'feer_cert',
                'title'  => 'title',
            ];
        }
        return $args;
    }

    // ── Strona ustawień ──────────────────────────────────────────────────────

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
        $q  = fn($s) => rcube::Q($s);
        if (!$this->allowed()) {
            return html::div(['class' => 'box formcontainer'], html::p([], $q($this->gettext('notallowed'))));
        }
        $email = strtolower((string) $rc->get_user_email());
        $rows  = $this->issued_list();
        // static.php nie wydaje .crt — plik leży w SZO (ten sam co w artykule /uslugi-it)
        $root  = (string) $rc->config->get('feer_cert_root_url', 'https://szo.feer.org.pl/assets/ca/feer-root-ca.crt');
        $fp    = $this->root_fingerprint();

        $list = '';
        if ($rows) {
            $t = new html_table(['class' => 'records-table', 'cols' => 3]);
            $t->add_header([], $q($this->gettext('serial')));
            $t->add_header([], $q($this->gettext('validto')));
            $t->add_header([], $q($this->gettext('status')));
            foreach ($rows as $r) {
                $exp = strtotime($r['not_after']) < time();
                $t->add([], $q(substr($r['serial'], 0, 16) . '…'));
                $t->add([], $q(date('Y-m-d', strtotime($r['not_after']))));
                $t->add([], $q($this->gettext($r['revoked_at'] ? 'revoked' : ($exp ? 'expired' : 'active'))));
            }
            $list = html::tag('h2', ['class' => 'feer-cert-h'], $q($this->gettext('yourcerts'))) . $t->show();
        }

        $form = html::tag('form', [
                'method' => 'post',
                'action' => './?_task=settings&_action=plugin.feer_cert-issue',
                'class'  => 'propform feer-cert-form',
                'id'     => 'feer-cert-form',
            ],
            html::tag('input', ['type' => 'hidden', 'name' => '_token', 'value' => $rc->get_request_token()])
            . html::p([], sprintf($q($this->gettext('issuefor')), html::tag('strong', [], $q($email))))
            . html::div(['class' => 'form-group row'],
                html::label(['for' => 'feer-cert-pass', 'class' => 'col-sm-4 col-form-label'], $q($this->gettext('p12pass')))
                . html::div(['class' => 'col-sm-8'],
                    html::tag('input', ['type' => 'password', 'name' => '_p12pass', 'id' => 'feer-cert-pass',
                        'class' => 'form-control', 'autocomplete' => 'new-password', 'minlength' => 8,
                        'aria-describedby' => 'feer-cert-pass-hint'])
                    . html::div(['class' => 'form-text text-muted', 'id' => 'feer-cert-pass-hint'], $q($this->gettext('p12hint')))))
            . html::div(['class' => 'formbuttons'],
                html::tag('button', ['type' => 'submit', 'class' => 'button mainaction submit btn btn-primary'],
                    $q($this->gettext('issue'))))
        );

        $out = html::div(['class' => 'box formcontainer scroller'],
            html::div(['class' => 'boxcontent formcontent feer-cert'],
                html::tag('h1', ['class' => 'feer-cert-title'], $q($this->gettext('title')))
                . html::p([], $q($this->gettext('lead')))
                . html::div(['class' => 'feer-cert-note', 'role' => 'note'],
                    html::p([], $q($this->gettext('trustnote')))
                    . html::p([], html::a(['href' => $root, 'download' => 'FEER-Root-CA.crt'], $q($this->gettext('rootdl')))
                        . ' · SHA-256: ' . html::tag('code', ['class' => 'feer-cert-fp'], $q($fp))))
                . $list
                . html::tag('h2', ['class' => 'feer-cert-h'], $q($this->gettext('newcert')))
                . $form
            )
        );
        // po pobraniu .p12 strona się nie przeładowuje — odśwież listę po chwili
        $rc->output->add_script("$('#feer-cert-form').on('submit', function(){ setTimeout(function(){ location.href = './?_task=settings&_action=plugin.feer_cert&_framed=1'; }, 2500); });", 'docready');
        return $out;
    }

    // ── Wydanie certyfikatu ──────────────────────────────────────────────────

    public function action_issue(): void
    {
        $rc = rcmail::get_instance();
        $back = function (string $msg, string $type = 'error') use ($rc) {
            $rc->output->show_message('feer_cert.' . $msg, $type);
            $this->action_page();
        };
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !$rc->check_request()) {
            $back('error');
            return;
        }
        if (!$this->allowed()) {
            $back('notallowed');
            return;
        }
        $pass = (string) rcube_utils::get_input_string('_p12pass', rcube_utils::INPUT_POST, true);
        unset($_POST['_p12pass']);
        if ($pass !== '' && strlen($pass) < 8) {
            $back('passshort');
            return;
        }
        $limit = (int) $rc->config->get('feer_cert_max_per_30d', 3);
        if ($this->issued_count_30d() >= $limit) {
            $back('ratelimit');
            return;
        }
        $ms = $rc->plugins->get_plugin('mimeshield');
        if (!$ms || !method_exists($ms, 'services')) {
            $back('nomimeshield');
            return;
        }

        $email = strtolower((string) $rc->get_user_email());
        $ident = (array) $rc->user->get_identity();
        $name  = trim((string) ($ident['name'] ?? '')) ?: $email;

        try {
            [$keyPem, $certPem, $chainPem, $serial, $notAfter] = $this->sign($name, $email);
        } catch (\Throwable $e) {
            rcube::raise_error(['code' => 500, 'message' => 'feer_cert: ' . $e->getMessage()], true, false);
            $back('error');
            return;
        }

        // do magazynu kluczy mimeshield (klucz + certyfikat + łańcuch, bez hasła — od razu szyfrowany kluczem głównym)
        try {
            $identities = array_map(static fn($i) => ['identity_id' => $i['identity_id'], 'email' => (string) $i['email']],
                (array) $rc->user->list_identities());
            $ms->services()->keys()->import($keyPem . $certPem . $chainPem, '', $identities);
        } catch (\Throwable $e) {
            rcube::raise_error(['code' => 500, 'message' => 'feer_cert import: ' . $e->getMessage()], true, false);
            $back('importerror');
            return;
        }

        $this->issued_add($email, $serial, $notAfter);

        if ($pass === '') {
            $back('issued', 'confirmation');
            return;
        }

        // .p12 do pobrania (Outlook, Thunderbird, telefon)
        $extra = [];
        foreach (preg_split('/(?<=-----END CERTIFICATE-----)\s*/', trim($chainPem)) as $c) {
            if (trim($c) !== '') {
                $extra[] = trim($c) . "\n";
            }
        }
        if (!openssl_pkcs12_export($certPem, $p12, $keyPem, $pass, ['extracerts' => $extra, 'friendly_name' => $name . ' (FEER)'])) {
            $back('issuednop12', 'warning');
            return;
        }
        $fname = 'FEER-' . preg_replace('/[^a-z0-9._-]+/', '_', $email) . '.p12';
        header('Content-Type: application/x-pkcs12');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        header('Content-Length: ' . strlen($p12));
        header('Cache-Control: no-store');
        echo $p12;
        exit;
    }

    /** @return array{0:string,1:string,2:string,3:string,4:string} klucz, cert, łańcuch, serial (hex), not_after */
    private function sign(string $name, string $email): array
    {
        $rc  = rcmail::get_instance();
        $dir = rtrim((string) $rc->config->get('feer_cert_ca_dir', ''), '/');
        $caKey = openssl_pkey_get_private('file://' . $dir . '/issuing.key');
        $caCrt = (string) @file_get_contents($dir . '/issuing.crt');
        $root  = (string) @file_get_contents($dir . '/root.crt');
        if (!$caKey || $caCrt === '' || $root === '') {
            throw new RuntimeException('brak urzędu w ' . $dir);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('zły adres');
        }

        $tmpdir = rtrim((string) $rc->config->get('temp_dir', sys_get_temp_dir()), '/');
        $cnf = tempnam($tmpdir, 'feercert');
        file_put_contents($cnf, "[ req ]\ndistinguished_name = dn\n[ dn ]\n[ v3_smime ]\n"
            . "basicConstraints = critical, CA:false\n"
            . "keyUsage = critical, digitalSignature, nonRepudiation, keyEncipherment\n"
            . "extendedKeyUsage = emailProtection\n"
            . "subjectKeyIdentifier = hash\nauthorityKeyIdentifier = keyid:always\n"
            . "subjectAltName = email:" . $email . "\n");
        try {
            $conf = ['config' => $cnf, 'x509_extensions' => 'v3_smime', 'digest_alg' => 'sha256'];
            $key = openssl_pkey_new(['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $cnf]);
            $dn  = ['countryName' => 'PL', 'organizationName' => self::ORG, 'commonName' => mb_substr($name, 0, 64), 'emailAddress' => $email];
            $csr = openssl_csr_new($dn, $key, $conf);
            $serialInt = random_int(1 << 40, PHP_INT_MAX);
            $crt = openssl_csr_sign($csr, $caCrt, $caKey, (int) $rc->config->get('feer_cert_days', 397), $conf, $serialInt);
            if (!$crt) {
                throw new RuntimeException('podpis: ' . openssl_error_string());
            }
            openssl_pkey_export($key, $keyPem, null, ['config' => $cnf]);
            openssl_x509_export($crt, $certPem);
            $info = openssl_x509_parse($crt);
        } finally {
            @unlink($cnf);
        }
        return [$keyPem, $certPem, trim($caCrt) . "\n" . trim($root) . "\n",
            (string) ($info['serialNumberHex'] ?? dechex($serialInt)), date('Y-m-d H:i:s', (int) $info['validTo_time_t'])];
    }

    private function root_fingerprint(): string
    {
        $root = (string) @file_get_contents(__DIR__ . '/feer-root-ca.crt');
        $fp = $root !== '' ? openssl_x509_fingerprint($root, 'sha256') : '';
        return $fp ? strtoupper(implode(':', str_split($fp, 2))) : '';
    }

    // ── Rejestr wydanych (tabela feer_cert_issued) ───────────────────────────

    private function issued_list(): array
    {
        $rc = rcmail::get_instance();
        $res = $rc->db->query('SELECT serial, not_after, revoked_at FROM feer_cert_issued WHERE user_id = ? ORDER BY id DESC', $rc->user->ID);
        $out = [];
        while ($res && ($r = $rc->db->fetch_assoc($res))) {
            $out[] = $r;
        }
        return $out;
    }

    private function issued_count_30d(): int
    {
        $rc = rcmail::get_instance();
        $res = $rc->db->query('SELECT COUNT(*) AS n FROM feer_cert_issued WHERE user_id = ? AND created > ?',
            $rc->user->ID, date('Y-m-d H:i:s', time() - 30 * 86400));
        $r = $res ? $rc->db->fetch_assoc($res) : null;
        return (int) ($r['n'] ?? 0);
    }

    private function issued_add(string $email, string $serial, string $notAfter): void
    {
        $rc = rcmail::get_instance();
        $rc->db->query('INSERT INTO feer_cert_issued (user_id, email, serial, not_after, created) VALUES (?, ?, ?, ?, ?)',
            $rc->user->ID, $email, $serial, $notAfter, date('Y-m-d H:i:s'));
    }
}
