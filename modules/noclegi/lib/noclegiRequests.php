<?php
/**
 * modules/noclegi/lib/noclegiRequests.php — filar 1: zgłoszenia i rezerwacje noclegów.
 *
 * Cykl: wniosek → w_trakcie → potwierdzone → zrealizowane (+ anulowane). Dostęp wg ról (noclegiRoles.php):
 *  • Użytkownik zgłasza i anuluje własne (do dnia przyjazdu), widzi własne;
 *  • Koordynator Projektu przejmuje wnioski swoich budżetów (akceptacja kosztów), anuluje je;
 *  • Logistyk przydziela pokój, potwierdza, cofa i realizuje.
 * Noce liczymy jako różnicę dat: date_from = dzień przyjazdu, date_to = dzień wyjazdu.
 * Uwagi o dostępności (accessibility_notes) to dane wrażliwe — widzi je tylko właściciel, koordynator i logistyk;
 * obiekty dostają w rooming list wyłącznie flagę „pokój dostępny”.
 */
require_once __DIR__ . '/noclegiDict.php';
require_once __DIR__ . '/noclegiRoles.php';

/** Dozwolone przejścia: [z => [do => wymagana rola minimalna]] — dodatkowe warunki w noclTransition(). */
const NOCL_PRZEJSCIA = [
    'wniosek'      => ['w_trakcie', 'anulowane'],
    'w_trakcie'    => ['wniosek', 'potwierdzone', 'anulowane'],
    'potwierdzone' => ['w_trakcie', 'zrealizowane', 'anulowane'],
    'zrealizowane' => [],
    'anulowane'    => ['wniosek'],
];

function noclNow(): string { return date('Y-m-d H:i:s'); }

function noclLog(?int $requestId, string $action, string $detail = '', string $entity = 'request', ?int $uid = null): void {
    $u = current_user() ?: [];
    $uid ??= (int)($u['id'] ?? 0);
    db()->prepare("INSERT INTO nocl_log (request_id, entity, action, detail, user_id, user_name, created_at) VALUES (?,?,?,?,?,?,?)")
        ->execute([$requestId, $entity, $action, mb_substr($detail, 0, 2000), $uid ?: null, (string)($u['name'] ?? ''), noclNow()]);
}

function noclNights(string $from, string $to): int {
    $a = strtotime($from); $b = strtotime($to);
    return ($a && $b && $b > $a) ? (int)round(($b - $a) / 86400) : 0;
}

function noclDateOk(string $d): bool {
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4));
}

/** Walidacja formularza zgłoszenia → [dane, błędy]. */
function noclRequestValidate(array $in): array {
    $err = []; $d = [];
    $d['person_name']  = trim((string)($in['person_name'] ?? ''));
    $d['person_email'] = trim((string)($in['person_email'] ?? ''));
    $d['person_phone'] = trim((string)($in['person_phone'] ?? ''));
    $d['person_role']  = (string)($in['person_role'] ?? 'dydaktyk');
    $d['date_from']    = (string)($in['date_from'] ?? '');
    $d['date_to']      = (string)($in['date_to'] ?? '');
    $d['city']         = trim((string)($in['city'] ?? ''));
    $d['purpose']      = trim((string)($in['purpose'] ?? ''));
    $d['room_type_pref'] = (string)($in['room_type_pref'] ?? '');
    $d['roommate_pref']  = trim((string)($in['roommate_pref'] ?? ''));
    $d['needs_accessible'] = !empty($in['needs_accessible']) ? 1 : 0;
    $d['accessibility_notes'] = trim((string)($in['accessibility_notes'] ?? ''));
    $d['budget_id']    = (int)($in['budget_id'] ?? 0) ?: null;
    $prefs = array_values(array_intersect(array_map('strval', (array)($in['preferences'] ?? [])), array_keys(noclPreferencje())));
    $d['preferences'] = implode(',', $prefs);

    if ($d['person_name'] === '') $err['person_name'] = 'Podaj imię i nazwisko osoby.';
    if ($d['person_email'] !== '' && !filter_var($d['person_email'], FILTER_VALIDATE_EMAIL)) $err['person_email'] = 'Adres e-mail ma nieprawidłowy format.';
    if (!noclDictAllowedKey($d['person_role'], noclRoleOsoby())) $err['person_role'] = 'Wybierz rolę osoby z listy.';
    if (!noclDateOk($d['date_from'])) $err['date_from'] = 'Podaj datę przyjazdu (RRRR-MM-DD).';
    if (!noclDateOk($d['date_to']))   $err['date_to']   = 'Podaj datę wyjazdu (RRRR-MM-DD).';
    if (!$err && $d['date_to'] <= $d['date_from']) $err['date_to'] = 'Data wyjazdu musi być późniejsza niż data przyjazdu.';
    if (!$err && noclNights($d['date_from'], $d['date_to']) > 60) $err['date_to'] = 'Jedno zgłoszenie obejmuje najwyżej 60 nocy.';
    if ($d['room_type_pref'] !== '' && !noclDictAllowedKey($d['room_type_pref'], noclTypyPokoi())) $err['room_type_pref'] = 'Wybierz typ pokoju z listy.';
    if ($d['needs_accessible'] && $d['accessibility_notes'] === '' ) { /* opis opcjonalny */ }
    if ($d['budget_id'] && !db_one("SELECT 1 FROM nocl_budgets WHERE id = ? AND is_active = 1", [$d['budget_id']])) $err['budget_id'] = 'Wybrany budżet nie istnieje albo jest zamknięty.';
    return [$d, $err];
}

