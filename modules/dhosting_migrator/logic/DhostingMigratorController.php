<?php
declare(strict_types=1);

/**
 * modules/dhosting_migrator/logic/DhostingMigratorController.php — ekrany migratora kont Microsoft 365 → dhosting.pl.
 *
 * Administrator (role:admin + ważna sesja IKA): GET / (lista kont, ustawienia), POST /zaloz, /ustawienia, /test.
 * Kopiowanie poczty: POST /migracjaadmin/kopia (ponów teraz / anuluj), POST /migracjapoczty/kopia (start z hasłem / anuluj).
 * Użytkownik: GET/POST /migracjapoczty — samodzielna strona bez menu SZO (wejście z panelu współpracownika) — zalogowany
 * albo bez logowania (DhostingGuest: prywatny e-mail/PESEL + adres służbowy + kod SMS; POST /wejscie, /zakoncz);
 * POST /telefon — zalogowany bez numeru komórkowego dopisuje go kodem SMS. Konto zmigrowane: akcje tylko administrator.
 * Wyniki i komunikaty idą przez sesję (nie przez adres); wygenerowane hasła admin widzi tylko raz, zaraz po zapisie.
 */
require_once __DIR__ . '/DhostingMigrator.php';
require_once __DIR__ . '/DhostingGuest.php';
require_once __DIR__ . '/DhostingContractMail.php';
require_once dirname(__DIR__) . '/lib/DhostingGraphSource.php';
require_once dirname(__DIR__, 2) . '/audit_logs/logic/audit_logs.php';
require_once dirname(__DIR__, 3) . '/includes/admin_audit.php';
require_once dirname(__DIR__, 3) . '/includes/approval.php';

final class DhostingMigratorController
{
    private const VIEW = __DIR__ . '/../view/';
    private const K_RESULT = 'dhmResult';
    private const K_FLASH  = 'dhmFlash';
    private const K_TEST   = 'dhmTest';
    private const K_RESTORED = 'dhmRestored';
    private const K_RETRY    = 'dhmRetry';

    // ── Administrator ─────────────────────────────────────────────────────────────────────────

    public function index(): string
    {
        ika_require();
        @set_time_limit(120);
        $overview = DhostingMigrator::adminOverview();
        $data = [
            'overview' => $overview,
            'result'   => $this->take(self::K_RESULT),
            'flash'    => $this->take(self::K_FLASH),
            'test'     => $this->take(self::K_TEST),
            'jobs'     => DhostingCopy::jobs(),
            'copyOk'   => DhostingCopy::available(),
            'suspended'=> DhostingContractMail::suspendedList(),
            'restored' => $this->take(self::K_RESTORED),
            'retried'  => $this->take(self::K_RETRY),
            'storedPass' => DhostingCopy::storedPasswordBoxes(),
            'cfg'      => [
                'api_url'      => DhostingConfig::get('dhosting_api_url'),
                'api_user'     => DhostingConfig::get('dhosting_api_user'),
                'has_pass'     => DhostingConfig::hasPassword(),
                'domains'      => DhostingConfig::get('dhosting_domains'),
                'quota'        => DhostingConfig::get('dhosting_quota'),
                'self_enabled' => DhostingConfig::selfEnabled(),
                'webmail_url'  => DhostingConfig::webmail(),
                'imap_host'    => DhostingConfig::get('dhosting_imap_host'),
                'imap_port'    => DhostingConfig::get('dhosting_imap_port'),
                'switch_date'  => DhostingConfig::get('dhosting_switch_date'),
            ],
        ];
        return Router::view(self::VIEW . 'admin.php', $data);
    }

