<?php
declare(strict_types=1);

/**
 * modules/ti_dop/lib/Dop5Statement.php — DOP5: oświadczenie operatora zajęć o stanie środków finansowych (wkład własny /
 * zgromadzone zasoby) na dzień, przed uruchomieniem transzy DOP2.
 *
 * 1. Nagłówek: miejscowość i data, dane podmiotu (nazwa, adres, NIP, REGON, KRS — z ustawień organizacji, migawka),
 *    umowa o dofinansowanie / realizację zadania (numer, tytuł projektu / zadania), powiązany wniosek DOP2 (opcjonalnie).
 * 2. Oświadczenie: kwota (liczbowo i słownie), stan na dzień, wyodrębniony rachunek / subkonto.
 * 3. Załączniki: wyciąg / historia rachunku na dzień, zaświadczenie z banku (pliki PDF / JPG / PNG + kratki).
 * 4. Oświadczenia prawne: prawidłowe wydatkowanie i zabezpieczenie środków; prawdziwość danych (odpowiedzialność karna /
 *    zapisy umowy).
 * 5. Podpisy: reprezentacja wg KRS (org_representatives) albo pełnomocnik (rejestr pełnomocnictw) + opcjonalnie główny
 *    księgowy / skarbnik. Podpis elektroniczny — każda osoba dostaje osobny link (e-mail), stempel: imię i nazwisko, czas, IP;
 *    albo wersja papierowa — skan podpisanego dokumentu.
 * Numer: DOP5/NNNNNN/RRRR. Stany: signing → signed (wszyscy podpisali / skan) | cancelled.
 */
require_once __DIR__ . '/dopSchema.php';
require_once __DIR__ . '/DopForm.php';
require_once __DIR__ . '/DopService.php';

function dop5Migrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    dopMigrate();
    if (function_exists('szo_schema_current') && szo_schema_current('ti_dop5_migrate', __FILE__)) return;
    $pk = (defined('DB_TYPE') && DB_TYPE !== 'sqlite') ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    db()->exec("CREATE TABLE IF NOT EXISTS dop5_statements (
        id {$pk}, seq INTEGER NOT NULL DEFAULT 0, number VARCHAR(40) NOT NULL DEFAULT '', status VARCHAR(10) NOT NULL DEFAULT 'signing',
        place VARCHAR(120) NOT NULL DEFAULT '', data TEXT, files TEXT, sign_mode VARCHAR(8) NOT NULL DEFAULT 'link',
        scan_path VARCHAR(255) NOT NULL DEFAULT '', scan_name VARCHAR(190) NOT NULL DEFAULT '', pdf_path VARCHAR(255) NOT NULL DEFAULT '',
        dop_request_id INTEGER, created_by INTEGER, created_name VARCHAR(190) NOT NULL DEFAULT '', created_ip VARCHAR(64) NOT NULL DEFAULT '', created_at VARCHAR(19), signed_at VARCHAR(19)
    )");
    db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_dop5_seq ON dop5_statements(seq)");
    db()->exec("CREATE TABLE IF NOT EXISTS dop5_signers (
        id {$pk}, statement_id INTEGER NOT NULL, name VARCHAR(190) NOT NULL DEFAULT '', role VARCHAR(20) NOT NULL DEFAULT 'reprezentant',
        function_title VARCHAR(190) NOT NULL DEFAULT '', basis VARCHAR(255) NOT NULL DEFAULT '', email VARCHAR(190) NOT NULL DEFAULT '',
        token_hash VARCHAR(64) NOT NULL DEFAULT '', token_expires VARCHAR(19), signed_at VARCHAR(19), signed_name VARCHAR(190) NOT NULL DEFAULT '', signed_ip VARCHAR(64) NOT NULL DEFAULT ''
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_dop5_signers ON dop5_signers(statement_id)");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_dop5_token ON dop5_signers(token_hash)");
}

