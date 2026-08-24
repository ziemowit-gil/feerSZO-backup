<?php
/**
 * includes/podmioty_import.php — masowy import podmiotów do CRM po NIP i REGON.
 *
 * Wklejasz listę numerów, system sam dociąga dane z rejestrów i zakłada (albo
 * uzupełnia) kartoteki organizacji. Ręczne przepisywanie nazw i adresów z KRS-u
 * przy kilkudziesięciu podmiotach to godziny roboty i literówki w NIP-ach.
 *
 * Źródła danych, w kolejności pytania:
 *   1. Rejestr REGON (GUS BIR) — jeśli w ustawieniach jest bezpłatny klucz
 *      `gus_bir_key`. Jako jedyny zna podmioty spoza rejestru VAT: fundacje,
 *      stowarzyszenia, szkoły, jednostki budżetowe.
 *   2. CEIDG — dla działalności gospodarczych (gdy jest klucz `ceidg_api_key`).
 *   3. Biała Lista VAT MF — bezpłatnie i bez klucza, ale TYLKO podatnicy VAT.
 * Bez klucza GUS import nadal działa; po prostu część organizacji non-profit
 * wróci jako „nie znaleziono" i trzeba je wpisać ręcznie.
 *
 * Import jest DWUETAPOWY: najpierw podgląd (co zostanie założone, co uzupełnione,
 * czego nie znaleziono), dopiero potem zapis. Nikt nie wsypuje sobie do bazy
 * 200 rekordów jednym kliknięciem bez zobaczenia, co z tego wyszło.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/crm.php';
require_once __DIR__ . '/ceidg.php';
require_once __DIR__ . '/gus_bir.php';

const PODMIOT_IMPORT_MAX = 200;      // ile numerów w jednej paczce
const PODMIOT_API_PAUSE  = 120000;   // 0,12 s przerwy między zapytaniami (µs)

/** Suma kontrolna NIP — odsiewa literówki, zanim pójdą do API. */
function podmiot_nip_valid(string $nip): bool {
    $nip = preg_replace('/\D/', '', $nip);
    if (strlen($nip) !== 10) return false;
    $w = [6, 5, 7, 2, 3, 4, 5, 6, 7];
    $sum = 0;
    for ($i = 0; $i < 9; $i++) $sum += $w[$i] * (int)$nip[$i];
    return ($sum % 11) === (int)$nip[9];
}

/** Suma kontrolna REGON (9 lub 14 cyfr). */
function podmiot_regon_valid(string $regon): bool {
    $r = preg_replace('/\D/', '', $regon);
    $len = strlen($r);
    if ($len !== 9 && $len !== 14) return false;
    $w = $len === 9 ? [8, 9, 2, 3, 4, 5, 6, 7] : [2, 4, 8, 5, 0, 9, 7, 3, 6, 1, 2, 4, 8];
    $sum = 0;
    for ($i = 0, $n = $len - 1; $i < $n; $i++) $sum += $w[$i] * (int)$r[$i];
    $check = $sum % 11;
    if ($check === 10) $check = 0;
    return $check === (int)$r[$len - 1];
}

/** Rozpoznaje, czy w linii jest NIP czy REGON (po długości i sumie kontrolnej). */
function podmiot_detect_kind(string $raw): string {
    $d = preg_replace('/\D/', '', $raw);
    if (strlen($d) === 10) return 'nip';
    if (strlen($d) === 9 || strlen($d) === 14) return 'regon';
    return '';
}

