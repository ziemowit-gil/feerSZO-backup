<?php
/**
 * modules/ti_licencje/logic/tiLicencjeManager.php — Zarządzanie licencjami kursantów TI.
 *
 * Jedno miejsce reguł dla przypisań licencji (k30_ti_client_licenses): walidacja
 * (forma rozliczenia FAK / FAZ / DSC / GST obowiązkowa), statusy liczone z daty ważności
 * (Aktywna / Wygasająca wkrótce / Wygasła / Unieważniona), wyszukiwanie z filtrami,
 * statystyki, katalog oprogramowania oraz powiadomienia o przypisaniu (tiLicencjeNotify.php).
 *
 * Ekran: karty30/ti/dydaktyk/licencjeKursantow.php (kierownik). Zakładka „Licencje”
 * w koncie kursanta (karty30/ti/dydaktyk/konta.php) przypisuje i cofa przez ten sam menedżer,
 * więc wszędzie obowiązują te same reguły. Panel kursanta i API czytają te same tabele
 * (k30_ti_client_licenses() w includes/karty30.php) — pola rozliczeniowe są wewnętrzne
 * i do kursanta nie trafiają.
 *
 * Statusy NIE są zapisywane (poza „unieważniona” = status 'revoked') — liczy je statusOf()
 * z expires_at, dzięki czemu nic nie wymaga crona i nic się nie rozjeżdża.
 */
require_once dirname(__DIR__, 3) . '/modules/audit_logs/logic/audit_logs.php';
require_once __DIR__ . '/tiLicencjeSchema.php';
require_once __DIR__ . '/tiLicencjeNotify.php';

final class TiLicencjeManager
{
    /** Ile dni przed końcem ważności licencja jest „wygasająca wkrótce”. */
    public const WARN_DAYS = 30;
    public const PER_PAGE  = 50;

    /** Formy rozliczenia licencji — obowiązkowa przy każdym nowym lub edytowanym przypisaniu. */
    public const BILLING_FORMS = [
        'FAK' => ['label' => 'Faktura',          'ref_label' => 'Numer faktury',           'ref_required' => false],
        'FAZ' => ['label' => 'Faktura zbiorcza', 'ref_label' => 'Numer faktury zbiorczej', 'ref_required' => false],
        'DSC' => ['label' => 'Kod rabatowy',     'ref_label' => 'Kod rabatowy',            'ref_required' => true],
        'GST' => ['label' => 'Goodstack',        'ref_label' => 'Referencja Goodstack',    'ref_required' => false],
    ];

    public const STATUSES = [
        'active'   => 'Aktywna',
        'expiring' => 'Wygasająca wkrótce',
        'expired'  => 'Wygasła',
        'revoked'  => 'Unieważniona',
    ];

    /** Kolumny, po których można sortować listę (klucz => etykieta). */
    public const SORTS = ['client' => 'Kursant', 'software' => 'Oprogramowanie', 'expires' => 'Ważność', 'form' => 'Rozliczenie'];

    /** Limity długości pól tekstowych: pole => [etykieta, maks. znaków]. Za długiej wartości nie ucinamy po cichu (klucz!). */
    private const FIELD_MAX = [
        'login'        => ['Login / konto',          190],
        'access_key'   => ['Klucz licencyjny',      1000],
        'notes'        => ['Uwagi dla kursanta',     500],
        'billing_ref'  => ['Numer / kod rozliczenia', 190],
        'billing_note' => ['Notatka rozliczeniowa',  500],
    ];

    private const SELECT_SQL =
        "SELECT cl.*, l.name AS license_name, l.vendor, l.category, c.name AS client_name,
                COALESCE(NULLIF(cl.issued_at, ''), substr(cl.assigned_at, 1, 10)) AS issued,
                u.name AS assigned_by_name
           FROM k30_ti_client_licenses cl
           JOIN k30_ti_licenses l ON l.id = cl.license_id
           JOIN k30_clients c     ON c.id = cl.client_id
           LEFT JOIN users u      ON u.id = cl.assigned_by";

    private static function ready(): void { ti_licencje_migrate(); }

    // ── Statusy ──────────────────────────────────────────────────────────────

    public static function today(): string { return date('Y-m-d'); }

