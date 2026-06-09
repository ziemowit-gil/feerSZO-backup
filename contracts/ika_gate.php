<?php
/**
 * contracts/ika_gate.php — Brama IKA (Indywidualny Kod Autoryzacyjny).
 *
 * Tryby weryfikacji:
 *   1. Kod IKA (6 cyfr) — domyślny
 *   2. Email OTP — odzyskiwanie kodem jednorazowym
 *   3. PESEL challenge — alternatywa gdy user ma kartotekę z PESEL-em
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
    if ($host_ok && $path_ok)                             $return_to = $raw_to;
    elseif (str_starts_with($raw_to, APP_URL . '/'))      $return_to = $raw_to;
}
if ($return_to === '') {
    $return_to = is_crm_only() ? APP_URL . '/crm/dashboard.php' : APP_URL . '/index.php';
}

// ── Kontekst modułu ───────────────────────────────────────────────────────────
$_ika_context = 'system';
$_ika_module  = 'System';
if (str_contains($return_to, '/karty30/')) {
    $_ika_context = 'karty30';
    $_ika_module  = 'TyfloKonsultacje — Karty 30';
} elseif (str_contains($return_to, '/crm/')) {
    $_ika_context = 'crm';
    $_ika_module  = 'CRM';
} else {
    foreach (['/wolontariat/'=>'Umowy wolontariackie','/zlecenie/'=>'Umowy zlecenie',
              '/dzielo/'=>'Umowy o dzieło','/praca/'=>'Umowy o pracę',
              '/uslugi/'=>'Umowy usługowe','/inne/'=>'Inne umowy'] as $seg => $lbl) {
        if (str_contains($return_to, $seg)) { $_ika_module = $lbl; break; }
    }
}

// ── Dane IKA z DB ────────────────────────────────────────────────────────────
$u_fresh    = db_one("SELECT cpc_code, cpc_fails, cpc_blocked_until, email,
                             ika_email_otp, ika_email_otp_expires
                      FROM users WHERE id=?", [$user_id]);
$has_code   = !empty($u_fresh['cpc_code']);
$is_blocked = !empty($u_fresh['cpc_blocked_until']) && $u_fresh['cpc_blocked_until'] > date('Y-m-d H:i:s');
$user_email = trim($u_fresh['email'] ?? '');

// ── Setup token (AdminCode do self-service ustawiania IKA + IKAKS) ────────────
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
        ['umowy_wolontariat', 'email',      $email],
        ['umowy_wolontariat', 'm365_login', $email],
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
$_sess_key = 'ika_ip_fails_' . md5($user_ip);
$_ip_fails = (int)($_SESSION[$_sess_key] ?? 0);
$_ip_blocked = $_ip_fails >= 10; // Max 10 błędów z jednego IP w sesji

// ── Branding ──────────────────────────────────────────────────────────────────
$_b      = branding_load();
$org_name = $_b['org_name'] ?: (defined('ORG_NAME') ? ORG_NAME : 'System');

// ── Display name / initials ───────────────────────────────────────────────────
$display_name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
if ($display_name === '') $display_name = $user['name'] ?? $user['email'] ?? '';
$initials = '';
foreach (preg_split('/\s+/', trim($display_name)) as $w) $initials .= mb_strtoupper(mb_substr($w,0,1,'UTF-8'),'UTF-8');
$initials = mb_substr($initials,0,2,'UTF-8') ?: '?';
$role_label = match(true) {
    $role === 'admin'    => 'Administrator',
    $role === 'editor'   => 'Edytor',
    $role === 'crm_user' => 'Użytkownik CRM',
    $_ika_k30            => 'Doradca TyfloKonsultacje',
    default              => ucfirst($role),
};

// ═════════════════════════════════════════════════════════════════════════════
// OBSŁUGA TRYBÓW
// ═════════════════════════════════════════════════════════════════════════════

$page_mode = 'ika';       // ika | email_sent | email_verify | pesel
$error     = '';
$info      = '';

// ─── POST ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_ok = hash_equals($_SESSION['csrf'] ?? '', $_POST['_csrf'] ?? '');
    if (!$csrf_ok) { $error = 'Nieprawidłowy token CSRF. Odśwież stronę.'; goto render; }

    // Honeypot
    if (!empty($_POST['_hp'])) { sleep(2); $error = 'Weryfikacja nieudana.'; goto render; }

    // IP rate limit
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
        if (!$user_email) { $error = 'Brak adresu e-mail na koncie. Skontaktuj się z administratorem.'; goto render; }

        // Throttle: max 1 OTP co 2 minuty
        if (!empty($u_fresh['ika_email_otp_expires'])) {
            $throttle_until = date('Y-m-d H:i:s', strtotime($u_fresh['ika_email_otp_expires']) - 780); // 13 min ago = 2 min throttle
            if ($throttle_until > date('Y-m-d H:i:s')) {
                $error = 'Kod e-mail został już wysłany. Poczekaj chwilę przed ponowną próbą.';
                $page_mode = 'email_verify';
                goto render;
            }
        }

        $otp      = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $otp_hash = password_hash($otp, PASSWORD_BCRYPT);
        $expires  = date('Y-m-d H:i:s', strtotime('+15 minutes'));

        db()->prepare("UPDATE users SET ika_email_otp=?, ika_email_otp_expires=? WHERE id=?")
            ->execute([$otp_hash, $expires, $user_id]);

        // Wyślij e-mail
        $subject  = 'Kod weryfikacyjny IKA — ' . $org_name;
        $mod_safe = htmlspecialchars($_ika_module);
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
  <p style="font-size:.88em;color:#6c757d">Kod jest ważny przez <strong>15 minut</strong>. Nie udostępniaj go nikomu.<br>
  Jeśli to nie Ty, zmień hasło i skontaktuj się z administratorem.</p>
</div>
</body></html>
HTML;
        try {
            require_once dirname(__DIR__) . '/includes/mail_queue.php';
            require_once dirname(__DIR__) . '/includes/approval.php';
            mail_queue_add($user_email, $display_name, $subject, $mail_body, '', 'ika_otp', $user_id, '', true);
        } catch (\Throwable $e) {}

        $info      = 'Kod jednorazowy został wysłany na adres ' . $user_email . '. Sprawdź skrzynkę (w tym spam).';
        $page_mode = 'email_verify';
        goto render;
    }

    // ── 3. Weryfikacja email OTP ────────────────────────────────────────────
    if ($mode === 'verify_email_otp') {
        $otp_input = preg_replace('/\D/', '', $_POST['email_otp'] ?? '');

        if (empty($u_fresh['ika_email_otp']) || empty($u_fresh['ika_email_otp_expires'])) {
            $error = 'Brak aktywnego kodu e-mail. Wygeneruj nowy.';
            $page_mode = 'email_verify';
            goto render;
        }
        if ($u_fresh['ika_email_otp_expires'] < date('Y-m-d H:i:s')) {
            $error = 'Kod wygasł. Wygeneruj nowy.';
            $page_mode = 'email_verify';
            goto render;
        }
        if (!password_verify($otp_input, $u_fresh['ika_email_otp'])) {
            $_SESSION[$_sess_key] = $_ip_fails + 1;
            $error = 'Nieprawidłowy kod.';
            $page_mode = 'email_verify';
            goto render;
        }

        // OK — wyczyść OTP, ustaw sesję IKA
        db()->prepare("UPDATE users SET ika_email_otp=NULL, ika_email_otp_expires=NULL WHERE id=?")
            ->execute([$user_id]);
        unset($_SESSION[$_sess_key]);
        ika_set_verified();
        header('Location: ' . $return_to); exit;
    }

    // ── 4. Ustawienie kodów IKA + IKAKS (AdminCode) ────────────────────────────
    if ($mode === 'setup') {
        $admin_code = strtoupper(trim($_POST['admin_code'] ?? ''));
        $cpc1       = trim($_POST['cpc_code'] ?? '');
        $ikaks1     = $_POST['ikaks1'] ?? '';
        $ikaks2     = $_POST['ikaks2'] ?? '';
        $page_mode  = 'setup';

        $errs = [];
        if (!preg_match('/^[0-9A-F]{10}$/', $admin_code)) {
            $errs[] = 'Nieprawidłowy format AdminCode (10 znaków, cyfry i litery A–F).';
        }
        if (!preg_match('/^\d{6}$/', $cpc1)) {
            $errs[] = 'Kod IKA musi składać się dokładnie z 6 cyfr.';
        }
        if (strlen($ikaks1) < 6) {
            $errs[] = 'IKAKS musi mieć minimum 6 znaków.';
        } elseif ($ikaks1 !== $ikaks2) {
            $errs[] = 'Oba pola IKAKS muszą być identyczne.';
        }
        if ($errs) {
            $error = implode(' ', $errs);
            goto render;
        }
        if (!cpc_setup_token_verify($user_id, $admin_code)) {
            $error = 'AdminCode jest nieprawidłowy lub wygasł. Poproś administratora o wygenerowanie nowego.';
            goto render;
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
            $error = 'Sesja wyzwania wygasła. Zacznij od nowa.';
            goto render;
        }
        if ($challenge['attempts'] >= 3) {
            unset($_SESSION['ika_pesel_ch']);
            $error = 'Zbyt wiele błędnych prób weryfikacji PESEL.';
            goto render;
        }

        $d1 = preg_replace('/\D/', '', $_POST['pc1'] ?? '');
        $d2 = preg_replace('/\D/', '', $_POST['pc2'] ?? '');
        $d3 = preg_replace('/\D/', '', $_POST['pc3'] ?? '');

        if ($d1 === $challenge['a'][0] && $d2 === $challenge['a'][1] && $d3 === $challenge['a'][2]) {
            unset($_SESSION['ika_pesel_ch']);
            unset($_SESSION[$_sess_key]);
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
        // Losuj 3 pozycje (1-indexed)
        $positions = [];
        while (count($positions) < 3) {
            $p = random_int(1, 11);
            if (!in_array($p, $positions)) $positions[] = $p;
        }
        sort($positions);
        $answers = array_map(fn($p) => $pesel[$p - 1], $positions);
        $_SESSION['ika_pesel_ch'] = [
            'uid'      => $user_id,
            'pos'      => $positions,
            'a'        => $answers,
            'expires'  => time() + 300,
            'attempts' => 0,
        ];
        $page_mode = 'pesel';
    } else {
        $error = 'Brak danych PESEL w kartotece. Wybierz inną metodę.';
    }
}

// ── GET: tryb email_verify ────────────────────────────────────────────────────
if ($_GET['mode'] ?? '' === 'email_verify') {
    $page_mode = 'email_verify';
}

// ── GET: tryb setup ───────────────────────────────────────────────────────────
if ($_GET['mode'] ?? '' === 'setup') {
    $page_mode = 'setup';
}

// Auto-switch: brak kodu + aktywny token konfiguracyjny → setup mode
if ($page_mode === 'ika' && !$has_code && $has_setup_token) {
    $page_mode = 'setup';
}

render:
// ── Przygotuj dane wyzwania PESEL do wyświetlenia ─────────────────────────────
$pesel_challenge = null;
if ($page_mode === 'pesel' && !empty($_SESSION['ika_pesel_ch']) && $_SESSION['ika_pesel_ch']['uid'] === $user_id) {
    $pesel_challenge = $_SESSION['ika_pesel_ch'];
}
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Weryfikacja tożsamości — <?= h($org_name) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?php branding_css($_b); ?>
<style>
*,*::before,*::after{box-sizing:border-box}
html,body{height:100%;margin:0;padding:0;background:#0f172a}

/* ── Shell ───────────────────────────────────────────────────── */
.gate-shell{
  min-height:100vh;
  display:flex;
  align-items:stretch;
}

