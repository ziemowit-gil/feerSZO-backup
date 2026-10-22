<?php
/**
 * modules/noclegi/lib/noclegiTiSync.php — integracja z modułem TI (modules/ti_lodging, „Noclegi kadry”).
 *
 * TiLodging trzyma tylko flagi „noc przed / noc po” dla kadry (daty wylicza z lekcji stacjonarnych). Moduł Noclegi
 * zamienia je w pełne zgłoszenia: jedno zgłoszenie na flagę (klucz ti:{grupa}:{osoba}:{rodzaj}), role dydaktyk,
 * cel = nazwa grupy. Synchronizacja jest jednokierunkowa (TI → Noclegi) i idempotentna:
 *  • nowa flaga → nowe zgłoszenie „Wniosek”,
 *  • zmiana terminów lekcji → aktualizacja dat (gdy zgłoszenie nie jest zamknięte; potwierdzone wraca do „W trakcie”, jeśli pokój przestał pasować),
 *  • zdjęta flaga → anulowanie (zgłoszenia zamknięte bez zmian),
 *  • osoba bez umowy ze znacznikiem „Dop. noclegi” jest pomijana.
 * Nie zmienia nic w ti_lodging.
 */
require_once __DIR__ . '/noclegiRequests.php';
require_once dirname(__DIR__, 2) . '/ti_lodging/logic/lodging.php';

function noclTiSync(int $by): array {
    TiLodging::migrate();
    $res = ['created' => 0, 'updated' => 0, 'cancelled' => 0, 'skipped' => 0];
    $flags = TiLodging::flags();
    $seen = [];
    $users = [];
    foreach (db_all("SELECT id, name, email FROM users") as $u) $users[(int)$u['id']] = $u;
    foreach (TiLodging::spans() as $sp) {
        foreach (array_keys(TiLodging::KINDS) as $kind) {
            $fk = $sp['course_id'] . ':' . $sp['uid'] . ':' . $kind;
            if (empty($flags[$fk])) continue;
            $key = 'ti:' . $fk;
            $seen[$key] = true;
            $nt = TiLodging::nights($sp['first_d'], $sp['last_d'])[$kind];
            $u = $users[(int)$sp['uid']] ?? null;
            if (!$u || !noclEligible((int)$sp['uid'])) { $res['skipped']++; continue; }   // bramka „Dop. noclegi” na umowie
            $ex = db_one("SELECT * FROM nocl_requests WHERE ti_key = ? AND status != 'anulowane' ORDER BY id DESC LIMIT 1", [$key]);
            if (!$ex) {
                $anyCancelled = db_one("SELECT id FROM nocl_requests WHERE ti_key = ? AND status = 'anulowane'", [$key]);
                if ($anyCancelled) { $res['skipped']++; continue; } // ktoś świadomie anulował — nie wskrzeszamy
                noclRequestCreate([
                    'person_name' => (string)$u['name'], 'person_email' => (string)$u['email'], 'person_phone' => '', 'person_role' => 'dydaktyk',
                    'date_from' => $nt[0], 'date_to' => $nt[1], 'city' => '', 'purpose' => 'Szkolenie stacjonarne: ' . $sp['course_name'] . ' (' . TiLodging::KINDS[$kind] . ')',
                    'room_type_pref' => '', 'preferences' => '', 'roommate_pref' => '', 'needs_accessible' => 0, 'accessibility_notes' => '', 'budget_id' => null,
                ], (int)$sp['uid'], $by, 'ti', ['ti_key' => $key, 'course_id' => (int)$sp['course_id']]);
                $res['created']++;
                continue;
            }
            if ($ex['date_from'] === $nt[0] && $ex['date_to'] === $nt[1]) continue;
            if (in_array($ex['status'], ['zrealizowane'], true)) { $res['skipped']++; continue; }
            $err = noclRequestUpdate((int)$ex['id'], ['person_name' => $ex['person_name'], 'person_email' => $ex['person_email'], 'person_phone' => $ex['person_phone'], 'person_role' => $ex['person_role'],
                'date_from' => $nt[0], 'date_to' => $nt[1], 'city' => $ex['city'], 'purpose' => $ex['purpose'], 'room_type_pref' => $ex['room_type_pref'], 'preferences' => $ex['preferences'],
                'roommate_pref' => $ex['roommate_pref'], 'needs_accessible' => (int)$ex['needs_accessible'], 'accessibility_notes' => $ex['accessibility_notes'], 'budget_id' => $ex['budget_id']], $by);
            if ($err) { $res['skipped']++; continue; }
            noclLog((int)$ex['id'], 'ti', 'zmiana terminów w TI: ' . $ex['date_from'] . '–' . $ex['date_to'] . ' → ' . $nt[0] . '–' . $nt[1], 'request', $by);
            $res['updated']++;
        }
    }
    // Zdjęte flagi (lub lekcje stacjonarne zniknęły) → anuluj zgłoszenia z TI, które nie są zamknięte
    foreach (db_all("SELECT id, ti_key FROM nocl_requests WHERE source = 'ti' AND status IN ('wniosek','w_trakcie','potwierdzone') AND date_to >= ?", [date('Y-m-d')]) as $r) {
        if (isset($seen[$r['ti_key']])) continue;
        db()->prepare("UPDATE nocl_requests SET status='anulowane', budget_approved=0, updated_at=? WHERE id=?")->execute([noclNow(), $r['id']]);
        noclLog((int)$r['id'], 'ti', 'anulowano — w TI zdjęto nocleg lub zniknęły lekcje stacjonarne', 'request', $by);
        $res['cancelled']++;
    }
    return $res;
}
