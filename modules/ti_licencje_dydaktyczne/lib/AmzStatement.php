<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_dydaktyczne/lib/AmzStatement.php — AMZ: zestawienie licencji i usług na koncie kursanta.
 *
 * 1. Dane identyfikacyjne: imię i nazwisko, ID uczestnika, login / UPN / e-mail, organizacja (płatnik firmowy z historii płatników).
 * 2. Wykaz: licencje z katalogu oprogramowania TI, licencje i dostępy AM5 / AM7, Microsoft 365, kursy (dostęp do zajęć),
 *    egzaminy (pula podejść), usługi kursanta — domeny, hosting, inne (k30_ti_client_products).
 *    Dla każdej pozycji: nazwa, typ / wariant (subskrypcyjna, bezterminowa, jednorazowa / pula), status (Aktywna,
 *    Oczekująca, Wygasająca — ≤ 30 dni, Wygasła / Wykorzystana, Zawieszona, Cofnięta), okres (od – do).
 * 3. Zużycie i limity: zajęcia odbyte / zaplanowane, godziny, obecność, podejścia do egzaminu, postęp %.
 * 4. Akcje: przejście do kursu (panel kursanta), przedłużenie (AM24 dla biura; dla kursanta — kontakt / portal płatności),
 *    certyfikaty i zaświadczenia z teczki kursanta, klucz licencyjny (w zestawieniu ZAWSZE zamaskowany — pełny tylko
 *    w panelu kursanta po oświadczeniu).
 * Numer zestawienia: AMZ/{ID uczestnika}/{RRRRMMDD}. Tylko odczyt.
 */
require_once __DIR__ . '/LicenseResources.php';
require_once __DIR__ . '/LicenseRepository.php';

final class AmzStatement
{
    public const SOON_DAYS = 30;

    private static function status(string $to, bool $active = true): string
    {
        if (!$active) return 'Wygasła';
        if ($to === '') return 'Aktywna';
        if ($to < ldToday()) return 'Wygasła';
        return $to <= date('Y-m-d', strtotime('+' . self::SOON_DAYS . ' days')) ? 'Wygasająca' : 'Aktywna';
    }

    public static function statusColor(string $s): string
    {
        return match ($s) {
            'Aktywna' => '#008a00', 'Wygasająca' => '#f0a30a', 'Oczekująca' => '#1ba1e2', 'Zawieszona' => '#fa6800',
            'Wygasła', 'Wykorzystana', 'Zakończona' => '#647687', 'Cofnięta' => '#a20025', default => '#647687',
        };
    }

