<?php
/**
 * modules/ti_products/logic/tiProducts.php — usługi i produkty kursanta TI poza licencjami:
 * domeny, hosting i inne (np. skrzynka pocztowa, certyfikat SSL), u dostawców, z którymi współpracuje fundacja.
 *
 * Tabela k30_ti_client_products (po client_id — jak licencje k30_ti_client_licenses): typ, dostawca z listy
 * partnerów (lub „Inny” + nazwa), nazwa (domena / konto hostingowe), pakiet / serwer, data wygaśnięcia, status,
 * numery faktur „Nasza” (fundacja → kursant) i „Obca” (dostawca → fundacja), uwagi. Status zapisany (aktywny / wygasający / zawieszony / zarchiwizowany) + podpowiedź liczona z daty
 * (wygasa w ciągu SOON_DAYS dni / po terminie). Ekran: karty30/ti/dydaktyk/konta.php, zakładka „Usługi i produkty”.
 * Bez haseł i kodów AuthInfo — tych nie przechowujemy.
 */
require_once dirname(__DIR__, 3) . '/includes/karty30.php';

final class TiProducts
{
    public const SOON_DAYS = 30;

    public const TYPES = [
        'domain'  => ['label' => 'Domena',  'icon' => 'bi-globe2',      'name_label' => 'Nazwa domeny',          'name_ph' => 'np. mojafirma.pl'],
        'hosting' => ['label' => 'Hosting', 'icon' => 'bi-hdd-network', 'name_label' => 'Konto hostingowe',      'name_ph' => 'np. login konta / nazwa usługi'],
        'other'   => ['label' => 'Inne',    'icon' => 'bi-box-seam',    'name_label' => 'Nazwa usługi',          'name_ph' => 'np. skrzynka e-mail, certyfikat SSL'],
    ];

    /** Dostawcy, z którymi współpracuje fundacja („other” = Inny — nazwa w provider_other). */
    public const PROVIDERS = [
        'mydevil'    => 'MyDevil.net',
        'ovh'        => 'OVH',
        'cyberfolks' => 'Cyberfolks',
        'nazwapl'    => 'Nazwa.pl',
        'cloudflare' => 'Cloudflare',
        'other'      => 'Inny',
    ];

    public const STATUSES = [
        'active'    => ['label' => 'Aktywny',        'icon' => 'bi-check-circle-fill', 'cls' => 'text-bg-success'],
        'expiring'  => ['label' => 'Wygasający',     'icon' => 'bi-hourglass-split',   'cls' => 'text-bg-warning'],
        'suspended' => ['label' => 'Zawieszony',     'icon' => 'bi-pause-circle-fill', 'cls' => 'text-bg-danger'],
        'archived'  => ['label' => 'Zarchiwizowany', 'icon' => 'bi-archive-fill',      'cls' => 'text-bg-secondary'],
    ];

