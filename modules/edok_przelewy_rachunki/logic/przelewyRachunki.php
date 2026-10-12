<?php
/**
 * modules/edok_przelewy_rachunki/logic/przelewyRachunki.php — przelewy za rachunki do umów zlecenie.
 *
 * Zatwierdzanie rachunków odbywa się na papierze, więc przelewy NIE czekają na obieg EODoK:
 * wybiera się rachunki z rejestru (zlecenie_rachunki) za miesiąc i generuje plik przelewów
 * (iPKO biznes / inne banki — te same eksportery co Preliminarz, edok_przelewy_export()).
 *
 * Zasady:
 *  - kwota przelewu = NETTO rachunku (do wypłaty, po składkach i zaliczce na PIT) — bez netto rachunek
 *    nie jest do wyboru (brutto nie jest kwotą wypłaty);
 *  - NRB odbiorcy = rachunek bankowy z umowy (26 cyfr), odbiorca = zleceniobiorca;
 *  - tytuł „WYNAGRODZENIE MM/RRRR - umowa nr {Rejestr Umów}” (okres rachunku; umowa techniczna bez numeru);
 *  - pomijane: testowe, odrzucone, zapłacone, czekające na eObieg w EODoK i będące w KDOK; rachunek w EODoK
 *    zaakceptowany (np. obieg papierowy) jest do wyboru — dokument EODoK dostaje status płatności „zlecony”
 *    (albo „opłacony”), więc Preliminarz nie zapłaci go drugi raz;
 *  - każdy plik zapisany w historii; rachunek ujęty w pliku jest oznaczony — ponowne ujęcie wymaga potwierdzenia.
 */

require_once dirname(__DIR__, 3) . '/includes/zlecenie_rachunki.php';
require_once dirname(__DIR__, 3) . '/includes/edok.php';

function prz_rach_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS zlecenie_rachunki_przelewy (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        okres        TEXT,
        format       TEXT,
        nrb_z        TEXT,
        plik_nazwa   TEXT,
        plik_path    TEXT,
        liczba       INTEGER,
        suma         REAL,
        rachunek_ids TEXT,
        oznaczono_zaplacone INTEGER DEFAULT 0,
        created_by   INTEGER,
        created_name TEXT,
        created_at   DATETIME
    )");
    foreach (['przelew_id' => 'INTEGER', 'przelew_at' => 'DATETIME'] as $col => $def) {
        try { db()->exec("ALTER TABLE zlecenie_rachunki ADD COLUMN {$col} {$def}"); } catch (\Throwable $e) {}
    }
}

/** Czy rachunek obejmuje miesiąc RRRR-MM (okres od–do; bez okresu — miesiąc wystawienia). */
function prz_rach_w_okresie(array $r, string $ym): bool {
    $od = (string)($r['okres_od'] ?? '');
    $do = (string)($r['okres_do'] ?? '') ?: $od;
    if ($od === '') return substr((string)($r['data_wystawienia'] ?? ''), 0, 7) === $ym;
    return $od <= $ym && $ym <= $do;
}

/**
 * Rachunki do przelewów za miesiąc (pusty $ym = wszystkie niezapłacone).
 * Każdy wiersz ma `problem` (null = można wybrać) i `uwaga` (informacja, np. był już w pliku).
 */
