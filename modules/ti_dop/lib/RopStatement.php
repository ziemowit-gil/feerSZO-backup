<?php
declare(strict_types=1);

/**
 * modules/ti_dop/lib/RopStatement.php — ROP: stan subkonta uczestnika na dzień wpłaty, wysyłany kursantowi i rodzicowi
 * (opiekunowi) z podpisem elektronicznym osoby wystawiającej (imię i nazwisko, data, godzina, IP).
 *
 * Numer: ROP/{ID uczestnika}{6 losowych cyfr}-{numer wniosku}, np. ROP/345482913-DOP2/000001/2026;
 *        bez powiązanego wniosku końcówka „SALDPocz”: ROP/345482913-SALDPocz.
 * Powstaje:
 *   • automatycznie po każdej zaksięgowanej wpłacie (ti_payment_add() i rozdział wpłaty na subkonto tiSubApply()) —
 *     dla uczestników z nadanym subkontem; wpisy jednego żądania łączą się w jeden ROP (kolejka, wykonanie na końcu żądania),
 *     błąd ROP nigdy nie blokuje księgowania; włącznik: ustawienie rop_auto_enabled (domyślnie włączone);
 *   • ręcznie z panelu (Finanse › Wnioski DOP › ROP) na wybrany dzień, z opcjonalnym numerem wniosku.
 * Treść: dane kursanta i opiekuna, subkonto, wpłata (data, kwota, tytuł), saldo przed i po wpłacie, operacje dnia
 * (rozdział wpłaty na należności), ostatnie operacje z saldem narastającym, nadpłata / niedopłata, podpis wystawiającego.
 * Źródło liczb: księga uczestnika (tiSubStatement / ti_client_balance) — ROP jest jej migawką (data JSON).
 */
require_once __DIR__ . '/dopSchema.php';
require_once dirname(__DIR__, 2) . '/ti_licencje_dydaktyczne/lib/LicenseNotificationService.php';

function ropMigrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    dopMigrate();
    if (function_exists('szo_schema_current') && szo_schema_current('ti_dop_rop_migrate', __FILE__)) return;
    $pk = (defined('DB_TYPE') && DB_TYPE !== 'sqlite') ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    db()->exec("CREATE TABLE IF NOT EXISTS rop_statements (
        id {$pk},
        kind VARCHAR(4) NOT NULL DEFAULT 'ROP',
        number VARCHAR(80) NOT NULL DEFAULT '',
        client_id INTEGER NOT NULL,
        as_of VARCHAR(10) NOT NULL DEFAULT '',
        payment_ids TEXT,
        request_number VARCHAR(60) NOT NULL DEFAULT '',
        data TEXT,
        pdf_path VARCHAR(255) NOT NULL DEFAULT '',
        auto INTEGER NOT NULL DEFAULT 0,
        issued_by INTEGER,
        issued_name VARCHAR(190) NOT NULL DEFAULT '',
        issued_ip VARCHAR(64) NOT NULL DEFAULT '',
        issued_at VARCHAR(19),
        sent TEXT
    )");
    db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_rop_number ON rop_statements(number)");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_rop_client ON rop_statements(client_id, as_of)");
}

/** Kolejkuje automatyczny ROP po wpłacie (wykonanie na końcu żądania; wiele wpisów jednego uczestnika = jeden ROP). */
function ropQueuePayment(int $clientId, int $paymentId): void
{
    static $registered = false;
    $GLOBALS['__rop_queue'][$clientId][] = $paymentId;
    if ($registered) return;
    $registered = true;
    register_shutdown_function(static function (): void {
        $q = $GLOBALS['__rop_queue'] ?? [];
        $GLOBALS['__rop_queue'] = [];
        foreach ($q as $cid => $ids) {
            try { RopStatement::auto((int)$cid, array_map('intval', $ids)); }
            catch (\Throwable $e) { error_log('ROP auto (uczestnik ' . $cid . '): ' . $e->getMessage()); }
        }
    });
}

final class RopStatement
{
    public static function autoEnabled(): bool
    {
        try { $v = db_one("SELECT value FROM settings WHERE key_ = 'rop_auto_enabled'"); } catch (\Throwable $e) { $v = null; }
        return $v === null || (string)$v['value'] !== '0';
    }

