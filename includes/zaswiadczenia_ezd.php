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

    // Kolumny dodane po pierwszym wdrożeniu — healed
    try { $pdo->exec("ALTER TABLE ezd_zas_typy ADD COLUMN nr_prefix TEXT NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE ezd_zas_typy ADD COLUMN naglowek_html TEXT NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE ezd_zas_typy ADD COLUMN podpisujacy TEXT NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}

    // Plik własny (wydany z uploadu, nie z szablonu)
    try { $pdo->exec("ALTER TABLE ezd_zaswiadczenia_wlasne ADD COLUMN plik_path TEXT"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE ezd_zaswiadczenia_wlasne ADD COLUMN plik_mime TEXT NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}

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
        "SELECT zt.*, j.symbol AS jrwa_symbol,
                COALESCE(zt.naglowek_html,'') AS naglowek_html,
                COALESCE(zt.podpisujacy,'') AS podpisujacy
         FROM ezd_zas_typy zt
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
                zt.wymaga_akceptacji, zt.jrwa_id, zt.nr_prefix,
                COALESCE(zt.naglowek_html,'') AS naglowek_html,
                COALESCE(zt.podpisujacy,'') AS podpisujacy,
                j.symbol AS jrwa_symbol,
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

/**
 * Generuje kolejny numer zaświadczenia w danym roku, per typ.
 * Format: {prefix}/{rok}/{n} — prefix z nr_prefix typu, fallback 'ZAS'.
 */
function ezd_zas_next_nr(int $typ_id, int $year): string {
    $typ    = db_one("SELECT nr_prefix FROM ezd_zas_typy WHERE id=?", [$typ_id]);
    $prefix = trim($typ['nr_prefix'] ?? '');
    if ($prefix === '') $prefix = 'ZAS';

    $n = (int)(db_one(
        "SELECT COUNT(*) AS c FROM ezd_zaswiadczenia_wlasne
         WHERE typ_id=? AND strftime('%Y',created_at)=? AND nr_zaswiadczenia IS NOT NULL",
        [$typ_id, (string)$year]
    )['c'] ?? 0) + 1;

    return $prefix . '/' . $year . '/' . str_pad((string)$n, 3, '0', STR_PAD_LEFT);
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

/** Generuje HTML dokumentu zaświadczenia do wydruku/PDF. */
function ezd_zas_pdf_html(array $zas): string {
    $org  = function_exists('org_setting') ? (org_setting('org_name') ?: '') : (defined('ORG_NAME') ? ORG_NAME : '');
    // ezd_logo ma priorytet; fallback na org_logo; ścieżka = assets/logo/{fname}
    $_lf  = function_exists('org_setting')
        ? (org_setting('ezd_logo') ?: org_setting('org_logo') ?: '')
        : '';
    $logo = $_lf ? dirname(__DIR__) . '/assets/logo/' . $_lf : '';
    $nr   = h($zas['nr_zaswiadczenia'] ?? '');
    $data_wyd = $zas['zatwierdzone_at']
        ? date('d.m.Y', strtotime((string)$zas['zatwierdzone_at']))
        : date('d.m.Y');

    // Treść: obsługa HTML (TinyMCE) i legacy plain-text
    $raw_body  = $zas['tresc_html'] ?: '';
    $_trimmed  = ltrim($raw_body);
    $body      = ($_trimmed !== '' && $_trimmed[0] === '<')
                    ? $raw_body
                    : nl2br(h($raw_body));

    // Logo jako data-URI
    $logo_html = '';
    if ($logo && file_exists($logo)) {
        $mime = mime_content_type($logo);
        $b64  = base64_encode(file_get_contents($logo));
        $logo_html = '<img src="data:' . $mime . ';base64,' . $b64
                   . '" style="max-height:60px;max-width:190px;display:block">';
    }

    // Prawa kolumna nagłówka: naglowek_html (z tokenami) lub auto z org settings
    $naglowek_raw = trim($zas['naglowek_html'] ?? '');
    if ($naglowek_raw !== '') {
        $right_cell = ezd_zas_render($naglowek_raw, $zas['dane'] ?? [], [
            'nr_zaswiadczenia' => $zas['nr_zaswiadczenia'] ?? '',
            'data_wydania'     => $data_wyd,
            'organizacja'      => $org,
        ]);
    } else {
        // Buduj z org settings: nazwa, adres, KRS/NIP/REGON, strona www
        $_os = function_exists('org_setting') ? 'org_setting' : null;
        $_g  = fn($k) => $_os ? (org_setting($k) ?: '') : '';
        $org_adres    = trim($_g('org_adres'));
        $org_miasto   = trim($_g('org_miasto') ?: $_g('org_miejscowosc'));
        $org_nip      = trim($_g('org_nip'));
        $org_krs      = trim($_g('org_krs'));
        $org_regon    = trim($_g('org_regon'));
        $org_www      = trim($_g('org_www')) ?: 'feer.org.pl';

        $lines = ['<strong><em>' . h($org) . '</em></strong>'];
        $adres_full = trim($org_adres . ($org_adres && $org_miasto ? ', ' : '') . $org_miasto);
        if ($adres_full) $lines[] = h($adres_full);
        $legal = array_filter([
            $org_krs   ? 'KRS: '   . h($org_krs)   : '',
            $org_nip   ? 'NIP: '   . h($org_nip)   : '',
            $org_regon ? 'REGON: ' . h($org_regon) : '',
        ]);
        if ($legal) $lines[] = implode('&nbsp;&nbsp;', $legal);
        if ($org_www) $lines[] = h($org_www);
        $right_cell = implode('<br>', $lines);
    }

    // Podpisujący: z pola typu lub fallback na org + datę
    $podpisujacy = trim($zas['podpisujacy'] ?? '');
    $sig_inner   = $podpisujacy !== ''
        ? nl2br(h($podpisujacy))
        : h($org) . '<br><span style="color:#555;font-size:9pt">' . $data_wyd . '</span>';

    return '<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<style>
  body   { font-family: ubuntu, "DejaVu Sans", sans-serif; font-size: 11pt; line-height: 1.65; color: #111; margin: 0; }
  p      { margin: 0; padding: 0; }
  .body  { text-align: justify; }
  .footer{ margin-top: 24pt; font-size: 8pt; color: #999; border-top: 1px solid #ddd; padding-top: 5pt; }
</style>
</head>
<body>

<table style="width:100%;border-collapse:collapse;margin-bottom:18pt">
  <tr>
    <td style="width:50%;vertical-align:middle;border:0">' . $logo_html . '</td>
    <td style="text-align:right;vertical-align:middle;border:0;font-size:9.5pt;line-height:1.5">' . $right_cell . '</td>
  </tr>
</table>

<p style="font-size:17pt;font-weight:bold;text-align:center;margin:14pt 0 4pt">ZAŚWIADCZENIE</p>
<p style="font-size:10pt;text-align:center;color:#555;margin-bottom:' . ($zas['znak_sprawy'] ? '4pt' : '20pt') . '">Nr ' . $nr . '</p>
' . ($zas['znak_sprawy'] ? '<p style="font-size:8pt;text-align:right;color:#888;margin-bottom:16pt">Sprawa: ' . h($zas['znak_sprawy']) . '</p>' : '') . '

<div class="body">' . $body . '</div>

<table style="width:100%;border-collapse:collapse;margin-top:55pt">
  <tr>
    <td style="width:48%;border:0"></td>
    <td style="border:0;border-top:1px solid #444;text-align:center;padding-top:5pt;font-size:9.5pt;line-height:1.4">' . $sig_inner . '</td>
  </tr>
</table>

</body>
</html>';
}

/**
 * Wydaje zaświadczenie: generuje numer per typ, renderuje treść,
 * rejestruje pismo wychodzące w EZD w podanej koszulce.
 */
function ezd_zas_wydaj(int $zas_id, int $user_id, ?int $sprawa_id_override = null, string $tresc_override = ''): array {
    $zas = ezd_zas_get($zas_id);
    if (!$zas) return ['ok' => false, 'error' => 'Wniosek nie istnieje.'];
    if ($zas['status'] === 'wydane') return ['ok' => false, 'error' => 'Już wydane.'];

    $nr = ezd_zas_next_nr((int)$zas['typ_id'], (int)date('Y'));
    if ($tresc_override !== '') {
        // Edytowana treść z widoku — podstaw tylko numer (placeholder [NUMER])
        $tresc = str_replace('[NUMER]', htmlspecialchars($nr, ENT_QUOTES, 'UTF-8'), $tresc_override);
    } else {
        $tresc = ezd_zas_render($zas['szablon_tresc'], $zas['dane'], ['nr_zaswiadczenia' => $nr]);
    }

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

/**
 * Wydaje zaświadczenie jako zewnętrzny plik (PDF/inny) — numer nadany,
 * tresc_html pozostaje puste. Plik serwowany przez pdf.php bezpośrednio.
 */
function ezd_zas_wydaj_plik(int $zas_id, int $user_id, string $plik_path, string $plik_mime, ?int $sprawa_id_override = null): array {
    $zas = ezd_zas_get($zas_id);
    if (!$zas) return ['ok' => false, 'error' => 'Wniosek nie istnieje.'];
    if ($zas['status'] === 'wydane') return ['ok' => false, 'error' => 'Już wydane.'];

    $nr        = ezd_zas_next_nr((int)$zas['typ_id'], (int)date('Y'));
    $sprawa_id = $sprawa_id_override ?? ($zas['sprawa_id'] ?: null);
    $pismo_id  = null;

    if ($sprawa_id) {
        try {
            $pismo_id = ezd_pismo_create([
                'sprawa_id'     => $sprawa_id,
                'kierunek'      => 'wychodzace',
                'title'         => 'Zaświadczenie ' . $nr . ' — ' . $zas['wnioskodawca_name'],
                'tresc'         => '',
                'odbiorca'      => $zas['wnioskodawca_name'],
                'data_pisma'    => date('Y-m-d'),
                'data_wysylki'  => date('Y-m-d'),
                'status'        => 'wyslane',
                'rodzaj_medium' => 'inne',
            ], $user_id);
        } catch (\Throwable $e) {}
    }

    db()->prepare(
        "UPDATE ezd_zaswiadczenia_wlasne
         SET nr_zaswiadczenia=?, status='wydane', plik_path=?, plik_mime=?,
             zatwierdzone_przez=?, zatwierdzone_at=datetime('now'),
             sprawa_id=?, pismo_id=?, updated_at=datetime('now')
         WHERE id=?"
    )->execute([$nr, $plik_path, $plik_mime, $user_id, $sprawa_id, $pismo_id, $zas_id]);

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

/** Usuwa wniosek (tylko admin/manager). Nie usuwa powiązanego pisma EZD. */
function ezd_zas_delete(int $zas_id): void {
    db()->prepare("DELETE FROM ezd_zaswiadczenia_wlasne WHERE id=?")->execute([$zas_id]);
}