    /** Ostatni dzień okna „wygasa wkrótce” (dziś + WARN_DAYS). */
    public static function soonLimit(?string $today = null): string
    {
        return date('Y-m-d', strtotime(($today ?? self::today()) . ' +' . self::WARN_DAYS . ' days'));
    }

    /** Status przypisania: revoked > expired > expiring > active. Data „ważna do” obowiązuje włącznie. */
    public static function statusOf(array $row, ?string $today = null): string
    {
        $today ??= self::today();
        if (($row['status'] ?? 'active') === 'revoked') return 'revoked';
        $exp = trim((string)($row['expires_at'] ?? ''));
        if ($exp === '') return 'active';
        if ($exp < $today) return 'expired';
        return $exp <= self::soonLimit($today) ? 'expiring' : 'active';
    }

    /** Ile dni do końca ważności (ujemne = po terminie); null = bezterminowo. */
    public static function daysLeft(array $row, ?string $today = null): ?int
    {
        $exp = trim((string)($row['expires_at'] ?? ''));
        if ($exp === '') return null;
        return (int)round((strtotime($exp) - strtotime($today ?? self::today())) / 86400);
    }

    /** Klucz zamaskowany do list (ostatnie 4 znaki, jeśli klucz jest dość długi). */
    public static function maskKey(string $key): string
    {
        $key = trim($key);
        if ($key === '') return '';
        return mb_strlen($key) >= 8 ? '••••' . mb_substr($key, -4) : '••••';
    }

    // ── Wyszukiwanie ─────────────────────────────────────────────────────────

    /**
     * Normalizuje filtry z GET/POST do znanych wartości.
     * status: '' (bieżące = bez unieważnionych) | all | active | expiring | expired | revoked;
     * form: '' | none (brak formy, do uzupełnienia) | FAK | FAZ | DSC | GST.
     *
     * @return array{q:string,status:string,form:string,license_id:int,client_id:int,sort:string,dir:string}
     */
    public static function filters(array $src): array
    {
        $status = (string)($src['status'] ?? '');
        $form   = (string)($src['form'] ?? '');
        $sort   = (string)($src['sort'] ?? 'client');
        return [
            'q'          => mb_substr(trim((string)($src['q'] ?? '')), 0, 100),
            'status'     => in_array($status, ['', 'all', ...array_keys(self::STATUSES)], true) ? $status : '',
            'form'       => ($form === 'none' || isset(self::BILLING_FORMS[$form])) ? $form : '',
            'license_id' => max(0, (int)($src['license_id'] ?? 0)),
            'client_id'  => max(0, (int)($src['client_id'] ?? 0)),
            'sort'       => isset(self::SORTS[$sort]) ? $sort : 'client',
            'dir'        => (($src['dir'] ?? '') === 'desc') ? 'desc' : 'asc',
        ];
    }

