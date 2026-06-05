<?php
/**
 * includes/foundation_report.php
 * Moduł Sprawozdania z działalności fundacji (MRPiPS / Min.Sprawiedliwości).
 * Podstawa: rozporządzenie MS z 20.12.2022 (Dz.U. poz. 2791).
 */

// ── Migracja ──────────────────────────────────────────────────────────────────
function freport_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS foundation_reports (
            id                  INTEGER PRIMARY KEY AUTOINCREMENT,
            rok                 INTEGER NOT NULL,
            status              TEXT NOT NULL DEFAULT 'roboczy',  -- roboczy|złożone|zatwierdzone

            -- I. Dane fundacji (uzupełniane ręcznie / z org_settings)
            organ_nadzoru       TEXT,
            -- II. Charakterystyka działalności
            ii_zasady_formy     TEXT,
            ii_zdarzenia_prawne TEXT,
            ii_dzialalnosc_gosp INTEGER NOT NULL DEFAULT 0,
            ii_pkd             TEXT,
            ii_uchwaly         TEXT,

            -- III. Przychody (auto+ręczne, JSON)
            iii_json            TEXT NOT NULL DEFAULT '{}',

            -- IV. Koszty (JSON)
            iv_json             TEXT NOT NULL DEFAULT '{}',

            -- V. Zatrudnienie (auto+ręczne)
            v_json              TEXT NOT NULL DEFAULT '{}',

            -- VI. Pożyczki
            vi_pozyczki         INTEGER NOT NULL DEFAULT 0,
            vi_wysokosc         TEXT,
            vi_pozyczkobiorcy   TEXT,
            vi_podstawa_stat    TEXT,

            -- VII. Środki fundacji (JSON)
            vii_json            TEXT NOT NULL DEFAULT '{}',

            -- VIII. Działalność zlecona
            viii_opis           TEXT,

            -- IX. Rozliczenia podatkowe
            ix_zobowiazania     TEXT,
            ix_deklaracje       TEXT,

            -- X. AML
            x_aml               INTEGER NOT NULL DEFAULT 0,

            -- XI. Płatności gotówkowe >= 10k EUR
            xi_platnosci        TEXT,

            -- XII. Kontrole
            xii_kontrola        INTEGER NOT NULL DEFAULT 0,
            xii_wyniki          TEXT,

            notatki             TEXT,
            created_by          INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_at          DATETIME DEFAULT (datetime('now','localtime')),
            updated_at          DATETIME DEFAULT (datetime('now','localtime')),
            UNIQUE (rok)
        )");
    } catch (\Throwable $e) {
        error_log('[freport_migrate] ' . $e->getMessage());
    }
}

// ── Auto-obliczenia z bazy ────────────────────────────────────────────────────

/**
 * Oblicza dane sekcji III (Przychody) z tabel grantów i umów.
 */
function freport_calc_iii(int $rok): array {
    $od  = "{$rok}-01-01";
    $do  = "{$rok}-12-31";
    $data = [
        'przychody_statutowe_przelew' => 0,
        'przychody_statutowe_gotowka' => 0,
        'przychody_gosp_przelew'      => 0,
        'darowizny'                   => 0,
        'srodki_publiczne'            => 0,
        'budzet_panstwa'              => 0,
        'budzet_jst'                  => 0,
        'inne_zrodla'                 => '',
    ];

    try {
        // Granty zakończone lub aktywne w roku sprawozdawczym
        $grants = db_all(
            "SELECT kwota_przyznana, obszar_tematyczny, donator, status
             FROM grants
             WHERE status NOT IN ('pomysł','odrzucony')
               AND ((data_od BETWEEN ? AND ?) OR (data_do BETWEEN ? AND ?) OR (data_od <= ? AND (data_do >= ? OR data_do IS NULL)))",
            [$od, $do, $od, $do, $od, $do]
        );
        foreach ($grants as $g) {
            $kwota = (float)($g['kwota_przyznana'] ?? 0);
            $donator = strtolower($g['donator'] ?? '');
            if (str_contains($donator,'ministerstwo') || str_contains($donator,'urząd') ||
                str_contains($donator,'pfron') || str_contains($donator,'rządowy')) {
                $data['budzet_panstwa']   += $kwota;
                $data['srodki_publiczne'] += $kwota;
            } elseif (str_contains($donator,'gmina') || str_contains($donator,'powiat') ||
                      str_contains($donator,'województw') || str_contains($donator,'samorząd')) {
                $data['budzet_jst']       += $kwota;
                $data['srodki_publiczne'] += $kwota;
            } else {
                $data['darowizny'] += $kwota;
            }
            $data['przychody_statutowe_przelew'] += $kwota;
        }
    } catch (\Throwable $e) {}

    return $data;
}

