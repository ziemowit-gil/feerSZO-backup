<?php
/**
 * modules/application_documents/logic/applicationDocuments.php — wzory urzędowych wniosków
 * składanych elektronicznie w Panelu współpracownika (panel/apply.php → user_applications)
 * oraz ich dokument do wydruku / PDF.
 *
 * Rejestr appDocsTemplates(): kod wzoru → typ wniosku (application_types.name), pola formularza
 * i treść dokumentu. appDocsEnsureTypes() dopisuje brakujące typy i pola (idempotentnie, wołane
 * z _applications_init()); istniejących typów nie nadpisuje — administrator może je zmieniać
 * w admin/application_types.php.
 */

/** Wspólne pola nagłówka wniosku (miejscowość, adresat, wnioskodawca). */
function appDocsHeaderFields(): array {
    return [
        ['miejscowosc',  'Miejscowość',                                  'text',     [], 'np. Kraków',                                   1],
        ['organ',        'Do: nazwa i adres organu lub instytucji',      'textarea', [], "Nazwa\nUlica i numer\nKod pocztowy, miejscowość", 1],
        ['wnioskodawca', 'Wnioskodawca: imię i nazwisko lub nazwa',      'text',     [], 'Imię i nazwisko lub nazwa wnioskodawcy',       1],
        ['adres',        'Adres zamieszkania lub siedziby',             'text',     [], 'Ulica, numer, kod pocztowy, miejscowość',       1],
        ['kontakt',      'Dane kontaktowe',                              'text',     [], 'Telefon lub e-mail',                           1],
    ];
}

/** Wspólne pola stopki wniosku (forma, sposób przekazania, kontakt, załączniki). */
function appDocsFooterFields(string $what): array {
    return [
        ['forma',        'Forma wydania ' . $what,                       'select',   ['papierowa', 'elektroniczna'], '',                 1],
        ['sposob',       'Sposób przekazania',                           'select',   ['odbiór osobisty', 'doręczenie pocztą', 'doręczenie elektroniczne'], '', 1],
        ['kontakt_pref', 'Preferowany sposób kontaktu w sprawie wniosku', 'select',  ['e-mail', 'telefon', 'Panel współpracownika (SZO)', 'poczta tradycyjna'], '', 1],
        ['zalaczniki',   'Załączniki',                                   'textarea', [], 'Wymagany załącznik — każdy w osobnym wierszu. Puste = brak.', 0],
    ];
}

/**
 * Rejestr wzorów. Klucz = application_types.name.
 * fields: [name, label, type, options, placeholder, required]; doc: elementy treści dokumentu.
 */
