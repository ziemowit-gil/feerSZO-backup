<?php
/**
 * Most: zatwierdzone protokoły zajęć TI → EZD Wirtualne biurko.
 *
 * Każdy zatwierdzony protokół (includes/ti_protocols.php, ti_protocol_approve())
 * trafia do EZD jako NOWA koszulka (sprawa) w segregatorze rocznym klasy JRWA
 * 384 „Protokoły zajęć kursów TI" (gałąź 38 Promowanie tyfloinformatyki).
 * W koszulce rejestrowane jest pismo wewnętrzne z PDF protokołu.
 *
 * Powiązanie: ezd_sprawy.ref_type='ti_protocol', ref_id=<k30_ti_protocols.id>.
 * Ponowne zatwierdzenie po odblokowaniu NIE zakłada drugiej koszulki —
 * dopisuje do istniejącej kolejne pismo z nową wersją PDF.
 *
 * Wzorem includes/pelnomocnictwa_ezd.php: no-op, gdy EZD wyłączone albo
 * org_setting('ti_protocols_ezd_auto') = '0'; błędy łapane i logowane —
 * nigdy nie mogą przerwać zatwierdzenia protokołu.
 */

require_once dirname(__DIR__, 3) . '/includes/db.php';
require_once dirname(__DIR__, 3) . '/includes/functions.php';

const TI_PROT_EZD_JRWA_SYMBOL = '384';
const TI_PROT_EZD_JRWA_TITLE  = 'Protokoły zajęć kursów TI';
const TI_PROT_EZD_REF_TYPE    = 'ti_protocol';

function ti_prot_ezd_active(): bool {
    if (defined('TI_PROT_EZD_OFF')) return false;   // autotest protokołów bez EZD (cli/ti_protocols.php)
    return function_exists('module_enabled')
        && module_enabled('ezd_enabled')
        && org_setting('ti_protocols_ezd_auto') !== '0';
}

/** Klasa JRWA 384 — znajduje albo zakłada (pod 38, jeśli istnieje). */
function _ti_prot_ezd_jrwa_id(int $user_id): ?int {
    $j = db_one("SELECT id FROM ezd_jrwa WHERE symbol=?", [TI_PROT_EZD_JRWA_SYMBOL]);
    if ($j) return (int)$j['id'];
    $parent = db_one("SELECT id FROM ezd_jrwa WHERE symbol='38'");
    db()->prepare(
        "INSERT INTO ezd_jrwa (symbol,title,kat_arch,description,sort_order,parent_id) VALUES (?,?,?,?,?,?)"
    )->execute([
        TI_PROT_EZD_JRWA_SYMBOL, TI_PROT_EZD_JRWA_TITLE, 'B10',
        'Zatwierdzone protokoły zajęć kursów tyfloinformatycznych (oceny końcowe, ewidencja godzin, wypłata) — zakładane automatycznie z panelu dydaktyka.',
        40, $parent['id'] ?? null,
    ]);
    $id = (int)db()->lastInsertId();
    ezd_log(null, null, null, null, $user_id, 'jrwa_create', 'Dodano hasło JRWA: ' . TI_PROT_EZD_JRWA_SYMBOL . ' (automatycznie, protokoły TI)');
    return $id;
}

/** Segregator roczny klasy 384 — otwarty na bieżący rok albo nowy. */
function _ti_prot_ezd_teczka_id(int $user_id): int {
    $rok = (int)date('Y');
    $t = db_one(
        "SELECT id FROM ezd_teczki WHERE symbol=? AND rok=? AND status='open' ORDER BY id DESC LIMIT 1",
        [TI_PROT_EZD_JRWA_SYMBOL, $rok]
    );
    if ($t) return (int)$t['id'];
    return ezd_teczka_create([
        'jrwa_id'  => _ti_prot_ezd_jrwa_id($user_id),
        'symbol'   => TI_PROT_EZD_JRWA_SYMBOL,
        'title'    => TI_PROT_EZD_JRWA_TITLE . ' ' . $rok,
        'rok'      => $rok,
        'owner_id' => null,
    ], $user_id);
}

/** Istniejąca koszulka protokołu albo null. */
function ti_prot_ezd_sprawa_id(int $protocol_id): ?int {
    if (!function_exists('ezd_sprawy_by_ref')) return null;
    $rows = ezd_sprawy_by_ref(TI_PROT_EZD_REF_TYPE, $protocol_id);
    return $rows ? (int)$rows[0]['id'] : null;
}

