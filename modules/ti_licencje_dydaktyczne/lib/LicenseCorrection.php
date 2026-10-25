<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_dydaktyczne/lib/LicenseCorrection.php — AMSK: korekty danych we WSZYSTKICH dokumentach AM
 * (sprawy AM5 / AM7: wniosek, decyzja, dane stron i okres; operacje AM11–AM24: pola wniosku i dane osoby).
 *
 * Obieg (dane osobowe → podpis ODRĘCZNY kierownika):
 *   1. Biuro wystawia korektę (pola: było → ma być, uzasadnienie) — status „pending”, dane sprawy NIE są jeszcze zmieniane.
 *   2. Druk kontrolny AMSK/1 (zmiany + QR + Code128 + miejsce na podpis) → podpis odręczny kierownika.
 *   3. Kierownik / administrator potwierdza w SZO podpisany druk → zmiany zapisują się w sprawie (status „applied”, stempel ID/czas/IP).
 *      Jeżeli pole zmieniło się od wystawienia korekty — odmowa (trzeba wystawić nową).
 *   4. Druk AMSK (korekta) idzie do osoby e-mailem w PDF (wysyłka ręczna).
 * Kody: Code128 = AMSK-NNNNNN (do skanera → wyszukiwarka korekt w panelu), QR = publiczny link weryfikacji statusu bez danych osobowych.
 * Klucze pól: col:<kolumna> | req:<klucz wniosku> | dec:<klucz decyzji>.
 */
require_once __DIR__ . '/ldSchema.php';
require_once __DIR__ . '/LicenseOperationDefs.php';
require_once __DIR__ . '/LicenseCodes.php';
require_once __DIR__ . '/LicenseManagementService.php';
require_once __DIR__ . '/LicenseOperationService.php';
require_once __DIR__ . '/LicenseDocument.php';
require_once __DIR__ . '/LicenseStorage.php';
require_once __DIR__ . '/LicenseNotificationService.php';
require_once __DIR__ . '/Am7Workflow.php';

final class LicenseCorrection
{
    public const PENDING = 'pending';
    public const APPLIED = 'applied';
    public const CANCELLED = 'cancelled';

    public static function code(int $id): string
    {
        return 'AMSK-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
    }

    /** „AMSK-000012” / „amsk12” / „12” → id (0 = nie rozpoznano). */
    public static function idFromCode(string $code): int
    {
        return preg_match('/^\s*(?:AMSK-?)?0*(\d{1,9})\s*$/i', $code, $m) ? (int)$m[1] : 0;
    }

    public static function verifyUrl(array $c): string
    {
        return rtrim(APP_URL, '/') . '/karty30/ti/licencja.php?' . http_build_query(['_route' => '/amsk/' . $c['token']]);
    }

    public static function statusLabel(string $s): string
    {
        return ['pending' => 'Czeka na podpis kierownika', 'applied' => 'Zatwierdzona — zmiany wprowadzone', 'cancelled' => 'Anulowana'][$s] ?? $s;
    }

    public static function statusColor(string $s): string
    {
        return ['pending' => '#f0a30a', 'applied' => '#008a00', 'cancelled' => '#647687'][$s] ?? '#647687';
    }

    // ── Pola do korekty ───────────────────────────────────────────────────────────

