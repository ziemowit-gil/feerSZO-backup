<?php
/**
 * modules/noclegi/lib/noclegiRooming.php — filar 2: baza obiektów i pokoi, rooming (łączenie osób w pokoje),
 * skrytki i kody do drzwi, listy dla obiektów (Rooming List).
 *
 * Pokój ma `beds` miejsc; osoba zajmuje miejsce w nocach [date_from, date_to) w statusach NOCL_STATUSY_ZAJETE.
 * Kod do drzwi / skrytka (door_code, lockbox) to dane dostępowe — nie trafiają do rooming list ani do słowników;
 * osoba widzi je w instrukcji dopiero w oknie przed przyjazdem (noclegiInstruction.php).
 */
require_once __DIR__ . '/noclegiRequests.php';

function noclFacilityList(bool $onlyActive = false): array {
    return db_all("SELECT f.*, (SELECT COUNT(*) FROM nocl_rooms r WHERE r.facility_id = f.id AND r.is_active = 1) AS rooms_n,
                          (SELECT COALESCE(SUM(r.beds),0) FROM nocl_rooms r WHERE r.facility_id = f.id AND r.is_active = 1) AS beds_n
                     FROM nocl_facilities f " . ($onlyActive ? 'WHERE f.is_active = 1 ' : '') . "ORDER BY f.is_active DESC, f.city, f.name");
}
function noclFacilityGet(int $id): ?array { return db_one("SELECT * FROM nocl_facilities WHERE id = ?", [$id]) ?: null; }

function noclFacilityValidate(array $in): array {
    $d = ['name' => trim((string)($in['name'] ?? '')), 'type' => (string)($in['type'] ?? 'hotel'), 'city' => trim((string)($in['city'] ?? '')),
          'address' => trim((string)($in['address'] ?? '')), 'phone' => trim((string)($in['phone'] ?? '')), 'email' => trim((string)($in['email'] ?? '')),
          'map_url' => trim((string)($in['map_url'] ?? '')), 'checkin_from' => (string)($in['checkin_from'] ?? '15:00'),
          'checkin_to' => (string)($in['checkin_to'] ?? '22:00'), 'checkout_until' => (string)($in['checkout_until'] ?? '11:00'),
          'accessible' => !empty($in['accessible']) ? 1 : 0, 'accessibility_info' => trim((string)($in['accessibility_info'] ?? '')),
          'access_info' => trim((string)($in['access_info'] ?? '')), 'rules' => trim((string)($in['rules'] ?? '')),
          'notes' => trim((string)($in['notes'] ?? '')), 'is_active' => !empty($in['is_active']) ? 1 : 0];
    $e = [];
    if ($d['name'] === '') $e['name'] = 'Podaj nazwę obiektu.';
    if (!noclDictAllowedKey($d['type'], noclTypyObiektow())) $e['type'] = 'Wybierz typ obiektu z listy.';
    if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $e['email'] = 'Adres e-mail ma nieprawidłowy format.';
    if ($d['map_url'] !== '' && !preg_match('#^https://#i', $d['map_url'])) $e['map_url'] = 'Link do mapy musi zaczynać się od https://.';
    foreach (['checkin_from', 'checkin_to', 'checkout_until'] as $k) if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $d[$k])) $e[$k] = 'Godzina w formacie GG:MM.';
    return [$d, $e];
}

function noclFacilitySave(array $d, int $id = 0): int {
    $now = noclNow();
    if ($id) { $d['updated_at'] = $now; db_update('nocl_facilities', $d, $id); noclLog(null, 'obiekt', 'zmieniono: ' . $d['name'], 'facility'); return $id; }
    $id = db_insert('nocl_facilities', $d + ['created_at' => $now, 'updated_at' => $now]);
    noclLog(null, 'obiekt', 'dodano: ' . $d['name'], 'facility');
    return $id;
}

function noclRoomList(int $facilityId, bool $onlyActive = true): array {
    return db_all("SELECT * FROM nocl_rooms WHERE facility_id = ? " . ($onlyActive ? 'AND is_active = 1 ' : '') . "ORDER BY name", [$facilityId]);
}
function noclRoomGet(int $id): ?array { return db_one("SELECT r.*, f.name AS facility_name FROM nocl_rooms r JOIN nocl_facilities f ON f.id = r.facility_id WHERE r.id = ?", [$id]) ?: null; }