    public function create(): ?string
    {
        $back = route('dhm.index');
        if (!ika_ok($back)) return $this->flash($back, 'warning', 'Sesja kodu IKA wygasła — skrzynek NIE założono. Potwierdź kod IKA i powtórz.');
        $ids = array_values(array_filter(array_map('strval', (array)($_POST['ids'] ?? []))));
        if (!$ids) return $this->flash($back, 'warning', 'Zaznacz co najmniej jedno konto.');
        if (count($ids) > DhostingMigrator::BATCH_MAX) {
            return $this->flash($back, 'warning', 'Naraz można przenieść najwyżej ' . DhostingMigrator::BATCH_MAX . ' kont — zaznacz mniej i powtórz.');
        }
        @set_time_limit(600);
        @ignore_user_abort(true);
        $me = current_user();
        try {
            $results = DhostingMigrator::adminCreate($ids, !empty($_POST['reset_existing']), !empty($_POST['send_sms']), (string)($me['email'] ?? ''), !empty($_POST['copy_mail']),
                null, !empty($_POST['notify']));
        } catch (\Throwable $e) {
            return $this->flash($back, 'danger', $e->getMessage());
        }
        $ok  = count(array_filter($results, fn($r) => $r['status'] === 'ok'));
        $err = count(array_filter($results, fn($r) => $r['status'] === 'error'));
        $log = array_map(fn($r) => ['mailbox' => $r['mailbox'], 'status' => $r['status'], 'sms' => $r['sms'], 'notify' => $r['notify'] ?? '', 'msg' => $r['status'] === 'error' ? $r['msg'] : ''], $results);
        try { audit_log('dhosting.mailboxes_create', ['ok' => $ok, 'errors' => $err, 'items' => $log]); } catch (\Throwable $e) { error_log('[dhosting] audit_log: ' . $e->getMessage()); }
        admin_audit_migrate();
        admin_audit('dhosting_create', 'dhosting', 'Skrzynki dhosting: ' . $ok . ' ustawionych, ' . $err . ' błędów', 0, implode(', ', array_column($results, 'mailbox')));

        $_SESSION[self::K_RESULT] = $results;
        return Router::redirect($back . '#wynik');
    }

    public function saveSettings(): ?string
    {
        $back = route('dhm.index');
        try {
            DhostingConfig::save($_POST);
        } catch (\Throwable $e) {
            return $this->flash($back . '#ustawienia', 'danger', $e->getMessage());
        }
        admin_audit_migrate();
        admin_audit('dhosting_settings', 'dhosting', 'Zmieniono ustawienia migratora dhosting');
        return $this->flash($back . '#ustawienia', 'success', 'Zapisano ustawienia.');
    }

    public function test(): ?string
    {
        $back = route('dhm.index') . '#ustawienia';
        $api = DhostingConfig::api();
        $out = ['ok' => true, 'lines' => [], 'raw' => null];
        $domains = array_unique(array_values(DhostingConfig::domains()));
        if (!$domains) { $out['ok'] = false; $out['lines'][] = 'Najpierw wpisz domeny i zapisz ustawienia.'; }
        foreach ($domains as $d) {
            try {
                $list = $api->getMailboxList($d);
                $out['lines'][] = $d . ': połączenie OK, skrzynek: ' . count($list);
            } catch (\Throwable $e) {
                $out['ok'] = false;
                $out['lines'][] = $d . ': ' . $e->getMessage();
            }
            $out['raw'] = ['http' => $api->lastHttp, 'body' => $api->lastRaw];
        }
        $first = '';
        try {
            $users = DhostingGraphSource::users(array_keys(DhostingConfig::domains()));
            $out['lines'][] = 'Microsoft 365: odczytano ' . count($users) . ' kont w tych domenach.';
            $first = (string)($users[0]['userPrincipalName'] ?? '');
        } catch (\Throwable $e) {
            $out['ok'] = false;
            $out['lines'][] = 'Microsoft 365: ' . $e->getMessage();
        }
        // Kopiowanie poczty: odczyt skrzynki (Mail.Read) i dostępność serwera IMAP dhosting — bez logowania.
        if ($first !== '') {
            try {
                (new DhostingGraphMail())->messagesPage($first, 'inbox');
                $out['lines'][] = 'Microsoft 365: odczyt poczty działa (uprawnienie Mail.Read) — sprawdzone na ' . $first . '.';
            } catch (\Throwable $e) {
                $out['ok'] = false;
                $out['lines'][] = 'Kopiowanie poczty: ' . $e->getMessage();
            }
        }
        $addr = DhostingConfig::imapAddress();
        if ($addr === '') {
            $out['lines'][] = 'Serwer IMAP dhosting nieustawiony — kopiowanie poczty wyłączone.';
        } else {
            try { $im = new DhostingImap($addr, 10); $im->connect(); $im->logout(); $out['lines'][] = 'Serwer IMAP ' . $addr . ': odpowiada.'; }
            catch (\Throwable $e) { $out['ok'] = false; $out['lines'][] = 'Serwer IMAP: ' . $e->getMessage(); }
        }
        $_SESSION[self::K_TEST] = $out;
        return Router::redirect($back);
    }

