<?php
/**
 * modules/account_security/logic/AccountSecurityReportController.php — raport bezpieczeństwa kont (tylko administrator).
 *
 * GET /raport      — strona w chrome Systemu Tożsamości (wejście z menu: /tozsamosc/raport.php)
 * GET /raport.csv  — eksport tych samych wierszy (filtry z adresu), zapis w audit_logs (tozsamosc.security_report_export)
 * Dane osobowe wszystkich kont → wymagana ważna sesja kodu IKA (jak lista użytkowników).
 */
require_once dirname(__DIR__) . '/lib/accountSecurityReport.php';

final class AccountSecurityReportController
{
    public const ENTRY = '/tozsamosc/raport.php';
    public const DISPLAY_MAX = 500;

    /** Filtry z adresu (oczyszczone). */
    public static function filters(): array
    {
        $issue = (string)($_GET['issue'] ?? '');
        return [
            'q'        => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100),
            'role'     => preg_match('/^[a-z0-9_]{1,40}$/', (string)($_GET['role'] ?? '')) ? (string)$_GET['role'] : '',
            'issue'    => isset(AccountSecurityReport::ISSUES[$issue]) ? $issue : '',
            'inactive' => !empty($_GET['inactive']),
        ];
    }

    /** Adres strony/CSV z filtrami. */
    public static function url(array $f, bool $csv = false, array $override = []): string
    {
        $f = array_merge($f, $override);
        $q = array_filter(['q' => $f['q'] ?? '', 'role' => $f['role'] ?? '', 'issue' => $f['issue'] ?? '', 'inactive' => !empty($f['inactive']) ? '1' : ''],
                          fn($v) => $v !== '');
        if ($csv) $q = ['_route' => '/raport.csv'] + $q;
        return APP_URL . self::ENTRY . ($q ? '?' . http_build_query($q) : '');
    }

    private static function gate(string $returnUrl): void
    {
        if (!is_admin()) { http_response_code(403); exit('Raport bezpieczeństwa kont jest dostępny tylko dla administratora.'); }
        ika_require($returnUrl, 3600);
    }

    public function page(): ?string
    {
        $f = self::filters();
        self::gate(self::url($f));
        $all  = AccountSecurityReport::rows(['q' => $f['q'], 'role' => $f['role'], 'inactive' => $f['inactive']]);
        $sum  = AccountSecurityReport::summary($all);
        $rows = $f['issue'] !== '' ? array_values(array_filter($all, fn($r) => in_array($f['issue'], $r['issues'], true))) : $all;
        $roles = [];
        try { $roles = db_all("SELECT name, display_name FROM roles ORDER BY display_name"); } catch (\Throwable $e) {}
        include dirname(__DIR__) . '/view/reportPage.php';
        return null;
    }

    public function csv(): ?string
    {
        $f = self::filters();
        self::gate(self::url($f));
        $rows = AccountSecurityReport::rows($f);
        if (function_exists('audit_log') || is_file(dirname(__DIR__, 2) . '/audit_logs/logic/audit_logs.php')) {
            try {
                require_once dirname(__DIR__, 2) . '/audit_logs/logic/audit_logs.php';
                audit_log('tozsamosc.security_report_export', ['rows' => count($rows), 'filters' => $f]);
            } catch (\Throwable $e) {}
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="raport-bezpieczenstwa-kont-' . date('Y-m-d') . '.csv"');
        header('Cache-Control: no-store');
        echo AccountSecurityReport::csv($rows);
        return null;
    }
}
