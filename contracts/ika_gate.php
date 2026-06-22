<?php
/**
 * contracts/ika_gate.php — Brama IKA (Indywidualny Kod Autoryzacyjny).
 *
 * Tryby weryfikacji:
 *   1. Kod IKA (6 cyfr) — domyślny
 *   2. Email OTP — metoda pierwotna (gdy ika_email_method=1) lub odzyskiwanie
 *   3. PESEL challenge — alternatywa gdy user ma kartotekę z PESEL-em
 *   4. Setup (AdminCode) — jednorazowe ustawianie kodów
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/cpc.php';
require_once dirname(__DIR__) . '/includes/branding.php';
require_once dirname(__DIR__) . '/includes/ksiegowosc.php';

require_login();
cpc_migrate();

$user    = current_user();
$role    = $user['role'] ?? '';
$user_id = (int)$user['id'];
$user_ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

// ── Uprawnienia do IKA ────────────────────────────────────────────────────────
$_ika_k30 = false;
$_is_crm_only_role = false;
if (!in_array($role, ['admin', 'editor', 'crm_user'], true)) {
    try { $r = db_one("SELECT crm_only FROM roles WHERE name=?", [$role]); $_is_crm_only_role = !empty($r['crm_only']); } catch (\Throwable $e) {}
    if (!$_is_crm_only_role) {
        try { $row = db_one("SELECT k30_consultant FROM users WHERE id=?", [$user_id]); $_ika_k30 = !empty($row['k30_consultant']); } catch (\Throwable $e) {}
    }
    if (!$_ika_k30 && !$_is_crm_only_role) $_ika_k30 = is_crm_only();
    if (!$_ika_k30 && !$_is_crm_only_role) {
        $dest = is_crm_only() ? '/crm/dashboard.php' : '/index.php';
        header('Location: ' . APP_URL . $dest); exit;
    }
}

// ── Walidacja URL powrotu ─────────────────────────────────────────────────────
$raw_to    = trim($_GET['to'] ?? $_POST['to'] ?? '');
$return_to = '';
if ($raw_to !== '') {
    $app_path = parse_url(APP_URL, PHP_URL_PATH) ?: '';
    $raw_path = parse_url($raw_to, PHP_URL_PATH) ?? '';
    $raw_host = parse_url($raw_to, PHP_URL_HOST) ?? '';
    $app_host = parse_url(APP_URL, PHP_URL_HOST) ?? '';
    $host_ok  = ($raw_host === '' || $raw_host === $app_host);
    $path_ok  = ($app_path === '' || $app_path === '/' || str_starts_with($raw_path, $app_path));
    if ($host_ok && $path_ok)                        $return_to = $raw_to;
    elseif (str_starts_with($raw_to, APP_URL . '/')) $return_to = $raw_to;
}
if ($return_to === '') {
    $return_to = is_crm_only() ? APP_URL . '/crm/dashboard.php' : APP_URL . '/index.php';
}

// ── Parsowanie kontekstu żądania ──────────────────────────────────────────────
function _ika_parse_destination(string $url): array {
    $path = parse_url($url, PHP_URL_PATH) ?? '';
    $qs   = [];
    parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $qs);
    $id_str = (isset($qs['id']) && ctype_digit((string)$qs['id'])) ? ' #' . (int)$qs['id'] : '';

    // Typ akcji na podstawie pliku
    $fname       = basename($path);
    $action_type = match(true) {
        str_contains($fname, 'add')    => 'Dodawanie',
        str_contains($fname, 'edit')   => 'Edycja',
        str_contains($fname, 'view')   => 'Przeglądanie',
        str_contains($fname, 'delete') => 'Usuwanie',
        str_contains($fname, 'users')  => 'Zarządzanie użytkownikami',
        default                        => 'Dostęp',
    };

    // Mapowanie segmentów URL → [icon, color, module, resource, ctx]
    $map = [
        '/admin/users'             => ['bi-people',              '#6d28d9', 'Panel administratora', 'Zarządzanie użytkownikami'],
        '/admin/'                  => ['bi-shield-lock',          '#6d28d9', 'Panel administratora', 'Ustawienia systemu'],
        '/karty30/'                => ['bi-person-vcard',         '#dc2626', 'Dydaktyka',            'Karty 30 — dydaktyka i konsultacje'],
        '/crm/contact'             => ['bi-person-fill',          '#059669', 'CRM',                  'Profil kontaktu' . $id_str],
        '/crm/case'                => ['bi-briefcase-fill',       '#059669', 'CRM',                  'Sprawa' . $id_str],
        '/crm/letter'              => ['bi-envelope-paper-fill',  '#059669', 'CRM — Pisma',          'Pismo' . $id_str],
        '/crm/dashboard'           => ['bi-speedometer2',         '#059669', 'CRM',                  'Panel główny CRM'],
        '/crm/'                    => ['bi-diagram-2-fill',       '#059669', 'CRM',                  'Moduł CRM'],
        '/contracts/wolontariat/'  => ['bi-people-fill',          '#2563eb', 'Umowy wolontariackie', ($id_str ? 'Umowa' . $id_str : $action_type)],
        '/contracts/zlecenie/'     => ['bi-file-earmark-text',    '#2563eb', 'Umowy zlecenie',       ($id_str ? 'Umowa' . $id_str : $action_type)],
        '/contracts/dzielo/'       => ['bi-file-earmark-text',    '#2563eb', 'Umowy o dzieło',      ($id_str ? 'Umowa' . $id_str : $action_type)],
        '/contracts/praca/'        => ['bi-file-earmark-text',    '#2563eb', 'Umowy o pracę',       ($id_str ? 'Umowa' . $id_str : $action_type)],
        '/contracts/uslugi/'       => ['bi-file-earmark-text',    '#2563eb', 'Umowy usługowe',      ($id_str ? 'Umowa' . $id_str : $action_type)],
        '/contracts/inne/'         => ['bi-file-earmark-text',    '#2563eb', 'Inne umowy',          ($id_str ? 'Umowa' . $id_str : $action_type)],
        '/rodo/'                   => ['bi-lock-fill',             '#dc2626', 'RODO',                'Klauzule informacyjne'],
        '/resources/'              => ['bi-box-seam-fill',        '#0891b2', 'Zasoby',              'Zarządzanie zasobami'],
        '/grants/'                 => ['bi-currency-euro',        '#b45309', 'Granty',               'Moduł grantów'],
        '/actions/'                => ['bi-lightning-fill',       '#0891b2', 'Działania',            'Moduł działań'],
    ];

    foreach ($map as $seg => [$icon, $color, $module, $resource]) {
        if (str_contains($path, $seg)) {
            return compact('icon', 'color', 'module', 'resource');
        }
    }
    return ['icon' => 'bi-shield-check', 'color' => '#64748b', 'module' => 'System', 'resource' => 'Strona chroniona'];
}

$dest_ctx = _ika_parse_destination($return_to);

// ── Dane IKA z DB ────────────────────────────────────────────────────────────
$u_fresh = db_one(
    "SELECT cpc_code, cpc_fails, cpc_blocked_until, email,
            ika_email_otp, ika_email_otp_expires, ika_email_method
     FROM users WHERE id=?",
    [$user_id]
);
$has_code         = !empty($u_fresh['cpc_code']);
$is_blocked       = !empty($u_fresh['cpc_blocked_until']) && $u_fresh['cpc_blocked_until'] > date('Y-m-d H:i:s');
$user_email       = trim($u_fresh['email'] ?? '');
$ika_email_method = (bool)($u_fresh['ika_email_method'] ?? false);
$email_is_primary = $ika_email_method && $user_email !== '';

// ── Setup token ───────────────────────────────────────────────────────────────
$has_setup_token = false;
try {
    $u_token = db_one("SELECT ika_setup_token, ika_setup_token_expires FROM users WHERE id=?", [$user_id]);
    $has_setup_token = !empty($u_token['ika_setup_token'])
        && !empty($u_token['ika_setup_token_expires'])
        && $u_token['ika_setup_token_expires'] > date('Y-m-d H:i:s');
} catch (\Throwable $e) {}

// ── PESEL z kartoteki umów ─────────────────────────────────────────────────────
function _ika_find_pesel(int $uid): ?string {
    $u     = db_one("SELECT email, microsoft_id FROM users WHERE id=?", [$uid]);
    $email = strtolower(trim($u['email'] ?? ''));
    $ms_id = trim($u['microsoft_id'] ?? '');
    $tables = [
        ['umowy_wolontariat', 'email',        $email],
        ['umowy_wolontariat', 'm365_login',   $email],
        ['umowy_wolontariat', 'm365_user_id', $ms_id],
    ];
    foreach ($tables as [$tbl, $col, $val]) {
        if (!$val) continue;
        try {
            $row = db_one("SELECT pesel FROM {$tbl}
                           WHERE LOWER({$col})=? AND pesel IS NOT NULL AND LENGTH(pesel)=11
                           LIMIT 1", [$val]);
            if ($row && strlen($row['pesel']) === 11) return $row['pesel'];
        } catch (\Throwable $e) {}
    }
    return null;
}
$pesel_available = (_ika_find_pesel($user_id) !== null);

// ── Rate limit per IP w sesji ─────────────────────────────────────────────────
auth_start();
$_sess_key  = 'ika_ip_fails_' . md5($user_ip);
$_ip_fails  = (int)($_SESSION[$_sess_key] ?? 0);
$_ip_blocked = $_ip_fails >= 10;

// ── Branding ──────────────────────────────────────────────────────────────────
$_b      = branding_load();
$org_name = $_b['org_name'] ?: (defined('ORG_NAME') ? ORG_NAME : 'System');

// ── Display name / initials ───────────────────────────────────────────────────
$display_name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
if ($display_name === '') $display_name = $user['name'] ?? $user['email'] ?? '';
$initials = '';
foreach (preg_split('/\s+/', trim($display_name)) as $w) $initials .= mb_strtoupper(mb_substr($w,0,1,'UTF-8'),'UTF-8');
$initials   = mb_substr($initials, 0, 2, 'UTF-8') ?: '?';
$role_label = match(true) {
    $role === 'admin'    => 'Administrator',
    $role === 'editor'   => 'Edytor',
    $role === 'crm_user' => 'Użytkownik CRM',
    $_ika_k30            => 'Doradca — Dydaktyka',
    default              => ucfirst($role),
};

// ═════════════════════════════════════════════════════════════════════════════
// OBSŁUGA TRYBÓW
// ═════════════════════════════════════════════════════════════════════════════

$page_mode = 'ika';
$error     = '';
$info      = '';

// ─── POST ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_ok = hash_equals($_SESSION['csrf'] ?? '', $_POST['_csrf'] ?? '');
    if (!$csrf_ok) { $error = 'Nieprawidłowy token CSRF. Odśwież stronę.'; goto render; }
    if (!empty($_POST['_hp'])) { sleep(2); $error = 'Weryfikacja nieudana.'; goto render; }
    if ($_ip_blocked) { $error = 'Zbyt wiele prób z tego komputera. Zamknij i otwórz przeglądarkę.'; goto render; }

    $mode = $_POST['_mode'] ?? 'ika';

    // ── 1. Weryfikacja kodu IKA ─────────────────────────────────────────────
    if ($mode === 'ika') {
        if (!$has_code) { $error = 'Nie masz przypisanego kodu IKA.'; goto render; }
        if ($is_blocked) { $error = 'Kod IKA zablokowany do ' . date('H:i', strtotime($u_fresh['cpc_blocked_until'])) . '.'; goto render; }

        $code   = preg_replace('/\D/', '', $_POST['ika_code'] ?? '');
        $result = cpc_verify($user_id, $code);

        if ($result['blocked']) {
            $_SESSION[$_sess_key] = $_ip_fails + 1;
            $error = 'Zbyt wiele błędnych prób. Kod IKA zablokowany na 15 minut.';
        } elseif ($result['ok']) {
            unset($_SESSION[$_sess_key]);
            ika_set_verified();
            header('Location: ' . $return_to); exit;
        } else {
            $_SESSION[$_sess_key] = $_ip_fails + 1;
            $fails = (int)($result['fails'] ?? 0);
            $left  = max(0, 3 - $fails);
            $error = 'Nieprawidłowy kod IKA.';
            if ($fails >= 2) $error .= ' Pozostało prób: ' . $left . '.';
        }
        goto render;
    }

    // ── 2. Żądanie email OTP ───────────────────────────────────────────────
    if ($mode === 'request_email_otp') {
        if (!$user_email) { $error = 'Brak adresu e-mail na koncie.'; goto render; }

        if (!empty($u_fresh['ika_email_otp_expires'])) {
            $throttle_until = date('Y-m-d H:i:s', strtotime($u_fresh['ika_email_otp_expires']) - 780);
            if ($throttle_until > date('Y-m-d H:i:s')) {
                $error     = 'Kod e-mail został już wysłany. Poczekaj chwilę przed ponowną próbą.';
                $page_mode = 'email_verify';
                goto render;
            }
        }

        $otp      = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $otp_hash = password_hash($otp, PASSWORD_BCRYPT);
        $expires  = date('Y-m-d H:i:s', strtotime('+15 minutes'));

        db()->prepare("UPDATE users SET ika_email_otp=?, ika_email_otp_expires=? WHERE id=?")
            ->execute([$otp_hash, $expires, $user_id]);

        $subject  = 'Kod weryfikacyjny IKA — ' . $org_name;
        $mod_safe = htmlspecialchars($dest_ctx['module'] . ' / ' . $dest_ctx['resource']);
        $mail_body = <<<HTML
<html><body style="font-family:sans-serif;max-width:540px;margin:0 auto;padding:20px;color:#212529">
<div style="background:linear-gradient(135deg,#1e3a5f,#1e40af);padding:20px 24px;border-radius:10px 10px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.1rem">Kod weryfikacyjny IKA</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:24px;border-radius:0 0 10px 10px">
  <p>Otrzymujesz ten kod ponieważ zażądałeś/aś jednorazowego dostępu do: <strong>{$mod_safe}</strong></p>
  <div style="text-align:center;margin:24px 0">
    <div style="display:inline-block;background:#f0f7ff;border:2px solid #2563eb;border-radius:10px;padding:16px 32px">
      <div style="font-size:2.2rem;font-weight:800;letter-spacing:.35em;font-family:monospace;color:#1e3a5f">{$otp}</div>
    </div>
  </div>
  <p style="font-size:.88em;color:#6c757d">Kod jest ważny przez <strong>15 minut</strong>. Nie udostępniaj go nikomu.</p>
</div>
</body></html>
HTML;
        try {
            require_once dirname(__DIR__) . '/includes/mail_queue.php';
            require_once dirname(__DIR__) . '/includes/approval.php';
            mail_queue_add($user_email, $display_name, $subject, $mail_body, '', 'ika_otp', $user_id, '', true);
        } catch (\Throwable $e) {}

        $info      = 'Kod jednorazowy został wysłany na ' . $user_email . '. Sprawdź skrzynkę.';
        $page_mode = 'email_verify';
        goto render;
    }

    // ── 3. Weryfikacja email OTP ────────────────────────────────────────────
    if ($mode === 'verify_email_otp') {
        $otp_input = preg_replace('/\D/', '', $_POST['email_otp'] ?? '');

        if (empty($u_fresh['ika_email_otp']) || empty($u_fresh['ika_email_otp_expires'])) {
            $error = 'Brak aktywnego kodu e-mail. Wygeneruj nowy.'; $page_mode = 'email_verify'; goto render;
        }
        if ($u_fresh['ika_email_otp_expires'] < date('Y-m-d H:i:s')) {
            $error = 'Kod wygasł. Wygeneruj nowy.'; $page_mode = 'email_verify'; goto render;
        }
        if (!password_verify($otp_input, $u_fresh['ika_email_otp'])) {
            $_SESSION[$_sess_key] = $_ip_fails + 1;
            $error = 'Nieprawidłowy kod.'; $page_mode = 'email_verify'; goto render;
        }

        db()->prepare("UPDATE users SET ika_email_otp=NULL, ika_email_otp_expires=NULL WHERE id=?")
            ->execute([$user_id]);
        unset($_SESSION[$_sess_key]);
        ika_set_verified();
        header('Location: ' . $return_to); exit;
    }

    // ── 4. Ustawienie kodów IKA + IKAKS (AdminCode) ────────────────────────
    if ($mode === 'setup') {
        $admin_code = strtoupper(trim($_POST['admin_code'] ?? ''));
        $cpc1       = trim($_POST['cpc_code'] ?? '');
        $ikaks1     = $_POST['ikaks1'] ?? '';
        $ikaks2     = $_POST['ikaks2'] ?? '';
        $page_mode  = 'setup';

        $errs = [];
        if (!preg_match('/^[0-9A-F]{10}$/', $admin_code))  $errs[] = 'Nieprawidłowy format AdminCode (10 znaków, cyfry i litery A–F).';
        if (!preg_match('/^\d{6}$/', $cpc1))                $errs[] = 'Kod IKA musi składać się dokładnie z 6 cyfr.';
        if (strlen($ikaks1) < 6)                            $errs[] = 'IKAKS musi mieć minimum 6 znaków.';
        elseif ($ikaks1 !== $ikaks2)                        $errs[] = 'Oba pola IKAKS muszą być identyczne.';
        if ($errs) { $error = implode(' ', $errs); goto render; }

        if (!cpc_setup_token_verify($user_id, $admin_code)) {
            $error = 'AdminCode jest nieprawidłowy lub wygasł.'; goto render;
        }
        db()->prepare("UPDATE users SET cpc_code=?, cpc_fails=0, cpc_blocked_until=NULL WHERE id=?")
            ->execute([$cpc1, $user_id]);
        kdok_ikaks_set($user_id, $ikaks1);
        ika_set_verified();
        header('Location: ' . $return_to); exit;
    }

    // ── 5. Weryfikacja PESEL ────────────────────────────────────────────────
    if ($mode === 'verify_pesel') {
        $challenge = $_SESSION['ika_pesel_ch'] ?? null;
        if (!$challenge || $challenge['uid'] !== $user_id || $challenge['expires'] < time()) {
            $error = 'Sesja wyzwania wygasła. Zacznij od nowa.'; goto render;
        }
        if ($challenge['attempts'] >= 3) {
            unset($_SESSION['ika_pesel_ch']); $error = 'Zbyt wiele błędnych prób PESEL.'; goto render;
        }

        $d1 = preg_replace('/\D/', '', $_POST['pc1'] ?? '');
        $d2 = preg_replace('/\D/', '', $_POST['pc2'] ?? '');
        $d3 = preg_replace('/\D/', '', $_POST['pc3'] ?? '');

        if ($d1 === $challenge['a'][0] && $d2 === $challenge['a'][1] && $d3 === $challenge['a'][2]) {
            unset($_SESSION['ika_pesel_ch'], $_SESSION[$_sess_key]);
            ika_set_verified();
            header('Location: ' . $return_to); exit;
        } else {
            $_SESSION['ika_pesel_ch']['attempts']++;
            $_SESSION[$_sess_key] = $_ip_fails + 1;
            $left = max(0, 3 - $_SESSION['ika_pesel_ch']['attempts']);
            $error = 'Nieprawidłowe cyfry PESEL.';
            if ($left > 0) $error .= ' Pozostało prób: ' . $left . '.';
            else { unset($_SESSION['ika_pesel_ch']); $error = 'Zbyt wiele błędnych prób.'; }
            $page_mode = 'pesel';
        }
        goto render;
    }
}

// ── GET: przejście do trybu PESEL ─────────────────────────────────────────────
if ($_GET['mode'] ?? '' === 'pesel') {
    $pesel = _ika_find_pesel($user_id);
    if ($pesel && strlen($pesel) === 11) {
        $positions = [];
        while (count($positions) < 3) {
            $p = random_int(1, 11);
            if (!in_array($p, $positions)) $positions[] = $p;
        }
        sort($positions);
        $answers = array_map(fn($p) => $pesel[$p - 1], $positions);
        $_SESSION['ika_pesel_ch'] = [
            'uid' => $user_id, 'pos' => $positions, 'a' => $answers,
            'expires' => time() + 300, 'attempts' => 0,
        ];
        $page_mode = 'pesel';
    } else {
        $error = 'Brak danych PESEL w kartotece. Wybierz inną metodę.';
    }
}

if ($_GET['mode'] ?? '' === 'email_verify') $page_mode = 'email_verify';
if ($_GET['mode'] ?? '' === 'setup')        $page_mode = 'setup';

// Auto-switch: brak kodu + aktywny token konfiguracyjny → setup
if ($page_mode === 'ika' && !$has_code && $has_setup_token) $page_mode = 'setup';

// Auto-switch: brak kodu IKA + email is primary → email
if ($page_mode === 'ika' && !$has_code && $email_is_primary) $page_mode = 'email_verify';

render:
// ── Przygotuj dane wyzwania PESEL ─────────────────────────────────────────────
$pesel_challenge = null;
if ($page_mode === 'pesel' && !empty($_SESSION['ika_pesel_ch']) && $_SESSION['ika_pesel_ch']['uid'] === $user_id) {
    $pesel_challenge = $_SESSION['ika_pesel_ch'];
}

// Czy aktywna zakładka e-mail
$active_method  = in_array($page_mode, ['email_verify'], true) ? 'email' : 'ika';
// Czy pokazywać zakładki metod
$show_method_tabs = $email_is_primary && ($has_code || !$has_code) && $page_mode !== 'pesel' && $page_mode !== 'setup';

// Czy OTP już wysłany
$otp_ready = !empty($u_fresh['ika_email_otp'])
    && !empty($u_fresh['ika_email_otp_expires'])
    && $u_fresh['ika_email_otp_expires'] > date('Y-m-d H:i:s');

// Poziom ochrony
$sec_dots = ['admin' => 4, 'editor' => 3];
$dots = $sec_dots[$role] ?? (in_array($role, ['crm_user'], true) ? 3 : 2);
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Weryfikacja IKA — <?= h($org_name) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?php branding_css($_b); ?>
<style>
*,*::before,*::after{box-sizing:border-box}
html,body{height:100%;margin:0;padding:0;background:#0f172a}

/* ── Shell ─────────────────────────────────────────────────────── */
.gate-shell{min-height:100vh;display:flex;align-items:stretch}