/** Wyszukanie po REGON: najpierw rejestr REGON (GUS), potem Biała Lista VAT. */
function podmiot_lookup_regon(string $regon): array {
    $regon = preg_replace('/\D/', '', $regon);

    if (gus_bir_available()) {
        $g = gus_bir_lookup('regon', $regon);
        if (!isset($g['error'])) return $g;
    }
    $url = 'https://wl-api.mf.gov.pl/api/search/regon/' . urlencode($regon) . '?date=' . date('Y-m-d');
    $ctx = stream_context_create(['http' => [
        'method' => 'GET', 'header' => "Accept: application/json\r\n",
        'ignore_errors' => true, 'timeout' => 10,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) return ['error' => 'Brak połączenia z serwisem weryfikacji REGON.'];

    $data = json_decode($resp, true);
    $subj = $data['result']['subject'] ?? null;
    if (!$subj) return ['error' => $data['message'] ?? 'Nie znaleziono podmiotu o podanym REGON.'];

    return [
        'nip'     => (string)($subj['nip'] ?? ''),
        'regon'   => (string)($subj['regon'] ?? $regon),
        'krs'     => (string)($subj['krs'] ?? ''),
        'nazwa'   => trim((string)($subj['name'] ?? '')),
        'adres'   => trim((string)($subj['workingAddress'] ?? $subj['residenceAddress'] ?? '')),
        'status'  => (string)($subj['statusVat'] ?? ''),
        'aktywna' => ($subj['statusVat'] ?? '') === 'Czynny',
    ];
}

/** Wyszukanie po NIP (GUS → CEIDG → Biała Lista), znormalizowane do jednego kształtu. */
function podmiot_lookup_nip(string $nip): array {
    if (gus_bir_available()) {
        $g = gus_bir_lookup('nip', $nip);
        if (!isset($g['error'])) return $g;
    }

    $r = ceidg_lookup($nip);
    if (isset($r['error'])) return $r;
    return [
        'nip'     => (string)($r['nip'] ?? $nip),
        'regon'   => (string)($r['regon'] ?? ''),
        'krs'     => (string)($r['krs'] ?? ''),
        'nazwa'   => trim((string)($r['nazwa'] ?? '')),
        'adres'   => trim((string)($r['adres'] ?? '')),
        'status'  => (string)($r['status'] ?? ''),
        'aktywna' => !empty($r['aktywna']),
    ];
}

/** Istniejąca kartoteka o tym NIP/REGON (null = nowy podmiot). */
function podmiot_existing(string $nip, string $regon): ?array {
    $nip   = preg_replace('/\D/', '', $nip);
    $regon = preg_replace('/\D/', '', $regon);
    try {
        if ($nip !== '') {
            $r = db_one("SELECT id, imie_nazwisko, email, telefon, adres, regon FROM crm_contacts
                         WHERE crm_active=1 AND REPLACE(REPLACE(nip,'-',''),' ','')=? LIMIT 1", [$nip]);
            if ($r) return $r;
        }
        if ($regon !== '') {
            $r = db_one("SELECT id, imie_nazwisko, email, telefon, adres, regon FROM crm_contacts
                         WHERE crm_active=1 AND REPLACE(REPLACE(regon,'-',''),' ','')=? LIMIT 1", [$regon]);
            if ($r) return $r;
        }
    } catch (\Throwable $e) {}
    return null;
}

/**
 * Rozkłada wklejoną listę na wiersze.
 * Format linii: `NUMER[;e-mail][;telefon]` — separator `;`, `,` albo tabulator.
 */
function podmiot_parse_input(string $raw): array {
    $out  = [];
    $seen = [];
    foreach (preg_split('/\R/', $raw) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;

        $parts = preg_split('/[;,\t]/', $line);
        $id    = preg_replace('/\D/', '', (string)($parts[0] ?? ''));
        if ($id === '') continue;
        if (isset($seen[$id])) continue;         // ta sama firma wklejona dwa razy
        $seen[$id] = true;

        $out[] = [
            'raw'     => $line,
            'id'      => $id,
            'kind'    => podmiot_detect_kind($id),
            'email'   => trim((string)($parts[1] ?? '')),
            'telefon' => trim((string)($parts[2] ?? '')),
        ];
        if (count($out) >= PODMIOT_IMPORT_MAX) break;
    }
    return $out;
}

/**
 * Sprawdza listę w rejestrach — bez zapisu do bazy.
 *
 * @return array wiersze: ['id','kind','status'=>new|update|error|invalid,
 *                         'data'=>?array, 'existing'=>?array, 'error'=>string]
 */
function podmiot_preview(array $rows): array {
    $out = [];
    foreach ($rows as $r) {
        $item = $r + ['status' => 'error', 'data' => null, 'existing' => null, 'error' => ''];

        if ($r['kind'] === '') {
            $item['status'] = 'invalid';
            $item['error']  = 'To nie wygląda na NIP (10 cyfr) ani REGON (9 lub 14 cyfr).';
            $out[] = $item; continue;
        }
        if ($r['kind'] === 'nip' && !podmiot_nip_valid($r['id'])) {
            $item['status'] = 'invalid';
            $item['error']  = 'Niepoprawna suma kontrolna NIP — sprawdź numer.';
            $out[] = $item; continue;
        }
        if ($r['kind'] === 'regon' && !podmiot_regon_valid($r['id'])) {
            $item['status'] = 'invalid';
            $item['error']  = 'Niepoprawna suma kontrolna REGON — sprawdź numer.';
            $out[] = $item; continue;
        }

        $data = $r['kind'] === 'nip' ? podmiot_lookup_nip($r['id']) : podmiot_lookup_regon($r['id']);
        usleep(PODMIOT_API_PAUSE);               // nie zasypujemy publicznego API

        if (isset($data['error'])) {
            $item['error'] = $data['error'];
            $out[] = $item; continue;
        }
        if (($data['nazwa'] ?? '') === '') {
            $item['error'] = 'Rejestr nie zwrócił nazwy podmiotu.';
            $out[] = $item; continue;
        }

        $item['data']     = $data;
        $item['existing'] = podmiot_existing($data['nip'] ?? '', $data['regon'] ?? '');
        $item['status']   = $item['existing'] ? 'update' : 'new';
        $out[] = $item;
    }
    return $out;
}

/**
 * Zapisuje wynik podglądu do kartotek CRM.
 *
 * @param array $preview   wiersze z podmiot_preview()
 * @param array $opt       ['update_existing'=>bool, 'status'=>string, 'owner_id'=>int, 'group_id'=>int]
 * @return array ['created'=>int, 'updated'=>int, 'skipped'=>int, 'ids'=>[…]]
 */
function podmiot_import_commit(array $preview, array $opt = []): array {
    $uid    = (int)(current_user()['id'] ?? 0);
    $status = (string)($opt['status'] ?? 'nowy');
    $owner  = (int)($opt['owner_id'] ?? 0);
    $group  = (int)($opt['group_id'] ?? 0);
    $upd_ok = !empty($opt['update_existing']);

    $res = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'ids' => []];

    foreach ($preview as $row) {
        if (!in_array($row['status'], ['new', 'update'], true) || empty($row['data'])) {
            $res['skipped']++; continue;
        }
        $d = $row['data'];

        // Dane z rejestru nie nadpisują tego, co wpisał człowiek — uzupełniają puste pola
        $fields = [
            'nip'          => $d['nip']   ?: null,
            'regon'        => $d['regon'] ?: null,
            'krs'          => $d['krs']   ?: null,
            'adres'        => $d['adres'] ?: null,
            'updated_at'   => date('Y-m-d H:i:s'),
        ];
        if ($row['email']   !== '') $fields['email']   = $row['email'];
        if ($row['telefon'] !== '') $fields['telefon'] = $row['telefon'];

        try {
            if ($row['existing']) {
                if (!$upd_ok) { $res['skipped']++; continue; }
                $id  = (int)$row['existing']['id'];
                $set = [];
                foreach ($fields as $k => $v) {
                    if ($v === null || $v === '') continue;
                    if ($k !== 'updated_at' && trim((string)($row['existing'][$k] ?? '')) !== '') continue;
                    $set[$k] = $v;
                }
                if ($set) {
                    $sql = implode(',', array_map(static fn($k) => "$k=?", array_keys($set)));
                    db()->prepare("UPDATE crm_contacts SET {$sql} WHERE id=?")
                        ->execute(array_merge(array_values($set), [$id]));
                }
                $res['updated']++;
            } else {
                $id = (int)db_insert('crm_contacts', array_filter($fields + [
                    'type'            => 'organizacja',
                    'status'          => $status,
                    'imie_nazwisko'   => mb_substr($d['nazwa'], 0, 200),
                    'organizacja'     => mb_substr($d['nazwa'], 0, 200),
                    'avatar_initials' => CrmManager::makeInitials($d['nazwa']),
                    'source'          => 'import_rejestr',
                    'owner_id'        => $owner ?: null,
                    'crm_active'      => 1,
                    'created_by'      => $uid ?: null,
                    'created_at'      => date('Y-m-d H:i:s'),
                ], static fn($v) => $v !== null));
                $res['created']++;
            }

            $res['ids'][] = $id;

            if ($group > 0) {
                try {
                    db()->prepare("INSERT OR IGNORE INTO crm_group_members (group_id, contact_id, added_at)
                                   VALUES (?,?,?)")
                        ->execute([$group, $id, date('Y-m-d H:i:s')]);
                } catch (\Throwable $e) {}
            }
        } catch (\Throwable $e) {
            error_log('[podmiot_import_commit] ' . $e->getMessage());
            $res['skipped']++;
        }
    }
    return $res;
}