    /** POST /migracjaadmin/kopia — „Ponów teraz” albo „Anuluj” dla zadania kopiowania. */
    public function copyAction(): ?string
    {
        $back = route('dhm.index') . '#kopiowanie';
        $id = (int)($_POST['job'] ?? 0);
        $job = $id ? DhostingCopy::job($id) : null;
        if (!$job) return $this->flash($back, 'warning', 'Nie znaleziono zadania kopiowania.');
        if (($_POST['op'] ?? '') === 'cancel') {
            DhostingCopy::cancel($id);
            try { audit_log('dhosting.copy_cancel', ['job' => $id, 'mailbox' => $job['mailbox']]); } catch (\Throwable $e) {}
            return $this->flash($back, 'success', 'Anulowano kopiowanie do ' . $job['mailbox'] . ' (hasło usunięte).');
        }
        // „Ponów przenoszenie poczty” — zapisanym hasłem albo z nowym hasłem (SMS do osoby / raz na ekranie)
        $op = (string)($_POST['op'] ?? '');
        if ($op === 'retry' || $op === 'retry_newpass') {
            $mb = (string)$job['mailbox'];
            $by = (string)(current_user()['email'] ?? '');
            $note = '';
            try {
                if ($op === 'retry_newpass') {
                    if (!ika_ok($back)) return $this->flash($back, 'warning', 'Sesja kodu IKA wygasła — hasła NIE zmieniono. Potwierdź kod IKA i powtórz.');
                    $pass = DhostingConfig::generatePassword();
                    DhostingConfig::api()->changeMailboxPassword($mb, $pass);
                    $phone = $this->phoneForMailbox($mb, $job);
                    $sms = '';
                    if ($phone !== '' && sms_is_mobile($phone)) {
                        try { DhostingMigrator::sendPasswordSms($phone, $mb, $pass, false); $sms = 'wysłano na ' . DhostingMigrator::maskPhone($phone); }
                        catch (\Throwable $e) { $sms = 'błąd SMS: ' . $e->getMessage(); }
                    }
                    $nid = DhostingCopy::retry($mb, $by, $pass);
                    $_SESSION[self::K_RETRY] = ['mailbox' => $mb, 'password' => $pass, 'sms' => $sms];
                    $note = ' Nowe hasło' . ($sms !== '' ? ' — SMS: ' . $sms : ' — poniżej (tylko teraz), przekaż je osobie') . '.';
                } else {
                    $nid = DhostingCopy::retry($mb, $by);
                }
            } catch (\Throwable $e) {
                return $this->flash($back, $e->getMessage() === DhostingCopy::NO_PASS ? 'warning' : 'danger', $e->getMessage());
            }
            try { audit_log('dhosting.copy_retry', ['mailbox' => $mb, 'job' => $nid, 'new_password' => $op === 'retry_newpass']); } catch (\Throwable $e) {}
            return $this->flash($back, 'success', 'Przenoszenie poczty do ' . $mb . ' ponowione (zadanie #' . $nid . ') — ruszy w najbliższym przebiegu (do 5 min). '
                . 'Skopiowane zostaną tylko brakujące wiadomości; to, co jest w nowej skrzynce, zostaje bez zmian.' . $note);
        }
        DhostingCopy::runSoon($id);
        return $this->flash($back, 'success', 'Kopiowanie do ' . $job['mailbox'] . ' ruszy w najbliższym przebiegu (do 5 min).');
    }