    /**
     * Lista przypisań z filtrami i stronicowaniem.
     *
     * @return array{rows:list<array>,total:int,page:int,pages:int,per_page:int}
     */
    public static function search(array $f, int $page = 1, int $perPage = self::PER_PAGE): array
    {
        self::ready();
        $f = self::filters($f);
        [$where, $params] = self::where($f);

        $total   = (int)(db_one("SELECT COUNT(*) AS n FROM k30_ti_client_licenses cl
                                  JOIN k30_ti_licenses l ON l.id = cl.license_id
                                  JOIN k30_clients c ON c.id = cl.client_id
                                 $where", $params)['n'] ?? 0);
        $pages   = max(1, (int)ceil($total / $perPage));
        $page    = max(1, min($page, $pages));
        $offset  = ($page - 1) * $perPage;
        $rows    = db_all(self::SELECT_SQL . " $where ORDER BY " . self::orderBy($f) . " LIMIT " . (int)$perPage . " OFFSET " . (int)$offset, $params);
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $perPage];
    }

    /** @return array{0:string,1:list<mixed>} klauzula WHERE i parametry (pozycyjne) */
    private static function where(array $f): array
    {
        $today = self::today();
        $soon  = self::soonLimit($today);
        $noExp = "(cl.expires_at IS NULL OR cl.expires_at = '')";
        $w = []; $p = [];

        switch ($f['status']) {
            case 'active':   $w[] = "cl.status <> 'revoked' AND ($noExp OR cl.expires_at > ?)"; $p[] = $soon; break;
            case 'expiring': $w[] = "cl.status <> 'revoked' AND cl.expires_at >= ? AND cl.expires_at <= ?"; array_push($p, $today, $soon); break;
            case 'expired':  $w[] = "cl.status <> 'revoked' AND cl.expires_at <> '' AND cl.expires_at < ?"; $p[] = $today; break;
            case 'revoked':  $w[] = "cl.status = 'revoked'"; break;
            case 'all':      break;
            default:         $w[] = "cl.status <> 'revoked'";
        }
        if ($f['form'] === 'none')  $w[] = "cl.billing_form = ''";
        elseif ($f['form'] !== '')  { $w[] = "cl.billing_form = ?"; $p[] = $f['form']; }
        if ($f['license_id'] > 0)   { $w[] = "cl.license_id = ?"; $p[] = $f['license_id']; }
        if ($f['client_id'] > 0)    { $w[] = "cl.client_id = ?";  $p[] = $f['client_id']; }

        if ($f['q'] !== '') {
            // SQLite LIKE ignoruje wielkość tylko dla ASCII — dla „Ż/ż”, „Ł/ł” szukamy wariantów wielkości liter.
            $esc = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['q']);
            $variants = array_values(array_unique([$esc, mb_strtolower($esc), mb_strtoupper($esc), mb_convert_case($esc, MB_CASE_TITLE)]));
            $or = [];
            foreach (['c.name', 'l.name', 'l.vendor', 'cl.login', 'cl.billing_ref'] as $col) {
                foreach ($variants as $v) { $or[] = "$col LIKE ? ESCAPE '\\'"; $p[] = '%' . $v . '%'; }
            }
            $w[] = '(' . implode(' OR ', $or) . ')';
        }
        return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $p];
    }

    private static function orderBy(array $f): string
    {
        $dir = $f['dir'] === 'desc' ? 'DESC' : 'ASC';
        $primary = match ($f['sort']) {
            'software' => "l.name COLLATE NOCASE $dir",
            'expires'  => "CASE WHEN cl.expires_at IS NULL OR cl.expires_at = '' THEN 1 ELSE 0 END ASC, cl.expires_at $dir",
            'form'     => "cl.billing_form $dir",
            default    => "c.name COLLATE NOCASE $dir",
        };
        return "$primary, c.name COLLATE NOCASE ASC, l.name COLLATE NOCASE ASC, cl.id ASC";
    }

    public static function get(int $id): ?array
    {
        self::ready();
        return db_one(self::SELECT_SQL . " WHERE cl.id = ?", [$id]);
    }

    /**
     * Liczniki do kafelków (bez filtrów): per status oraz per forma rozliczenia (bez unieważnionych).
     *
     * @return array{total:int,current:int,active:int,expiring:int,expired:int,revoked:int,forms:array<string,int>}
     */
    public static function stats(): array
    {
        self::ready();
        $today = self::today();
        $soon  = self::soonLimit($today);
        $r = db_one(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(CASE WHEN cl.status = 'revoked' THEN 1 ELSE 0 END), 0) AS revoked,
                    COALESCE(SUM(CASE WHEN cl.status <> 'revoked' AND cl.expires_at <> '' AND cl.expires_at < ? THEN 1 ELSE 0 END), 0) AS expired,
                    COALESCE(SUM(CASE WHEN cl.status <> 'revoked' AND cl.expires_at >= ? AND cl.expires_at <= ? THEN 1 ELSE 0 END), 0) AS expiring,
                    COALESCE(SUM(CASE WHEN cl.status <> 'revoked' AND (cl.expires_at IS NULL OR cl.expires_at = '' OR cl.expires_at > ?) THEN 1 ELSE 0 END), 0) AS active
               FROM k30_ti_client_licenses cl",
            [$today, $today, $soon, $soon]
        ) ?: [];
        $out = ['total' => (int)($r['total'] ?? 0), 'active' => (int)($r['active'] ?? 0), 'expiring' => (int)($r['expiring'] ?? 0),
                'expired' => (int)($r['expired'] ?? 0), 'revoked' => (int)($r['revoked'] ?? 0)];
        $out['current'] = $out['total'] - $out['revoked'];
        $forms = array_fill_keys([...array_keys(self::BILLING_FORMS), 'none'], 0);
        foreach (db_all("SELECT billing_form, COUNT(*) AS n FROM k30_ti_client_licenses WHERE status <> 'revoked' GROUP BY billing_form") as $row) {
            $k = $row['billing_form'] === '' ? 'none' : $row['billing_form'];
            if (isset($forms[$k])) $forms[$k] = (int)$row['n'];
        }
        $out['forms'] = $forms;
        return $out;
    }

    /** Kursanci TI, którym można przypisać licencję (zapisani na kurs lub z kontem panelu). */
    public static function clients(): array
    {
        self::ready();
        return db_all(
            "SELECT DISTINCT c.id, c.name FROM k30_clients c
              WHERE c.id IN (SELECT client_id FROM k30_ti_enrollments)
                 OR c.id IN (SELECT client_id FROM k30_ti_student_accounts)
                 OR COALESCE(c.consult_client, 0) = 1
                 OR c.id IN (SELECT client_id FROM k30_schedules)
              ORDER BY c.name"
        );
    }

    public static function isTiClient(int $clientId): bool
    {
        return (bool)db_one(
            "SELECT 1 FROM k30_clients c
              WHERE c.id = ? AND (c.id IN (SELECT client_id FROM k30_ti_enrollments)
                               OR c.id IN (SELECT client_id FROM k30_ti_student_accounts)
                               OR COALESCE(c.consult_client, 0) = 1
                               OR c.id IN (SELECT client_id FROM k30_schedules))",   // także beneficjenci konsultacji
            [$clientId]
        );
    }

    // ── Walidacja i zapis przypisań ──────────────────────────────────────────

    /**
     * Waliduje dane przypisania. $existing = null → nowe przypisanie (wymaga oprogramowania i kursanta),
     * inaczej edycja (kursant i oprogramowanie są niezmienne).
     *
     * @return array{data:array<string,mixed>,errors:list<string>}
     */
    /**
     * Rozliczenie cykliczne z formularza: kwota miesięczna, dzień miesiąca (domyślnie dzień $issued), rabat (pct/amt) z opisem.
     * Puste = bez rozliczenia cyklicznego. Dla konta „tylko rozliczenie licencji” kwota jest obowiązkowa.
     * Wspólne dla licencji (TiLicencjeManager::validate) i produktów (TiProducts::validate).
     * @param list<string> $errors  dopisywane błędy
     * @return array{monthly_amount:?float,billing_day:?int,discount_type:string,discount_value:?float,discount_note:string}
     */
    public static function parseBilling(array $in, string $issued, int $clientId, array &$errors): array
    {
        $amtRaw = trim(str_replace([' ', "\xc2\xa0"], '', (string)($in['monthly_amount'] ?? '')));
        $amt = null;
        if ($amtRaw !== '') {
            if (!preg_match('/^\d{1,6}([.,]\d{1,2})?$/', $amtRaw) || (float)str_replace(',', '.', $amtRaw) <= 0) $errors[] = 'Kwota miesięczna: podaj dodatnią liczbę (np. 49,00).';
            else $amt = round((float)str_replace(',', '.', $amtRaw), 2);
        }
        $out = ['monthly_amount' => $amt, 'billing_day' => null, 'discount_type' => '', 'discount_value' => null, 'discount_note' => ''];
        $day = trim((string)($in['billing_day'] ?? ''));
        if ($amt !== null) {
            if ($day === '') $day = (string)(int)date('j', strtotime($issued));
            if (!ctype_digit($day) || (int)$day < 1 || (int)$day > 31) $errors[] = 'Dzień rozliczenia: liczba od 1 do 31.';
            else $out['billing_day'] = (int)$day;
        }
        $dt = (string)($in['discount_type'] ?? '');
        $dv = trim(str_replace(',', '.', (string)($in['discount_value'] ?? '')));
        if ($dt === 'pct' || $dt === 'amt') {
            if ($amt === null) $errors[] = 'Rabat wymaga podania kwoty miesięcznej.';
            elseif (!preg_match('/^\d{1,6}(\.\d{1,2})?$/', $dv) || (float)$dv <= 0) $errors[] = 'Rabat: podaj dodatnią wartość.';
            elseif ($dt === 'pct' && (float)$dv >= 100) $errors[] = 'Rabat procentowy musi być mniejszy niż 100%.';
            elseif ($dt === 'amt' && (float)$dv >= $amt) $errors[] = 'Rabat kwotowy musi być mniejszy niż kwota miesięczna.';
            else { $out['discount_type'] = $dt; $out['discount_value'] = round((float)$dv, 2); }
        }
        $dn = trim((string)($in['discount_note'] ?? ''));
        if (mb_strlen($dn) > 120) $errors[] = 'Opis rabatu jest za długi (maks. 120 znaków).';
        $out['discount_note'] = $out['discount_type'] !== '' ? $dn : '';
        if ($clientId > 0 && self::isLicensesOnly($clientId) && $amt === null) {
            $errors[] = 'To konto ma opcję „tylko rozliczenie licencji” — podaj kwotę miesięczną i dzień rozliczenia.';
        }
        return $out;
    }

    /** Czy konto kursanta ma opcję „tylko rozliczenie licencji”. */
    public static function isLicensesOnly(int $clientId): bool
    {
        self::ready();
        $r = db_one("SELECT 1 FROM k30_ti_student_accounts WHERE client_id = ? AND licenses_only = 1 AND is_active = 1 LIMIT 1", [$clientId]);
        return (bool)$r;
    }

    /** Kwota po rabacie (lub bez rabatu): [cena katalogowa, kwota do zapłaty]. NULL gdy brak rozliczenia cyklicznego. */
    public static function amounts(array $row): ?array
    {
        if (!isset($row['monthly_amount']) || $row['monthly_amount'] === null || $row['monthly_amount'] === '') return null;
        $list = (float)$row['monthly_amount'];
        $due  = $list;
        $type = (string)($row['discount_type'] ?? '');
        $val  = (float)($row['discount_value'] ?? 0);
        if ($type === 'pct') $due = $list * (1 - $val / 100);
        elseif ($type === 'amt') $due = $list - $val;
        return [round($list, 2), max(0.0, round($due, 2))];
    }

    /** Termin rozliczenia w danym miesiącu (dzień ograniczony do długości miesiąca): 'RRRR-MM-DD'. */
    public static function dueDate(int $billingDay, string $ym): string
    {
        $dim = (int)date('t', strtotime($ym . '-01'));
        return sprintf('%s-%02d', $ym, min(max(1, $billingDay), $dim));
    }

    public static function validate(array $in, ?array $existing = null): array
    {
        self::ready();
        $errors = []; $d = [];
        $isNew  = $existing === null;

        if ($isNew) {
            $d['license_id'] = (int)($in['license_id'] ?? 0);
            $d['client_id']  = (int)($in['client_id'] ?? 0);
            if ($d['license_id'] <= 0 || !db_one("SELECT 1 FROM k30_ti_licenses WHERE id = ? AND is_active = 1", [$d['license_id']])) {
                $errors[] = 'Wybierz oprogramowanie z katalogu (aktywne).';
            }
            if ($d['client_id'] <= 0 || !self::isTiClient($d['client_id'])) {
                $errors[] = 'Wybierz kursanta TI (zapisanego na kurs lub z kontem panelu).';
            }
        }

        foreach (self::FIELD_MAX as $k => [$label, $max]) {
            $v = trim((string)($in[$k] ?? ''));
            if (mb_strlen($v) > $max) $errors[] = 'Pole „' . $label . '” jest za długie (maks. ' . $max . ' znaków).';
            $d[$k] = $v;
        }

        $issued  = self::parseDate($in['issued_at'] ?? '');
        $expires = self::parseDate($in['expires_at'] ?? '');
        if ($issued === false)  { $errors[] = 'Data wydania ma niepoprawny format (RRRR-MM-DD).'; $issued = ''; }
        if ($expires === false) { $errors[] = 'Data ważności ma niepoprawny format (RRRR-MM-DD).'; $expires = ''; }
        if ($issued === '') $issued = $isNew ? self::today() : (string)($existing['issued'] ?? self::today());
        if ($expires !== '' && $expires < $issued) $errors[] = 'Data ważności nie może być wcześniejsza niż data wydania.';
        $d['issued_at']  = $issued;
        $d['expires_at'] = $expires !== '' ? $expires : null;

        $form = strtoupper(trim((string)($in['billing_form'] ?? '')));
        if (!isset(self::BILLING_FORMS[$form])) {
            $errors[] = 'Wybierz formę rozliczenia: FAK, FAZ, DSC lub GST.';
            $form = '';
        } elseif (self::BILLING_FORMS[$form]['ref_required'] && $d['billing_ref'] === '') {
            $errors[] = 'Podaj ' . mb_strtolower(self::BILLING_FORMS[$form]['ref_label']) . ' (wymagane dla formy ' . $form . ').';
        }
        $d['billing_form'] = $form;

        // Rozliczenie cykliczne: kwota miesięczna + dzień miesiąca (+ rabat); wspólne z produktami (TiProducts)
        $cidB = $isNew ? (int)($d['client_id'] ?? 0) : (int)($existing['client_id'] ?? 0);
        $d += self::parseBilling($in, $issued, $cidB, $errors);

        // Ochrona przed podwójnym przypisaniem (np. dwukrotne kliknięcie = dwa SMS-y): ta sama licencja, ta sama osoba, wciąż ważna.
        if ($isNew && !$errors) {
            $dup = db_one(
                "SELECT id FROM k30_ti_client_licenses
                  WHERE client_id = ? AND license_id = ? AND status = 'active'
                    AND (expires_at IS NULL OR expires_at = '' OR expires_at >= ?) LIMIT 1",
                [$d['client_id'], $d['license_id'], self::today()]
            );
            if ($dup) $errors[] = 'Kursant ma już ważną licencję na to oprogramowanie (przypisanie nr ' . (int)$dup['id'] . '). Edytuj je albo je unieważnij.';
        }
        return ['data' => $d, 'errors' => $errors];
    }

    /**
     * Przypisuje licencję kursantowi. $notifyChannels = ['email','sms'] (lub podzbiór / pusta) —
     * powiadomienie o przypisaniu wysyłane po zapisie; niepowodzenie wysyłki nie cofa przypisania.
     *
     * @return array{ok:bool,id:int,errors:list<string>,notify:?array}
     */
    public static function assign(array $in, ?int $by = null, array $notifyChannels = ['email', 'sms']): array
    {
        $v = self::validate($in);
        if ($v['errors']) return ['ok' => false, 'id' => 0, 'errors' => $v['errors'], 'notify' => null];
        $d = $v['data'];
        $sw = db_one("SELECT name FROM k30_ti_licenses WHERE id = ?", [$d['license_id']]);

        $id = self::atomic(function () use ($d, $by, $sw): int {
            $id = db_insert('k30_ti_client_licenses', $d + ['status' => 'active', 'assigned_by' => $by]);
            audit_log('ti_licencje.assigned', [
                'assignment_id' => $id, 'client_id' => $d['client_id'], 'license' => $sw['name'] ?? '',
                'billing_form' => $d['billing_form'], 'billing_ref' => $d['billing_ref'],
                'issued_at' => $d['issued_at'], 'expires_at' => $d['expires_at'],
            ], $by);
            return $id;
        });
        if ($d['monthly_amount'] !== null && $notifyChannels) {
            // Rozliczenie cykliczne: jedna wiadomość w dniu przypisania z kwotą, formą, dniem rozliczenia (i rabatem) — komunikacja rozliczeniowa
            require_once __DIR__ . '/tiLicencjeBilling.php';
            $notify = TiLicencjeBilling::sendStart($id, $notifyChannels, $by);
        } else {
            $notify = $notifyChannels ? TiLicencjeNotifier::send($id, $notifyChannels, $by) : null;
        }
        return ['ok' => true, 'id' => $id, 'errors' => [], 'notify' => $notify];
    }

    /**
     * Zmienia dane przypisania (login, klucz, daty, forma rozliczenia, notatki). Kursant i oprogramowanie
     * są niezmienne; unieważnionej licencji nie edytujemy.
     *
     * @return array{ok:bool,errors:list<string>}
     */
    public static function update(int $id, array $in, ?int $by = null): array
    {
        $cur = self::get($id);
        if (!$cur) return ['ok' => false, 'errors' => ['Nie znaleziono przypisania.']];
        if ($cur['status'] === 'revoked') return ['ok' => false, 'errors' => ['Licencja jest unieważniona — nie można jej edytować.']];
        $v = self::validate($in, $cur);
        if ($v['errors']) return ['ok' => false, 'errors' => $v['errors']];
        $d = $v['data'];

        // Ślad audytowy: nazwy zmienionych pól; wartości tylko tych, które nie są tajne ani swobodnym tekstem.
        $changed = [];
        foreach (['login', 'access_key', 'notes', 'billing_note', 'issued_at', 'expires_at', 'billing_form', 'billing_ref', 'monthly_amount', 'billing_day', 'discount_type', 'discount_value'] as $k) {
            $old = (string)($k === 'issued_at' ? ($cur['issued'] ?? '') : ($cur[$k] ?? ''));
            $new = (string)($d[$k] ?? '');
            if ($old === $new) continue;
            $changed[$k] = in_array($k, ['issued_at', 'expires_at', 'billing_form', 'billing_ref', 'monthly_amount', 'billing_day', 'discount_type', 'discount_value'], true) ? ['z' => $old, 'na' => $new] : 'zmieniono';
        }

        self::atomic(function () use ($id, $d, $by, $changed, $cur): void {
            db_exec(
                "UPDATE k30_ti_client_licenses
                    SET login = ?, access_key = ?, notes = ?, issued_at = ?, expires_at = ?,
                        billing_form = ?, billing_ref = ?, billing_note = ?,
                        monthly_amount = ?, billing_day = ?, discount_type = ?, discount_value = ?, discount_note = ?
                  WHERE id = ?",
                [$d['login'], $d['access_key'], $d['notes'], $d['issued_at'], $d['expires_at'],
                 $d['billing_form'], $d['billing_ref'], $d['billing_note'],
                 $d['monthly_amount'], $d['billing_day'], $d['discount_type'], $d['discount_value'], $d['discount_note'], $id]
            );
            if ($changed) audit_log('ti_licencje.updated', ['assignment_id' => $id, 'client_id' => (int)$cur['client_id'], 'zmiany' => $changed], $by);
        });
        return ['ok' => true, 'errors' => []];
    }

    /** Unieważnia licencję (status 'revoked', data, autor, powód). Przypisanie zostaje w historii. */
    public static function revoke(int $id, string $reason = '', ?int $by = null): array
    {
        $cur = self::get($id);
        if (!$cur) return ['ok' => false, 'error' => 'Nie znaleziono przypisania.'];
        if ($cur['status'] === 'revoked') return ['ok' => false, 'error' => 'Licencja jest już unieważniona.'];
        $reason = mb_substr(trim($reason), 0, 300);
        self::atomic(function () use ($id, $by, $reason, $cur): void {
            db_exec(
                "UPDATE k30_ti_client_licenses SET status = 'revoked', revoked_at = ?, revoked_by = ?, revoke_reason = ? WHERE id = ?",
                [date('Y-m-d H:i:s'), $by, $reason, $id]
            );
            audit_log('ti_licencje.revoked', [
                'assignment_id' => $id, 'client_id' => (int)$cur['client_id'], 'license' => $cur['license_name'], 'reason' => $reason,
            ], $by);
        });
        return ['ok' => true, 'error' => ''];
    }

    /** Powiadomienie o przypisaniu (SMS / e-mail): ponowna wysyłka z ekranu = $force. Zob. TiLicencjeNotifier::send(). */
    public static function notifyAssigned(int $id, ?int $by = null, bool $force = true, array $channels = ['email', 'sms']): array
    {
        self::ready();
        // Licencja z rozliczeniem miesięcznym: wiadomość rozliczeniowa (kwota, forma, dzień, rabat) — nie zwykłe powiadomienie
        $a = db_one("SELECT monthly_amount FROM k30_ti_client_licenses WHERE id = ?", [$id]);
        if ($a && $a['monthly_amount'] !== null && $a['monthly_amount'] !== '') {
            require_once __DIR__ . '/tiLicencjeBilling.php';
            return TiLicencjeBilling::sendStart($id, $channels, $by, 'license', $force);
        }
        return TiLicencjeNotifier::send($id, $channels, $by, $force);
    }

    /** Dziennik prób powiadomienia dla przypisania (najnowsze pierwsze). */
    public static function notifications(int $assignmentId): array
    {
        self::ready();
        return db_all("SELECT * FROM k30_ti_license_notifications WHERE assignment_id = ? ORDER BY id DESC LIMIT 50", [$assignmentId]);
    }

    // ── Katalog oprogramowania ───────────────────────────────────────────────

    /** Katalog oprogramowania z liczbą aktywnych przypisań (k30_ti_licenses_all). */
    public static function catalog(bool $activeOnly = false): array
    {
        self::ready();
        return k30_ti_licenses_all($activeOnly);
    }

    /** @return array{ok:bool,errors:list<string>,id:int} */
    public static function saveSoftware(array $in, int $id = 0, ?int $by = null): array
    {
        self::ready();
        $d = [
            'name'        => trim((string)($in['name'] ?? '')),
            'vendor'      => trim((string)($in['vendor'] ?? '')),
            'category'    => trim((string)($in['category'] ?? '')),
            'vendor_url'  => trim((string)($in['vendor_url'] ?? '')),
            'seats_total' => max(0, (int)($in['seats_total'] ?? 0)),
            'notes'       => trim((string)($in['notes'] ?? '')),
            'is_active'   => !empty($in['is_active']) ? 1 : 0,
        ];
        $errors = [];
        if ($d['name'] === '') $errors[] = 'Podaj nazwę oprogramowania.';
        foreach (['name' => 190, 'vendor' => 190, 'category' => 190, 'vendor_url' => 500, 'notes' => 500] as $k => $max) {
            if (mb_strlen($d[$k]) > $max) $errors[] = 'Pole „' . $k . '” jest za długie (maks. ' . $max . ' znaków).';
        }
        // Link jest pokazywany kursantowi jako href — dopuszczamy wyłącznie http(s).
        if ($d['vendor_url'] !== '' && !preg_match('~^https?://[^\s]+$~i', $d['vendor_url'])) {
            $errors[] = 'Link musi zaczynać się od http:// lub https://.';
        }
        if ($errors) return ['ok' => false, 'errors' => $errors, 'id' => $id];

        if ($id > 0) {
            $set = []; $p = [];
            foreach ($d as $k => $v) { $set[] = "$k = ?"; $p[] = $v; }
            $p[] = $id;
            db_exec("UPDATE k30_ti_licenses SET " . implode(', ', $set) . " WHERE id = ?", $p);
        } else {
            $id = db_insert('k30_ti_licenses', $d + ['created_by' => $by]);
        }
        return ['ok' => true, 'errors' => [], 'id' => $id];
    }

    public static function toggleSoftware(int $id): void
    {
        self::ready();
        db_exec("UPDATE k30_ti_licenses SET is_active = 1 - is_active WHERE id = ?", [$id]);
    }

    /** Usuwa pozycję katalogu tylko wtedy, gdy nie ma żadnych przypisań (także unieważnionych) — to historia rozliczeń. */
    public static function deleteSoftware(int $id): array
    {
        self::ready();
        $n = (int)(db_one("SELECT COUNT(*) AS n FROM k30_ti_client_licenses WHERE license_id = ?", [$id])['n'] ?? 0);
        if ($n > 0) {
            return ['ok' => false, 'error' => 'Nie można usunąć — do tego oprogramowania są przypisania (' . $n . ', także unieważnione); zostają jako historia rozliczeń. Wyłącz pozycję zamiast ją usuwać.'];
        }
        db_exec("DELETE FROM k30_ti_licenses WHERE id = ?", [$id]);
        return ['ok' => true, 'error' => ''];
    }

    // ── Pomocnicze ───────────────────────────────────────────────────────────

    /** '' = puste, false = niepoprawny format, inaczej data Y-m-d. */
    private static function parseDate(mixed $v): string|false
    {
        $s = trim((string)$v);
        if ($s === '') return '';
        $d = DateTime::createFromFormat('!Y-m-d', $s);
        return ($d && $d->format('Y-m-d') === $s) ? $s : false;
    }

    /** Operacja + wpis audytu w jednej transakcji (audit_log() dołącza do otwartej transakcji). */
    private static function atomic(callable $fn): mixed
    {
        $pdo = db();
        if ($pdo->inTransaction()) return $fn();
        $pdo->beginTransaction();
        try {
            $r = $fn();
            $pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
