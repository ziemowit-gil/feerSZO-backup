<?php
/**
 * Moduł zaświadczeń własnych EZD — typy, wnioski, workflow wydawania.
 * Odrębny od includes/certificates.php (wolontariat/TI).
 */

(function () {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS ezd_zas_typy (
        id                INTEGER PRIMARY KEY AUTOINCREMENT,
        kod               TEXT    NOT NULL UNIQUE,
        nazwa             TEXT    NOT NULL,
        opis              TEXT    NOT NULL DEFAULT '',
        szablon_tresc     TEXT    NOT NULL DEFAULT '',
        szablon_pola      TEXT    NOT NULL DEFAULT '[]',
        wymaga_akceptacji INTEGER NOT NULL DEFAULT 1,
        jrwa_id           INTEGER REFERENCES ezd_jrwa(id) ON DELETE SET NULL,
        is_active         INTEGER NOT NULL DEFAULT 1,
        created_by        INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at        DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ezd_zaswiadczenia_wlasne (
        id                   INTEGER PRIMARY KEY AUTOINCREMENT,
        typ_id               INTEGER NOT NULL REFERENCES ezd_zas_typy(id),
        nr_zaswiadczenia     TEXT,
        status               TEXT    NOT NULL DEFAULT 'wniosek',
        wnioskodawca_name    TEXT    NOT NULL DEFAULT '',
        wnioskodawca_email   TEXT    NOT NULL DEFAULT '',
        dane_json            TEXT    NOT NULL DEFAULT '{}',
        tresc_html           TEXT    NOT NULL DEFAULT '',
        sprawa_id            INTEGER REFERENCES ezd_sprawy(id) ON DELETE SET NULL,
        pismo_id             INTEGER REFERENCES ezd_pisma(id) ON DELETE SET NULL,
        zatwierdzone_przez   INTEGER REFERENCES users(id) ON DELETE SET NULL,
        zatwierdzone_at      DATETIME,
        odrzucone_powod      TEXT,
        created_by           INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at           DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at           DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
})();

const EZD_ZAS_STATUSY = [
    'wniosek'     => ['label' => 'Wniosek',       'class' => 'secondary', 'icon' => 'bi-hourglass'],
    'weryfikacja' => ['label' => 'W weryfikacji',  'class' => 'warning',   'icon' => 'bi-search'],
    'wydane'      => ['label' => 'Wydane',         'class' => 'success',   'icon' => 'bi-award-fill'],
    'odrzucone'   => ['label' => 'Odrzucone',      'class' => 'danger',    'icon' => 'bi-x-circle'],
];

function ezd_zas_status_badge(string $status): string {
    $s = EZD_ZAS_STATUSY[$status] ?? ['label' => $status, 'class' => 'light text-dark border', 'icon' => 'bi-question'];
    return '<span class="badge bg-' . $s['class'] . '" style="font-size:.68rem"><i class="bi ' . $s['icon'] . ' me-1"></i>' . h($s['label']) . '</span>';
}

function ezd_zas_typy_all(bool $only_active = false): array {
    $w = $only_active ? 'WHERE zt.is_active=1' : '';
    return db_all(
        "SELECT zt.*, j.symbol AS jrwa_symbol FROM ezd_zas_typy zt
         LEFT JOIN ezd_jrwa j ON j.id=zt.jrwa_id $w ORDER BY zt.nazwa"
    );
}

function ezd_zas_typ_get(int $id): ?array {
    $r = db_one(
        "SELECT zt.*, j.symbol AS jrwa_symbol FROM ezd_zas_typy zt
         LEFT JOIN ezd_jrwa j ON j.id=zt.jrwa_id WHERE zt.id=?",
        [$id]
    );
    if (!$r) return null;
    $r['pola'] = json_decode($r['szablon_pola'] ?: '[]', true) ?: [];
    return $r;
}

function ezd_zas_all(array $f = []): array {
    $where = ['1=1']; $params = [];
    if (!empty($f['status'])) { $where[] = 'w.status=?'; $params[] = $f['status']; }
    if (!empty($f['typ_id'])) { $where[] = 'w.typ_id=?'; $params[] = (int)$f['typ_id']; }
    if (!empty($f['q'])) {
        $where[] = '(w.wnioskodawca_name LIKE ? OR w.nr_zaswiadczenia LIKE ?)';
        $params[] = '%' . $f['q'] . '%'; $params[] = '%' . $f['q'] . '%';
    }
    return db_all(
        "SELECT w.*, zt.nazwa AS typ_nazwa, u.name AS created_by_name, z.name AS zatw_name
         FROM ezd_zaswiadczenia_wlasne w
         JOIN ezd_zas_typy zt ON zt.id=w.typ_id
         LEFT JOIN users u ON u.id=w.created_by
         LEFT JOIN users z ON z.id=w.zatwierdzone_przez
         WHERE " . implode(' AND ', $where) . "
         ORDER BY w.created_at DESC",
        $params
    );
}

function ezd_zas_get(int $id): ?array {
    $r = db_one(
        "SELECT w.*, zt.nazwa AS typ_nazwa, zt.szablon_tresc, zt.szablon_pola,
                zt.wymaga_akceptacji, zt.jrwa_id, j.symbol AS jrwa_symbol,
                u.name AS created_by_name, z.name AS zatw_name,
                sp.znak_sprawy, p.sygnatura AS pismo_syg
         FROM ezd_zaswiadczenia_wlasne w
         JOIN ezd_zas_typy zt ON zt.id=w.typ_id
         LEFT JOIN ezd_jrwa j ON j.id=zt.jrwa_id
         LEFT JOIN users u ON u.id=w.created_by
         LEFT JOIN users z ON z.id=w.zatwierdzone_przez
         LEFT JOIN ezd_sprawy sp ON sp.id=w.sprawa_id
         LEFT JOIN ezd_pisma p ON p.id=w.pismo_id
         WHERE w.id=?",
        [$id]
    );
    if (!$r) return null;
    $r['pola'] = json_decode($r['szablon_pola'] ?: '[]', true) ?: [];
    $r['dane'] = json_decode($r['dane_json'] ?: '{}', true) ?: [];
    return $r;
}

function ezd_zas_create(int $typ_id, string $name, string $email, array $dane, int $user_id): int {
    db()->prepare(
        "INSERT INTO ezd_zaswiadczenia_wlasne
         (typ_id,wnioskodawca_name,wnioskodawca_email,dane_json,status,created_by)
         VALUES (?,?,?,?,?,?)"
    )->execute([$typ_id, $name, $email, json_encode($dane, JSON_UNESCAPED_UNICODE), 'wniosek', $user_id]);
    return (int)db()->lastInsertId();
}

function ezd_zas_next_nr(int $year): string {
    $n = (int)(db_one(
        "SELECT COUNT(*) AS c FROM ezd_zaswiadczenia_wlasne
         WHERE strftime('%Y',created_at)=? AND nr_zaswiadczenia IS NOT NULL",
        [(string)$year]
    )['c'] ?? 0) + 1;
    return 'ZAS/' . $year . '/' . str_pad((string)$n, 3, '0', STR_PAD_LEFT);
}

/** Zastępuje {{tokeny}} w szablonie wartościami z $dane + danymi systemowymi. */
function ezd_zas_render(string $szablon, array $dane, array $extra = []): string {
    $vars = array_merge($extra, $dane);
    $vars['data_wydania']    = date('d.m.Y');
    $vars['data_wydania_dl'] = function_exists('date_pl') ? date_pl(date('Y-m-d')) : date('d.m.Y');
    $vars['organizacja']     = defined('ORG_NAME') ? ORG_NAME : '';
    return preg_replace_callback('/\{\{(\w+)\}\}/', function ($m) use ($vars) {
        return isset($vars[$m[1]]) ? htmlspecialchars((string)$vars[$m[1]], ENT_QUOTES, 'UTF-8') : '';
    }, $szablon);
}

/**
 * Wydaje zaświadczenie: generuje numer, renderuje treść,
 * rejestruje pismo wychodzące w EZD w podanej koszulce.
 * $sprawa_id_override pozwala podać koszulkę przy wydaniu (nadpisuje przypisaną).
 */
function ezd_zas_wydaj(int $zas_id, int $user_id, ?int $sprawa_id_override = null): array {
    $zas = ezd_zas_get($zas_id);
    if (!$zas) return ['ok' => false, 'error' => 'Wniosek nie istnieje.'];
    if ($zas['status'] === 'wydane') return ['ok' => false, 'error' => 'Już wydane.'];

    $nr    = ezd_zas_next_nr((int)date('Y'));
    $tresc = ezd_zas_render(
        $zas['szablon_tresc'],
        $zas['dane'],
        ['nr_zaswiadczenia' => $nr]
    );

    $sprawa_id = $sprawa_id_override ?? ($zas['sprawa_id'] ?: null);
    $pismo_id  = null;

    if ($sprawa_id) {
        try {
            $pismo_id = ezd_pismo_create([
                'sprawa_id'     => $sprawa_id,
                'kierunek'      => 'wychodzace',
                'title'         => 'Zaświadczenie ' . $nr . ' — ' . $zas['wnioskodawca_name'],
                'tresc'         => $tresc,
                'odbiorca'      => $zas['wnioskodawca_name'],
                'data_pisma'    => date('Y-m-d'),
                'data_wysylki'  => date('Y-m-d'),
                'status'        => 'wyslane',
                'rodzaj_medium' => 'inne',
            ], $user_id);
        } catch (\Throwable $e) { /* pismo opcjonalne */ }
    }

    db()->prepare(
        "UPDATE ezd_zaswiadczenia_wlasne
         SET nr_zaswiadczenia=?, tresc_html=?, status='wydane',
             zatwierdzone_przez=?, zatwierdzone_at=datetime('now'),
             sprawa_id=?, pismo_id=?, updated_at=datetime('now')
         WHERE id=?"
    )->execute([$nr, $tresc, $user_id, $sprawa_id, $pismo_id, $zas_id]);

    return ['ok' => true, 'nr' => $nr, 'pismo_id' => $pismo_id];
}

function ezd_zas_odrzuc(int $zas_id, string $powod, int $user_id): void {
    db()->prepare(
        "UPDATE ezd_zaswiadczenia_wlasne
         SET status='odrzucone', odrzucone_powod=?,
             zatwierdzone_przez=?, zatwierdzone_at=datetime('now'), updated_at=datetime('now')
         WHERE id=?"
    )->execute([$powod, $user_id, $zas_id]);
}