    /** POST /migracjaadmin/zawieszone — przywrócenie zawieszonej skrzynki z listy (nowe hasło raz na ekranie, opcjonalnie SMS). */
    public function suspendedPost(): ?string
    {
        $back = route('dhm.index') . '#zawieszone';
        if (!ika_ok($back)) return $this->flash($back, 'warning', 'Sesja kodu IKA wygasła — skrzynki NIE przywrócono. Potwierdź kod IKA i powtórz.');
        $mb = strtolower(trim((string)($_POST['mailbox'] ?? '')));
        $item = null;
        foreach (DhostingContractMail::suspendedList() as $s) if ($s['mailbox'] === $mb) { $item = $s; break; }
        if (!$item) return $this->flash($back, 'warning', 'Tej skrzynki nie ma na liście zawieszonych.');
        $by = (string)(current_user()['email'] ?? '');
        try {
            $res = DhostingContractMail::restore($mb, $by, !empty($_POST['sms']), $item['phone']);
        } catch (\Throwable $e) {
            return $this->flash($back, 'danger', 'Serwer poczty: ' . $e->getMessage());
        }
        foreach ($item['contracts'] as $c) {
            try { log_contract_action($c['type'], $c['id'], (int)(current_user()['id'] ?? 0), 'note', 'Administrator przywrócił skrzynkę pocztową ' . $mb . ' (lista zawieszonych).'); } catch (\Throwable $e) {}
        }
        try { admin_audit_migrate(); admin_audit('dhosting_box_restore', 'dhosting', 'Przywrócono skrzynkę ' . $mb, 0, $mb); } catch (\Throwable $e) {}
        $_SESSION[self::K_RESTORED] = $res + ['person' => $item['person']];
        return $this->flash($back, 'success', 'Przywrócono skrzynkę ' . $mb . ' — nowe hasło poniżej (tylko teraz).' . ($res['sms'] !== '' ? ' SMS: ' . $res['sms'] . '.' : ''));
    }

    /**
     * POST /migracjaadmin/skrzynka — administrator w widoku umowy albo w Systemie Tożsamości: op=password | suspend | restore | create
     * (karta „Skrzynka pocztowa”, view/contractMailbox.php). Komunikaty przez flash SZO, powrót do zakładki M365 umowy
     * albo (ret=tz) do tozsamosc/access.php.
     */
    public function boxPost(): ?string
    {
        $type = (string)($_POST['type'] ?? '');
        $id   = (int)($_POST['id'] ?? 0);
        $table = DhostingContractMail::TABLES[$type] ?? null;
        if (!$table || $id <= 0) return Router::redirect(route('dhm.index'));
        // Powrót: karta w widoku umowy (zakładka M365) albo w Systemie Tożsamości (tozsamosc/access.php, ret=tz) — tylko te dwa.
        $back = ($_POST['ret'] ?? '') === 'tz'
            ? APP_URL . "/tozsamosc/access.php?type={$type}&id={$id}#skrzynka"
            : APP_URL . "/contracts/{$type}/view.php?id={$id}&tab=m365#skrzynka";
        if (!ika_ok($back)) {   // strona Tożsamości sama nie pyta o IKA — od razu na bramę, potem z powrotem na kartę
            flash_set('warning', 'Potwierdź kod IKA i powtórz operację na skrzynce.');
            return Router::redirect(APP_URL . '/contracts/ika_gate.php?to=' . urlencode($back));
        }
        $row = db_one("SELECT * FROM {$table} WHERE id=?", [$id]);
        if (!$row) { flash_set('error', 'Nie znaleziono umowy.'); return Router::redirect($back); }
        $by = (string)(current_user()['email'] ?? '');
        $phone = (string)($row['telefon'] ?? '');
        $sms = !empty($_POST['sms']);
        $op = (string)($_POST['op'] ?? '');
        try {
            if ($op === 'create') {
                $r = DhostingContractMail::provision($type, $id, $row, $by, null);
                flash_set('success', ($r['action'] === 'created' ? 'Założono skrzynkę ' : 'Przywrócono dawną skrzynkę ') . $r['mailbox'] . ' — hasło poniżej (tylko teraz).');
            } else {
                $box = DhostingContractMail::boxForContract($type, $id);
                if (!$box) { flash_set('error', 'Ta umowa nie ma skrzynki pocztowej.'); return Router::redirect($back); }
                $mb = (string)$box['mailbox'];
                if ($op === 'password') {
                    DhostingContractMail::adminPassword($mb, $by, $sms, $phone, [$type, $id]);
                    flash_set('success', 'Ustawiono nowe hasło do ' . $mb . ' — widoczne poniżej tylko teraz.');
                } elseif ($op === 'suspend') {
                    DhostingContractMail::suspend($mb, 'decyzja administratora', $by);
                    flash_set('success', 'Skrzynka ' . $mb . ' zawieszona (wyłączona, losowe hasło). Użytkownik nie dostał powiadomienia.');
                } elseif ($op === 'restore') {
                    DhostingContractMail::restore($mb, $by, $sms, $phone, [$type, $id]);
                    flash_set('success', 'Skrzynka ' . $mb . ' przywrócona — nowe hasło poniżej (tylko teraz).');
                }
            }
        } catch (\Throwable $e) {
            flash_set('error', 'Serwer poczty: ' . $e->getMessage());
        }
        try { admin_audit_migrate(); admin_audit('dhosting_box_' . $op, 'dhosting', 'Skrzynka umowy ' . $type . ' #' . $id . ': ' . $op, $id, $type); } catch (\Throwable $e) {}
        return Router::redirect($back);
    }