    /** Zestawienie dla uczestnika (k30_clients.id) albo null. */
    public static function build(int $clientId): ?array
    {
        ldMigrate();
        $acc = LicenseResources::account('client', $clientId);
        if (!$acc) return null;
        $items = [];
        $panel = function (string $path): string {
            if (!function_exists('tiPanelUrl')) require_once dirname(__DIR__, 2) . '/ti_panel_urls/lib/tiPanelUrl.php';
            return tiPanelUrl('kursant', $path);
        };

        // ── Licencje z katalogu oprogramowania TI ──
        try {
            foreach (db_all("SELECT cl.*, l.name AS license_name, l.vendor, l.vendor_url FROM k30_ti_client_licenses cl JOIN k30_ti_licenses l ON l.id = cl.license_id WHERE cl.client_id = ? ORDER BY l.name", [$clientId]) as $r) {
                $to = (string)($r['expires_at'] ?? '');
                $key = trim((string)$r['access_key']);
                $items[] = ['group' => 'Oprogramowanie', 'key' => 'ti:' . $r['id'], 'name' => (string)$r['license_name'], 'vendor' => (string)$r['vendor'],
                    'variant' => $to === '' ? 'Bezterminowa' : 'Subskrypcyjna (czasowa)', 'status' => $r['status'] === 'revoked' ? 'Cofnięta' : self::status($to),
                    'from' => substr((string)(($r['issued_at'] ?? '') ?: $r['assigned_at']), 0, 10), 'to' => $to, 'renewal' => $to === '' ? '' : 'odnowienie przez biuro (AM24)',
                    'usage' => trim((string)$r['login']) !== '' ? 'konto: ' . $r['login'] : '', 'progress' => null,
                    'license_key' => $key !== '' ? (class_exists('TiLicencjeManager') ? TiLicencjeManager::maskKey($key) : str_repeat('•', 6) . substr($key, -4)) : '',
                    'actions' => array_filter(['Przejdź do usługi' => (string)$r['vendor_url']])];
            }
        } catch (\Throwable $e) {}

        // ── Licencje i dostępy AM5 / AM7 ──
        foreach (db_all("SELECT a.*, l.title, l.licensor_name, l.form_kind, l.period_from, l.period_to, l.suspended, l.request_status FROM license_assignments a JOIN licenses l ON l.id = a.license_id
                          WHERE a.holder_kind = 'client' AND a.holder_id = ? ORDER BY a.id", [$clientId]) as $r) {
            $am7 = ($r['form_kind'] ?? '') === 'AM7';
            $st = match (true) {
                $r['status'] === 'revoked' => 'Cofnięta',
                !empty($r['suspended']) => 'Zawieszona',
                in_array($r['status'], ['invited', 'rejected'], true) => 'Oczekująca',
                $r['status'] === 'submitted' => 'Oczekująca',
                default => self::status((string)$r['period_to']),
            };
            $items[] = ['group' => $am7 ? 'Oprogramowanie (AM7)' : 'Materiały dydaktyczne (AM5)', 'key' => 'am:' . $r['id'], 'name' => (string)$r['title'],
                'vendor' => $am7 ? ldOrgName() : (string)$r['licensor_name'], 'variant' => 'Subskrypcyjna (czasowa)', 'status' => $st,
                'from' => (string)$r['period_from'], 'to' => (string)$r['period_to'], 'renewal' => 'przedłużenie przez biuro (AM24)',
                'usage' => match ($r['status']) { 'invited' => 'wymaga złożenia oświadczeń', 'rejected' => 'oświadczenia do ponownego złożenia', 'submitted' => 'oświadczenia w weryfikacji', default => '' },
                'progress' => null, 'license_key' => '', 'actions' => []];
        }

        // ── Microsoft 365 ──
        try {
            $m = db_one("SELECT m365_license_sku, m365_login, m365_provisioned_at, m365_expires_at, m365_disabled_at FROM k30_clients WHERE id = ?", [$clientId]);
            if ($m && trim((string)$m['m365_license_sku']) !== '') {
                $to = (string)($m['m365_expires_at'] ?? '');
                $items[] = ['group' => 'Microsoft 365', 'key' => 'm365:' . $clientId, 'name' => 'Microsoft 365 — ' . $m['m365_license_sku'], 'vendor' => 'Microsoft',
                    'variant' => $to === '' ? 'Bezterminowa (do odwołania)' : 'Subskrypcyjna (czasowa)', 'status' => !empty($m['m365_disabled_at']) ? 'Cofnięta' : self::status($to),
                    'from' => substr((string)$m['m365_provisioned_at'], 0, 10), 'to' => $to, 'renewal' => '', 'usage' => 'konto: ' . $m['m365_login'], 'progress' => null,
                    'license_key' => '', 'actions' => ['Zaloguj się' => 'https://www.office.com']];
            }
            $ms = db_one("SELECT ms_upn, ms_created_at FROM k30_ti_student_accounts WHERE client_id = ? AND ms_upn <> '' ORDER BY id LIMIT 1", [$clientId]);
            if ($ms) $items[] = ['group' => 'Microsoft 365', 'key' => 'msedu:' . $clientId, 'name' => 'Konto Microsoft 365 (tenant ośrodka)', 'vendor' => 'Microsoft',
                'variant' => 'Bezterminowa (na czas udziału w zajęciach)', 'status' => 'Aktywna', 'from' => substr((string)$ms['ms_created_at'], 0, 10), 'to' => '',
                'renewal' => '', 'usage' => 'konto: ' . $ms['ms_upn'], 'progress' => null, 'license_key' => '', 'actions' => ['Zaloguj się' => 'https://www.office.com']];
        } catch (\Throwable $e) {}

        // ── Kursy: dostęp do zajęć + zużycie ──
        try {
            foreach (db_all("SELECT e.*, c.name, c.display_name, c.group_code, c.is_active, c.closed_at FROM k30_ti_enrollments e JOIN k30_ti_courses c ON c.id = e.course_id WHERE e.client_id = ? ORDER BY e.id", [$clientId]) as $e) {
                $cid = (int)$e['course_id'];
                $s = db_one("SELECT SUM(CASE WHEN status NOT IN ('cancelled','canceled') THEN 1 ELSE 0 END) AS planned,
                                    SUM(CASE WHEN status IN ('held','individual_change','remote_material') THEN 1 ELSE 0 END) AS held,
                                    SUM(CASE WHEN status NOT IN ('cancelled','canceled') THEN duration_min ELSE 0 END) AS planned_min,
                                    MIN(lesson_date) AS first_d, MAX(lesson_date) AS last_d FROM k30_ti_sessions WHERE course_id = ?", [$cid]) ?? [];
                $att = db_one("SELECT COUNT(*) AS n, COALESCE(SUM(s.duration_min), 0) AS mins FROM k30_ti_attendance a JOIN k30_ti_sessions s ON s.id = a.session_id
                                WHERE a.client_id = ? AND a.attended = 1 AND s.course_id = ?", [$clientId, $cid]) ?? [];
                $planned = (int)($s['planned'] ?? 0); $held = (int)($s['held'] ?? 0);
                $from = (string)($e['start_date'] ?: ($s['first_d'] ?? '')); $to = (string)($e['end_date'] ?: ($s['last_d'] ?? ''));
                $done = !empty($e['closed_at']) || (int)$e['is_active'] === 0 || $e['status'] !== 'active';
                $label = (trim((string)$e['display_name']) !== '' ? $e['display_name'] : $e['name']) . (trim((string)$e['group_code']) !== '' ? ' (' . $e['group_code'] . ')' : '');
                $items[] = ['group' => 'Kursy i zajęcia', 'key' => 'course:' . $cid, 'name' => (string)$label, 'vendor' => ldOrgName(),
                    'variant' => 'Dostęp do kursu (pula zajęć)', 'status' => $done ? 'Zakończona' : ($to !== '' && $to < ldToday() ? 'Wygasła' : 'Aktywna'),
                    'from' => $from, 'to' => $to, 'renewal' => '',
                    'usage' => 'zajęcia odbyte ' . $held . ' z ' . $planned . '; obecność ' . (int)($att['n'] ?? 0) . ' (' . round(((int)($att['mins'] ?? 0)) / 60, 1) . ' h z ' . round(((int)($s['planned_min'] ?? 0)) / 60, 1) . ' h)',
                    'progress' => $planned > 0 ? (int)round(100 * $held / $planned) : null, 'license_key' => '',
                    'actions' => ['Przejdź do kursu' => $panel('index.php?tab=lekcje')]];
            }
        } catch (\Throwable $e) {}

        // ── Egzaminy: pula podejść ──
        try {
            foreach (db_all("SELECT x.id, x.title, x.max_attempts, x.pass_pct, x.open_at, x.close_at, (SELECT COUNT(*) FROM k30_ti_exam_attempts t WHERE t.exam_id = x.id AND t.client_id = ?) AS used,
                                    (SELECT MAX(CASE WHEN t.max_score > 0 THEN 100.0 * t.score / t.max_score END) FROM k30_ti_exam_attempts t WHERE t.exam_id = x.id AND t.client_id = ? AND t.status IN ('graded','submitted')) AS best
                               FROM k30_ti_exams x WHERE x.is_active = 1 AND x.course_id IN (SELECT course_id FROM k30_ti_enrollments WHERE client_id = ?) ORDER BY x.open_at", [$clientId, $clientId, $clientId]) as $x) {
                $max = (int)$x['max_attempts']; $used = (int)$x['used'];
                $passed = $x['best'] !== null && (float)$x['best'] >= (float)$x['pass_pct'];
                $to = substr((string)$x['close_at'], 0, 10);
                $items[] = ['group' => 'Egzaminy', 'key' => 'exam:' . $x['id'], 'name' => (string)$x['title'], 'vendor' => ldOrgName(), 'variant' => 'Pula podejść do egzaminu',
                    'status' => $passed ? 'Wykorzystana' : ($max > 0 && $used >= $max ? 'Wykorzystana' : ($to !== '' && $to < ldToday() ? 'Wygasła' : 'Aktywna')),
                    'from' => substr((string)$x['open_at'], 0, 10), 'to' => $to, 'renewal' => '',
                    'usage' => 'podejścia ' . $used . ($max > 0 ? ' z ' . $max : ' (bez limitu)') . ($x['best'] !== null ? '; najlepszy wynik ' . round((float)$x['best']) . '%' . ($passed ? ' — zaliczony' : '') : ''),
                    'progress' => $max > 0 ? (int)round(100 * min($used, $max) / $max) : null, 'license_key' => '', 'actions' => ['Przejdź do egzaminu' => $panel('index.php?tab=testy')]];
            }
        } catch (\Throwable $e) {}

        // ── Usługi kursanta: domeny, hosting, inne ──
        try {
            require_once dirname(__DIR__, 2) . '/ti_products/logic/tiProducts.php';
            foreach (TiProducts::forClient($clientId) as $p) {
                $to = (string)($p['expires_at'] ?? '');
                $st = match ($p['status']) { 'suspended' => 'Zawieszona', 'archived' => 'Wygasła', default => self::status($to) };
                $items[] = ['group' => 'Usługi: domeny, hosting, inne', 'key' => 'prod:' . $p['id'], 'name' => (TiProducts::TYPES[$p['product_type']]['label'] ?? 'Usługa') . ': ' . $p['name'],
                    'vendor' => TiProducts::providerName($p), 'variant' => $to === '' ? 'Bezterminowa' : 'Subskrypcyjna (odnawialna)', 'status' => $st,
                    'from' => substr((string)$p['created_at'], 0, 10), 'to' => $to, 'renewal' => in_array($p['product_type'], ['domain', 'hosting'], true) ? 'odnowienie u dostawcy (przypomnienie przed terminem)' : '',
                    'usage' => '', 'progress' => null, 'license_key' => '', 'actions' => []];
            }
        } catch (\Throwable $e) {}

        // ── Certyfikaty i zaświadczenia z teczki kursanta ──
        $certs = [];
        try {
            if ($acc['student_account_id']) $certs = db_all("SELECT id, title, created_at FROM k30_ti_sfile WHERE account_id = ? AND deleted_at IS NULL AND category IN ('certyfikat', 'zaswiadczenie') AND status = 'approved' ORDER BY created_at DESC", [$acc['student_account_id']]);
        } catch (\Throwable $e) {}

        $org = '';
        foreach ($items as $it) { $f = LicenseResources::funding($it['key']); if ($f && in_array($f['source'], ['Partner', 'Grant'], true)) { $org = $f['payer']; break; } }
        $counts = [];
        foreach ($items as $it) $counts[$it['status']] = ($counts[$it['status']] ?? 0) + 1;
        return ['number' => 'AMZ/' . $clientId . '/' . date('Ymd'), 'generated_at' => ldNow(), 'person' => $acc + ['organization' => $org],
                'items' => $items, 'certs' => $certs, 'counts' => $counts, 'portal' => $panel('index.php?tab=licencje')];
    }
}