function prz_rach_lista(string $ym = ''): array {
    prz_rach_migrate();
    $rows = db_all(
        "SELECT r.*, u.imie_nazwisko, u.numer_umowy, u.nr_rejestru, u.rachunek_bankowy AS umowa_nrb, u.id AS umowa_id
           FROM zlecenie_rachunki r JOIN umowy_zlecenie u ON u.id = r.contract_id
          WHERE COALESCE(r.contract_type, 'zlecenie') = 'zlecenie'
            AND COALESCE(r.test_mode, 0) = 0 AND r.status <> 'odrzucony'
          ORDER BY u.imie_nazwisko, r.id"
    );
    $out = [];
    foreach ($rows as $r) {
        if ($ym !== '' && !prz_rach_w_okresie($r, $ym)) continue;
        if ($ym === '' && $r['status'] === 'zaplacony') continue;
        $nrb = preg_replace('/\D/', '', (string)$r['umowa_nrb']);
        $r['nrb']    = $nrb;
        $r['umowa_ref'] = rachunek_umowa_ref($r);
        $doc = (int)($r['edok_doc_id'] ?? 0) > 0 ? db_one("SELECT id, number, status, status_platnosci, obieg_papierowy FROM edok_documents WHERE id = ?", [(int)$r['edok_doc_id']]) : null;
        $r['edok'] = $doc;
        $edok_plat = $doc ? ($doc['status_platnosci'] ?: 'nowy') : null;
        $r['problem'] = match (true) {
            $r['status'] === 'zaplacony'                             => 'Zapłacony',
            !empty($r['kdok_doc_id'])                                 => 'W obiegu KDOK — płatność z Preliminarza',
            $doc !== null && $doc['status'] !== 'zaakceptowany'       => 'Czeka na akceptację w eObiegu EODoK (' . $doc['number'] . ')',
            $edok_plat === 'oplacony'                                 => 'Opłacony w EODoK (' . $doc['number'] . ')',
            ($r['kwota_netto'] ?? null) === null || (float)$r['kwota_netto'] <= 0 => 'Brak kwoty netto — uzupełnij w rachunku',
            strlen($nrb) !== 26                                      => 'Brak numeru konta w umowie',
            default                                                  => null,
        };
        $r['uwaga'] = !empty($r['przelew_id']) ? 'Już w pliku przelewów z ' . date('d.m.Y H:i', strtotime((string)$r['przelew_at']))
            : ($edok_plat === 'zlecony' ? 'Przelew już zlecony z Preliminarza EODoK (' . $doc['number'] . ')'
            : ($doc ? 'EODoK ' . $doc['number'] . (!empty($doc['obieg_papierowy']) ? ' (obieg papierowy)' : '') : null));
        $r['ponowny'] = !empty($r['przelew_id']) || $edok_plat === 'zlecony';
        $out[] = $r;
    }
    return $out;
}

/** Rachunek → „dokument” dla eksporterów EODoK (wiersz wypłaty: kwota netto, tytuł WYNAGRODZENIE). */
function prz_rach_doc(array $r): array {
    $okres = preg_match('/^\d{4}-\d{2}$/', (string)($r['okres_od'] ?? '')) ? (string)$r['okres_od'] : substr((string)($r['data_wystawienia'] ?: date('Y-m-d')), 0, 7);
    return [
        'id'               => 0,
        'kierunek'         => 'wydatek',
        'typ_dokumentu'    => 'rachunek',
        '_wyplata'         => true,   // tytuł „WYNAGRODZENIE MM/RRRR[ - umowa nr …]”
        'number'           => (string)($r['numer'] ?? ''), // referencja w pliku banku
        'nr_faktury'       => (string)($r['numer'] ?? ''),
        'kontrahent_nazwa' => (string)$r['imie_nazwisko'],
        'rachunek_bankowy' => (string)$r['nrb'],
        'kwota_brutto'     => number_format((float)$r['kwota_netto'], 2, ',', ''),
        'kwota_do_wyplaty' => '',
        'waluta'           => 'PLN',
        'okres'            => $okres,
        'umowa_numer'      => (string)$r['umowa_ref'],
    ];
}

/**
 * Generuje plik przelewów dla wybranych rachunków.
 *
 * @return array{ok:bool, msg:string, plik?:array{nazwa:string,content:string}, przelew_id?:int}
 */