/**
 * Rejestruje zatwierdzony protokół w EZD: koszulka (nowa lub istniejąca) +
 * pismo wewnętrzne z PDF. Zwraca id koszulki albo null.
 */
function ti_prot_ezd_register(int $protocol_id, ?int $user_id): ?int {
    if (!ti_prot_ezd_active()) return null;
    try {
        require_once dirname(__DIR__, 3) . '/includes/ezd.php';
        require_once dirname(__DIR__, 3) . '/includes/ti_protocols.php';

        $prot = ti_protocol_get($protocol_id);
        if (!$prot || ($prot['status'] ?? '') !== 'approved') return null;
        $uid = (int)($user_id ?: ($prot['approved_by'] ?? 0));

        $okres  = (string)($prot['period_name'] ?? '');
        $title  = 'Protokół zajęć — ' . (string)$prot['course_name'] . ($okres !== '' ? ', ' . $okres : '');
        $course = db_one("SELECT c.name, u.name AS instructor_name FROM k30_ti_courses c
                          LEFT JOIN users u ON u.id=c.instructor_id WHERE c.id=?", [(int)$prot['course_id']]) ?: [];

        $sid = ti_prot_ezd_sprawa_id($protocol_id);
        if (!$sid) {
            $desc = "Protokół zajęć kursu TI (#{$protocol_id})\n"
                  . 'Grupa: ' . (string)$prot['course_name'] . "\n"
                  . ($okres !== '' ? "Okres: {$okres}" . (!empty($prot['date_from']) ? " ({$prot['date_from']} – {$prot['date_to']})" : '') . "\n" : '')
                  . (!empty($course['instructor_name']) ? 'Prowadzący: ' . $course['instructor_name'] . "\n" : '')
                  . 'Zatwierdził(a): ' . (string)($prot['approved_name'] ?? '') . ', ' . (string)($prot['approved_at'] ?? '');
            $sid = ezd_sprawa_create([
                'teczka_id'   => _ti_prot_ezd_teczka_id($uid),
                'title'       => $title,
                'description' => $desc,
                'status'      => 'open',
                'priority'    => 'normal',
                'owner_id'    => $uid ?: null,
                'ref_type'    => TI_PROT_EZD_REF_TYPE,
                'ref_id'      => $protocol_id,
            ], $uid);
        }

        $again = (int)(db_one("SELECT COUNT(*) AS n FROM ezd_pisma WHERE sprawa_id=?", [$sid])['n'] ?? 0) > 0;
        $pid = ezd_pismo_create([
            'sprawa_id'     => $sid,
            'kierunek'      => 'wewnetrzne',
            'title'         => ($again ? 'Protokół zatwierdzony ponownie (po odblokowaniu)' : 'Protokół zatwierdzony') . ' — ' . (string)$prot['course_name'],
            'tresc'         => 'PDF protokołu dołączony automatycznie z panelu dydaktyka TI w chwili zatwierdzenia.',
            'nadawca'       => (string)($prot['approved_name'] ?? ''),
            'odbiorca'      => '',
            'data_pisma'    => substr((string)($prot['approved_at'] ?? date('Y-m-d')), 0, 10),
            'status'        => 'zakonczone',
            'owner_id'      => $uid ?: null,
            'rodzaj_medium' => 'inne',
        ], $uid);

        $pdf = ti_protocol_pdf($prot);
        if ($pdf !== null && $pdf !== '') {
            $tmp = tempnam(sys_get_temp_dir(), 'tiprot_');
            if ($tmp !== false) {
                try {
                    file_put_contents($tmp, $pdf);
                    ezd_attach_path($tmp, ti_protocol_pdf_filename($prot), $sid, $pid, $uid);
                } finally {
                    @unlink($tmp);
                }
            }
        }
        return $sid;
    } catch (\Throwable $e) {
        error_log('ti_prot_ezd_register #' . $protocol_id . ': ' . $e->getMessage());
        return null;
    }
}

/** Odnośnik do koszulki protokołu w EZD (lub null). */
function ti_prot_ezd_url(int $protocol_id): ?string {
    $sid = ti_prot_ezd_sprawa_id($protocol_id);
    return $sid ? rtrim(APP_URL, '/') . '/ezd/sprawy/view.php?id=' . $sid : null;
}