function appDocsTemplates(): array {
    return [
        'wniosek_zsw1' => [
            'code'        => 'ZSW-1',
            'label'       => 'ZSW-1 · Wniosek o wydanie dokumentu lub zaświadczenia',
            'icon'        => 'bi-file-earmark-text',
            'description' => 'Wniosek o wydanie dokumentu lub zaświadczenia (wzór ZSW-1), składany elektronicznie.',
            'sort'        => 15,
            'title'       => 'WNIOSEK O WYDANIE DOKUMENTU LUB ZAŚWIADCZENIA',
            'fields'      => array_merge(appDocsHeaderFields(), [
                ['dokument', 'Dokładna nazwa dokumentu lub zaświadczenia',        'text', [], 'np. zaświadczenie o odbyciu wolontariatu', 1],
                ['dotyczy',  'Kogo lub czego dotyczy',                            'text', [], 'Osoba, sprawa lub okres, którego dotyczy dokument', 1],
                ['cel',      'Cel uzyskania dokumentu',                           'text', [], 'Jeśli wymagany — np. do urzędu pracy', 0],
            ], appDocsFooterFields('dokumentu')),
            'request'     => 'Zwracam się z wnioskiem o wydanie {dokument} dotyczącego: {dotyczy}.',
            'purpose'     => 'Dokument jest mi potrzebny w celu: {cel}.',
            'asks'        => ['wydanie dokumentu w formie {forma_txt};', 'przekazanie go w sposób: {sposob}.'],
            'fee'         => 'Jeżeli wydanie dokumentu wiąże się z opłatą, proszę o wskazanie jej wysokości oraz sposobu uiszczenia, o ile informacja ta nie jest dostępna w formularzu lub na stronie organu.',
        ],
        'wniosek_zsw2' => [
            'code'        => 'ZSW-2',
            'label'       => 'ZSW-2 · Wniosek o wydanie duplikatu dokumentacji',
            'icon'        => 'bi-files',
            'description' => 'Wniosek o wydanie duplikatu dokumentacji (wzór ZSW-2), składany elektronicznie.',
            'sort'        => 16,
            'title'       => 'WNIOSEK O WYDANIE DUPLIKATU DOKUMENTACJI',
            'fields'      => array_merge(appDocsHeaderFields(), [
                ['dokument', 'Rodzaj i nazwa dokumentacji',                       'text', [], 'np. umowa wolontariacka i karta czasu pracy', 1],
                ['dotyczy',  'Kogo lub czego dotyczy',                            'text', [], 'Osoba, sprawa lub okres, którego dotyczy dokumentacja', 1],
                ['cel',      'Cel uzyskania dokumentacji',                        'text', [], 'Jeśli wymagany', 0],
            ], appDocsFooterFields('duplikatu')),
            'request'     => 'Zwracam się z wnioskiem o wydanie duplikatu dokumentacji: {dokument}, dotyczącej: {dotyczy}.',
            'purpose'     => 'Dokumentacja jest mi potrzebna w celu: {cel}.',
            'asks'        => ['wydanie duplikatu w formie {forma_txt}, jeśli dopuszczalna;', 'przekazanie go w sposób: {sposob}.'],
            'fee'         => 'Jeżeli wydanie duplikatu dokumentacji wiąże się z opłatą, proszę o wskazanie jej wysokości oraz sposobu uiszczenia, o ile informacja ta nie jest dostępna w formularzu lub na stronie podmiotu przechowującego dokumentację.',
        ],
        'wniosek_dok' => [
            'code'        => 'DOK',
            'label'       => 'DOK · Wniosek o kserokopię dokumentacji wolontariusza / zleceniobiorcy',
            'icon'        => 'bi-copy',
            'description' => 'Wniosek o sporządzenie kserokopii dokumentacji wolontariusza lub zleceniobiorcy (wzór DOK), składany elektronicznie.',
            'sort'        => 17,
            'title'       => 'WNIOSEK O SPORZĄDZENIE KSEROKOPII DOKUMENTACJI WOLONTARIUSZA / ZLECENIOBIORCY',
            'fields'      => array_merge(appDocsHeaderFields(), [
                ['osoba',    'Imię i nazwisko wolontariusza lub zleceniobiorcy', 'text',     [], 'Czyjej dokumentacji dotyczy wniosek', 1],
                ['podmiot',  'Nazwa podmiotu lub organizacji',                   'text',     [], 'U kogo był wolontariat / umowa zlecenia', 1],
                ['okres',    'Okres, którego dotyczy dokumentacja',              'text',     [], 'np. 01.2025 – 06.2026', 1],
                ['dokument', 'Dokumenty do skopiowania',                         'textarea', [], 'Rodzaje lub nazwy dokumentów — przy wniosku o całość dokumentacji wpisz to wprost', 1],
                ['cel',      'Cel uzyskania dokumentacji',                       'text',     [], 'Jeśli wymagany', 0],
                ['sposob',       'Sposób przekazania kserokopii',                'select',   ['odbiór osobisty', 'doręczenie na wskazany adres'], '', 1],
                ['kontakt_pref', 'Preferowany sposób kontaktu w sprawie wniosku', 'select',  ['e-mail', 'telefon', 'Panel współpracownika (SZO)', 'poczta tradycyjna'], '', 1],
                ['zalaczniki',   'Załączniki',                                   'textarea', [], 'Wymagany załącznik — każdy w osobnym wierszu. Puste = brak.', 0],
            ]),
            'request'     => 'Zwracam się z wnioskiem o sporządzenie kserokopii dokumentacji dotyczącej {osoba}, związanej z wolontariatem lub wykonywaniem umowy zlecenia u {podmiot} w okresie {okres}. Proszę o sporządzenie kserokopii następujących dokumentów: {dokument}.',
            'purpose'     => 'Dokumentacja jest mi potrzebna w celu: {cel}.',
            'asks'        => ['sporządzenie kserokopii w formie papierowej;', 'przekazanie kserokopii w sposób: {sposob}.'],
            'fee'         => 'Jeżeli sporządzenie kserokopii wiąże się z opłatą, proszę o wskazanie jej wysokości oraz sposobu uiszczenia przed realizacją wniosku.',
        ],
    ];
}