function noclDictAllowedKey(string $v, array $opts): bool { return $v !== '' && array_key_exists($v, $opts); }

/** Tworzy zgłoszenie. $uid = konto osoby (null = osoba bez konta, np. wolontariusz zgłoszony przez logistyka). */
function noclRequestCreate(array $d, ?int $uid, int $by, string $source = 'form', array $extra = []): int {
    $now = noclNow();
    $row = $d + $extra + ['user_id' => $uid ?: null, 'source' => $source, 'status' => 'wniosek', 'created_by' => $by ?: null, 'created_at' => $now, 'updated_at' => $now];
    $id = db_insert('nocl_requests', $row);
    noclLog($id, 'zgloszono', $d['date_from'] . ' → ' . $d['date_to'] . ($source !== 'form' ? ' (' . $source . ')' : ''), 'request', $by);
    return $id;
}

function noclRequestGet(int $id): ?array {
    $r = db_one("SELECT r.*, f.name AS facility_name, f.city AS facility_city, rm.name AS room_name, b.code AS budget_code, b.name AS budget_name
                   FROM nocl_requests r
                   LEFT JOIN nocl_facilities f ON f.id = r.facility_id
                   LEFT JOIN nocl_rooms rm ON rm.id = r.room_id
                   LEFT JOIN nocl_budgets b ON b.id = r.budget_id
                  WHERE r.id = ?", [$id]);
    return $r ?: null;
}

/** Czy użytkownik może zobaczyć zgłoszenie. */
function noclCanView(array $r, int $uid): bool {
    if ($uid <= 0) return false;
    if ((int)($r['user_id'] ?? 0) === $uid || (int)($r['created_by'] ?? 0) === $uid) return true;
    if (noclIsLogistyk($uid)) return true;
    return noclRole($uid) === 'koordynator' && noclCoordCovers($uid, (int)($r['budget_id'] ?? 0) ?: null);
}

/** Lista zgłoszeń z filtrami; zakres wg roli: 'own' | 'coord' | 'all'. */
function noclRequestList(int $uid, string $scope, array $f = []): array {
    $w = ['1=1']; $p = [];
    if ($scope === 'own') { $w[] = '(r.user_id = ? OR r.created_by = ?)'; array_push($p, $uid, $uid); }
    elseif ($scope === 'coord') {
        $b = noclCoordBudgets($uid);
        if ($b !== null) {
            if (!$b) return [];
            $w[] = 'r.budget_id IN (' . implode(',', array_map('intval', $b)) . ')';
        }
    }
    if (!empty($f['status'])) { $w[] = 'r.status = ?'; $p[] = (string)$f['status']; }
    if (!empty($f['budget_id'])) { $w[] = 'r.budget_id = ?'; $p[] = (int)$f['budget_id']; }
    if (!empty($f['from'])) { $w[] = 'r.date_to >= ?'; $p[] = (string)$f['from']; }
    if (!empty($f['to']))   { $w[] = 'r.date_from <= ?'; $p[] = (string)$f['to']; }
    if (!empty($f['q'])) { $w[] = '(r.person_name LIKE ? OR r.purpose LIKE ? OR r.city LIKE ?)'; $q = '%' . (string)$f['q'] . '%'; array_push($p, $q, $q, $q); }
    if (!empty($f['open'])) $w[] = "r.status IN ('wniosek','w_trakcie')";
    return db_all("SELECT r.*, f.name AS facility_name, rm.name AS room_name, b.code AS budget_code
                     FROM nocl_requests r
                     LEFT JOIN nocl_facilities f ON f.id = r.facility_id
                     LEFT JOIN nocl_rooms rm ON rm.id = r.room_id
                     LEFT JOIN nocl_budgets b ON b.id = r.budget_id
                    WHERE " . implode(' AND ', $w) . " ORDER BY r.date_from DESC, r.id DESC LIMIT 500", $p);
}

/** Zgłoszenia „potwierdzone”, których wyjazd już minął → zrealizowane. Zwraca liczbę zmian. */
function noclAutoComplete(): int {
    $rows = db_all("SELECT id FROM nocl_requests WHERE status = 'potwierdzone' AND date_to < ?", [date('Y-m-d')]);
    foreach ($rows as $r) {
        db()->prepare("UPDATE nocl_requests SET status='zrealizowane', updated_at=? WHERE id=?")->execute([noclNow(), $r['id']]);
        noclLog((int)$r['id'], 'status', 'potwierdzone → zrealizowane (automatycznie po wyjeździe)', 'request', 0);
    }
    return count($rows);
}

/**
 * Zmiana statusu z kontrolą roli i warunków. Zwraca null (OK) albo komunikat błędu.
 * $by = id wykonującego.
 */
function noclTransition(int $id, string $to, int $by, string $note = ''): ?string {
    $r = noclRequestGet($id);
    if (!$r) return 'Nie znaleziono zgłoszenia.';
    $from = (string)$r['status'];
    if (!in_array($to, NOCL_PRZEJSCIA[$from] ?? [], true)) return 'Z „' . noclStatusLabel($from) . '” nie można przejść do „' . noclStatusLabel($to) . '”.';
    $role = noclRole($by);
    $owner = (int)$r['user_id'] === $by || (int)$r['created_by'] === $by;
    $coord = $role === 'koordynator' && noclCoordCovers($by, (int)$r['budget_id'] ?: null);
    $log = $role === 'logistyk';

    if ($to === 'anulowane') {
        $okOwner = $owner && in_array($from, ['wniosek', 'w_trakcie', 'potwierdzone'], true) && $r['date_from'] >= date('Y-m-d');
        if (!($okOwner || $coord || $log)) return 'Brak uprawnień do anulowania tego zgłoszenia.';
    } elseif ($to === 'w_trakcie' && $from === 'wniosek') {
        if (!($coord || $log)) return 'Wniosek przejmuje Koordynator Projektu (swój budżet) albo Logistyk.';
    } elseif ($to === 'wniosek' && $from === 'anulowane') {
        if (!$log) return 'Przywrócić anulowane zgłoszenie może Logistyk.';
    } else {
        if (!$log) return 'Tę zmianę statusu wykonuje Logistyk.';
    }
    if ($to === 'potwierdzone') {
        if (!(int)$r['room_id']) return 'Najpierw przydziel pokój (zakładka Rooming).';
        if ((int)$r['budget_id'] && !(int)$r['budget_approved']) return 'Koszt wymaga akceptacji Koordynatora Projektu przypisanego do budżetu.';
        if ($e = noclBudgetCheck($r, true)) return $e;
    }
    if ($to === 'zrealizowane' && $r['date_to'] > date('Y-m-d')) return 'Pobyt jeszcze się nie zakończył.';

    $set = ['status = ?', 'updated_at = ?']; $p = [$to, noclNow()];
    if ($to === 'wniosek' || ($to === 'w_trakcie' && $from === 'potwierdzone')) { /* zachowaj przydział pokoju */ }
    if ($to === 'anulowane') { $set[] = 'budget_approved = 0'; }
    $p[] = $id;
    db()->prepare("UPDATE nocl_requests SET " . implode(', ', $set) . " WHERE id = ?")->execute($p);
    noclLog($id, 'status', noclStatusLabel($from) . ' → ' . noclStatusLabel($to) . ($note !== '' ? ' — ' . $note : ''), 'request', $by);
    if ($to === 'potwierdzone') { require_once __DIR__ . '/noclegiInstruction.php'; noclSendConfirmation($id); }
    return null;
}

/** Edycja zgłoszenia przez właściciela (do potwierdzenia) lub Logistyka. */
function noclRequestUpdate(int $id, array $d, int $by): ?string {
    $r = noclRequestGet($id);
    if (!$r) return 'Nie znaleziono zgłoszenia.';
    $owner = (int)$r['user_id'] === $by || (int)$r['created_by'] === $by;
    $log = noclIsLogistyk($by);
    if (!$log && !($owner && in_array($r['status'], ['wniosek', 'w_trakcie'], true))) return 'Zgłoszenie można zmieniać do chwili potwierdzenia (poza Logistykiem).';
    $dates = $d['date_from'] !== $r['date_from'] || $d['date_to'] !== $r['date_to'];
    $d['updated_at'] = noclNow();
    if ($dates && in_array($r['status'], ['potwierdzone', 'w_trakcie'], true)) {
        // zmiana terminów po przydziale: sprawdź dostępność łóżka, inaczej zdejmij pokój
        if ((int)$r['room_id']) {
            require_once __DIR__ . '/noclegiRooming.php';
            if (noclRoomFreeBeds((int)$r['room_id'], $d['date_from'], $d['date_to'], $id) < 1) { $d['room_id'] = null; $d['facility_id'] = null; $d['cost_total'] = '0'; $d['budget_approved'] = 0; if ($r['status'] === 'potwierdzone') $d['status'] = 'w_trakcie'; }
        }
    }
    if ((int)($r['budget_id'] ?? 0) !== (int)($d['budget_id'] ?? 0)) { $d['budget_approved'] = 0; }
    db_update('nocl_requests', $d, $id);
    noclLog($id, 'zmieniono', $dates ? 'terminy: ' . $d['date_from'] . ' → ' . $d['date_to'] : 'dane zgłoszenia', 'request', $by);
    return null;
}

/** Koszt zgłoszenia = noce × cena za osobę za noc przydzielonego pokoju. */
function noclRequestCost(array $r): float {
    if (!(int)($r['room_id'] ?? 0)) return 0.0;
    $room = db_one("SELECT price_per_night FROM nocl_rooms WHERE id = ?", [(int)$r['room_id']]);
    return round(noclNights($r['date_from'], $r['date_to']) * noclMoney((string)($room['price_per_night'] ?? '0')), 2);
}

function noclMoney(string $v): float { return (float)str_replace([' ', ','], ['', '.'], $v); }
function noclMoneyFmt(float $v): string { return number_format($v, 2, ',', ' ') . ' zł'; }

function noclLogList(int $requestId): array {
    return db_all("SELECT * FROM nocl_log WHERE request_id = ? ORDER BY id DESC LIMIT 100", [$requestId]);
}

/** Statystyki do kafli pulpitu. */
function noclCounts(int $uid, string $scope): array {
    $rows = noclRequestList($uid, $scope);
    $c = array_fill_keys(array_keys(NOCL_STATUSY), 0);
    foreach ($rows as $r) $c[$r['status']] = ($c[$r['status']] ?? 0) + 1;
    return $c;
}
