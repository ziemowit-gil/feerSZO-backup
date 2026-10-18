<?php
declare(strict_types=1);

/**
 * modules/ti_dop/lib/DopService.php — obieg wniosków DOP1 (zwrot nadpłaty) i DOP2 (płatność z subkonta) uczestnika TI.
 *
 * Złożenie (biuro): numer DOP1/NNNNNN/RRRR, termin realizacji 7 dni, podpis wnioskodawcy:
 *   'link'  — e-mail + SMS z linkiem do portalu (karty30/ti/wniosekDop.php?_route=/potwierdz/{token}); podpis elektroniczny
 *             (imię i nazwisko, czas, IP),
 *   'paper' — wersja papierowa podpisana odręcznie: skan wgrywany w panelu.
 * Kroki: DOP1 — weryfikacja i decyzja (kierownik / administrator: saldo nadpłaty z księgi, zgodność rachunku) → realizacja
 *        przelewu (data, nr transakcji) = ti_op_refund() na zarejestrowanych nadpłatach (księga + rejestr nadpłat);
 *        DOP2 — weryfikacja środków (saldo subkonta z księgi) → zatwierdzenie kierownika → realizacja (wpis ujemny
 *        w k30_ti_payments, source_type dop2_payout). Kwota nigdy nie przekracza salda z księgi.
 * Każdy krok: stempel (ID, imię i nazwisko, czas, IP). Decyzja odmowna i realizacja: PDF e-mailem do wnioskodawcy;
 * dokument końcowy trafia do teczki „Dokumenty kursanta” (k30_ti_sfile, kategoria potwierdzenie).
 */
require_once __DIR__ . '/dopSchema.php';
require_once __DIR__ . '/DopForm.php';
require_once __DIR__ . '/DopDocument.php';
require_once dirname(__DIR__, 2) . '/ti_licencje_dydaktyczne/lib/LicenseNotificationService.php';
require_once dirname(__DIR__, 2) . '/ti_licencje_dydaktyczne/lib/LicenseDto.php';

final class DopService
{
    private LicenseNotificationService $notify;

    public function __construct(?LicenseNotificationService $notify = null)
    {
        dopMigrate();
        $this->notify = $notify ?? new LicenseNotificationService('ti_dop');
        require_once dirname(__DIR__, 3) . '/includes/karty30.php';
        require_once dirname(__DIR__, 3) . '/includes/ti_payments.php';
        require_once dirname(__DIR__, 2) . '/ti_overpayments/logic/overpayments.php';
        require_once dirname(__DIR__, 2) . '/ti_subaccount/logic/subaccountManager.php';
        ti_op_migrate();
    }

    /** Kierownik (rola panelu TI) albo administrator — decyzja DOP1 i zatwierdzenie DOP2. */
    public static function canApprove(int $userId): bool
    {
        if (function_exists('dyd_is_admin') && dyd_is_admin()) return true;
        return function_exists('ti_panel_role') && ti_panel_role($userId) === 'kierownik';
    }

    private static function ip(): string
    {
        return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
    }

    // ── Odczyt ──────────────────────────────────────────────────────────────────

    public function get(int $id): ?array
    {
        $r = db_one("SELECT * FROM dop_requests WHERE id = ?", [$id]);
        return $r ? self::hydrate($r) : null;
    }

    private static function hydrate(array $r): array
    {
        $r['formEnum'] = DopForm::from((string)$r['form']);
        $r['statusEnum'] = DopStatus::tryFrom((string)$r['status']) ?? DopStatus::Signing;
        $r['d'] = json_decode((string)($r['data'] ?? ''), true) ?: [];
        $r['stepsData'] = json_decode((string)($r['steps'] ?? ''), true) ?: [];
        return $r;
    }