/* ── Lewa — ciemny panel informacyjny ────────────────────────── */
.gate-left{
  width:360px;flex-shrink:0;
  background:linear-gradient(160deg,#0f172a 0%,#1e293b 60%,#1e3a5f 100%);
  display:flex;flex-direction:column;justify-content:space-between;
  padding:2.5rem 2rem;
  border-right:1px solid rgba(255,255,255,.06);
  position:relative;overflow:hidden;
}
.gate-left::before{
  content:'';position:absolute;
  width:400px;height:400px;border-radius:50%;
  border:70px solid rgba(255,255,255,.025);
  bottom:-120px;right:-130px;pointer-events:none;
}
.gate-left::after{
  content:'';position:absolute;
  width:220px;height:220px;border-radius:50%;
  border:45px solid rgba(37,99,235,.08);
  top:-60px;left:-70px;pointer-events:none;
}

/* ── Tarcza bezpieczeństwa ────────────────────────────────────── */
.shield-wrap{
  position:relative;width:72px;height:72px;margin-bottom:1.5rem;
}
.shield-ring{
  position:absolute;inset:-10px;border-radius:50%;
  border:2px solid rgba(37,99,235,.3);
  animation:_pulse 2.5s ease-in-out infinite;
}
@keyframes _pulse{
  0%,100%{transform:scale(1);opacity:.5}
  50%{transform:scale(1.12);opacity:1}
}
.shield-icon{
  width:72px;height:72px;border-radius:18px;
  background:linear-gradient(135deg,#1e3a5f,#2563eb);
  display:flex;align-items:center;justify-content:center;
  font-size:2rem;color:#fff;
  box-shadow:0 0 30px rgba(37,99,235,.35);
}

/* ── Tekst lewego panelu ──────────────────────────────────────── */
.gate-sys-label{font-size:.62rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.3);margin-bottom:.35rem}
.gate-sys-name{font-size:.95rem;font-weight:600;color:rgba(255,255,255,.5);line-height:1.35;margin-bottom:2rem}
.gate-sys-name em{color:#93c5fd;font-style:normal}

/* ── Panel info ───────────────────────────────────────────────── */
.gate-info{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.09);border-radius:10px;padding:1rem 1.1rem}
.gate-info-label{font-size:.65rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:rgba(255,255,255,.35);margin-bottom:.6rem;display:flex;align-items:center;gap:.4rem}
.gate-info-row{display:flex;align-items:flex-start;gap:.55rem;font-size:.79rem;color:rgba(255,255,255,.55);line-height:1.5;margin-bottom:.35rem}
.gate-info-row:last-child{margin-bottom:0}
.gate-info-row i{flex-shrink:0;margin-top:.15rem}

/* ── Moduł chip ───────────────────────────────────────────────── */
.module-chip{
  display:inline-flex;align-items:center;gap:.4rem;
  background:rgba(37,99,235,.2);border:1px solid rgba(37,99,235,.4);
  border-radius:6px;padding:.2rem .6rem;font-size:.75rem;
  color:#93c5fd;font-weight:600;margin-top:.75rem;
}

/* ── Pasek bezpieczeństwa ─────────────────────────────────────── */
.sec-level{
  display:flex;align-items:center;gap:.6rem;margin-top:1.25rem;
  padding:.6rem .9rem;background:rgba(255,255,255,.04);border-radius:8px;
}
.sec-level-dots{display:flex;gap:3px}
.sec-dot{width:6px;height:6px;border-radius:50%}

/* ── Footer lewego panelu ─────────────────────────────────────── */
.gate-left-footer{color:rgba(255,255,255,.2);font-size:.71rem}
.gate-left-footer a{color:rgba(255,255,255,.35);text-decoration:none;display:inline-flex;align-items:center;gap:.3rem}
.gate-left-footer a:hover{color:rgba(255,255,255,.65)}

/* ── Prawa strona ────────────────────────────────────────────── */
.gate-right{
  flex:1;background:#F1F5F9;
  display:flex;align-items:center;justify-content:center;
  padding:2rem 1.5rem;overflow-y:auto;
}
.gate-box{
  width:100%;max-width:440px;
  background:#fff;border-radius:18px;
  border:1px solid rgba(100,116,139,.12);
  box-shadow:0 12px 48px rgba(0,0,0,.13),0 2px 8px rgba(0,0,0,.06);
  overflow:hidden;
}

/* ── Header boksa ────────────────────────────────────────────── */
.gate-box-head{
  background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 100%);
  padding:1.25rem 1.5rem;
  display:flex;align-items:center;gap:.85rem;
  border-bottom:1px solid rgba(37,99,235,.2);
}
.gate-box-head.mode-setup{
  background:linear-gradient(135deg,#78350f 0%,#b45309 100%);
  border-bottom:1px solid rgba(245,158,11,.25);
}
.gate-box-head-icon{
  width:40px;height:40px;border-radius:10px;
  background:rgba(37,99,235,.3);
  display:flex;align-items:center;justify-content:center;
  font-size:1.15rem;color:#93c5fd;flex-shrink:0;
}
.mode-setup .gate-box-head-icon{
  background:rgba(245,158,11,.25);color:#fcd34d;
}
.gate-box-head-title{font-size:1rem;font-weight:700;color:#fff}
.gate-box-head-sub{font-size:.77rem;color:rgba(255,255,255,.55);margin-top:.15rem}

/* ── Ciało boksa ─────────────────────────────────────────────── */
.gate-box-body{padding:1.5rem}

/* ── Chip użytkownika ────────────────────────────────────────── */
.user-chip{
  display:flex;align-items:center;gap:.75rem;
  background:#f8fafc;border:1.5px solid #e2e8f0;
  border-radius:.75rem;padding:.6rem .9rem;margin-bottom:1.25rem;
}
.user-avatar{
  width:36px;height:36px;border-radius:50%;
  background:var(--c,#2563eb);color:#fff;
  display:flex;align-items:center;justify-content:center;
  font-size:.82rem;font-weight:700;flex-shrink:0;
}
.user-name{font-size:.87rem;font-weight:600;color:#0f172a;line-height:1.2}
.user-role{font-size:.73rem;color:#64748b}
.user-chip-lock{margin-left:auto;color:#94a3b8;font-size:.9rem}

/* ── 6 boxów na cyfry IKA ────────────────────────────────────── */
.digit-row{display:flex;gap:.45rem;justify-content:center;margin:1rem 0}
.digit-box{
  width:52px;height:64px;
  border:2px solid #CBD5E1;border-radius:.6rem;
  background:#F8FAFC;
  font-size:1.9rem;font-weight:700;font-family:monospace;
  text-align:center;outline:none;
  transition:border-color .15s,box-shadow .15s,background .15s;
  color:#0f172a;caret-color:transparent;
}
.digit-box:focus{
  border-color:var(--c,#2563eb);
  box-shadow:0 0 0 3px var(--c-ring,rgba(37,99,235,.15));
  background:#fff;
}
.digit-box.filled{background:#f0f7ff;border-color:var(--c,#2563eb)}
.digit-box.is-error{border-color:#ef4444;background:#fff5f5}

/* ── 3 boxy PESEL ────────────────────────────────────────────── */
.pesel-row{display:flex;gap:.5rem;justify-content:center;margin:1rem 0}
.pesel-box{
  width:52px;height:64px;
  border:2px solid #CBD5E1;border-radius:.6rem;
  background:#F8FAFC;
  font-size:2rem;font-weight:700;font-family:monospace;
  text-align:center;outline:none;
  transition:border-color .15s,box-shadow .15s;
  color:#0f172a;
}
.pesel-box:focus{border-color:var(--c,#2563eb);box-shadow:0 0 0 3px var(--c-ring,rgba(37,99,235,.15));background:#fff}
.pesel-box.is-error{border-color:#ef4444;background:#fff5f5}
.pesel-pos{font-size:.7rem;color:#94a3b8;text-align:center;margin-top:.2rem;font-weight:600}

/* ── Przycisk główny ─────────────────────────────────────────── */
.btn-gate{
  background:linear-gradient(135deg,var(--c,#2563eb) 0%,var(--c-dark,#1d4ed8) 100%);
  color:#fff;border:none;
  border-radius:.6rem;padding:.75rem 1.25rem;
  font-size:.92rem;font-weight:600;width:100%;
  transition:all .15s;cursor:pointer;
  display:flex;align-items:center;justify-content:center;gap:.5rem;
}
.btn-gate:hover:not(:disabled){
  background:linear-gradient(135deg,var(--c-dark,#1d4ed8) 0%,#1e40af 100%);
  box-shadow:0 4px 14px var(--c-ring,rgba(37,99,235,.35));
  transform:translateY(-1px);
}
.btn-gate:disabled{opacity:.45;cursor:not-allowed;transform:none}
.btn-gate.btn-setup{
  --c:#d97706;--c-dark:#b45309;--c-ring:rgba(217,119,6,.35);
}

/* ── Separator ───────────────────────────────────────────────── */
.or-sep{display:flex;align-items:center;gap:.75rem;margin:1rem 0;color:#94a3b8;font-size:.75rem}
.or-sep::before,.or-sep::after{content:'';flex:1;height:1px;background:#e2e8f0}

/* ── Odzyskiwanie ────────────────────────────────────────────── */
.recovery-links{display:flex;flex-direction:column;gap:.4rem}
.recovery-btn{
  display:flex;align-items:center;gap:.6rem;
  padding:.6rem 1rem;border:1.5px solid #e2e8f0;border-radius:.65rem;
  background:#fff;color:#374151;font-size:.81rem;text-decoration:none;
  cursor:pointer;transition:all .12s;width:100%;text-align:left;
}
.recovery-btn:hover{border-color:var(--c,#2563eb);color:var(--c,#2563eb);background:#f0f7ff;transform:translateX(2px)}
.recovery-btn i{color:#94a3b8;font-size:.9rem;flex-shrink:0;transition:color .12s}
.recovery-btn:hover i{color:var(--c,#2563eb)}

/* ── Setup form ──────────────────────────────────────────────── */
.setup-field{margin-bottom:1rem}
.setup-label{font-size:.78rem;font-weight:600;color:#374151;margin-bottom:.35rem;display:block}
.setup-label span{font-weight:400;color:#94a3b8;font-size:.73rem;margin-left:.3rem}
.setup-input{
  width:100%;border:1.5px solid #CBD5E1;border-radius:.6rem;
  padding:.55rem .8rem;font-size:.9rem;color:#0f172a;
  background:#F8FAFC;outline:none;
  transition:border-color .15s,box-shadow .15s,background .15s;
}
.setup-input:focus{
  border-color:#d97706;
  box-shadow:0 0 0 3px rgba(217,119,6,.12);
  background:#fff;
}
.setup-input.is-error{border-color:#ef4444;background:#fff5f5}
.setup-code-input{
  font-family:monospace;letter-spacing:.25em;font-size:1.1rem;font-weight:700;
  text-align:center;text-transform:uppercase;
}
.setup-hint{font-size:.72rem;color:#94a3b8;margin-top:.3rem}

/* ── Spinner ──────────────────────────────────────────────────── */
@keyframes _spin{to{transform:rotate(360deg)}}
.spin-icon{display:none;width:1rem;height:1rem;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:_spin .6s linear infinite}

/* ── Stan: brak kodu / blocked ───────────────────────────────── */
.state-box{text-align:center;padding:1rem 0 .5rem}
.state-icon{font-size:2.4rem;display:block;margin-bottom:.7rem}
.state-box p{color:#64748b;font-size:.86rem;line-height:1.6;margin:0}
.state-box p+p{margin-top:.4rem}

/* ── Postęp (czas sesji) ─────────────────────────────────────── */
.session-badge{
  display:inline-flex;align-items:center;gap:.3rem;
  font-size:.72rem;background:#f0fdf4;border:1px solid #bbf7d0;
  border-radius:20px;padding:.15rem .55rem;color:#166534;
}

/* ── Mobile ───────────────────────────────────────────────────── */
@media(max-width:700px){
  .gate-shell{flex-direction:column}
  .gate-left{width:100%;padding:1rem 1.25rem;flex-direction:row;align-items:center;gap:.75rem;min-height:auto}
  .gate-left::before,.gate-left::after{display:none}
  .gate-left>div:first-child{display:flex;align-items:center;gap:.75rem;flex:1}
  .shield-wrap{width:40px;height:40px;margin-bottom:0}
  .shield-ring{display:none}
  .shield-icon{width:40px;height:40px;border-radius:10px;font-size:1.1rem}
  .gate-sys-label,.gate-sys-name,.gate-info,.module-chip,.sec-level,.gate-left-footer{display:none}
  .gate-right{padding:1.25rem 1rem;align-items:flex-start;background:#F1F5F9}
  .gate-box{box-shadow:none;border-radius:14px}
  .digit-box{width:44px;height:56px;font-size:1.55rem}
}
</style>
</head>
<body>
<div class="gate-shell">

<!-- ══ Lewa ═══════════════════════════════════════════════════════════════════ -->
<div class="gate-left">
  <div>
    <div class="shield-wrap">
      <div class="shield-ring"></div>
      <?php if ($_b['logo_url']): ?>
      <div class="shield-icon" style="background:rgba(255,255,255,.12);padding:.3rem">
        <img src="<?= h($_b['logo_url']) ?>" alt="" style="max-height:44px;max-width:44px;object-fit:contain;filter:brightness(0) invert(1)">
      </div>
      <?php else: ?>
      <div class="shield-icon"><i class="bi bi-shield-lock-fill"></i></div>
      <?php endif; ?>
    </div>

    <div class="gate-sys-label">Platforma NGO</div>
    <div class="gate-sys-name">
      System Zarządzania<br><em>Organizacją i Wolontariatem</em>
    </div>

    <div class="gate-info">
      <div class="gate-info-label"><i class="bi bi-shield-check"></i> Punkt weryfikacji</div>
      <div class="gate-info-row">
        <i class="bi bi-key-fill" style="color:#93c5fd"></i>
        <span>IKA to 6-cyfrowy kod przypisany indywidualnie do konta — nie jest hasłem ani SMS-em.</span>
      </div>
      <?php if ($_ika_context === 'karty30'): ?>
      <div class="gate-info-row">
        <i class="bi bi-person-vcard" style="color:#c4b5fd"></i>
        <span>Moduł przetwarza <strong style="color:#fca5a5">wrażliwe dane osobowe</strong> beneficjentów — weryfikacja wymagana przy każdym wejściu.</span>
      </div>
      <?php elseif ($_ika_context === 'crm'): ?>
      <div class="gate-info-row">
        <i class="bi bi-diagram-2-fill" style="color:#86efac"></i>
        <span>Chroni dostęp do CRM — kontaktów, komunikacji i danych relacyjnych organizacji.</span>
      </div>
      <?php else: ?>
      <div class="gate-info-row">
        <i class="bi bi-file-earmark-text" style="color:#93c5fd"></i>
        <span>Chroni modyfikację dokumentów i umów w systemie.</span>
      </div>
      <?php endif; ?>
      <div class="gate-info-row">
        <i class="bi bi-clock" style="color:#fde68a"></i>
        <span>Sesja weryfikacji jest ważna <strong style="color:#fde68a">30 minut</strong>.</span>
      </div>
    </div>

    <div class="module-chip">
      <i class="bi bi-arrow-right-circle-fill"></i>
      <?= h($_ika_module) ?>
    </div>

    <?php
      $sec_dots = ['admin'=>4,'editor'=>3];
      $dots = $sec_dots[$role] ?? (in_array($role,['crm_user'])?3:2);
    ?>
    <div class="sec-level">
      <div class="sec-level-dots">
        <?php for ($i=1;$i<=4;$i++): ?>
        <div class="sec-dot" style="background:<?= $i<=$dots ? '#2563eb' : 'rgba(255,255,255,.15)' ?>"></div>
        <?php endfor; ?>
      </div>
      <span style="font-size:.73rem;color:rgba(255,255,255,.45)">
        Poziom ochrony: <strong style="color:rgba(255,255,255,.65)"><?= $dots>=4?'Krytyczny':($dots>=3?'Wysoki':'Standardowy') ?></strong>
      </span>
    </div>

    <div style="margin-top:.75rem">
      <div style="font-size:.7rem;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:rgba(255,255,255,.25);margin-bottom:.3rem">Organizacja</div>
      <div style="font-size:.9rem;font-weight:700;color:rgba(255,255,255,.7)"><?= h($org_name) ?></div>
    </div>
  </div>

  <div class="gate-left-footer">
    <a href="<?= h(APP_URL) ?>/index.php"><i class="bi bi-arrow-left-circle"></i> Strona główna</a>
    <div style="margin-top:.4rem">&copy; <?= date('Y') ?> · System NGO</div>
  </div>
</div>

<!-- ══ Prawa ════════════════════════════════════════════════════════════════════ -->
<div class="gate-right">
<div class="gate-box">

  <!-- Header boksa — kontekstowy -->
  <div class="gate-box-head<?= $page_mode === 'setup' ? ' mode-setup' : '' ?>">
    <div class="gate-box-head-icon">
      <?php if ($page_mode === 'setup'): ?>
        <i class="bi bi-shield-plus"></i>
      <?php elseif ($page_mode === 'pesel'): ?>
        <i class="bi bi-card-text"></i>
      <?php elseif ($page_mode === 'email_verify' || $page_mode === 'email_sent'): ?>
        <i class="bi bi-envelope-check"></i>
      <?php elseif ($_ika_context === 'karty30'): ?>
        <i class="bi bi-card-checklist"></i>
      <?php elseif ($_ika_context === 'crm'): ?>
        <i class="bi bi-diagram-2-fill"></i>
      <?php else: ?>
        <i class="bi bi-shield-check"></i>
      <?php endif; ?>
    </div>
    <div>
      <div class="gate-box-head-title">
        <?php if ($page_mode === 'setup'): ?>Ustaw kody autoryzacyjne
        <?php elseif ($page_mode === 'pesel'): ?>Weryfikacja PESEL
        <?php elseif ($page_mode === 'email_verify'): ?>Kod e-mail
        <?php else: ?>Weryfikacja IKA<?php endif; ?>
      </div>
      <div class="gate-box-head-sub">
        <?php if ($page_mode === 'setup'): ?>Jednorazowy AdminCode od administratora
        <?php elseif ($page_mode === 'pesel'): ?>Podaj cyfry z numeru PESEL
        <?php elseif ($page_mode === 'email_verify'): ?>Jednorazowy kod wysłany na e-mail
        <?php else: ?>Podaj kod IKA, aby przejść do: <strong style="color:#93c5fd"><?= h($_ika_module) ?></strong>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="gate-box-body">

    <!-- Chip zalogowanego użytkownika -->
    <div class="user-chip">
      <div class="user-avatar"><?= h($initials) ?></div>
      <div style="flex:1;min-width:0">
        <div class="user-name"><?= h($display_name) ?></div>
        <div class="user-role"><?= h($role_label) ?></div>
      </div>
      <i class="bi bi-lock-fill user-chip-lock"></i>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger d-flex align-items-center gap-2 py-2 mb-3" style="border-radius:.5rem;font-size:.83rem">
      <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
      <?= h($error) ?>
    </div>
    <?php endif; ?>

    <?php if ($info): ?>
    <div class="alert alert-info d-flex align-items-center gap-2 py-2 mb-3" style="border-radius:.5rem;font-size:.83rem">
      <i class="bi bi-info-circle-fill flex-shrink-0"></i>
      <?= h($info) ?>
    </div>
    <?php endif; ?>

    <?php if ($_ip_blocked): ?>
    <!-- ── Blokada IP ───────────────────────────────────────── -->
    <div class="state-box">
      <span class="state-icon"><i class="bi bi-slash-circle text-danger"></i></span>
      <p>Zbyt wiele nieudanych prób z tego komputera.</p>
      <p>Zamknij przeglądarkę i spróbuj ponownie za kilka minut.</p>
    </div>

    <?php elseif ($is_blocked): ?>
    <!-- ── Zablokowany IKA ──────────────────────────────────── -->
    <div class="state-box">
      <span class="state-icon"><i class="bi bi-hourglass-split text-warning"></i></span>
      <p>Kod IKA jest tymczasowo zablokowany<br>z powodu błędnych prób.</p>
      <p>Odblokowanie o: <strong><?= h(date('H:i', strtotime($u_fresh['cpc_blocked_until']))) ?></strong></p>
    </div>
    <div class="or-sep">lub</div>
    <div class="recovery-links">
      <?php if ($user_email): ?>
      <a href="?to=<?= urlencode($return_to) ?>&mode=email_verify" class="recovery-btn">
        <i class="bi bi-envelope-arrow-down-fill"></i>
        Zweryfikuj jednorazowym kodem e-mail
      </a>
      <?php endif; ?>
      <?php if ($pesel_available): ?>
      <a href="?to=<?= urlencode($return_to) ?>&mode=pesel" class="recovery-btn">
        <i class="bi bi-card-text"></i>
        Zweryfikuj cyframi PESEL
      </a>
      <?php endif; ?>
    </div>

    <?php elseif (!$has_code && $page_mode === 'ika'): ?>
    <!-- ── Brak kodu IKA ────────────────────────────────────── -->
    <div class="state-box">
      <span class="state-icon"><i class="bi bi-key text-secondary"></i></span>
      <p>Nie masz przypisanego kodu IKA.</p>
      <p>Poproś administratora o nadanie kodu<br>lub wygenerowanie <strong>AdminCode</strong>.</p>
    </div>
    <?php if ($has_setup_token || $user_email || $pesel_available): ?>
    <div class="or-sep">dostępne opcje</div>
    <div class="recovery-links">
      <?php if ($has_setup_token): ?>
      <a href="?to=<?= urlencode($return_to) ?>&mode=setup" class="recovery-btn" style="border-color:#fcd34d;background:#fffbeb;color:#92400e">
        <i class="bi bi-shield-plus" style="color:#d97706"></i>
        <span><strong>Mam AdminCode</strong> — ustaw kody samodzielnie</span>
        <i class="bi bi-arrow-right ms-auto" style="color:#d97706;font-size:.75rem"></i>
      </a>
      <?php endif; ?>
      <?php if ($user_email): ?>
      <a href="?to=<?= urlencode($return_to) ?>&mode=email_verify" class="recovery-btn">
        <i class="bi bi-envelope-arrow-down-fill"></i>
        Użyj jednorazowego kodu e-mail
      </a>
      <?php endif; ?>
      <?php if ($pesel_available): ?>
      <a href="?to=<?= urlencode($return_to) ?>&mode=pesel" class="recovery-btn">
        <i class="bi bi-card-text"></i>
        Zweryfikuj cyframi PESEL
      </a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php elseif ($page_mode === 'email_verify'): ?>
    <!-- ══ Tryb: Email OTP ══════════════════════════════════════ -->
    <?php
      $otp_ready = !empty($u_fresh['ika_email_otp'])
               && !empty($u_fresh['ika_email_otp_expires'])
               && $u_fresh['ika_email_otp_expires'] > date('Y-m-d H:i:s');
    ?>

    <?php if (!$otp_ready): ?>
    <!-- Nie ma jeszcze kodu — wyślij -->
    <p style="font-size:.84rem;color:#475569;margin-bottom:1.25rem">
      Jeśli nie pamiętasz kodu IKA, system może wysłać <strong>jednorazowy kod</strong>
      na Twój adres e-mail:
      <?php if ($user_email): ?>
      <strong><?= h($user_email) ?></strong>.
      <?php else: ?>
      <span class="text-danger">(brak adresu e-mail na koncie)</span>.
      <?php endif; ?>
    </p>
    <?php if ($user_email): ?>
    <form method="post">
      <input type="hidden" name="_csrf"  value="<?= csrf_token() ?>">
      <input type="hidden" name="_mode" value="request_email_otp">
      <input type="hidden" name="to"    value="<?= h($return_to) ?>">
      <input type="text"   name="_hp"   style="display:none" tabindex="-1" autocomplete="off">
      <button type="submit" class="btn-gate">
        <i class="bi bi-envelope-arrow-down-fill"></i>
        Wyślij kod na <?= h($user_email) ?>
      </button>
    </form>
    <?php else: ?>
    <div class="alert alert-warning py-2" style="font-size:.83rem">Brak adresu e-mail na koncie. Skontaktuj się z administratorem.</div>
    <?php endif; ?>

    <?php else: ?>
    <!-- Kod już wysłany — formularz wpisania OTP -->
    <p style="font-size:.83rem;color:#475569;margin-bottom:1rem">
      Wpisz 6-cyfrowy kod wysłany na <strong><?= h($user_email) ?></strong>.
      Kod jest ważny 15 minut.
    </p>

    <form method="post" id="emailOtpForm" autocomplete="off">
      <input type="hidden" name="_csrf"  value="<?= csrf_token() ?>">
      <input type="hidden" name="_mode" value="verify_email_otp">
      <input type="hidden" name="to"    value="<?= h($return_to) ?>">
      <input type="text"   name="_hp"   style="display:none" tabindex="-1" autocomplete="off">

      <div class="digit-row" id="emailDigits" role="group" aria-label="Kod e-mail">
        <?php for ($i=1;$i<=6;$i++): ?>
        <input type="text" class="digit-box" maxlength="1" inputmode="numeric" pattern="\d"
               id="ed<?= $i ?>" data-idx="<?= $i ?>" autocomplete="off" aria-label="Cyfra <?= $i ?>">
        <?php endfor; ?>
        <input type="hidden" name="email_otp" id="emailOtpHidden">
      </div>

      <button type="submit" class="btn-gate" id="btnEmailOtp" disabled>
        <span class="spin-icon" id="spinEmailOtp"></span>
        <i class="bi bi-shield-check"></i>
        Zweryfikuj kod e-mail
      </button>
    </form>

    <div style="margin-top:.9rem;text-align:center">
      <form method="post" style="display:inline">
        <input type="hidden" name="_csrf"  value="<?= csrf_token() ?>">
        <input type="hidden" name="_mode" value="request_email_otp">
        <input type="hidden" name="to"    value="<?= h($return_to) ?>">
        <input type="text"   name="_hp"   style="display:none" tabindex="-1" autocomplete="off">
        <button type="submit" style="background:none;border:none;color:#94a3b8;font-size:.78rem;cursor:pointer;padding:0">
          <i class="bi bi-arrow-clockwise me-1"></i>Wyślij nowy kod
        </button>
      </form>
    </div>
    <?php endif; ?>

    <?php elseif ($page_mode === 'pesel' && $pesel_challenge): ?>
    <!-- ══ Tryb: PESEL challenge ════════════════════════════════ -->
    <?php $pos = $pesel_challenge['pos']; ?>
    <p style="font-size:.84rem;color:#475569;margin-bottom:.75rem">
      Podaj cyfry z numeru PESEL na pozycjach:
    </p>
    <div style="display:flex;justify-content:center;gap:.5rem;margin-bottom:1.25rem">
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
        <?php foreach ($pos as $idx => $p): $n = $idx+1; ?>
        <div style="text-align:center">
          <input type="text" class="pesel-box<?= $error?' is-error':'' ?>"
                 name="pc<?= $n ?>" id="pb<?= $n ?>" maxlength="1"
                 inputmode="numeric" pattern="\d"
                 data-idx="<?= $n ?>" aria-label="Pozycja <?= $p ?>" autocomplete="off">
          <div class="pesel-pos">poz. <?= $p ?></div>
        </div>
        <?php endforeach; ?>
      </div>

      <button type="submit" class="btn-gate" id="btnPesel" disabled>
        <span class="spin-icon" id="spinPesel"></span>
        <i class="bi bi-card-text"></i>
        Zweryfikuj
      </button>
    </form>

    <?php elseif ($page_mode === 'setup'): ?>
    <!-- ══ Tryb: Setup (AdminCode) ══════════════════════════════ -->
    <p style="font-size:.82rem;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:.6rem;padding:.6rem .85rem;margin-bottom:1.1rem;display:flex;gap:.5rem;align-items:flex-start">
      <i class="bi bi-info-circle-fill flex-shrink-0 mt-1" style="color:#d97706"></i>
      Wpisz <strong>AdminCode</strong> otrzymany od administratora oraz kody, które chcesz ustawić.
      Po zapisaniu uzyskasz natychmiastowy dostęp.
    </p>
    <form method="post" id="setupForm" autocomplete="off">
      <input type="hidden" name="_csrf"  value="<?= csrf_token() ?>">
      <input type="hidden" name="_mode" value="setup">
      <input type="hidden" name="to"    value="<?= h($return_to) ?>">

      <div class="setup-field">
        <label class="setup-label" for="admin_code">AdminCode <span>(jednorazowy, od administratora)</span></label>
        <input type="text"
               id="admin_code" name="admin_code"
               class="setup-input setup-code-input<?= ($page_mode==='setup'&&$error) ? ' is-error' : '' ?>"
               maxlength="10" placeholder="np. A3B7C2D8E1"
               autocomplete="off" spellcheck="false"
               oninput="this.value=this.value.toUpperCase().replace(/[^0-9A-F]/g,'')">
        <div class="setup-hint">10 znaków: cyfry i litery A–F</div>
      </div>

      <div class="or-sep" style="margin:.75rem 0"></div>

      <div class="setup-field">
        <label class="setup-label" for="setup_cpc">Nowy kod IKA <span>(dokładnie 6 cyfr)</span></label>
        <input type="text"
               id="setup_cpc" name="cpc_code"
               class="setup-input setup-code-input<?= ($page_mode==='setup'&&$error) ? ' is-error' : '' ?>"
               maxlength="6" placeholder="000000" inputmode="numeric"
               pattern="\d{6}" autocomplete="new-password">
        <div class="setup-hint">Wybierz 6-cyfrowy kod IKA — zapamiętaj go</div>
      </div>

      <div class="setup-field">
        <label class="setup-label" for="ikaks1">Nowy IKAKS <span>(min. 6 znaków)</span></label>
        <input type="password"
               id="ikaks1" name="ikaks1"
               class="setup-input<?= ($page_mode==='setup'&&$error) ? ' is-error' : '' ?>"
               minlength="6" autocomplete="new-password" placeholder="min. 6 znaków">
      </div>

      <div class="setup-field" style="margin-bottom:1.25rem">
        <label class="setup-label" for="ikaks2">Powtórz IKAKS</label>
        <input type="password"
               id="ikaks2" name="ikaks2"
               class="setup-input<?= ($page_mode==='setup'&&$error) ? ' is-error' : '' ?>"
               minlength="6" autocomplete="new-password" placeholder="powtórz IKAKS">
      </div>

      <button type="submit" class="btn-gate btn-setup">
        <span class="spin-icon" id="spinSetup"></span>
        <i class="bi bi-shield-check"></i>
        Zapisz kody i wejdź do: <strong class="ms-1"><?= h($_ika_module) ?></strong>
      </button>
    </form>
    <div style="margin-top:.75rem;text-align:center">
      <a href="?to=<?= urlencode($return_to) ?>" style="font-size:.77rem;color:#94a3b8;text-decoration:none">
        <i class="bi bi-arrow-left me-1"></i>Mam już kod IKA
      </a>
    </div>

    <?php else: ?>
    <!-- ══ Tryb domyślny: kod IKA ═══════════════════════════════ -->
    <form method="post" id="ikaForm" autocomplete="off">
      <input type="hidden" name="_csrf"  value="<?= csrf_token() ?>">
      <input type="hidden" name="_mode" value="ika">
      <input type="hidden" name="to"    value="<?= h($return_to) ?>">
      <input type="text"   name="_hp"   style="display:none" tabindex="-1" autocomplete="off">

      <p style="font-size:.8rem;color:#94a3b8;text-align:center;margin-bottom:.5rem">
        Wpisz 6-cyfrowy kod IKA
      </p>

      <!-- 6 osobnych boxów -->
      <div class="digit-row" id="ikaDigits" role="group" aria-label="Kod IKA">
        <?php for ($i=1;$i<=6;$i++): ?>
        <input type="text" class="digit-box<?= $error?' is-error':'' ?>"
               maxlength="1" inputmode="numeric" pattern="\d"
               id="d<?= $i ?>" data-idx="<?= $i ?>"
               autocomplete="off" aria-label="Cyfra <?= $i ?>">
        <?php endfor; ?>
        <input type="hidden" name="ika_code" id="ikaCodeHidden">
      </div>

      <button type="submit" class="btn-gate" id="btnVerify" disabled>
        <span class="spin-icon" id="ikaSpinner"></span>
        <i class="bi bi-shield-check"></i>
        <span id="btnText">Zweryfikuj i wejdź</span>
      </button>
    </form>

    <?php if ($user_email || $pesel_available || $has_setup_token): ?>
    <div class="or-sep">nie pamiętasz kodu?</div>
    <div class="recovery-links">
      <?php if ($has_setup_token): ?>
      <a href="?to=<?= urlencode($return_to) ?>&mode=setup" class="recovery-btn" style="border-color:#fcd34d;background:#fffbeb;color:#92400e">
        <i class="bi bi-shield-plus" style="color:#d97706"></i>
        <span><strong>Mam AdminCode</strong> — ustaw nowy kod IKA</span>
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

    <!-- Anuluj -->
    <div style="margin-top:1rem;text-align:center">
      <?php $back_url = match($_ika_context) {'karty30'=>APP_URL.'/karty30/index.php','crm'=>APP_URL.'/crm/dashboard.php',default=>APP_URL.'/index.php'}; ?>
      <a href="<?= h($back_url) ?>" style="font-size:.79rem;color:#94a3b8;text-decoration:none">
        <i class="bi bi-arrow-left me-1"></i>Anuluj i wróć
      </a>
    </div>

  </div><!-- /gate-box-body -->
</div><!-- /gate-box -->
</div><!-- /gate-right -->
</div><!-- /gate-shell -->

<script>
(function(){
  'use strict';

  /* ── Obsługa 6 boxów (IKA) ──────────────────────────────────── */
  function initDigitGroup(groupId, hiddenId, btnId, spinnerId, maxLen) {
    var group   = document.getElementById(groupId);
    var hidden  = document.getElementById(hiddenId);
    var btn     = document.getElementById(btnId);
    var spinner = document.getElementById(spinnerId);
    if (!group || !hidden || !btn) return;

    var inputs = Array.from(group.querySelectorAll('.digit-box, .pesel-box'));

    function collect() {
      return inputs.map(function(i){ return i.value.replace(/\D/g,'').slice(0,1); }).join('');
    }
    function sync() {
      var v = collect();
      if (hidden) hidden.value = v;
      var ready = v.length === (maxLen || inputs.length);
      btn.disabled = !ready;
      inputs.forEach(function(i){
        i.classList.toggle('filled', i.value !== '');
      });
    }

    inputs.forEach(function(inp, idx) {
      inp.addEventListener('input', function() {
        var d = this.value.replace(/\D/g,'').slice(0,1);
        this.value = d;
        sync();
        if (d && idx < inputs.length - 1) inputs[idx+1].focus();
        if (collect().length === inputs.length && btn) {
          setTimeout(function(){ if (!btn.disabled) btn.click(); }, 120);
        }
      });
      inp.addEventListener('keydown', function(e) {
        if (e.key === 'Backspace' && !this.value && idx > 0) inputs[idx-1].focus();
        if (e.key === 'ArrowLeft'  && idx > 0) inputs[idx-1].focus();
        if (e.key === 'ArrowRight' && idx < inputs.length-1) inputs[idx+1].focus();
      });
      inp.addEventListener('paste', function(e) {
        e.preventDefault();
        var pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g,'');
        pasted.split('').forEach(function(ch, i) {
          if (idx + i < inputs.length) {
            inputs[idx+i].value = ch;
          }
        });
        var next = Math.min(idx + pasted.length, inputs.length - 1);
        inputs[next].focus();
        sync();
      });
    });

    var form = btn.closest('form');
    if (form) {
      form.addEventListener('submit', function() {
        if (spinner) spinner.style.display = 'inline-block';
        btn.disabled = true;
      });
    }

    if (inputs.length) inputs[0].focus();
  }

  initDigitGroup('ikaDigits',    'ikaCodeHidden',  'btnVerify',   'ikaSpinner',    6);
  initDigitGroup('emailDigits',  'emailOtpHidden', 'btnEmailOtp', 'spinEmailOtp',  6);
  initDigitGroup('peselForm',    null,             'btnPesel',    'spinPesel',     3);

  /* Setup form — spinner + uppercase AdminCode */
  (function() {
    var form = document.getElementById('setupForm');
    if (!form) return;
    var ac = document.getElementById('admin_code');
    if (ac) {
      ac.addEventListener('input', function() {
        this.value = this.value.toUpperCase().replace(/[^0-9A-F]/g, '');
      });
      ac.addEventListener('paste', function(e) {
        e.preventDefault();
        var v = (e.clipboardData || window.clipboardData).getData('text');
        this.value = v.toUpperCase().replace(/[^0-9A-F]/g, '').slice(0, 10);
      });
    }
    var btnSetup  = form.querySelector('button[type=submit]');
    var spinSetup = document.getElementById('spinSetup');
    form.addEventListener('submit', function() {
      if (btnSetup)  { btnSetup.disabled = true; }
      if (spinSetup) { spinSetup.style.display = 'inline-block'; }
    });
    if (ac) ac.focus();
  })();

  /* PESEL form — 3 osobne boxy (nie mają wspólnej grupy z id) */
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
        if (collect().length === pInputs.length) setTimeout(function(){if (!btn.disabled) btn.click();}, 120);
      });
      inp.addEventListener('keydown', function(e){
        if (e.key==='Backspace' && !this.value && idx>0) pInputs[idx-1].focus();
      });
    });
    if (pInputs.length) pInputs[0].focus();
  })();

})();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
