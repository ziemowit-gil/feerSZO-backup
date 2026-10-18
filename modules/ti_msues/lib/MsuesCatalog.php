<?php
declare(strict_types=1);

/**
 * modules/ti_msues/lib/MsuesCatalog.php — publiczny katalog szkoleń (MSUES IV: katalog z efektami uczenia się, wymaganiami,
 * programem i czasem trwania). Wpis = grupa TI z profilem usługi (msues_service_profiles) i zaznaczonym „w katalogu”.
 *
 * Strona: karty30/ti/dydaktyk/katalog.php (bez logowania), krótko /katalog/ i /katalog/<id> na ti.* / szo.* / kurs*.* (.htaccess).
 * Wygląd i przełącznik Metro / klasyczny wspólne z publicznym planem zajęć (modules/ti_plan_public/ui).
 * Prywatność jak w planie: prowadzący tylko kodem (tiPlanPublicStaffCode), nazwa grupy przez tiPlanPublicGroupLabel
 * (anonimizacja imion i nazwisk), bez uczestników, linków do zajęć i kontaktów osób. Link do planu tylko dla grup z katalogu planu.
 */
require_once __DIR__ . '/msuesSchema.php';
require_once __DIR__ . '/MsuesRequirements.php';
require_once dirname(__DIR__, 2) . '/ti_plan_public/logic/planPublicManager.php';
require_once dirname(__DIR__, 2) . '/ti_plan_public/logic/planPublicPortal.php';

final class MsuesCatalog
{
    /** Adres katalogu (lub wpisu): krótki /katalog/… na hostach TI (ti.*, szo.*, kurs*.*), inaczej pełna ścieżka skryptu. */
    public static function url(?int $id = null): string
    {
        if (preg_match('/^(ti|szo|kurs)[^.]*\./', strtolower((string)($_SERVER['HTTP_HOST'] ?? '')))) return '/katalog/' . ($id ? $id : '');
        return rtrim((string)(defined('APP_URL') ? APP_URL : ''), '/') . '/karty30/ti/dydaktyk/katalog.php' . ($id ? '?u=' . $id : '');
    }

