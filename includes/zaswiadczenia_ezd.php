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
    try { $pdo->exec("ALTER TABLE ezd_zas_typy ADD COLUMN waznosc_dni INTEGER NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE ezd_zas_typy ADD COLUMN qr_enabled INTEGER NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}

    // Plik własny (wydany z uploadu, nie z szablonu)
    try { $pdo->exec("ALTER TABLE ezd_zaswiadczenia_wlasne ADD COLUMN plik_path TEXT"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE ezd_zaswiadczenia_wlasne ADD COLUMN plik_mime TEXT NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE ezd_zaswiadczenia_wlasne ADD COLUMN wazne_do TEXT"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE ezd_zaswiadczenia_wlasne ADD COLUMN verify_code TEXT"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE ezd_zaswiadczenia_wlasne ADD COLUMN qr_on_pdf INTEGER NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
    try { $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_zas_verify_code ON ezd_zaswiadczenia_wlasne(verify_code) WHERE verify_code IS NOT NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE ezd_zaswiadczenia_wlasne ADD COLUMN z_urzedu INTEGER NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}

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
                COALESCE(zt.podpisujacy,'') AS podpisujacy,
                COALESCE(zt.waznosc_dni,0) AS waznosc_dni,
                COALESCE(zt.qr_enabled,0) AS qr_enabled
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
                COALESCE(zt.waznosc_dni,0) AS waznosc_dni,
                COALESCE(zt.qr_enabled,0) AS qr_enabled,
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
 * Format: {PREFIX}/{rok}/{nnn} — np. ZAS/2026/001
 */
function ezd_zas_next_nr(int $typ_id, int $year): string {
    $typ    = db_one("SELECT nr_prefix FROM ezd_zas_typy WHERE id=?", [$typ_id]);
    $prefix = strtoupper(trim($typ['nr_prefix'] ?? ''));
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

/**
 * Generuje HTML dokumentu zaświadczenia do wydruku/PDF.
 * @param bool $preview Tryb podglądu — numer zastąpiony placeholderem, watermark PROJEKT
 */
function ezd_zas_pdf_html(array $zas, bool $preview = false): string {
    $org  = function_exists('org_setting') ? (org_setting('org_name') ?: '') : (defined('ORG_NAME') ? ORG_NAME : '');
    $_lf  = function_exists('org_setting')
        ? (org_setting('ezd_logo') ?: org_setting('org_logo') ?: '')
        : '';
    $logo = $_lf ? dirname(__DIR__) . '/assets/logo/' . $_lf : '';
    $nr   = $preview ? '[PODGLĄD]' : h($zas['nr_zaswiadczenia'] ?? '');
    $data_wyd = $zas['zatwierdzone_at']
        ? date('d.m.Y', strtotime((string)$zas['zatwierdzone_at']))
        : date('d.m.Y');

    // Treść: w podglądzie renderuj szablon z placeholderem
    $raw_body  = $zas['tresc_html'] ?: '';
    if ($preview && $raw_body === '') {
        $raw_body = ezd_zas_render($zas['szablon_tresc'] ?? '', $zas['dane'] ?? [], ['nr_zaswiadczenia' => '[NUMER]']);
    }
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
        $org_nip      = trim($_g('org_nip'));
        $org_krs      = trim($_g('org_krs'));
        $org_regon    = trim($_g('org_regon'));
        $org_www      = trim($_g('org_www')) ?: 'feer.org.pl';

        $lines = ['<strong><em>' . h($org) . '</em></strong>'];
        // org_adres już zawiera kod pocztowy i miasto — nie dopisujemy org_miejscowosc
        if ($org_adres) $lines[] = h($org_adres);
        $legal = array_filter([
            $org_krs ? 'KRS: ' . h($org_krs) : '',
            $org_nip ? 'NIP: ' . h($org_nip) : '',
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

    // Data ważności — wyświetlana przed podpisem, czcionka jak treść
    $wazne_do_before_sig = '';
    if (!empty($zas['wazne_do'])) {
        $wdt     = strtotime($zas['wazne_do']);
        $wdt_fmt = $wdt ? (function_exists('date_pl') ? date_pl(date('Y-m-d', $wdt)) : date('d.m.Y', $wdt)) : h($zas['wazne_do']);
        $expired = $wdt && $wdt < time();
        $wazne_do_before_sig = '<p style="margin-top:16pt;' . ($expired ? 'color:#c00;' : '') . '">'
            . ($expired ? '⚠ ' : '') . 'Zaświadczenie ważne do: <strong>' . $wdt_fmt . '</strong>'
            . ($expired ? ' — <em>WYGASŁE</em>' : '') . '</p>';
    }

    // Miejscowość + data wydania (format urzędowy)
    $miejscowosc = function_exists('org_setting') ? trim(org_setting('org_miejscowosc') ?: '') : '';
    $data_iso    = $zas['zatwierdzone_at'] ? substr((string)$zas['zatwierdzone_at'], 0, 10) : date('Y-m-d');
    $data_dl     = function_exists('date_pl') ? date_pl($data_iso) : date('d.m.Y', strtotime($data_iso));
    $miasto_data = ($miejscowosc ? h($miejscowosc) . ', ' : '') . 'dnia ' . $data_dl . ' r.';

    // QR kod weryfikacyjny
    $qr_html = '';
    if (!$preview && !empty($zas['qr_on_pdf']) && !empty($zas['verify_code'])) {
        try {
            $verify_url = (defined('APP_URL') ? APP_URL : '') . '/ezd/zaswiadczenia/verify.php?code=' . rawurlencode($zas['verify_code']);
            $qr  = new \Mpdf\QrCode\QrCode($verify_url, 'M');
            $png = (new \Mpdf\QrCode\Output\Png())->output($qr, 72);
            $qr_img = 'data:image/png;base64,' . base64_encode($png);
            $qr_html = '<img src="' . $qr_img . '" style="width:72px;height:72px" alt="QR">'
                     . '<br><span style="font-size:7pt;color:#888">Weryfikacja online</span>';
        } catch (\Throwable $e) {}
    }

    $watermark = $preview
        ? '<p style="text-align:center;color:#ccc;font-size:28pt;font-weight:bold;margin:4pt 0 10pt;letter-spacing:8pt">PROJEKT</p>'
        : '';

    return '<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<style>
  body { font-family: ubuntu, "DejaVu Sans", sans-serif; font-size: 11pt; line-height: 1.65; color: #111; margin: 0; }
  p    { margin: 0; padding: 0; }
  .body{ text-align: justify; }
</style>
</head>
<body>

' . $watermark . '

<table style="width:100%;border-collapse:collapse;margin-bottom:14pt">
  <tr>
    <td style="width:50%;vertical-align:middle;border:0">' . $logo_html . '</td>
    <td style="text-align:right;vertical-align:middle;border:0;font-size:9.5pt;line-height:1.5">' . $right_cell . '</td>
  </tr>
</table>

<p style="font-size:9.5pt;text-align:right;margin-bottom:10pt">' . $miasto_data . '</p>

<p style="font-size:17pt;font-weight:bold;text-align:center;margin:0 0 2pt">ZAŚWIADCZENIE</p>
<p style="font-size:11pt;text-align:center;margin-bottom:14pt">Nr ' . $nr . '</p>

' . ($zas['znak_sprawy'] ? '<p style="font-size:9.5pt;margin-bottom:14pt"><span style="color:#888">Sprawa:</span> <strong>' . h($zas['znak_sprawy']) . '</strong></p>' : '') . '

<div class="body">' . $body . '</div>

' . $wazne_do_before_sig . '

<table style="width:100%;border-collapse:collapse;margin-top:50pt">
  <tr>
    <td style="border:0;vertical-align:bottom;padding-bottom:4pt;font-size:8pt;color:#aaa">' . $qr_html . '</td>
    <td style="width:52%;border:0;border-top:1px solid #444;text-align:center;padding-top:5pt;font-size:9.5pt;line-height:1.4">' . $sig_inner . '</td>
  </tr>
</table>

' . (!$preview && empty($zas['plik_path']) ? '<div style="position:fixed;bottom:10mm;left:0;right:0;font-size:7pt;color:#aaa;font-style:italic;line-height:1.4;text-align:center">Dokument wygenerowany przez system teleinformatyczny. Oryginał opatrzony kwalifikowanym podpisem elektronicznym w rozumieniu art.&nbsp;3 pkt&nbsp;12 rozporządzenia Parlamentu Europejskiego i Rady (UE) nr&nbsp;910/2014 z dnia 23&nbsp;lipca 2014&nbsp;r. w sprawie identyfikacji elektronicznej i usług zaufania w odniesieniu do transakcji elektronicznych na rynku wewnętrznym (eIDAS). Kwalifikowany podpis elektroniczny wywołuje skutki prawne równoważne podpisowi własnoręcznemu (art.&nbsp;25 ust.&nbsp;2 rozporządzenia eIDAS).</div>' : '') . '

</body>
</html>';
}

/**
 * Wydaje zaświadczenie: generuje numer per typ, renderuje treść,
 * rejestruje pismo wychodzące w EZD w podanej koszulce.
 */
function ezd_zas_wydaj(int $zas_id, int $user_id, ?int $sprawa_id_override = null, string $tresc_override = '', bool $include_qr = false, bool $manual = false): array {
    $zas = ezd_zas_get($zas_id);
    if (!$zas) return ['ok' => false, 'error' => 'Wniosek nie istnieje.'];
    if ($zas['status'] === 'wydane') return ['ok' => false, 'error' => 'Już wydane.'];

    $nr = ezd_zas_next_nr((int)$zas['typ_id'], (int)date('Y'));
    if ($manual) {
        $tresc = '';  // wydanie ręczne — treść pusta, numer nadany
    } elseif ($tresc_override !== '') {
        $tresc = str_replace('[NUMER]', htmlspecialchars($nr, ENT_QUOTES, 'UTF-8'), $tresc_override);
    } else {
        $tresc = ezd_zas_render($zas['szablon_tresc'], $zas['dane'], ['nr_zaswiadczenia' => $nr]);
    }

    // Data ważności
    $wazne_do = null;
    $waznosc_dni = (int)($zas['waznosc_dni'] ?? 0);
    if ($waznosc_dni > 0) {
        $wazne_do = date('Y-m-d', strtotime("+{$waznosc_dni} days"));
    }

    // Kod QR weryfikacyjny
    $verify_code = null;
    $qr_on_pdf   = 0;
    if ($include_qr) {
        $verify_code = bin2hex(random_bytes(16));
        $qr_on_pdf   = 1;
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
        } catch (\Throwable $e) {}
    }

    db()->prepare(
        "UPDATE ezd_zaswiadczenia_wlasne
         SET nr_zaswiadczenia=?, tresc_html=?, status='wydane', wazne_do=?,
             verify_code=?, qr_on_pdf=?,
             zatwierdzone_przez=?, zatwierdzone_at=datetime('now'),
             sprawa_id=?, pismo_id=?, updated_at=datetime('now')
         WHERE id=?"
    )->execute([$nr, $tresc, $wazne_do, $verify_code, $qr_on_pdf, $user_id, $sprawa_id, $pismo_id, $zas_id]);

    // Auto-kopia PDF do plików sprawy (tylko gdy ma szablon — plik własny obsługuje ezd_zas_wydaj_plik)
    if ($sprawa_id && !$manual) {
        ezd_zas_attach_pdf_to_sprawa($zas_id, $sprawa_id, $user_id);
    }

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

    $wazne_do    = null;
    $waznosc_dni = (int)($zas['waznosc_dni'] ?? 0);
    if ($waznosc_dni > 0) {
        $wazne_do = date('Y-m-d', strtotime("+{$waznosc_dni} days"));
    }

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
         SET nr_zaswiadczenia=?, status='wydane', plik_path=?, plik_mime=?, wazne_do=?,
             zatwierdzone_przez=?, zatwierdzone_at=datetime('now'),
             sprawa_id=?, pismo_id=?, updated_at=datetime('now')
         WHERE id=?"
    )->execute([$nr, $plik_path, $plik_mime, $wazne_do, $user_id, $sprawa_id, $pismo_id, $zas_id]);

    // Auto-kopia pliku własnego do plików sprawy
    if ($sprawa_id && file_exists($plik_path)) {
        try {
            $dir = UPLOAD_DIR . 'ezd/' . $sprawa_id . '/';
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $nr_safe = preg_replace('/[^a-zA-Z0-9\-_]/', '_', $nr);
            $ext  = pathinfo($plik_path, PATHINFO_EXTENSION) ?: 'pdf';
            $fname = 'zaswiadczenie_' . $nr_safe . '.' . $ext;
            $dest  = $dir . $fname;
            if (!file_exists($dest)) {
                copy($plik_path, $dest);
                db()->prepare(
                    "INSERT INTO ezd_zalaczniki (sprawa_id,pismo_id,filename,original_name,mime_type,file_size,uploaded_by)
                     VALUES (?,?,?,?,?,?,?)"
                )->execute([$sprawa_id, $pismo_id, $fname, $nr . '.' . $ext, $plik_mime, filesize($plik_path), $user_id]);
            }
        } catch (\Throwable $e) {
            error_log('[ezd_zas_attach_plik] ' . $e->getMessage());
        }
    }

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

/** Zaświadczenia złożone przez danego użytkownika (dla panelu wolontariusza). */
function ezd_zas_my_all(int $user_id): array {
    return db_all(
        "SELECT w.id, w.nr_zaswiadczenia, w.status, w.wazne_do, w.created_at,
                w.plik_path, w.verify_code,
                zt.nazwa AS typ_nazwa, zt.opis AS typ_opis
         FROM ezd_zaswiadczenia_wlasne w
         JOIN ezd_zas_typy zt ON zt.id = w.typ_id
         WHERE w.created_by = ?
         ORDER BY w.created_at DESC",
        [$user_id]
    );
}

/**
 * Generuje PDF zaświadczenia i zapisuje jako załącznik do powiązanej sprawy EZD.
 * Wymaga vendor/autoload.php (mPDF). Nie rzuca — błędy loguje.
 */
function ezd_zas_attach_pdf_to_sprawa(int $zas_id, int $sprawa_id, int $user_id): void {
    $zas = ezd_zas_get($zas_id);
    if (!$zas) return;

    try {
        require_once dirname(__DIR__) . '/vendor/autoload.php';

        $mpdf_tmp = UPLOAD_DIR . 'mpdf_tmp';
        if (!is_dir($mpdf_tmp)) @mkdir($mpdf_tmp, 0755, true);

        $ubuntu_cfg = [];
        foreach ([
            dirname(__DIR__) . '/assets/fonts/ubuntu',
            '/usr/share/fonts/truetype/ubuntu',
            '/usr/share/fonts/truetype/ubuntu-font-family',
            '/usr/share/fonts/ubuntu',
        ] as $_udir) {
            if (is_dir($_udir) && file_exists($_udir . '/Ubuntu-R.ttf')) {
                $ubuntu_cfg = ['fontDir' => [$_udir], 'fontdata' => ['ubuntu' => ['R'=>'Ubuntu-R.ttf','B'=>'Ubuntu-B.ttf','I'=>'Ubuntu-RI.ttf','BI'=>'Ubuntu-BI.ttf']], 'default_font' => 'ubuntu'];
                break;
            }
        }

        $mpdf = new \Mpdf\Mpdf(array_merge(['mode'=>'utf-8','format'=>'A4','margin_left'=>25,'margin_right'=>25,'margin_top'=>35,'margin_bottom'=>20,'default_font'=>'dejavusans','tempDir'=>$mpdf_tmp], $ubuntu_cfg));
        $mpdf->WriteHTML(ezd_zas_pdf_html($zas));
        $pdf_string = $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);

        $dir = UPLOAD_DIR . 'ezd/' . $sprawa_id . '/';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);

        $nr_safe = preg_replace('/[^a-zA-Z0-9\-_]/', '_', $zas['nr_zaswiadczenia'] ?? 'zaswiadczenie');
        $fname   = 'zaswiadczenie_' . $nr_safe . '.pdf';
        // Zabezpieczenie przed nadpisaniem
        $dest = $dir . $fname;
        if (file_exists($dest)) {
            $fname = 'zaswiadczenie_' . $nr_safe . '_' . time() . '.pdf';
            $dest  = $dir . $fname;
        }

        file_put_contents($dest, $pdf_string);

        $pismo_id = $zas['pismo_id'] ?: null;
        db()->prepare(
            "INSERT INTO ezd_zalaczniki (sprawa_id,pismo_id,filename,original_name,mime_type,file_size,uploaded_by)
             VALUES (?,?,?,?,?,?,?)"
        )->execute([
            $sprawa_id,
            $pismo_id,
            $fname,
            ($zas['nr_zaswiadczenia'] ?? 'zaswiadczenie') . '.pdf',
            'application/pdf',
            strlen($pdf_string),
            $user_id,
        ]);
    } catch (\Throwable $e) {
        error_log('[ezd_zas_attach_pdf] ' . $e->getMessage());
    }
}

/**
 * Retroaktywne przypisanie kodu QR do już wydanego zaświadczenia.
 * Nie nadpisuje istniejącego verify_code.
 */
function ezd_zas_assign_qr(int $zas_id): ?string {
    $zas = db_one("SELECT id, status, verify_code, plik_path FROM ezd_zaswiadczenia_wlasne WHERE id=?", [$zas_id]);
    if (!$zas || $zas['status'] !== 'wydane') return null;
    if ($zas['verify_code']) return $zas['verify_code'];   // już ma — nic nie rób
    if (!empty($zas['plik_path'])) return null;             // plik własny — QR bez sensu
    $code = bin2hex(random_bytes(16));
    db()->prepare(
        "UPDATE ezd_zaswiadczenia_wlasne SET verify_code=?, qr_on_pdf=1, updated_at=datetime('now') WHERE id=?"
    )->execute([$code, $zas_id]);
    return $code;
}

/** Publiczna weryfikacja zaświadczenia po kodzie — zwraca dane lub null. */
function ezd_zas_by_verify_code(string $code): ?array {
    if (strlen($code) < 8) return null;
    return db_one(
        "SELECT w.nr_zaswiadczenia, w.wnioskodawca_name, w.status, w.wazne_do,
                w.zatwierdzone_at, zt.nazwa AS typ_nazwa
         FROM ezd_zaswiadczenia_wlasne w
         JOIN ezd_zas_typy zt ON zt.id=w.typ_id
         WHERE w.verify_code=? AND w.status='wydane'",
        [$code]
    );
}