    public static function migrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_client_products (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            client_id      INTEGER NOT NULL REFERENCES k30_clients(id) ON DELETE CASCADE,
            product_type   TEXT    NOT NULL DEFAULT 'other' CHECK (product_type IN ('domain','hosting','other')),
            provider       TEXT    NOT NULL DEFAULT '',          -- klucz z PROVIDERS
            provider_other TEXT    NOT NULL DEFAULT '',          -- nazwa, gdy provider = other
            name           TEXT    NOT NULL DEFAULT '',          -- domena / konto hostingowe / nazwa usługi
            server_details TEXT    NOT NULL DEFAULT '',          -- pakiet, serwer, IP (hosting)
            expires_at     DATE,
            status         TEXT    NOT NULL DEFAULT 'active' CHECK (status IN ('active','expiring','suspended','archived')),
            invoice_own    TEXT    NOT NULL DEFAULT '',          -- nr faktury „Nasza” (wystawionej przez fundację)
            invoice_vendor TEXT    NOT NULL DEFAULT '',          -- nr faktury „Obca” (od dostawcy)
            notes          TEXT    NOT NULL DEFAULT '',
            created_by     INTEGER,
            updated_by     INTEGER,
            created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_ti_cl_prod_client ON k30_ti_client_products(client_id)");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_ti_cl_prod_exp    ON k30_ti_client_products(expires_at)");
    }

    /** Produkty kursanta: najpierw niezarchiwizowane, potem wg typu i daty wygaśnięcia. */
    public static function forClient(int $clientId): array
    {
        self::migrate();
        return db_all("SELECT * FROM k30_ti_client_products WHERE client_id = ?
                        ORDER BY status = 'archived', CASE product_type WHEN 'domain' THEN 1 WHEN 'hosting' THEN 2 ELSE 3 END,
                                 expires_at IS NULL, expires_at, name COLLATE NOCASE", [$clientId]);
    }

    public static function get(int $id): ?array
    {
        self::migrate();
        return db_one("SELECT * FROM k30_ti_client_products WHERE id = ?", [$id]) ?: null;
    }

    public static function providerName(array $p): string
    {
        return ($p['provider'] ?? '') === 'other' ? (trim((string)$p['provider_other']) ?: 'Inny') : (self::PROVIDERS[$p['provider'] ?? ''] ?? '—');
    }

    /** Dni do wygaśnięcia (ujemne = po terminie) albo null bez daty. */
    public static function daysLeft(array $p): ?int
    {
        if (empty($p['expires_at'])) return null;
        return (int)floor((strtotime((string)$p['expires_at']) - strtotime(date('Y-m-d'))) / 86400);
    }

    /** Podpowiedź z daty dla aktywnych / wygasających: '' | 'soon' | 'overdue'. */
    public static function dateHint(array $p): string
    {
        if (!in_array($p['status'] ?? '', ['active', 'expiring'], true)) return '';
        $d = self::daysLeft($p);
        if ($d === null) return '';
        return $d < 0 ? 'overdue' : ($d <= self::SOON_DAYS ? 'soon' : '');
    }

    /** Walidacja i normalizacja danych z formularza. @return array{0: array<string,mixed>, 1: list<string>} */
    public static function validate(array $in): array
    {
        $e = [];
        $type = (string)($in['product_type'] ?? '');
        if (!isset(self::TYPES[$type])) { $e[] = 'Wybierz typ produktu.'; $type = 'other'; }
        $prov = (string)($in['provider'] ?? '');
        if (!isset(self::PROVIDERS[$prov])) $e[] = 'Wybierz dostawcę.';
        $provOther = mb_substr(trim((string)($in['provider_other'] ?? '')), 0, 100);
        if ($prov === 'other' && $provOther === '') $e[] = 'Podaj nazwę dostawcy (wybrano „Inny”).';
        $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 190);
        if ($type === 'domain') {
            $name = mb_strtolower(preg_replace(['#^[a-z]+://#i', '#^www\.#i', '#[/?\#].*$#'], '', $name));   // adres → sama domena
            if ($name === '') $e[] = 'Podaj nazwę domeny.';
            elseif (!preg_match('/^(?=.{3,253}$)([\p{L}\p{N}](?:[\p{L}\p{N}-]{0,61}[\p{L}\p{N}])?\.)+[\p{L}]{2,63}$/u', $name)) $e[] = 'Nazwa domeny jest nieprawidłowa (np. mojafirma.pl).';
        } elseif ($name === '') {
            $e[] = $type === 'hosting' ? 'Podaj nazwę konta hostingowego.' : 'Podaj nazwę usługi.';
        }
        $exp = trim((string)($in['expires_at'] ?? ''));
        if ($exp !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp) || !strtotime($exp))) $e[] = 'Nieprawidłowa data wygaśnięcia.';
        $status = (string)($in['status'] ?? 'active');
        if (!isset(self::STATUSES[$status])) $e[] = 'Wybierz status.';
        return [[
            'product_type'   => $type,
            'provider'       => $prov,
            'provider_other' => $prov === 'other' ? $provOther : '',
            'name'           => $name,
            'server_details' => mb_substr(trim((string)($in['server_details'] ?? '')), 0, 500),
            'expires_at'     => $exp !== '' ? $exp : null,
            'status'         => $status,
            'invoice_own'    => mb_substr(trim((string)($in['invoice_own'] ?? '')), 0, 100),
            'invoice_vendor' => mb_substr(trim((string)($in['invoice_vendor'] ?? '')), 0, 100),
            'notes'          => mb_substr(trim((string)($in['notes'] ?? '')), 0, 1000),
        ], $e];
    }

    /** Dodanie ($id = 0) albo edycja produktu kursanta. @return array{ok: bool, id: int, errors: list<string>} */
    public static function save(int $clientId, array $in, int $id = 0, int $by = 0): array
    {
        self::migrate();
        if (!$clientId || !db_one("SELECT 1 FROM k30_clients WHERE id = ?", [$clientId])) return ['ok' => false, 'id' => 0, 'errors' => ['Nie znaleziono kursanta.']];
        [$d, $e] = self::validate($in);
        if ($id) {
            $cur = self::get($id);
            if (!$cur || (int)$cur['client_id'] !== $clientId) $e[] = 'Ten produkt nie należy do wybranego kursanta.';
        }
        if ($e) return ['ok' => false, 'id' => $id, 'errors' => $e];
        $cols = array_keys($d);
        if ($id) {
            $set = implode(', ', array_map(fn($c) => "$c = ?", $cols));
            db()->prepare("UPDATE k30_ti_client_products SET $set, updated_by = ?, updated_at = datetime('now') WHERE id = ? AND client_id = ?")
                ->execute([...array_values($d), $by ?: null, $id, $clientId]);
        } else {
            db()->prepare("INSERT INTO k30_ti_client_products (client_id, " . implode(', ', $cols) . ", created_by, updated_by, created_at, updated_at)
                           VALUES (?, " . implode(', ', array_fill(0, count($cols), '?')) . ", ?, ?, datetime('now'), datetime('now'))")
                ->execute([$clientId, ...array_values($d), $by ?: null, $by ?: null]);
            $id = (int)db()->lastInsertId();
        }
        self::audit($id ? 'ti.product.save' : 'ti.product.add', $id, $clientId, $d['name']);
        return ['ok' => true, 'id' => $id, 'errors' => []];
    }

    public static function delete(int $clientId, int $id): bool
    {
        self::migrate();
        $cur = self::get($id);
        if (!$cur || (int)$cur['client_id'] !== $clientId) return false;
        db()->prepare("DELETE FROM k30_ti_client_products WHERE id = ? AND client_id = ?")->execute([$id, $clientId]);
        self::audit('ti.product.delete', $id, $clientId, (string)$cur['name']);
        return true;
    }

    /** Adresy kopii dla administratora: ustawienie ti_products_admin_email (po przecinku), a gdy puste — aktywne konta z rolą admin. */
    public static function adminEmails(): array
    {
        $set = function_exists('org_setting') ? trim(org_setting('ti_products_admin_email')) : '';
        $list = $set !== '' ? preg_split('/[\s,;]+/', $set)
                            : array_column(db_all("SELECT email FROM users WHERE role = 'admin' AND is_active = 1 AND email <> ''"), 'email');
        return array_values(array_unique(array_filter(array_map('strtolower', array_map('trim', $list)), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL))));
    }

    /**
     * E-mail „uruchomiono w Twoim imieniu nową usługę” do kursanta (i opiekuna małoletniego — reguły jak przy licencjach,
     * TiLicencjeNotifier::recipients) + kopia do administratora (adminEmails). Szablony: ti_product_started, ti_product_started_admin.
     * @return string podsumowanie do komunikatu
     */
    public static function notifyStarted(int $id, string $byName = ''): string
    {
        require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
        require_once dirname(__DIR__, 3) . '/includes/email_templates.php';
        require_once dirname(__DIR__, 2) . '/ti_licencje/logic/tiLicencjeNotify.php';
        $p = self::get($id);
        if (!$p) return 'nie znaleziono produktu';
        $student = (string)(db_one("SELECT name FROM k30_clients WHERE id = ?", [(int)$p['client_id']])['name'] ?? '');
        $td = fn($k, $v) => '<tr><td style="padding:8px 12px;border:1px solid #e2e8f0;background:#f8fafc;width:38%"><strong>' . h($k) . '</strong></td><td style="padding:8px 12px;border:1px solid #e2e8f0">' . h($v) . '</td></tr>';
        $vars = [
            'org'         => defined('ORG_NAME') ? (string)ORG_NAME : 'Dydaktyka TI',
            'student'     => h($student),
            'type'        => h(self::TYPES[$p['product_type']]['label'] ?? ''),
            'name'        => h((string)$p['name']),
            'provider'    => h(self::providerName($p)),
            'details_row' => $p['product_type'] !== 'domain' && $p['server_details'] !== '' ? $td('Pakiet / serwer', (string)$p['server_details']) : '',
            'expires'     => $p['expires_at'] ? date('d.m.Y', strtotime((string)$p['expires_at'])) : 'bez daty',
            'status'      => h(self::STATUSES[$p['status']]['label'] ?? ''),
        ];
        $sent = []; $skip = []; $seen = [];
        foreach (TiLicencjeNotifier::recipients((int)$p['client_id']) as $rc) {
            $to = (string)$rc['email'];
            if ($rc['email_skip'] !== '') { $skip[] = $rc['email_skip']; continue; }
            if (isset($seen[strtolower($to)])) continue;
            $seen[strtolower($to)] = true;
            $first = trim(explode(' ', trim((string)$rc['name']))[0] ?? '');
            $r = email_tpl_render('ti_product_started', $vars + ['greeting' => $rc['kind'] === 'opiekun' ? ($rc['name'] !== '' ? ' ' . h($rc['name']) : '') : ($first !== '' ? ' ' . h($first) : '')]);
            if (empty($r['enabled'])) { $skip[] = 'szablon e-mail jest wyłączony'; break; }
            try { if (mail_queue_add($to, (string)$rc['name'], $r['subject'], $r['html'], '', 'ti_product', $id) > 0) $sent[] = TiLicencjeNotifier::maskEmail($to); }
            catch (\Throwable $e) { $skip[] = 'błąd kolejki: ' . $e->getMessage(); }
        }
        $admins = self::adminEmails(); $adminN = 0;
        if ($admins) {
            $r = email_tpl_render('ti_product_started_admin', $vars + [
                'invoice_own' => h($p['invoice_own'] ?: '—'), 'invoice_vendor' => h($p['invoice_vendor'] ?: '—'),
                'recipients'  => h($sent ? implode(', ', $sent) : 'nikt (' . ($skip ? implode('; ', array_unique($skip)) : 'brak adresu') . ')'),
                'by'          => h($byName !== '' ? $byName : '—'),
            ]);
            if (!empty($r['enabled'])) foreach ($admins as $a) {
                try { if (mail_queue_add($a, '', $r['subject'], $r['html'], '', 'ti_product_admin', $id) > 0) $adminN++; } catch (\Throwable $e) {}
            }
        }
        return ($sent ? 'e-mail do: ' . implode(', ', $sent) : 'kursant bez e-maila (' . ($skip ? implode('; ', array_unique($skip)) : 'brak adresu') . ')')
             . '; kopia do administratora: ' . ($adminN ? $adminN . ' adr.' : 'brak adresu admina');
    }

    private static function audit(string $action, int $id, int $clientId, string $name): void
    {
        try {
            require_once dirname(__DIR__, 2) . '/audit_logs/logic/audit_logs.php';
            audit_log($action, ['product_id' => $id, 'client_id' => $clientId, 'name' => $name], null);
        } catch (\Throwable $e) {}
    }
}
