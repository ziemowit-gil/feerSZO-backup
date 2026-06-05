<?php
/**
 * includes/strategy.php — Moduł Strategii Rozwoju NGO
 *
 * Tabele:
 *   public_benefit_spheres  — Sfery pożytku publicznego
 *   strategy_objectives     — Cele strategiczne
 *   strategy_mapping        — Powiązania celów z umowami / grantami / działaniami
 *   strategy_progress       — Snapshoty postępu realizacji celu
 * Widok:
 *   v_strategy_dashboard    — Agregacja dla dashboardu
 */

// ── Auto-migracja ─────────────────────────────────────────────────────────────
function strategy_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $pdo = db();

        // 1. Sfery pożytku publicznego
        $pdo->exec("CREATE TABLE IF NOT EXISTS public_benefit_spheres (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            kod        TEXT NOT NULL UNIQUE,
            nazwa      TEXT NOT NULL,
            opis       TEXT,
            kolor      TEXT NOT NULL DEFAULT '#2563eb',
            ikona      TEXT NOT NULL DEFAULT 'bi-globe2',
            is_active  INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT (datetime('now','localtime'))
        )");

        // 2. Cele strategiczne
        $pdo->exec("CREATE TABLE IF NOT EXISTS strategy_objectives (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            sphere_id        INTEGER REFERENCES public_benefit_spheres(id) ON DELETE SET NULL,
            nazwa            TEXT NOT NULL,
            opis             TEXT,
            cel_miernika     TEXT,
            wartosc_docelowa DECIMAL(14,2),
            wartosc_bazowa   DECIMAL(14,2),
            data_od          DATE,
            data_do          DATE,
            status           TEXT NOT NULL DEFAULT 'aktywny',
            waga             INTEGER NOT NULL DEFAULT 1,
            owner_id         INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_by       INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_at       DATETIME DEFAULT (datetime('now','localtime')),
            updated_at       DATETIME DEFAULT (datetime('now','localtime'))
        )");

        // 3. Mapowania (powiązania celów z encjami)
        $pdo->exec("CREATE TABLE IF NOT EXISTS strategy_mapping (
            id                  INTEGER PRIMARY KEY AUTOINCREMENT,
            objective_id        INTEGER NOT NULL REFERENCES strategy_objectives(id) ON DELETE CASCADE,
            entity_type         TEXT NOT NULL,
            entity_id           INTEGER NOT NULL,
            contract_type       TEXT,
            contribution_weight DECIMAL(5,2) NOT NULL DEFAULT 100,
            note                TEXT,
            added_by            INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_at          DATETIME DEFAULT (datetime('now','localtime')),
            UNIQUE (objective_id, entity_type, entity_id, contract_type)
        )");

        // 4. Postęp realizacji celu (snapshoty)
        $pdo->exec("CREATE TABLE IF NOT EXISTS strategy_progress (
            id                  INTEGER PRIMARY KEY AUTOINCREMENT,
            objective_id        INTEGER NOT NULL REFERENCES strategy_objectives(id) ON DELETE CASCADE,
            wartosc_realizowana DECIMAL(14,2) NOT NULL DEFAULT 0,
            budzet_wydany       DECIMAL(14,2) NOT NULL DEFAULT 0,
            budzet_przypisany   DECIMAL(14,2) NOT NULL DEFAULT 0,
            snapshot_date       DATE NOT NULL DEFAULT (date('now')),
            source              TEXT NOT NULL DEFAULT 'manual',
            notatka             TEXT,
            created_at          DATETIME DEFAULT (datetime('now','localtime'))
        )");

        // 5. Widok dashboardu
        try {
            $pdo->exec("DROP VIEW IF EXISTS v_strategy_dashboard");
        } catch (\Throwable $e) {}

        $pdo->exec("CREATE VIEW v_strategy_dashboard AS
            SELECT
                o.id,
                o.sphere_id,
                o.nazwa,
                o.opis,
                o.cel_miernika,
                o.wartosc_docelowa,
                o.wartosc_bazowa,
                o.data_od,
                o.data_do,
                o.status,
                o.waga,
                o.owner_id,
                o.created_at,
                o.updated_at,
                s.kod        AS sphere_kod,
                s.nazwa      AS sphere_nazwa,
                s.kolor      AS sphere_kolor,
                s.ikona      AS sphere_ikona,
                COALESCE(p.wartosc_realizowana, 0) AS wartosc_realizowana,
                COALESCE(p.budzet_wydany,       0) AS budzet_wydany,
                COALESCE(p.budzet_przypisany,   0) AS budzet_przypisany,
                p.snapshot_date,
                CASE
                    WHEN o.wartosc_docelowa IS NULL OR o.wartosc_docelowa = 0 THEN 0
                    ELSE ROUND(COALESCE(p.wartosc_realizowana,0) * 100.0 / o.wartosc_docelowa, 1)
                END AS postep_procent,
                CASE
                    WHEN o.wartosc_docelowa IS NULL OR o.wartosc_docelowa = 0 THEN 0
                    ELSE ROUND(COALESCE(p.budzet_wydany,0) * 100.0 / o.wartosc_docelowa, 1)
                END AS budzet_procent,
                (SELECT COUNT(*) FROM strategy_mapping m WHERE m.objective_id = o.id) AS entity_count
            FROM strategy_objectives o
            LEFT JOIN public_benefit_spheres s ON s.id = o.sphere_id
            LEFT JOIN (
                SELECT p1.*
                FROM strategy_progress p1
                WHERE p1.id = (
                    SELECT p2.id FROM strategy_progress p2
                    WHERE p2.objective_id = p1.objective_id
                    ORDER BY p2.snapshot_date DESC, p2.id DESC
                    LIMIT 1
                )
            ) p ON p.objective_id = o.id
        ");

        // 6. Seedowanie domyślnych sfer pożytku
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM public_benefit_spheres")->fetchColumn();
        if ($cnt === 0) {
            $spheres = [
                ['SP-01', 'Pomocy społecznej',                                      '#dc2626', 'bi-heart-pulse'],
                ['SP-02', 'Działalności na rzecz osób niepełnosprawnych',           '#9333ea', 'bi-person-wheelchair'],
                ['SP-03', 'Ochrony zdrowia',                                        '#16a34a', 'bi-hospital'],
                ['SP-04', 'Edukacji, oświaty i wychowania',                         '#2563eb', 'bi-mortarboard'],
                ['SP-05', 'Kultury, sztuki, ochrony dóbr kultury i dziedzictwa',   '#d97706', 'bi-palette'],
            ];
            $stmt = $pdo->prepare(
                "INSERT INTO public_benefit_spheres (kod, nazwa, kolor, ikona, is_active, sort_order) VALUES (?,?,?,?,1,?)"
            );
            foreach ($spheres as $i => $s) {
                $stmt->execute([$s[0], $s[1], $s[2], $s[3], ($i + 1) * 10]);
            }
        }

    } catch (\Throwable $e) {
        error_log('[strategy_migrate] ' . $e->getMessage());
    }
}