function prz_rach_generuj(array $rids, string $nrb_z, string $format, bool $oznacz_zaplacone, bool $potwierdz_ponowne, string $ym, array $user): array {
    prz_rach_migrate();
    $nrb_z = preg_replace('/\D/', '', $nrb_z);
    $konta = [];
    foreach (edok_rachunki_list() as $k) $konta[preg_replace('/\D/', '', (string)$k['nrb'])] = $k;
    if (!isset($konta[$nrb_z])) return ['ok' => false, 'msg' => 'Wybierz rachunek organizacji, z którego mają pójść przelewy.'];
    if (!isset(EDOK_PRZELEWY_FORMATY[$format])) $format = 'auto';

    $wybrane = array_flip(array_map('intval', $rids));
    $rows = array_values(array_filter(prz_rach_lista($ym), fn($r) => isset($wybrane[(int)$r['id']])));
    if (!$rows) return ['ok' => false, 'msg' => 'Zaznacz co najmniej jeden rachunek.'];
    $zle = array_filter($rows, fn($r) => $r['problem'] !== null);
    if ($zle) return ['ok' => false, 'msg' => 'Nie można wygenerować przelewu dla: ' . implode(', ', array_map(fn($r) => $r['imie_nazwisko'] . ' (' . $r['problem'] . ')', $zle)) . '.'];
    $ponowne = array_filter($rows, fn($r) => !empty($r['ponowny']));
    if ($ponowne && !$potwierdz_ponowne) {
        return ['ok' => false, 'msg' => 'Rachunki ' . implode(', ', array_map(fn($r) => $r['imie_nazwisko'] . ' ' . $r['numer'], $ponowne))
            . ' były już w pliku przelewów — zaznacz „Potwierdzam ponowne ujęcie”, jeśli to nie podwójna płatność.'];
    }

    $docs = array_map('prz_rach_doc', $rows);
    $plik = edok_przelewy_export($docs, $nrb_z, $format);
    if ($plik['content'] === '') return ['ok' => false, 'msg' => 'Eksporter nie zwrócił żadnego przelewu — sprawdź numery kont.'];

    $suma  = array_sum(array_map(fn($r) => round((float)$r['kwota_netto'], 2), $rows));
    $nazwa = 'Przelewy_rachunki_' . ($ym !== '' ? $ym . '_' : '') . $plik['prefix'] . '_' . substr($nrb_z, -4) . '_' . date('Y-m-d_His') . '.' . $plik['ext'];
    $dir   = rtrim(UPLOAD_DIR, '/') . '/przelewy_rachunki/';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $rel   = is_dir($dir) && file_put_contents($dir . $nazwa, $plik['content']) !== false ? 'przelewy_rachunki/' . $nazwa : null;

    $pid = (int)db_insert('zlecenie_rachunki_przelewy', [
        'okres'        => $ym !== '' ? $ym : null,
        'format'       => $plik['prefix'],
        'nrb_z'        => $nrb_z,
        'plik_nazwa'   => $nazwa,
        'plik_path'    => $rel,
        'liczba'       => count($rows),
        'suma'         => round($suma, 2),
        'rachunek_ids' => json_encode(array_map(fn($r) => (int)$r['id'], $rows)),
        'oznaczono_zaplacone' => $oznacz_zaplacone ? 1 : 0,
        'created_by'   => (int)($user['id'] ?? 0) ?: null,
        'created_name' => (string)($user['name'] ?? ''),
        'created_at'   => date('Y-m-d H:i:s'),
    ]);

    $uid = (int)($user['id'] ?? 0) ?: null;
    foreach ($rows as $r) {
        update_rachunek((int)$r['id'], ['przelew_id' => $pid, 'przelew_at' => date('Y-m-d H:i:s')]);
        if ($oznacz_zaplacone) rachunek_set_status((int)$r['id'], 'zaplacony', $uid);
        // Rachunek w EODoK: status płatności dokumentu — Preliminarz nie zapłaci go drugi raz.
        if (!empty($r['edok'])) {
            $prev = $r['edok']['status_platnosci'] ?: 'nowy';
            $nowy = $oznacz_zaplacone ? 'oplacony' : 'zlecony';
            if ($prev !== $nowy) {
                db_exec("UPDATE edok_documents SET status_platnosci = ?, updated_at = datetime('now') WHERE id = ?", [$nowy, (int)$r['edok']['id']]);
                edok_log((int)$r['edok']['id'], 'status_platnosci', '', $prev, $nowy,
                    'Przelew w pliku „Przelewy za rachunki” #' . $pid . ' (netto ' . number_format((float)$r['kwota_netto'], 2, ',', ' ') . ' zł) — status płatności: '
                    . ($nowy === 'oplacony' ? 'Opłacony' : 'Zlecony do banku') . '.');
            }
        }
        try {
            log_contract_action('zlecenie', (int)$r['contract_id'], (int)$uid,
                'rachunek_przelew', 'Rachunek ' . ($r['numer'] ?: '#' . $r['id']) . ' w pliku przelewów #' . $pid
                . ' (netto ' . number_format((float)$r['kwota_netto'], 2, ',', ' ') . ' zł' . ($oznacz_zaplacone ? ', oznaczony jako zapłacony' : '') . ').');
        } catch (\Throwable $e) {}
    }

    return [
        'ok'   => true,
        'msg'  => 'Plik przelewów: ' . prz_rach_ile(count($rows)) . ' na ' . number_format($suma, 2, ',', ' ') . ' zł.',
        'plik' => ['nazwa' => $nazwa, 'content' => $plik['content']],
        'przelew_id' => $pid,
    ];
}

/** „1 przelew”, „3 przelewy”, „5 przelewów”. */
function prz_rach_ile(int $n): string {
    $f = $n === 1 ? 'przelew' : (($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 12 || $n % 100 > 14)) ? 'przelewy' : 'przelewów');
    return $n . ' ' . $f;
}

/** Historia wygenerowanych plików (najnowsze pierwsze). */
function prz_rach_historia(int $limit = 30): array {
    prz_rach_migrate();
    return db_all("SELECT * FROM zlecenie_rachunki_przelewy ORDER BY id DESC LIMIT " . max(1, $limit));
}