    /** Osoba wystawiająca: zalogowany w panelu dydaktyka / SZO, inaczej system (płatność online, CRON). */
    public static function signer(): array
    {
        try { if (function_exists('dyd_current') && ($s = dyd_current())) return ['id' => (int)$s['user_id'], 'name' => (string)($s['name'] ?? '')]; } catch (\Throwable $e) {}
        try { if (function_exists('current_user') && ($u = current_user()) && !empty($u['id'])) return ['id' => (int)$u['id'], 'name' => (string)($u['name'] ?? '')]; } catch (\Throwable $e) {}
        return ['id' => 0, 'name' => 'system SZO (płatność zaksięgowana automatycznie)'];
    }

    public static function auto(int $clientId, array $paymentIds): ?int
    {
        ropMigrate();
        if (!self::autoEnabled() || !$paymentIds) return null;
        require_once dirname(__DIR__, 2) . '/ti_subaccount/logic/subaccountManager.php';
        if (!tiSubNrb($clientId)) return null;   // ROP dotyczy subkonta — bez subkonta nie wysyłamy
        $ph = implode(',', array_fill(0, count($paymentIds), '?'));
        $p = db_one("SELECT MAX(COALESCE(paid_at, date(created_at))) AS d, SUM(amount) AS s FROM k30_ti_payments WHERE id IN ($ph) AND amount > 0", $paymentIds);
        if (!$p || (float)$p['s'] <= 0) return null;
        return self::create($clientId, (string)$p['d'], $paymentIds, '', self::signer(), true)['id'];
    }

