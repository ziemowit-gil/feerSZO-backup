<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_dydaktyczne/lib/LicenseOperationService.php — rejestr operacji na licencjach (AM11–AM13, AM20–AM24).
 *
 * Tryb decyzji cyfrowej (bez podpisów papierowych): złożenie = stempel składającego (ID, czas, IP); decyzja kierownika
 * (rola „kierownik” panelu TI albo administrator) = stempel zatwierdzającego (ID, czas, IP) → wydruk w układzie CIT (PDF)
 * → e-mail do osoby z PDF → wykonanie:
 *   automatycznie w SZO (execution_mode AUTOMATIC_WEBHOOK_API): AM20 (historia płatnika + doliczenie do zajęć
 *     ti_billing_add_charge() albo dane do wpłaty na rachunek), AM23 (cofnięcie: TiLicencjeManager::revoke / przydział AM),
 *     AM24 (przedłużenie: TiLicencjeManager::update / okres licencji AM),
 *   zadaniem dla IT (MANUAL_ADMIN_TASK, zgłoszenie Helpdesku): AM11 provisioning, AM13 aktywacja, AM21, AM22, AM23 po stronie
 *     dostawcy, Microsoft 365; AM12 — zadanie dla finansów (alert do biura). Zadanie ręczne zamyka „Oznacz jako wykonane”.
 * AM20 NIE wymaga akceptacji kierownika: składa biuro, osoba POTWIERDZA wniosek przez link (e-mail + SMS, portal karty30/ti/licencja.php
 * ?_route=/zmiana-platnika/{token}) — stempel potwierdzenia (imię i nazwisko, czas, IP) i dopiero wtedy wykonanie.
 * Wszczęcie (każda operacja): e-mail + SMS do osoby, której dotyczy, z numerem sprawy i terminem realizacji 7 dni.
 * Rekord JSON (operation_meta, user_context, action_payload, verification_and_execution) — record(), zapisywany w record_json.
 */
require_once __DIR__ . '/LicenseOperationDefs.php';
require_once __DIR__ . '/LicenseResources.php';
require_once __DIR__ . '/LicenseManagementService.php';

final class LicenseOperationService
{
    private LicenseNotificationService $notify;

    public function __construct(?LicenseNotificationService $notify = null)
    {
        ldMigrate();
        $this->notify = $notify ?? new LicenseNotificationService();
        if (is_file(dirname(__DIR__, 2) . '/ti_licencje/logic/tiLicencjeManager.php')) {
            require_once dirname(__DIR__, 2) . '/ti_licencje/logic/tiLicencjeManager.php';
            if (function_exists('ti_licencje_migrate')) { try { ti_licencje_migrate(); } catch (\Throwable $e) {} }
        }
    }

    // ── Odczyt ──────────────────────────────────────────────────────────────────

    public function get(int $id): ?array
    {
        $r = db_one("SELECT * FROM license_operations WHERE id = ?", [$id]);
        return $r ? self::hydrate($r) : null;
    }

    private static function hydrate(array $r): array
    {
        $r['formEnum'] = OperationForm::from((string)$r['form']);
        $r['statusEnum'] = OperationStatus::tryFrom((string)$r['status']) ?? OperationStatus::Pending;
        $r['request'] = json_decode((string)($r['request_data'] ?? ''), true) ?: [];
        $r['decision'] = json_decode((string)($r['decision_data'] ?? ''), true) ?: [];
        $r['state'] = json_decode((string)($r['current_state'] ?? ''), true) ?: [];
        return $r;
    }