    public static function count(): int
    {
        msuesMigrate();
        try { return (int)(db_one("SELECT COUNT(*) AS n FROM msues_service_profiles p JOIN k30_ti_courses c ON c.id = p.course_id
                                     WHERE p.in_catalog = 1 AND c.status NOT IN ('archived','cancelled')")['n'] ?? 0); }
        catch (\Throwable $e) { return 0; }
    }

    /** Wpisy katalogu (skrót do listy). */
    public static function entries(): array
    {
        msuesMigrate();
        try {
            $rows = db_all("SELECT c.id FROM msues_service_profiles p JOIN k30_ti_courses c ON c.id = p.course_id
                             WHERE p.in_catalog = 1 AND c.status NOT IN ('archived','cancelled')
                             ORDER BY p.catalog_order, c.name COLLATE NOCASE");
        } catch (\Throwable $e) { return []; }
        $out = [];
        foreach ($rows as $r) if ($e = self::entry((int)$r['id'])) $out[] = $e;
        return $out;
    }

    /** Pełny wpis albo null (grupa spoza katalogu / zamknięta). */
    public static function entry(int $courseId): ?array
    {
        $p = MsuesRequirements::profile($courseId);
        if (empty($p['in_catalog'])) return null;
        $c = db_one("SELECT c.*, u.name AS instr_name, st.name AS subject_name FROM k30_ti_courses c
                       LEFT JOIN users u ON u.id = c.instructor_id LEFT JOIN k30_ti_subject_types st ON st.id = c.subject_type_id
                      WHERE c.id = ? AND c.status NOT IN ('archived','cancelled')", [$courseId]);
        if (!$c) return null;
        $title = trim((string)$p['catalog_title']) !== '' ? (string)$p['catalog_title']
               : tiPlanPublicGroupLabel($courseId, (string)$c['name'], (string)($c['display_name'] ?? ''), (string)($c['subject_name'] ?? ''));
        // Program: plan nauczania (działy → tematy), czas z szacunku pozycji
        $prog = []; $estMin = 0;
        foreach (db_all("SELECT section, title, est_minutes FROM k30_ti_curriculum WHERE course_id = ? AND is_active = 1 ORDER BY position, id", [$courseId]) as $it) {
            $prog[trim((string)$it['section'])][] = (string)$it['title'];
            $estMin += (int)$it['est_minutes'];
        }
        // Czas trwania i terminy: lekcje oficjalne (bez rezerwacji, roboczych i odwołanych)
        $ses = db_all("SELECT lesson_date, time_from, time_to FROM k30_ti_sessions
                        WHERE course_id = ? AND draft_id IS NULL AND status NOT IN ('draft','reserved','cancelled') ORDER BY lesson_date, time_from", [$courseId]);
        $min = 0; $days = [];
        foreach ($ses as $s) { $min += max(0, ti_hm2min(substr((string)$s['time_to'], 0, 5)) - ti_hm2min(substr((string)$s['time_from'], 0, 5))); $days[(string)$s['lesson_date']] = true; }
        $today = date('Y-m-d');
        $upcoming = array_values(array_filter(array_keys($days), fn($d) => $d >= $today));
        $staff = [];
        foreach (MsuesSzoData::serviceStaff($courseId) as $s) $staff[] = tiPlanPublicStaffCode((string)$s['name']) . ' (' . $s['role'] . ')';
        $form = !empty($c['is_online']) ? 'online' : ((string)($c['class_type'] ?? '') === 'hybrid' ? 'hybrydowo (stacjonarnie i online)' : 'stacjonarnie');
        $planTok = tiPlanPublicDirectoryTokens()[$courseId] ?? '';
        return [
            'id' => $courseId, 'title' => $title, 'subject' => (string)($c['subject_name'] ?? ''),
            'summary' => (string)$p['catalog_summary'], 'audience' => (string)$p['catalog_audience'],
            'outcomes' => (string)$p['learning_outcomes'], 'requirements' => (string)$p['requirements'],
            'support' => (string)$p['support_form'], 'certificate' => (string)$p['certificate_doc'],
            'program' => $prog,
            'hours' => $min ? round($min / 60, 1) : ($estMin ? round($estMin / 60, 1) : 0.0), 'hours_src' => $min ? 'plan' : ($estMin ? 'program' : ''),
            'days' => count($days), 'first' => array_key_first($days), 'last' => $days ? array_key_last($days) : null,
            'next' => $upcoming[0] ?? null, 'ended' => $days && !$upcoming,
            'form' => $form, 'location' => !empty($c['is_online']) ? '' : (string)($c['location'] ?? ''),
            'staff' => $staff, 'msues' => !empty($p['is_msues']),
            'plan_url' => $planTok !== '' ? tiPlanPortalUrlSafe(['g' => $planTok]) : '',
        ];
    }

    /** Dokumenty instytucji do stopki katalogu: tylko te, które są adresami stron. */
    public static function orgLinks(): array
    {
        $o = [];
        foreach (MsuesRequirements::orgDocs() as $k => $d) {
            if ($k === 'msues_doc_katalog' || $k === 'msues_doc_rozwoj') continue;
            if (preg_match('#^https?://\S+$#i', $d['value'])) $o[] = [$d['label'], $d['value']];
        }
        return $o;
    }
}

/** Adres planu grupy z portalu planu (krótki na hostach TI) — bez zależności od widoku portalu. */
function tiPlanPortalUrlSafe(array $q): string
{
    $self = '/karty30/ti/dydaktyk/planPubliczny.php';
    if (function_exists('tiPlanPortalUrl')) return html_entity_decode(tiPlanPortalUrl($self, $q));
    return $self . '?' . http_build_query($q);
}