// Uruchom migrację automatycznie przy załadowaniu pliku
strategy_migrate();

// ── Ocena zdrowia celu ────────────────────────────────────────────────────────

/**
 * Zwraca 'green'|'yellow'|'red' na podstawie postępu i dat.
 */
function strategy_health_score(array $row): string {
    $postep  = (float)($row['postep_procent']  ?? 0);
    $budzet  = (float)($row['budzet_procent']  ?? 0);
    $data_do = $row['data_do'] ?? null;

    $today     = date('Y-m-d');
    $in30days  = date('Y-m-d', strtotime('+30 days'));
    $is_expired = $data_do && $data_do < $today;
    $expires_soon = $data_do && $data_do <= $in30days && !$is_expired;

    // Czerwone: poważne problemy
    if ($postep < 30 || $budzet > 120 || $is_expired) {
        return 'red';
    }

    // Zielone: wszystko OK
    if ($postep >= 70 && $budzet >= 50 && $budzet <= 110 && !$expires_soon) {
        return 'green';
    }

    // Żółte: reszta
    return 'yellow';
}

/**
 * Zwraca HTML badge Bootstrap na podstawie score.
 */
function strategy_health_badge(string $score): string {
    return match ($score) {
        'green'  => '<span class="badge" style="background:#16a34a;color:#fff"><i class="bi bi-check-circle-fill me-1"></i>Na dobrej drodze</span>',
        'yellow' => '<span class="badge" style="background:#d97706;color:#fff"><i class="bi bi-exclamation-triangle-fill me-1"></i>Wymaga uwagi</span>',
        'red'    => '<span class="badge" style="background:#dc2626;color:#fff"><i class="bi bi-x-circle-fill me-1"></i>Zagrożony</span>',
        default  => '<span class="badge bg-secondary">Nieznany</span>',
    };
}

// ── Helpery danych ────────────────────────────────────────────────────────────

/**
 * Zwraca wszystkie cele dla danej sfery (z widoku dashboardu).
 */