function noclRoomValidate(array $in): array {
    $d = ['name' => trim((string)($in['name'] ?? '')), 'room_type' => (string)($in['room_type'] ?? 'dwuosobowy'), 'beds' => (int)($in['beds'] ?? 0),
          'accessible' => !empty($in['accessible']) ? 1 : 0, 'price_per_night' => (string)noclMoney((string)($in['price_per_night'] ?? '0')),
          'door_code' => trim((string)($in['door_code'] ?? '')), 'lockbox' => trim((string)($in['lockbox'] ?? '')),
          'notes' => trim((string)($in['notes'] ?? '')), 'is_active' => !empty($in['is_active']) ? 1 : 0];
    $e = [];
    if ($d['name'] === '') $e['name'] = 'Podaj numer albo nazwę pokoju.';
    if (!noclDictAllowedKey($d['room_type'], noclTypyPokoi())) $e['room_type'] = 'Wybierz typ pokoju z listy.';
    if ($d['beds'] <= 0) $d['beds'] = noclTypeBeds($d['room_type']);
    if ($d['beds'] > 30) $e['beds'] = 'Liczba miejsc: 1–30.';
    if ((float)$d['price_per_night'] < 0) $e['price_per_night'] = 'Cena nie może być ujemna.';
    return [$d, $e];
}
function noclRoomSave(int $facilityId, array $d, int $id = 0): int {
    $now = noclNow();
    if ($id) { $d['updated_at'] = $now; db_update('nocl_rooms', $d, $id); noclLog(null, 'pokoj', 'zmieniono: ' . $d['name'], 'facility'); return $id; }
    return db_insert('nocl_rooms', $d + ['facility_id' => $facilityId, 'created_at' => $now, 'updated_at' => $now]);
}