    // ── Użytkownik ─────────────────────────────────────────────────────────────────────────────

    public function self(): string
    {
        $u = $this->meRow();
        if ($u === null) {   // bez logowania: wejście zestawem danych (DhostingGuest)
            return Router::view(self::VIEW . 'guest.php', ['flow' => DhostingGuest::flow(), 'flash' => $this->take(self::K_FLASH)]);
        }
        $guest = DhostingGuest::current();
        return Router::view(self::VIEW . 'self.php', [
            'st'        => DhostingMigrator::selfState($u),
            'flash'     => $this->take(self::K_FLASH),
            'web'       => DhostingConfig::webmail(),
            'me'        => $u,
            'guest'     => $guest !== null && !current_user(),
            'phoneFlow' => (int)$u['id'] > 0 ? DhostingGuest::phoneFlow((int)$u['id']) : null,
        ]);
    }

    public function selfPost(): ?string
    {
        $back = route('dhm.self');
        $u = $this->meRow();
        if ($u === null) return Router::redirect($back);
        if (empty($_POST['confirm'])) return $this->flash($back, 'warning', 'Zaznacz potwierdzenie, że numer telefonu jest Twój.');
        try {
            $msg = DhostingMigrator::selfProvision($u, $this->by($u), !empty($_POST['copy_mail']));
        } catch (\Throwable $e) {
            try { audit_log('dhosting.self_failed', ['error' => $e->getMessage()]); } catch (\Throwable $e2) {}
            return $this->flash($back, 'danger', $e->getMessage());
        }
        try { audit_log('dhosting.self_provision', ['mailbox' => DhostingConfig::mailboxFor(DhostingMigrator::loginForUser($u))]); } catch (\Throwable $e) {}
        return $this->flash($back, 'success', $msg);
    }

    /** POST /migracjapoczty/kopia — start kopiowania z hasłem do nowej skrzynki albo anulowanie własnego zadania. */
    public function selfCopyPost(): ?string
    {
        $back = route('dhm.self');
        $u = $this->meRow();
        if ($u === null) return Router::redirect($back);
        $box = DhostingConfig::mailboxFor(DhostingMigrator::loginForUser($u));
        if (($_POST['op'] ?? '') === 'cancel') {
            $job = $box ? DhostingCopy::activeFor($box) : null;
            if ($job && (int)$job['user_id'] === (int)$u['id']) DhostingCopy::cancel((int)$job['id']);
            return $this->flash($back, 'success', 'Anulowano kopiowanie poczty.');
        }
        try {
            $msg = DhostingMigrator::selfCopy($u, (string)($_POST['mail_password'] ?? ''), $this->by($u));
        } catch (\Throwable $e) {
            return $this->flash($back, 'danger', $e->getMessage());
        }
        try { audit_log('dhosting.copy_self', ['mailbox' => $box]); } catch (\Throwable $e) {}
        return $this->flash($back, 'success', $msg);
    }

    /** POST /migracjapoczty/wejscie — wejście bez logowania: op=ident | phone | code | resend | restart. */
    public function guestPost(): ?string
    {
        $back = route('dhm.self');
        if (current_user()) return Router::redirect($back);
        $op = (string)($_POST['op'] ?? '');
        try {
            switch ($op) {
                case 'ident':  DhostingGuest::start((string)($_POST['ident'] ?? ''), (string)($_POST['address'] ?? '')); break;
                case 'phone':  DhostingGuest::setPhone((string)($_POST['phone'] ?? '')); break;
                case 'resend': DhostingGuest::resend(); return $this->flash($back, 'success', 'Wysłaliśmy nowy kod.');
                case 'code':   return $this->flash($back, 'success', DhostingGuest::verify((string)($_POST['code'] ?? ''), (string)($_POST['mail_code'] ?? '')));
                default:       DhostingGuest::restart();
            }
        } catch (\RuntimeException $e) {
            return $this->flash($back, 'danger', $e->getMessage());
        }
        return Router::redirect($back);
    }