final class Dop5Statement
{
    public const STATEMENTS = [
        'wydatkowanie' => 'Oświadczam(-y), że wskazane środki zostaną prawidłowo wydatkowane i zabezpieczone wyłącznie na cele zgodne z umową o dofinansowanie / realizację zadania.',
        'prawdziwosc'  => 'Oświadczam(-y), że dane zawarte w niniejszym oświadczeniu są zgodne z prawdą — pod rygorem odpowiedzialności karnej za złożenie fałszywego oświadczenia (art. 233 § 1 Kodeksu karnego) albo zgodnie z zapisami umowy o dofinansowanie.',
    ];

    private static function ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64); }

    public static function org(): array
    {
        $g = fn(string $k) => trim((string)org_setting($k));
        return ['name' => $g('org_name') ?: ldOrgName(), 'address' => $g('org_adres'), 'nip' => $g('org_nip'), 'regon' => $g('org_regon'), 'krs' => $g('org_krs'), 'city' => $g('org_miejscowosc')];
    }

    /** Podpowiedzi osób podpisujących: reprezentacja (KRS) i aktywne pełnomocnictwa. */
    public static function suggestions(): array
    {
        $out = [];
        try { foreach (db_all("SELECT name, title FROM org_representatives WHERE is_active = 1 ORDER BY sort_order, name") as $r) $out[] = ['name' => $r['name'], 'function_title' => $r['title'], 'basis' => 'reprezentacja zgodnie z KRS', 'email' => '', 'role' => 'reprezentant']; } catch (\Throwable $e) {}
        try {
            foreach (db_all("SELECT numer, pelnomocnik, pelnomocnik_email, zakres FROM pelnomocnictwa WHERE (data_odwolania IS NULL OR data_odwolania = '') AND (data_waznosci IS NULL OR data_waznosci = '' OR data_waznosci >= ?) ORDER BY data_udzielenia DESC LIMIT 20", [ldToday()]) as $r)
                $out[] = ['name' => $r['pelnomocnik'], 'function_title' => 'pełnomocnik', 'basis' => 'pełnomocnictwo nr ' . $r['numer'], 'email' => (string)$r['pelnomocnik_email'], 'role' => 'reprezentant'];
        } catch (\Throwable $e) {}
        return $out;
    }

    public static function get(int $id): ?array
    {
        dop5Migrate();
        $r = db_one("SELECT * FROM dop5_statements WHERE id = ?", [$id]);
        if (!$r) return null;
        $r['d'] = json_decode((string)$r['data'], true) ?: [];
        $r['filesList'] = json_decode((string)($r['files'] ?? ''), true) ?: [];
        $r['signers'] = db_all("SELECT * FROM dop5_signers WHERE statement_id = ? ORDER BY id", [$id]);
        return $r;
    }

    public static function search(): array
    {
        dop5Migrate();
        return db_all("SELECT * FROM dop5_statements ORDER BY id DESC LIMIT 200");
    }

    /** @return array{id:int, errors:array, report:list<string>} */
    public static function create(array $in, array $files, string $signMode, array $actor): array
    {
        dop5Migrate();
        $s = fn(string $k, int $max = 300) => LdInput::str($in, $k, $max);
        $e = [];
        $org = self::org();
        foreach (['name', 'address', 'nip', 'regon', 'krs'] as $k) { $v = $s('org_' . $k); if ($v !== '') $org[$k] = $v; }
        $d = ['org' => $org, 'umowa_nr' => $s('umowa_nr', 120), 'tytul' => $s('tytul', 400), 'kwota' => str_replace([' ', ','], ['', '.'], $s('kwota', 30)),
              'as_of' => LdInput::date($in, 'as_of'), 'rachunek' => strtoupper(preg_replace('/\s+/', '', $s('rachunek', 40))), 'rachunek_opis' => $s('rachunek_opis', 190),
              'dop2' => $s('dop2', 60), 'zal_wyciag' => LdInput::bool($in, 'zal_wyciag'), 'zal_zaswiadczenie' => LdInput::bool($in, 'zal_zaswiadczenie')];
        foreach (array_keys(self::STATEMENTS) as $k) { $d['osw_' . $k] = LdInput::bool($in, 'osw_' . $k); if (!$d['osw_' . $k]) $e['osw_' . $k] = 'Zaznacz oświadczenie.'; }
        if ($d['umowa_nr'] === '') $e['umowa_nr'] = 'Podaj numer umowy o dofinansowanie / realizację zadania.';
        if ($d['tytul'] === '') $e['tytul'] = 'Podaj tytuł projektu / zadania publicznego.';
        if (!is_numeric($d['kwota']) || (float)$d['kwota'] <= 0) $e['kwota'] = 'Podaj kwotę środków (PLN).'; else $d['kwota'] = number_format((float)$d['kwota'], 2, '.', '');
        if ($d['as_of'] === '') $e['as_of'] = 'Podaj dzień stanu środków.';
        if (!preg_match('/^(PL)?\d{26}$/', $d['rachunek'])) $e['rachunek'] = 'Numer rachunku: 26 cyfr (opcjonalnie z PL).'; else $d['rachunek'] = 'PL' . preg_replace('/^PL/', '', $d['rachunek']);
        $place = $s('place', 120);
        if ($place === '') $e['place'] = 'Podaj miejscowość.';
        $signers = [];
        foreach ((array)($in['signers'] ?? []) as $sg) {
            if (!is_array($sg) || trim((string)($sg['name'] ?? '')) === '') continue;
            $row = ['name' => LdInput::str($sg, 'name', 190), 'function_title' => LdInput::str($sg, 'function_title', 190), 'basis' => LdInput::str($sg, 'basis', 255),
                    'email' => LdInput::str($sg, 'email', 190), 'role' => ($sg['role'] ?? '') === 'ksiegowy' ? 'ksiegowy' : 'reprezentant'];
            if ($signMode !== 'paper' && !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) { $e['signers'] = 'Każda osoba podpisująca elektronicznie musi mieć e-mail (' . $row['name'] . ').'; continue; }
            $signers[] = $row;
        }
        if (!array_filter($signers, fn($x) => $x['role'] === 'reprezentant')) $e['signers'] = $e['signers'] ?? 'Dodaj co najmniej jedną osobę uprawnioną do reprezentacji.';
        if ($e) return ['id' => 0, 'errors' => $e, 'report' => []];
        $up = [];
        foreach (['wyciag' => 'Wyciąg / historia rachunku', 'zaswiadczenie' => 'Zaświadczenie z banku'] as $fk => $fl) {
            $f = $files['plik_' . $fk] ?? null;
            if ($f && ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) { [$rel, $nm] = DopService::storeUpload($f, 'dop5' . $fk); $up[] = ['kind' => $fk, 'label' => $fl, 'path' => $rel, 'name' => $nm]; $d['zal_' . $fk] = true; }
        }
        $year = date('Y');
        for ($t = 0; ; $t++) {
            $seq = 1 + (int)(db_one("SELECT MAX(seq) m FROM dop5_statements")['m'] ?? 0);
            try {
                $id = db_insert('dop5_statements', ['seq' => $seq, 'number' => 'DOP5/' . str_pad((string)$seq, 6, '0', STR_PAD_LEFT) . '/' . $year, 'status' => 'signing', 'place' => $place,
                    'data' => json_encode($d, JSON_UNESCAPED_UNICODE), 'files' => json_encode($up, JSON_UNESCAPED_UNICODE), 'sign_mode' => $signMode === 'paper' ? 'paper' : 'link',
                    'created_by' => ($actor['id'] ?? 0) ?: null, 'created_name' => (string)$actor['name'], 'created_ip' => self::ip(), 'created_at' => ldNow()]);
                break;
            } catch (\PDOException $ex) { if ($t >= 4) throw $ex; }
        }
        foreach ($signers as $sg) db_insert('dop5_signers', $sg + ['statement_id' => $id]);
        $report = [];
        if ($signMode !== 'paper') foreach (self::get($id)['signers'] as $sg) $report[] = $sg['name'] . ': ' . self::sendLink((int)$sg['id'], $actor);
        else $report[] = 'wydrukuj, podpisz i wgraj skan na karcie DOP5';
        return ['id' => $id, 'errors' => [], 'report' => $report];
    }

    public static function signUrl(string $token): string
    {
        return rtrim(APP_URL, '/') . '/karty30/ti/wniosekDop.php?' . http_build_query(['_route' => '/dop5/' . $token]);
    }

    public static function sendLink(int $signerId, array $actor): string
    {
        $sg = db_one("SELECT * FROM dop5_signers WHERE id = ?", [$signerId]);
        $r = $sg ? self::get((int)$sg['statement_id']) : null;
        if (!$r || $r['status'] !== 'signing' || !empty($sg['signed_at'])) throw new DomainException('Ta osoba nie czeka na podpis.');
        $token = bin2hex(random_bytes(24));
        db_exec("UPDATE dop5_signers SET token_hash = ?, token_expires = ? WHERE id = ?", [hash('sha256', $token), date('Y-m-d H:i:s', time() + LD_TOKEN_DAYS * 86400), $signerId]);
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $link = self::signUrl($token);
        $body = '<p>Dzień dobry,</p><p>prosimy o podpis elektroniczny oświadczenia <b>DOP5 — o stanie środków finansowych</b> nr <b>' . $e($r['number']) . '</b> (' . $e($r['d']['tytul'])
            . '): kwota <b>' . $e(number_format((float)$r['d']['kwota'], 2, ',', ' ')) . ' zł</b> na dzień ' . $e(ldDate($r['d']['as_of'])) . '. Podpisujesz jako: ' . $e(trim($sg['function_title'] . ' — ' . $sg['basis'], ' —')) . '.</p>';
        $res = (new LicenseNotificationService('ti_dop'))->send(LicenseNotificationType::RequestStarted, ['name' => $sg['name'], 'email' => $sg['email']], 'Podpis DOP5 — oświadczenie o stanie środków — ' . $r['number'],
            LicenseNotificationService::layout('Oświadczenie o stanie środków (DOP5)', $body, $link, 'Sprawdź i podpisz', 'DOP5'), '', ['type' => 'dop5', 'id' => (int)$r['id']], $actor, false);
        return LicenseNotificationService::statusLabel($res['channels']['email'] ?? 'skipped');
    }

    public static function bySignToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) return null;
        dop5Migrate();
        $sg = db_one("SELECT * FROM dop5_signers WHERE token_hash = ?", [hash('sha256', $token)]);
        if (!$sg || (string)$sg['token_expires'] < ldNow() || !empty($sg['signed_at'])) return null;
        $r = self::get((int)$sg['statement_id']);
        return $r && $r['status'] === 'signing' ? ['statement' => $r, 'signer' => $sg] : null;
    }

    /** @return array errors */
    public static function sign(array $ctx, string $name, bool $accept): array
    {
        $err = [];
        if (mb_strlen(trim($name)) < 3) $err['name'] = 'Wpisz imię i nazwisko — to Twój podpis.';
        if (!$accept) $err['confirm'] = 'Zaznacz potwierdzenie.';
        if ($err) return $err;
        db_exec("UPDATE dop5_signers SET signed_at = ?, signed_name = ?, signed_ip = ?, token_hash = '' WHERE id = ?", [ldNow(), mb_substr(trim($name), 0, 190), self::ip(), (int)$ctx['signer']['id']]);
        self::checkComplete((int)$ctx['statement']['id'], ['id' => 0, 'name' => trim($name)]);
        return [];
    }

    private static function checkComplete(int $id, array $actor): void
    {
        $r = self::get($id);
        if ($r['status'] !== 'signing') return;
        if ($r['sign_mode'] === 'link' && array_filter($r['signers'], fn($s) => empty($s['signed_at']))) return;
        db_exec("UPDATE dop5_statements SET status = 'signed', signed_at = ? WHERE id = ?", [ldNow(), $id]);
        $out = self::render(self::get($id));
        $dir = rtrim(UPLOAD_DIR, '/') . '/' . DOP_DIR;
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $name = 'dop5_' . $id . '_' . bin2hex(random_bytes(6)) . '.' . $out['ext'];
        file_put_contents($dir . '/' . $name, $out['bytes']);
        db_exec("UPDATE dop5_statements SET pdf_path = ? WHERE id = ?", [DOP_DIR . '/' . $name, $id]);
        (new LicenseNotificationService('ti_dop'))->alertOffice('DOP5 podpisane — ' . $r['number'], '<p>Wszystkie osoby podpisały oświadczenie o stanie środków <b>' . htmlspecialchars($r['number']) . '</b>. Dokument (PDF) jest na karcie DOP5 — można uruchomić transzę DOP2.</p>',
            ['type' => 'dop5', 'id' => $id], $actor, LicenseNotificationType::DeclarationAdmin);
    }

    public static function uploadScan(int $id, array $file, array $actor): void
    {
        $r = self::get($id);
        if (!$r || $r['status'] !== 'signing') throw new DomainException('DOP5 nie czeka na podpis.');
        [$rel, $nm] = DopService::storeUpload($file, 'dop5skan' . $id);
        db_exec("UPDATE dop5_statements SET scan_path = ?, scan_name = ?, sign_mode = 'paper' WHERE id = ?", [$rel, $nm, $id]);
        db_exec("UPDATE dop5_signers SET signed_at = ?, signed_name = name || ' (podpis odręczny — skan)' WHERE statement_id = ? AND signed_at IS NULL", [ldNow(), $id]);
        self::checkComplete($id, $actor);
    }

    public static function cancel(int $id): void
    {
        db_exec("UPDATE dop5_statements SET status = 'cancelled' WHERE id = ? AND status = 'signing'", [$id]);
        db_exec("UPDATE dop5_signers SET token_hash = '' WHERE statement_id = ?", [$id]);
    }

    // ── Wydruk ──────────────────────────────────────────────────────────────────

    public static function html(array $r): string
    {
        $d = $r['d']; $o = $d['org'];
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $box = fn(bool $on) => '<span style="font-size:10.5pt">' . ($on ? '&#9746;' : '&#9744;') . '</span>';
        $n = 0;
        $fld = function (string $label, string $html, string $extra = '') use (&$n): string { $n++; return '<td class="f"' . $extra . '><div class="lb"><b>' . $n . '.</b> ' . htmlspecialchars($label) . '</div><div class="v">' . ($html !== '' ? $html : '&nbsp;') . '</div></td>'; };
        $sec = fn(string $l, string $t, int $c = 2) => '<tr><td class="sec" colspan="' . $c . '"><b>' . $l . '. ' . htmlspecialchars(mb_strtoupper($t)) . '</b></td></tr>';
        $iban = fn(string $v) => 'PL ' . substr(preg_replace('/^PL/', '', $v), 0, 2) . ' ' . implode(' ', str_split(substr(preg_replace('/^PL/', '', $v), 2), 4));
        $h = '<table class="hdr"><tr><td style="width:60%"><b style="font-size:11pt">' . $e($o['name']) . '</b><br><span class="small">' . $e($o['address']) . '</span></td>'
           . '<td style="width:40%;text-align:right">' . $e($r['place'] . ', dnia ' . ldDate(substr((string)$r['created_at'], 0, 10))) . '<div class="small">miejscowość i data sporządzenia</div></td></tr></table>'
           . '<div class="title">DOP5 – OŚWIADCZENIE O STANIE ŚRODKÓW FINANSOWYCH</div><div class="num">Numer: <b>' . $e($r['number']) . '</b>' . ($d['dop2'] !== '' ? ' · dotyczy transzy / wniosku: <b>' . $e($d['dop2']) . '</b>' : '') . '</div>';
        $h .= '<table class="t">' . $sec('1', 'Dane wnioskodawcy / operatora zajęć i umowy', 3) . '<tr>' . $fld('Nazwa podmiotu', '<b>' . $e($o['name']) . '</b>', ' colspan="3"') . '</tr><tr>'
            . $fld('Adres siedziby', $e($o['address']), ' colspan="3"') . '</tr><tr>' . $fld('NIP', $e($o['nip'] ?: '—'), ' width="33%"') . $fld('REGON', $e($o['regon'] ?: '—'), ' width="33%"') . $fld('KRS', $e($o['krs'] ?: '—'), ' width="34%"') . '</tr><tr>'
            . $fld('Numer umowy o dofinansowanie / realizację zadania', '<b>' . $e($d['umowa_nr']) . '</b>', '') . $fld('Tytuł projektu / zadania publicznego', $e($d['tytul']), ' colspan="2"') . '</tr></table>';
        $h .= '<table class="t">' . $sec('2', 'Oświadczenie o stanie środków finansowych') . '<tr><td class="pc" colspan="2">Działając w imieniu ' . $e($o['name']) . ' oświadczam(-y), że na dzień <b>' . $e(ldDate($d['as_of'])) . '</b> podmiot posiada środki finansowe stanowiące wkład własny / zgromadzone zasoby na potrzeby realizacji zadania w kwocie:</td></tr><tr>'
            . $fld('Kwota (PLN)', '<b style="font-size:11pt">' . $e(number_format((float)$d['kwota'], 2, ',', ' ')) . ' PLN</b><div class="small">słownie: ' . $e(dopKwotaSlownie((float)$d['kwota'])) . '</div>', ' width="50%"')
            . $fld('Stan na dzień', '<b>' . $e(ldDate($d['as_of'])) . '</b>', ' width="50%"') . '</tr><tr>'
            . $fld('Wyodrębniony rachunek bankowy / subkonto, na którym znajdują się środki', '<b>' . $e($iban($d['rachunek'])) . '</b>' . ($d['rachunek_opis'] !== '' ? '<div class="small">' . $e($d['rachunek_opis']) . '</div>' : ''), ' colspan="2"') . '</tr></table>';
        $fl = array_column($r['filesList'], 'name', 'kind');
        $h .= '<table class="t">' . $sec('3', 'Załączniki (dokumenty potwierdzające)') . '<tr><td class="f" colspan="2">' . $box(!empty($d['zal_wyciag'])) . ' Wyciąg z rachunku bankowego / historia rachunku na dzień ' . $e(ldDate($d['as_of'])) . (isset($fl['wyciag']) ? ' — plik: ' . $e($fl['wyciag']) : '') . '</td></tr>'
            . '<tr><td class="f" colspan="2">' . $box(!empty($d['zal_zaswiadczenie'])) . ' Zaświadczenie z banku o dostępnych środkach / saldzie na wyodrębnionym rachunku projektu' . (isset($fl['zaswiadczenie']) ? ' — plik: ' . $e($fl['zaswiadczenie']) : '') . '</td></tr></table>';
        $h .= '<table class="t">' . $sec('4', 'Oświadczenia prawne i odpowiedzialność');
        foreach (self::STATEMENTS as $k => $t) $h .= '<tr><td class="f" colspan="2">' . $box(!empty($d['osw_' . $k])) . ' ' . $e($t) . '</td></tr>';
        $h .= '</table>';
        $rows = '';
        foreach ($r['signers'] as $sg) {
            $rows .= '<tr><td class="f" style="width:45%"><b>' . $e($sg['name']) . '</b><div class="small">' . $e(trim(($sg['role'] === 'ksiegowy' ? 'Główny księgowy / Skarbnik' : $sg['function_title']) . ' — ' . $sg['basis'], ' —')) . '</div></td><td class="f" style="height:14mm">'
                . ($sg['signed_at'] ? '<b>' . $e($sg['signed_name']) . '</b><div class="esig">' . (str_contains((string)$sg['signed_name'], 'skan') ? 'Podpis odręczny — skan w aktach' : 'Podpisano elektronicznie przez link') . ': ' . $e(ldDate(substr((string)$sg['signed_at'], 0, 10)) . ' ' . substr((string)$sg['signed_at'], 11, 5)) . ($sg['signed_ip'] ? ', IP ' . $e($sg['signed_ip']) : '') . '</div>'
                                    : '<div style="height:9mm"></div><div class="small center">...............................................<br>(podpis)</div>') . '</td></tr>';
        }
        $h .= '<table class="t" style="page-break-inside:avoid">' . $sec('5', 'Podpisy osób uprawnionych') . $rows . '</table>';
        return '<style>body{font-family:dejavusans;font-size:8.8pt}table.hdr{width:100%;border-collapse:collapse;margin-bottom:3mm}table.hdr td{vertical-align:top}.title{font-size:13pt;font-weight:bold;text-align:center;margin:2mm 0 1mm}.num{text-align:center;font-size:8.6pt;margin-bottom:3mm}table.t{width:100%;border-collapse:collapse;margin:0 0 2mm}table.t td{border:0.25mm solid #000;vertical-align:top}td.f{padding:0.8mm 1.6mm 1mm}td.pc{padding:1.2mm 1.6mm;font-size:9pt}td.sec{background:#bfbfbf;padding:1mm 1.6mm}.lb{font-size:6.6pt;color:#222;margin-bottom:0.4mm}.v{font-size:9.2pt}.small{font-size:7pt}.center{text-align:center}.esig{font-size:6.6pt;color:#333;margin-top:0.8mm}</style>' . $h;
    }

    public static function render(array $r): array
    {
        $html = self::html($r);
        try {
            require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
            $tmp = rtrim(UPLOAD_DIR, '/') . '/mpdf_tmp';
            if (!is_dir($tmp)) @mkdir($tmp, 0755, true);
            require_once dirname(__DIR__, 2) . '/ti_pdf/logic/tiStudentDocCode.php';
            $_kd = ti_student_doc_code((int)($r['client_id'] ?? 0), 'DOP5', (int)($r['id'] ?? 0));
            // DOP5 dotyczy organizacji, nie uczestnika — nie ma kodu KD, więc zapisany dokument dostaje kody z rejestru dokumentów
            // (QR + Code128 + kod); pusty wzór do druku (bez id) bez kodów. Błąd kodów nie blokuje PDF.
            require_once dirname(__DIR__, 3) . '/includes/ti_terms.php';   // ti_pdf_print_codes(), ti_mpdf_codes_html()
            $_dc = null;
            if ($_kd === '' && (int)($r['id'] ?? 0) > 0) {
                $_dc = ti_pdf_print_codes('ti_dop5', (string)(int)$r['id'], 'DOP5 — oświadczenie o stanie środków ' . (string)($r['number'] ?? ''));
            }
            $m = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 14, 'margin_right' => 14, 'margin_top' => 10, 'margin_bottom' => $_dc ? 27 : ti_student_doc_margin($_kd, 13), 'margin_footer' => 5, 'default_font' => 'dejavusans', 'tempDir' => $tmp, 'shrink_tables_to_fit' => 1]);
            $m->SetTitle('DOP5 — oświadczenie o stanie środków');
            $m->SetHTMLFooter(ti_student_doc_footer(ti_mpdf_codes_html($_dc, false, '<table width="100%" style="font-family:dejavusans;font-size:7pt;border-top:0.25mm solid #000"><tr><td width="50%"><b>DOP5</b> — oświadczenie o stanie środków finansowych</td><td width="30%" style="text-align:center">' . htmlspecialchars((string)$r['number']) . '</td><td width="20%" style="text-align:right">Strona {PAGENO}/{nbpg}</td></tr></table>'), $_kd));
            $m->WriteHTML($html);
            return ['bytes' => $m->Output('', \Mpdf\Output\Destination::STRING_RETURN), 'mime' => 'application/pdf', 'ext' => 'pdf'];
        } catch (\Throwable $e) {
            return ['bytes' => '<!doctype html><meta charset="utf-8">' . $html, 'mime' => 'text/html', 'ext' => 'html'];
        }
    }

    /** Pusty wzór do druku. */
    public static function blank(): array
    {
        $r = ['number' => 'DOP5 / ______ / ______', 'place' => '...................', 'created_at' => '', 'filesList' => [], 'signers' => [
                ['name' => '', 'role' => 'reprezentant', 'function_title' => 'Członek Zarządu / Prezes', 'basis' => '', 'signed_at' => null, 'signed_name' => '', 'signed_ip' => ''],
                ['name' => '', 'role' => 'reprezentant', 'function_title' => 'Członek Zarządu', 'basis' => '', 'signed_at' => null, 'signed_name' => '', 'signed_ip' => ''],
                ['name' => '', 'role' => 'ksiegowy', 'function_title' => '', 'basis' => '', 'signed_at' => null, 'signed_name' => '', 'signed_ip' => '']],
              'd' => ['org' => self::org(), 'umowa_nr' => '', 'tytul' => '', 'kwota' => '0', 'as_of' => '', 'rachunek' => 'PL' . str_repeat('0', 26), 'rachunek_opis' => '', 'dop2' => '']];
        return self::render($r);
    }
}