/* ── Lewa — ciemny panel ─────────────────────────────────────── */
.gate-left{
  width:340px;flex-shrink:0;
  background:linear-gradient(160deg,#0f172a 0%,#1e293b 60%,#1e3a5f 100%);
  display:flex;flex-direction:column;justify-content:space-between;
  padding:2rem 1.75rem;
  border-right:1px solid rgba(255,255,255,.06);
  position:relative;overflow:hidden;
}
.gate-left::before{
  content:'';position:absolute;width:380px;height:380px;border-radius:50%;
  border:65px solid rgba(255,255,255,.025);bottom:-110px;right:-120px;pointer-events:none;
}
.gate-left::after{
  content:'';position:absolute;width:200px;height:200px;border-radius:50%;
  border:40px solid rgba(37,99,235,.08);top:-50px;left:-60px;pointer-events:none;
}

/* ── Logo / tarcza ───────────────────────────────────────────── */
.gate-logo-row{display:flex;align-items:center;gap:.85rem;margin-bottom:1.75rem}
.shield-wrap{position:relative;width:44px;height:44px;flex-shrink:0}
.shield-ring{
  position:absolute;inset:-8px;border-radius:50%;
  border:2px solid rgba(37,99,235,.3);
  animation:_pulse 2.5s ease-in-out infinite;
}
@keyframes _pulse{0%,100%{transform:scale(1);opacity:.5}50%{transform:scale(1.14);opacity:1}}
.shield-icon{
  width:44px;height:44px;border-radius:12px;
  background:linear-gradient(135deg,#1e3a5f,#2563eb);
  display:flex;align-items:center;justify-content:center;
  font-size:1.3rem;color:#fff;
  box-shadow:0 0 20px rgba(37,99,235,.3);
}
.gate-org{font-size:.9rem;font-weight:700;color:rgba(255,255,255,.75);line-height:1.3}
.gate-org small{display:block;font-size:.65rem;font-weight:400;color:rgba(255,255,255,.3);letter-spacing:.05em;text-transform:uppercase}

/* ── Karta użytkownika ───────────────────────────────────────── */
.user-card{
  background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);
  border-radius:12px;padding:.75rem .9rem;margin-bottom:1.25rem;
  display:flex;align-items:center;gap:.7rem;
}
.user-avatar{
  width:38px;height:38px;border-radius:50%;
  background:var(--c,#2563eb);color:#fff;
  display:flex;align-items:center;justify-content:center;
  font-size:.82rem;font-weight:700;flex-shrink:0;
  border:2px solid rgba(255,255,255,.15);
}
.user-card-name{font-size:.86rem;font-weight:700;color:#f1f5f9;line-height:1.25}
.user-card-role{font-size:.7rem;color:rgba(255,255,255,.4);margin-top:.1rem}
.user-card-lock{margin-left:auto;color:rgba(255,255,255,.25);font-size:.85rem}

/* ── Karta celu żądania ──────────────────────────────────────── */
.dest-card{
  background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);
  border-radius:12px;padding:.8rem 1rem;margin-bottom:1rem;
}
.dest-card-label{
  font-size:.6rem;font-weight:700;letter-spacing:.09em;text-transform:uppercase;
  color:rgba(255,255,255,.3);margin-bottom:.55rem;display:flex;align-items:center;gap:.35rem;
}
.dest-icon-wrap{
  width:34px;height:34px;border-radius:9px;flex-shrink:0;
  display:flex;align-items:center;justify-content:center;font-size:1rem;
}
.dest-module{font-size:.78rem;font-weight:700;color:rgba(255,255,255,.75);line-height:1.3}
.dest-resource{font-size:.72rem;color:rgba(255,255,255,.45);margin-top:.1rem}

/* ── Poziom ochrony ──────────────────────────────────────────── */
.sec-bar{
  display:flex;align-items:center;gap:.6rem;margin-bottom:1rem;
  padding:.55rem .8rem;background:rgba(255,255,255,.04);border-radius:8px;
}
.sec-dots{display:flex;gap:3px}
.sec-dot{width:6px;height:6px;border-radius:50%}

/* ── Info box IKA ────────────────────────────────────────────── */
.gate-info{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);border-radius:9px;padding:.75rem .9rem}
.gate-info-row{display:flex;align-items:flex-start;gap:.5rem;font-size:.76rem;color:rgba(255,255,255,.45);line-height:1.5;margin-bottom:.3rem}
.gate-info-row:last-child{margin-bottom:0}
.gate-info-row i{flex-shrink:0;margin-top:.15rem}

/* ── Footer lewej ────────────────────────────────────────────── */
.gate-left-footer{color:rgba(255,255,255,.2);font-size:.69rem}
.gate-left-footer a{color:rgba(255,255,255,.3);text-decoration:none;display:inline-flex;align-items:center;gap:.3rem}
.gate-left-footer a:hover{color:rgba(255,255,255,.6)}

/* ── Prawa strona ────────────────────────────────────────────── */
.gate-right{
  flex:1;background:#F1F5F9;
  display:flex;align-items:center;justify-content:center;
  padding:2rem 1.5rem;overflow-y:auto;
}
.gate-box{
  width:100%;max-width:450px;
  background:#fff;border-radius:18px;
  border:1px solid rgba(100,116,139,.12);
  box-shadow:0 12px 48px rgba(0,0,0,.13),0 2px 8px rgba(0,0,0,.06);
  overflow:hidden;
}

/* ── Header boksa ────────────────────────────────────────────── */
.gate-box-head{
  background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 100%);
  padding:1.1rem 1.4rem;display:flex;align-items:center;gap:.8rem;
  border-bottom:1px solid rgba(37,99,235,.2);
}
.gate-box-head.mode-setup{background:linear-gradient(135deg,#78350f 0%,#b45309 100%);border-bottom-color:rgba(245,158,11,.25)}
.gate-box-head.mode-email{background:linear-gradient(135deg,#064e3b 0%,#065f46 100%);border-bottom-color:rgba(16,185,129,.2)}
.gate-box-head-icon{
  width:38px;height:38px;border-radius:9px;
  background:rgba(37,99,235,.3);
  display:flex;align-items:center;justify-content:center;
  font-size:1.1rem;color:#93c5fd;flex-shrink:0;
}
.mode-setup .gate-box-head-icon{background:rgba(245,158,11,.25);color:#fcd34d}
.mode-email .gate-box-head-icon{background:rgba(16,185,129,.25);color:#6ee7b7}
.gate-box-head-title{font-size:.97rem;font-weight:700;color:#fff}
.gate-box-head-sub{font-size:.74rem;color:rgba(255,255,255,.5);margin-top:.12rem}

.gate-box-body{padding:1.35rem 1.4rem}

/* ── Zakładki metod (IKA | E-mail) ───────────────────────────── */
.method-tabs{
  display:flex;gap:.4rem;margin-bottom:1.1rem;
  background:#f1f5f9;border-radius:10px;padding:.3rem;
}
.method-tab{
  flex:1;padding:.55rem .5rem;border:none;border-radius:8px;
  background:transparent;font-size:.83rem;font-weight:500;color:#64748b;
  cursor:pointer;transition:all .15s;display:flex;align-items:center;justify-content:center;gap:.4rem;
}
.method-tab.active{background:#fff;color:#0f172a;font-weight:700;box-shadow:0 1px 4px rgba(0,0,0,.1)}
.method-tab:hover:not(.active){color:#0f172a;background:rgba(255,255,255,.6)}

/* ── 6 boxów IKA ─────────────────────────────────────────────── */
.digit-row{display:flex;gap:.42rem;justify-content:center;margin:.85rem 0}
.digit-box{
  width:50px;height:62px;
  border:2px solid #CBD5E1;border-radius:.6rem;
  background:#F8FAFC;
  font-size:1.85rem;font-weight:700;font-family:monospace;
  text-align:center;outline:none;caret-color:transparent;
  transition:border-color .15s,box-shadow .15s,background .15s;color:#0f172a;
}
.digit-box:focus{border-color:var(--c,#2563eb);box-shadow:0 0 0 3px var(--c-ring,rgba(37,99,235,.15));background:#fff}
.digit-box.filled{background:#f0f7ff;border-color:var(--c,#2563eb)}
.digit-box.is-error{border-color:#ef4444;background:#fff5f5}

/* ── 3 boxy PESEL ────────────────────────────────────────────── */
.pesel-row{display:flex;gap:.5rem;justify-content:center;margin:.9rem 0}
.pesel-box{
  width:52px;height:64px;border:2px solid #CBD5E1;border-radius:.6rem;
  background:#F8FAFC;font-size:2rem;font-weight:700;font-family:monospace;
  text-align:center;outline:none;color:#0f172a;
  transition:border-color .15s,box-shadow .15s;
}
.pesel-box:focus{border-color:var(--c,#2563eb);box-shadow:0 0 0 3px var(--c-ring,rgba(37,99,235,.15));background:#fff}
.pesel-box.is-error{border-color:#ef4444;background:#fff5f5}
.pesel-pos{font-size:.68rem;color:#94a3b8;text-align:center;margin-top:.2rem;font-weight:600}

/* ── Przycisk główny ─────────────────────────────────────────── */
.btn-gate{
  background:linear-gradient(135deg,var(--c,#2563eb) 0%,var(--c-dark,#1d4ed8) 100%);
  color:#fff;border:none;border-radius:.6rem;padding:.7rem 1.25rem;
  font-size:.9rem;font-weight:600;width:100%;
  display:flex;align-items:center;justify-content:center;gap:.5rem;
  transition:all .15s;cursor:pointer;
}
.btn-gate:hover:not(:disabled){background:linear-gradient(135deg,var(--c-dark,#1d4ed8) 0%,#1e40af 100%);box-shadow:0 4px 14px var(--c-ring,rgba(37,99,235,.35));transform:translateY(-1px)}
.btn-gate:disabled{opacity:.45;cursor:not-allowed;transform:none}
.btn-gate.btn-email{--c:#059669;--c-dark:#047857;--c-ring:rgba(5,150,105,.3)}
.btn-gate.btn-setup{--c:#d97706;--c-dark:#b45309;--c-ring:rgba(217,119,6,.35)}

/* ── Separator ───────────────────────────────────────────────── */
.or-sep{display:flex;align-items:center;gap:.75rem;margin:.9rem 0;color:#94a3b8;font-size:.73rem}
.or-sep::before,.or-sep::after{content:'';flex:1;height:1px;background:#e2e8f0}

/* ── Linki odzyskiwania ──────────────────────────────────────── */
.recovery-links{display:flex;flex-direction:column;gap:.35rem}
.recovery-btn{
  display:flex;align-items:center;gap:.55rem;
  padding:.55rem .9rem;border:1.5px solid #e2e8f0;border-radius:.6rem;
  background:#fff;color:#374151;font-size:.79rem;text-decoration:none;
  cursor:pointer;transition:all .12s;width:100%;text-align:left;
}
.recovery-btn:hover{border-color:var(--c,#2563eb);color:var(--c,#2563eb);background:#f0f7ff;transform:translateX(2px)}
.recovery-btn i{color:#94a3b8;font-size:.88rem;flex-shrink:0;transition:color .12s}
.recovery-btn:hover i{color:var(--c,#2563eb)}

/* ── Setup form ──────────────────────────────────────────────── */
.setup-field{margin-bottom:.9rem}
.setup-label{font-size:.77rem;font-weight:600;color:#374151;margin-bottom:.3rem;display:block}
.setup-label span{font-weight:400;color:#94a3b8;font-size:.72rem;margin-left:.3rem}
.setup-input{
  width:100%;border:1.5px solid #CBD5E1;border-radius:.6rem;
  padding:.52rem .8rem;font-size:.9rem;color:#0f172a;
  background:#F8FAFC;outline:none;
  transition:border-color .15s,box-shadow .15s;
}
.setup-input:focus{border-color:#d97706;box-shadow:0 0 0 3px rgba(217,119,6,.12);background:#fff}
.setup-input.is-error{border-color:#ef4444;background:#fff5f5}
.setup-code-input{font-family:monospace;letter-spacing:.25em;font-size:1.1rem;font-weight:700;text-align:center;text-transform:uppercase}

/* ── Spinner ──────────────────────────────────────────────────── */
@keyframes _spin{to{transform:rotate(360deg)}}
.spin-icon{display:none;width:1rem;height:1rem;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:_spin .6s linear infinite}

/* ── Stan: brak kodu / blocked ───────────────────────────────── */
.state-box{text-align:center;padding:.9rem 0 .4rem}
.state-icon{font-size:2.2rem;display:block;margin-bottom:.6rem}
.state-box p{color:#64748b;font-size:.84rem;line-height:1.6;margin:0}
.state-box p+p{margin-top:.35rem}

/* ── Email 6-cyfr OTP ────────────────────────────────────────── */
.sms-code-note{font-size:.82rem;color:#475569;margin-bottom:1rem}

/* ── Mobile ───────────────────────────────────────────────────── */
@media(max-width:680px){
  .gate-shell{flex-direction:column}
  .gate-left{width:100%;padding:.9rem 1.1rem;flex-direction:row;align-items:center;gap:.6rem;min-height:auto}
  .gate-left::before,.gate-left::after{display:none}
  .gate-left>div:first-child>*:not(.gate-logo-row){display:none}
  .gate-right{padding:1.1rem .9rem;align-items:flex-start;background:#F1F5F9}
  .gate-box{box-shadow:none;border-radius:14px}
  .digit-box{width:44px;height:56px;font-size:1.5rem}
}
@media(prefers-contrast:high){
  .digit-box,.setup-input{border-width:3px;border-color:#000}
  .btn-gate{background:#000!important;border-color:#000!important}
}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{transition:none!important;animation:none!important}}
</style>
</head>
<body>
<div class="gate-shell">

<!-- ══ Lewa ═══════════════════════════════════════════════════════════════════ -->
<div class="gate-left">
  <div>

    <!-- Logo + org -->
    <div class="gate-logo-row">
      <div class="shield-wrap">
        <div class="shield-ring"></div>
        <?php if ($_b['logo_url']): ?>
        <div class="shield-icon" style="background:rgba(255,255,255,.1);padding:.25rem">
          <img src="<?= h($_b['logo_url']) ?>" alt="" style="max-height:36px;max-width:36px;object-fit:contain;filter:brightness(0) invert(1)">
        </div>
        <?php else: ?>
        <div class="shield-icon"><i class="bi bi-shield-lock-fill"></i></div>
        <?php endif; ?>
      </div>
      <div class="gate-org">
        <?= h($org_name) ?>
        <small>Punkt weryfikacji tożsamości</small>
      </div>
    </div>

    <!-- Karta użytkownika -->
    <div class="user-card">
      <div class="user-avatar"><?= h($initials) ?></div>
      <div style="flex:1;min-width:0">
        <div class="user-card-name"><?= h($display_name) ?></div>
        <div class="user-card-role"><?= h($role_label) ?></div>
      </div>
      <i class="bi bi-lock-fill user-card-lock"></i>
    </div>

    <!-- Cel żądania -->
    <div class="dest-card">
      <div class="dest-card-label">
        <i class="bi bi-arrow-right-circle"></i>
        Żądanie dostępu do
      </div>
      <div style="display:flex;align-items:flex-start;gap:.65rem">
        <div class="dest-icon-wrap"
             style="background:<?= h($dest_ctx['color']) ?>22;color:<?= h($dest_ctx['color']) ?>">
          <i class="bi <?= h($dest_ctx['icon']) ?>"></i>
        </div>
        <div>
          <div class="dest-module"><?= h($dest_ctx['module']) ?></div>
          <div class="dest-resource"><?= h($dest_ctx['resource']) ?></div>
        </div>
      </div>
    </div>

    <!-- Poziom ochrony -->
    <div class="sec-bar">
      <div class="sec-dots">
        <?php for ($i = 1; $i <= 4; $i++): ?>
        <div class="sec-dot" style="background:<?= $i <= $dots ? '#2563eb' : 'rgba(255,255,255,.12)' ?>"></div>
        <?php endfor; ?>
      </div>
      <span style="font-size:.7rem;color:rgba(255,255,255,.4)">
        Ochrona: <strong style="color:rgba(255,255,255,.6)"><?= $dots >= 4 ? 'Krytyczna' : ($dots >= 3 ? 'Wysoka' : 'Standardowa') ?></strong>
      </span>
    </div>

    <!-- Info IKA -->
    <div class="gate-info">
      <div class="gate-info-row">
        <i class="bi bi-key-fill" style="color:#93c5fd"></i>
        <span>IKA to 6-cyfrowy kod przypisany do konta — nie jest hasłem ani SMS-em.</span>
      </div>
      <?php if ($email_is_primary): ?>
      <div class="gate-info-row">
        <i class="bi bi-envelope-check-fill" style="color:#6ee7b7"></i>
        <span>Masz włączoną <strong style="color:#6ee7b7">metodę e-mail</strong> — możesz zweryfikować się kodem wysłanym na <?= h($user_email) ?>.</span>
      </div>
      <?php endif; ?>
      <div class="gate-info-row">
        <i class="bi bi-clock" style="color:#fde68a"></i>
        <span>Sesja ważna <strong style="color:#fde68a">30 minut</strong> po weryfikacji.</span>
      </div>
    </div>

  </div>

  <div class="gate-left-footer">
    <?php $back_url = match($dest_ctx['module']) {'CRM' => APP_URL . '/crm/dashboard.php', 'Dydaktyka' => APP_URL . '/karty30/index.php', default => APP_URL . '/index.php'}; ?>
    <a href="<?= h($back_url) ?>"><i class="bi bi-arrow-left-circle"></i> Anuluj i wróć</a>
    <div style="margin-top:.35rem">&copy; <?= date('Y') ?> · <?= h($org_name) ?></div>
  </div>
</div>

<!-- ══ Prawa ════════════════════════════════════════════════════════════════════ -->
<div class="gate-right">
<div class="gate-box">

  <!-- Header boksa -->
  <?php
  $head_mode_class = match(true) {
    $page_mode === 'setup'        => ' mode-setup',
    in_array($page_mode, ['email_verify'], true) && !$show_method_tabs => ' mode-email',
    default => '',
  };
  $head_icon = match(true) {
    $page_mode === 'setup'                                       => 'bi-shield-plus',
    $page_mode === 'pesel'                                       => 'bi-card-text',
    $page_mode === 'email_verify' && !$show_method_tabs          => 'bi-envelope-check',
    $dest_ctx['module'] === 'CRM'                                => 'bi-diagram-2-fill',
    $dest_ctx['module'] === 'Dydaktyka'                          => 'bi-card-checklist',
    $dest_ctx['module'] === 'Panel administratora'               => 'bi-shield-lock',
    default                                                      => 'bi-shield-check',
  };
  $head_title = match(true) {
    $page_mode === 'setup'        => 'Ustaw kody autoryzacyjne',
    $page_mode === 'pesel'        => 'Weryfikacja PESEL',
    $show_method_tabs             => 'Weryfikacja IKA',
    $page_mode === 'email_verify' => 'Kod e-mail',
    default                       => 'Weryfikacja IKA',
  };
  $head_sub = match(true) {
    $page_mode === 'setup'        => 'Jednorazowy AdminCode od administratora',
    $page_mode === 'pesel'        => 'Podaj cyfry z numeru PESEL z kartoteki',
    $show_method_tabs             => 'Wybierz metodę weryfikacji',
    $page_mode === 'email_verify' => 'Jednorazowy kod wysłany na e-mail',
    default => 'Wejście do: <strong style="color:#93c5fd">' . h($dest_ctx['module']) . ($dest_ctx['resource'] !== 'Strona chroniona' ? ' / ' . h($dest_ctx['resource']) : '') . '</strong>',
  };
  ?>
  <div class="gate-box-head<?= $head_mode_class ?>">
    <div class="gate-box-head-icon"><i class="bi <?= $head_icon ?>"></i></div>
    <div>
      <div class="gate-box-head-title"><?= $head_title ?></div>
      <div class="gate-box-head-sub"><?= $head_sub ?></div>
    </div>
  </div>

  <div class="gate-box-body">

    <?php if ($error): ?>
    <div class="alert alert-danger d-flex align-items-center gap-2 py-2 mb-3" style="border-radius:.5rem;font-size:.83rem" role="alert">
      <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
      <?= h($error) ?>
    </div>
    <?php endif; ?>
    <?php if ($info): ?>
    <div class="alert alert-success d-flex align-items-center gap-2 py-2 mb-3" style="border-radius:.5rem;font-size:.83rem" role="status">
      <i class="bi bi-check-circle-fill flex-shrink-0"></i>
      <?= h($info) ?>
    </div>
    <?php endif; ?>

    <?php if ($_ip_blocked): ?>
    <!-- ── Blokada IP ────────────────────────────── -->
    <div class="state-box">
      <span class="state-icon"><i class="bi bi-slash-circle text-danger"></i></span>
      <p>Zbyt wiele nieudanych prób z tego komputera.</p>
      <p>Zamknij przeglądarkę i spróbuj za kilka minut.</p>
    </div>

    <?php elseif ($page_mode === 'pesel' && $pesel_challenge): ?>
    <!-- ══ PESEL challenge ═══════════════════════ -->
    <?php $pos = $pesel_challenge['pos']; ?>
    <p style="font-size:.83rem;color:#475569;margin-bottom:.75rem">
      Podaj cyfry numeru PESEL na pozycjach:
    </p>
    <div style="display:flex;justify-content:center;gap:.5rem;margin-bottom:1.1rem">
      <?php foreach ($pos as $p): ?>
      <div style="background:#f0f7ff;border:2px solid #2563eb;border-radius:8px;padding:.3rem .8rem;font-size:1.1rem;font-weight:800;color:#1e3a5f;min-width:44px;text-align:center"><?= $p ?></div>
      <?php endforeach; ?>
    </div>
    <form method="post" id="peselForm" autocomplete="off">
      <input type="hidden" name="_csrf"  value="<?= csrf_token() ?>">
      <input type="hidden" name="_mode" value="verify_pesel">
      <input type="hidden" name="to"    value="<?= h($return_to) ?>">
      <input type="text"   name="_hp"   style="display:none" tabindex="-1" autocomplete="off">
      <div class="pesel-row">
        <?php foreach ($pos as $idx => $p): $n = $idx + 1; ?>
        <div style="text-align:center">
          <input type="text" class="pesel-box<?= $error ? ' is-error' : '' ?>"
                 name="pc<?= $n ?>" id="pb<?= $n ?>" maxlength="1"
                 inputmode="numeric" pattern="\d" data-idx="<?= $n ?>"
                 aria-label="Pozycja <?= $p ?>" autocomplete="off">
          <div class="pesel-pos">poz. <?= $p ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="submit" class="btn-gate" id="btnPesel" disabled>
        <span class="spin-icon" id="spinPesel"></span>
        <i class="bi bi-card-text"></i> Zweryfikuj
      </button>
    </form>
    <div style="margin-top:.8rem;text-align:center">
      <a href="?to=<?= urlencode($return_to) ?>" style="font-size:.77rem;color:#94a3b8;text-decoration:none">
        <i class="bi bi-arrow-left me-1"></i>Inna metoda
      </a>
    </div>

    <?php elseif ($page_mode === 'setup'): ?>
    <!-- ══ Setup (AdminCode) ════════════════════ -->
    <p style="font-size:.81rem;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:.55rem;padding:.55rem .8rem;margin-bottom:1rem;display:flex;gap:.45rem;align-items:flex-start">
      <i class="bi bi-info-circle-fill flex-shrink-0 mt-1" style="color:#d97706"></i>
      Wpisz <strong>AdminCode</strong> od administratora i ustaw swoje kody dostępu.
    </p>
    <form method="post" id="setupForm" autocomplete="off">
      <input type="hidden" name="_csrf"  value="<?= csrf_token() ?>">
      <input type="hidden" name="_mode" value="setup">
      <input type="hidden" name="to"    value="<?= h($return_to) ?>">
      <div class="setup-field">
        <label class="setup-label" for="admin_code">AdminCode <span>(jednorazowy, od administratora)</span></label>
        <input type="text" id="admin_code" name="admin_code"
               class="setup-input setup-code-input<?= ($page_mode === 'setup' && $error) ? ' is-error' : '' ?>"
               maxlength="10" placeholder="np. A3B7C2D8E1"
               autocomplete="off" spellcheck="false"
               oninput="this.value=this.value.toUpperCase().replace(/[^0-9A-F]/g,'')">
        <div style="font-size:.7rem;color:#94a3b8;margin-top:.25rem">10 znaków: cyfry i litery A–F</div>
      </div>
      <div class="or-sep" style="margin:.65rem 0"></div>
      <div class="setup-field">
        <label class="setup-label" for="setup_cpc">Nowy kod IKA <span>(dokładnie 6 cyfr)</span></label>
        <input type="text" id="setup_cpc" name="cpc_code"
               class="setup-input setup-code-input<?= ($page_mode === 'setup' && $error) ? ' is-error' : '' ?>"
               maxlength="6" placeholder="000000" inputmode="numeric" pattern="\d{6}" autocomplete="new-password">
      </div>
      <div class="setup-field">
        <label class="setup-label" for="ikaks1">Nowy IKAKS <span>(min. 6 znaków)</span></label>
        <input type="password" id="ikaks1" name="ikaks1"
               class="setup-input<?= ($page_mode === 'setup' && $error) ? ' is-error' : '' ?>"
               minlength="6" autocomplete="new-password" placeholder="min. 6 znaków">
      </div>
      <div class="setup-field" style="margin-bottom:1.1rem">
        <label class="setup-label" for="ikaks2">Powtórz IKAKS</label>
        <input type="password" id="ikaks2" name="ikaks2"
               class="setup-input<?= ($page_mode === 'setup' && $error) ? ' is-error' : '' ?>"
               minlength="6" autocomplete="new-password" placeholder="powtórz IKAKS">
      </div>
      <button type="submit" class="btn-gate btn-setup">
        <span class="spin-icon" id="spinSetup"></span>
        <i class="bi bi-shield-check"></i>
        Zapisz i wejdź
      </button>
    </form>
    <div style="margin-top:.7rem;text-align:center">
      <a href="?to=<?= urlencode($return_to) ?>" style="font-size:.76rem;color:#94a3b8;text-decoration:none">
        <i class="bi bi-arrow-left me-1"></i>Mam już kod IKA
      </a>
    </div>

    <?php else: ?>
    <!-- ══ Tryby IKA / Email (z zakładkami lub bez) ════════════ -->

    <?php if ($show_method_tabs): ?>
    <!-- Zakładki metod -->
    <div class="method-tabs" role="tablist" aria-label="Wybierz metodę weryfikacji">
      <?php if ($has_code || (!$has_code && !$email_is_primary)): ?>
      <button class="method-tab <?= $active_method === 'ika' ? 'active' : '' ?>"
              role="tab" aria-selected="<?= $active_method === 'ika' ? 'true' : 'false' ?>"
              id="tab-btn-ika" aria-controls="panel-ika"
              onclick="switchMethod('ika')">
        <i class="bi bi-key-fill"></i> Kod IKA
      </button>
      <?php endif; ?>
      <button class="method-tab <?= $active_method === 'email' ? 'active' : '' ?>"
              role="tab" aria-selected="<?= $active_method === 'email' ? 'true' : 'false' ?>"
              id="tab-btn-email" aria-controls="panel-email"
              onclick="switchMethod('email')">
        <i class="bi bi-envelope-fill"></i> E-mail
      </button>
    </div>
    <?php endif; ?>

    <!-- ── Panel IKA ─────────────────────────────────────── -->
    <div id="panel-ika" <?= ($show_method_tabs && $active_method !== 'ika') ? 'hidden' : '' ?>>

      <?php if ($is_blocked): ?>
      <div class="state-box">
        <span class="state-icon"><i class="bi bi-hourglass-split text-warning"></i></span>
        <p>Kod IKA zablokowany z powodu błędnych prób.</p>
        <p>Odblokowanie o: <strong><?= h(date('H:i', strtotime($u_fresh['cpc_blocked_until']))) ?></strong></p>
      </div>
      <?php elseif (!$has_code): ?>
      <div class="state-box">
        <span class="state-icon"><i class="bi bi-key text-secondary"></i></span>
        <p>Nie masz przypisanego kodu IKA.</p>
        <?php if ($has_setup_token): ?>
        <p>Masz <strong>AdminCode</strong> — możesz ustawić kody samodzielnie.</p>
        <?php else: ?>
        <p>Poproś administratora o nadanie kodu lub <strong>AdminCode</strong>.</p>
        <?php endif; ?>
      </div>
      <?php if ($has_setup_token): ?>
      <a href="?to=<?= urlencode($return_to) ?>&mode=setup" class="recovery-btn" style="border-color:#fcd34d;background:#fffbeb;color:#92400e;margin-top:.5rem">
        <i class="bi bi-shield-plus" style="color:#d97706"></i>
        <span><strong>Mam AdminCode</strong> — ustaw kody samodzielnie</span>
        <i class="bi bi-arrow-right ms-auto" style="color:#d97706;font-size:.75rem"></i>
      </a>
      <?php endif; ?>
      <?php else: ?>
      <form method="post" id="ikaForm" autocomplete="off">
        <input type="hidden" name="_csrf"  value="<?= csrf_token() ?>">
        <input type="hidden" name="_mode" value="ika">
        <input type="hidden" name="to"    value="<?= h($return_to) ?>">
        <input type="text"   name="_hp"   style="display:none" tabindex="-1" autocomplete="off">

        <p style="font-size:.78rem;color:#94a3b8;text-align:center;margin-bottom:.4rem">Wpisz 6-cyfrowy kod IKA</p>
        <div class="digit-row" id="ikaDigits" role="group" aria-label="Kod IKA — 6 cyfr">
          <?php for ($i = 1; $i <= 6; $i++): ?>
          <input type="text" class="digit-box<?= $error && $active_method === 'ika' ? ' is-error' : '' ?>"
                 maxlength="1" inputmode="numeric" pattern="\d"
                 id="d<?= $i ?>" data-idx="<?= $i ?>"
                 autocomplete="off" aria-label="Cyfra <?= $i ?>">
          <?php endfor; ?>
          <input type="hidden" name="ika_code" id="ikaCodeHidden">
        </div>
        <button type="submit" class="btn-gate" id="btnVerify" disabled>
          <span class="spin-icon" id="ikaSpinner"></span>
          <i class="bi bi-shield-check"></i>
          Zweryfikuj i wejdź
        </button>
      </form>

      <?php if (!$show_method_tabs): ?>
      <!-- Recovery (tylko gdy brak zakładek) -->
      <?php $show_recovery = $user_email || $pesel_available || $has_setup_token || $is_blocked; ?>
      <?php if ($show_recovery): ?>
      <div class="or-sep">nie pamiętasz kodu?</div>
      <div class="recovery-links">
        <?php if ($has_setup_token): ?>
        <a href="?to=<?= urlencode($return_to) ?>&mode=setup" class="recovery-btn" style="border-color:#fcd34d;background:#fffbeb;color:#92400e">
          <i class="bi bi-shield-plus" style="color:#d97706"></i>
          <strong>Mam AdminCode</strong> — ustaw nowy kod IKA
          <i class="bi bi-arrow-right ms-auto" style="color:#d97706;font-size:.75rem"></i>
        </a>
        <?php endif; ?>
        <?php if ($user_email): ?>
        <a href="?to=<?= urlencode($return_to) ?>&mode=email_verify" class="recovery-btn">
          <i class="bi bi-envelope-arrow-down-fill"></i>
          Wyślij jednorazowy kod e-mail
          <span style="margin-left:auto;font-size:.7rem;color:#94a3b8"><?= h($user_email) ?></span>
        </a>
        <?php endif; ?>
        <?php if ($pesel_available): ?>
        <a href="?to=<?= urlencode($return_to) ?>&mode=pesel" class="recovery-btn">
          <i class="bi bi-card-text"></i>
          Zweryfikuj cyframi PESEL
          <span style="margin-left:auto;font-size:.7rem;color:#94a3b8">z kartoteki</span>
        </a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <?php endif; ?>

      <?php endif; /* /has_code or not */ ?>
    </div><!-- /panel-ika -->

    <!-- ── Panel E-mail ──────────────────────────────────── -->
    <?php if ($email_is_primary || $page_mode === 'email_verify' || (!$show_method_tabs && $page_mode === 'email_verify')): ?>
    <div id="panel-email" <?= ($show_method_tabs && $active_method !== 'email') ? 'hidden' : '' ?>
         <?= (!$email_is_primary && $page_mode !== 'email_verify') ? 'hidden' : '' ?>>

      <?php if (!$otp_ready): ?>
      <!-- Wyślij OTP -->
      <p class="sms-code-note">
        Otrzymasz jednorazowy 6-cyfrowy kod na adres:<br>
        <strong><?= h($user_email) ?></strong>. Ważny 15 minut.
      </p>
      <form method="post">
        <input type="hidden" name="_csrf"  value="<?= csrf_token() ?>">
        <input type="hidden" name="_mode" value="request_email_otp">
        <input type="hidden" name="to"    value="<?= h($return_to) ?>">
        <input type="text"   name="_hp"   style="display:none" tabindex="-1" autocomplete="off">
        <button type="submit" class="btn-gate btn-email">
          <i class="bi bi-envelope-arrow-down-fill"></i>
          Wyślij kod na <?= h($user_email) ?>
        </button>
      </form>
      <?php if ($pesel_available && !$show_method_tabs): ?>
      <div class="or-sep">lub</div>
      <div class="recovery-links">
        <a href="?to=<?= urlencode($return_to) ?>&mode=pesel" class="recovery-btn">
          <i class="bi bi-card-text"></i>
          Zweryfikuj cyframi PESEL
        </a>
      </div>
      <?php endif; ?>

      <?php else: ?>
      <!-- Wpisz OTP -->
      <p class="sms-code-note">
        Wpisz 6-cyfrowy kod wysłany na <strong><?= h($user_email) ?></strong>.<br>
        Ważny 15 minut.
      </p>
      <form method="post" id="emailOtpForm" autocomplete="off">
        <input type="hidden" name="_csrf"  value="<?= csrf_token() ?>">
        <input type="hidden" name="_mode" value="verify_email_otp">
        <input type="hidden" name="to"    value="<?= h($return_to) ?>">
        <input type="text"   name="_hp"   style="display:none" tabindex="-1" autocomplete="off">
        <div class="digit-row" id="emailDigits" role="group" aria-label="Kod e-mail — 6 cyfr">
          <?php for ($i = 1; $i <= 6; $i++): ?>
          <input type="text" class="digit-box<?= $error && $active_method === 'email' ? ' is-error' : '' ?>"
                 maxlength="1" inputmode="numeric" pattern="\d"
                 id="ed<?= $i ?>" data-idx="<?= $i ?>" autocomplete="off" aria-label="Cyfra <?= $i ?>">
          <?php endfor; ?>
          <input type="hidden" name="email_otp" id="emailOtpHidden">
        </div>
        <button type="submit" class="btn-gate btn-email" id="btnEmailOtp" disabled>
          <span class="spin-icon" id="spinEmailOtp"></span>
          <i class="bi bi-envelope-check"></i>
          Zweryfikuj kod e-mail
        </button>
      </form>
      <div style="margin-top:.75rem;text-align:center">
        <form method="post" style="display:inline">
          <input type="hidden" name="_csrf"  value="<?= csrf_token() ?>">
          <input type="hidden" name="_mode" value="request_email_otp">
          <input type="hidden" name="to"    value="<?= h($return_to) ?>">
          <input type="text"   name="_hp"   style="display:none" tabindex="-1" autocomplete="off">
          <button type="submit" style="background:none;border:none;color:#94a3b8;font-size:.77rem;cursor:pointer;padding:0">
            <i class="bi bi-arrow-clockwise me-1"></i>Wyślij nowy kod
          </button>
        </form>
      </div>
      <?php endif; /* /otp_ready */ ?>

    </div><!-- /panel-email -->
    <?php endif; ?>

    <?php if ($show_method_tabs && $pesel_available): ?>
    <div class="or-sep" style="margin-top:.9rem">dodatkowe opcje</div>
    <div class="recovery-links">
      <a href="?to=<?= urlencode($return_to) ?>&mode=pesel" class="recovery-btn">
        <i class="bi bi-card-text"></i>
        Zweryfikuj cyframi PESEL
        <span style="margin-left:auto;font-size:.7rem;color:#94a3b8">z kartoteki umów</span>
      </a>
    </div>
    <?php endif; ?>

    <?php endif; /* /$page_mode cases */ ?>

  </div><!-- /gate-box-body -->
</div><!-- /gate-box -->
</div><!-- /gate-right -->
</div><!-- /gate-shell -->

<script>
(function(){
'use strict';

/* ── Przełączanie zakładek IKA / E-mail ──────────────────── */
function switchMethod(name) {
  ['ika', 'email'].forEach(function(k) {
    var p = document.getElementById('panel-' + k);
    if (p) p.hidden = true;
    var b = document.getElementById('tab-btn-' + k);
    if (b) { b.classList.toggle('active', k === name); b.setAttribute('aria-selected', k === name ? 'true' : 'false'); }
  });
  var panel = document.getElementById('panel-' + name);
  if (panel) {
    panel.hidden = false;
    var first = panel.querySelector('input:not([type=hidden]):not([style*=display])');
    if (first) setTimeout(function(){ first.focus(); }, 60);
  }
}
window.switchMethod = switchMethod;

/* ── Helper: 6-box digit group ─────────────────────────── */
function initDigitGroup(groupId, hiddenId, btnId, spinnerId) {
  var group   = document.getElementById(groupId);
  var hidden  = document.getElementById(hiddenId);
  var btn     = document.getElementById(btnId);
  var spinner = document.getElementById(spinnerId);
  if (!group || !btn) return;

  var inputs = Array.from(group.querySelectorAll('.digit-box'));

  function collect() { return inputs.map(function(i){ return i.value.replace(/\D/g,'').slice(0,1); }).join(''); }
  function sync() {
    var v = collect();
    if (hidden) hidden.value = v;
    btn.disabled = v.length < inputs.length;
    inputs.forEach(function(i){ i.classList.toggle('filled', i.value !== ''); });
  }

  inputs.forEach(function(inp, idx) {
    inp.addEventListener('input', function() {
      var d = this.value.replace(/\D/g,'').slice(0,1);
      this.value = d; sync();
      if (d && idx < inputs.length - 1) inputs[idx+1].focus();
      if (collect().length === inputs.length) setTimeout(function(){ if (!btn.disabled) btn.click(); }, 120);
    });
    inp.addEventListener('keydown', function(e) {
      if (e.key === 'Backspace' && !this.value && idx > 0) inputs[idx-1].focus();
      if (e.key === 'ArrowLeft'  && idx > 0) inputs[idx-1].focus();
      if (e.key === 'ArrowRight' && idx < inputs.length-1) inputs[idx+1].focus();
    });
    inp.addEventListener('paste', function(e) {
      e.preventDefault();
      var pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g,'');
      pasted.split('').forEach(function(ch,i){ if (idx+i < inputs.length) inputs[idx+i].value = ch; });
      inputs[Math.min(idx + pasted.length, inputs.length-1)].focus();
      sync();
    });
  });

  var form = btn.closest('form');
  if (form) form.addEventListener('submit', function() {
    if (spinner) spinner.style.display = 'inline-block';
    btn.disabled = true;
  });

  // Auto-focus pierwszego boxa jeśli panel widoczny
  var panel = group.closest('[id^=panel-]') || group;
  if (!panel.hidden) inputs[0].focus();
}

initDigitGroup('ikaDigits',   'ikaCodeHidden',  'btnVerify',   'ikaSpinner');
initDigitGroup('emailDigits', 'emailOtpHidden', 'btnEmailOtp', 'spinEmailOtp');

/* ── PESEL — 3 osobne boxy ────────────────────────────── */
(function() {
  var pInputs = document.querySelectorAll('.pesel-box');
  var btn     = document.getElementById('btnPesel');
  if (!pInputs.length || !btn) return;
  function collect(){ return Array.from(pInputs).map(function(i){return i.value.replace(/\D/g,'').slice(0,1)}).join('')}
  pInputs.forEach(function(inp, idx) {
    inp.addEventListener('input', function(){
      this.value = this.value.replace(/\D/g,'').slice(0,1);
      btn.disabled = collect().length < pInputs.length;
      if (this.value && idx < pInputs.length-1) pInputs[idx+1].focus();
      if (collect().length === pInputs.length) setTimeout(function(){if(!btn.disabled)btn.click();},120);
    });
    inp.addEventListener('keydown', function(e){
      if (e.key==='Backspace' && !this.value && idx>0) pInputs[idx-1].focus();
    });
  });
  if (pInputs.length) pInputs[0].focus();
})();

/* ── Setup form ───────────────────────────────────────── */
(function(){
  var form = document.getElementById('setupForm');
  if (!form) return;
  var ac = document.getElementById('admin_code');
  if (ac) {
    ac.addEventListener('input', function(){ this.value = this.value.toUpperCase().replace(/[^0-9A-F]/g,''); });
    ac.focus();
  }
  var btnS = form.querySelector('button[type=submit]');
  var spnS = document.getElementById('spinSetup');
  form.addEventListener('submit', function(){
    if (btnS) btnS.disabled = true;
    if (spnS) spnS.style.display = 'inline-block';
  });
})();

})();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