    /** POST /migracjapoczty/zakoncz — koniec sesji gościa. */
    public function guestEnd(): ?string
    {
        DhostingGuest::end();
        return $this->flash(route('dhm.self'), 'success', 'Zakończono. Aby wrócić, wpisz dane jeszcze raz.');
    }

    /** POST /migracjapoczty/telefon — zalogowany bez numeru komórkowego: op=send (numer) | verify (kod) | cancel. */
    public function phonePost(): ?string
    {
        $back = route('dhm.self');
        $u = $this->meRow();
        if ($u === null || (int)$u['id'] <= 0) return Router::redirect($back);
        try {
            switch ((string)($_POST['op'] ?? '')) {
                case 'send':   DhostingGuest::phoneSend($u, (string)($_POST['phone'] ?? '')); return $this->flash($back, 'success', 'Wysłaliśmy kod SMS. Wpisz go poniżej.');
                case 'verify': return $this->flash($back, 'success', DhostingGuest::phoneVerify($u, (string)($_POST['code'] ?? '')));
                default:       DhostingGuest::phoneCancel();
            }
        } catch (\RuntimeException $e) {
            return $this->flash($back, 'danger', $e->getMessage());
        }
        return Router::redirect($back);
    }

    // ── Pomocnicze ─────────────────────────────────────────────────────────────────────────────

    /**
     * Wiersz users zalogowanego (prawdziwego, nie kontekstu admina; _admin = administrator SZO) albo potwierdzonego gościa
     * (DhostingGuest — konto SZO osoby albo wiersz zastępczy z umowy). null = nikt (strona pokaże wejście zestawem danych).
     */
    private function meRow(): ?array
    {
        if (current_user()) {
            $me = function_exists('ctx_real_user') ? (ctx_real_user() ?: current_user()) : current_user();
            $row = db_one("SELECT id, name, email, phone_number, microsoft_id, m365_login FROM users WHERE id=?", [(int)($me['id'] ?? 0)]);
            $row = $row ?: ['id' => (int)($me['id'] ?? 0), 'email' => (string)($me['email'] ?? ''), 'microsoft_id' => (string)($me['microsoft_id'] ?? '')];
            $row['_admin'] = ($me['role'] ?? '') === 'admin';
            return $row;
        }
        $g = DhostingGuest::current();
        return $g ? DhostingGuest::userRow($g) : null;
    }

    /** Numer komórkowy właściciela skrzynki: konto SZO z zadania / rejestru, potem umowy (telefon). '' = brak. */
    private function phoneForMailbox(string $mailbox, array $job): string
    {
        $uids = array_filter([(int)($job['user_id'] ?? 0)]);
        try { $uids[] = (int)(db_one("SELECT user_id FROM dhosting_mailboxes WHERE mailbox=?", [$mailbox])['user_id'] ?? 0); } catch (\Throwable $e) {}
        foreach (array_filter($uids) as $uid) {
            $p = (string)(db_one("SELECT phone_number FROM users WHERE id=?", [$uid])['phone_number'] ?? '');
            if ($p !== '' && sms_is_mobile($p)) return $p;
        }
        try {
            foreach (DhostingContractMail::contractsFor($mailbox) as $c) {
                $p = (string)($c['telefon'] ?? '');
                if ($p !== '' && sms_is_mobile($p)) return $p;
            }
        } catch (\Throwable $e) {}
        return '';
    }

    /** Kto wykonał akcję (rejestr, kolejka kopiowania). */
    private function by(array $u): string
    {
        return current_user() ? (string)($u['email'] ?? '') : 'bez logowania: ' . (string)($u['m365_login'] ?? '');
    }

    private function flash(string $back, string $type, string $msg): ?string
    {
        $_SESSION[self::K_FLASH] = ['type' => $type, 'msg' => $msg];
        return Router::redirect($back);
    }

    private function take(string $key): mixed
    {
        $v = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        return $v;
    }
}