    public static function number(int $clientId, string $requestNumber): string
    {
        for ($i = 0; $i < 20; $i++) {
            $n = 'ROP/' . $clientId . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT) . '-' . ($requestNumber !== '' ? $requestNumber : 'SALDPocz');
            if (!db_one("SELECT id FROM rop_statements WHERE number = ?", [$n])) return $n;
        }
        throw new RuntimeException('Nie udało się nadać unikalnego numeru ROP.');
    }

    /** Wnioski uczestnika do powiązania (DOP, operacje licencyjne). */
    public static function requests(int $clientId): array
    {
        $out = [];
        try { foreach (db_all("SELECT number FROM dop_requests WHERE client_id = ? ORDER BY id DESC", [$clientId]) as $r) $out[] = (string)$r['number']; } catch (\Throwable $e) {}
        try { foreach (db_all("SELECT number FROM license_operations WHERE account_kind = 'client' AND account_id = ? ORDER BY id DESC", [$clientId]) as $r) $out[] = (string)$r['number']; } catch (\Throwable $e) {}
        return $out;
    }

    /** Migawka stanu subkonta na dzień $asOf (wpłaty $paymentIds — puste = wszystkie wpłaty tego dnia). */
    public static function snapshot(int $clientId, string $asOf, array $paymentIds = []): array
    {
        require_once dirname(__DIR__, 3) . '/includes/ti_payments.php';
        require_once dirname(__DIR__, 2) . '/ti_subaccount/logic/subaccountManager.php';
        $c = db_one("SELECT id, name, email, phone FROM k30_clients WHERE id = ?", [$clientId]);
        if (!$c) throw new DomainException('Nie ma takiego uczestnika.');
        $acc = db_one("SELECT is_minor, guardian_name, guardian_email, guardian_phone FROM k30_ti_student_accounts WHERE client_id = ? ORDER BY id LIMIT 1", [$clientId]) ?: [];
        $st = tiSubStatement($clientId, '', $asOf);
        $lines = $st['lines'];
        $opening = (float)$st['opening'];
        $before = $opening;
        foreach ($lines as $l) { if ($l['date'] >= $asOf) break; $before = (float)$l['balance']; }
        $day = array_values(array_filter($lines, fn($l) => $l['date'] === $asOf));
        $closing = $lines ? (float)$lines[count($lines) - 1]['balance'] : $opening;
        $payments = $paymentIds
            ? db_all("SELECT id, amount, paid_at, method, note, source_type FROM k30_ti_payments WHERE client_id = ? AND id IN (" . implode(',', array_fill(0, count($paymentIds), '?')) . ") ORDER BY id", [$clientId, ...$paymentIds])
            : db_all("SELECT id, amount, paid_at, method, note, source_type FROM k30_ti_payments WHERE client_id = ? AND amount > 0 AND method NOT IN ('internal', 'refund') AND COALESCE(paid_at, date(created_at)) = ? ORDER BY id", [$clientId, $asOf]);
        $paid = array_sum(array_map(fn($p) => (float)$p['amount'], array_filter($payments, fn($p) => (float)$p['amount'] > 0)));
        return ['client' => ['id' => (int)$c['id'], 'name' => (string)$c['name'], 'email' => (string)$c['email'], 'phone' => (string)$c['phone']],
                'guardian' => !empty($acc['guardian_email']) || !empty($acc['guardian_name']) ? ['name' => (string)($acc['guardian_name'] ?? ''), 'email' => (string)($acc['guardian_email'] ?? ''),
                                                                                                 'phone' => (string)($acc['guardian_phone'] ?? ''), 'minor' => !empty($acc['is_minor'])] : null,
                'nrb' => (string)($st['nrb'] ?? ''), 'as_of' => $asOf, 'payments' => $payments, 'paid' => round($paid, 2),
                'balance_before' => round($before, 2), 'balance_after' => round($closing, 2), 'day' => $day, 'recent' => array_slice($lines, -12),
                'credit' => max(0, round($closing, 2)), 'debt' => max(0, round(-$closing, 2))];
    }

    /** @return array{id:int, number:string, report:string} */
    public static function create(int $clientId, string $asOf, array $paymentIds, string $requestNumber, array $signer, bool $auto = false): array
    {
        ropMigrate();
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) throw new InvalidArgumentException('Podaj dzień, na który liczyć stan subkonta.');
        $requestNumber = trim(preg_replace('/\s+/', '', $requestNumber));
        $snap = self::snapshot($clientId, $asOf, $paymentIds);
        $now = ldNow();
        $number = self::number($clientId, $requestNumber);
        $id = db_insert('rop_statements', ['kind' => 'ROP', 'number' => $number, 'client_id' => $clientId, 'as_of' => $asOf, 'payment_ids' => json_encode(array_values($paymentIds)),
            'request_number' => $requestNumber, 'data' => json_encode($snap, JSON_UNESCAPED_UNICODE), 'auto' => $auto ? 1 : 0, 'issued_by' => ($signer['id'] ?? 0) ?: null,
            'issued_name' => (string)$signer['name'], 'issued_ip' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64), 'issued_at' => $now]);
        $rop = self::get($id);
        $out = self::render($rop);
        $dir = rtrim(UPLOAD_DIR, '/') . '/' . DOP_DIR;
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        $name = 'rop' . $id . '_' . bin2hex(random_bytes(6)) . '.' . $out['ext'];
        file_put_contents($dir . '/' . $name, $out['bytes']);
        db_exec("UPDATE rop_statements SET pdf_path = ? WHERE id = ?", [DOP_DIR . '/' . $name, $id]);
        $report = self::send($id, $signer);
        return ['id' => $id, 'number' => $number, 'report' => $report];
    }

    public static function get(int $id): ?array
    {
        ropMigrate();
        $r = db_one("SELECT * FROM rop_statements WHERE id = ?", [$id]);
        if (!$r) return null;
        $r['snap'] = json_decode((string)$r['data'], true) ?: [];
        $r['sentList'] = json_decode((string)($r['sent'] ?? ''), true) ?: [];
        return $r;
    }

    public static function search(array $f = []): array
    {
        ropMigrate();
        $w = []; $p = [];
        if (!empty($f['client'])) { $w[] = 'r.client_id = ?'; $p[] = (int)$f['client']; }
        if (($q = trim((string)($f['q'] ?? ''))) !== '') { $w[] = '(lower(c.name) LIKE ? OR lower(r.number) LIKE ?)'; $l = '%' . mb_strtolower($q) . '%'; array_push($p, $l, $l); }
        return db_all("SELECT r.*, c.name AS client_name FROM rop_statements r JOIN k30_clients c ON c.id = r.client_id" . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY r.id DESC LIMIT 300", $p);
    }

    /** Wysyłka do kursanta i rodzica (opiekuna) — osobne e-maile z PDF. */
    public static function send(int $id, array $actor): string
    {
        $r = self::get($id);
        $s = $r['snap'];
        $n = new LicenseNotificationService('ti_dop');
        $att = [];
        $p = rtrim(UPLOAD_DIR, '/') . '/' . $r['pdf_path'];
        if (str_ends_with((string)$r['pdf_path'], '.pdf') && is_file($p)) $att = [['path' => $r['pdf_path'], 'name' => preg_replace('/[^A-Za-z0-9_-]+/', '-', $r['number']) . '.pdf', 'mime' => 'application/pdf', 'size' => (int)filesize($p)]];
        $zl = fn($v) => number_format((float)$v, 2, ',', ' ') . ' zł';
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $body = '<p>Dzień dobry,</p><p>przesyłamy <b>stan subkonta</b> uczestnika <b>' . $e($s['client']['name']) . '</b> na dzień wpłaty <b>' . $e(ldDate($s['as_of'])) . '</b> (dokument <b>' . $e($r['number']) . '</b>).</p>'
            . '<table style="border-collapse:collapse" cellpadding="6"><tr><td style="background:#f2f2f2">Wpłata</td><td><b>' . $e($zl($s['paid'])) . '</b></td></tr>'
            . '<tr><td style="background:#f2f2f2">Saldo przed wpłatą</td><td>' . $e($zl($s['balance_before'])) . '</td></tr>'
            . '<tr><td style="background:#f2f2f2">Saldo po wpłacie</td><td><b>' . $e($zl($s['balance_after'])) . '</b>' . ($s['balance_after'] > 0 ? ' (nadpłata)' : ($s['balance_after'] < 0 ? ' (do zapłaty)' : '')) . '</td></tr>'
            . ($s['nrb'] !== '' ? '<tr><td style="background:#f2f2f2">Subkonto</td><td>' . $e($s['nrb']) . '</td></tr>' : '') . '</table>'
            . '<p>Wystawił(a): ' . $e($r['issued_name']) . '. Szczegóły i podpis — w załączniku (PDF).</p>';
        $html = LicenseNotificationService::layout('Stan subkonta na dzień wpłaty', $body, '', '', 'ROP');
        $subject = 'Stan subkonta na dzień wpłaty ' . ldDate($s['as_of']) . ' — ' . $r['number'];
        $sent = [];
        $who = [['rola' => 'kursant', 'name' => $s['client']['name'], 'email' => $s['client']['email']]];
        if (!empty($s['guardian']['email'])) $who[] = ['rola' => 'rodzic / opiekun', 'name' => $s['guardian']['name'] ?: 'Rodzic / opiekun', 'email' => $s['guardian']['email']];
        foreach ($who as $w) {
            if (strcasecmp((string)$w['email'], (string)($who[0]['email'] ?? '')) === 0 && $w['rola'] !== 'kursant') continue;
            $res = $n->send(LicenseNotificationType::Statement, ['name' => $w['name'], 'email' => $w['email']], $subject, $html, '', ['type' => 'rop', 'id' => $id], $actor, false, $att);
            $sent[] = ['rola' => $w['rola'], 'email' => LicenseNotificationService::maskEmail((string)$w['email']), 'status' => $res['channels']['email'] ?? 'skipped', 'at' => ldNow()];
        }
        $all = array_merge($r['sentList'], $sent);
        db_exec("UPDATE rop_statements SET sent = ? WHERE id = ?", [json_encode($all, JSON_UNESCAPED_UNICODE), $id]);
        return implode('; ', array_map(fn($x) => $x['rola'] . ': ' . LicenseNotificationService::statusLabel($x['status']), $sent)) ?: 'brak adresów e-mail';
    }

    // ── Wydruk ──────────────────────────────────────────────────────────────────

    public static function html(array $r): string
    {
        $s = $r['snap'];
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $zl = fn($v) => number_format((float)$v, 2, ',', ' ') . ' zł';
        $n = 0;
        $fld = function (string $label, string $html, string $extra = '') use (&$n): string { $n++; return '<td class="f"' . $extra . '><div class="lb"><b>' . $n . '.</b> ' . htmlspecialchars($label) . '</div><div class="v">' . ($html !== '' ? $html : '&nbsp;') . '</div></td>'; };
        $sec = fn(string $l, string $t, int $cols = 2) => '<tr><td class="sec" colspan="' . $cols . '"><b>' . $l . '. ' . htmlspecialchars($t) . '</b></td></tr>';
        $h = '<table class="hdr"><tr><td style="width:60%"><b style="font-size:11pt">' . $e(ldOrgName()) . '</b><br><span class="small">' . $e((string)org_setting('org_adres')) . '</span></td>'
           . '<td style="width:40%;text-align:right">' . $e(ldDate(substr((string)$r['issued_at'], 0, 10))) . '<div class="small">data wystawienia</div></td></tr></table>'
           . '<div class="title">ROP – STAN SUBKONTA NA DZIEŃ WPŁATY</div><div class="num">Numer: <b>' . $e($r['number']) . '</b> · stan na dzień <b>' . $e(ldDate($s['as_of'])) . '</b></div>';
        $h .= '<table class="t">' . $sec('A', 'DANE UCZESTNIKA I SUBKONTA') . '<tr>' . $fld('Uczestnik (kursant)', '<b>' . $e($s['client']['name']) . '</b> · ID ' . (int)$s['client']['id'], ' width="55%"')
            . $fld('Rodzic / opiekun', $e(($s['guardian']['name'] ?? '') ?: '—'), ' width="45%"') . '</tr><tr>'
            . $fld('Subkonto (rachunek wirtualny)', '<b>' . $e($s['nrb'] ?: '—') . '</b>', ' width="55%"') . $fld('Wniosek', $e($r['request_number'] !== '' ? $r['request_number'] : 'brak — saldo początkowe (SALDPocz)'), ' width="45%"') . '</tr></table>';
        $pRows = '';
        foreach ($s['payments'] as $p) $pRows .= '<tr><td class="f">' . $e(ldDate((string)$p['paid_at'])) . '</td><td class="f">' . $e($p['note'] ?: 'Wpłata') . '</td><td class="f" style="text-align:right"><b>' . $e($zl($p['amount'])) . '</b></td></tr>';
        $h .= '<table class="t">' . $sec('B', 'WPŁATA', 3) . '<tr><td class="lb" style="padding:1mm;width:16%"><b>Data</b></td><td class="lb" style="padding:1mm"><b>Tytuł / opis</b></td><td class="lb" style="padding:1mm;width:20%;text-align:right"><b>Kwota</b></td></tr>'
            . ($pRows ?: '<tr><td class="f" colspan="3">brak wpłat w tym dniu</td></tr>') . '</table>';
        $h .= '<table class="t">' . $sec('C', 'STAN SUBKONTA', 3) . '<tr>' . $fld('Saldo przed wpłatą', '<b>' . $e($zl($s['balance_before'])) . '</b>', ' width="33%"')
            . $fld('Wpłata', '<b>+' . $e($zl($s['paid'])) . '</b>', ' width="33%"') . $fld('Saldo po wpłacie (na dzień ' . ldDate($s['as_of']) . ')', '<b>' . $e($zl($s['balance_after'])) . '</b>', ' width="34%"') . '</tr><tr>'
            . $fld('Nadpłata (do dyspozycji)', $e($zl($s['credit'])), '') . $fld('Do zapłaty (niedopłata)', $e($zl($s['debt'])), '') . $fld('Saldo dodatnie = nadpłata, ujemne = zaległość', '', '') . '</tr></table>';
        $line = fn(array $l) => '<tr><td class="f">' . $e(ldDate($l['date'])) . '</td><td class="f">' . $e($l['desc']) . '<div class="lb">' . $e($l['what']) . '</div></td><td class="f" style="text-align:right">'
            . ($l['in'] > 0 ? '+' . $e($zl($l['in'])) : '') . '</td><td class="f" style="text-align:right">' . ($l['out'] > 0 ? '−' . $e($zl($l['out'])) : '') . '</td><td class="f" style="text-align:right"><b>' . $e($zl($l['balance'])) . '</b></td></tr>';
        $head = '<tr><td class="lb" style="padding:1mm;width:13%"><b>Data</b></td><td class="lb" style="padding:1mm"><b>Operacja</b></td><td class="lb" style="padding:1mm;width:14%;text-align:right"><b>Wpłata</b></td><td class="lb" style="padding:1mm;width:14%;text-align:right"><b>Należność</b></td><td class="lb" style="padding:1mm;width:15%;text-align:right"><b>Saldo</b></td></tr>';
        $h .= '<table class="t">' . $sec('D', 'OPERACJE W DNIU WPŁATY (ROZDZIAŁ WPŁATY)', 5) . $head . (implode('', array_map($line, $s['day'])) ?: '<tr><td class="f" colspan="5">—</td></tr>') . '</table>';
        $h .= '<table class="t">' . $sec('E', 'OSTATNIE OPERACJE NA SUBKONCIE (DO DNIA WPŁATY)', 5) . $head . (implode('', array_map($line, $s['recent'])) ?: '<tr><td class="f" colspan="5">—</td></tr>') . '</table>';
        $h .= '<table class="t" style="page-break-inside:avoid">' . $sec('F', 'PODPIS OSOBY WYSTAWIAJĄCEJ') . '<tr>' . $fld('Wystawił(a)', '<b>' . $e($r['issued_name']) . '</b><div class="esig">Podpisano elektronicznie w SZO: '
            . $e(ldDate(substr((string)$r['issued_at'], 0, 10)) . ' ' . substr((string)$r['issued_at'], 11, 5)) . ($r['issued_ip'] ? ', IP ' . $e($r['issued_ip']) : '') . ($r['auto'] ? ' (wystawiony automatycznie po zaksięgowaniu wpłaty)' : '') . '</div>', ' colspan="2" style="height:14mm"')
            . '</tr><tr><td class="pc" colspan="2"><div class="small">Dokument informacyjny — stan wyliczony z księgi rozliczeń uczestnika na wskazany dzień. Pytania: ' . $e((string)org_setting('org_email')) . '.</div></td></tr></table>';
        return '<style>body{font-family:dejavusans;font-size:8.8pt}table.hdr{width:100%;border-collapse:collapse;margin-bottom:3mm}table.hdr td{vertical-align:top}.title{font-size:13pt;font-weight:bold;text-align:center;margin:2mm 0 1mm}.num{text-align:center;font-size:8.6pt;margin-bottom:3mm}table.t{width:100%;border-collapse:collapse;margin:0 0 2mm}table.t td{border:0.25mm solid #000;vertical-align:top}td.f{padding:0.8mm 1.6mm 1mm}td.pc{padding:1.2mm 1.6mm}td.sec{background:#bfbfbf;padding:1mm 1.6mm;letter-spacing:0.15mm}.lb{font-size:6.6pt;color:#222;margin-bottom:0.4mm}.v{font-size:9.2pt}.small{font-size:7pt}.esig{font-size:6.6pt;color:#333;margin-top:0.8mm}</style>' . $h;
    }

    public static function render(array $r): array
    {
        $html = self::html($r);
        try {
            require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
            $tmp = rtrim(UPLOAD_DIR, '/') . '/mpdf_tmp';
            if (!is_dir($tmp)) @mkdir($tmp, 0755, true);
            $m = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 14, 'margin_right' => 14, 'margin_top' => 10, 'margin_bottom' => 13, 'margin_footer' => 5, 'default_font' => 'dejavusans', 'tempDir' => $tmp, 'shrink_tables_to_fit' => 1]);
            $m->SetTitle('ROP — stan subkonta — ' . ($r['snap']['client']['name'] ?? ''));
            $m->SetAuthor(ldOrgName());
            $m->SetHTMLFooter('<table width="100%" style="font-family:dejavusans;font-size:7pt;border-top:0.25mm solid #000"><tr><td width="40%"><b>ROP</b> — stan subkonta na dzień wpłaty</td><td width="40%" style="text-align:center">' . htmlspecialchars((string)$r['number']) . '</td><td width="20%" style="text-align:right">Strona {PAGENO}/{nbpg}</td></tr></table>');
            $m->WriteHTML($html);
            return ['bytes' => $m->Output('', \Mpdf\Output\Destination::STRING_RETURN), 'mime' => 'application/pdf', 'ext' => 'pdf'];
        } catch (\Throwable $e) {
            error_log('rop pdf fallback html: ' . $e->getMessage());
            return ['bytes' => '<!doctype html><meta charset="utf-8">' . $html, 'mime' => 'text/html', 'ext' => 'html'];
        }
    }
}