/** Wzór dla nazwy typu wniosku albo null (typ bez dokumentu). */
function appDocsTemplateFor(?string $typeName): ?array {
    return $typeName ? (appDocsTemplates()[$typeName] ?? null) : null;
}

/** Dopisuje brakujące typy wniosków z rejestru (wraz z polami). Bez nadpisywania istniejących. */
function appDocsEnsureTypes(\PDO $pdo): void {
    $have = $pdo->query("SELECT name FROM application_types")->fetchAll(\PDO::FETCH_COLUMN) ?: [];
    foreach (appDocsTemplates() as $name => $t) {
        if (in_array($name, $have, true)) continue;
        $pdo->prepare("INSERT INTO application_types (name, label, icon, description, requires_contract, allow_attachment, is_active, sort_order)
                       VALUES (?,?,?,?,0,1,1,?)")
            ->execute([$name, $t['label'], $t['icon'], $t['description'], $t['sort']]);
        $typeId = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare("INSERT INTO application_type_fields (type_id, name, label, field_type, options_json, placeholder, required, sort_order)
                              VALUES (?,?,?,?,?,?,?,?)");
        foreach ($t['fields'] as $i => [$fname, $label, $ftype, $opts, $ph, $req]) {
            $ins->execute([$typeId, $fname, $label, $ftype, json_encode($opts, JSON_UNESCAPED_UNICODE), $ph, $req, ($i + 1) * 10]);
        }
    }
}

/** Domyślna wartość pola formularza wzoru (dane zalogowanej osoby, adresat = organizacja). */
function appDocsPrefill(?string $typeName, string $field, array $user): string {
    if (!appDocsTemplateFor($typeName)) return '';
    switch ($field) {
        case 'wnioskodawca': return (string)($user['name'] ?? '');
        case 'kontakt':
            $u = db_one("SELECT email, phone_number FROM users WHERE id = ?", [(int)($user['id'] ?? 0)]) ?: [];
            return implode(', ', array_filter([trim((string)($u['phone_number'] ?? '')), trim((string)($u['email'] ?? $user['email'] ?? ''))]));
        case 'organ':
            $name = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
            return trim($name . "\n" . org_setting('org_adres'));
        case 'podmiot':     return (string)(org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : ''));
        case 'osoba':       return (string)($user['name'] ?? '');
        case 'miejscowosc': return '';
    }
    return '';
}

/** Domyślny tytuł wniosku z wzoru (gdy użytkownik go nie wpisał). */
function appDocsDefaultTitle(string $typeName, array $fields): string {
    $t = appDocsTemplateFor($typeName);
    if (!$t) return '';
    $what = trim((string)($fields['dokument'] ?? ''));
    return $t['code'] . ($what !== '' ? ' — ' . mb_substr($what, 0, 120) : ' — ' . mb_strtolower($t['title']));
}

/** Wniosek z typem i wnioskodawcą albo null. */
function appDocsLoad(int $id): ?array {
    $row = db_one("SELECT a.*, t.name AS type_name, t.label AS type_label, u.name AS user_name, u.email AS user_email
                   FROM user_applications a
                   LEFT JOIN application_types t ON t.id = a.type_id
                   LEFT JOIN users u ON u.id = a.user_id
                   WHERE a.id = ?", [$id]);
    return $row ?: null;
}

/** Czy bieżący użytkownik może zobaczyć dokument: autor wniosku albo admin/redaktor. */
function appDocsCanView(array $app): bool {
    $u = current_user();
    if (!$u) return false;
    return (int)$app['user_id'] === (int)$u['id'] || in_array($u['role'] ?? '', ['admin', 'editor'], true);
}

/** Numer dokumentu wniosku, np. ZSW-1/42/2026. */
function appDocsNumber(array $app, array $tpl): string {
    return $tpl['code'] . '/' . (int)$app['id'] . '/' . date('Y', strtotime((string)$app['created_at']));
}

/** Adres dokumentu (HTML) lub PDF dla wniosku. */
function appDocsUrl(int $id, bool $pdf = false): string {
    return APP_URL . '/modules/application_documents/router.php/' . $id . ($pdf ? '/pdf' : '');
}

/** Treść dokumentu (HTML, bez <html>) — wspólna dla podglądu i PDF. */
function appDocsHtml(array $app, array $tpl): string {
    $f   = json_decode((string)($app['fields_json'] ?? '{}'), true) ?: [];
    $v   = fn(string $k) => trim((string)($f[$k] ?? ''));
    $date = date('d.m.Y', strtotime((string)$app['created_at']));
    $calc = ['forma_txt' => $v('forma') === 'elektroniczna' ? 'elektronicznej' : ($v('forma') === 'papierowa' ? 'papierowej' : '')];
    $fill = fn(string $s) => preg_replace_callback('/\{(\w+)\}/', function ($m) use ($v, $calc) {
        $val = $calc[$m[1]] ?? $v($m[1]);
        return '<b>' . nl2br(h($val !== '' ? $val : '—')) . '</b>';
    }, h($s));
    $att = array_values(array_filter(array_map('trim', preg_split('/\R/', $v('zalaczniki')))));
    if (!empty($app['plik'])) $att[] = 'plik dołączony do wniosku: ' . basename((string)$app['plik']);

    ob_start(); ?>
<div class="ad-place"><?= h($v('miejscowosc') ?: '…') ?>, <?= h($date) ?> r.</div>
<table class="ad-parties"><tr>
  <td><div class="ad-lbl">Wnioskodawca:</div>
    <div class="ad-val"><b><?= h($v('wnioskodawca')) ?></b><br><?= h($v('adres')) ?><br><?= h($v('kontakt')) ?></div></td>
  <td class="ad-to"><div class="ad-lbl">Do:</div><div class="ad-val"><b><?= nl2br(h($v('organ'))) ?></b></div></td>
</tr></table>
<h1 class="ad-title"><?= h($tpl['title']) ?></h1>
<div class="ad-no">nr <?= h(appDocsNumber($app, $tpl)) ?></div>
<p><?= $fill($tpl['request']) ?></p>
<?php if ($v('cel') !== ''): ?><p><?= $fill($tpl['purpose']) ?></p><?php endif; ?>
<p>Proszę o:</p>
<ul><?php foreach ($tpl['asks'] as $ask): ?><li><?= $fill($ask) ?></li><?php endforeach; ?></ul>
<p><?= h($tpl['fee']) ?></p>
<p>Proszę o kontakt w sprawie wniosku za pośrednictwem: <b><?= h($v('kontakt_pref') ?: '—') ?></b>.</p>
<p class="ad-lbl2">Załączniki:</p>
<ul><?php if ($att): foreach ($att as $a): ?><li><?= h($a) ?></li><?php endforeach; else: ?><li>brak</li><?php endif; ?></ul>
<div class="ad-sign">
  <div class="ad-sign-box">
    <div class="ad-lbl">Złożono elektronicznie</div>
    <div>w miejsce podpisu wnioskodawcy: <b><?= h($app['user_name'] ?: $v('wnioskodawca')) ?></b><?= $app['user_email'] ? ' (' . h($app['user_email']) . ')' : '' ?></div>
    <div>po zalogowaniu do SZO, <?= h(date('d.m.Y, H:i', strtotime((string)$app['created_at']))) ?></div>
  </div>
</div>
<?php
    return (string)ob_get_clean();
}

/** Arkusz stylów dokumentu (podgląd i mPDF — tylko proste selektory). */
function appDocsCss(): string {
    return 'body{font-family:"DejaVu Sans","Segoe UI",Arial,sans-serif;font-size:10.5pt;color:#111;line-height:1.5}'
        . '.ad-place{text-align:right;margin-bottom:18pt}'
        . '.ad-parties{width:100%;border-collapse:collapse;margin-bottom:22pt}.ad-parties td{vertical-align:top;width:50%;padding:0}'
        . '.ad-to{padding-left:24pt}.ad-lbl{font-size:8pt;text-transform:uppercase;letter-spacing:1pt;color:#555;margin-bottom:3pt}'
        . '.ad-lbl2{margin-bottom:2pt}.ad-title{font-size:13pt;text-align:center;margin:0 0 2pt;letter-spacing:.5pt}'
        . '.ad-no{text-align:center;font-size:8.5pt;color:#555;margin-bottom:16pt}'
        . 'p{margin:0 0 8pt;text-align:justify}ul{margin:0 0 8pt 16pt;padding:0}li{margin-bottom:2pt}'
        . '.ad-sign{margin-top:30pt;text-align:right}.ad-sign-box{display:inline-block;text-align:left;border-left:3pt solid #0050ef;padding:4pt 0 4pt 8pt;font-size:9pt}';
}

/** PDF wniosku jako string albo null przy błędzie. */
function appDocsPdf(array $app, array $tpl): ?string {
    try {
        require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
        $tmp = UPLOAD_DIR . 'mpdf_tmp';
        if (!is_dir($tmp)) @mkdir($tmp, 0755, true);
        $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 20, 'margin_right' => 18,
                                'margin_top' => 18, 'margin_bottom' => 18, 'default_font' => 'dejavusans', 'tempDir' => $tmp]);
        $mpdf->SetTitle($tpl['title'] . ' ' . appDocsNumber($app, $tpl));
        $mpdf->SetAuthor((string)($app['user_name'] ?? ''));
        $mpdf->SetHTMLFooter('<div style="font-size:7.5pt;color:#777;text-align:right">' . h(appDocsNumber($app, $tpl)) . ' · strona {PAGENO}/{nbpg}</div>');
        $mpdf->WriteHTML(appDocsCss(), \Mpdf\HTMLParserMode::HEADER_CSS);
        $mpdf->WriteHTML(appDocsHtml($app, $tpl), \Mpdf\HTMLParserMode::HTML_BODY);
        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    } catch (\Throwable $e) {
        error_log('[application_documents] ' . $e->getMessage());
        return null;
    }
}

