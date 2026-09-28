<?php
/**
 * tozsamosc/rodo_export.php — Eksport danych osobowych do PDF (art. 15 i 20 RODO).
 *
 * Dostęp: tylko zalogowany właściciel konta (brak parametrów id — zawsze własne dane).
 * Generuje PDF z pełnym zakresem danych przetwarzanych przez administratora.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth_security.php';
require_once dirname(__DIR__) . '/includes/rodo.php';

auth_start();
if (!current_user()) {
    header('Location: ' . APP_URL . '/tozsamosc/login.php');
    exit;
}
require_login();
try { rodo_migrate(); } catch (\Throwable $e) {}

$h   = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$user = current_user();
$uid  = (int)$user['id'];

// ── Dane użytkownika ──────────────────────────────────────────────────────────
$db_user     = db_one("SELECT * FROM users WHERE id=?", [$uid]);
$panel_login = $user['email'] ?? '';
$m365_login  = $db_user['m365_login'] ?? '';
$microsoft_id = $db_user['microsoft_id'] ?? '';
$phone_number = $db_user['phone_number'] ?? '';
$mfa_method  = $db_user['twofa_method'] ?? '';
$mfa_label   = $mfa_method === 'totp' ? 'Aplikacja Authenticator'
             : ($mfa_method === 'sms' ? 'Kod SMS' : 'Nieaktywne');

// Maskowanie telefonu: +48 ••• ••• 200
$phone_masked = '';
if ($phone_number !== '') {
    $d = preg_replace('/\D/', '', $phone_number);
    $phone_masked = strlen($d) >= 3
        ? '+' . substr($d, 0, max(0, strlen($d) - 9)) . ' ••• ••• ' . substr($d, -3)
        : $phone_number;
}

// ── Konto M365 ────────────────────────────────────────────────────────────────
$m365_row = null;
foreach ([['m365_user_id = ?',$microsoft_id],['m365_login = ?',$m365_login],['email = ?',$panel_login]] as [$cond,$val]) {
    if ($val === '' || $val === null) continue;
    $m365_row = db_one("SELECT m365_user_id,m365_login,m365_konto_aktywne,m365_licencja_przypisana,m365_data_utworzenia,numer_umowy,data_zawarcia FROM umowy_wolontariat WHERE {$cond} AND m365_user_id != '' AND m365_konto = 1 ORDER BY id DESC LIMIT 1", [$val]);
    if ($m365_row) break;
}

// ── Umowa źródłowa ────────────────────────────────────────────────────────────
$src_contract = null; $account_type_label = 'Konto SZO';
foreach ([['wolontariat','Wolontariusz'],['zlecenie','Zleceniobiorca'],['praca','Pracownik'],['dzielo','Wykonawca dzieła']] as [$t,$lbl]) {
    $ecol = ($t === 'praca') ? 'email_login' : 'email';
    try {
        $row = db_one("SELECT numer_umowy, data_zawarcia FROM umowy_{$t} WHERE {$ecol}=? ORDER BY id DESC LIMIT 1", [$panel_login]);
    } catch (\Throwable $e) { $row = null; }
    if ($row) { $src_contract = $row; $account_type_label = $lbl; break; }
}

// ── RODO: upoważnienia + historia ─────────────────────────────────────────────
$rodo_org  = function_exists('rodo_org_data') ? rodo_org_data() : ['name'=>'','address'=>'','city'=>'','nip'=>'','krs'=>''];
$rodo_auth = []; $rodo_ids = [];
foreach (rodo_contract_ids_for_login((string)$panel_login, (string)($microsoft_id ?? '')) as $ctype => $ids) {
    $rodo_ids[$ctype] = $ids;
    $ph = implode(',', array_fill(0, count($ids), '?'));
    try { foreach (db_all("SELECT * FROM rodo_authorizations WHERE contract_type=? AND contract_id IN ({$ph}) ORDER BY created_at DESC", array_merge([$ctype],$ids)) as $a) $rodo_auth[] = $a; } catch (\Throwable $e) {}
}
$REG_ENTER  = ['login','cpc_verify_ok','admin_impersonate','admin_impersonate_stop'];
$REG_MODIFY = ['edit','status','renewal_create','renewed_by','user_role_change','user_password_reset','delete','note','consent_accepted'];
$rodo_history = [];
if ($rodo_ids) {
    $conds = []; $params = [];
    foreach ($rodo_ids as $ct=>$ids) {
        $ph = implode(',', array_fill(0,count($ids),'?'));
        $conds[] = "(contract_type=? AND contract_id IN ({$ph}))";
        $params[] = $ct; foreach ($ids as $i) $params[] = $i;
    }
    $inActions = array_merge($REG_ENTER, $REG_MODIFY);
    $aph = implode(',', array_fill(0,count($inActions),'?'));
    try {
        $rodo_history = db_all(
            "SELECT contract_type, contract_id, action, note, created_at FROM contract_audit_log
             WHERE (" . implode(' OR ',$conds) . ") AND action IN ({$aph})
             ORDER BY created_at DESC LIMIT 50",
            array_merge($params, $inActions)
        );
    } catch (\Throwable $e) { $rodo_history = []; }
}
$ACT_LABELS = [
    'login'               => 'Wejście do konta',
    'cpc_verify_ok'       => 'Weryfikacja tożsamości',
    'admin_impersonate'   => 'Wejście administratora',
    'admin_impersonate_stop' => 'Koniec wejścia administratora',
    'edit'                => 'Modyfikacja danych umowy',
    'status'              => 'Zmiana statusu umowy',
    'renewal_create'      => 'Przedłużenie umowy',
    'renewed_by'          => 'Przedłużenie umowy',
    'user_role_change'    => 'Zmiana roli',
    'user_password_reset' => 'Reset hasła',
    'consent_accepted'    => 'Akceptacja zgody',
    'note'                => 'Notatka',
    'delete'              => 'Usunięcie',
];

// ── Budowanie HTML ────────────────────────────────────────────────────────────
$now     = date('d.m.Y H:i');
$org     = defined('ORG_NAME') ? ORG_NAME : '';
$org_adr = trim(($rodo_org['address'] ?? '') . ' ' . ($rodo_org['city'] ?? ''));
$org_nip = trim(($rodo_org['nip'] ?? '') . ($rodo_org['krs'] ? ' / KRS ' . $rodo_org['krs'] : ''));

// Sekcja: dane identyfikacyjne
$s_ident = '
<h2 class="sec-h">1. Dane identyfikacyjne</h2>
<table class="dt">
  <tr><th>Imię i nazwisko</th><td>' . $h($db_user['name'] ?? '—') . '</td></tr>
  <tr><th>Numer UID</th><td>' . $h($uid) . '</td></tr>
  <tr><th>Login (identyfikator sieciowy)</th><td>' . $h($panel_login) . '</td></tr>
  <tr><th>Login Microsoft 365</th><td>' . ($m365_login ? $h($m365_login) : '<em class="na">— brak konta M365 —</em>') . '</td></tr>
  <tr><th>Typ konta</th><td>' . $h($account_type_label) . '</td></tr>
  <tr><th>Umowa źródłowa</th><td>' . ($src_contract ? $h($src_contract['numer_umowy']) . ' (zawarta ' . $h(date('d.m.Y', strtotime($src_contract['data_zawarcia']))) . ')' : '<em class="na">— brak powiązanej umowy —</em>') . '</td></tr>
  <tr><th>Konto założone</th><td>' . (!empty($db_user['created_at']) ? $h(date('d.m.Y', strtotime($db_user['created_at']))) : '—') . '</td></tr>
</table>';

// Sekcja: konto M365
$s_m365 = '<h2 class="sec-h">2. Konto Microsoft 365 / Entra ID</h2>';
if ($m365_row) {
    $s_m365 .= '
<table class="dt">
  <tr><th>Identyfikator konta (M365 ID)</th><td class="mono">' . $h($m365_row['m365_user_id']) . '</td></tr>
  <tr><th>Login M365</th><td>' . $h($m365_row['m365_login']) . '</td></tr>
  <tr><th>Status konta</th><td>' . ($m365_row['m365_konto_aktywne'] ? 'Aktywne' : 'Nieaktywne') . '</td></tr>
  <tr><th>Licencja przypisana</th><td>' . ($m365_row['m365_licencja_przypisana'] ? 'Tak' : 'Nie') . '</td></tr>
  ' . (!empty($m365_row['m365_data_utworzenia']) ? '<tr><th>Data utworzenia konta M365</th><td>' . $h(date('d.m.Y', strtotime($m365_row['m365_data_utworzenia']))) . '</td></tr>' : '') . '
</table>';
} else {
    $s_m365 .= '<p class="na-p">Brak powiązanego konta Microsoft 365 / Entra ID.</p>';
}

// Sekcja: bezpieczeństwo
$s_sec = '
<h2 class="sec-h">3. Uwierzytelnianie i bezpieczeństwo</h2>
<table class="dt">
  <tr><th>Hasło lokalne (SZO)</th><td>' . (!empty($db_user['password']) ? 'Ustawione' : 'Brak') . '</td></tr>
  <tr><th>Dwuskładnikowe uwierzytelnianie (MFA)</th><td>' . $h($mfa_label) . '</td></tr>
  <tr><th>Numer telefonu (do SMS/MFA)</th><td>' . ($phone_masked ? $h($phone_masked) : '<em class="na">— nie podano —</em>') . '</td></tr>
  <tr><th>Kod IKA</th><td>' . (!empty($db_user['cpc_code']) ? 'Ustawiony' : 'Brak') . '</td></tr>
  <tr><th>Klucze WebAuthn (sprzętowe)</th><td>' . (function(){
        try {
            $cnt = (int)(db_one("SELECT COUNT(*) c FROM webauthn_credentials WHERE user_id=?", [current_user()['id']])['c'] ?? 0);
            return $cnt > 0 ? "Zarejestrowane: {$cnt}" : 'Brak';
        } catch (\Throwable $e) { return '—'; }
    })() . '</td></tr>
</table>';

// Sekcja: administrator danych
$s_admin = '
<h2 class="sec-h">4. Administrator danych osobowych</h2>
<table class="dt">
  <tr><th>Nazwa</th><td>' . $h($rodo_org['name'] ?: $org) . '</td></tr>
  <tr><th>Adres</th><td>' . ($org_adr ? $h($org_adr) : '—') . '</td></tr>
  <tr><th>NIP / KRS</th><td>' . ($org_nip ? $h($org_nip) : '—') . '</td></tr>
  <tr><th>Cel przetwarzania</th><td>Realizacja umowy i obowiązków organizacji</td></tr>
  <tr><th>Podstawa prawna</th><td>Wykonanie umowy (art. 6 ust. 1 lit. b RODO)</td></tr>
  <tr><th>Okres przechowywania</th><td>Czas trwania umowy + okres wymagany przepisami prawa</td></tr>
</table>';

// Sekcja: upoważnienia RODO
$s_upo = '<h2 class="sec-h">5. Upoważnienia do przetwarzania danych osobowych</h2>';
if (!$rodo_auth) {
    $s_upo .= '<p class="na-p">Brak upoważnień powiązanych z Twoim kontem.</p>';
} else {
    foreach ($rodo_auth as $idx => $a) {
        $scope = json_decode($a['scope_items'] ?? '[]', true) ?: [];
        $scope_labels = array_map(fn($k) => RODO_SCOPE_ITEMS[$k] ?? $k, $scope);
        if (!empty($a['scope_custom'])) $scope_labels[] = $a['scope_custom'];
        $valid_from  = !empty($a['authorized_from'])  ? date('d.m.Y', strtotime($a['authorized_from']))  : '—';
        $valid_until = !empty($a['authorized_until']) ? date('d.m.Y', strtotime($a['authorized_until'])) : 'do zakończenia umowy';
        $status_map  = ['aktywne'=>'Aktywne','wycofane'=>'Wycofane','wygasle'=>'Wygasłe'];
        $status_lbl  = $status_map[$a['status']] ?? $a['status'];
        $s_upo .= '
<table class="dt" style="margin-bottom:8pt">
  <tr><th>Numer</th><td class="mono">' . $h($a['number']) . '</td></tr>
  <tr><th>Status</th><td>' . $h($status_lbl) . '</td></tr>
  <tr><th>Ważność</th><td>' . $h($valid_from) . ' – ' . $h($valid_until) . '</td></tr>
  ' . ($scope_labels ? '<tr><th>Zakres przetwarzania</th><td>' . implode('; ', array_map(fn($l)=>$h($l), $scope_labels)) . '</td></tr>' : '') . '
</table>';
    }
}

// Sekcja: rejestr czynności
$s_hist = '<h2 class="sec-h">6. Rejestr czynności na umowie</h2>';
if (!$rodo_history) {
    $s_hist .= '<p class="na-p">Brak zarejestrowanych czynności.</p>';
} else {
    $s_hist .= '<table class="tbl"><thead><tr><th>Data i czas</th><th>Zdarzenie</th><th>Kategoria</th><th>Uwagi</th></tr></thead><tbody>';
    foreach ($rodo_history as $ev) {
        $lbl     = $ACT_LABELS[$ev['action']] ?? $ev['action'];
        $cat     = in_array($ev['action'], $REG_ENTER, true) ? 'Wejście' : 'Modyfikacja';
        $date    = !empty($ev['created_at']) ? date('d.m.Y H:i', strtotime($ev['created_at'])) : '—';
        $note    = !empty($ev['note']) ? mb_strimwidth($ev['note'], 0, 100, '…') : '—';
        $s_hist .= '<tr><td class="mono nowrap">' . $h($date) . '</td><td>' . $h($lbl) . '</td><td>' . $h($cat) . '</td><td>' . $h($note) . '</td></tr>';
    }
    $s_hist .= '</tbody></table><p class="small-note">Wyświetlono maksymalnie 50 ostatnich zdarzeń.</p>';
}

// ── Złożenie HTML ─────────────────────────────────────────────────────────────
$body_html = '
<div class="cover">
  <div class="cover-title">Informacja o przetwarzaniu danych osobowych</div>
  <div class="cover-sub">Eksport na podstawie art. 15 i 20 RODO — prawo dostępu i przenoszenia danych</div>
  <table class="cover-meta">
    <tr><td>Osoba:</td><td><strong>' . $h($db_user['name'] ?? $panel_login) . '</strong></td></tr>
    <tr><td>UID:</td><td>' . $h($uid) . '</td></tr>
    <tr><td>Data wygenerowania:</td><td>' . $h($now) . '</td></tr>
    <tr><td>Administrator:</td><td>' . $h($rodo_org['name'] ?: $org) . '</td></tr>
  </table>
  <p class="cover-note">Dokument zawiera dane przetwarzane przez administratora na Twój temat. Wygenerowano na wniosek osoby, której dane dotyczą.</p>
</div>

' . $s_ident . $s_m365 . $s_sec . $s_admin . $s_upo . $s_hist . '

<p class="footer-note">Wygenerowano automatycznie ' . $h($now) . ' · ' . $h($rodo_org['name'] ?: $org) . ' · System Tożsamości</p>
';

$base_css = '
body {
  font-family: "DejaVu Sans", sans-serif;
  font-size: 10pt;
  line-height: 1.55;
  color: #111;
}
.cover {
  text-align: center;
  padding: 40pt 20pt 30pt;
  border-bottom: 2px solid #1E6DFF;
  margin-bottom: 20pt;
}
.cover-title {
  font-size: 16pt;
  font-weight: bold;
  color: #1E6DFF;
  margin-bottom: 6pt;
}
.cover-sub {
  font-size: 9pt;
  color: #555;
  margin-bottom: 18pt;
}
.cover-meta {
  margin: 0 auto 14pt;
  font-size: 10pt;
  border-collapse: collapse;
}
.cover-meta td {
  padding: 2pt 8pt;
  text-align: left;
}
.cover-meta td:first-child {
  color: #555;
  white-space: nowrap;
}
.cover-note {
  font-size: 8pt;
  color: #777;
  margin-top: 8pt;
}
.sec-h {
  font-size: 11pt;
  font-weight: bold;
  color: #1E6DFF;
  border-bottom: 1px solid #1E6DFF;
  padding-bottom: 3pt;
  margin-top: 18pt;
  margin-bottom: 8pt;
}
.dt {
  width: 100%;
  border-collapse: collapse;
  font-size: 9.5pt;
  margin-bottom: 4pt;
}
.dt th {
  width: 44%;
  text-align: left;
  font-weight: 600;
  color: #444;
  padding: 3pt 6pt 3pt 0;
  vertical-align: top;
  border-bottom: 1px solid #E5E9F0;
}
.dt td {
  padding: 3pt 0;
  vertical-align: top;
  border-bottom: 1px solid #E5E9F0;
}
.tbl {
  width: 100%;
  border-collapse: collapse;
  font-size: 8.5pt;
  margin-bottom: 4pt;
}
.tbl thead th {
  background: #F4F6F9;
  font-weight: 700;
  padding: 3pt 5pt;
  border: 1px solid #E5E9F0;
  text-align: left;
}
.tbl tbody td {
  padding: 3pt 5pt;
  border: 1px solid #E5E9F0;
  vertical-align: top;
}
.tbl tbody tr:nth-child(even) td { background: #fafbfc; }
.mono { font-family: "DejaVu Sans Mono", monospace; font-size: 8.5pt; }
.nowrap { white-space: nowrap; }
.na { color: #888; font-style: italic; }
.na-p { color: #888; font-style: italic; font-size: 9pt; margin: 4pt 0 10pt; }
.small-note { font-size: 7.5pt; color: #888; margin-top: 4pt; }
.footer-note { font-size: 7.5pt; color: #aaa; text-align: center; margin-top: 30pt; border-top: 1px solid #eee; padding-top: 6pt; }
';

// ── Generowanie PDF ───────────────────────────────────────────────────────────
require_once dirname(__DIR__) . '/vendor/autoload.php';

$mpdf_tmp = UPLOAD_DIR . 'mpdf_tmp';
if (!is_dir($mpdf_tmp)) @mkdir($mpdf_tmp, 0755, true);

$safe_name = preg_replace('/[^A-Za-z0-9_-]/', '_', $db_user['name'] ?? 'eksport');
$filename  = 'Eksport-RODO-UID' . $uid . '-' . date('Y-m-d') . '.pdf';

try {
    $mpdf = new \Mpdf\Mpdf([
        'mode'          => 'utf-8',
        'format'        => 'A4',
        'margin_left'   => 22,
        'margin_right'  => 20,
        'margin_top'    => 16,
        'margin_bottom' => 16,
        'default_font'  => 'dejavusans',
        'tempDir'       => $mpdf_tmp,
    ]);
    $mpdf->SetTitle('Eksport RODO — ' . ($db_user['name'] ?? $panel_login));
    $mpdf->SetSubject('Informacja o przetwarzaniu danych osobowych (art. 15 i 20 RODO)');
    $mpdf->SetAuthor($rodo_org['name'] ?: $org);
    $mpdf->SetCreator('FEER SZO — System Tożsamości');
    $mpdf->WriteHTML($base_css, \Mpdf\HTMLParserMode::HEADER_CSS);
    $mpdf->WriteHTML($body_html, \Mpdf\HTMLParserMode::HTML_BODY);
    $mpdf->Output($filename, \Mpdf\Output\Destination::INLINE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo '<p style="font-family:sans-serif;color:red;padding:2rem">Błąd generowania PDF: ' . htmlspecialchars($e->getMessage()) . '</p>';
}
