<?php
/**
 * modules/ti_comm_targets/logic/tiCommTargets.php — odbiorcy wysyłki „Komunikacja — e-mail / SMS”
 * (panel dydaktyka, kierownik). Jedno miejsce dla podglądu i wysyłki.
 *
 * Tryby:
 *   grupa / prowadzacy / dzien — jak dotąd (przez kursy, k30_ti_comm_course_ids),
 *   osoby   — wybrani kursanci ($opts['client_ids']),
 *   wszyscy — wszyscy kursanci z aktywnym zapisem w dowolnej grupie.
 * Opcjonalnie dokłada rodziców/opiekunów małoletnich i usuwa duplikaty (po e-mailu, potem telefonie).
 */

const TI_COMM_MODES = ['grupa', 'prowadzacy', 'dzien', 'osoby', 'wszyscy'];

/** Kursanci z aktywnym zapisem (do listy wyboru „Wybrane osoby”). */
function tiCommStudentsList(): array {
    return db_all(
        "SELECT cl.id, cl.name, cl.email, cl.phone
           FROM k30_ti_enrollments e JOIN k30_clients cl ON cl.id = e.client_id
          WHERE e.status = 'active'
          GROUP BY cl.id
          ORDER BY cl.name COLLATE NOCASE");
}

/** @return array{0: array, 1: string} [odbiorcy, opis filtra do dziennika] */
function tiCommTargets(string $mode, array $opts, bool $withGuardians): array {
    if (!in_array($mode, TI_COMM_MODES, true)) $mode = 'grupa';
    $clientIds = [];
    $recips = $guard = [];

    if ($mode === 'osoby' || $mode === 'wszyscy') {
        if ($mode === 'osoby') {
            $clientIds = array_values(array_unique(array_filter(array_map('intval', (array)($opts['client_ids'] ?? [])))));
        } else {
            $clientIds = array_map(fn($r) => (int)$r['id'], tiCommStudentsList());
        }
        if ($clientIds) {
            $ph = implode(',', array_fill(0, count($clientIds), '?'));
            $recips = db_all(
                "SELECT cl.id AS client_id, cl.name, cl.email, cl.phone, 'kursant' AS role
                   FROM k30_clients cl WHERE cl.id IN ($ph) ORDER BY cl.name COLLATE NOCASE", $clientIds);
            if ($withGuardians) {
                $guard = db_all(
                    "SELECT a.id AS client_id, 'Opiekun: ' || cl.name AS name, a.guardian_email AS email,
                            a.guardian_phone AS phone, 'opiekun' AS role
                       FROM k30_ti_student_accounts a JOIN k30_clients cl ON cl.id = a.client_id
                      WHERE a.client_id IN ($ph) AND a.is_active = 1 AND a.is_minor = 1
                        AND (a.guardian_email != '' OR a.guardian_phone != '')
                      GROUP BY a.id ORDER BY cl.name COLLATE NOCASE", $clientIds);
            }
        }
        $label = $mode === 'wszyscy'
            ? 'Wszyscy aktywni kursanci'
            : 'Wybrane osoby: ' . (implode(', ', array_slice(array_column($recips, 'name'), 0, 5)) ?: '—')
              . (count($recips) > 5 ? ' i ' . (count($recips) - 5) . ' więcej' : '');
    } else {
        $cids   = k30_ti_comm_course_ids($mode, $opts);
        $recips = k30_ti_comm_recipients($cids);
        if ($withGuardians) $guard = k30_ti_comm_guardian_recipients($cids);
        if ($mode === 'grupa') {
            $names = $cids ? array_column(db_all("SELECT name FROM k30_ti_courses WHERE id IN (" . implode(',', array_fill(0, count($cids), '?')) . ")", $cids), 'name') : [];
            $label = 'Grupy: ' . (implode(', ', $names) ?: '—');
        } elseif ($mode === 'prowadzacy') {
            $in = !empty($opts['instructor_id']) ? db_one("SELECT name FROM users WHERE id = ?", [(int)$opts['instructor_id']]) : null;
            $label = 'Prowadzący: ' . ($in['name'] ?? '—');
        } else {
            $label = 'Dzień: ' . (($opts['date'] ?? '') ?: '—');
        }
    }

    if ($guard) {
        $label .= ' + rodzice/opiekunowie';
        $seen = [];
        $recips = array_values(array_filter(array_merge($recips, $guard), function ($r) use (&$seen) {
            $key = trim(mb_strtolower((string)$r['email'])) ?: trim((string)$r['phone']);
            if ($key === '') return true;
            if (isset($seen[$key])) return false;
            return $seen[$key] = true;
        }));
    } elseif ($withGuardians) {
        $label .= ' + rodzice/opiekunowie';
    }
    return [$recips, $label];
}
