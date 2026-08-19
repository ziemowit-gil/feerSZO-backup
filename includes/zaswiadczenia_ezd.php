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
    try { $pdo->exec("ALTER TABLE ezd_zas_typy ADD COLUMN qr_enabled      INTEGER NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE ezd_zas_typy ADD COLUMN barcode_enabled INTEGER NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE ezd_zas_typy ADD COLUMN nr_format TEXT NOT NULL DEFAULT 'sequential'"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE ezd_zas_typy ADD COLUMN waznosc_adnotacja INTEGER NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}

    // Plik własny (wydany z uploadu, nie z szablonu)
    try { $pdo->exec("ALTER TABLE ezd_zaswiadczenia_wlasne ADD COLUMN plik_path TEXT"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE ezd_zaswiadczenia_wlasne ADD COLUMN plik_mime TEXT NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE ezd_zaswiadczenia_wlasne ADD COLUMN wazne_do TEXT"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE ezd_zaswiadczenia_wlasne ADD COLUMN verify_code TEXT"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE ezd_zaswiadczenia_wlasne ADD COLUMN qr_on_pdf INTEGER NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
    try { $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_zas_verify_code ON ezd_zaswiadczenia_wlasne(verify_code) WHERE verify_code IS NOT NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE ezd_zaswiadczenia_wlasne ADD COLUMN z_urzedu INTEGER NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
    // Powiązanie z umową (przeniesienie modelu z certificates.php)
    try { $pdo->exec("ALTER TABLE ezd_zaswiadczenia_wlasne ADD COLUMN contract_type TEXT"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE ezd_zaswiadczenia_wlasne ADD COLUMN contract_id INTEGER"); } catch (\Throwable $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ezd_zas_contract ON ezd_zaswiadczenia_wlasne(contract_type,contract_id) WHERE contract_type IS NOT NULL"); } catch (\Throwable $e) {}

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
                COALESCE(zt.qr_enabled,0) AS qr_enabled,
                COALESCE(zt.barcode_enabled,0) AS barcode_enabled,
                COALESCE(zt.nr_format,'sequential') AS nr_format,
                COALESCE(zt.waznosc_adnotacja,0) AS waznosc_adnotacja
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
        "SELECT w.*, zt.nazwa AS typ_nazwa, zt.kod AS typ_kod, zt.szablon_tresc, zt.szablon_pola,
                zt.wymaga_akceptacji, zt.jrwa_id, zt.nr_prefix,
                COALESCE(zt.naglowek_html,'') AS naglowek_html,
                COALESCE(zt.podpisujacy,'') AS podpisujacy,
                COALESCE(zt.waznosc_dni,0) AS waznosc_dni,
                COALESCE(zt.qr_enabled,0) AS qr_enabled,
                COALESCE(zt.barcode_enabled,0) AS barcode_enabled,
                COALESCE(zt.nr_format,'sequential') AS nr_format,
                COALESCE(zt.waznosc_adnotacja,0) AS waznosc_adnotacja,
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

function ezd_zas_create(
    int $typ_id, string $name, string $email, array $dane, int $user_id,
    string $contract_type = '', int $contract_id = 0
): int {
    db()->prepare(
        "INSERT INTO ezd_zaswiadczenia_wlasne
         (typ_id,wnioskodawca_name,wnioskodawca_email,dane_json,status,created_by,contract_type,contract_id)
         VALUES (?,?,?,?,?,?,?,?)"
    )->execute([
        $typ_id, $name, $email, json_encode($dane, JSON_UNESCAPED_UNICODE), 'wniosek', $user_id,
        $contract_type ?: null, $contract_id ?: null,
    ]);
    return (int)db()->lastInsertId();
}

/** Zaświadczenia EZD powiązane z daną umową. */
function ezd_zas_for_contract(string $type, int $id): array {
    try {
        return db_all(
            "SELECT w.*, zt.nazwa AS typ_nazwa, u.name AS created_by_name
             FROM ezd_zaswiadczenia_wlasne w
             JOIN ezd_zas_typy zt ON zt.id=w.typ_id
             LEFT JOIN users u ON u.id=w.created_by
             WHERE w.contract_type=? AND w.contract_id=?
             ORDER BY w.created_at DESC",
            [$type, $id]
        );
    } catch (\Throwable $e) { return []; }
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
        if (!isset($vars[$m[1]])) return '';
        $v = (string)$vars[$m[1]];
        // Wartości HTML (zaczynają się od '<') wstrzykujemy bez escapowania
        return ($v !== '' && $v[0] === '<') ? $v : htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }, $szablon);
}

/**
 * Oblicza tokeny pochodne dla OSW-ST (Zaświadczenie dla studenta).
 * Wywołaj przed ezd_zas_render() i przed ezd_zas_create().
 */
function ezd_zas_compute_derived_tokens_osw_st(array $dane): array {
    $zen = ($dane['plec'] ?? '') === 'kobieta';
    $dane['student_forma']  = $zen ? 'studentka' : 'student';
    $dane['podjal']         = $zen ? 'podjęła'   : 'podjął';
    $kierunek = trim($dane['kierunek'] ?? '');
    $dane['kierunek_fraza'] = $kierunek !== '' ? ', kierunek ' . $kierunek : '';
    if (empty($dane['uczelnia_celownik'])) {
        $dane['uczelnia_celownik'] = $dane['uczelnia'] ?? '';
    }
    $vw = $zen ? 'Wolontariuszki' : 'Wolontariusza';

    // Akapit uczelni / rekrutacja
    if (!empty($dane['dolacz_akapit_uczelni'])) {
        $g   = trim($dane['suma_godzin'] ?? '');
        $od  = trim($dane['okres_od']    ?? '');
        $do  = trim($dane['okres_do']    ?? '');
        $ucz = trim($dane['uczelnia_celownik'] ?: ($dane['uczelnia'] ?? '')) ?: 'Uczelni';
        $ap  = '<p>Niniejsze zaświadczenie wydaje się na wniosek ' . h($vw) . ' w celu potwierdzenia';
        if ($g !== '') $ap .= ' przepracowania łącznej liczby <strong>' . h($g) . ' godzin</strong>';
        if ($od !== '' || $do !== '') {
            $ap .= ' w okresie od <strong>' . h($od) . '</strong> do <strong>' . h($do) . '</strong>&nbsp;r';
        }
        $ap .= '. Zwracamy się z prośbą do <strong>' . h($ucz) . '</strong> o uwzględnienie powyższego zaangażowania społecznego i przyznanie należnych punktów w procesie rekrutacji.</p>';
        $dane['akapit_uczelni'] = $ap;
    } else {
        $dane['akapit_uczelni'] = '';
    }

    // Korpus Solidarności
    if (!empty($dane['dolacz_korpus'])) {
        $dane['akapit_korpus'] = '<p style="margin-top:10pt;font-size:9.5pt">'
            . 'Godziny wolontariatu zostały potwierdzone i są zarejestrowane w systemie '
            . '<strong>Korpusu Solidarności</strong> (www.korpussolidarnosci.gov.pl/pl).</p>';
    } else {
        $dane['akapit_korpus'] = '';
    }

    // Zamknięcie zobowiązań
    if (!empty($dane['dolacz_zamkniecie_zobowiazan'])) {
        $dz  = trim($dane['data_zamkniecia']  ?? '');
        $uwg = trim($dane['uwagi_zamkniecia'] ?? '');
        $az  = '<p style="margin-top:10pt;padding:8pt 10pt;border:1px solid #888;border-radius:3pt;font-size:9pt">'
             . '<strong>Informacja o zamknięciu zobowiązań:</strong> '
             . 'Potwierdzamy, że wszelkie zobowiązania wynikające ze współpracy zostały prawidłowo rozliczone i zamknięte';
        if ($dz !== '') $az .= ' w dniu <strong>' . h($dz) . '</strong>';
        $az .= '.';
        if ($uwg !== '') $az .= ' ' . h($uwg);
        $az .= '</p>';
        $dane['akapit_zamkniecie'] = $az;
    } else {
        $dane['akapit_zamkniecie'] = '';
    }

    // Opinia
    $opinia = trim($dane['opinia'] ?? '');
    $dane['opinia_akapit'] = $opinia !== ''
        ? '<p style="margin-top:10pt;font-size:9.5pt"><strong>Opinia:</strong> ' . nl2br(h($opinia)) . '</p>'
        : '';

    return $dane;
}

/**
 * Generuje HTML dokumentu zaświadczenia do wydruku/PDF.
 * @param bool $preview Tryb podglądu — numer zastąpiony placeholderem, watermark PROJEKT
 */
function ezd_zas_pdf_html(array $zas, bool $preview = false, array $copy_info = []): string {
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

    // Podpisujący: {{wystawca}} → imię i nazwisko z zatwierdzone_przez
    $podpisujacy = trim($zas['podpisujacy'] ?? '');
    $_wystawca_h = h($zas['zatw_name'] ?? '');
    if ($podpisujacy !== '') {
        $sig_inner = nl2br(str_replace('{{wystawca}}', $_wystawca_h, h($podpisujacy)));
    } elseif ($_wystawca_h) {
        $sig_inner = '<strong>' . $_wystawca_h . '</strong>';
    } else {
        $sig_inner = h($org) . '<br><span style="color:#555;font-size:9pt">' . $data_wyd . '</span>';
    }

    // Data ważności — wyświetlana przed podpisem, czcionka jak treść
    $wazne_do_before_sig = '';
    if (!empty($zas['wazne_do'])) {
        $wdt     = strtotime($zas['wazne_do']);
        $wdt_fmt = $wdt ? (function_exists('date_pl') ? date_pl(date('Y-m-d', $wdt)) : date('d.m.Y', $wdt)) : h($zas['wazne_do']);
        $expired = $wdt && $wdt < time();
        $wazne_do_before_sig = '<p style="margin-top:16pt;' . ($expired ? 'color:#c00;' : '') . '">'
            . ($expired ? '⚠ ' : '') . 'Zaświadczenie ważne do: <strong>' . $wdt_fmt . '</strong>'
            . ($expired ? ' — <em>WYGASŁE</em>' : '') . '</p>';
    } elseif (!empty($zas['waznosc_adnotacja'])) {
        $wazne_do_before_sig = '<p style="margin-top:16pt">Zaświadczenie <strong>bezterminowe</strong>.</p>';
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

    // Kod kreskowy (Code 128) — opcjonalny per typ zaświadczenia
    $barcode_html = '';
    if (!$preview && !empty($zas['barcode_enabled'])) {
        // Treść: numer zaświadczenia | KRS org | 8-znakowy hash (SHA256 seed)
        $bc_nr   = $zas['nr_zaswiadczenia'] ?? '';
        $bc_znak = $zas['znak_sprawy']      ?? '';
        $bc_krs  = function_exists('org_setting') ? (org_setting('org_krs') ?: '') : '';
        $bc_seed = substr(hash('sha256', $bc_nr . '|' . $bc_krs . '|' . ($zas['verify_code'] ?? '')), 0, 8);
        $bc_raw  = $bc_nr . ($bc_krs !== '' ? ' ' . $bc_krs : '') . ' ' . $bc_seed;
        // Code 128 obsługuje /. i spacje; sanityzuj do bezpiecznego zakresu ASCII 32-126
        $bc_code = preg_replace('/[^\x20-\x7e]/', '', $bc_raw);
        if ($bc_code !== '') {
            $bc_label = h($bc_nr);
            if ($bc_znak && $bc_nr && $bc_znak !== $bc_nr) {
                $bc_label .= '<br><span style="font-size:6pt;color:#aaa">' . h($bc_znak) . '</span>';
            }
            try {
                $gen  = new \Picqer\Barcode\BarcodeGeneratorPNG();
                $png  = $gen->getBarcode($bc_code, $gen::TYPE_CODE_128, 1, 16);
                $b64  = base64_encode($png);
                $barcode_html = '<img src="data:image/png;base64,' . $b64 . '" style="height:6mm;display:inline-block">'
                              . '<br><span style="font-size:6pt;font-family:monospace;color:#555">' . $bc_label . '</span>';
            } catch (\Throwable $e) {
                $barcode_html = '';
            }
        }
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
    <td style="width:48%;border:0"></td>
    <td style="width:52%;border:0;border-top:1px solid #444;text-align:center;padding-top:5pt;font-size:9.5pt;line-height:1.4">' . $sig_inner . '</td>
  </tr>
</table>

' . ($barcode_html !== ''
    ? '<div style="text-align:right;margin-top:18pt">' . $barcode_html . '</div>'
    : '') . '

' . ($qr_html !== '' || !empty($copy_info)
    ? '<div style="position:fixed;top:18mm;left:20mm;text-align:center;font-size:7pt;color:#555;line-height:1.3">'
      . (!empty($copy_info) ? '<div style="font-weight:bold;margin-bottom:2pt">Egzemplarz&nbsp;' . (int)$copy_info['egzemplarz'] . '&nbsp;z&nbsp;' . (int)$copy_info['z'] . '</div>' : '')
      . $qr_html . '</div>'
    : '') . '

' . (!$preview && empty($zas['plik_path']) && !empty($zas['wymaga_akceptacji']) ? '<div style="position:fixed;bottom:10mm;left:0;right:0;font-size:7pt;color:#aaa;font-style:italic;line-height:1.4;text-align:center">Dokument wygenerowany przez system teleinformatyczny. Oryginał opatrzony kwalifikowanym podpisem elektronicznym w rozumieniu art.&nbsp;3 pkt&nbsp;12 rozporządzenia Parlamentu Europejskiego i Rady (UE) nr&nbsp;910/2014 z dnia 23&nbsp;lipca 2014&nbsp;r. w sprawie identyfikacji elektronicznej i usług zaufania w odniesieniu do transakcji elektronicznych na rynku wewnętrznym (eIDAS). Kwalifikowany podpis elektroniczny wywołuje skutki prawne równoważne podpisowi własnoręcznemu (art.&nbsp;25 ust.&nbsp;2 rozporządzenia eIDAS).</div>' : '') . '

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

    $nr_format = $zas['nr_format'] ?? 'sequential';
    if ($nr_format === 'date_id') {
        $prefix = trim($zas['nr_prefix'] ?? 'EZD-ZP') ?: 'EZD-ZP';
        $nr = $prefix . '/' . date('dmY') . '/' . $zas_id;
    } else {
        $nr = ezd_zas_next_nr((int)$zas['typ_id'], (int)date('Y'));
    }
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

        $mpdf = new \Mpdf\Mpdf(array_merge(['mode'=>'utf-8','format'=>'A4','margin_left'=>25,'margin_right'=>25,'margin_top'=>20,'margin_bottom'=>20,'default_font'=>'dejavusans','tempDir'=>$mpdf_tmp], $ubuntu_cfg));
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

// ── Seedy wbudowanych typów zaświadczeń — idempotentne ──────────────────────
(function () {
    static $done = false;
    if ($done) return;
    $done = true;
    try { db()->query("SELECT 1 FROM ezd_zas_typy LIMIT 1"); } catch (\Throwable $e) { return; }

    $_tbl = fn(array $rows) => (function () use ($rows) {
        $t = '<table style="width:100%;border-collapse:collapse;margin:0 0 16pt;font-size:10.5pt"><tbody>';
        foreach ($rows as [$l, $k]) {
            $t .= '<tr><td style="padding:5pt 10pt;font-weight:bold;width:42%;border:1px solid #ccc;background:#f5f5f5">'
                . $l . '</td><td style="padding:5pt 10pt;border:1px solid #ccc">' . $k . '</td></tr>';
        }
        return $t . '</tbody></table>';
    })();

    $_ins = function (string $kod, string $nazwa, string $opis, string $tresc, array $pola, string $prefix) {
        if (db_one("SELECT id FROM ezd_zas_typy WHERE kod=?", [$kod])) return;
        try {
            db()->prepare(
                "INSERT INTO ezd_zas_typy
                 (kod,nazwa,opis,szablon_tresc,szablon_pola,wymaga_akceptacji,is_active,nr_prefix,qr_enabled)
                 VALUES (?,?,?,?,?,1,1,?,1)"
            )->execute([$kod, $nazwa, $opis, $tresc, json_encode($pola, JSON_UNESCAPED_UNICODE), $prefix]);
        } catch (\Throwable $e) { error_log('[ezd_zas seed] ' . $e->getMessage()); }
    };

    // Idempotentna aktualizacja istniejących typów — wstrzykuje pole byl_jest jeśli brak
    $_upd_byl_jest = function (string $kod, string $tresc_find, string $tresc_replace) {
        $row = db_one("SELECT id, szablon_pola, szablon_tresc FROM ezd_zas_typy WHERE kod=?", [$kod]);
        if (!$row) return;
        $pola = json_decode($row['szablon_pola'] ?: '[]', true) ?: [];
        if (in_array('byl_jest', array_column($pola, 'name'))) return;
        $nowe = ['name'=>'byl_jest','label'=>'Jest / był(a)','type'=>'select','required'=>true,'options'=>['jest','był/była']];
        $cel_idx = array_search('cel_akapit', array_column($pola, 'name'));
        if ($cel_idx !== false) array_splice($pola, $cel_idx, 0, [$nowe]);
        else $pola[] = $nowe;
        $tresc = str_replace($tresc_find, $tresc_replace, $row['szablon_tresc']);
        try {
            db()->prepare("UPDATE ezd_zas_typy SET szablon_pola=?, szablon_tresc=? WHERE kod=?")
                ->execute([json_encode($pola, JSON_UNESCAPED_UNICODE), $tresc, $kod]);
        } catch (\Throwable $e) { error_log('[ezd_zas seed byl_jest] ' . $e->getMessage()); }
    };

    // 1. Zaświadczenie o wolontariacie
    $_ins(
        'zaswiadczenie_wolontariat',
        'Zaświadczenie o wolontariacie',
        'Potwierdza udział w wolontariacie na podstawie porozumienia wolontariackiego.',
        '<p style="margin-bottom:12pt">Niniejszym zaświadcza się, że <strong>{{imie_nazwisko}}</strong> {{byl_jest}} wolontariuszem/wolontariuszką w <strong>{{organizacja}}</strong> na podstawie Porozumienia o Wolontariacie nr <strong>{{numer_umowy}}</strong>, zawartego dnia {{data_zawarcia}}.</p>' . "\n\n"
        . $_tbl([
            ['Numer porozumienia',    '{{numer_umowy}}'],
            ['Data zawarcia',         '{{data_zawarcia}}'],
            ['Okres wolontariatu od', '{{data_od}}'],
            ['Okres wolontariatu do', '{{data_do}}'],
            ['Zakres działań',        '{{zakres_dzialan}}'],
        ]) . "\n\n"
        . '<p>{{cel_akapit}}</p>',
        [
            ['name'=>'imie_nazwisko',  'label'=>'Imię i nazwisko',                          'type'=>'text',     'required'=>true],
            ['name'=>'numer_umowy',    'label'=>'Numer porozumienia',                        'type'=>'text',     'required'=>true],
            ['name'=>'data_zawarcia',  'label'=>'Data zawarcia (dd.mm.rrrr)',                'type'=>'text',     'required'=>false],
            ['name'=>'data_od',        'label'=>'Okres od (dd.mm.rrrr)',                     'type'=>'text',     'required'=>true],
            ['name'=>'data_do',        'label'=>'Okres do (dd.mm.rrrr lub „bezterminowo")',  'type'=>'text',     'required'=>false],
            ['name'=>'zakres_dzialan', 'label'=>'Zakres działań wolontariackich',            'type'=>'textarea', 'required'=>false],
            ['name'=>'byl_jest',       'label'=>'Jest / był(a)',                             'type'=>'select',   'required'=>true,
             'options'=>['jest','był/była']],
            ['name'=>'cel_akapit',     'label'=>'Cel zaświadczenia (akapit końcowy)',         'type'=>'text',     'required'=>false],
        ],
        'ZAWOL'
    );
    $_upd_byl_jest(
        'zaswiadczenie_wolontariat',
        'był(a) wolontariuszem/wolontariuszką',
        '{{byl_jest}} wolontariuszem/wolontariuszką'
    );

    // Adnotacja o wygaśnięciu porozumienia + natychmiastowe wydanie do ręcznego wydruku
    (function () {
        $row = db_one("SELECT id, szablon_pola, szablon_tresc FROM ezd_zas_typy WHERE kod='zaswiadczenie_wolontariat'");
        if (!$row) return;
        $pola  = json_decode($row['szablon_pola'] ?: '[]', true) ?: [];
        $tresc = $row['szablon_tresc'] ?? '';
        $changed = false;

        if (!in_array('adnotacja', array_column($pola, 'name'))) {
            $pola[] = [
                'name'     => 'adnotacja',
                'label'    => 'Adnotacja o wygaśnięciu porozumienia w dniu (pozostaw puste jeśli aktywne)',
                'type'     => 'text',
                'required' => false,
            ];
            $changed = true;
        }

        if (!str_contains($tresc, '{{adnotacja}}')) {
            $tresc .= "\n\n"
                . '<p style="font-size:10pt;font-style:italic;color:#333;'
                . 'margin-top:14pt;border-left:2px solid #999;padding-left:8pt">{{adnotacja}}</p>';
            $changed = true;
        }

        if ($changed) {
            try {
                db()->prepare("UPDATE ezd_zas_typy SET szablon_pola=?,szablon_tresc=? WHERE kod='zaswiadczenie_wolontariat'")
                    ->execute([json_encode($pola, JSON_UNESCAPED_UNICODE), $tresc]);
            } catch (\Throwable $e) { error_log('[zas seed adnotacja] ' . $e->getMessage()); }
        }

        // Ręczny wydruk: wydaj natychmiast bez oczekiwania na akceptację
        try {
            db()->prepare("UPDATE ezd_zas_typy SET wymaga_akceptacji=0 WHERE kod='zaswiadczenie_wolontariat'")
                ->execute([]);
        } catch (\Throwable $e) {}
    })();

    // 2. Zaświadczenie o zatrudnieniu
    $_ins(
        'zaswiadczenie_zatrudnienie',
        'Zaświadczenie o zatrudnieniu',
        'Potwierdza fakt zatrudnienia na podstawie umowy o pracę.',
        '<p style="margin-bottom:12pt">Niniejszym zaświadcza się, że <strong>{{imie_nazwisko}}</strong> {{byl_jest}} zatrudniony(a) w <strong>{{organizacja}}</strong> na podstawie umowy o pracę nr <strong>{{numer_umowy}}</strong>, zawartej dnia {{data_zawarcia}}.</p>' . "\n\n"
        . $_tbl([
            ['Numer umowy o pracę',   '{{numer_umowy}}'],
            ['Data zawarcia',         '{{data_zawarcia}}'],
            ['Stanowisko',            '{{stanowisko}}'],
            ['Zatrudnienie od',       '{{data_od}}'],
            ['Zatrudnienie do',       '{{data_do}}'],
        ]) . "\n\n"
        . '<p>{{cel_akapit}}</p>',
        [
            ['name'=>'imie_nazwisko', 'label'=>'Imię i nazwisko',                          'type'=>'text', 'required'=>true],
            ['name'=>'numer_umowy',   'label'=>'Numer umowy o pracę',                       'type'=>'text', 'required'=>true],
            ['name'=>'data_zawarcia', 'label'=>'Data zawarcia (dd.mm.rrrr)',                'type'=>'text', 'required'=>false],
            ['name'=>'stanowisko',    'label'=>'Zajmowane stanowisko',                      'type'=>'text', 'required'=>false],
            ['name'=>'data_od',       'label'=>'Zatrudnienie od (dd.mm.rrrr)',               'type'=>'text', 'required'=>true],
            ['name'=>'data_do',       'label'=>'Zatrudnienie do (dd.mm.rrrr lub „bezterminowo")', 'type'=>'text', 'required'=>false],
            ['name'=>'byl_jest',      'label'=>'Jest / był(a)',                             'type'=>'select', 'required'=>true,
             'options'=>['jest','był/była']],
            ['name'=>'cel_akapit',    'label'=>'Cel zaświadczenia (akapit końcowy)',         'type'=>'text', 'required'=>false],
        ],
        'ZAWPR'
    );
    $_upd_byl_jest(
        'zaswiadczenie_zatrudnienie',
        'jest/był(a) zatrudniony(a)',
        '{{byl_jest}} zatrudniony(a)'
    );

    // 3. Zaświadczenie o współpracy / wykonaniu umowy
    $_ins(
        'zaswiadczenie_wspolpraca',
        'Zaświadczenie o współpracy / wykonaniu umowy',
        'Potwierdza realizację umowy cywilnoprawnej lub współpracę z organizacją.',
        '<p style="margin-bottom:12pt">Niniejszym zaświadcza się, że <strong>{{imie_nazwisko}}</strong> {{byl_jest}} wykonawcą zlecenia na rzecz <strong>{{organizacja}}</strong> na podstawie {{typ_umowy}} nr <strong>{{numer_umowy}}</strong>, zawartej dnia {{data_zawarcia}}.</p>' . "\n\n"
        . $_tbl([
            ['Typ umowy',              '{{typ_umowy}}'],
            ['Numer umowy',            '{{numer_umowy}}'],
            ['Data zawarcia',          '{{data_zawarcia}}'],
            ['Okres realizacji od',    '{{data_od}}'],
            ['Okres realizacji do',    '{{data_do}}'],
            ['Przedmiot umowy',        '{{przedmiot}}'],
        ]) . "\n\n"
        . '<p>{{cel_akapit}}</p>',
        [
            ['name'=>'imie_nazwisko', 'label'=>'Imię i nazwisko / nazwa firmy', 'type'=>'text', 'required'=>true],
            ['name'=>'typ_umowy',     'label'=>'Typ umowy', 'type'=>'select', 'required'=>true,
             'options'=>['Umowy zlecenie','Umowy o dzieło','Umowy o świadczenie usług','Umowy współpracy','Innej umowy']],
            ['name'=>'numer_umowy',   'label'=>'Numer umowy',                                  'type'=>'text',     'required'=>true],
            ['name'=>'data_zawarcia', 'label'=>'Data zawarcia (dd.mm.rrrr)',                    'type'=>'text',     'required'=>false],
            ['name'=>'data_od',       'label'=>'Realizacja od (dd.mm.rrrr)',                    'type'=>'text',     'required'=>true],
            ['name'=>'data_do',       'label'=>'Realizacja do (dd.mm.rrrr)',                    'type'=>'text',     'required'=>false],
            ['name'=>'przedmiot',     'label'=>'Przedmiot umowy',                               'type'=>'textarea', 'required'=>false],
            ['name'=>'byl_jest',      'label'=>'Jest / był(a)',                                 'type'=>'select',   'required'=>true,
             'options'=>['jest','był/była']],
            ['name'=>'cel_akapit',    'label'=>'Cel zaświadczenia (akapit końcowy)',             'type'=>'text',     'required'=>false],
        ],
        'ZAWWS'
    );
    $_upd_byl_jest(
        'zaswiadczenie_wspolpraca',
        'wykonał(a) zlecenie na rzecz',
        '{{byl_jest}} wykonawcą zlecenia na rzecz'
    );

    // Ważność 30 dni dla wszystkich 4 typów (tylko gdzie waznosc_dni=0/NULL — nie nadpisuje)
    foreach (['zaswiadczenie_wolontariat','zaswiadczenie_zatrudnienie','zaswiadczenie_wspolpraca','zaswiadczenie_umowy'] as $_k) {
        try { db()->prepare("UPDATE ezd_zas_typy SET waznosc_dni=30 WHERE kod=? AND COALESCE(waznosc_dni,0)=0")->execute([$_k]); }
        catch (\Throwable $e) {}
    }
    // Ustaw podpisujacy = "{{wystawca}}\nPrezes" dla 4 typów gdzie nie ma jeszcze tokenu
    foreach (['zaswiadczenie_wolontariat','zaswiadczenie_zatrudnienie','zaswiadczenie_wspolpraca','zaswiadczenie_umowy'] as $_k) {
        try { db()->prepare("UPDATE ezd_zas_typy SET podpisujacy=? WHERE kod=? AND COALESCE(podpisujacy,'') NOT LIKE '%{{wystawca}}%'")->execute(["{{wystawca}}\nPrezes", $_k]); }
        catch (\Throwable $e) {}
    }

    // 4a. Oświadczenie dla studenta o współpracy
    $_ins(
        'oswiadczenie_student_wspolpraca',
        'Zaświadczenie dla studenta o współpracy',
        'Zaświadczenie wystawiane na potrzeby uczelni potwierdzające aktywną lub zakończoną współpracę studenta z organizacją.',
        '<p style="margin-bottom:12pt">Niniejszym zaświadcza się, że <strong>{{imie_nazwisko}}</strong>,'
        . ' {{student_forma}} <strong>{{uczelnia_celownik}}</strong>{{kierunek_fraza}}, {{podjal}} współpracę z <strong>{{organizacja}}</strong>.</p>'
        . "\n\n"
        . $_tbl([
            ['Imię i nazwisko',          '{{imie_nazwisko}}'],
            ['Uczelnia',                 '{{uczelnia}}'],
            ['Kierunek / wydział',       '{{kierunek}}'],
            ['Rok studiów',              '{{rok_studiow}}'],
            ['Forma współpracy',         '{{forma_wspolpracy}}'],
            ['Współpraca od',            '{{data_od}}'],
            ['Współpraca do',            '{{data_do}}'],
            ['Zakres działań / zadania', '{{zakres}}'],
        ])
        . "\n\n"
        . '{{akapit_uczelni}}'
        . "\n\n"
        . '{{akapit_korpus}}'
        . "\n\n"
        . '{{akapit_zamkniecie}}'
        . "\n\n"
        . '{{opinia_akapit}}',
        [
            ['name'=>'plec',             'label'=>'Płeć', 'type'=>'select', 'required'=>true,
             'options'=>['mężczyzna','kobieta']],
            ['name'=>'imie_nazwisko',    'label'=>'Imię i nazwisko',                               'type'=>'text',     'required'=>true],
            ['name'=>'uczelnia',         'label'=>'Uczelnia (mianownik, np. Uniwersytet Gdański)', 'type'=>'text',     'required'=>true],
            ['name'=>'uczelnia_celownik','label'=>'Uczelnia – forma zależna (np. Uniwersytetu Gdańskiego, pozostaw puste = jak wyżej)', 'type'=>'text', 'required'=>false],
            ['name'=>'kierunek',         'label'=>'Kierunek / wydział (opcjonalne)',               'type'=>'text',     'required'=>false],
            ['name'=>'rok_studiow',      'label'=>'Rok studiów (opcjonalne)',                      'type'=>'text',     'required'=>false],
            ['name'=>'forma_wspolpracy', 'label'=>'Forma współpracy', 'type'=>'select',            'required'=>true,
             'options'=>['wolontariat','porozumienie o współpracy','umowa zlecenie','umowa o pracę','praktyka/staż','inne']],
            ['name'=>'data_od',          'label'=>'Współpraca od (dd.mm.rrrr)',                    'type'=>'text',     'required'=>true],
            ['name'=>'data_do',          'label'=>'Współpraca do (lub „nadal")',                   'type'=>'text',     'required'=>false],
            ['name'=>'zakres',           'label'=>'Zakres działań / zadania',                      'type'=>'textarea', 'required'=>false],
            ['name'=>'byl_aktywny',      'label'=>'Status (np. „Współpraca trwa nadal." / „Współpraca zakończona.")', 'type'=>'text', 'required'=>false],
            ['name'=>'dolacz_akapit_uczelni', 'label'=>'Dołącz akapit uczelni dla celów rekrutacji (tzw. punkty)', 'type'=>'checkbox', 'required'=>false],
            ['name'=>'suma_godzin',      'label'=>'Łączna liczba godzin',                         'type'=>'text',     'required'=>false],
            ['name'=>'okres_od',         'label'=>'Okres zliczonych godzin: od (dd.mm.rrrr)',      'type'=>'text',     'required'=>false],
            ['name'=>'okres_do',         'label'=>'Okres zliczonych godzin: do (dd.mm.rrrr)',      'type'=>'text',     'required'=>false],
            ['name'=>'dolacz_korpus',     'label'=>'Potwierdzone w Korpusie Solidarności',          'type'=>'checkbox', 'required'=>false],
            ['name'=>'dolacz_zamkniecie_zobowiazan', 'label'=>'Połącz z informacją o zamknięciu zobowiązań', 'type'=>'checkbox', 'required'=>false],
            ['name'=>'data_zamkniecia',  'label'=>'Data zamknięcia zobowiązań (dd.mm.rrrr)',       'type'=>'text',     'required'=>false],
            ['name'=>'uwagi_zamkniecia', 'label'=>'Uwagi do zamknięcia (opcjonalne)',              'type'=>'textarea', 'required'=>false],
            ['name'=>'opinia',           'label'=>'Opinia (opcjonalna)',                           'type'=>'textarea', 'required'=>false],
        ],
        'OSW-ST'
    );
    // Natychmiastowe wydanie (ręczny wydruk) — brak eIDAS automatycznie (wymaga_akceptacji=0)
    try { db()->prepare("UPDATE ezd_zas_typy SET wymaga_akceptacji=0 WHERE kod='oswiadczenie_student_wspolpraca'")->execute([]); }
    catch (\Throwable $e) {}
    // Idempotentna aktualizacja szablonu pól — dodaje nowe pola jeśli brakuje
    (function () {
        $row = db_one("SELECT id, szablon_pola, szablon_tresc FROM ezd_zas_typy WHERE kod='oswiadczenie_student_wspolpraca'");
        if (!$row) return;
        $pola  = json_decode($row['szablon_pola'] ?: '[]', true) ?: [];
        $names = array_column($pola, 'name');
        $new_fields = [
            ['name'=>'plec', 'label'=>'Płeć', 'type'=>'select', 'required'=>true, 'options'=>['mężczyzna','kobieta']],
            ['name'=>'uczelnia_celownik', 'label'=>'Uczelnia – forma zależna (np. Uniwersytetu Gdańskiego)', 'type'=>'text', 'required'=>false],
            ['name'=>'dolacz_akapit_uczelni', 'label'=>'Dołącz akapit uczelni dla celów rekrutacji (tzw. punkty)', 'type'=>'checkbox', 'required'=>false],
            ['name'=>'suma_godzin', 'label'=>'Łączna liczba godzin', 'type'=>'text', 'required'=>false],
            ['name'=>'okres_od',    'label'=>'Okres zliczonych godzin: od (dd.mm.rrrr)', 'type'=>'text', 'required'=>false],
            ['name'=>'okres_do',    'label'=>'Okres zliczonych godzin: do (dd.mm.rrrr)', 'type'=>'text', 'required'=>false],
            ['name'=>'dolacz_zamkniecie_zobowiazan', 'label'=>'Połącz z informacją o zamknięciu zobowiązań', 'type'=>'checkbox', 'required'=>false],
            ['name'=>'data_zamkniecia',  'label'=>'Data zamknięcia zobowiązań (dd.mm.rrrr)', 'type'=>'text', 'required'=>false],
            ['name'=>'uwagi_zamkniecia', 'label'=>'Uwagi do zamknięcia (opcjonalne)', 'type'=>'textarea', 'required'=>false],
            ['name'=>'opinia',           'label'=>'Opinia (opcjonalna)', 'type'=>'textarea', 'required'=>false],
        ];
        $changed = false;
        foreach ($new_fields as $f) {
            if (!in_array($f['name'], $names)) { $pola[] = $f; $changed = true; }
        }
        // Dodaj token {{akapit_zamkniecie}} do treści jeśli brakuje
        $tresc = $row['szablon_tresc'] ?? '';
        if (!str_contains($tresc, '{{akapit_zamkniecie}}')) {
            $tresc = str_replace(
                '<p style="font-size:9pt;color:#666">Oświadczenie wydano',
                '{{akapit_zamkniecie}}' . "\n\n" . '<p style="font-size:9pt;color:#666">Oświadczenie wydano',
                $tresc
            );
            if (!str_contains($tresc, '{{akapit_zamkniecie}}')) {
                $tresc .= "\n\n{{akapit_zamkniecie}}";
            }
            $changed = true;
        }
        // Dodaj token {{akapit_korpus}} jeśli brakuje
        if (!str_contains($tresc, '{{akapit_korpus}}')) {
            $tresc = str_replace('{{akapit_uczelni}}', '{{akapit_uczelni}}' . "\n\n" . '{{akapit_korpus}}', $tresc);
            if (!str_contains($tresc, '{{akapit_korpus}}')) {
                $tresc = str_replace('{{akapit_zamkniecie}}', '{{akapit_korpus}}' . "\n\n" . '{{akapit_zamkniecie}}', $tresc);
            }
            $changed = true;
        }
        // Dodaj pole dolacz_korpus jeśli brakuje
        $has_korpus = false;
        foreach ($pola as $_f) { if ($_f['name'] === 'dolacz_korpus') { $has_korpus = true; break; } }
        if (!$has_korpus) {
            $zamk_idx = null;
            foreach ($pola as $_i => $_f) { if ($_f['name'] === 'dolacz_zamkniecie_zobowiazan') { $zamk_idx = $_i; break; } }
            $new_f = ['name'=>'dolacz_korpus', 'label'=>'Potwierdzone w Korpusie Solidarności', 'type'=>'checkbox', 'required'=>false];
            if ($zamk_idx !== null) {
                array_splice($pola, $zamk_idx, 0, [$new_f]);
            } else {
                $pola[] = $new_f;
            }
            $changed = true;
        }
        // Dodaj token {{opinia_akapit}} jeśli brakuje
        if (!str_contains($tresc, '{{opinia_akapit}}')) {
            $tresc .= "\n\n{{opinia_akapit}}";
            $changed = true;
        }
        // Usuń niechciane fragmenty z szablonu (regex — odporna na whitespace/styl)
        $tresc = str_replace('<p>{{byl_aktywny}}</p>', '', $tresc);
        $tresc_clean = preg_replace(
            '/<p[^>]*>Oświadczenie wydano na prośbę zainteresowanego\(ej\)[^<]*<\/p>/u',
            '',
            $tresc
        );
        if ($tresc_clean !== $tresc) { $tresc = $tresc_clean; $changed = true; }
        $tresc = trim($tresc);
        // Wyczyść też już wydane zaświadczenia tego typu (tresc_html)
        try {
            $issued = db_all(
                "SELECT w.id, w.tresc_html, w.dane FROM ezd_zaswiadczenia_wlasne w
                 JOIN ezd_zas_typy zt ON zt.id=w.typ_id
                 WHERE zt.kod='oswiadczenie_student_wspolpraca' AND w.tresc_html != ''"
            );
            // Frazy do usunięcia z tresc_html (różne formy)
            $_cleanup_phrases = [
                '<p>Oświadczenie wydano na pro',  // partial match — strip whole <p>
            ];
            foreach ($issued as $_w) {
                $_ht = $_w['tresc_html'];
                // Usuń akapit byl_aktywny — może mieć dowolną treść między <p>…</p>
                // Rozpoznajemy po tym że poprzedza akapit_uczelni lub jest przed nim pusty wiersz
                // Najpewniej: usuń <p>...</p> gdzie treść to tylko tekst (nie zaczyna się od '<')
                // Bardziej niezawodnie: przerenderuj z aktualnego szablonu
                $dane = json_decode($_w['dane'] ?? '{}', true) ?: [];
                // Usuń zdanie zamknięcia — regex odporny na whitespace/styl
                $_ht = preg_replace(
                    '/<p[^>]*>Oświadczenie wydano na prośbę zainteresowanego\(ej\)[^<]*<\/p>/u',
                    '',
                    $_ht
                );
                // Usuń <p>{{byl_aktywny}}</p> jeśli nie był zamieniony
                $_ht = str_replace('<p>{{byl_aktywny}}</p>', '', $_ht);
                // Usuń wyrenderowaną wartość byl_aktywny z paragrafów
                $byl_val = trim($dane['byl_aktywny'] ?? '');
                if ($byl_val !== '') {
                    $_ht = str_replace('<p>' . htmlspecialchars($byl_val, ENT_QUOTES, 'UTF-8') . '</p>', '', $_ht);
                    $_ht = str_replace('<p>' . $byl_val . '</p>', '', $_ht);
                }
                $_ht = trim($_ht);
                if ($_ht !== trim($_w['tresc_html'])) {
                    db()->prepare("UPDATE ezd_zaswiadczenia_wlasne SET tresc_html=? WHERE id=?")->execute([$_ht, $_w['id']]);
                }
            }
        } catch (\Throwable $e) { error_log('[zas seed osw-st tresc_html] ' . $e->getMessage()); }
        if ($changed) {
            try { db()->prepare("UPDATE ezd_zas_typy SET szablon_pola=?,szablon_tresc=?,nazwa=?,opis=?,nr_format=?,nr_prefix=?,waznosc_dni=?,waznosc_adnotacja=?,barcode_enabled=1 WHERE kod='oswiadczenie_student_wspolpraca'")->execute([
                json_encode($pola, JSON_UNESCAPED_UNICODE),
                $tresc,
                'Zaświadczenie dla studenta o współpracy',
                'Zaświadczenie wystawiane na potrzeby uczelni potwierdzające aktywną lub zakończoną współpracę studenta z organizacją.',
                'date_id', 'EZD-ZP', 60, 1,
            ]); }
            catch (\Throwable $e) { error_log('[zas seed osw-st] ' . $e->getMessage()); }
        } else {
            // Zawsze wymuś flagi niezależnie od pól
            try { db()->prepare("UPDATE ezd_zas_typy SET nr_format='date_id',nr_prefix='EZD-ZP',waznosc_dni=60,waznosc_adnotacja=1,barcode_enabled=1,nazwa='Zaświadczenie dla studenta o współpracy',szablon_tresc=? WHERE kod='oswiadczenie_student_wspolpraca'")->execute([$tresc]); }
            catch (\Throwable $e) {}
        }
    })();

    // 4. Ogólne zaświadczenie o posiadanej umowie (tabela danych)
    $_ins(
        'zaswiadczenie_umowy',
        'Zaświadczenie o posiadanej umowie',
        'Zaświadczenie potwierdzające zawarcie umowy lub porozumienia z organizacją. Dane umowy w formie tabeli.',
        '<p style="margin-bottom:12pt">Niniejszym zaświadcza się, że <strong>{{imie_nazwisko}}</strong> zawarł(a) z <strong>{{organizacja}}</strong> umowę / porozumienie na następujących warunkach:</p>' . "\n\n"
        . $_tbl([
            ['Imię i nazwisko',             '{{imie_nazwisko}}'],
            ['Typ umowy',                   '{{typ_umowy}}'],
            ['Numer umowy / porozumienia',  '{{numer_umowy}}'],
            ['Data zawarcia',               '{{data_zawarcia}}'],
            ['Obowiązuje od',               '{{data_od}}'],
            ['Obowiązuje do',               '{{data_do}}'],
            ['Stanowisko / funkcja / rola', '{{stanowisko}}'],
            ['Miejsce realizacji',          '{{miejsce}}'],
            ['Status umowy',                '{{status_umowy}}'],
        ]) . "\n\n"
        . '<p>Zaświadczenie wydano na wniosek zainteresowanego / z inicjatywy organizacji.</p>',
        [
            ['name'=>'imie_nazwisko', 'label'=>'Imię i nazwisko',               'type'=>'text',   'required'=>true],
            ['name'=>'typ_umowy',     'label'=>'Typ umowy', 'type'=>'select', 'required'=>true,
             'options'=>['Porozumienie wolontariackie','Umowa o pracę','Umowa zlecenie','Umowa o dzieło','Inne']],
            ['name'=>'numer_umowy',   'label'=>'Numer umowy / porozumienia',    'type'=>'text',   'required'=>true],
            ['name'=>'data_zawarcia', 'label'=>'Data zawarcia (dd.mm.rrrr)',     'type'=>'text',   'required'=>false],
            ['name'=>'data_od',       'label'=>'Obowiązuje od (dd.mm.rrrr)',     'type'=>'text',   'required'=>true],
            ['name'=>'data_do',       'label'=>'Obowiązuje do (lub „bezterminowe")', 'type'=>'text', 'required'=>false],
            ['name'=>'stanowisko',    'label'=>'Stanowisko / funkcja / rola',   'type'=>'text',   'required'=>false],
            ['name'=>'miejsce',       'label'=>'Miejsce realizacji',             'type'=>'text',   'required'=>false],
            ['name'=>'status_umowy',  'label'=>'Status umowy', 'type'=>'select', 'required'=>true,
             'options'=>['aktywna','zawieszona','zakończona','rozwiązana']],
        ],
        'ZAS-UM'
    );
})();
