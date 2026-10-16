<?php
/**
 * modules/ti_edziennik/logic/closeArchive.php — dziennik zajęć grupy TI → EZD przy „Zamknij i archiwizuj”.
 *
 * ti_course_close() (includes/karty30.php) woła tiCloseArchiveRegister(): pełny dziennik zajęć grupy
 * (modules/ti_pdf/logic/dziennik.php — tematy, frekwencja, oceny, wynik kursu, podpisy; BEZ danych osobowych)
 * trafia jako pismo wewnętrzne do koszulki EZD w tym samym segregatorze co protokoły (JRWA 384, rok bieżący).
 * Koszulka: ezd_sprawy.ref_type='ti_course_close', ref_id=<course_id> — ponowne zamknięcie po przywróceniu
 * z archiwum albo „Wyślij dziennik do EZD ponownie” (kierownik, zakładka Grupy) dopisuje nowe pismo.
 * Wyłącznik jak dla protokołów: ti_prot_ezd_active() (EZD włączone i ti_protocols_ezd_auto ≠ '0').
 * Błędy tylko do error_log — nigdy nie blokują zamknięcia grupy.
 */

const TI_CLOSE_EZD_REF_TYPE = 'ti_course_close';

/** Rejestruje dziennik zamkniętej grupy w EZD. Zwraca id koszulki albo null (wyłączone / błąd). */
function tiCloseArchiveRegister(int $courseId, ?int $userId): ?int
{
    require_once dirname(__DIR__, 2) . '/ti_protokoly_ezd/logic/ti_protokoly_ezd.php';
    if (!ti_prot_ezd_active()) return null;
    try {
        require_once dirname(__DIR__, 3) . '/includes/ezd.php';
        require_once dirname(__DIR__, 2) . '/ti_pdf/logic/dziennik.php';
        $c = k30_ti_course_get($courseId);
        if (!$c) return null;
        $uid  = (int)($userId ?: ($c['closed_by'] ?? 0));
        $name = (string)$c['name'];
        $closedAt = (string)($c['closed_at'] ?? '');

        $rows = ezd_sprawy_by_ref(TI_CLOSE_EZD_REF_TYPE, $courseId);
        $sid  = $rows ? (int)$rows[0]['id'] : 0;
        if (!$sid) {
            $sid = ezd_sprawa_create([
                'teczka_id'   => _ti_prot_ezd_teczka_id($uid),
                'title'       => 'Dziennik zajęć — zamknięcie grupy ' . $name,
                'description' => "Dziennik zajęć grupy TI (#{$courseId}) przekazany przy zamknięciu i archiwizacji grupy.\n"
                               . 'Grupa: ' . $name . "\n"
                               . (!empty($c['instructor_name']) ? 'Prowadzący: ' . $c['instructor_name'] . "\n" : '')
                               . 'Zamknął(a): ' . (string)($c['closed_name'] ?? '') . ($closedAt !== '' ? ', ' . $closedAt : ''),
                'status'      => 'open',
                'priority'    => 'normal',
                'owner_id'    => $uid ?: null,
                'ref_type'    => TI_CLOSE_EZD_REF_TYPE,
                'ref_id'      => $courseId,
            ], $uid);
        }
        $again = (int)(db_one("SELECT COUNT(*) AS n FROM ezd_pisma WHERE sprawa_id=?", [$sid])['n'] ?? 0) > 0;
        $pid = ezd_pismo_create([
            'sprawa_id'     => $sid,
            'kierunek'      => 'wewnetrzne',
            'title'         => ($again ? 'Dziennik zajęć (ponownie)' : 'Dziennik zajęć przy zamknięciu grupy') . ' — ' . $name,
            'tresc'         => 'PDF dziennika zajęć (tematy, frekwencja, oceny, wynik kursu) dołączony automatycznie z panelu dydaktyka TI.',
            'nadawca'       => (string)($c['closed_name'] ?? ''),
            'odbiorca'      => '',
            'data_pisma'    => $closedAt !== '' ? substr($closedAt, 0, 10) : date('Y-m-d'),
            'status'        => 'zakonczone',
            'owner_id'      => $uid ?: null,
            'rodzaj_medium' => 'inne',
        ], $uid);
        _ti_prot_ezd_attach(ti_dziennik_pdf($courseId, ['personal' => false]), ti_dziennik_filename($c), $sid, $pid, $uid);
        return $sid;
    } catch (\Throwable $e) {
        error_log('tiCloseArchiveRegister #' . $courseId . ': ' . $e->getMessage());
        return null;
    }
}

/** Odnośnik do koszulki dziennika zamkniętej grupy w EZD (lub null). */
function tiCloseArchiveUrl(int $courseId): ?string
{
    try {
        require_once dirname(__DIR__, 3) . '/includes/ezd.php';
        $rows = ezd_sprawy_by_ref(TI_CLOSE_EZD_REF_TYPE, $courseId);
    } catch (\Throwable $e) { return null; }
    return $rows ? rtrim(APP_URL, '/') . '/ezd/sprawy/view.php?id=' . (int)$rows[0]['id'] : null;
}
