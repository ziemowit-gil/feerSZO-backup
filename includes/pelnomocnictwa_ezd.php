<?php
/**
 * Most: Rejestr pełnomocnictw ↔ EZD Wirtualne biurko.
 *
 * Zakłada koszulkę (sprawę EZD) dla każdego pełnomocnictwa i rejestruje w niej
 * podpisany skan jako pismo wewnętrzne. Celowo odseparowane od rdzenia modułu
 * (includes/pelnomocnictwa.php), który pozostaje niezależny od EZD — wszystkie
 * funkcje są no-op, gdy moduł EZD jest wyłączony, i nie mogą przerwać zapisu
 * pełnomocnictwa (błędy łapane, zwracają null).
 *
 * Powiązanie: ezd_sprawy.ref_type='pelnomocnictwo', ref_id=<id wpisu>
 * (wzorem includes/helpdesk.php). Skrót do koszulki trzymany też w
 * pelnomocnictwa.ezd_sprawa_id dla szybkiego dostępu bez ładowania EZD.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/pelnomocnictwa.php';

/** Czy automatyczne zakładanie koszulek jest aktywne (EZD włączone + nie wyłączone ustawieniem). */
function pelnomocnictwo_ezd_active(): bool {
    return function_exists('module_enabled')
        && module_enabled('ezd_enabled')
        && org_setting('pelnomocnictwa_ezd_auto') !== '0';
}

/**
 * Segregator (teczka EZD) na pełnomocnictwa — z ustawienia
 * `pelnomocnictwa_ezd_teczka_id`, w razie braku znajduje otwarty segregator o
 * symbolu 013 (dawna klasa JRWA pełnomocnictw) lub tworzy nowy i zapamiętuje.
 */
function _peln_ezd_teczka_id(int $user_id): int {
    $set = (int)org_setting('pelnomocnictwa_ezd_teczka_id');
    if ($set) {
        $t = db_one("SELECT id, status FROM ezd_teczki WHERE id=?", [$set]);
        if ($t && ($t['status'] ?? '') !== 'closed') return $set;
    }
    $t = db_one("SELECT id FROM ezd_teczki WHERE symbol='013' AND status='open' ORDER BY rok DESC, id DESC LIMIT 1");
    if ($t) { org_setting_set('pelnomocnictwa_ezd_teczka_id', (string)$t['id']); return (int)$t['id']; }

    $jrwa = db_one("SELECT id FROM ezd_jrwa WHERE symbol='013' LIMIT 1");
    $tid  = ezd_teczka_create([
        'jrwa_id'  => $jrwa['id'] ?? null,
        'symbol'   => '013',
        'title'    => 'Pełnomocnictwa',
        'rok'      => (int)date('Y'),
        'owner_id' => null,
    ], $user_id);
    org_setting_set('pelnomocnictwa_ezd_teczka_id', (string)$tid);
    return $tid;
}

/**
 * Zapewnia koszulkę EZD dla wpisu (idempotentnie). Zwraca id sprawy lub null,
 * gdy EZD wyłączone / wpis nie istnieje / wystąpił błąd.
 */
