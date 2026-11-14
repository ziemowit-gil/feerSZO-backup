<?php
declare(strict_types=1);

/**
 * modules/contractIt/logic/ContractIt.php — „Konta i grupy IT” w formularzach umów (polecenie 2026-11-14: „zmień okno edycji umowy
 * i dodawania pod nowe IT”). Zastępuje dawny panel Microsoft 365 (wyłączenie 20.11.2026) jednym panelem dla wszystkich typów umów:
 *   • logowanie jednokrotne (Logto) i konto w chmurze plików (Nextcloud): stan po e-mailu z umowy, SsoAccounts::get();
 *   • poczta na nowym serwerze: skrzynka umowy (DhostingContractMail::boxForContract) i przenoszenie z Microsoft 365 (DhostingCopy);
 *   • grupy w chmurze z jednostki organizacyjnej (szo-jednostka-{id}) i działania (szo-dzialanie-{id}, tylko wolontariat) z podpowiedzią,
 *     jak w oknie „Problemy z przypisaniami” (CloudAssignIssues): jednostka z konta SZO osoby albo najczęstsza w aktywnych umowach typu,
 *     działanie najczęstsze w aktywnych umowach tej samej jednostki albo jedyne trwające.
 * Panel tylko pokazuje stan i pomaga ustawić pola formularza; zapis umowy się nie zmienia. Widok: view/panel.php.
 * Pola Microsoft 365 (m365_*) zostają w formularzach w zwiniętej sekcji „Microsoft 365 (wygaszane)”, bo są potrzebne do migracji.
 */
final class ContractIt
{
    /** Typy umów z panelem; karta konta w Zarządzaniu dostępem, karta hasła i skrzynki działają dla trzech pierwszych. */
    public const TYPES = ['wolontariat', 'zlecenie', 'dzielo', 'praca'];
    private const ACCOUNT_TYPES = ['wolontariat', 'zlecenie', 'dzielo'];
    private const TYPE_LABELS = ['wolontariat' => 'porozumieniach wolontariackich', 'zlecenie' => 'umowach zlecenia', 'dzielo' => 'umowach o dzieło', 'praca' => 'umowach o pracę'];

    private static function root(): string { return dirname(__DIR__, 3); }

    private static function app(): string { return rtrim((string)(defined('APP_URL') ? APP_URL : ''), '/'); }

    private static function loadSso(): bool
    {
        $f = self::root() . '/modules/sso_logto/logic/SsoAccounts.php';
        if (!is_file($f)) return false;
        try { require_once $f; return class_exists('SsoAccounts'); } catch (\Throwable $e) { return false; }
    }

    private static function loadMail(): bool
    {
        $f = self::root() . '/modules/dhosting_migrator/logic/DhostingContractMail.php';
        $c = self::root() . '/modules/dhosting_migrator/logic/DhostingCopy.php';
        if (!is_file($f) || !is_file($c)) return false;
        try { require_once $f; require_once $c; return class_exists('DhostingContractMail') && class_exists('DhostingCopy'); }
        catch (\Throwable $e) { return false; }
    }

