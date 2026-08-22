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

    $fname       = basename($path);
    $action_type = match(true) {
        str_contains($fname, 'add')    => 'Dodawanie',
        str_contains($fname, 'edit')   => 'Edycja',
        str_contains($fname, 'view')   => 'Przeglądanie',
        str_contains($fname, 'delete') => 'Usuwanie',
        str_contains($fname, 'users')  => 'Zarządzanie użytkownikami',
        default                        => 'Dostęp',
    };

    $map = [
        '/admin/users'             => ['bi-people',              '#6d28d9', 'Panel administratora', 'Zarządzanie użytkownikami'],
        '/admin/'                  => ['bi-shield-lock',          '#6d28d9', 'Panel administratora', 'Ustawienia systemu'],
        '/karty30/'                => ['bi-person-vcard',         '#dc2626', 'Dydaktyka',            'Dydaktyka 3 — dydaktyka i konsultacje'],
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

// ── Branding (tylko dla org_name i emaila) ────────────────────────────────────
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

$active_method    = in_array($page_mode, ['email_verify'], true) ? 'email' : 'ika';
$show_method_tabs = $email_is_primary && ($has_code || !$has_code) && $page_mode !== 'pesel' && $page_mode !== 'setup';
$otp_ready        = !empty($u_fresh['ika_email_otp'])
    && !empty($u_fresh['ika_email_otp_expires'])
    && $u_fresh['ika_email_otp_expires'] > date('Y-m-d H:i:s');

require_once dirname(__DIR__) . '/includes/auth_screen.php';
auth_screen_head([
    'title'     => 'Weryfikacja IKA',
    'mode'      => 'plain',
    'width'     => 640,
    'bootstrap' => true,
    'main_id'   => 'ika-main',
]);
?>

<style>
/* ── Rozszerzenia gate na tokeny tz-* ───────────────────────────────────────── */
.digit-row{display:flex;gap:.38rem;justify-content:center;margin:.8rem 0}
.digit-box{
  width:48px;height:60px;border:2px solid var(--tz-line);border-radius:10px;
  background:#f9fafb;font-size:1.8rem;font-weight:800;font-family:monospace;
  text-align:center;outline:none;caret-color:transparent;
  transition:border-color .15s,box-shadow .15s,background .15s;color:#0f172a;
}
.digit-box:focus{border-color:var(--tz);box-shadow:0 0 0 3px rgba(30,109,255,.18);background:#fff}
.digit-box.filled{background:var(--tz-50);border-color:var(--tz)}
.digit-box.is-error{border-color:#ef4444;background:#fef2f2}
.pesel-row{display:flex;gap:.45rem;justify-content:center;margin:.9rem 0}
.pesel-box{
  width:52px;height:64px;border:2px solid var(--tz-line);border-radius:10px;
  background:#f9fafb;font-size:2rem;font-weight:800;font-family:monospace;
  text-align:center;outline:none;color:#0f172a;
  transition:border-color .15s,box-shadow .15s;
}
.pesel-box:focus{border-color:var(--tz);box-shadow:0 0 0 3px rgba(30,109,255,.18);background:#fff}
.pesel-box.is-error{border-color:#ef4444;background:#fef2f2}
.pesel-pos{font-size:.66rem;color:var(--tz-muted);text-align:center;margin-top:.2rem;font-weight:600}
.or-sep{display:flex;align-items:center;gap:.7rem;margin:.9rem 0;color:var(--tz-muted);font-size:.75rem}
.or-sep::before,.or-sep::after{content:'';flex:1;height:1px;background:var(--tz-line)}
.recovery-links{display:flex;flex-direction:column;gap:.3rem}
.recovery-btn{
  display:flex;align-items:center;gap:.5rem;padding:.55rem .9rem;
  border:1.5px solid var(--tz-line);border-radius:9px;background:#fff;
  color:#374151;font-size:.85rem;text-decoration:none;
  cursor:pointer;transition:all .12s;width:100%;text-align:left;
}
.recovery-btn:hover{border-color:var(--tz);color:var(--tz-strong);background:var(--tz-50)}
.recovery-btn i{color:var(--tz-muted);flex-shrink:0;transition:color .12s}
.recovery-btn:hover i{color:var(--tz)}
.state-box{text-align:center;padding:1.5rem 0}
.state-icon{font-size:2.5rem;display:block;margin-bottom:.6rem}
.state-box p{color:var(--tz-muted);font-size:.88rem;line-height:1.6;margin:0}
.state-box p+p{margin-top:.3rem}
.state-box strong{color:#374151}
.tz-btn--wide{width:100%;justify-content:center}
.tz-btn--email{background:#047857;border:none}
.tz-btn--email:hover{background:#065f46;color:#fff}
.tz-btn--setup{background:#b45309;border:none}
.tz-btn--setup:hover{background:#92400e;color:#fff}
@keyframes _spin{to{transform:rotate(360deg)}}
.spin-icon{display:none;width:1rem;height:1rem;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:_spin .6s linear infinite;flex-shrink:0}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{transition:none!important;animation:none!important}}
@media(max-width:480px){.digit-box{width:42px;height:54px;font-size:1.55rem}.pesel-box{width:48px;height:60px}}
</style>

<!-- ── Nagłówek strony ───────────────────────────────────────────────────────── -->
<div class="tz-h">
  <h1><i class="bi bi-shield-check me-2" style="color:#1E6DFF" aria-hidden="true"></i>Weryfikacja IKA</h1>
  <p>Potwierdzenie tożsamości wymagane przez system</p>
</div>

<!-- ── Kontekst: cel + użytkownik ──────────────────────────────────────────── -->
<div class="tz-card mb-3">
  <div class="tz-card__bd" style="padding:.85rem 1.25rem">
    <div class="d-flex align-items-center gap-3 flex-wrap">
      <div style="width:42px;height:42px;border-radius:11px;flex-shrink:0;
                  background:<?= h($dest_ctx['color']) ?>1a;color:<?= h($dest_ctx['color']) ?>;
                  display:flex;align-items:center;justify-content:center;font-size:1.25rem"
           aria-hidden="true">
        <i class="bi <?= h($dest_ctx['icon']) ?>"></i>
      </div>
      <div class="flex-grow-1">
        <div class="fw-bold" style="color:var(--tz-strong)"><?= h($dest_ctx['module']) ?></div>
        <div class="text-muted small"><?= h($dest_ctx['resource']) ?></div>
      </div>
      <div class="d-flex align-items-center gap-2 border rounded-pill px-3 py-1"
           style="background:var(--tz-canvas);border-color:var(--tz-line)!important">
        <span style="width:26px;height:26px;border-radius:50%;background:var(--tz);color:#fff;
                     display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.62rem;flex-shrink:0"
              aria-hidden="true"><?= h($initials) ?></span>
        <span style="font-size:.82rem;font-weight:600;color:#374151;max-width:130px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= h($display_name) ?></span>
      </div>
    </div>
  </div>
</div>

<!-- ── Zakładki metod (gdy aktywna) ─────────────────────────────────────────── -->
<?php if ($show_method_tabs): ?>
<nav class="tz-subnav" aria-label="Metoda weryfikacji">
  <span class="seg" role="tablist">
    <?php if ($has_code || (!$has_code && !$email_is_primary)): ?>
    <a href="#panel-ika"
       id="tab-btn-ika" role="tab"
       aria-selected="<?= $active_method === 'ika' ? 'true' : 'false' ?>"
       aria-controls="panel-ika"
       class="<?= $active_method === 'ika' ? 'on' : '' ?>"
       tabindex="<?= $active_method === 'ika' ? '0' : '-1' ?>"
       onclick="switchMethod('ika'); return false">
      <i class="bi bi-key-fill" aria-hidden="true"></i>Kod IKA
    </a>
    <?php endif; ?>
    <a href="#panel-email"
       id="tab-btn-email" role="tab"
       aria-selected="<?= $active_method === 'email' ? 'true' : 'false' ?>"
       aria-controls="panel-email"
       class="<?= $active_method === 'email' ? 'on' : '' ?>"
       tabindex="<?= $active_method === 'email' ? '0' : '-1' ?>"
       onclick="switchMethod('email'); return false">
      <i class="bi bi-envelope-fill" aria-hidden="true"></i>E-mail
    </a>
  </span>
</nav>
<?php endif; ?>

<!-- ── Karta weryfikacji ─────────────────────────────────────────────────────── -->
<?php
$head_icon = match(true) {
    $page_mode === 'setup'                              => 'bi-shield-plus',
    $page_mode === 'pesel'                              => 'bi-card-text',
    $page_mode === 'email_verify' && !$show_method_tabs => 'bi-envelope-check',
    default                                             => 'bi-shield-check',
};
$head_title = match(true) {
    $page_mode === 'setup'        => 'Ustaw kody autoryzacyjne',
    $page_mode === 'pesel'        => 'Weryfikacja PESEL',
    $show_method_tabs             => 'Weryfikacja dwuetapowa',
    $page_mode === 'email_verify' => 'Kod e-mail',
    default                       => 'Weryfikacja IKA',
};
?>
<section class="tz-card" id="gate-main">
  <div class="tz-card__hd">
    <i class="bi <?= $head_icon ?>" aria-hidden="true"></i>
    <span><?= h($head_title) ?></span>
    <?php if (!$show_method_tabs && $page_mode === 'ika'): ?>
    <span class="lbl-en" style="display:inline;font-size:.72rem;color:var(--tz-muted);margin-left:.4rem">Indywidualny Kod Autoryzacyjny</span>
    <?php endif; ?>
  </div>
  <div class="tz-card__bd">

    <?php if ($error): ?>
    <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
      <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1" aria-hidden="true"></i>
      <span><?= h($error) ?></span>
    </div>
    <?php endif; ?>
    <?php if ($info): ?>
    <div class="alert alert-success d-flex align-items-start gap-2" role="status">
      <i class="bi bi-check-circle-fill flex-shrink-0 mt-1" aria-hidden="true"></i>
      <span><?= h($info) ?></span>
    </div>
    <?php endif; ?>

    <?php if ($_ip_blocked): ?>
    <!-- ── Blokada IP ───────────────────────────────────── -->
    <div class="state-box">
      <span class="state-icon"><i class="bi bi-slash-circle text-danger"></i></span>
      <p>Zbyt wiele nieudanych prób z tego komputera.</p>
      <p>Zamknij przeglądarkę i spróbuj za kilka minut.</p>
    </div>

    <?php elseif ($page_mode === 'pesel' && $pesel_challenge): ?>
    <!-- ── PESEL challenge ──────────────────────────────── -->
    <?php $pos = $pesel_challenge['pos']; ?>
    <p class="text-muted small mb-2">Podaj cyfry numeru PESEL na pozycjach:</p>
    <div class="d-flex justify-content-center gap-2 mb-3">
      <?php foreach ($pos as $p): ?>
      <div class="tz-badge tz-badge--ok" style="font-size:1rem;font-weight:800;padding:.4rem .9rem;min-width:44px;justify-content:center"><?= $p ?></div>
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
      <button type="submit" class="tz-btn tz-btn--wide" id="btnPesel" disabled>
        <span class="spin-icon" id="spinPesel"></span>
        <i class="bi bi-card-text" aria-hidden="true"></i> Zweryfikuj
      </button>
    </form>
    <div class="mt-3 text-center">
      <a href="?to=<?= urlencode($return_to) ?>" class="text-muted" style="font-size:.82rem;text-decoration:none">
        <i class="bi bi-arrow-left me-1"></i>Inna metoda
      </a>
    </div>

    <?php elseif ($page_mode === 'setup'): ?>
    <!-- ── Setup (AdminCode) ────────────────────────────── -->
    <div class="alert alert-warning d-flex align-items-start gap-2" role="note">
      <i class="bi bi-info-circle-fill flex-shrink-0 mt-1" aria-hidden="true"></i>
      <span>Wpisz <strong>AdminCode</strong> od administratora i ustaw swoje kody dostępu.</span>
    </div>
    <form method="post" id="setupForm" autocomplete="off" style="max-width:420px">
      <input type="hidden" name="_csrf"  value="<?= csrf_token() ?>">
      <input type="hidden" name="_mode" value="setup">
      <input type="hidden" name="to"    value="<?= h($return_to) ?>">
      <div class="mb-3">
        <label class="form-label fw-semibold" for="admin_code">
          AdminCode
          <span class="text-muted fw-normal" style="font-size:.8rem">(jednorazowy, od administratora)</span>
        </label>
        <input type="text" id="admin_code" name="admin_code"
               class="form-control<?= ($page_mode === 'setup' && $error) ? ' is-invalid' : '' ?>"
               style="font-family:monospace;letter-spacing:.25em;font-size:1.05rem;text-align:center;text-transform:uppercase"
               maxlength="10" placeholder="np. A3B7C2D8E1"
               autocomplete="off" spellcheck="false"
               oninput="this.value=this.value.toUpperCase().replace(/[^0-9A-F]/g,'')">
        <div class="form-text">10 znaków: cyfry i litery A–F</div>
      </div>
      <hr class="my-3">
      <div class="mb-3">
        <label class="form-label fw-semibold" for="setup_cpc">
          Nowy kod IKA
          <span class="text-muted fw-normal" style="font-size:.8rem">(dokładnie 6 cyfr)</span>
        </label>
        <input type="text" id="setup_cpc" name="cpc_code"
               class="form-control<?= ($page_mode === 'setup' && $error) ? ' is-invalid' : '' ?>"
               style="font-family:monospace;letter-spacing:.25em;text-align:center;font-size:1.1rem"
               maxlength="6" placeholder="000000" inputmode="numeric" pattern="\d{6}" autocomplete="new-password">
      </div>
      <div class="mb-3">
        <label class="form-label fw-semibold" for="ikaks1">
          Nowy IKAKS
          <span class="text-muted fw-normal" style="font-size:.8rem">(min. 6 znaków)</span>
        </label>
        <input type="password" id="ikaks1" name="ikaks1"
               class="form-control<?= ($page_mode === 'setup' && $error) ? ' is-invalid' : '' ?>"
               minlength="6" autocomplete="new-password" placeholder="min. 6 znaków">
      </div>
      <div class="mb-4">
        <label class="form-label fw-semibold" for="ikaks2">Powtórz IKAKS</label>
        <input type="password" id="ikaks2" name="ikaks2"
               class="form-control<?= ($page_mode === 'setup' && $error) ? ' is-invalid' : '' ?>"
               minlength="6" autocomplete="new-password" placeholder="powtórz IKAKS">
      </div>
      <button type="submit" class="tz-btn tz-btn--setup tz-btn--wide">
        <span class="spin-icon" id="spinSetup"></span>
        <i class="bi bi-shield-check" aria-hidden="true"></i>
        Zapisz i wejdź
      </button>
    </form>
    <div class="mt-3">
      <a href="?to=<?= urlencode($return_to) ?>" class="text-muted" style="font-size:.82rem;text-decoration:none">
        <i class="bi bi-arrow-left me-1"></i>Mam już kod IKA
      </a>
    </div>

    <?php else: ?>
    <!-- ── Panele IKA / E-mail ──────────────────────────── -->

    <!-- Panel IKA -->
    <div id="panel-ika"
         <?= $show_method_tabs ? 'role="tabpanel" aria-labelledby="tab-btn-ika" tabindex="0"' : '' ?>
         <?= ($show_method_tabs && $active_method !== 'ika') ? 'hidden' : '' ?>>

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
      <a href="?to=<?= urlencode($return_to) ?>&mode=setup"
         class="recovery-btn" style="border-color:#fde68a;background:#fffbeb;color:#92400e">
        <i class="bi bi-shield-plus" style="color:#d97706"></i>
        <strong>Mam AdminCode</strong> — ustaw kody samodzielnie
        <i class="bi bi-arrow-right ms-auto" style="color:#d97706;font-size:.8rem"></i>
      </a>
      <?php endif; ?>

      <?php else: ?>
      <form method="post" id="ikaForm" autocomplete="off">
        <input type="hidden" name="_csrf"  value="<?= csrf_token() ?>">
        <input type="hidden" name="_mode" value="ika">
        <input type="hidden" name="to"    value="<?= h($return_to) ?>">
        <input type="text"   name="_hp"   style="display:none" tabindex="-1" autocomplete="off">
        <p class="text-muted small text-center mb-1">Wpisz 6-cyfrowy kod IKA</p>
        <div class="digit-row" id="ikaDigits" role="group" aria-label="Kod IKA — 6 cyfr">
          <?php for ($i = 1; $i <= 6; $i++): ?>
          <input type="text" class="digit-box<?= $error && $active_method === 'ika' ? ' is-error' : '' ?>"
                 maxlength="1" inputmode="numeric" pattern="\d"
                 id="d<?= $i ?>" data-idx="<?= $i ?>"
                 autocomplete="off" aria-label="Cyfra <?= $i ?>">
          <?php endfor; ?>
          <input type="hidden" name="ika_code" id="ikaCodeHidden">
        </div>
        <button type="submit" class="tz-btn tz-btn--wide" id="btnVerify" disabled>
          <span class="spin-icon" id="ikaSpinner"></span>
          <i class="bi bi-shield-check" aria-hidden="true"></i>
          Zweryfikuj i wejdź
        </button>
      </form>

      <?php if (!$show_method_tabs):
        $show_recovery = $user_email || $pesel_available || $has_setup_token || $is_blocked;
        if ($show_recovery): ?>
      <div class="or-sep">nie pamiętasz kodu?</div>
      <div class="recovery-links">
        <?php if ($has_setup_token): ?>
        <a href="?to=<?= urlencode($return_to) ?>&mode=setup"
           class="recovery-btn" style="border-color:#fde68a;background:#fffbeb;color:#92400e">
          <i class="bi bi-shield-plus" style="color:#d97706"></i>
          <strong>Mam AdminCode</strong> — ustaw nowy kod IKA
          <i class="bi bi-arrow-right ms-auto" style="color:#d97706;font-size:.8rem"></i>
        </a>
        <?php endif; ?>
        <?php if ($user_email): ?>
        <a href="?to=<?= urlencode($return_to) ?>&mode=email_verify" class="recovery-btn">
          <i class="bi bi-envelope-arrow-down-fill"></i>
          Wyślij jednorazowy kod e-mail
          <span class="ms-auto text-muted" style="font-size:.75rem"><?= h($user_email) ?></span>
        </a>
        <?php endif; ?>
        <?php if ($pesel_available): ?>
        <a href="?to=<?= urlencode($return_to) ?>&mode=pesel" class="recovery-btn">
          <i class="bi bi-card-text"></i>
          Zweryfikuj cyframi PESEL
          <span class="ms-auto text-muted" style="font-size:.75rem">z kartoteki</span>
        </a>
        <?php endif; ?>
      </div>
      <?php endif; endif; ?>

      <?php endif; ?>
    </div><!-- /panel-ika -->

    <!-- Panel E-mail -->
    <?php if ($email_is_primary || $page_mode === 'email_verify'): ?>
    <div id="panel-email"
         <?= $show_method_tabs ? 'role="tabpanel" aria-labelledby="tab-btn-email" tabindex="0"' : '' ?>
         <?= ($show_method_tabs && $active_method !== 'email') ? 'hidden' : '' ?>
         <?= (!$email_is_primary && $page_mode !== 'email_verify') ? 'hidden' : '' ?>>

      <?php if (!$otp_ready): ?>
      <p class="text-muted small mb-3">
        Otrzymasz jednorazowy 6-cyfrowy kod na adres:<br>
        <strong><?= h($user_email) ?></strong>. Ważny 15 minut.
      </p>
      <form method="post">
        <input type="hidden" name="_csrf"  value="<?= csrf_token() ?>">
        <input type="hidden" name="_mode" value="request_email_otp">
        <input type="hidden" name="to"    value="<?= h($return_to) ?>">
        <input type="text"   name="_hp"   style="display:none" tabindex="-1" autocomplete="off">
        <button type="submit" class="tz-btn tz-btn--email tz-btn--wide">
          <i class="bi bi-envelope-arrow-down-fill" aria-hidden="true"></i>
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
      <p class="text-muted small mb-3">
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
        <button type="submit" class="tz-btn tz-btn--email tz-btn--wide" id="btnEmailOtp" disabled>
          <span class="spin-icon" id="spinEmailOtp"></span>
          <i class="bi bi-envelope-check" aria-hidden="true"></i>
          Zweryfikuj kod e-mail
        </button>
      </form>
      <div class="mt-3">
        <form method="post" style="display:inline">
          <input type="hidden" name="_csrf"  value="<?= csrf_token() ?>">
          <input type="hidden" name="_mode" value="request_email_otp">
          <input type="hidden" name="to"    value="<?= h($return_to) ?>">
          <input type="text"   name="_hp"   style="display:none" tabindex="-1" autocomplete="off">
          <button type="submit"
                  style="background:none;border:none;color:var(--tz-muted);font-size:.82rem;cursor:pointer;padding:0;display:inline-flex;align-items:center;gap:.3rem">
            <i class="bi bi-arrow-clockwise"></i>Wyślij nowy kod
          </button>
        </form>
      </div>
      <?php endif; ?>

    </div><!-- /panel-email -->
    <?php endif; ?>

    <?php if ($show_method_tabs && $pesel_available): ?>
    <div class="or-sep mt-3">dodatkowe opcje</div>
    <div class="recovery-links">
      <a href="?to=<?= urlencode($return_to) ?>&mode=pesel" class="recovery-btn">
        <i class="bi bi-card-text"></i>
        Zweryfikuj cyframi PESEL
        <span class="ms-auto text-muted" style="font-size:.75rem">z kartoteki umów</span>
      </a>
    </div>
    <?php endif; ?>

    <?php endif; /* page_mode cases */ ?>

  </div><!-- /tz-card__bd -->
</section>

<!-- ── Linki dolne ──────────────────────────────────────────────────────────── -->
<?php $back_url = match($dest_ctx['module']) {
    'CRM'      => APP_URL . '/crm/dashboard.php',
    'Dydaktyka'=> APP_URL . '/karty30/index.php',
    default    => APP_URL . '/index.php',
}; ?>
<div class="d-flex justify-content-between align-items-center mt-1 mb-2" style="font-size:.8rem;color:var(--tz-muted)">
  <a href="<?= h($back_url) ?>" class="text-muted"
     style="text-decoration:none;display:inline-flex;align-items:center;gap:.3rem">
    <i class="bi bi-arrow-left" aria-hidden="true"></i> Anuluj i wróć
  </a>
  <a href="<?= APP_URL ?>/tozsamosc/index.php"
     style="text-decoration:none;display:inline-flex;align-items:center;gap:.3rem;color:var(--tz-strong);font-weight:600">
    <i class="bi bi-person-vcard" aria-hidden="true"></i> System Tożsamości
  </a>
</div>

<script>
(function(){
'use strict';

/* ── Przełączanie zakładek IKA / E-mail ──────────────────── */
function switchMethod(name) {
  ['ika', 'email'].forEach(function(k) {
    var p = document.getElementById('panel-' + k);
    if (p) p.hidden = true;
    var b = document.getElementById('tab-btn-' + k);
    if (b) {
      b.classList.toggle('on', k === name);
      b.setAttribute('aria-selected', k === name ? 'true' : 'false');
      b.tabIndex = k === name ? 0 : -1;
    }
  });
  var panel = document.getElementById('panel-' + name);
  if (panel) {
    panel.hidden = false;
    var first = panel.querySelector('input:not([type=hidden]):not([style*=display])');
    if (first) setTimeout(function(){ first.focus(); }, 60);
  }
}
window.switchMethod = switchMethod;

/* Nawigacja klawiaturą po zakładkach metod (strzałki / Home / End) */
(function(){
  var tablist = document.querySelector('.tz-subnav .seg[role=tablist]');
  if (!tablist) return;
  var tabs = Array.from(tablist.querySelectorAll('[role=tab]'));
  tablist.addEventListener('keydown', function(e){
    var i = tabs.indexOf(document.activeElement);
    if (i < 0) return;
    var n = null;
    if (e.key === 'ArrowRight' || e.key === 'ArrowDown') n = (i + 1) % tabs.length;
    else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') n = (i - 1 + tabs.length) % tabs.length;
    else if (e.key === 'Home') n = 0;
    else if (e.key === 'End') n = tabs.length - 1;
    if (n === null) return;
    e.preventDefault();
    tabs[n].focus();
    tabs[n].click();
  });
})();

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

<?php
auth_screen_foot([
    'links' => [
        ['url' => APP_URL . '/portal.php',                  'label' => 'Wróć do portalu',  'icon' => 'bi-arrow-left'],
        ['url' => APP_URL . '/auth/report_login_issue.php', 'label' => 'Problem z IKA',    'icon' => 'bi-life-preserver'],
    ],
]);