function pelnomocnictwo_ensure_koszulka(int $peln_id, ?int $user_id): ?int {
    if (!pelnomocnictwo_ezd_active()) return null;
    require_once __DIR__ . '/ezd.php';

    $row = pelnomocnictwo_get($peln_id);
    if (!$row) return null;
    $uid = (int)($user_id ?: ($row['created_by'] ?? 0));

    // Już powiązana?
    if (!empty($row['ezd_sprawa_id']) && ezd_sprawa_get((int)$row['ezd_sprawa_id'])) {
        return (int)$row['ezd_sprawa_id'];
    }
    $existing = ezd_sprawy_by_ref('pelnomocnictwo', $peln_id);
    if ($existing) {
        $sid = (int)$existing[0]['id'];
        db()->prepare("UPDATE pelnomocnictwa SET ezd_sprawa_id=? WHERE id=?")->execute([$sid, $peln_id]);
        return $sid;
    }

    try {
        $tid        = _peln_ezd_teczka_id($uid);
        $rodzaj_lbl = pelnomocnictwo_rodzaj_label($row['rodzaj'] ?? 'ogolne');
        $zakres     = ($row['rodzaj'] ?? '') === 'korespondencja'
            ? pelnomocnictwo_kor_opis($row)
            : implode("\n", pelnomocnictwo_zakres_items($row));

        $desc = "Pełnomocnictwo {$row['numer']} ({$rodzaj_lbl})\n"
              . "Mocodawca: {$row['mocodawca']}\n"
              . "Pełnomocnik: {$row['pelnomocnik']}" . ($row['pelnomocnik_pesel'] ? " (PESEL: {$row['pelnomocnik_pesel']})" : '') . "\n"
              . ($row['data_udzielenia'] ? "Udzielono: {$row['data_udzielenia']}\n" : '')
              . ($row['data_waznosci']   ? "Ważne do: {$row['data_waznosci']}\n" : "Bezterminowe\n")
              . ($zakres ? "\nZakres:\n{$zakres}\n" : '')
              . "\n⚠ Wymaga wgrania podpisanego skanu do rejestru pełnomocnictw.";

        $sid = ezd_sprawa_create([
            'teczka_id'   => $tid,
            'title'       => "Pełnomocnictwo {$row['numer']} — {$row['pelnomocnik']}",
            'description' => $desc,
            'status'      => 'open',
            'priority'    => 'normal',
            'owner_id'    => $uid ?: null,
            'deadline'    => ($row['data_waznosci'] ?: null),
            'ref_type'    => 'pelnomocnictwo',
            'ref_id'      => $peln_id,
        ], $uid);

        db()->prepare("UPDATE pelnomocnictwa SET ezd_sprawa_id=? WHERE id=?")->execute([$sid, $peln_id]);
        pelnomocnictwo_log($peln_id, 'ezd_koszulka', "Założono koszulkę w EZD (sprawa #{$sid}).", $user_id);
        return $sid;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Rejestruje wgrany podpisany skan jako pismo wewnętrzne w koszulce EZD
 * i dołącza plik jako załącznik. Wywoływane po udanym uploadzie.
 */
function pelnomocnictwo_ezd_register_signed(int $peln_id, ?int $user_id): ?int {
    if (!pelnomocnictwo_ezd_active()) return null;
    require_once __DIR__ . '/ezd.php';

    $row = pelnomocnictwo_get($peln_id);
    if (!$row || empty($row['dokument_plik'])) return null;

    $sid = pelnomocnictwo_ensure_koszulka($peln_id, $user_id);
    if (!$sid) return null;
    $uid = (int)($user_id ?: ($row['created_by'] ?? 0));

    try {
        $typ_lbl = ($row['dokument_typ'] ?? '') === 'odwolanie'
            ? 'Podpisane odwołanie pełnomocnictwa'
            : 'Podpisane pełnomocnictwo';
        $pid = ezd_pismo_create([
            'sprawa_id'  => $sid,
            'kierunek'   => 'wewnetrzne',
            'title'      => "{$typ_lbl} {$row['numer']}",
            'tresc'      => 'Skan podpisanego dokumentu dołączony z rejestru pełnomocnictw.',
            'nadawca'    => $row['podpisujacy'] ?: $row['mocodawca'],
            'odbiorca'   => $row['pelnomocnik'],
            'data_pisma' => $row['data_udzielenia'] ?: date('Y-m-d'),
            'status'     => 'zakonczone',
            'owner_id'   => $uid ?: null,
        ], $uid);

        $src = dirname(__DIR__) . '/uploads/pelnomocnictwa/' . $row['dokument_plik'];
        if (is_file($src) && function_exists('ezd_attach_path')) {
            ezd_attach_path($src, $row['dokument_oryginal_nazwa'] ?: $row['dokument_plik'], $sid, $pid, $uid);
        }
        pelnomocnictwo_log($peln_id, 'ezd_pismo', "Podpisany skan zarejestrowany w koszulce (pismo #{$pid}).", $user_id);
        return $pid;
    } catch (\Throwable $e) {
        return null;
    }
}

/** Odnośnik do koszulki EZD wpisu (lub null). */
function pelnomocnictwo_ezd_url(array $row): ?string {
    if (empty($row['ezd_sprawa_id'])) return null;
    return APP_URL . '/ezd/sprawy/view.php?id=' . (int)$row['ezd_sprawa_id'];
}