/** Najmniejsza liczba wolnych miejsc w pokoju w nocach [from, to) — z pominięciem zgłoszenia $exclude. */
function noclRoomFreeBeds(int $roomId, string $from, string $to, int $exclude = 0): int {
    $room = db_one("SELECT beds FROM nocl_rooms WHERE id = ?", [$roomId]);
    if (!$room) return 0;
    $rows = db_all("SELECT date_from, date_to FROM nocl_requests WHERE room_id = ? AND id != ? AND status IN ('w_trakcie','potwierdzone','zrealizowane')
                       AND date_from < ? AND date_to > ?", [$roomId, $exclude, $to, $from]);
    $min = (int)$room['beds'];
    for ($t = strtotime($from); $t < strtotime($to); $t += 86400) {
        $d = date('Y-m-d', $t); $n = 0;
        foreach ($rows as $r) if ($r['date_from'] <= $d && $r['date_to'] > $d) $n++;
        $min = min($min, (int)$room['beds'] - $n);
    }
    return max(0, $min);
}

/** Przydział pokoju (Logistyk). Zwraca komunikat błędu albo null. */
function noclAssignRoom(int $requestId, int $roomId, int $by): ?string {
    if (!noclIsLogistyk($by)) return 'Pokoje przydziela Logistyk.';
    $r = noclRequestGet($requestId);
    if (!$r) return 'Nie znaleziono zgłoszenia.';
    if (in_array($r['status'], ['zrealizowane', 'anulowane'], true)) return 'Zgłoszenie jest zamknięte.';
    $room = noclRoomGet($roomId);
    if (!$room || !(int)$room['is_active']) return 'Wybrany pokój nie istnieje albo jest wyłączony.';
    if ((int)$r['needs_accessible'] && !(int)$room['accessible']) return 'Osoba potrzebuje pokoju dostępnego — wybrany pokój nie jest oznaczony jako dostępny.';
    if (noclRoomFreeBeds($roomId, $r['date_from'], $r['date_to'], $requestId) < 1) return 'W tym pokoju nie ma wolnego miejsca w całym terminie.';
    $tmp = $r; $tmp['room_id'] = $roomId;
    $cost = noclRequestCost($tmp);
    $tmp['cost_total'] = (string)$cost;
    if ($e = noclBudgetCheck($tmp, false)) return $e;
    $status = $r['status'] === 'wniosek' ? 'w_trakcie' : $r['status'];
    db()->prepare("UPDATE nocl_requests SET room_id=?, facility_id=?, cost_total=?, status=?, updated_at=? WHERE id=?")
        ->execute([$roomId, (int)$room['facility_id'], (string)$cost, $status, noclNow(), $requestId]);
    noclLog($requestId, 'rooming', 'pokój ' . $room['name'] . ' (' . $room['facility_name'] . '), koszt ' . noclMoneyFmt($cost), 'request', $by);
    return null;
}

function noclUnassignRoom(int $requestId, int $by): ?string {
    if (!noclIsLogistyk($by)) return 'Pokoje przydziela Logistyk.';
    $r = noclRequestGet($requestId);
    if (!$r) return 'Nie znaleziono zgłoszenia.';
    if ($r['status'] === 'potwierdzone') return 'Rezerwacja jest potwierdzona — najpierw cofnij ją do „W trakcie”.';
    db()->prepare("UPDATE nocl_requests SET room_id=NULL, facility_id=NULL, cost_total='0', budget_approved=0, updated_at=? WHERE id=?")->execute([noclNow(), $requestId]);
    noclLog($requestId, 'rooming', 'zdjęto przydział pokoju', 'request', $by);
    return null;
}

/** Podpowiedzi pokoi dla zgłoszenia: wolne miejsca, dostępność, preferowany typ, miasto — najlepsze pierwsze. */
function noclSuggestRooms(array $r): array {
    $out = [];
    $rooms = db_all("SELECT rm.*, f.name AS facility_name, f.city AS facility_city, f.accessible AS facility_accessible
                       FROM nocl_rooms rm JOIN nocl_facilities f ON f.id = rm.facility_id WHERE rm.is_active = 1 AND f.is_active = 1");
    foreach ($rooms as $rm) {
        $free = noclRoomFreeBeds((int)$rm['id'], $r['date_from'], $r['date_to'], (int)$r['id']);
        if ($free < 1) continue;
        if ((int)$r['needs_accessible'] && !(int)$rm['accessible']) continue;
        $score = 0;
        if ($r['room_type_pref'] !== '' && $rm['room_type'] === $r['room_type_pref']) $score += 4;
        if ($r['city'] !== '' && mb_stripos((string)$rm['facility_city'], (string)$r['city']) !== false) $score += 5;
        if ((int)$rm['accessible'] && (int)$r['needs_accessible']) $score += 1;
        // preferuj pokoje już częściowo zajęte w tym terminie (łączenie osób), potem mniejsze
        if ($free < (int)$rm['beds']) $score += 2;
        $rm['free'] = $free; $rm['score'] = $score; $rm['price'] = noclMoney((string)$rm['price_per_night']);
        $out[] = $rm;
    }
    usort($out, fn($a, $b) => [$b['score'], $a['price']] <=> [$a['score'], $b['price']]);
    return array_slice($out, 0, 8);
}

/** Zajętość: pokoje obiektu z osobami w oknie dat → [['room'=>..., 'people'=>[requests]]]. */
function noclRoomingData(int $facilityId, string $from, string $to): array {
    $out = [];
    foreach (noclRoomList($facilityId) as $room) {
        $people = db_all("SELECT id, person_name, person_role, date_from, date_to, needs_accessible, status, roommate_pref
                            FROM nocl_requests WHERE room_id = ? AND status IN ('w_trakcie','potwierdzone','zrealizowane')
                             AND date_from < ? AND date_to > ? ORDER BY date_from, person_name", [(int)$room['id'], $to, $from]);
        $out[] = ['room' => $room, 'people' => $people];
    }
    return $out;
}

/** Zestawienie w formacie AnalyticsExport — Rooming List dla obiektu (bez kodów i uwag o zdrowiu). */
function noclRoomingDataset(int $facilityId, string $from, string $to): array {
    $fac = noclFacilityGet($facilityId) ?: ['name' => '—'];
    $rows = [];
    $roles = noclRoleOsoby();
    foreach (noclRoomingData($facilityId, $from, $to) as $g) {
        foreach ($g['people'] as $p) {
            $rows[] = [$g['room']['name'], noclTypyPokoi()[$g['room']['room_type']] ?? $g['room']['room_type'], (int)$g['room']['beds'], $p['person_name'],
                       $roles[$p['person_role']] ?? $p['person_role'], date('d.m.Y', strtotime($p['date_from'])), date('d.m.Y', strtotime($p['date_to'])),
                       noclNights($p['date_from'], $p['date_to']), (int)$g['room']['accessible'] ? 'tak' : '', noclStatusLabel($p['status'])];
        }
    }
    return ['title' => 'Rooming List — ' . $fac['name'], 'subtitle' => 'Okres ' . date('d.m.Y', strtotime($from)) . ' – ' . date('d.m.Y', strtotime($to)) . '. Lista nie zawiera kodów dostępu ani danych o zdrowiu.',
            'headers' => ['Pokój', 'Typ', 'Miejsca', 'Osoba', 'Rola', 'Przyjazd', 'Wyjazd', 'Noce', 'Pokój dostępny', 'Status'],
            'rows' => $rows, 'num' => [2, 7], 'dec' => [], 'sheet' => 'Rooming', 'notes' => []];
}