    /** Poprawny adres e-mail (małe litery) albo ''. Adresy techniczne „x@local” nie dostaną konta. */
    public static function email(string $raw): string
    {
        $e = strtolower(trim($raw));
        return ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) && !str_ends_with($e, '@local')) ? $e : '';
    }

    /** Dzień wyłączenia Microsoft 365 do napisów („20.11.2026”): ustawienie sso_ms_off_date albo data z decyzji organizacji. */
    public static function msOffLabel(): string
    {
        if (self::loadSso() && class_exists('SsoConfig')) {
            try { $t = SsoConfig::msOffDate(); if ($t) return date('d.m.Y', $t); } catch (\Throwable $e) {}
        }
        return '20.11.2026';
    }

    /**
     * Konto osoby po e-mailu: ['email','logto','logtoUser','cloud','cloudUid','logtoReady','cloudReady','cloudLabel','pwdAt'].
     * Brak modułu albo błąd bazy = same fałsze (panel pokaże „brak konta”).
     */
    public static function account(string $email): array
    {
        $out = ['email' => self::email($email), 'logto' => false, 'logtoUser' => '', 'cloud' => false, 'cloudUid' => '',
                'logtoReady' => false, 'cloudReady' => false, 'cloudLabel' => 'Nextcloud', 'pwdAt' => ''];
        if (!self::loadSso()) return $out;
        try {
            $out['logtoReady'] = SsoConfig::logtoConfigured();
            $out['cloudReady'] = SsoConfig::cloudConfigured();
            $out['cloudLabel'] = SsoConfig::cloudLabel() ?: 'Nextcloud';
            $acc = $out['email'] !== '' ? SsoAccounts::get($out['email']) : null;
        } catch (\Throwable $e) { $acc = null; }
        if ($acc) {
            $out['logto']     = !empty($acc['logto_id']);
            $out['logtoUser'] = (string)($acc['logto_username'] ?? '');
            $out['cloud']     = !empty($acc['cloud_uid']);
            $out['cloudUid']  = (string)($acc['cloud_uid'] ?? '');
            $out['pwdAt']     = (string)($acc['logto_pwd_at'] ?? '');
        }
        return $out;
    }

    /**
     * Poczta umowy: ['on' => zakładanie skrzynek przy umowach włączone, 'domain', 'graceDays', 'box' => null|[mailbox,status,suspended_at,source],
     * 'm365Box' => skrzynka z loginu Microsoft w przenoszonej domenie albo '', 'copy' => null|[label,percent,status,finished]].
     * null = typ umowy bez obsługi poczty (praca) albo brak modułu.
     */
    public static function mail(string $type, int $id, array $row): ?array
    {
        if (!in_array($type, self::ACCOUNT_TYPES, true) || !self::loadMail()) return null;
        $out = ['on' => false, 'domain' => '', 'graceDays' => DhostingContractMail::GRACE_DAYS, 'box' => null, 'm365Box' => '', 'copy' => null];
        try {
            $out['on']     = DhostingContractMail::enabled();
            $out['domain'] = DhostingContractMail::domain();
            if ($id > 0) $out['box'] = DhostingContractMail::boxForContract($type, $id);
            $m = trim((string)($row['m365_login'] ?? ''));
            $out['m365Box'] = $m !== '' ? (string)(DhostingConfig::mailboxFor($m) ?? '') : '';
            if ($out['m365Box'] !== '' && ($job = DhostingCopy::mainFor($out['m365Box']))) {
                $out['copy'] = ['status' => (string)$job['status'], 'label' => DhostingCopy::LABELS[$job['status']] ?? (string)$job['status'],
                                'percent' => DhostingCopy::percent($job), 'finished' => (string)($job['finished_at'] ?? '')];
            }
        } catch (\Throwable $e) {}
        return $out;
    }

    /** Aktywne jednostki [id => nazwa]. */
    public static function units(): array
    {
        try { return array_column(db_all("SELECT id, name FROM org_units WHERE COALESCE(status, 'active') = 'active' ORDER BY sort_order, name"), 'name', 'id'); }
        catch (\Throwable $e) { return []; }
    }

    /** Trwające działania [id => nazwa]. */
    public static function actions(): array
    {
        try { return array_column(db_all("SELECT id, nazwa FROM actions WHERE status NOT IN ('anulowane', 'zakończone') ORDER BY nazwa"), 'nazwa', 'id'); }
        catch (\Throwable $e) { return []; }
    }

    /** Warunek aktywnej umowy jak przy synchronizacji grup (CloudTeamsSync::activeContractSql), parametr: dzisiejsza data. */
    private static function activeSql(): string
    {
        $f = self::root() . '/modules/sso_logto/logic/CloudTeamsSync.php';
        try { if (is_file($f)) { require_once $f; if (class_exists('CloudTeamsSync')) return CloudTeamsSync::activeContractSql(); } } catch (\Throwable $e) {}
        $in = function_exists('contractStatusSqlList') ? contractStatusSqlList('active') : "'podpisana','w realizacji','aneks'";
        return "status IN ($in) AND (COALESCE(data_zakonczenia, '') = '' OR data_zakonczenia >= ?)";
    }

    /** Propozycja jednostki: ['id','name','why'] albo null. Najpierw jednostka konta SZO osoby (po e-mailu), potem najczęstsza w aktywnych umowach typu. */
    public static function suggestUnit(string $type, string $email, ?array $units = null): ?array
    {
        $units ??= self::units();
        if (!$units || !in_array($type, self::TYPES, true)) return null;
        $email = self::email($email);
        if ($email !== '') {
            try {
                $u = db_one("SELECT org_unit_id FROM users WHERE LOWER(email) = ? AND COALESCE(org_unit_id, 0) > 0", [$email]);
                if ($u && isset($units[(int)$u['org_unit_id']])) {
                    return ['id' => (int)$u['org_unit_id'], 'name' => (string)$units[(int)$u['org_unit_id']], 'why' => 'taka jest na koncie SZO tej osoby'];
                }
            } catch (\Throwable $e) {}
        }
        try {
            $c = db_one("SELECT org_unit_id, COUNT(*) n FROM umowy_{$type} WHERE COALESCE(org_unit_id, 0) > 0 AND " . self::activeSql()
                      . " GROUP BY org_unit_id ORDER BY n DESC LIMIT 1", [date('Y-m-d')]);
        } catch (\Throwable $e) { $c = null; }
        if ($c && isset($units[(int)$c['org_unit_id']])) {
            return ['id' => (int)$c['org_unit_id'], 'name' => (string)$units[(int)$c['org_unit_id']],
                    'why' => 'najczęstsza w aktywnych ' . (self::TYPE_LABELS[$type] ?? 'umowach')];
        }
        return null;
    }

    /** Propozycja działania dla wolontariatu: ['id','name','why'] albo null. Najczęstsze w aktywnych umowach tej jednostki, inaczej jedyne trwające. */
    public static function suggestAction(int $unitId, ?array $actions = null): ?array
    {
        $actions ??= self::actions();
        if (!$actions) return null;
        try {
            $r = db_one("SELECT action_id, COUNT(*) n FROM umowy_wolontariat WHERE COALESCE(action_id, 0) > 0 AND COALESCE(org_unit_id, 0) = ? AND "
                      . self::activeSql() . " GROUP BY action_id ORDER BY n DESC LIMIT 1", [$unitId, date('Y-m-d')]);
        } catch (\Throwable $e) { $r = null; }
        if ($r && isset($actions[(int)$r['action_id']])) {
            return ['id' => (int)$r['action_id'], 'name' => (string)$actions[(int)$r['action_id']],
                    'why' => $unitId > 0 ? 'najczęstsze w umowach tej jednostki' : 'najczęstsze w umowach bez jednostki'];
        }
        if (count($actions) === 1) {
            $id = (int)array_key_first($actions);
            return ['id' => $id, 'name' => (string)$actions[$id], 'why' => 'jedyne trwające działanie'];
        }
        return null;
    }

    /**
     * Wszystko dla panelu. $id = 0 przy dodawaniu. Zwraca: type, id, add, account, mail, units, actions (tylko wolontariat), unitId, actionId,
     * unitSuggest, actionSuggest, links (access, card, mailbox, migration, groups — '' gdy niedostępny), msOff.
     */
    public static function state(string $type, int $id, array $row): array
    {
        $units   = self::units();
        $actions = $type === 'wolontariat' ? self::actions() : [];
        $unitId  = (int)($row['org_unit_id'] ?? 0);
        $actId   = (int)($row['action_id'] ?? 0);
        $acc     = self::account((string)($row['email'] ?? ''));
        $app     = self::app();
        $isAdmin = function_exists('is_admin') && is_admin();
        $hasCard = $id > 0 && in_array($type, self::ACCOUNT_TYPES, true);
        $canGroups = false;
        try { $canGroups = function_exists('can_write') && can_write('grupy_chmury'); } catch (\Throwable $e) {}
        return [
            'type'    => $type,
            'id'      => $id,
            'add'     => $id <= 0,
            'admin'   => $isAdmin,
            'account' => $acc,
            'mail'    => self::mail($type, $id, $row),
            'units'   => $units,
            'actions' => $actions,
            'unitId'  => isset($units[$unitId]) ? $unitId : 0,
            'unitOld' => $unitId > 0 && !isset($units[$unitId]),
            'actionId' => isset($actions[$actId]) ? $actId : 0,
            'actionOld' => $type === 'wolontariat' && $actId > 0 && !isset($actions[$actId]),
            'unitSuggest'   => $unitId > 0 ? null : self::suggestUnit($type, $acc['email'], $units),
            'actionSuggest' => ($type !== 'wolontariat' || $actId > 0) ? null : self::suggestAction($unitId, $actions),
            'links' => [
                'access'    => $hasCard ? $app . '/tozsamosc/access.php?type=' . rawurlencode($type) . '&id=' . $id . '#sso' : '',
                'card'      => $hasCard ? $app . '/contracts/' . $type . '/view.php?id=' . $id . '&tab=m365#sso' : '',
                'mailbox'   => $hasCard ? $app . '/contracts/' . $type . '/view.php?id=' . $id . '&tab=m365#skrzynka' : '',
                'migration' => $isAdmin ? $app . '/migracjaadmin' : '',
                'groups'    => $canGroups ? $app . '/ssoadmin/grupy' : '',
                'state'     => $app . '/modules/contractIt/router.php?_route=/stan',
            ],
            'msOff' => self::msOffLabel(),
        ];
    }

    /** HTML panelu „Konta i grupy IT” (view/panel.php); opcje opisane w widoku. Błąd przy zbieraniu stanu nie psuje formularza umowy. */
    public static function render(string $type, int $id, array $row, array $opt = []): string
    {
        try { $cit = self::state($type, $id, $row); }
        catch (\Throwable $e) { error_log('[contractIt] ' . $e->getMessage()); return ''; }
        $citOpt = $opt;
        ob_start();
        include dirname(__DIR__) . '/view/panel.php';
        return (string)ob_get_clean();
    }

    /** Style zwiniętej sekcji Microsoft 365 — raz na stronę, z pierwszym legacyOpen() (formularze i widoki umów). */
    private const LEGACY_CSS = '<style>'
        . '.cit-legacy{border:1px dashed #94a3b8;border-radius:6px;background:#f8fafc;margin:.75rem 0;color:#1f2937}'
        . '.cit-legacy>summary{cursor:pointer;padding:.75rem .8rem;font-weight:600;font-size:.85rem;color:#334155;list-style-position:inside}'
        . '.cit-legacy>summary .cit-legacy-sub{font-weight:400;color:#4b5563;font-size:.78rem;margin-left:.35rem}'
        . '.cit-legacy>summary:focus-visible{outline:3px solid #1d4ed8;outline-offset:2px}'
        . '.cit-legacy[open]>summary{border-bottom:1px dashed #94a3b8}'
        . '.cit-legacy>.cit-legacy-body{padding:.75rem .8rem}'
        . '</style>';
    private static bool $legacyCssDone = false;

    /** Początek i koniec zwiniętej sekcji z polami Microsoft 365 (pola i ich nazwy bez zmian; dane potrzebne do migracji). */
    public static function legacyOpen(string $note = '', string $id = 'citLegacyM365'): string
    {
        $css = self::$legacyCssDone ? '' : self::LEGACY_CSS;
        self::$legacyCssDone = true;
        return $css . '<details class="cit-legacy" id="' . h($id) . '"><summary>Microsoft 365 (wygaszane ' . h(self::msOffLabel()) . ')'
             . '<span class="cit-legacy-sub">' . h($note !== '' ? $note : 'stare dane konta, potrzebne do przeniesienia poczty i plików') . '</span></summary>'
             . '<div class="cit-legacy-body">';
    }

    public static function legacyClose(): string
    {
        return '</div></details>';
    }
}