/**
 * Oblicza dane sekcji V (Zatrudnienie) z tabel umów.
 */
function freport_calc_v(int $rok): array {
    $od = "{$rok}-01-01";
    $do = "{$rok}-12-31";
    $data = [
        'umowy_praca_liczba'    => 0,
        'umowy_praca_brutto'    => 0,
        'umowy_cywilne_brutto'  => 0,
        'umowy_zlecenie_liczba' => 0,
        'umowy_dzielo_liczba'   => 0,
    ];
    try {
        // Umowy o pracę
        $p = db_one(
            "SELECT COUNT(*) AS c, COALESCE(SUM(wynagrodzenie_brutto),0) AS s
             FROM umowy_praca
             WHERE status NOT IN ('projekt','anulowana')
               AND data_zawarcia BETWEEN ? AND ?",
            [$od, $do]
        );
        $data['umowy_praca_liczba'] = (int)($p['c'] ?? 0);
        $data['umowy_praca_brutto'] = (float)($p['s'] ?? 0);
    } catch (\Throwable $e) {}

    try {
        // Umowy zlecenia
        $z = db_one(
            "SELECT COUNT(*) AS c, COALESCE(SUM(wynagrodzenie_brutto),0) AS s
             FROM umowy_zlecenie
             WHERE status NOT IN ('projekt','anulowana')
               AND data_zawarcia BETWEEN ? AND ?",
            [$od, $do]
        );
        $data['umowy_zlecenie_liczba'] = (int)($z['c'] ?? 0);
        $data['umowy_cywilne_brutto'] += (float)($z['s'] ?? 0);
    } catch (\Throwable $e) {}

    try {
        // Umowy o dzieło
        $d = db_one(
            "SELECT COUNT(*) AS c, COALESCE(SUM(wynagrodzenie_brutto),0) AS s
             FROM umowy_dzielo
             WHERE status NOT IN ('projekt','anulowana')
               AND data_zawarcia BETWEEN ? AND ?",
            [$od, $do]
        );
        $data['umowy_dzielo_liczba'] = (int)($d['c'] ?? 0);
        $data['umowy_cywilne_brutto'] += (float)($d['s'] ?? 0);
    } catch (\Throwable $e) {}

    try {
        // Umowy usługi
        $u = db_one(
            "SELECT COALESCE(SUM(wartosc_brutto),0) AS s
             FROM umowy_uslugi
             WHERE status NOT IN ('projekt','anulowana')
               AND data_zawarcia BETWEEN ? AND ?",
            [$od, $do]
        );
        $data['umowy_cywilne_brutto'] += (float)($u['s'] ?? 0);
    } catch (\Throwable $e) {}

    return $data;
}

/**
 * Pobiera cele statutowe ze Strategy module.
 */
function freport_cele_statutowe(): string {
    try {
        $objs = db_all(
            "SELECT o.nazwa, o.opis, s.nazwa AS sphere
             FROM strategy_objectives o
             LEFT JOIN public_benefit_spheres s ON s.id=o.sphere_id
             WHERE o.status='aktywny'
             ORDER BY s.sort_order, o.waga DESC"
        );
        if (!$objs) return '';
        $lines = [];
        foreach ($objs as $o) {
            $line = '• ' . $o['nazwa'];
            if ($o['sphere']) $line .= ' [' . $o['sphere'] . ']';
            if ($o['opis'])   $line .= "\n  " . $o['opis'];
            $lines[] = $line;
        }
        return implode("\n", $lines);
    } catch (\Throwable $e) { return ''; }
}

/**
 * Dane organizacji z settings.
 */
function freport_org_data(): array {
    return [
        'nazwa'       => org_setting('org_name')      ?: (defined('ORG_NAME') ? ORG_NAME : ''),
        'adres'       => org_setting('org_adres'),
        'miejscowosc' => org_setting('org_miejscowosc'),
        'nip'         => org_setting('org_nip'),
        'krs'         => org_setting('org_krs'),
        'regon'       => org_setting('org_regon'),
        'email'       => org_setting('org_email'),
        'www'         => org_setting('org_www'),
        'tel'         => org_setting('org_tel'),
        'zarzad'      => org_setting('org_zarzad'),
    ];
}

/**
 * Formatuje kwotę: 1234.56 → "1 234,56"
 */
function freport_kwota(float $v): string {
    return number_format($v, 2, ',', ' ');
}

freport_migrate();