// ── Złożenie elektroniczne: suma kontrolna, rejestracja w EZD, potwierdzenie ─────

/** Suma kontrolna SHA-256 treści wniosku (pola + metadane złożenia) — stała, niezależna od PDF. */
function appDocsHash(array $app): string {
    $fields = json_decode((string)($app['fields_json'] ?? '{}'), true) ?: [];
    ksort($fields);
    return hash('sha256', json_encode([
        'id' => (int)$app['id'], 'type' => (string)($app['type_name'] ?? ''), 'user' => (int)$app['user_id'],
        'title' => (string)$app['tytul'], 'fields' => $fields, 'created' => (string)$app['created_at'],
        'file' => (string)($app['plik'] ?? ''),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** Skrót do wyświetlenia: grupy po 8 znaków. */
function appDocsHashPretty(string $hash): string {
    return trim(chunk_split(strtoupper($hash), 8, ' '));
}

/**
 * Po złożeniu wniosku z wzorem: numer, suma kontrolna, IP, rejestracja przesyłki w EZD (RPW)
 * z PDF wniosku jako skanem. Błąd EZD nie cofa złożenia — trafia do logu, potwierdzenie i tak powstaje.
 */
function appDocsAfterSubmit(int $appId): void {
    $app = appDocsLoad($appId);
    $tpl = $app ? appDocsTemplateFor($app['type_name'] ?? null) : null;
    if (!$app || !$tpl) return;
    $number = appDocsNumber($app, $tpl);
    $hash   = appDocsHash($app);
    db()->prepare("UPDATE user_applications SET doc_number=?, doc_hash=?, submitted_ip=? WHERE id=?")
        ->execute([$number, $hash, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64), $appId]);

    if (!function_exists('module_enabled') || !module_enabled('ezd_enabled')) return;
    try {
        require_once dirname(__DIR__, 3) . '/includes/ezd.php';
        $f = json_decode((string)$app['fields_json'], true) ?: [];
        $rpw = ezd_rpw_create([
            'data_wplywu' => date('Y-m-d', strtotime((string)$app['created_at'])),
            'typ'         => 'inne',
            'nadawca'     => trim((string)($f['wnioskodawca'] ?? '')) ?: (string)$app['user_name'],
            'znak_obcy'   => $number,
            'opis'        => $tpl['code'] . ' — ' . (string)$app['tytul'],
            'uwagi'       => 'Złożono elektronicznie w Panelu współpracownika (' . ($app['user_email'] ?: 'konto #' . $app['user_id']) . '). '
                           . 'Suma kontrolna SHA-256: ' . $hash,
        ], (int)$app['user_id']);
        db()->prepare("UPDATE user_applications SET ezd_rpw_id=? WHERE id=?")->execute([(int)$rpw['id'], $appId]);

        $pdf = appDocsPdf(appDocsLoad($appId) ?? $app, $tpl);
        if ($pdf !== null) {
            $dir = UPLOAD_DIR . EZD_RPW_SUBDIR . (int)$rpw['id'] . '/';
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';
            if (@file_put_contents($dir . $stored, $pdf) !== false) {
                db()->prepare("UPDATE ezd_rpw SET scan_file=?, scan_name=?, scan_mime='application/pdf', scan_size=? WHERE id=?")
                    ->execute([$stored, str_replace('/', '_', $number) . '.pdf', strlen($pdf), (int)$rpw['id']]);
            }
        }
    } catch (\Throwable $e) {
        error_log('[application_documents] EZD RPW dla wniosku #' . $appId . ': ' . $e->getMessage());
    }
}

/** Dane przesyłki RPW powiązanej z wnioskiem (numer do wyświetlenia) albo null. */
function appDocsRpw(array $app): ?array {
    if (empty($app['ezd_rpw_id'])) return null;
    try {
        $r = db_one("SELECT id, rpw_nr, rok, data_wplywu, status FROM ezd_rpw WHERE id = ?", [(int)$app['ezd_rpw_id']]);
        return $r ? $r + ['label' => 'RPW ' . $r['rpw_nr'] . '/' . $r['rok']] : null;
    } catch (\Throwable $e) { return null; }
}

/** Adres potwierdzenia złożenia (HTML) lub jego PDF. */
function appDocsReceiptUrl(int $id, bool $pdf = false): string {
    return APP_URL . '/modules/application_documents/router.php/' . $id . '/potwierdzenie' . ($pdf ? '/pdf' : '');
}

/**
 * Potwierdzenie złożenia wniosku — układ formularza urzędowego (jak CIT-8/UPO): kod wzoru w ramce,
 * sekcje A–D na szarym pasie, numerowane pola w ramkach. Tabele, żeby mPDF odwzorował układ.
 */
function appDocsReceiptHtml(array $app, array $tpl): string {
    $hash   = (string)($app['doc_hash'] ?: appDocsHash($app));
    $valid  = hash_equals($hash, appDocsHash($app));
    $rpw    = appDocsRpw($app);
    $f      = json_decode((string)($app['fields_json'] ?? '{}'), true) ?: [];
    $org    = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
    $n = 0;
    $cell = function (string $label, string $value, int $span = 1, bool $mono = false) use (&$n) {
        $n++;
        return '<td class="rc-c" colspan="' . $span . '"><div class="rc-l">' . $n . '. ' . h($label) . '</div>'
             . '<div class="rc-v' . ($mono ? ' rc-mono' : '') . '">' . ($value !== '' ? h($value) : '&nbsp;') . '</div></td>';
    };
    ob_start(); ?>
<table class="rc-top"><tr>
  <td class="rc-code"><div class="rc-code-k">UPZ</div><div class="rc-code-s">potwierdzenie<br>złożenia</div></td>
  <td class="rc-head"><div class="rc-h1">POTWIERDZENIE ZŁOŻENIA WNIOSKU DROGĄ ELEKTRONICZNĄ</div>
    <div class="rc-h2">wzór <?= h($tpl['code']) ?> · <?= h(mb_strtolower($tpl['title'])) ?></div></td>
  <td class="rc-stamp"><div class="rc-l">Status</div><div class="rc-ok"><?= $valid ? 'PRZYJĘTO' : 'NIEZGODNA SUMA' ?></div></td>
</tr></table>

<div class="rc-sec">A. DANE DOKUMENTU</div>
<table class="rc-t"><tr><?= $cell('Kod wzoru', $tpl['code']) ?><?= $cell('Numer wniosku', (string)($app['doc_number'] ?: appDocsNumber($app, $tpl)), 1, true) ?><?= $cell('Data i godzina złożenia', date('d.m.Y, H:i:s', strtotime((string)$app['created_at']))) ?></tr>
<tr><?= $cell('Tytuł wniosku', (string)$app['tytul'], 3) ?></tr></table>

<div class="rc-sec">B. DANE WNIOSKODAWCY</div>
<table class="rc-t"><tr><?= $cell('Imię i nazwisko lub nazwa', trim((string)($f['wnioskodawca'] ?? '')) ?: (string)$app['user_name'], 2) ?><?= $cell('Konto w SZO (login)', (string)($app['user_email'] ?? '')) ?></tr>
<tr><?= $cell('Adres zamieszkania lub siedziby', (string)($f['adres'] ?? ''), 2) ?><?= $cell('Adres IP przy złożeniu', (string)($app['submitted_ip'] ?? '')) ?></tr></table>

<div class="rc-sec">C. DANE ODBIORCY</div>
<table class="rc-t"><tr><?= $cell('Nazwa odbiorcy', $org, 2) ?><?= $cell('Numer w rejestrze przesyłek (EZD)', $rpw['label'] ?? 'oczekuje na rejestrację', 1, true) ?></tr>
<tr><?= $cell('Adresat wskazany we wniosku', preg_replace('/\s*\R\s*/', ', ', trim((string)($f['organ'] ?? ''))), 2) ?><?= $cell('Sposób złożenia', 'elektronicznie — Panel współpracownika') ?></tr></table>

<div class="rc-sec">D. SUMA KONTROLNA DOKUMENTU</div>
<table class="rc-t"><tr><?= $cell('Algorytm', 'SHA-256') ?><?= $cell('Wartość skrótu treści wniosku', appDocsHashPretty($hash), 2, true) ?></tr></table>

<p class="rc-note">Potwierdzenie wygenerowane automatycznie przez System Wspomagania Zarządzania Organizacją (SZO). Potwierdza wpływ wniosku
o podanym numerze i treści o wskazanej sumie kontrolnej. Wniosek złożono po uwierzytelnieniu w SZO — w miejsce podpisu własnoręcznego.</p>
<?php
    return (string)ob_get_clean();
}

/** Styl potwierdzenia (podgląd i mPDF). */
function appDocsReceiptCss(): string {
    return 'body{font-family:"DejaVu Sans","Segoe UI",Arial,sans-serif;font-size:9pt;color:#111}'
        . 'table{border-collapse:collapse;width:100%}'
        . '.rc-top td{border:1.2pt solid #111;vertical-align:middle;padding:5pt 7pt}'
        . '.rc-code{width:22%;text-align:center;background:#111;color:#fff}.rc-code-k{font-size:20pt;font-weight:bold;letter-spacing:2pt}.rc-code-s{font-size:7pt;text-transform:uppercase;letter-spacing:.6pt}'
        . '.rc-h1{font-size:11.5pt;font-weight:bold;letter-spacing:.3pt}.rc-h2{font-size:8pt;color:#444;margin-top:2pt}'
        . '.rc-stamp{width:18%;text-align:center}.rc-ok{font-size:11pt;font-weight:bold;color:#007a00;margin-top:2pt}'
        . '.rc-sec{background:#d9d9d9;border:1.2pt solid #111;border-top:0;font-weight:bold;font-size:8.5pt;padding:3pt 7pt;letter-spacing:.4pt;margin-top:8pt}'
        . '.rc-t{margin-top:0}.rc-c{border:1pt solid #111;border-top:0;vertical-align:top;padding:2pt 5pt 4pt;background:#fff}'
        . '.rc-l{font-size:6.8pt;color:#333}.rc-v{font-size:10pt;font-weight:bold;min-height:12pt;margin-top:2pt;word-wrap:break-word}'
        . '.rc-mono{font-family:"DejaVu Sans Mono",monospace;font-size:9pt}.rc-note{font-size:7.5pt;color:#444;margin-top:12pt;line-height:1.45}';
}

/** PDF potwierdzenia albo null. */
function appDocsReceiptPdf(array $app, array $tpl): ?string {
    try {
        require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
        $tmp = UPLOAD_DIR . 'mpdf_tmp';
        if (!is_dir($tmp)) @mkdir($tmp, 0755, true);
        $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 14, 'margin_right' => 14,
                                'margin_top' => 14, 'margin_bottom' => 14, 'default_font' => 'dejavusans', 'tempDir' => $tmp]);
        $mpdf->SetTitle('Potwierdzenie złożenia ' . ($app['doc_number'] ?: appDocsNumber($app, $tpl)));
        $mpdf->WriteHTML(appDocsReceiptCss(), \Mpdf\HTMLParserMode::HEADER_CSS);
        $mpdf->WriteHTML(appDocsReceiptHtml($app, $tpl), \Mpdf\HTMLParserMode::HTML_BODY);
        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    } catch (\Throwable $e) {
        error_log('[application_documents] potwierdzenie: ' . $e->getMessage());
        return null;
    }
}