function strategy_objectives_for_sphere(int $sphere_id): array {
    try {
        return db_all(
            "SELECT * FROM v_strategy_dashboard WHERE sphere_id = ? ORDER BY waga DESC, id ASC",
            [$sphere_id]
        );
    } catch (\Throwable $e) {
        error_log('[strategy_objectives_for_sphere] ' . $e->getMessage());
        return [];
    }
}

/**
 * Zwraca wszystkie powiązane encje dla celu, wzbogacone o szczegóły.
 */
function strategy_mappings_for_objective(int $obj_id): array {
    try {
        $rows = db_all(
            "SELECT * FROM strategy_mapping WHERE objective_id = ? ORDER BY entity_type, id",
            [$obj_id]
        );
    } catch (\Throwable $e) {
        error_log('[strategy_mappings_for_objective] ' . $e->getMessage());
        return [];
    }

    foreach ($rows as &$row) {
        $row['_detail'] = null;
        try {
            switch ($row['entity_type']) {
                case 'grant':
                    $row['_detail'] = db_one(
                        "SELECT id, nazwa, donator, kwota_przyznana, data_od, data_do, status FROM grants WHERE id = ?",
                        [(int)$row['entity_id']]
                    );
                    break;
                case 'action':
                    $row['_detail'] = db_one(
                        "SELECT id, nazwa, status, data_od, data_do FROM actions WHERE id = ?",
                        [(int)$row['entity_id']]
                    );
                    break;
                case 'contract':
                    $ct = $row['contract_type'] ?? '';
                    if ($ct) {
                        $table = 'umowy_' . $ct;
                        $row['_detail'] = db_one(
                            "SELECT id, numer_umowy, status, data_zakonczenia FROM {$table} WHERE id = ?",
                            [(int)$row['entity_id']]
                        );
                        if ($row['_detail']) {
                            $row['_detail']['_type'] = $ct;
                        }
                    }
                    break;
            }
        } catch (\Throwable $e) {
            error_log('[strategy_mappings_for_objective] detail join failed: ' . $e->getMessage());
        }
    }
    unset($row);

    return $rows;
}

/**
 * Automatycznie oblicza i zapisuje snapshot postępu dla danego celu.
 * Sumuje kwoty grantów i liczbę umów powiązanych z celem.
 */
function strategy_add_progress_snapshot(int $obj_id): void {
    try {
        $mappings = db_all(
            "SELECT * FROM strategy_mapping WHERE objective_id = ?",
            [$obj_id]
        );

        $budzet_przypisany  = 0.0;
        $wartosc_realizowana = 0.0;

        foreach ($mappings as $m) {
            switch ($m['entity_type']) {
                case 'grant':
                    try {
                        $g = db_one("SELECT kwota_przyznana FROM grants WHERE id = ?", [(int)$m['entity_id']]);
                        if ($g && $g['kwota_przyznana']) {
                            $w = (float)$m['contribution_weight'] / 100.0;
                            $budzet_przypisany  += (float)$g['kwota_przyznana'] * $w;
                            $wartosc_realizowana += (float)$g['kwota_przyznana'] * $w;
                        }
                    } catch (\Throwable $e) {}
                    break;

                case 'contract':
                    // Umowy — każda aktywna umowa = 1 jednostka realizacji
                    $ct = $m['contract_type'] ?? '';
                    if ($ct) {
                        try {
                            $table = 'umowy_' . $ct;
                            $c = db_one("SELECT status FROM {$table} WHERE id = ?", [(int)$m['entity_id']]);
                            if ($c && in_array($c['status'], ['podpisana','w realizacji','obowiązująca'], true)) {
                                $wartosc_realizowana += 1.0;
                            }
                        } catch (\Throwable $e) {}
                    }
                    break;

                case 'action':
                    try {
                        $a = db_one("SELECT status FROM actions WHERE id = ?", [(int)$m['entity_id']]);
                        if ($a && in_array($a['status'], ['zakończone','w trakcie'], true)) {
                            $wartosc_realizowana += 1.0;
                        }
                    } catch (\Throwable $e) {}
                    break;
            }
        }

        db_insert('strategy_progress', [
            'objective_id'        => $obj_id,
            'wartosc_realizowana' => round($wartosc_realizowana, 2),
            'budzet_wydany'       => 0,
            'budzet_przypisany'   => round($budzet_przypisany, 2),
            'snapshot_date'       => date('Y-m-d'),
            'source'              => 'auto',
            'notatka'             => 'Automatyczny snapshot z powiązanych encji',
        ]);

    } catch (\Throwable $e) {
        error_log('[strategy_add_progress_snapshot] ' . $e->getMessage());
    }
}