    public function search(array $f = []): array
    {
        $w = []; $p = [];
        if (($st = OperationStatus::tryFrom((string)($f['status'] ?? '')))) { $w[] = 'status = ?'; $p[] = $st->value; }
        if (($fm = OperationForm::tryFrom((string)($f['form'] ?? '')))) { $w[] = 'form = ?'; $p[] = $fm->value; }
        if (!empty($f['account'])) { [$k, $i] = explode(':', (string)$f['account'] . ':0'); $w[] = 'account_kind = ? AND account_id = ?'; array_push($p, $k, (int)$i); }
        if (!empty($f['resource'])) { $w[] = 'resource_key = ?'; $p[] = (string)$f['resource']; }
        if (($q = trim((string)($f['q'] ?? ''))) !== '') { $w[] = '(lower(account_name) LIKE ? OR lower(number) LIKE ?)'; $l = '%' . mb_strtolower($q) . '%'; array_push($p, $l, $l); }
        return array_map([self::class, 'hydrate'], db_all("SELECT * FROM license_operations" . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY id DESC LIMIT 300", $p));
    }

    public function counts(): array
    {
        $c = ['pending' => 0, 'approved' => 0, 'signing' => 0];
        foreach (db_all("SELECT status, COUNT(*) AS n FROM license_operations WHERE status IN ('pending', 'approved', 'signing') GROUP BY status") as $r) $c[$r['status']] = (int)$r['n'];
        return $c;
    }

    public function events(int $opId): array
    {
        return db_all("SELECT * FROM license_events WHERE operation_id = ? ORDER BY id DESC", [$opId]);
    }

    private function event(int $opId, string $action, string $note, array $actor): void
    {
        db_insert('license_events', ['license_id' => 0, 'operation_id' => $opId, 'action' => $action, 'note' => mb_substr($note, 0, 2000),
            'actor_id' => ($actor['id'] ?? 0) ?: null, 'actor_name' => (string)($actor['name'] ?? ''), 'ip' => self::ip(), 'created_at' => ldNow()]);
    }

    private static function ip(): string
    {
        return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
    }

    // ── Formularz ───────────────────────────────────────────────────────────────

    /** Wartości domyślne wniosku z konta i zasobu. */
    public static function defaults(OperationForm $form, array $acc, ?array $res, array $actor): array
    {
        $d = ['wnioskodawca' => (string)$actor['name'], 'cel' => (string)$acc['course_name'], 'uzytkownik' => (string)$acc['name'],
              'okres_od' => ldToday(), 'data_zmiany' => ldToday(), 'data_wycofania' => ldToday(), 'polityka' => AssetPolicy::Archive->value];
        if ($res) {
            $d += ['produkt' => trim($res['vendor'] . ' ' . $res['sku']), 'identyfikator' => $res['login'] !== '' ? $res['login'] : $res['key'],
                   'okres' => trim(ldDate($res['assigned_at']) . ' — ' . ldDate($res['expires_at']), ' —'), 'obecny_platnik' => trim($res['funding']['payer'] . ' (' . $res['funding']['source'] . ')'),
                   'licencjobiorca' => ldOrgName(), 'wlasciciel' => ldOrgName() . ' (administrator konta / tenantu)', 'licencja' => $res['sku']];
            if ($res['expires_at'] !== '') $d['do_dnia'] = date('Y-m-d', strtotime($res['expires_at'] . ' +1 year'));
        }
        if ($form === OperationForm::Am20) {
            $d['tryb'] = $acc['kind'] === 'client' ? 'zajecia' : 'rachunek';
            $d['am20_1'] = LicenseResources::accountingDocs($acc, $res);   // AM20-1: podpowiedź z rejestru faktur i rozliczeń TI
        }
        return $d;
    }

    /** Walidacja wniosku wg definicji pól + reguły szczególne. @return array{0:array,1:array} [dane, błędy] */
    public static function validate(OperationForm $form, array $in, array $acc, bool $decision = false): array
    {
        $fields = $decision ? $form->decisionFields() : $form->requestFields();
        $data = []; $err = [];
        foreach ($fields as $k => [$label, $type, $req, $opts]) {
            $v = $type === 'check' ? (LdInput::bool($in, $k) ? '1' : '') : LdInput::str($in, $k, $type === 'textarea' ? 4000 : 255);
            if ($type === 'date' && $v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) { $err[$k] = $label . ': nieprawidłowa data.'; continue; }
            if ($type === 'money' && $v !== '') {
                $v = str_replace([' ', ','], ['', '.'], $v);
                if (!is_numeric($v) || (float)$v < 0) { $err[$k] = $label . ': podaj kwotę.'; continue; }
                $v = number_format((float)$v, 2, '.', '');
            }
            if ($type === 'select' && $v !== '' && is_array($opts) && !isset($opts[$v])) { $err[$k] = $label . ': nieprawidłowa wartość.'; continue; }
            if ($type === 'account' && $v !== '') {
                $digits = preg_replace('/\D/', '', $v);
                if (strlen($digits) !== 26) { $err[$k] = 'Numer rachunku musi mieć 26 cyfr (NRB).'; continue; }
                $v = $digits;
            }
            if ($req && $v === '') { $err[$k] = $label . ': pole wymagane.'; continue; }
            $data[$k] = $v;
        }
        if (!$decision) {
            if ($form === OperationForm::Am20) {
                if (($data['tryb'] ?? '') === 'zajecia' && $acc['kind'] !== 'client') $err['tryb'] = 'Doliczenie do opłaty za zajęcia jest możliwe tylko u uczestnika (kursanta).';
                if (($data['tryb'] ?? '') === 'rachunek' && ($data['rachunek'] ?? '') === '') $err['rachunek'] = 'Podaj numer rachunku do wpłat.';
                if (($data['kwota'] ?? '') !== '' && (float)$data['kwota'] <= 0) $err['kwota'] = 'Kwota musi być większa od zera.';
                // AM20-1 — wykaz dokumentów księgowych wystawionych dotąd przez FEER w związku z opłatą
                $rows = [];
                foreach ((array)($in['am20_1'] ?? []) as $i => $row) {
                    if (!is_array($row)) continue;
                    $row = ['typ' => LdInput::str($row, 'typ', 60), 'numer' => LdInput::str($row, 'numer', 120), 'data' => LdInput::date($row, 'data'),
                            'kwota' => str_replace([' ', ','], ['', '.'], LdInput::str($row, 'kwota', 20)), 'zrodlo' => LdInput::str($row, 'zrodlo', 60)];
                    if ($row['typ'] === '' && $row['numer'] === '' && $row['kwota'] === '') continue;
                    if ($row['numer'] === '' || $row['data'] === '') { $err['am20_1'] = 'AM20-1: każdy dokument musi mieć numer i datę.'; continue; }
                    if ($row['kwota'] !== '' && !is_numeric($row['kwota'])) { $err['am20_1'] = 'AM20-1: nieprawidłowa kwota przy dokumencie ' . $row['numer'] . '.'; continue; }
                    $row['kwota'] = $row['kwota'] === '' ? '' : number_format((float)$row['kwota'], 2, '.', '');
                    if ($row['typ'] === '') $row['typ'] = 'faktura';
                    $rows[] = $row;
                }
                usort($rows, fn($a, $b) => strcmp($a['data'], $b['data']));
                $data['am20_1'] = $rows;
                $data['am20_1_brak'] = LdInput::bool($in, 'am20_1_brak') ? '1' : '';
                if (!$rows && $data['am20_1_brak'] === '' && !isset($err['am20_1'])) $err['am20_1'] = 'AM20-1: wpisz dokumenty księgowe wystawione dotąd przez FEER albo zaznacz, że ich nie było.';
                $pe = LdInput::str($in, 'platnik_email', 190);
                if ($pe !== '' && !filter_var($pe, FILTER_VALIDATE_EMAIL)) $err['platnik_email'] = 'Nieprawidłowy e-mail płatnika.';
                $data['platnik_email'] = $pe;
            }
            if ($form === OperationForm::Am22) {
                if (($data['rodzaj'] ?? '') === 'zawieszenie' && ($data['do_dnia'] ?? '') === '') $err['do_dnia'] = 'Podaj, do kiedy trwa zawieszenie.';
                if (($data['rodzaj'] ?? '') === 'reasygnacja' && ($data['nowy_uzytkownik'] ?? '') === '') $err['nowy_uzytkownik'] = 'Wskaż nowego użytkownika.';
            }
            if (in_array($form, [OperationForm::Am11], true) && ($data['okres_do'] ?? '') !== '' && $data['okres_do'] < ($data['okres_od'] ?? '')) $err['okres_do'] = 'Koniec okresu jest przed początkiem.';
            if ($form === OperationForm::Am13 && ($data['zrodlo'] ?? '') === 'inne' && ($data['zrodlo_inne'] ?? '') === '') $err['zrodlo_inne'] = 'Opisz inne źródło finansowania.';
        }
        return [$data, $err];
    }

    // ── Złożenie ────────────────────────────────────────────────────────────────

    /**
     * Złożenie operacji. $proof — opcjonalny plik z $_FILES (AM12: dowód zakupu).
     * @return array{id:int, errors:array, report:list<string>}
     */
    public function create(OperationForm $form, array $acc, ?string $resourceKey, array $in, array $actor, ?array $proof = null): array
    {
        $res = null;
        if ($form->needsResource()) {
            $res = $resourceKey ? LicenseResources::resource($acc, $resourceKey) : null;
            if (!$res) return ['id' => 0, 'errors' => ['resource' => 'Wybierz posiadaną licencję, której dotyczy operacja.'], 'report' => []];
        }
        [$data, $errors] = self::validate($form, $in, $acc);
        if ($errors) return ['id' => 0, 'errors' => $errors, 'report' => []];
        $proofPath = $proofName = '';
        if ($proof && ($proof['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            try { [$proofPath, $proofName] = LicenseStorage::putUpload($proof, 'op_dowod'); }
            catch (\Throwable $e) { return ['id' => 0, 'errors' => ['proof' => $e->getMessage()], 'report' => []]; }
        }
        $now = ldNow();
        $state = $res ? ['vendor' => $res['vendor'], 'sku' => $res['sku'], 'type' => $res['type'], 'source' => $res['source'], 'login' => $res['login'],
                         'assigned_at' => $res['assigned_at'], 'expires_at' => $res['expires_at'], 'status' => $res['status'],
                         'funding_source' => $res['funding']['source'], 'payer' => $res['funding']['payer'], 'budget_position_id' => $res['funding']['budget']] : [];
        $year = date('Y');
        for ($try = 0; ; $try++) {
            $seq = 1 + (int)(db_one("SELECT MAX(op_seq) AS m FROM license_operations")['m'] ?? 0);
            try {
                $id = db_insert('license_operations', ['op_seq' => $seq, 'number' => ldOpNumber($form, $seq, ldRandomLetters(), $year, (string)$acc['sub_id']),
                    'form' => $form->value, 'status' => OperationStatus::Pending->value, 'account_kind' => $acc['kind'], 'account_id' => $acc['id'],
                    'account_name' => $acc['name'], 'account_login' => $acc['login'], 'account_role' => $acc['role'], 'account_email' => $acc['email'],
                    'account_phone' => $acc['phone'], 'course_id' => $acc['course_id'], 'course_name' => $acc['course_name'], 'resource_key' => $res['key'] ?? '',
                    'current_state' => json_encode($state, JSON_UNESCAPED_UNICODE), 'request_data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                    'proof_path' => $proofPath, 'proof_name' => $proofName, 'due_on' => ldDue($now), 'created_by' => ($actor['id'] ?? 0) ?: null,
                    'created_name' => (string)$actor['name'], 'created_ip' => self::ip(), 'created_at' => $now, 'updated_at' => $now]);
                break;
            } catch (\PDOException $e) { if ($try >= 4) throw $e; }
        }
        $op = $this->get($id);
        $this->event($id, 'op.create', 'Złożono ' . $form->value . ' — ' . $form->title() . ($res ? ' (' . $res['sku'] . ')' : '') . '. Stempel składającego: ' . $actor['name'] . ', ' . $now . ', IP ' . self::ip() . '.', $actor);
        if (!$form->needsApproval()) {
            // AM20 — bez akceptacji kierownika: osoba potwierdza wniosek przez link (e-mail + SMS); wykonanie po potwierdzeniu
            db_exec("UPDATE license_operations SET status = ? WHERE id = ?", [OperationStatus::Signing->value, $id]);
            $report = ['Link do potwierdzenia: ' . $this->sendSignLink($id, $actor)];
            $this->saveRecord($id);
        } else {
            $report = ['Osoba: ' . $this->notifyStart($op, $actor)];
            $this->notify->alertOffice($form->value . ' do decyzji — ' . $op['number'],
                '<p>' . htmlspecialchars((string)$actor['name']) . ' złożył(a) <b>' . htmlspecialchars($form->title()) . '</b> dla <b>' . htmlspecialchars($acc['name']) . '</b>'
                . ($res ? ' (licencja ' . htmlspecialchars($res['sku']) . ')' : '') . '. Decyzję podejmuje kierownik w panelu dydaktyka › Licencje › Operacje — termin do ' . htmlspecialchars(ldDate(ldDue($now))) . '.</p>',
                ['type' => 'operation', 'id' => $id], $actor, LicenseNotificationType::DecisionAdmin);
            $this->saveRecord($id);
        }
        return ['id' => $id, 'errors' => [], 'report' => $report];
    }

    // ── AM20: potwierdzenie wniosku przez osobę (link) ──────────────────────────

    public static function signUrl(string $token): string
    {
        return rtrim(APP_URL, '/') . '/karty30/ti/licencja.php?' . http_build_query(['_route' => '/zmiana-platnika/' . $token]);
    }

    /** Operacja czekająca na potwierdzenie po tokenie z linku (null = zły / wygasły / zużyty). */
    public function bySignToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) return null;
        $r = db_one("SELECT * FROM license_operations WHERE sign_token_hash = ?", [hash('sha256', $token)]);
        if (!$r || (string)$r['sign_token_expires'] < ldNow() || $r['status'] !== OperationStatus::Signing->value) return null;
        return self::hydrate($r);
    }

    /** Nowy link (poprzedni traci ważność) — e-mail + SMS do osoby; kopia e-mailem do płatnika, jeśli podano jego adres. */
    public function sendSignLink(int $id, array $actor, bool $reminder = false): string
    {
        $op = $this->get($id);
        if (!$op || $op['statusEnum'] !== OperationStatus::Signing) throw new DomainException('Wniosek nie czeka na potwierdzenie.');
        $token = bin2hex(random_bytes(24));
        db_exec("UPDATE license_operations SET sign_token_hash = ?, sign_token_expires = ? WHERE id = ?", [hash('sha256', $token), date('Y-m-d H:i:s', time() + LD_TOKEN_DAYS * 86400), $id]);
        $link = self::signUrl($token);
        $r = $op['request'];
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $pay = $r['tryb'] === 'zajecia' ? 'doliczenie <b>' . $e(self::money((float)$r['kwota'])) . '</b> do opłaty za zajęcia' : 'wpłata <b>' . $e(self::money((float)$r['kwota'])) . '</b> na rachunek ' . $e(self::nrb((string)$r['rachunek']));
        $subject = ($reminder ? 'Przypomnienie: ' : '') . 'Potwierdź zmianę płatnika licencji (AM20) — ' . $op['number'];
        $body = '<p>Dzień dobry ' . $e($op['account_name']) . ',</p><p><b>' . $e($op['created_name']) . '</b> złożył(a) w Twoim imieniu wniosek <b>AM20</b> o zmianę płatnika licencji <b>'
            . $e($op['state']['sku'] ?? '') . '</b>.</p><table style="border-collapse:collapse;margin:8px 0" cellpadding="6">'
            . '<tr><td style="background:#f2f2f2;width:170px">Numer sprawy</td><td><b>' . $e($op['number']) . '</b></td></tr>'
            . '<tr><td style="background:#f2f2f2">Nowy płatnik</td><td>' . $e($r['nowy_platnik']) . '</td></tr>'
            . '<tr><td style="background:#f2f2f2">Sposób płatności</td><td>' . $pay . '</td></tr>'
            . '<tr><td style="background:#f2f2f2">Od dnia</td><td>' . $e(ldDate($r['data_zmiany'])) . '</td></tr>'
            . '<tr><td style="background:#f2f2f2">Termin potwierdzenia</td><td><b>do ' . LD_REALIZATION_DAYS . ' dni — do ' . $e(ldDate($op['due_on'])) . '</b></td></tr></table>'
            . '<p>Zmiana wejdzie w życie dopiero po Twoim <b>potwierdzeniu wniosku</b> (przycisk poniżej). Link jest osobisty i ważny ' . LD_TOKEN_DAYS . ' dni.</p>';
        $sms = 'FEER: potwierdz zmiane platnika licencji (AM20, ' . $op['number'] . ') do ' . ldDate($op['due_on']) . ': ' . $link;
        $html = LicenseNotificationService::layout('Zmiana płatnika — potwierdzenie', $body, $link, 'Sprawdź i potwierdź wniosek', 'AM20');
        $res = $this->notify->send($reminder ? LicenseNotificationType::Reminder : LicenseNotificationType::RequestStarted,
            ['name' => $op['account_name'], 'email' => $op['account_email'], 'phone' => $op['account_phone']], $subject, $html, $sms, ['type' => 'operation', 'id' => $id], $actor);
        $out = (new LicenseManagementService($this->notify))->describe($res);
        $pe = (string)($r['platnik_email'] ?? '');
        if ($pe !== '' && strcasecmp($pe, (string)$op['account_email']) !== 0) {
            $r2 = $this->notify->send(LicenseNotificationType::RequestStarted, ['name' => (string)$r['nowy_platnik'], 'email' => $pe], $subject,
                str_replace('Dzień dobry ' . $e($op['account_name']), 'Dzień dobry', $html), '', ['type' => 'operation', 'id' => $id], $actor, false);
            $out .= '; płatnik: ' . (new LicenseManagementService($this->notify))->describe($r2);
        }
        $this->event($id, $reminder ? 'op.sign_remind' : 'op.sign_link', 'Link do potwierdzenia AM20: ' . $out, $actor);
        return $out;
    }

    /**
     * Potwierdzenie wniosku AM20 przez osobę w portalu: stempel (imię i nazwisko, czas, IP, przeglądarka) → wykonanie.
     * @return array errors
     */
    public function confirm(array $op, string $name, bool $accept): array
    {
        $err = [];
        if (mb_strlen(trim($name)) < 3) $err['name'] = 'Wpisz imię i nazwisko — to Twój podpis.';
        if (!$accept) $err['confirm'] = 'Zaznacz potwierdzenie wniosku.';
        if ($err) return $err;
        $id = (int)$op['id'];
        $now = ldNow();
        $req = $op['request'];
        $req['potwierdzenie'] = '1';
        db_exec("UPDATE license_operations SET request_data = ?, signed_at = ?, signed_ip = ?, signed_name = ?, signed_ua = ?, sign_token_hash = '', updated_at = ? WHERE id = ? AND status = ?",
            [json_encode($req, JSON_UNESCAPED_UNICODE), $now, self::ip(), mb_substr(trim($name), 0, 190), mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), $now, $id, OperationStatus::Signing->value]);
        $actor = ['id' => 0, 'name' => trim($name) . ' (potwierdzenie przez link)'];
        $this->event($id, 'op.signed', 'Osoba potwierdziła wniosek AM20: ' . trim($name) . ', ' . $now . ', IP ' . self::ip() . '.', $actor);
        $report = $this->execute($id, $actor);
        $this->notify->alertOffice('AM20 potwierdzony — ' . $op['number'], '<p><b>' . htmlspecialchars(trim($name)) . '</b> potwierdził(a) zmianę płatnika licencji <b>'
            . htmlspecialchars((string)($op['state']['sku'] ?? '')) . '</b>. ' . htmlspecialchars(implode(' · ', $report)) . '</p>', ['type' => 'operation', 'id' => $id], $actor, LicenseNotificationType::DeclarationAdmin);
        return [];
    }

    /** Wszczęcie: e-mail + SMS do osoby, której dotyczy operacja (kto, numer, termin 7 dni). */
    private function notifyStart(array $op, array $actor): string
    {
        $f = $op['formEnum'];
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $subject = $f->value . ' — ' . $f->short() . ' — sprawa ' . $op['number'];
        $sku = (string)($op['state']['sku'] ?? '');
        $body = '<p>Dzień dobry ' . $e($op['account_name']) . ',</p><p><b>' . $e($actor['name']) . '</b> wszczął(-ęła) w dniu ' . $e(ldDate(substr((string)$op['created_at'], 0, 10)))
            . ' sprawę <b>' . $e($f->title()) . '</b>' . ($sku !== '' ? ' dotyczącą licencji <b>' . $e($sku) . '</b>' : '') . '.</p>'
            . '<table style="border-collapse:collapse;margin:8px 0" cellpadding="6"><tr><td style="background:#f2f2f2;width:170px">Numer sprawy</td><td><b>' . $e($op['number']) . '</b></td></tr>'
            . '<tr><td style="background:#f2f2f2">Formularz</td><td>' . $e($f->value) . ' — ' . $e($f->title()) . '</td></tr>'
            . '<tr><td style="background:#f2f2f2">Termin realizacji</td><td><b>do ' . LD_REALIZATION_DAYS . ' dni — do ' . $e(ldDate($op['due_on'])) . '</b></td></tr></table>'
            . ($f->needsApproval() ? '<p>O decyzji poinformujemy e-mailem (dokument w PDF).</p>' : '');
        $sms = 'FEER: ' . $actor['name'] . ' wszczal(a) sprawe ' . $f->value . ' (' . $f->short() . ')' . ($sku !== '' ? ' - ' . mb_strimwidth($sku, 0, 25, '...') : '') . ', nr ' . $op['number'] . '. Realizacja do ' . LD_REALIZATION_DAYS . ' dni.';
        $r = $this->notify->send(LicenseNotificationType::RequestStarted, ['name' => $op['account_name'], 'email' => $op['account_email'], 'phone' => $op['account_phone']],
            $subject, LicenseNotificationService::layout($f->short(), $body, '', '', $f->value), $sms, ['type' => 'operation', 'id' => (int)$op['id']], $actor);
        return (new LicenseManagementService($this->notify))->describe($r);
    }

    // ── Decyzja ─────────────────────────────────────────────────────────────────

    /** Decyzja cyfrowa kierownika. @return array{errors:array, report:list<string>} */
    public function decide(int $id, bool $grant, array $in, array $actor): array
    {
        $op = $this->get($id);
        if (!$op) throw new DomainException('Nie ma takiej operacji.');
        if ($op['statusEnum'] !== OperationStatus::Pending) throw new DomainException('Operacja nie czeka na decyzję.');
        if (!$op['formEnum']->needsApproval()) throw new DomainException('Ta operacja nie wymaga akceptacji.');
        if (!Am7Workflow::canApprove((int)$actor['id'])) throw new DomainException('Decyzję podejmuje kierownik (rola „kierownik” panelu TI) albo administrator.');
        $acc = ['kind' => $op['account_kind']];
        [$data, $errors] = self::validate($op['formEnum'], $in, $acc, true);
        if (!$grant && trim((string)($data['warunki'] ?? '')) === '') $errors['warunki'] = 'Przy odmowie podaj uzasadnienie.';
        if ($errors) return ['errors' => $errors, 'report' => []];
        $now = ldNow();
        $data['grant'] = $grant;
        db_exec("UPDATE license_operations SET status = ?, decision_data = ?, decided_by = ?, decided_name = ?, decided_at = ?, decided_ip = ?, updated_at = ? WHERE id = ?",
            [($grant ? OperationStatus::Approved : OperationStatus::Rejected)->value, json_encode($data, JSON_UNESCAPED_UNICODE), $actor['id'] ?: null, (string)$actor['name'], $now, self::ip(), $now, $id]);
        $this->event($id, $grant ? 'op.approve' : 'op.reject', ($grant ? 'Zatwierdzono' : 'Odmowa') . ' — stempel decyzji: ' . $actor['name'] . ' (ID ' . (int)$actor['id'] . '), ' . $now . ', IP ' . self::ip() . '.', $actor);
        $report = [];
        if ($grant) $report = $this->execute($id, $actor, false);
        $report[] = 'Decyzja do osoby: ' . $this->sendDecision($id, $actor);
        $this->saveRecord($id);
        return ['errors' => [], 'report' => $report];
    }

    // ── Wykonanie ───────────────────────────────────────────────────────────────

    /** Wykonanie po zatwierdzeniu (albo od razu dla AM20). @return list<string> raport */
    public function execute(int $id, array $actor, bool $sendDoc = true): array
    {
        $op = $this->get($id);
        $f = $op['formEnum'];
        $r = $op['request'];
        $key = (string)$op['resource_key'];
        [$src, $rid] = array_pad(explode(':', $key, 2), 2, '0');
        $rid = (int)$rid;
        $auto = []; $manual = []; $report = []; $billingId = null;
        $now = ldNow();
        try {
            switch ($f) {
                case OperationForm::Am20:
                    db_insert('license_funding', ['resource_key' => $key, 'payer' => (string)$r['nowy_platnik'], 'funding_source' => (string)$r['nowe_zrodlo'],
                        'budget_position' => (string)($r['pozycja'] ?? ''), 'payment_mode' => (string)$r['tryb'], 'amount' => (float)$r['kwota'],
                        'bank_account' => (string)($r['rachunek'] ?? ''), 'effective_from' => (string)$r['data_zmiany'], 'operation_id' => $id,
                        'created_by' => ($actor['id'] ?? 0) ?: null, 'created_name' => (string)$actor['name'], 'created_at' => $now]);
                    $auto[] = 'nowy płatnik od ' . ldDate($r['data_zmiany']) . ': ' . $r['nowy_platnik'] . ' (' . $r['nowe_zrodlo'] . ')';
                    if ($r['tryb'] === 'zajecia') {
                        require_once dirname(__DIR__, 3) . '/includes/ti_payments.php';
                        $t = strtotime((string)$r['data_zmiany']) ?: time();
                        $billingId = ti_billing_add_charge((int)$op['account_id'], (float)$r['kwota'],
                            'Licencja ' . ($op['state']['sku'] ?? '') . ' — ' . $op['number'] . ' (AM20)', (int)date('n', $t), (int)date('Y', $t));
                        $auto[] = 'doliczono ' . self::money((float)$r['kwota']) . ' do opłaty za zajęcia (' . date('m.Y', $t) . ', rozliczenie #' . $billingId . ')';
                    } else {
                        $auto[] = 'wpłata ' . self::money((float)$r['kwota']) . ' na rachunek ' . self::nrb((string)$r['rachunek']) . ', tytuł: ' . $op['number'];
                    }
                    break;
                case OperationForm::Am23:
                    if ($src === 'ti') {
                        $x = TiLicencjeManager::revoke($rid, 'AM23 ' . $op['number'] . ': ' . ($r['uzasadnienie'] ?? ''), ($actor['id'] ?? 0) ?: null);
                        $auto[] = !empty($x['ok']) ? 'licencję cofnięto w SZO' : 'cofnięcie w SZO nieudane: ' . implode(' ', (array)($x['errors'] ?? []));
                    } elseif ($src === 'am') {
                        (new LicenseManagementService($this->notify))->revoke($rid, 'AM23 ' . $op['number'] . ': ' . ($r['uzasadnienie'] ?? ''), $actor);
                        $auto[] = 'przydział cofnięto w SZO';
                    }
                    $manual[] = 'odpięcie konta / zwrot klucza u dostawcy; dane: ' . (AssetPolicy::tryFrom((string)$r['polityka'])?->label() ?? '') . ' — ' . ($r['procedura'] ?? '');
                    break;
                case OperationForm::Am24:
                    if ($src === 'ti') {
                        $cur = TiLicencjeManager::get($rid);
                        if ($cur) {
                            $in = ['login' => $cur['login'], 'access_key' => $cur['access_key'], 'notes' => $cur['notes'], 'billing_note' => $cur['billing_note'] ?? '',
                                   'issued_at' => $cur['issued_at'] ?? '', 'expires_at' => (string)$r['do_dnia'], 'billing_form' => $cur['billing_form'] ?? '', 'billing_ref' => $cur['billing_ref'] ?? ''];
                            $x = TiLicencjeManager::update($rid, $in, ($actor['id'] ?? 0) ?: null);
                            $auto[] = !empty($x['ok']) ? 'ważność w SZO przedłużona do ' . ldDate($r['do_dnia']) : 'przedłużenie w SZO nieudane: ' . implode(' ', (array)($x['errors'] ?? []));
                        }
                        $manual[] = 'odnowienie subskrypcji u dostawcy do ' . ldDate($r['do_dnia']);
                    } elseif ($src === 'am') {
                        $lid = (int)(db_one("SELECT license_id FROM license_assignments WHERE id = ?", [$rid])['license_id'] ?? 0);
                        if ($lid) { db_exec("UPDATE licenses SET period_to = ?, updated_at = ? WHERE id = ? AND (period_to = '' OR period_to < ?)", [$r['do_dnia'], $now, $lid, $r['do_dnia']]); $auto[] = 'okres licencji w SZO do ' . ldDate($r['do_dnia']); }
                    } else {
                        $manual[] = 'przedłużenie licencji w tenancie ośrodka do ' . ldDate($r['do_dnia']);
                    }
                    if (($r['nowe_zrodlo'] ?? '') !== '') {
                        db_insert('license_funding', ['resource_key' => $key, 'payer' => $r['nowe_zrodlo'] === 'FEER' ? ldOrgName() : (string)$r['nowe_zrodlo'], 'funding_source' => (string)$r['nowe_zrodlo'],
                            'budget_position' => (string)($r['pozycja'] ?? ''), 'effective_from' => ldToday(), 'operation_id' => $id, 'created_by' => ($actor['id'] ?? 0) ?: null,
                            'created_name' => (string)$actor['name'], 'created_at' => $now]);
                    }
                    break;
                case OperationForm::Am11: $manual[] = 'zakup / przypisanie licencji ' . ($r['produkt'] ?? '') . ' (' . ldDate($r['okres_od'] ?? '') . ' — ' . ldDate($r['okres_do'] ?? '') . ')'; break;
                case OperationForm::Am12: $manual[] = 'finanse: ' . ($r['sposob'] === 'refaktura' ? 'refaktura' : 'zwrot kosztu') . ' ' . self::money((float)($r['kwota'] ?? 0)); break;
                case OperationForm::Am13: $manual[] = 'aktywacja licencji ' . ($r['licencja'] ?? '') . ' (' . ($r['aktywacja'] ?? '') . ')'; break;
                case OperationForm::Am21: $manual[] = 'zmiana planu u dostawcy na: ' . ($r['nowy_produkt'] ?? ''); break;
                case OperationForm::Am22: $manual[] = ($r['rodzaj'] === 'reasygnacja' ? 'przekazanie licencji: ' . $r['nowy_uzytkownik'] : 'zawieszenie do ' . ldDate($r['do_dnia'] ?? '')) . '; dane: ' . ($r['wytyczne'] ?? ''); break;
            }
        } catch (\Throwable $e) {
            error_log('ld op execute: ' . $e);
            $manual[] = 'automatyczne wykonanie nieudane (' . $e->getMessage() . ') — do ręcznej realizacji';
        }
        $mode = $manual ? 'MANUAL_ADMIN_TASK' : 'AUTOMATIC_WEBHOOK_API';
        $notes = trim(($auto ? 'Automatycznie: ' . implode('; ', $auto) . '. ' : '') . ($manual ? 'Do wykonania: ' . implode('; ', $manual) . '.' : ''));
        $cols = ['execution_mode' => $mode, 'execution_notes' => $notes, 'billing_id' => $billingId, 'updated_at' => $now];
        if (!$manual) { $cols['status'] = OperationStatus::Executed->value; $cols['executed_at'] = $now; $cols['executed_by'] = ($actor['id'] ?? 0) ?: null; }
        elseif ($f === OperationForm::Am20) { $cols['status'] = OperationStatus::Approved->value; }
        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($cols)));
        db_exec("UPDATE license_operations SET {$set} WHERE id = ?", [...array_values($cols), $id]);
        $this->event($id, 'op.execute', $notes, $actor);
        $report[] = $notes;
        if ($manual) $report[] = $f === OperationForm::Am12 ? 'Finanse: ' . $this->financeTask($id, $actor) : 'Helpdesk IT: ' . $this->helpdeskTask($id, $actor);
        if ($f === OperationForm::Am20) {
            $report[] = 'Informacja o płatności: ' . $this->notifyPayment($id, $actor);
            if ($sendDoc) $report[] = 'Dokument AM20 do osoby: ' . $this->sendDecision($id, $actor);
        }
        $this->saveRecord($id);
        return $report;
    }

    /** Ręczne zamknięcie zadania (wykonane u dostawcy / w finansach). */
    public function markExecuted(int $id, string $notes, array $actor): void
    {
        $op = $this->get($id);
        if (!$op || $op['statusEnum'] !== OperationStatus::Approved) throw new DomainException('Operacja nie czeka na wykonanie.');
        $now = ldNow();
        db_exec("UPDATE license_operations SET status = ?, executed_at = ?, executed_by = ?, execution_notes = ?, updated_at = ? WHERE id = ?",
            [OperationStatus::Executed->value, $now, $actor['id'] ?: null, trim((string)$op['execution_notes'] . ($notes !== '' ? ' Wykonanie: ' . $notes : '')), $now, $id]);
        $this->event($id, 'op.executed', 'Oznaczono jako wykonane. ' . $notes, $actor);
        $this->saveRecord($id);
    }

    public function cancel(int $id, string $reason, array $actor): void
    {
        $op = $this->get($id);
        if (!$op || !in_array($op['statusEnum'], [OperationStatus::Pending, OperationStatus::Signing], true)) throw new DomainException('Wycofać można tylko operację przed decyzją lub potwierdzeniem.');
        db_exec("UPDATE license_operations SET status = ?, sign_token_hash = '', updated_at = ? WHERE id = ?", [OperationStatus::Cancelled->value, ldNow(), $id]);
        $this->event($id, 'op.cancel', 'Wycofano. ' . $reason, $actor);
        $this->saveRecord($id);
    }

    private function helpdeskTask(int $id, array $actor): string
    {
        $op = $this->get($id);
        if (!empty($op['helpdesk_ticket_id'])) return 'zgłoszenie już istnieje (' . $op['helpdesk_number'] . ')';
        $desc = $op['formEnum']->value . ' — ' . $op['formEnum']->title() . "\nSprawa: " . $op['number'] . "\nOsoba: " . $op['account_name'] . ' (' . $op['account_role'] . ', ' . $op['account_login'] . ")\n"
            . ($op['state'] ? 'Licencja: ' . ($op['state']['vendor'] ?? '') . ' ' . ($op['state']['sku'] ?? '') . ' (' . ($op['state']['type'] ?? '') . ', do ' . ldDate($op['state']['expires_at'] ?? '') . ")\n" : '')
            . "\n" . $op['execution_notes'] . "\n\nDecyzja: " . ($op['decided_name'] ?: $op['created_name']) . ' ' . ($op['decided_at'] ?: $op['created_at']);
        try {
            require_once dirname(__DIR__, 3) . '/includes/helpdesk.php';
            $me = $actor['id'] ? db_one("SELECT id, name, email FROM users WHERE id = ?", [(int)$actor['id']]) : null;
            $isMs = preg_match('/microsoft|office|m365|365/i', ($op['state']['vendor'] ?? '') . ' ' . ($op['state']['sku'] ?? '') . ' ' . ($op['request']['produkt'] ?? ''));
            $tid = hd_ticket_quick_create(['id' => $me['id'] ?? null, 'name' => $me['name'] ?? (string)$actor['name'], 'email' => $me['email'] ?? ''],
                $op['formEnum']->value . ' ' . $op['formEnum']->short() . ': ' . $op['account_name'] . ' (' . $op['number'] . ')', $desc, $isMs ? 'it_m365' : 'it_oprogramowanie', 'normalny', 'licencje_' . strtolower($op['formEnum']->value));
            $num = (string)(db_one("SELECT number FROM helpdesk_tickets WHERE id = ?", [$tid])['number'] ?? '');
            db_exec("UPDATE license_operations SET helpdesk_ticket_id = ?, helpdesk_number = ? WHERE id = ?", [$tid, $num, $id]);
            $this->event($id, 'op.helpdesk', 'Zadanie dla IT: zgłoszenie ' . $num . '.', $actor);
            return 'utworzono zgłoszenie ' . $num;
        } catch (\Throwable $e) {
            $this->notify->alertOffice('Zadanie IT dla ' . $op['number'] . ' — NIE UTWORZONO', '<p>Załóż zgłoszenie ręcznie:</p><pre style="white-space:pre-wrap">' . htmlspecialchars($desc) . '</pre>',
                ['type' => 'operation', 'id' => $id], $actor, LicenseNotificationType::HelpdeskTicket);
            return 'błąd (' . $e->getMessage() . ') — biuro dostało treść zadania';
        }
    }

    private function financeTask(int $id, array $actor): string
    {
        $op = $this->get($id);
        $r = $op['request'];
        $ok = $this->notify->alertOffice('AM12 — rozliczenie licencji do realizacji — ' . $op['number'],
            '<p>Zatwierdzono ' . ($r['sposob'] === 'refaktura' ? 'refakturę' : 'zwrot kosztu') . ' licencji <b>' . htmlspecialchars((string)$r['licencja']) . '</b> dla <b>' . htmlspecialchars((string)$r['wnioskodawca'])
            . '</b>: ' . htmlspecialchars((string)($op['decision']['forma_kwota'] ?? self::money((float)$r['kwota']))) . '.</p><p>Dane do rozliczenia: ' . nl2br(htmlspecialchars((string)$r['dane'])) . '</p>',
            ['type' => 'operation', 'id' => $id], $actor, LicenseNotificationType::DecisionAdmin);
        return $ok ? 'zadanie wysłane do biura / finansów' : 'brak adresu biura — sprawdź ustawienia alertów';
    }

    /** AM20: informacja o płatności dla osoby (i płatnika, jeśli podano e-mail): kwota doliczona albo rachunek, kwota i tytuł. */
    private function notifyPayment(int $id, array $actor): string
    {
        $op = $this->get($id);
        $r = $op['request'];
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        if ($r['tryb'] === 'zajecia') {
            $t = strtotime((string)$r['data_zmiany']) ?: time();
            $txt = '<p>Od ' . $e(ldDate($r['data_zmiany'])) . ' płatnikiem licencji <b>' . $e($op['state']['sku'] ?? '') . '</b> jest <b>' . $e($r['nowy_platnik']) . '</b>. '
                 . 'Opłata za zajęcia za ' . date('m.Y', $t) . ' wzrasta o <b>' . $e(self::money((float)$r['kwota'])) . '</b> (doliczone do rozliczenia).</p>';
            $sms = 'FEER: ' . $op['number'] . ' - licencja ' . mb_strimwidth((string)($op['state']['sku'] ?? ''), 0, 25, '...') . ': do oplaty za zajecia ' . date('m.Y', $t) . ' doliczono ' . number_format((float)$r['kwota'], 2, ',', '') . ' zl.';
        } else {
            $txt = '<p>Od ' . $e(ldDate($r['data_zmiany'])) . ' płatnikiem licencji <b>' . $e($op['state']['sku'] ?? '') . '</b> jest <b>' . $e($r['nowy_platnik']) . '</b>. Prosimy o wpłatę:</p>'
                 . '<table style="border-collapse:collapse" cellpadding="6"><tr><td style="background:#f2f2f2">Kwota</td><td><b>' . $e(self::money((float)$r['kwota'])) . '</b></td></tr>'
                 . '<tr><td style="background:#f2f2f2">Rachunek</td><td><b>' . $e(self::nrb((string)$r['rachunek'])) . '</b></td></tr>'
                 . '<tr><td style="background:#f2f2f2">Odbiorca</td><td>' . $e(ldOrgName()) . '</td></tr><tr><td style="background:#f2f2f2">Tytuł</td><td><b>' . $e($op['number']) . '</b></td></tr></table>';
            $sms = 'FEER: ' . $op['number'] . ' - wplata ' . number_format((float)$r['kwota'], 2, ',', '') . ' zl na rachunek ' . (string)$r['rachunek'] . ', tytul: ' . $op['number'];
        }
        $txt .= '<p style="font-size:12px;color:#666">' . $e(OperationForm::Am20->effect()) . '</p>';
        $html = LicenseNotificationService::layout('Zmiana płatnika licencji', '<p>Dzień dobry ' . $e($op['account_name']) . ',</p>' . $txt, '', '', 'AM20');
        $res = $this->notify->send(LicenseNotificationType::DecisionInterested, ['name' => $op['account_name'], 'email' => $op['account_email'], 'phone' => $op['account_phone']],
            'Zmiana płatnika licencji — ' . $op['number'], $html, $sms, ['type' => 'operation', 'id' => $id], $actor);
        $out = (new LicenseManagementService($this->notify))->describe($res);
        $pe = (string)($r['platnik_email'] ?? '');
        if ($pe !== '' && strcasecmp($pe, (string)$op['account_email']) !== 0) {
            $res2 = $this->notify->send(LicenseNotificationType::DecisionInterested, ['name' => (string)$r['nowy_platnik'], 'email' => $pe], 'Płatność za licencję — ' . $op['number'],
                str_replace('Dzień dobry ' . $e($op['account_name']), 'Dzień dobry', $html), '', ['type' => 'operation', 'id' => $id], $actor, false);
            $out .= '; płatnik: ' . (new LicenseManagementService($this->notify))->describe($res2);
        }
        return $out;
    }

    // ── Dokument i rekord ───────────────────────────────────────────────────────

    /** Druk CIT operacji: ['bytes','mime','ext']. */
    public function document(array $op): array
    {
        return LicenseDocument::render((new LicenseDocument())->operationHtml($op), $op['formEnum']->title() . ' — ' . $op['account_name'], (string)$op['number'], $op['formEnum']->value);
    }

    /** Zapis PDF i wysyłka e-mailem do osoby (decyzja / potwierdzenie AM20). */
    public function sendDecision(int $id, array $actor): string
    {
        $op = $this->get($id);
        $out = $this->document($op);
        [$rel, $sha] = LicenseStorage::put($out['bytes'], $out['ext'], 'op' . $id);
        db_exec("UPDATE license_operations SET pdf_path = ?, pdf_sha256 = ? WHERE id = ?", [$rel, $sha, $id]);
        $f = $op['formEnum'];
        $grant = $f === OperationForm::Am20 || !empty($op['decision']['grant']);
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $att = LicenseNotificationService::attachment($rel, $op['number']);
        $body = '<p>Dzień dobry ' . $e($op['account_name']) . ',</p><p>w sprawie <b>' . $e($op['number']) . '</b> (' . $e($f->title()) . ') '
            . ($f === OperationForm::Am20 ? 'zarejestrowaliśmy zmianę płatnika.' : ($grant ? '<b>zatwierdzono</b> wniosek.' : '<b>odmówiono</b>: ' . $e($op['decision']['warunki'] ?? ''))) . '</p>'
            . ($att ? '<p>Dokument w układzie formularza jest w załączniku (PDF).</p>' : '<p><i>Dokument doręczymy osobno.</i></p>');
        $res = $this->notify->send(LicenseNotificationType::DecisionInterested, ['name' => $op['account_name'], 'email' => $op['account_email'], 'phone' => $op['account_phone']],
            $f->value . ' — ' . ($f === OperationForm::Am20 ? 'zmiana płatnika' : ($grant ? 'decyzja: zatwierdzono' : 'decyzja: odmowa')) . ' — ' . $op['number'],
            LicenseNotificationService::layout($f->short(), $body, '', '', $f->value), $f === OperationForm::Am20 ? '' : 'FEER: decyzja w sprawie ' . $op['number'] . ' - ' . ($grant ? 'zatwierdzono' : 'odmowa') . '. PDF w e-mailu.',
            ['type' => 'operation', 'id' => $id], $actor, true, $att);
        if (!$att) $this->notify->alertOffice('Dokument ' . $op['number'] . ' bez PDF', '<p>PDF nie powstał — doręcz dokument osobie inną drogą (wydruk z panelu).</p>', ['type' => 'operation', 'id' => $id], $actor, LicenseNotificationType::DecisionAdmin);
        $d = (new LicenseManagementService($this->notify))->describe($res);
        $this->event($id, 'op.document', 'Wysłano dokument ' . $f->value . ' (' . ($att ? 'PDF' : 'bez PDF') . '): ' . $d, $actor);
        return $d;
    }

    /** Rekord JSON operacji (format panelu #detail-panel i zapisu w bazie). */
    public function record(array $op): array
    {
        $r = $op['request'];
        $s = $op['state'];
        $f = $op['formEnum'];
        $st = $op['statusEnum'];
        $policy = (string)($r['polityka'] ?? '');
        $asset = match ($f) {
            OperationForm::Am23 => 'Wycofanie licencji: dane i projekty — ' . (AssetPolicy::tryFrom($policy)?->label() ?? '—') . '. ' . ($r['procedura'] ?? ''),
            OperationForm::Am22 => 'Zawieszenie/reasygnacja: ' . ($r['wytyczne'] ?? ''),
            OperationForm::Am21 => 'Zmiana planu może zmienić dostępne funkcje i limity przestrzeni — pliki pozostają na koncie, o ile dostawca nie stanowi inaczej.',
            OperationForm::Am20 => 'Brak wpływu — zmiana płatnika nie przenosi licencji ani danych.',
            OperationForm::Am24 => 'Brak wpływu — ciągłość dostępu zachowana po przedłużeniu.',
            default => 'Nowa licencja — brak istniejących zasobów do przeniesienia.',
        };
        $rec = [
            'operation_meta' => ['account_id' => (int)$op['account_id'], 'case_number' => $op['number'], 'vendor' => (string)($s['vendor'] ?? ''),
                'target_license_sku' => (string)($r['nowy_produkt'] ?? ($s['sku'] ?? ($r['produkt'] ?? ($r['licencja'] ?? '')))), 'action_type' => $f->actionType(),
                'requested_at' => (string)$op['created_at'], 'status' => $st->jsonStatus()],
            'user_context' => ['account_id' => (int)$op['account_id'], 'account_kind' => $op['account_kind'], 'full_name' => $op['account_name'], 'login' => $op['account_login'],
                'role' => $op['account_role'], 'course_or_project' => $op['course_name'], 'sub_id' => implode('/', array_slice(explode('/', (string)$op['number']), -2))],
            'action_payload' => [
                'current_state' => ['vendor' => $s['vendor'] ?? null, 'sku' => $s['sku'] ?? null, 'license_type' => $s['type'] ?? null, 'funding_source' => $s['funding_source'] ?? null,
                    'payer' => $s['payer'] ?? null, 'budget_position_id' => ($s['budget_position_id'] ?? '') !== '' ? $s['budget_position_id'] : null,
                    'assigned_at' => $s['assigned_at'] ?? null, 'expires_at' => ($s['expires_at'] ?? '') !== '' ? $s['expires_at'] : null],
                'requested_changes' => ['new_vendor_or_product' => ($r['nowy_produkt'] ?? $r['produkt'] ?? $r['licencja'] ?? null) ?: null,
                    'new_payer' => $r['nowy_platnik'] ?? null, 'new_funding_source' => ($r['nowe_zrodlo'] ?? $r['zrodlo'] ?? null) ?: null,
                    'new_budget_position_id' => ($r['pozycja'] ?? '') !== '' ? $r['pozycja'] : null,
                    'payment' => $f === OperationForm::Am20 ? ['mode' => $r['tryb'] === 'zajecia' ? 'ADD_TO_CLASS_FEES' : 'BANK_TRANSFER', 'amount' => (float)$r['kwota'],
                        'bank_account' => ($r['rachunek'] ?? '') !== '' ? $r['rachunek'] : null, 'effective_from' => $r['data_zmiany'], 'billing_id' => $op['billing_id'] ? (int)$op['billing_id'] : null] : null,
                'accounting_documents' => $f === OperationForm::Am20 ? ['form' => 'AM20-1', 'documents' => array_map(fn($d) => ['type' => $d['typ'], 'number' => $d['numer'], 'date' => $d['data'], 'amount' => $d['kwota'] === '' ? null : (float)$d['kwota']], (array)($r['am20_1'] ?? [])),
                    'count' => ($sum = LicenseResources::accountingSummary((array)($r['am20_1'] ?? [])))['count'], 'total_amount' => round($sum['sum'], 2),
                    'first_invoice_date' => $sum['first_invoice'] ?: null, 'last_invoice_date' => $sum['last_invoice'] ?: null, 'none_issued' => !empty($r['am20_1_brak'])] : null,
                    'extended_until_date' => $f === OperationForm::Am24 ? ($r['do_dnia'] ?? null) : null,
                    'data_and_assets_policy' => $policy !== '' && in_array($f, [OperationForm::Am22, OperationForm::Am23], true) ? $policy : 'KEEP_AS_IS',
                    'reason_for_action' => (string)($r['uzasadnienie'] ?? $r['cel'] ?? '')],
            ],
            'verification_and_execution' => [
                'vendor_limit_check' => (string)($op['decision']['vendor_check'] ?? (($op['resource_key'] ?? '') !== '' ? LicenseResources::vendorCheck((string)$op['resource_key']) : 'nie dotyczy')),
                'asset_impact_assessment' => $asset, 'approval_status' => $f->needsApproval() ? $st->approval() : 'NOT_REQUIRED',
                'approval_stamp' => $op['decided_at'] ? ['approver_id' => (int)$op['decided_by'], 'approver' => $op['decided_name'], 'timestamp' => $op['decided_at'], 'ip' => $op['decided_ip']] : null,
                'submission_stamp' => ['submitter_id' => (int)$op['created_by'], 'submitter' => $op['created_name'], 'timestamp' => $op['created_at'], 'ip' => $op['created_ip']],
                'confirmation_stamp' => !empty($op['signed_at']) ? ['confirmed_by' => $op['signed_name'], 'timestamp' => $op['signed_at'], 'ip' => $op['signed_ip']] : null,
                'execution_mode' => $op['execution_mode'] !== '' ? $op['execution_mode'] : (in_array($f, [OperationForm::Am20, OperationForm::Am23, OperationForm::Am24], true) ? 'AUTOMATIC_WEBHOOK_API' : 'MANUAL_ADMIN_TASK'),
                'assigned_administrator' => $op['helpdesk_number'] !== '' ? 'Dział IT — zgłoszenie ' . $op['helpdesk_number'] : 'Administrator SZO / Dział IT',
                'executed_at' => $op['executed_at'] ?: null, 'execution_notes' => (string)$op['execution_notes'],
            ],
        ];
        return $rec;
    }

    public function saveRecord(int $id): void
    {
        $op = $this->get($id);
        if ($op) db_exec("UPDATE license_operations SET record_json = ? WHERE id = ?", [json_encode($this->record($op), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), $id]);
    }

    public static function money(float $v): string
    {
        return number_format($v, 2, ',', ' ') . ' zł';
    }

    public static function nrb(string $d): string
    {
        $d = preg_replace('/\D/', '', $d);
        return strlen($d) === 26 ? substr($d, 0, 2) . ' ' . implode(' ', str_split(substr($d, 2), 4)) : $d;
    }

    /** Rachunki organizacji do wpłat (lista z konfiguracji TI). @return list<array{nrb:string,label:string}> */
    public static function orgAccounts(): array
    {
        $out = [];
        try {
            if (!function_exists('k30_ti_org_accounts_list')) require_once dirname(__DIR__, 3) . '/includes/karty30.php';
            foreach (k30_ti_org_accounts_list() as $a) {
                $nrb = preg_replace('/\D/', '', (string)($a['nrb'] ?? ''));
                if (strlen($nrb) === 26) $out[] = ['nrb' => $nrb, 'label' => trim((string)($a['label'] ?? $a['name'] ?? $a['bank'] ?? '')) ?: self::nrb($nrb)];
            }
        } catch (\Throwable $e) {}
        return $out;
    }
}