    /**
     * Pola, które można korygować w danym dokumencie / sprawie: klucz => [label, type, doc, opts|null, current(string)].
     * Typy: text, textarea, date, int, money, select.
     * @param array $t sprawa (LicenseRepository::get) albo operacja (LicenseOperationService::get)
     */
    public static function fields(string $scope, array $t): array
    {
        $o = [];
        if ($scope === 'license') {
            $am7 = ($t['form'] ?? null) === LicenseForm::Am7;
            $req = $am7 ? 'AM7/1 — wniosek' : 'AM5/1 — wniosek';
            $col = [
                'title' => ['Tytuł / utwór / produkt', 'text'], 'work_description' => ['Opis utworu / zakresu', 'textarea'], 'course_name' => ['Kurs', 'text'],
                'licensor_name' => ['Licencjodawca — nazwa', 'text'], 'licensor_email' => ['Licencjodawca — e-mail', 'text'], 'licensor_phone' => ['Licencjodawca — telefon', 'text'],
                'licensor_address' => ['Licencjodawca — adres', 'textarea'],
                'interested_name' => ['Osoba zainteresowana — imię i nazwisko', 'text'], 'interested_email' => ['Osoba zainteresowana — e-mail', 'text'],
                'interested_phone' => ['Osoba zainteresowana — telefon', 'text'],
                'period_from' => ['Okres licencji — od', 'date'], 'period_to' => ['Okres licencji — do', 'date'], 'territory' => ['Terytorium', 'text'], 'seats' => ['Liczba miejsc', 'int'],
            ];
            foreach ($col as $k => [$lbl, $ty]) $o['col:' . $k] = [$lbl, $ty, 'Dane sprawy (' . ($am7 ? 'AM7' : 'AM5') . ')', null, (string)($t[$k] ?? '')];
            if ($am7) {
                foreach (['plan' => 'Produkt, licencja, plan / SKU', 'account' => 'Konto / identyfikator'] as $k => $lbl) $o['req:' . $k] = [$lbl, 'text', $req, null, (string)($t['request'][$k] ?? '')];
                if (!empty($t['decision'])) {
                    foreach (['plan' => ['Produkt, licencja, plan / SKU', 'text'], 'account' => ['Konto / identyfikator', 'text'], 'services' => ['Usługi / aplikacje', 'textarea'],
                              'restrictions' => ['Ograniczenia i zasady korzystania', 'textarea'], 'after_end' => ['Postępowanie po zakończeniu dostępu', 'textarea']] as $k => [$lbl, $ty]) {
                        $o['dec:' . $k] = [$lbl, $ty, 'AM7/2 — decyzja', null, (string)($t['decision'][$k] ?? '')];
                    }
                }
            }
        } elseif ($scope === 'operation') {
            /** @var OperationForm $f */
            $f = $t['formEnum'];
            foreach (['account_name' => ['Osoba — imię i nazwisko', 'text'], 'account_email' => ['Osoba — e-mail', 'text'], 'account_phone' => ['Osoba — telefon', 'text']] as $k => [$lbl, $ty]) {
                $o['col:' . $k] = [$lbl, $ty, $f->value . ' — dane osoby', null, (string)($t[$k] ?? '')];
            }
            foreach ($f->requestFields() as $k => [$lbl, $ty, , $opts]) {
                if ($ty === 'check') continue;
                $o['req:' . $k] = [$lbl, $ty === 'account' ? 'text' : $ty, $f->value . ' — wniosek', $opts, (string)($t['request'][$k] ?? '')];
            }
            foreach ($f->decisionFields() as $k => [$lbl, $ty, , $opts]) {
                if (!empty($t['decision']) && array_key_exists($k, $t['decision'])) $o['dec:' . $k] = [$lbl, $ty, $f->value . ' — decyzja', $opts, (string)($t['decision'][$k] ?? '')];
            }
        }
        return $o;
    }

    /** Wartość do pokazania człowiekowi (select → etykieta, date → dd.mm.rrrr, money → zł). */
    public static function display(array $def, string $v): string
    {
        if ($v === '') return '—';
        if ($def[1] === 'date') return ldDate($v) ?: $v;
        if ($def[1] === 'money') return number_format((float)$v, 2, ',', ' ') . ' zł';
        if ($def[1] === 'select' && is_array($def[3]) && isset($def[3][$v])) return (string)$def[3][$v];
        return $v;
    }

