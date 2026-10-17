<?php
/**
 * modules/skierowania_badania/logic/skierowaniaEzd.php — rejestracja skierowania w EZD.
 *
 * Skierowanie trafia do koszulki umowy (contract_ezd: umowa ↔ koszulka); gdy umowa nie ma koszulki,
 * zakładamy ją i wiążemy (contract_ezd_create_and_link). W koszulce powstaje pismo wewnętrzne z PDF
 * skierowania (bez korespondencji/książki nadawczej — skierowanie wręcza się osobie). Ponowna rejestracja
 * po zmianach dodaje kolejne pismo z nową wersją PDF. Automatycznie przy wystawieniu, gdy skb_ezd_auto != 0.
 */

require_once __DIR__ . '/skierowania.php';
require_once dirname(__DIR__) . '/lib/skierowaniePdf.php';

function skbEzdActive(): bool {
    return function_exists('module_enabled') && module_enabled('ezd_enabled');
}

/** @return int|string id koszulki albo komunikat błędu */
function skbEzdRegister(int $id, array $user): int|string {
    if (!skbEzdActive()) return 'Moduł EZD jest wyłączony.';
    $s = skbGet($id);
    if (!$s) return 'Nie znaleziono skierowania.';
    if ($s['status'] === 'anulowane') return 'Anulowanego skierowania nie rejestrujemy w EZD.';
    require_once dirname(__DIR__, 3) . '/includes/ezd.php';
    require_once dirname(__DIR__, 3) . '/includes/contract_ezd.php';
    $type = (string)$s['contract_type'];
    $cid  = (int)$s['contract_id'];
    $uid  = (int)($user['id'] ?? 0) ?: 1;
    try {
        $c = skbContract($type, $cid);
        if (!$c) return 'Umowa nie istnieje.';
        $sid = contract_ezd_get_sprawa_id($type, $cid);
        $sprawa = $sid ? ezd_sprawa_get($sid) : null;
        if (!$sprawa || ($sprawa['status'] ?? '') === 'closed') {
            if ($sprawa) return 'Koszulka EZD umowy (' . ($sprawa['znak_sprawy'] ?? '#' . $sid) . ') jest zamknięta — otwórz ją albo powiąż umowę z inną.';
            $sid = contract_ezd_create_and_link($type, $cid, $uid, $c);
        }
        $again = !empty($s['ezd_pismo_id']);
        $pid = ezd_pismo_create([
            'sprawa_id'     => $sid,
            'kierunek'      => 'wewnetrzne',
            'title'         => 'Skierowanie na badania lekarskie ' . SKB_RODZAJE[$s['rodzaj']] . ' ' . $s['numer'] . ' — ' . $s['imie_nazwisko'] . ($again ? ' (wersja po zmianach)' : ''),
            'tresc'         => 'Skierowanie na badania lekarskie (medycyna pracy) do umowy: ' . SKB_TYPY[$type]
                             . (skbContractNumber($c) !== '' ? ' nr ' . skbContractNumber($c) : '') . '. Stanowisko: ' . $s['stanowisko']
                             . '. Liczba czynników: ' . (int)$s['liczba_czynnikow'] . '. PDF dołączony automatycznie z modułu Skierowania na badania.',
            'nadawca'       => (string)org_setting('org_name'),
            'odbiorca'      => (string)$s['imie_nazwisko'],
            'data_pisma'    => (string)$s['data_wystawienia'],
            'status'        => 'zakonczone',
            'owner_id'      => (int)($user['id'] ?? 0) ?: null,
            'rodzaj_medium' => 'inne',
        ], $uid);
        $tmp = tempnam(sys_get_temp_dir(), 'skb_');
        try {
            file_put_contents($tmp, skbPdf($s));
            ezd_attach_path($tmp, skbPdfFilename($s), $sid, $pid, $uid);
        } finally { @unlink($tmp); }
        db()->prepare("UPDATE skierowania_badania SET ezd_sprawa_id=?, ezd_pismo_id=?, ezd_at=datetime('now') WHERE id=?")->execute([$sid, $pid, $id]);
        audit_log('skierowania.ezd', ['id' => $id, 'sprawa_id' => $sid, 'pismo_id' => $pid], (int)($user['id'] ?? 0) ?: null);
        return $sid;
    } catch (\Throwable $e) {
        error_log('skbEzdRegister #' . $id . ': ' . $e->getMessage());
        return 'Nie udało się zarejestrować w EZD: ' . $e->getMessage();
    }
}

function skbEzdUrl(?int $sprawaId): ?string {
    return $sprawaId ? rtrim(APP_URL, '/') . '/ezd/sprawy/view.php?id=' . $sprawaId : null;
}