    public function search(array $f = []): array
    {
        $w = []; $p = [];
        if (($s = DopStatus::tryFrom((string)($f['status'] ?? '')))) { $w[] = 'status = ?'; $p[] = $s->value; }
        if (($fm = DopForm::tryFrom((string)($f['form'] ?? '')))) { $w[] = 'form = ?'; $p[] = $fm->value; }
        if (!empty($f['client'])) { $w[] = 'client_id = ?'; $p[] = (int)$f['client']; }
        if (($q = trim((string)($f['q'] ?? ''))) !== '') { $w[] = '(lower(client_name) LIKE ? OR lower(number) LIKE ?)'; $l = '%' . mb_strtolower($q) . '%'; array_push($p, $l, $l); }
        return array_map([self::class, 'hydrate'], db_all("SELECT * FROM dop_requests" . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY id DESC LIMIT 300", $p));
    }

    public function counts(): array
    {
        $c = array_fill_keys(array_map(fn($s) => $s->value, DopStatus::cases()), 0);
        foreach (db_all("SELECT status, COUNT(*) AS n FROM dop_requests GROUP BY status") as $r) $c[$r['status']] = (int)$r['n'];
        return $c;
    }

    public function events(int $id): array
    {
        return db_all("SELECT * FROM dop_events WHERE request_id = ? ORDER BY id DESC", [$id]);
    }

    private function event(int $id, string $action, string $note, array $actor): void
    {
        db_insert('dop_events', ['request_id' => $id, 'action' => $action, 'note' => mb_substr($note, 0, 2000), 'actor_id' => ($actor['id'] ?? 0) ?: null,
            'actor_name' => (string)($actor['name'] ?? ''), 'ip' => self::ip(), 'created_at' => ldNow()]);
    }

    // ── Uczestnik ───────────────────────────────────────────────────────────────

    /** Dane uczestnika do wniosku: kontakt (małoletni → opiekun), adres, subkonto, saldo z księgi, ostatnia wpłata. */
    public static function client(int $clientId): ?array
    {
        $c = db_one("SELECT id, name, email, phone, address FROM k30_clients WHERE id = ?", [$clientId]);
        if (!$c) return null;
        $rec = ['email' => (string)$c['email'], 'name' => (string)$c['name']];
        try { $x = tiSubRecipient($clientId); if (!empty($x['email'])) $rec = ['email' => (string)$x['email'], 'name' => (string)($x['name'] ?? $c['name'])]; } catch (\Throwable $e) {}
        $bal = ti_client_balance($clientId);
        $last = db_one("SELECT amount, paid_at, note FROM k30_ti_payments WHERE client_id = ? AND amount > 0 ORDER BY COALESCE(paid_at, date(created_at)) DESC, id DESC LIMIT 1", [$clientId]);
        $acc = null;
        try { $acc = db_one("SELECT id, guardian_phone FROM k30_ti_student_accounts WHERE client_id = ? ORDER BY id LIMIT 1", [$clientId]); } catch (\Throwable $e) {}
        return ['id' => (int)$c['id'], 'name' => (string)$c['name'], 'email' => $rec['email'], 'recipient' => $rec['name'], 'phone' => (string)$c['phone'],
                'address' => (string)$c['address'], 'nrb' => (string)(tiSubNrb($clientId) ?? ''), 'credit' => (float)$bal['credit'], 'balance' => (float)$bal['balance'],
                'debt' => (float)$bal['debt'], 'last_payment' => $last, 'account_id' => $acc ? (int)$acc['id'] : null];
    }

    public static function clients(string $q = ''): array
    {
        $q = trim($q);
        if ($q === '') return db_all("SELECT id, name, email FROM k30_clients ORDER BY name LIMIT 300");
        $l = '%' . mb_strtolower($q) . '%';
        return db_all("SELECT id, name, email FROM k30_clients WHERE lower(name) LIKE ? OR lower(COALESCE(email,'')) LIKE ? ORDER BY name LIMIT 100", [$l, $l]);
    }

    public static function defaults(DopForm $f, array $c, array $actor): array
    {
        $kontakt = trim($c['phone'] . ($c['phone'] !== '' && $c['email'] !== '' ? ' / ' : '') . $c['email']);
        if ($f === DopForm::Dop1) {
            return ['nazwa' => $c['name'], 'nr_klienta' => 'uczestnik #' . $c['id'], 'adres' => $c['address'], 'kontakt' => $kontakt,
                    'kwota' => $c['credit'] > 0 ? number_format($c['credit'], 2, '.', '') : '', 'wlasciciel' => $c['name'], 'przyczyna' => 'podwojna'];
        }
        if ($f->isNonFinancial()) {
            $g = '';
            try { $g = (string)(db_one("SELECT guardian_name FROM k30_ti_student_accounts WHERE client_id = ? ORDER BY id LIMIT 1", [$c['id']])['guardian_name'] ?? ''); } catch (\Throwable $e) {}
            $base = ['nazwa' => $c['name'], 'adres' => $c['address'], 'kontakt' => $kontakt, 'opiekun' => $g];
            return $f === DopForm::Zpro ? $base + ['do' => 'Kierownik zajęć — ' . ldOrgName()] : $base + ['zakres' => 'grupa', 'data_rezygnacji' => ldToday()];
        }
        $lp = $c['last_payment'];
        return ['wnioskodawca' => (string)$actor['name'], 'subkonto' => $c['nrb'] !== '' ? $c['nrb'] : 'uczestnik #' . $c['id'], 'beneficjent' => $c['name'],
                'przedplata_kwota' => $lp ? number_format((float)$lp['amount'], 2, '.', '') : '', 'przedplata_data' => $lp ? substr((string)$lp['paid_at'], 0, 10) : '',
                'przedplata_dok' => $lp ? trim((string)$lp['note']) : '', 'kwota' => $c['credit'] > 0 ? number_format($c['credit'], 2, '.', '') : '',
                'termin' => ldDue()];
    }

    /** @return array{0:array,1:array} [dane, błędy] */
    public static function validate(DopForm $f, array $in): array
    {
        $data = []; $err = [];
        foreach ($f->fields() as $k => [$label, $type, $req, $opts]) {
            $v = $type === 'check' ? (LdInput::bool($in, $k) ? '1' : '') : LdInput::str($in, $k, 400);
            if ($type === 'money' && $v !== '') {
                $v = str_replace([' ', ','], ['', '.'], $v);
                if (!is_numeric($v) || (float)$v <= 0) { $err[$k] = $label . ': podaj kwotę większą od zera.'; continue; }
                $v = number_format((float)$v, 2, '.', '');
            }
            if ($type === 'date' && $v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) { $err[$k] = $label . ': nieprawidłowa data.'; continue; }
            if ($type === 'iban' && $v !== '') {
                $n = strtoupper(preg_replace('/\s+/', '', $v));
                if (!preg_match('/^(PL)?\d{26}$/', $n)) { $err[$k] = 'Numer rachunku: 26 cyfr (opcjonalnie z PL).'; continue; }
                $v = 'PL' . preg_replace('/^PL/', '', $n);
            }
            if ($type === 'select' && $v !== '' && !isset($opts[$v])) { $err[$k] = $label . ': nieprawidłowa wartość.'; continue; }
            if ($type === 'course' && $v !== '' && !ctype_digit($v)) { $err[$k] = $label . ': wybierz z listy.'; continue; }
            if ($type === 'course' && $v === '0') $v = '';
            if ($req && $v === '') { $err[$k] = $type === 'check' ? 'Zaznacz: ' . $label . '.' : $label . ': pole wymagane.'; continue; }
            $data[$k] = $v;
        }
        if ($f === DopForm::Dop1 && ($data['przyczyna'] ?? '') === 'inne' && ($data['przyczyna_inna'] ?? '') === '') $err['przyczyna_inna'] = 'Podaj inną przyczynę nadpłaty.';
        if ($f === DopForm::Dop2 && ($data['kwota'] ?? '') !== '' && ($data['przedplata_kwota'] ?? '') !== '' && (float)$data['kwota'] > (float)$data['przedplata_kwota'])
            $err['kwota'] = 'Kwota do uruchomienia nie może przekraczać wniesionej przedpłaty.';
        if ($f->isResignation() && ($data['zakres'] ?? '') === 'grupa' && ($data['grupa'] ?? '') === '' && !isset($err['grupa'])) $err['grupa'] = 'Wybierz grupę, z której uczestnik rezygnuje.';
        return [$data, $err];
    }

    // ── Złożenie ────────────────────────────────────────────────────────────────

    /** @return array{id:int, errors:array, report:list<string>} */
    public function create(DopForm $f, int $clientId, array $in, string $signMode, array $actor, ?array $scan = null): array
    {
        $c = self::client($clientId);
        if (!$c) return ['id' => 0, 'errors' => ['client' => 'Wybierz uczestnika.'], 'report' => []];
        [$data, $err] = self::validate($f, $in);
        $place = LdInput::str($in, 'place', 120);
        if ($place === '') $err['place'] = 'Podaj miejscowość wypełnienia.';
        $signMode = $f->forcedSignMode() ?? ($signMode === 'paper' ? 'paper' : 'link');
        if ($signMode === 'link' && filter_var($c['email'], FILTER_VALIDATE_EMAIL) === false && strlen(preg_replace('/\D/', '', $c['phone'])) < 9)
            $err['sign_mode'] = $f === DopForm::Rez1 ? 'Uczestnik nie ma e-maila ani telefonu — ePodpis niemożliwy; użyj REZ2 (prośba odręczna ze skanem).' : 'Uczestnik nie ma e-maila ani telefonu — wybierz wersję papierową (skan podpisanego wniosku).';
        if (($data['grupa'] ?? '') !== '') {
            $g = db_one("SELECT c.id, c.name, c.group_code, u.name AS instructor FROM k30_ti_enrollments e JOIN k30_ti_courses c ON c.id = e.course_id LEFT JOIN users u ON u.id = c.instructor_id WHERE e.client_id = ? AND c.id = ? LIMIT 1", [$clientId, (int)$data['grupa']]);
            if (!$g) $err['grupa'] = 'Uczestnik nie jest zapisany do tej grupy.';
            else {
                $data['grupa_nazwa'] = trim($g['name'] . (trim((string)$g['group_code']) !== '' ? ' (' . $g['group_code'] . ')' : ''));
                if ($f === DopForm::Zpro && ($data['obecny'] ?? '') === '') $data['obecny'] = (string)$g['instructor'];
            }
        }
        if ($err) return ['id' => 0, 'errors' => $err, 'report' => []];
        $now = ldNow(); $year = date('Y');
        for ($try = 0; ; $try++) {
            $seq = 1 + (int)(db_one("SELECT MAX(seq) AS m FROM dop_requests WHERE form = ?", [$f->value])['m'] ?? 0);
            try {
                $id = db_insert('dop_requests', ['form' => $f->value, 'seq' => $seq, 'number' => $f->value . '/' . str_pad((string)$seq, 6, '0', STR_PAD_LEFT) . '/' . $year,
                    'status' => DopStatus::Signing->value, 'client_id' => $clientId, 'client_name' => $c['name'], 'client_email' => $c['email'], 'client_phone' => $c['phone'],
                    'place' => $place, 'data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'sign_mode' => $signMode, 'steps' => '{}', 'due_on' => ldDue($now),
                    'created_by' => ($actor['id'] ?? 0) ?: null, 'created_name' => (string)$actor['name'], 'created_ip' => self::ip(), 'created_at' => $now, 'updated_at' => $now]);
                break;
            } catch (\PDOException $e) { if ($try >= 4) throw $e; }
        }
        $r = $this->get($id);
        $this->event($id, 'create', 'Złożono ' . $f->value . ' — ' . $f->title() . ' (' . ($signMode === 'link' ? 'podpis przez link' : 'wersja papierowa') . ').', $actor);
        $report = [];
        if ($signMode === 'link') {
            $report[] = 'Link do podpisu: ' . $this->sendSignLink($id, $actor);
        } else {
            $report[] = 'Wnioskodawca: ' . $this->notifyRegistered($r, $actor);
            if ($scan && ($scan['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) $report[] = $this->uploadScan($id, $scan, $actor);
            else $report[] = 'wydrukuj wniosek, daj do podpisu i wgraj skan na karcie wniosku';
        }
        return ['id' => $id, 'errors' => [], 'report' => $report];
    }

    private function contact(array $r): array
    {
        return ['name' => (string)$r['client_name'], 'email' => (string)$r['client_email'], 'phone' => (string)$r['client_phone']];
    }

    private function describe(array $res): string
    {
        if (!$res['channels']) return 'brak danych kontaktowych — powiadomiono biuro';
        return implode(', ', array_map(fn($c, $s) => ($c === 'email' ? 'e-mail' : 'SMS') . ' — ' . LicenseNotificationService::statusLabel($s), array_keys($res['channels']), $res['channels']));
    }

    public static function signUrl(string $token): string
    {
        return rtrim(APP_URL, '/') . '/karty30/ti/wniosekDop.php?' . http_build_query(['_route' => '/potwierdz/' . $token]);
    }

    public function sendSignLink(int $id, array $actor, bool $reminder = false): string
    {
        $r = $this->get($id);
        if (!$r || $r['statusEnum'] !== DopStatus::Signing || $r['sign_mode'] !== 'link') throw new DomainException('Wniosek nie czeka na podpis przez link.');
        $token = bin2hex(random_bytes(24));
        db_exec("UPDATE dop_requests SET sign_token_hash = ?, sign_token_expires = ? WHERE id = ?", [hash('sha256', $token), date('Y-m-d H:i:s', time() + LD_TOKEN_DAYS * 86400), $id]);
        $link = self::signUrl($token);
        $f = $r['formEnum'];
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $doc = mb_strtolower($f->docLabel() === 'WNIOSEK' ? 'wniosek' : $f->docLabel());
        $subject = ($reminder ? 'Przypomnienie: ' : '') . 'Podpisz ' . $doc . ' ' . $f->value . ' — ' . $f->title() . ' (' . $r['number'] . ')';
        $what = match (true) {
            $f->isResignation() => ' — rezygnacja z dniem <b>' . $e(ldDate((string)($r['d']['data_rezygnacji'] ?? ''))) . '</b>' . (($r['d']['grupa_nazwa'] ?? '') !== '' && ($r['d']['zakres'] ?? '') === 'grupa' ? ', grupa: <b>' . $e($r['d']['grupa_nazwa']) . '</b>' : ', wszystkie zajęcia i usługi'),
            $f === DopForm::Zpro => ' — zmiana prowadzącego w grupie <b>' . $e($r['d']['grupa_nazwa'] ?? '') . '</b>',
            default => ' na kwotę <b>' . $e(number_format((float)($r['d']['kwota'] ?? 0), 2, ',', ' ')) . ' zł</b>',
        };
        $body = '<p>Dzień dobry,</p><p><b>' . $e($r['created_name']) . '</b> przygotował(a) dokument <b>' . $e($f->value . ' — ' . $f->title()) . '</b> dotyczący uczestnika <b>'
            . $e($r['client_name']) . '</b>' . $what . '.</p>'
            . '<p>Sprawdź dane i <b>podpisz dokument elektronicznie</b> (ePodpis: imię i nazwisko, data, godzina i adres IP). Numer: <b>' . $e($r['number']) . '</b>. Realizacja do ' . LD_REALIZATION_DAYS . ' dni (do ' . $e(ldDate($r['due_on'])) . ').</p>';
        $sms = 'FEER: podpisz ' . $f->value . ' (' . match ($f) { DopForm::Dop1 => 'zwrot nadplaty', DopForm::Dop2 => 'platnosc z subkonta', DopForm::Rez1 => 'oswiadczenie o rezygnacji', DopForm::Rez2 => 'prosba o rezygnacje', DopForm::Zpro => 'zmiana prowadzacego' } . ', ' . $r['number'] . ') do ' . ldDate($r['due_on']) . ': ' . $link;
        $res = $this->notify->send($reminder ? LicenseNotificationType::Reminder : LicenseNotificationType::RequestStarted, $this->contact($r), $subject,
            LicenseNotificationService::layout($f->title(), $body, $link, 'Sprawdź i podpisz wniosek', $f->value), $sms, ['type' => 'dop', 'id' => $id], $actor);
        $d = $this->describe($res);
        $this->event($id, $reminder ? 'sign.remind' : 'sign.link', 'Link do podpisu: ' . $d, $actor);
        return $d;
    }

    private function notifyRegistered(array $r, array $actor): string
    {
        $f = $r['formEnum'];
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $res = $this->notify->send(LicenseNotificationType::RequestStarted, $this->contact($r), 'Zarejestrowano wniosek ' . $r['number'],
            LicenseNotificationService::layout($f->title(), '<p>Dzień dobry,</p><p>zarejestrowaliśmy wniosek <b>' . $e($f->value . ' — ' . $f->title()) . '</b> nr <b>' . $e($r['number'])
                . '</b> (wersja papierowa). Realizacja do ' . LD_REALIZATION_DAYS . ' dni — do ' . $e(ldDate($r['due_on'])) . '.</p>', '', '', $f->value),
            'FEER: zarejestrowano wniosek ' . $r['number'] . ' (' . $f->value . '). Realizacja do ' . ldDate($r['due_on']) . '.', ['type' => 'dop', 'id' => (int)$r['id']], $actor);
        return $this->describe($res);
    }

    public function bySignToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) return null;
        $r = db_one("SELECT * FROM dop_requests WHERE sign_token_hash = ?", [hash('sha256', $token)]);
        if (!$r || (string)$r['sign_token_expires'] < ldNow() || $r['status'] !== DopStatus::Signing->value) return null;
        return self::hydrate($r);
    }

    /** Podpis elektroniczny wnioskodawcy (portal). @return array errors */
    public function confirm(array $r, string $name, bool $accept): array
    {
        $err = [];
        if (mb_strlen(trim($name)) < 3) $err['name'] = 'Wpisz imię i nazwisko — to Twój podpis.';
        if (!$accept) $err['confirm'] = 'Zaznacz potwierdzenie wniosku.';
        if ($err) return $err;
        $now = ldNow();
        db_exec("UPDATE dop_requests SET status = ?, signed_at = ?, signed_name = ?, signed_ip = ?, sign_token_hash = '', updated_at = ? WHERE id = ? AND status = ?",
            [DopStatus::Verify->value, $now, mb_substr(trim($name), 0, 190), self::ip(), $now, (int)$r['id'], DopStatus::Signing->value]);
        $actor = ['id' => 0, 'name' => trim($name) . ' (podpis przez link)'];
        $this->event((int)$r['id'], 'signed', 'Wnioskodawca podpisał wniosek elektronicznie: ' . trim($name) . ', IP ' . self::ip() . '.', $actor);
        $this->alertVerify($this->get((int)$r['id']), $actor);
        return [];
    }

    /** Wersja papierowa: skan podpisanego wniosku (PDF / JPG / PNG) → do weryfikacji. */
    public function uploadScan(int $id, array $file, array $actor): string
    {
        $r = $this->get($id);
        if (!$r) throw new DomainException('Nie ma takiego wniosku.');
        [$rel, $name] = self::storeUpload($file, 'dopskan' . $id);
        db_exec("UPDATE dop_requests SET scan_path = ?, scan_name = ?, updated_at = ? WHERE id = ?", [$rel, $name, ldNow(), $id]);
        $this->event($id, 'scan', 'Wgrano skan podpisanego wniosku: ' . $name . '.', $actor);
        if ($r['statusEnum'] === DopStatus::Signing) {
            db_exec("UPDATE dop_requests SET status = ?, signed_at = ?, signed_name = ? WHERE id = ?", [DopStatus::Verify->value, ldNow(), $r['client_name'] . ' (podpis odręczny — skan)', $id]);
            $this->alertVerify($this->get($id), $actor);
        }
        return 'skan zapisany — wniosek przekazany do weryfikacji';
    }

    private function alertVerify(array $r, array $actor): void
    {
        $this->notify->alertOffice($r['form'] . ' do weryfikacji — ' . $r['number'], '<p>Wniosek <b>' . htmlspecialchars($r['formEnum']->title()) . '</b> uczestnika <b>' . htmlspecialchars((string)$r['client_name'])
            . '</b> (' . htmlspecialchars($r['formEnum']->isNonFinancial() ? ($r['d']['grupa_nazwa'] ?? '') ?: 'wszystkie zajęcia' : number_format((float)($r['d']['kwota'] ?? 0), 2, ',', ' ') . ' zł') . ') czeka na weryfikację w panelu dydaktyka › Finanse › Wnioski DOP. Termin: ' . htmlspecialchars(ldDate($r['due_on'])) . '.</p>',
            ['type' => 'dop', 'id' => (int)$r['id']], $actor, LicenseNotificationType::DeclarationAdmin);
    }

    // ── Kroki obsługi ───────────────────────────────────────────────────────────

    private function saveStep(int $id, string $step, array $data, array $actor, DopStatus $next): void
    {
        $r = $this->get($id);
        $steps = $r['stepsData'];
        $steps[$step] = $data + ['by' => (int)$actor['id'], 'name' => (string)$actor['name'], 'at' => ldNow(), 'ip' => self::ip()];
        db_exec("UPDATE dop_requests SET steps = ?, status = ?, updated_at = ? WHERE id = ?", [json_encode($steps, JSON_UNESCAPED_UNICODE), $next->value, ldNow(), $id]);
    }

    /**
     * Krok 1. DOP1: weryfikacja i decyzja (kierownik); DOP2: weryfikacja dostępności środków.
     * @return array errors
     */
    public function verify(int $id, array $in, array $actor): array
    {
        $r = $this->get($id);
        if (!$r || $r['statusEnum'] !== DopStatus::Verify) throw new DomainException('Wniosek nie czeka na weryfikację.');
        $c = self::client((int)$r['client_id']);
        $kwota = (float)($r['d']['kwota'] ?? 0);
        $grant = ($in['decision'] ?? '') === 'grant';
        if ($r['formEnum']->isNonFinancial()) {
            if (!self::canApprove((int)$actor['id'])) throw new DomainException('Decyzję podejmuje kierownik (rola „kierownik” panelu TI) albo administrator.');
            $powod = LdInput::str($in, 'powod', 500);
            if (!$grant && $powod === '') return ['powod' => 'Podaj powód odmowy.'];
            $this->saveStep($id, 'verify', ['grant' => $grant, 'powod' => $powod], $actor, $grant ? DopStatus::Execute : DopStatus::Rejected);
            $this->event($id, $grant ? 'verify.grant' : 'verify.reject', $grant ? ($r['formEnum']->isResignation() ? 'Przyjęto rezygnację.' : 'Wyrażono zgodę na zmianę prowadzącego.') . ($powod !== '' ? ' ' . $powod : '') : 'Odmowa: ' . $powod, $actor);
            if (!$grant) $this->sendDocument($id, $actor, $r['formEnum']->isResignation() ? 'Rezygnacja nieprzyjęta' : 'Podanie rozpatrzone odmownie');
            return [];
        }
        if ($r['formEnum'] === DopForm::Dop1) {
            if (!self::canApprove((int)$actor['id'])) throw new DomainException('Decyzję o zwrocie nadpłaty podejmuje kierownik (rola „kierownik” panelu TI) albo administrator.');
            $zg = LdInput::str($in, 'zgodnosc', 10);
            $powod = LdInput::str($in, 'powod', 500);
            if (!in_array($zg, ['zgodny', 'niezgodny'], true)) return ['zgodnosc' => 'Zaznacz zgodność numeru rachunku.'];
            if (!$grant && $powod === '') return ['powod' => 'Podaj powód odrzucenia.'];
            if ($grant && $kwota > $c['credit'] + 0.004) return ['kwota' => 'Kwota zwrotu (' . number_format($kwota, 2, ',', ' ') . ' zł) przekracza nadpłatę w księdze (' . number_format($c['credit'], 2, ',', ' ') . ' zł).'];
            if ($grant && $zg === 'niezgodny' && empty($r['d']['zal_upowaznienie'])) return ['zgodnosc' => 'Rachunek niezgodny z historią wpłat — wymagane upoważnienie do odbioru środków (załącznik).'];
            $this->saveStep($id, 'verify', ['saldo_na_dzien' => ldToday(), 'saldo' => round($c['credit'], 2), 'zgodnosc' => $zg, 'grant' => $grant, 'powod' => $powod], $actor,
                $grant ? DopStatus::Execute : DopStatus::Rejected);
            $this->event($id, $grant ? 'verify.grant' : 'verify.reject', ($grant ? 'Zatwierdzono do zwrotu' : 'Odrzucono: ' . $powod) . '. Saldo nadpłaty ' . number_format($c['credit'], 2, ',', ' ') . ' zł na ' . ldDate(ldToday()) . '.', $actor);
            if (!$grant) $this->sendDocument($id, $actor, 'Wniosek odrzucony');
            return [];
        }
        $ok = $kwota <= $c['credit'] + 0.004;
        if (!$ok && $grant) return ['kwota' => 'Brak środków: saldo subkonta (nadpłata w księdze) ' . number_format($c['credit'], 2, ',', ' ') . ' zł < ' . number_format($kwota, 2, ',', ' ') . ' zł.'];
        $this->saveStep($id, 'verify', ['saldo' => round($c['credit'], 2), 'srodki_ok' => $ok, 'grant' => $grant, 'powod' => LdInput::str($in, 'powod', 500)], $actor, $grant ? DopStatus::Approve : DopStatus::Rejected);
        $this->event($id, $grant ? 'verify.ok' : 'verify.reject', 'Dostępne saldo subkonta: ' . number_format($c['credit'], 2, ',', ' ') . ' zł' . ($grant ? '' : '; odrzucono: ' . LdInput::str($in, 'powod', 500)) . '.', $actor);
        if ($grant) $this->notify->alertOffice('DOP2 do zatwierdzenia — ' . $r['number'], '<p>Środki zweryfikowane. Wniosek czeka na zatwierdzenie kierownika.</p>', ['type' => 'dop', 'id' => $id], $actor, LicenseNotificationType::DecisionAdmin);
        else $this->sendDocument($id, $actor, 'Wniosek odrzucony');
        return [];
    }

    /** Krok 2 (DOP2): zatwierdzenie dyrektora / kierownika. */
    public function approve(int $id, bool $agree, string $note, array $actor): void
    {
        $r = $this->get($id);
        if (!$r || $r['statusEnum'] !== DopStatus::Approve) throw new DomainException('Wniosek nie czeka na zatwierdzenie.');
        if (!self::canApprove((int)$actor['id'])) throw new DomainException('Zatwierdza kierownik (rola „kierownik” panelu TI) albo administrator.');
        if (!$agree && trim($note) === '') throw new InvalidArgumentException('Podaj powód braku zgody.');
        $this->saveStep($id, 'approve', ['zgoda' => $agree, 'uwagi' => $note], $actor, $agree ? DopStatus::Execute : DopStatus::Rejected);
        $this->event($id, $agree ? 'approve' : 'approve.no', $agree ? 'Kierownik wyraził zgodę na realizację.' : 'Brak zgody: ' . $note, $actor);
        if (!$agree) $this->sendDocument($id, $actor, 'Brak zgody na realizację');
    }

    /** Krok końcowy: realizacja przelewu (data, nr transakcji) — wpis w księdze i dokument do wnioskodawcy + teczka kursanta. */
    public function execute(int $id, string $date, string $txId, array $actor, array $in = []): string
    {
        $r = $this->get($id);
        if (!$r || $r['statusEnum'] !== DopStatus::Execute) throw new DomainException('Wniosek nie czeka na realizację.');
        if ($r['formEnum']->isNonFinancial()) return $this->executeChange($r, $date, $actor, $in);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new InvalidArgumentException('Podaj datę wykonania przelewu.');
        if (trim($txId) === '') throw new InvalidArgumentException('Podaj numer transakcji / identyfikator wyciągu.');
        $cid = (int)$r['client_id'];
        $gr = ti_op_gr((float)$r['d']['kwota']);
        $ledger = [];
        if ($r['formEnum'] === DopForm::Dop1) {
            ti_op_reconcile($cid, (string)$actor['name'], ($actor['id'] ?? 0) ?: null);
            $rows = db_all("SELECT * FROM overpayment_transactions WHERE participant_id = ? AND status = 'available' ORDER BY course_id, created_at, id", [$cid]);
            $avail = array_sum(array_map(fn($x) => ti_op_gr($x['amount']), $rows));
            if ($avail < $gr) throw new DomainException('W rejestrze nadpłat uczestnika jest ' . ti_op_fmt(ti_op_zl($avail)) . ' do dyspozycji — za mało na zwrot ' . ti_op_fmt(ti_op_zl($gr)) . '. Zarejestruj nadpłatę (Finanse › Nadpłaty uczestników).');
            $left = $gr;
            foreach ($rows as $row) {
                if ($left <= 0) break;
                $part = min($left, ti_op_gr($row['amount']));
                $res = ti_op_refund((int)$row['id'], $part, (string)$r['d']['iban'], $r['number'] . ' zwrot nadpłaty', 'DOP1 ' . $r['number'] . ', transakcja ' . $txId, (string)$actor['name'], ($actor['id'] ?? 0) ?: null);
                if (is_string($res)) throw new DomainException('Zwrot w rejestrze nadpłat: ' . $res);
                $ledger[] = (int)$res; $left -= $part;
            }
        } else {
            $c = self::client($cid);
            if (ti_op_gr($c['credit']) < $gr) throw new DomainException('Saldo subkonta spadło do ' . ti_op_fmt($c['credit']) . ' — za mało na płatność.');
            $pdo = db(); $pdo->beginTransaction();
            try {
                $ledger[] = ti_op_ledger_add($cid, 0, -$gr, 'transfer', 'dop2_payout', $id, 'DOP2 ' . $r['number'] . ': ' . $r['d']['tytul'] . ' → ' . $r['d']['odbiorca'] . ' (' . substr((string)$r['d']['iban'], -4) . '), transakcja ' . $txId);
                ti_billing_recompute($cid);
                ti_op_refresh_balance($cid);
                $pdo->commit();
            } catch (\Throwable $e) { $pdo->rollBack(); throw $e; }
        }
        $this->saveStep($id, 'execute', ['data_przelewu' => $date, 'transakcja' => mb_substr(trim($txId), 0, 120)], $actor, DopStatus::Done);
        db_exec("UPDATE dop_requests SET ledger_ids = ? WHERE id = ?", [json_encode($ledger), $id]);
        $this->event($id, 'execute', 'Zrealizowano przelew ' . ldDate($date) . ', transakcja ' . $txId . '; wpisy w księdze: ' . implode(', ', $ledger) . '.', $actor);
        $d = $this->sendDocument($id, $actor, 'Wniosek zrealizowany');
        $f = $this->archive($id, $actor);
        return 'Zrealizowano. Dokument: ' . $d . '. Teczka kursanta: ' . $f . '.';
    }

    /** REZ1/REZ2: zakończenie zapisu (grupa albo wszystkie) z dniem; ZPRO: odnotowanie zmiany, opcjonalnie nowy prowadzący grupy. */
    private function executeChange(array $r, string $date, array $actor, array $in): string
    {
        $id = (int)$r['id']; $cid = (int)$r['client_id']; $d = $r['d'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new InvalidArgumentException('Podaj datę.');
        $out = [];
        if ($r['formEnum']->isResignation()) {
            $w = ($d['zakres'] ?? '') === 'grupa' && ($d['grupa'] ?? '') !== '' ? ' AND course_id = ' . (int)$d['grupa'] : '';
            $rows = db_all("SELECT e.id, c.name FROM k30_ti_enrollments e JOIN k30_ti_courses c ON c.id = e.course_id WHERE e.client_id = ? AND e.status = 'active'" . $w, [$cid]);
            foreach ($rows as $e) {
                k30_ti_unenroll_execute((int)$e['id'], $cid, $r['number'] . ' — ' . $r['formEnum']->title() . ' z dniem ' . ldDate($date) . (($d['przyczyna'] ?? '') !== '' ? ' (' . $d['przyczyna'] . ')' : ''), (int)$actor['id'] ?: null, null);
                db_exec("UPDATE k30_ti_enrollments SET end_date = ? WHERE id = ? AND (end_date IS NULL OR end_date = '' OR end_date > ?)", [$date, (int)$e['id'], $date]);
                $out[] = (string)$e['name'];
            }
            $this->saveStep($id, 'execute', ['data_zakonczenia' => $date, 'zapisy' => $out], $actor, DopStatus::Done);
            $this->event($id, 'execute', 'Zakończono zapis z dniem ' . ldDate($date) . ': ' . ($out ? implode(', ', $out) : 'brak aktywnych zapisów') . '.', $actor);
            $what = 'Rezygnacja przyjęta';
        } else {
            $uid = (int)($in['nowy_prowadzacy'] ?? 0);
            $nu = $uid ? db_one("SELECT id, name FROM users WHERE id = ? AND is_active = 1", [$uid]) : null;
            $apply = $nu && !empty($in['ustaw_w_grupie']) && (int)($d['grupa'] ?? 0) > 0;
            if ($apply) {
                $old = db_one("SELECT instructor_id FROM k30_ti_courses WHERE id = ?", [(int)$d['grupa']]);
                db_exec("UPDATE k30_ti_courses SET instructor_id = ? WHERE id = ?", [$uid, (int)$d['grupa']]);
                if (function_exists('audit_log')) audit_log('ti_dop.zpro_instructor', ['course_id' => (int)$d['grupa'], 'from' => $old['instructor_id'] ?? null, 'to' => $uid, 'doc' => $r['number']], (int)$actor['id'] ?: null);
            }
            $this->saveStep($id, 'execute', ['data_zmiany' => $date, 'nowy' => $nu['name'] ?? LdInput::str($in, 'nowy_opis', 160), 'w_systemie' => $apply], $actor, DopStatus::Done);
            $this->event($id, 'execute', 'Zmiana prowadzącego od ' . ldDate($date) . ': ' . (($nu['name'] ?? '') ?: LdInput::str($in, 'nowy_opis', 160) ?: '—') . ($apply ? ' (ustawiono w grupie)' : '') . '.', $actor);
            $what = 'Zmiana prowadzącego wprowadzona';
        }
        $doc = $this->sendDocument($id, $actor, $what);
        $f = $this->archive($id, $actor);
        return $what . '. Dokument: ' . $doc . '. Teczka kursanta: ' . $f . '.';
    }

    public function cancel(int $id, string $reason, array $actor): void
    {
        $r = $this->get($id);
        if (!$r || !in_array($r['statusEnum'], [DopStatus::Signing, DopStatus::Verify, DopStatus::Approve], true)) throw new DomainException('Wycofać można wniosek przed realizacją.');
        db_exec("UPDATE dop_requests SET status = ?, sign_token_hash = '', updated_at = ? WHERE id = ?", [DopStatus::Cancelled->value, ldNow(), $id]);
        $this->event($id, 'cancel', 'Wycofano. ' . $reason, $actor);
    }

    // ── Dokumenty ───────────────────────────────────────────────────────────────

    public function document(array $r): array
    {
        return DopDocument::render($r);
    }

    private static function dir(): string
    {
        $dir = rtrim(UPLOAD_DIR, '/') . '/' . DOP_DIR;
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        return $dir;
    }

    public static function storeUpload(array $file, string $prefix): array
    {
        if ((int)($file['size'] ?? 0) <= 0) throw new InvalidArgumentException('Plik jest pusty.');
        if ((int)$file['size'] > 15 * 1024 * 1024) throw new InvalidArgumentException('Plik jest za duży (maks. 15 MB).');
        $mime = (string)(new \finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
        $ext = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'][$mime] ?? null;
        if (!$ext) throw new InvalidArgumentException('Dozwolone pliki: PDF, JPG, PNG.');
        $name = $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $ok = is_uploaded_file((string)$file['tmp_name']) ? move_uploaded_file((string)$file['tmp_name'], self::dir() . '/' . $name) : copy((string)$file['tmp_name'], self::dir() . '/' . $name);
        if (!$ok) throw new RuntimeException('Nie udało się zapisać pliku.');
        return [DOP_DIR . '/' . $name, mb_substr(preg_replace('/[\x00-\x1F\/\\\\]/u', '_', basename((string)$file['name'])), 0, 180)];
    }

    public static function fullPath(string $rel): ?string
    {
        if (!preg_match('#^' . DOP_DIR . '/[a-z0-9_]+\.(pdf|jpg|png|html)$#', $rel)) return null;
        $p = rtrim(UPLOAD_DIR, '/') . '/' . $rel;
        return is_file($p) ? $p : null;
    }

    /** Zapis PDF i wysyłka do wnioskodawcy (e-mail z załącznikiem; SMS z informacją). */
    public function sendDocument(int $id, array $actor, string $what): string
    {
        $r = $this->get($id);
        $out = $this->document($r);
        $name = strtolower($r['form']) . 'd' . $id . '_' . bin2hex(random_bytes(6)) . '.' . $out['ext'];
        file_put_contents(self::dir() . '/' . $name, $out['bytes']);
        $rel = DOP_DIR . '/' . $name;
        db_exec("UPDATE dop_requests SET pdf_path = ? WHERE id = ?", [$rel, $id]);
        $att = $out['ext'] === 'pdf' ? [['path' => $rel, 'name' => str_replace('/', '-', $r['number']) . '.pdf', 'mime' => 'application/pdf', 'size' => strlen($out['bytes'])]] : [];
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $f = $r['formEnum'];
        $steps = $r['stepsData'];
        $why = (string)($steps['verify']['powod'] ?? '') ?: (string)($steps['approve']['uwagi'] ?? '');
        $body = '<p>Dzień dobry,</p><p>wniosek <b>' . $e($r['number']) . '</b> (' . $e($f->title()) . '): <b>' . $e(mb_strtolower($what)) . '</b>.'
            . ($r['statusEnum'] === DopStatus::Done && !$f->isNonFinancial() ? ' Przelew ' . $e(number_format((float)$r['d']['kwota'], 2, ',', ' ')) . ' zł wykonano ' . $e(ldDate($steps['execute']['data_przelewu'] ?? '')) . '.' : '')
            . ($r['statusEnum'] === DopStatus::Rejected && $why !== '' ? ' Powód: ' . $e($why) . '.' : '') . '</p>'
            . ($att ? '<p>Wniosek z adnotacjami jest w załączniku (PDF).</p>' : '<p><i>Dokument doręczymy osobno.</i></p>');
        $res = $this->notify->send(LicenseNotificationType::DecisionInterested, $this->contact($r), $f->value . ' — ' . $what . ' — ' . $r['number'],
            LicenseNotificationService::layout($what, $body, '', '', $f->value), 'FEER: wniosek ' . $r['number'] . ' - ' . mb_strtolower($what) . '. Szczegoly w e-mailu.',
            ['type' => 'dop', 'id' => $id], $actor, true, $att);
        $d = $this->describe($res);
        $this->event($id, 'document', 'Wysłano dokument (' . $what . ', ' . ($att ? 'PDF' : 'bez PDF') . '): ' . $d, $actor);
        return $d;
    }

    /** Kopia dokumentu końcowego w teczce „Dokumenty kursanta”. */
    private function archive(int $id, array $actor): string
    {
        $r = $this->get($id);
        $src = self::fullPath((string)$r['pdf_path']);
        if (!$src || !str_ends_with($src, '.pdf')) return 'bez kopii (brak PDF)';
        $acc = db_one("SELECT id FROM k30_ti_student_accounts WHERE client_id = ? ORDER BY id LIMIT 1", [(int)$r['client_id']]);
        if (!$acc) return 'uczestnik bez konta — kopia tylko we wnioskach DOP';
        try {
            require_once dirname(__DIR__, 2) . '/ti_student_folder/logic/studentFolder.php';
            TiStudentFolder::migrate();
            $aid = (int)$acc['id'];
            $relT = TiStudentFolder::DIR . '/' . $aid . '_' . bin2hex(random_bytes(8)) . '.pdf';
            $dir = rtrim(UPLOAD_DIR, '/') . '/' . TiStudentFolder::DIR;
            if (!is_dir($dir)) @mkdir($dir, 0750, true);
            if (!@copy($src, rtrim(UPLOAD_DIR, '/') . '/' . $relT)) return 'kopiowanie nieudane';
            $fid = db_insert('k30_ti_sfile', ['account_id' => $aid, 'category' => 'potwierdzenie', 'title' => mb_substr($r['number'] . ' — ' . $r['formEnum']->title(), 0, 200),
                'note' => 'Wniosek ' . $r['form'] . ' zrealizowany (moduł Wnioski DOP).', 'file_path' => $relT, 'file_name' => str_replace('/', '-', $r['number']) . '.pdf',
                'mime' => 'application/pdf', 'size' => (int)filesize($src), 'sha256' => (string)hash_file('sha256', $src), 'visible_student' => 1,
                'source' => 'system', 'status' => 'approved', 'created_by_name' => 'system (' . $r['form'] . ')', 'created_at' => ldNow()]);
            db_exec("UPDATE dop_requests SET folder_file_id = ? WHERE id = ?", [$fid, $id]);
            return 'dodano do teczki „Dokumenty kursanta”';
        } catch (\Throwable $e) {
            error_log('dop archive: ' . $e->getMessage());
            return 'kopia nieudana: ' . $e->getMessage();
        }
    }
}