    /** Normalizacja wpisanej wartości wg typu; wyjątek InvalidArgumentException z komunikatem dla użytkownika. */
    private static function normalize(array $def, string $raw): string
    {
        $v = trim($raw);
        if (mb_strlen($v) > 2000) throw new InvalidArgumentException('Pole „' . $def[0] . '” jest za długie (maks. 2000 znaków).');
        switch ($def[1]) {
            case 'date':
                if ($v === '') return '';
                if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $v, $m)) $v = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4))) {
                    throw new InvalidArgumentException('Pole „' . $def[0] . '”: podaj datę jako rrrr-mm-dd albo dd.mm.rrrr.');
                }
                return $v;
            case 'int':
                if ($v === '') return '0';
                if (!preg_match('/^\d{1,6}$/', $v)) throw new InvalidArgumentException('Pole „' . $def[0] . '” musi być liczbą całkowitą.');
                return (string)(int)$v;
            case 'money':
                $x = str_replace([' ', ','], ['', '.'], $v);
                if ($x === '' || !is_numeric($x) || (float)$x < 0) throw new InvalidArgumentException('Pole „' . $def[0] . '” musi być kwotą (np. 49,00).');
                return rtrim(rtrim(number_format((float)$x, 2, '.', ''), '0'), '.');
            case 'select':
                $opts = (array)$def[3];
                if (isset($opts[$v])) return $v;
                foreach ($opts as $k => $lbl) if (mb_strtolower((string)$lbl) === mb_strtolower($v)) return (string)$k;
                throw new InvalidArgumentException('Pole „' . $def[0] . '”: wybierz jedną z dozwolonych wartości.');
        }
        return $v;
    }

    private static function sameValue(array $def, string $a, string $b): bool
    {
        if ($def[1] === 'money') return abs((float)$a - (float)$b) < 0.005;
        return trim($a) === trim($b);
    }

    // ── Dostęp do celu ────────────────────────────────────────────────────────────

    /** @return array{0:array,1:string} [cel, numer sprawy / operacji] albo wyjątek */
    public static function target(string $scope, int $id): array
    {
        if ($scope === 'license') {
            $t = (new LicenseRepository())->get($id);
            if (!$t) throw new DomainException('Nie ma takiej sprawy.');
            return [$t, (string)(($t['case_number'] ?? '') ?: ldCaseNumber((int)$t['id'], 'XX'))];
        }
        if ($scope === 'operation') {
            $t = (new LicenseOperationService())->get($id);
            if (!$t) throw new DomainException('Nie ma takiej operacji.');
            return [$t, (string)$t['number']];
        }
        throw new DomainException('Nieznany rodzaj dokumentu.');
    }

    private static function ip(): string
    {
        return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
    }

    private static function event(array $c, string $action, string $note, array $actor): void
    {
        $base = ['action' => $action, 'note' => mb_substr($note, 0, 2000), 'actor_id' => ($actor['id'] ?? 0) ?: null, 'actor_name' => (string)($actor['name'] ?? ''), 'ip' => self::ip(), 'created_at' => ldNow()];
        if ($c['scope'] === 'operation') db_insert('license_events', ['license_id' => 0, 'operation_id' => (int)$c['target_id']] + $base);
        else db_insert('license_events', ['license_id' => (int)$c['target_id']] + $base);
    }

    // ── Odczyt ────────────────────────────────────────────────────────────────────

    private static function hydrate(array $r): array
    {
        $r['itemsList'] = json_decode((string)($r['items'] ?? ''), true) ?: [];
        return $r;
    }

    public static function get(int $id): ?array
    {
        ldMigrate();
        $r = db_one("SELECT * FROM license_corrections WHERE id = ?", [$id]);
        return $r ? self::hydrate($r) : null;
    }

    public static function byToken(string $token): ?array
    {
        ldMigrate();
        if (!preg_match('/^[0-9a-f]{24}$/', $token)) return null;
        $r = db_one("SELECT * FROM license_corrections WHERE token = ?", [$token]);
        return $r ? self::hydrate($r) : null;
    }

    /** Korekty celu (najnowsze pierwsze). */
    public static function forTarget(string $scope, int $id): array
    {
        ldMigrate();
        return array_map([self::class, 'hydrate'], db_all("SELECT * FROM license_corrections WHERE scope = ? AND target_id = ? ORDER BY id DESC", [$scope, $id]));
    }

    /** Lista z wyszukiwaniem po kodzie (AMSK-000012), numerze sprawy lub uzasadnieniu; oczekujące na podpis najpierw. */
    public static function search(string $q = '', int $limit = 100): array
    {
        ldMigrate();
        $q = trim($q); $w = ['1=1']; $p = [];
        if ($q !== '') {
            $id = self::idFromCode($q);
            $w = ['(id = ? OR target_number LIKE ? OR reason LIKE ? OR created_name LIKE ?)']; $p = [$id, "%$q%", "%$q%", "%$q%"];
        }
        return array_map([self::class, 'hydrate'], db_all("SELECT * FROM license_corrections WHERE " . implode(' AND ', $w)
            . " ORDER BY CASE status WHEN 'pending' THEN 0 ELSE 1 END, id DESC LIMIT " . (int)$limit, $p));
    }

    /** Zatwierdzone korekty BEZ pakietu poprawionych druków (np. błąd generowania) — wymagają ręcznego wygenerowania. */
    public static function withoutPackage(): array
    {
        ldMigrate();
        return array_map([self::class, 'hydrate'], db_all("SELECT c.* FROM license_corrections c WHERE c.status = 'applied'
            AND NOT EXISTS (SELECT 1 FROM license_doc_packages p WHERE p.correction_id = c.id) ORDER BY c.id DESC"));
    }

    public static function hasPackage(int $id): bool
    {
        try { ldMigrate(); return (bool)db_one("SELECT 1 FROM license_doc_packages WHERE correction_id = ?", [$id]); } catch (\Throwable $e) { return false; }
    }

    public static function pendingCount(): int
    {
        try { ldMigrate(); return (int)(db_one("SELECT COUNT(*) AS n FROM license_corrections WHERE status = 'pending'")['n'] ?? 0); } catch (\Throwable $e) { return 0; }
    }

    // ── Wystawienie, zatwierdzenie, anulowanie ────────────────────────────────────

    /**
     * Wystawia korektę (bez zmiany danych). $changes: klucz pola => nowa wartość (niezmienione są pomijane).
     * @return int id korekty
     */
    public function create(string $scope, int $targetId, array $changes, string $reason, array $actor): int
    {
        ldMigrate();
        [$t, $number] = self::target($scope, $targetId);
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) throw new InvalidArgumentException('Podaj uzasadnienie korekty (min. 5 znaków).');
        $defs = self::fields($scope, $t);
        $items = [];
        foreach ($changes as $key => $raw) {
            $key = (string)$key;
            if (trim((string)$raw) === '' && !isset($defs[$key])) continue;
            if (!isset($defs[$key])) throw new InvalidArgumentException('Pole nie podlega korekcie w tym dokumencie.');
            if (isset($items[$key])) throw new InvalidArgumentException('Pole „' . $defs[$key][0] . '” wskazano dwa razy.');
            $def = $defs[$key];
            $new = self::normalize($def, (string)$raw);
            if (self::sameValue($def, $def[4], $new)) continue;
            $items[$key] = ['key' => $key, 'label' => $def[0], 'doc' => $def[2], 'type' => $def[1], 'old' => $def[4], 'new' => $new,
                            'old_disp' => self::display($def, $def[4]), 'new_disp' => self::display($def, $new)];
        }
        if (!$items) throw new InvalidArgumentException('Wskaż co najmniej jedno pole z nową wartością (różną od obecnej).');
        $id = (int)db_insert('license_corrections', ['scope' => $scope, 'target_id' => $targetId, 'target_number' => $number, 'token' => bin2hex(random_bytes(12)),
            'status' => self::PENDING, 'reason' => $reason, 'items' => json_encode(array_values($items), JSON_UNESCAPED_UNICODE),
            'created_by' => ($actor['id'] ?? 0) ?: null, 'created_name' => (string)($actor['name'] ?? ''), 'created_ip' => self::ip(), 'created_at' => ldNow()]);
        $c = self::get($id);
        self::event($c, 'amsk.create', 'Wystawiono korektę ' . self::code($id) . ' (' . count($items) . ' pól) — czeka na odręczny podpis kierownika na druku AMSK/1. Uzasadnienie: ' . $reason, $actor);
        return $id;
    }

    /** Potwierdzenie podpisanego odręcznie druku AMSK/1 — zapis zmian w danych sprawy. Tylko kierownik / administrator. */
    public function approve(int $id, array $actor): string
    {
        $c = self::get($id);
        if (!$c) throw new DomainException('Nie ma takiej korekty.');
        if ($c['status'] !== self::PENDING) throw new DomainException('Ta korekta nie czeka już na podpis.');
        if (!Am7Workflow::canApprove((int)($actor['id'] ?? 0))) throw new DomainException('Korektę danych osobowych potwierdza kierownik (rola „kierownik” w panelu TI) albo administrator.');
        [$t] = self::target($c['scope'], (int)$c['target_id']);
        $defs = self::fields($c['scope'], $t);
        foreach ($c['itemsList'] as $it) {
            $def = $defs[$it['key']] ?? null;
            if (!$def) throw new DomainException('Pole „' . $it['label'] . '” nie jest już dostępne w tym dokumencie — anuluj korektę i wystaw nową.');
            if (!self::sameValue($def, $def[4], (string)$it['old'])) {
                throw new DomainException('Pole „' . $it['label'] . '” zmieniło się od wystawienia korekty (jest: ' . self::display($def, $def[4]) . ') — anuluj ją i wystaw nową.');
            }
        }
        $db = db();
        $db->beginTransaction();
        try {
            $req = $t['request'] ?? []; $dec = $t['decision'] ?? []; $cols = [];
            foreach ($c['itemsList'] as $it) {
                [$kind, $name] = explode(':', (string)$it['key'], 2);
                $val = (string)$it['new'];
                if ($kind === 'col') $cols[$name] = $defs[$it['key']][1] === 'int' ? (int)$val : $val;
                elseif ($kind === 'req') $req[$name] = $defs[$it['key']][1] === 'money' ? (float)$val : $val;
                elseif ($kind === 'dec') $dec[$name] = $defs[$it['key']][1] === 'money' ? (float)$val : $val;
            }
            $table = $c['scope'] === 'operation' ? 'license_operations' : 'licenses';
            $set = ['request_data = ?', 'updated_at = ?']; $par = [json_encode($req, JSON_UNESCAPED_UNICODE), ldNow()];
            if (array_key_exists('decision', $t) && ($dec || !empty($t['decision']))) { $set[] = 'decision_data = ?'; $par[] = json_encode($dec, JSON_UNESCAPED_UNICODE); }
            foreach ($cols as $name => $val) {   // nazwy kolumn pochodzą wyłącznie z fields() — biała lista
                $set[] = $name . ' = ?'; $par[] = $val;
            }
            $par[] = (int)$c['target_id'];
            $db->prepare("UPDATE {$table} SET " . implode(', ', $set) . " WHERE id = ?")->execute($par);
            $db->prepare("UPDATE license_corrections SET status = 'applied', applied_by = ?, applied_name = ?, applied_ip = ?, applied_at = ? WHERE id = ?")
               ->execute([($actor['id'] ?? 0) ?: null, (string)($actor['name'] ?? ''), self::ip(), ldNow(), $id]);
            $db->commit();
        } catch (\Throwable $e) { $db->rollBack(); throw $e; }
        if ($c['scope'] === 'operation') (new LicenseOperationService())->saveRecord((int)$c['target_id']);
        self::event($c, 'amsk.apply', 'Potwierdzono podpis kierownika na druku AMSK/1 — korekta ' . self::code($id) . ' wprowadzona (' . count($c['itemsList']) . ' pól).', $actor);
        // Wdrożona korekta → pakiet poprawionych druków AM (stare unieważnione) i ZIP z hasłem do osoby i kierownika (hasło SMS-em)
        require_once __DIR__ . '/LicenseDocPackage.php';
        return (new LicenseDocPackage())->run($id, $actor);
    }

    public function cancel(int $id, string $reason, array $actor): void
    {
        $c = self::get($id);
        if (!$c || $c['status'] !== self::PENDING) throw new DomainException('Anulować można tylko korektę, która czeka na podpis.');
        db()->prepare("UPDATE license_corrections SET status = 'cancelled', cancel_reason = ? WHERE id = ?")->execute([trim($reason), $id]);
        self::event($c, 'amsk.cancel', 'Anulowano korektę ' . self::code($id) . ($reason !== '' ? ': ' . trim($reason) : '') . '.', $actor);
    }

    // ── Druki i wysyłka ───────────────────────────────────────────────────────────

    /** Druk: AMSK/1 (kontrolny, do odręcznego podpisu) albo AMSK (korekta — dla osoby, tylko po zatwierdzeniu). */
    public function document(array $c, bool $control): array
    {
        if (!$control && $c['status'] !== self::APPLIED) throw new DomainException('Druk AMSK (korekta dla osoby) powstaje po zatwierdzeniu — do podpisu służy druk kontrolny AMSK/1.');
        [$t, $number] = self::target($c['scope'], (int)$c['target_id']);
        $html = (new LicenseDocument())->amskHtml($c, $t, $control);
        $num = self::docNumber($c, $control);
        return ['out' => LicenseDocument::render($html, ($control ? 'Druk kontrolny korekty (AMSK/1) — ' : 'Korekta dokumentu AM (AMSK) — ') . $number, $num, $control ? 'AMSK/1' : 'AMSK'), 'number' => $num];
    }

    public static function docNumber(array $c, bool $control): string
    {
        return $c['target_number'] . ' · ' . self::code((int)$c['id']) . ($control ? ' · AMSK/1' : ' · AMSK');
    }

    /** E-mail z PDF korekty (AMSK) do osoby — po zatwierdzeniu, wysyłka ręczna. @return string raport */
    public function send(int $id, array $actor): string
    {
        $c = self::get($id);
        if (!$c) throw new DomainException('Nie ma takiej korekty.');
        $o = $this->document($c, false);
        [$t] = self::target($c['scope'], (int)$c['target_id']);
        $contact = $c['scope'] === 'operation'
            ? ['name' => $t['account_name'], 'email' => $t['account_email'], 'phone' => $t['account_phone']]
            : (new LicenseManagementService())->interestedContact($t);
        [$rel] = LicenseStorage::put($o['out']['bytes'], $o['out']['ext'], 'amsk_' . $id);
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $body = '<p>Dzień dobry ' . $e($contact['name']) . ',</p><p><b>Przepraszamy za pomyłkę.</b> W załączniku przesyłamy korektę danych w dokumentach AM (<b>AMSK</b>) w sprawie <b>'
              . $e($c['target_number']) . '</b>. <b>Nie jest wymagane żadne działanie z Twojej strony.</b></p>';
        $att = LicenseNotificationService::attachment($rel, $o['number']);
        $notify = new LicenseNotificationService();
        $res = $notify->send(LicenseNotificationType::OperationConfirm, $contact, 'Korekta dokumentów AM (AMSK) — przepraszamy za pomyłkę, sprawa ' . $c['target_number'],
            LicenseNotificationService::layout('Korekta dokumentów AM', $body, '', '', 'AMSK'), '', ['type' => $c['scope'] === 'operation' ? 'operation' : 'license', 'id' => (int)$c['target_id']], $actor, true, $att);
        $d = (new LicenseManagementService($notify))->describe($res);
        if (!empty($res['delivered']) || in_array('queued', (array)($res['channels'] ?? []), true)) db()->prepare("UPDATE license_corrections SET sent_at = ? WHERE id = ?")->execute([ldNow(), $id]);
        self::event($c, 'amsk.sent', 'Wysłano AMSK ' . self::code($id) . ': ' . $d, $actor);
        return 'AMSK: ' . $d . ($att ? '' : ' (bez PDF)');
    }
}
