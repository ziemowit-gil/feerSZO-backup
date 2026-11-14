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
        '/modules/srs/'            => ['bi-box-seam-fill',        '#0891b2', 'Zasoby (SRS)',        'Zarządzanie zasobami'],
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
                           WHERE LOWER({$col})=? AND pesel IS NOT NULL AND (LENGTH(pesel)=11 OR pesel LIKE 'enc1:%')
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
    // Honeypot usunięty: menedżery haseł i autouzupełnianie przeglądarki wpisywały
    // wartość w ukryte pole pułapki i blokowały weryfikację mimo poprawnego kodu.
    // Bramka i tak wymaga zalogowanej sesji, tokenu CSRF oraz limitu prób per IP/kod.
    if ($_ip_blocked) { $error = 'Zbyt wiele prób z tego komputera. Zamknij i otwórz przeglądarkę.'; goto render; }

    $mode = $_POST['_mode'] ?? 'ika';

    // ── 1. Weryfikacja kodu IKA ─────────────────────────────────────────────
    if ($mode === 'ika') {
        if (!$has_code) { $error = 'Nie masz przypisanego kodu IKA.'; goto render; }
        if ($is_blocked) { $error = 'Kod IKA zablokowany do ' . date('H:i', strtotime($u_fresh['cpc_blocked_until'])) . '.'; goto render; }

        // Kod składamy z ukrytego pola (wypełnia je JS), a gdy jest puste — wprost
        // z sześciu pól cyfr. Bez tego awaria skryptu = „nieprawidłowy kod" przy
        // każdej próbie, mimo poprawnie wpisanych cyfr.
        $code = preg_replace('/\D/', '', $_POST['ika_code'] ?? '');
        if ($code === '') {
            $code = preg_replace('/\D/', '', implode('', array_map('strval', (array)($_POST['ika_d'] ?? []))));
        }
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
        if ($otp_input === '') {
            $otp_input = preg_replace('/\D/', '', implode('', array_map('strval', (array)($_POST['email_d'] ?? []))));
        }

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
// UWAGA na nawiasy: `$_GET['x'] ?? '' === 'y'` parsuje się jako
// `$_GET['x'] ?? ('' === 'y')`, czyli warunek jest prawdziwy dla KAŻDEGO
// niepustego mode. Zawsze `($_GET['x'] ?? '') === 'y'`.
if (($_GET['mode'] ?? '') === 'pesel') {
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

if (($_GET['mode'] ?? '') === 'email_verify') $page_mode = 'email_verify';
if (($_GET['mode'] ?? '') === 'setup')        $page_mode = 'setup';

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
// Bramka IKA w jasnym wyglądzie ekranów wejścia (inc/loginShell/authUi.php, przebudowa 2026-11-14). Style tylko tej strony,
// zakotwiczone w #ika-main (element <main> powłoki), żeby wygrały z ogólną regułą linków w karcie.
$ika_css = <<<'CSS'
<style>
#ika-main .ika-h1{display:flex;align-items:center;gap:.65rem}
#ika-main .ika-h1 i{flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;
  border-radius:10px;background:var(--au-acc-soft);color:var(--au-acc);font-size:1.2rem}
#ika-main .ika-who-line{margin:.5rem 0 1.5rem;padding:.7rem .9rem;background:#f9fafb;border:1px solid var(--au-line);
  border-radius:var(--au-rs);font-size:.95rem;color:var(--au-ink2);overflow-wrap:anywhere}
#ika-main .ika-who-line a{white-space:nowrap}
/* Zakładki metod: Kod IKA / E-mail */
#ika-main .tz-subnav{margin:0 0 1.25rem}
#ika-main .tz-subnav .seg{display:flex;gap:.25rem;padding:.25rem;background:var(--au-bg);border-radius:10px}
#ika-main .tz-subnav a{flex:1;display:inline-flex;align-items:center;justify-content:center;gap:.4rem;min-height:44px;padding:.45rem .9rem;
  border-radius:8px;color:var(--au-ink2);font-weight:600;text-decoration:none}
#ika-main .tz-subnav a:hover{background:#e5e7eb;color:var(--au-ink)}
#ika-main .tz-subnav a.on{background:#fff;color:var(--au-acc);box-shadow:0 1px 3px rgba(17,24,39,.14)}
/* Pole kodu (IKA, e-mail) */
#ika-main .ika-field{max-width:20rem;margin:0 auto 1.25rem;text-align:center}
#ika-main .ika-field__label{display:block;margin:0 0 .2rem;font-size:1rem;font-weight:700;color:var(--au-ink)}
#ika-main .ika-field__hint{margin:0 0 .5rem;font-size:.875rem;color:var(--au-muted)}
#ika-main .ika-code{min-height:60px;padding:.55rem .5rem .55rem 1rem;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
  font-size:1.75rem;letter-spacing:.45em;text-align:center}
#ika-main .reveal-btn{display:inline-flex;align-items:center;gap:.4rem;min-height:44px;margin-top:.35rem;padding:.25rem .75rem;border:0;
  border-radius:8px;background:none;color:var(--au-ink2);font:inherit;font-size:.9rem;cursor:pointer}
#ika-main .reveal-btn:hover{background:var(--au-bg);color:var(--au-ink)}
/* PESEL: pozycje i trzy pola na cyfry */
#ika-main .tz-badge{display:inline-flex;align-items:center;justify-content:center;border:1px solid transparent;border-radius:999px}
#ika-main .tz-badge--ok{background:var(--au-ok-bg);border-color:var(--au-ok-line);color:var(--au-ok-ink)}
#ika-main .pesel-row{display:flex;justify-content:center;gap:.5rem;margin:.9rem 0}
#ika-main .pesel-box{width:56px;height:64px;border:1px solid var(--au-field);border-radius:10px;background:#fff;color:var(--au-ink);
  font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:2rem;font-weight:700;text-align:center}
#ika-main .pesel-box:focus{border-color:var(--au-acc);box-shadow:0 0 0 1px var(--au-acc)}
#ika-main .pesel-box.is-error,#ika-main .pesel-box[aria-invalid="true"]{border-color:var(--au-err);box-shadow:0 0 0 1px var(--au-err);background:var(--au-err-bg)}
#ika-main .pesel-pos{margin-top:.25rem;font-size:.75rem;font-weight:600;text-align:center;color:var(--au-muted)}
/* Separator i drogi odzyskiwania dostępu */
#ika-main .or-sep{display:flex;align-items:center;gap:.75rem;margin:1.5rem 0 .9rem;font-size:.875rem;color:var(--au-muted)}
#ika-main .or-sep::before,#ika-main .or-sep::after{content:"";flex:1;height:1px;background:var(--au-line2)}
#ika-main .recovery-links{display:flex;flex-direction:column;gap:.5rem}
#ika-main .recovery-btn{display:flex;align-items:center;gap:.6rem;width:100%;min-height:48px;padding:.65rem .9rem;border:1px solid var(--au-line2);
  border-radius:var(--au-rs);background:#fff;color:var(--au-ink);font:inherit;font-size:.95rem;text-align:left;text-decoration:none;cursor:pointer}
#ika-main .recovery-btn:hover{border-color:var(--au-acc);background:#f9fafb}
#ika-main .recovery-btn>i:first-child{flex-shrink:0;width:1.25rem;text-align:center;color:var(--au-acc)}
#ika-main .recovery-btn .recovery-sub{margin-left:auto;font-size:.8rem;color:var(--au-muted);overflow-wrap:anywhere;text-align:right}
#ika-main .recovery-btn--admin{background:var(--au-warn-bg);border-color:var(--au-warn-line);color:var(--au-warn-ink)}
#ika-main .recovery-btn--admin>i{color:var(--au-warn)!important}
/* Stany: blokada, brak kodu */
#ika-main .state-box{padding:1.25rem 0;text-align:center}
#ika-main .state-icon{display:block;margin-bottom:.6rem;font-size:2.4rem}
#ika-main .state-box p{margin:0;font-size:.95rem;line-height:1.6;color:var(--au-muted)}
#ika-main .state-box p+p{margin-top:.3rem}
#ika-main .state-box strong{color:var(--au-ink)}
/* Przyciski: granat (kod IKA), zieleń (e-mail, #047857 = 5,5:1), bursztyn (AdminCode, #b45309 = 5,0:1) */
#ika-main .tz-btn{display:inline-flex;align-items:center;justify-content:center;gap:.5rem;min-height:48px;padding:.7rem 1.25rem;
  border:1px solid var(--au-acc);border-radius:var(--au-rs);background:var(--au-acc);color:#fff;font:inherit;font-weight:600;cursor:pointer;text-decoration:none}
#ika-main .tz-btn:hover{background:var(--au-acc-h);border-color:var(--au-acc-h)}
#ika-main .tz-btn--wide{width:100%}
#ika-main .tz-btn--email{background:#047857;border-color:#047857}
#ika-main .tz-btn--email:hover{background:#065f46;border-color:#065f46}
#ika-main .tz-btn--setup{background:#b45309;border-color:#b45309}
#ika-main .tz-btn--setup:hover{background:#92400e;border-color:#92400e}
#ika-main .tz-btn:disabled{opacity:.65;cursor:not-allowed}
#ika-main .ika-link-btn{display:inline-flex;align-items:center;gap:.35rem;min-height:44px;padding:.25rem 0;border:0;background:none;
  color:var(--au-acc);font:inherit;font-size:.95rem;text-decoration:underline;text-underline-offset:2px;cursor:pointer}
@keyframes _spin{to{transform:rotate(360deg)}}
#ika-main .spin-icon{display:none;flex-shrink:0;width:1rem;height:1rem;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;
  border-radius:50%;animation:_spin .6s linear infinite}
/* Linki pod weryfikacją */
#ika-main .ika-bottom{display:flex;justify-content:space-between;flex-wrap:wrap;gap:0 1rem;margin:1.5rem 0 0;padding-top:.75rem;
  border-top:1px solid var(--au-line);font-size:.95rem}
#ika-main .ika-bottom a{display:inline-flex;align-items:center;gap:.35rem;min-height:44px;font-weight:600}
@media(max-width:480px){#ika-main .pesel-box{width:48px;height:58px}}
</style>
CSS;
auth_screen_head([
    'title'           => 'Weryfikacja IKA',
    'light'           => true,
    'width'           => 520,
    'bootstrap'       => true,
    'main_id'         => 'ika-main',
    'account_buttons' => [],
    'head_html'       => $ika_css,
]);
?>

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
    default                       => 'Podaj kod IKA',
};
?>
<?php /* Jeden nagłówek strony (h1), pod nim zwarta linia „na kogo się logujesz” z wyjściem „To nie Ty?”, potem weryfikacja. */ ?>
<h1 class="ks-h1 ika-h1" id="ika-h1"><i class="bi <?= $head_icon ?>" aria-hidden="true"></i><span><?= h($head_title) ?></span></h1>
<p class="ika-who-line">
  Logujesz się jako <strong><?= h($display_name) ?></strong><?php if ($user_email !== '' && $user_email !== $display_name): ?> (<?= h($user_email) ?>)<?php endif; ?>
  · <a href="<?= h(APP_URL) ?>/auth/logout.php">To nie Ty? Wyloguj się</a>
</p>

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

<section class="ika-gate" id="gate-main" aria-labelledby="ika-h1">
  <div>

    <?php if ($error): ?>
    <div class="alert alert-danger d-flex align-items-start gap-2" role="alert" id="ika-err">
      <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1" aria-hidden="true"></i>
      <span id="login-error-text"><?= h($error) ?></span>
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
      <span class="state-icon"><i class="bi bi-slash-circle text-danger" aria-hidden="true"></i></span>
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
      <div class="pesel-row">
        <?php foreach ($pos as $idx => $p): $n = $idx + 1; ?>
        <div style="text-align:center">
          <input type="text" class="pesel-box<?= $error ? ' is-error' : '' ?>"
                 name="pc<?= $n ?>" id="pb<?= $n ?>" maxlength="1"
                 inputmode="numeric" pattern="\d" data-idx="<?= $n ?>"
                 aria-label="Cyfra PESEL z pozycji <?= $p ?>" autocomplete="off"<?= $error ? ' aria-invalid="true" aria-describedby="ika-err"' : '' ?>>
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
      <a href="?to=<?= urlencode($return_to) ?>">
        <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Inna metoda
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
               class="form-control<?= ($page_mode === 'setup' && $error) ? ' is-invalid" aria-invalid="true" aria-describedby="ika-err' : '' ?>"
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
               class="form-control<?= ($page_mode === 'setup' && $error) ? ' is-invalid" aria-invalid="true" aria-describedby="ika-err' : '' ?>"
               style="font-family:monospace;letter-spacing:.25em;text-align:center;font-size:1.1rem"
               maxlength="6" placeholder="000000" inputmode="numeric" pattern="\d{6}" autocomplete="new-password">
      </div>
      <div class="mb-3">
        <label class="form-label fw-semibold" for="ikaks1">
          Nowy IKAKS
          <span class="text-muted fw-normal" style="font-size:.8rem">(min. 6 znaków)</span>
        </label>
        <input type="password" id="ikaks1" name="ikaks1"
               class="form-control<?= ($page_mode === 'setup' && $error) ? ' is-invalid" aria-invalid="true" aria-describedby="ika-err' : '' ?>"
               minlength="6" autocomplete="new-password" placeholder="min. 6 znaków">
      </div>
      <div class="mb-4">
        <label class="form-label fw-semibold" for="ikaks2">Powtórz IKAKS</label>
        <input type="password" id="ikaks2" name="ikaks2"
               class="form-control<?= ($page_mode === 'setup' && $error) ? ' is-invalid" aria-invalid="true" aria-describedby="ika-err' : '' ?>"
               minlength="6" autocomplete="new-password" placeholder="powtórz IKAKS">
      </div>
      <button type="submit" class="tz-btn tz-btn--setup tz-btn--wide">
        <span class="spin-icon" id="spinSetup"></span>
        <i class="bi bi-shield-check" aria-hidden="true"></i>
        Zapisz i wejdź
      </button>
    </form>
    <div class="mt-3">
      <a href="?to=<?= urlencode($return_to) ?>">
        <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Mam już kod IKA
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
        <span class="state-icon"><i class="bi bi-hourglass-split text-warning" aria-hidden="true"></i></span>
        <p>Kod IKA zablokowany z powodu błędnych prób.</p>
        <p>Odblokowanie o: <strong><?= h(date('H:i', strtotime($u_fresh['cpc_blocked_until']))) ?></strong></p>
      </div>
      <?php elseif (!$has_code): ?>
      <div class="state-box">
        <span class="state-icon"><i class="bi bi-key text-secondary" aria-hidden="true"></i></span>
        <p>Nie masz przypisanego kodu IKA.</p>
        <?php if ($has_setup_token): ?>
        <p>Masz <strong>AdminCode</strong> — możesz ustawić kody samodzielnie.</p>
        <?php elseif ($user_email): ?>
        <p>Możesz wejść jednorazowym kodem wysłanym na <strong><?= h($user_email) ?></strong>
           albo poprosić administratora o nadanie kodu IKA.</p>
        <?php else: ?>
        <p>Poproś administratora o nadanie kodu lub <strong>AdminCode</strong>.</p>
        <?php endif; ?>
      </div>
      <?php if ($has_setup_token): ?>
      <a href="?to=<?= urlencode($return_to) ?>&mode=setup"
         class="recovery-btn recovery-btn--admin">
        <i class="bi bi-shield-plus" aria-hidden="true"></i>
        <strong>Mam AdminCode</strong> — ustaw kody samodzielnie
        <i class="bi bi-arrow-right ms-auto" aria-hidden="true"></i>
      </a>
      <?php endif; ?>
      <?php if (!$has_setup_token && $user_email): ?>
      <!-- Konto bez kodu IKA nie może być ślepym zaułkiem — dajemy drogę przez e-mail. -->
      <form method="post" class="mt-2">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_mode" value="request_email_otp">
        <input type="hidden" name="to"    value="<?= h($return_to) ?>">
        <button type="submit" class="recovery-btn">
          <i class="bi bi-envelope-check" aria-hidden="true"></i>
          <span><strong>Wyślij kod na e-mail</strong> — wejdź bez kodu IKA</span>
          <i class="bi bi-arrow-right ms-auto" aria-hidden="true"></i>
        </button>
      </form>
      <?php endif; ?>

      <?php else: ?>
      <form method="post" id="ikaForm" autocomplete="off">
        <input type="hidden" name="_csrf"  value="<?= csrf_token() ?>">
        <input type="hidden" name="_mode" value="ika">
        <input type="hidden" name="to"    value="<?= h($return_to) ?>">
        <div class="ika-field">
          <label for="ikaCode" class="ika-field__label">Kod IKA</label>
          <p class="ika-field__hint" id="ikaCodeHint">6 cyfr</p>
          <input type="password" class="form-control ika-code" id="ikaCode" name="ika_code"
                 inputmode="numeric" pattern="[0-9]{6}" maxlength="6" minlength="6" required
                 autocomplete="off" data-1p-ignore data-lpignore="true"
                 aria-describedby="ikaCodeHint<?= $error ? ' ika-err' : '' ?>"
                 <?= $error && $active_method === 'ika' ? 'aria-invalid="true"' : '' ?>>
          <button type="button" class="reveal-btn" data-target="ikaCode" aria-controls="ikaCode" aria-pressed="false">
            <i class="bi bi-eye" aria-hidden="true"></i><span>Pokaż kod</span>
          </button>
        </div>
        <!-- Bez „disabled" w HTML: gdy skrypt padnie, formularz nadal da się wysłać. -->
        <button type="submit" class="tz-btn tz-btn--wide" id="btnVerify">
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
           class="recovery-btn recovery-btn--admin">
          <i class="bi bi-shield-plus" aria-hidden="true"></i>
          <strong>Mam AdminCode</strong> — ustaw nowy kod IKA
          <i class="bi bi-arrow-right ms-auto" aria-hidden="true"></i>
        </a>
        <?php endif; ?>
        <?php if ($user_email): ?>
        <a href="?to=<?= urlencode($return_to) ?>&mode=email_verify" class="recovery-btn">
          <i class="bi bi-envelope-arrow-down-fill" aria-hidden="true"></i>
          Wyślij jednorazowy kod e-mail
          <span class="recovery-sub"><?= h($user_email) ?></span>
        </a>
        <?php endif; ?>
        <?php if ($pesel_available): ?>
        <a href="?to=<?= urlencode($return_to) ?>&mode=pesel" class="recovery-btn">
          <i class="bi bi-card-text" aria-hidden="true"></i>
          Zweryfikuj cyframi PESEL
          <span class="recovery-sub">z kartoteki</span>
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
        <button type="submit" class="tz-btn tz-btn--email tz-btn--wide">
          <i class="bi bi-envelope-arrow-down-fill" aria-hidden="true"></i>
          Wyślij kod na <?= h($user_email) ?>
        </button>
      </form>
      <?php if ($pesel_available && !$show_method_tabs): ?>
      <div class="or-sep">lub</div>
      <div class="recovery-links">
        <a href="?to=<?= urlencode($return_to) ?>&mode=pesel" class="recovery-btn">
          <i class="bi bi-card-text" aria-hidden="true"></i>
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
        <div class="ika-field">
          <label for="emailOtp" class="ika-field__label">Kod z e-maila</label>
          <input type="password" class="form-control ika-code" id="emailOtp" name="email_otp"
                 inputmode="numeric" pattern="[0-9]{6}" maxlength="6" minlength="6" required
                 autocomplete="one-time-code" data-1p-ignore data-lpignore="true"
                 <?= $error ? 'aria-describedby="ika-err"' : '' ?>
                 <?= $error && $active_method === 'email' ? 'aria-invalid="true"' : '' ?>>
          <button type="button" class="reveal-btn" data-target="emailOtp" aria-controls="emailOtp" aria-pressed="false">
            <i class="bi bi-eye" aria-hidden="true"></i><span>Pokaż kod</span>
          </button>
        </div>
        <button type="submit" class="tz-btn tz-btn--email tz-btn--wide" id="btnEmailOtp">
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
          <button type="submit" class="ika-link-btn">
            <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>Wyślij nowy kod
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
        <i class="bi bi-card-text" aria-hidden="true"></i>
        Zweryfikuj cyframi PESEL
        <span class="recovery-sub">z kartoteki umów</span>
      </a>
    </div>
    <?php endif; ?>

    <?php endif; /* page_mode cases */ ?>

  </div>
</section>

<!-- ── Linki dolne ──────────────────────────────────────────────────────────── -->
<?php $back_url = match($dest_ctx['module']) {
    'CRM'      => APP_URL . '/crm/dashboard.php',
    'Dydaktyka'=> APP_URL . '/karty30/index.php',
    default    => APP_URL . '/index.php',
}; ?>
<nav class="ika-bottom" aria-label="Inne działania">
  <a href="<?= h($back_url) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Anuluj i wróć</a>
  <a href="<?= h(APP_URL) ?>/auth/report_login_issue.php"><i class="bi bi-life-preserver" aria-hidden="true"></i> Problem z kodem IKA</a>
  <a href="<?= h(APP_URL) ?>/tozsamosc/index.php"><i class="bi bi-person-vcard" aria-hidden="true"></i> System Tożsamości</a>
</nav>

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

/* ── Podgląd zamaskowanego kodu ────────────────────────── */
document.querySelectorAll('.reveal-btn').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var inp = document.getElementById(btn.dataset.target);
    if (!inp) return;
    var show = btn.getAttribute('aria-pressed') !== 'true';
    inp.type = show ? 'text' : 'password';
    btn.setAttribute('aria-pressed', show ? 'true' : 'false');
    btn.querySelector('span').textContent = show ? 'Ukryj kod' : 'Pokaż kod';
    btn.querySelector('i').className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
  });
});

/* ── Pole kodu: tylko cyfry; wysyła dopiero przycisk lub Enter (bez zmiany kontekstu przy wpisywaniu) ── */
function initCodeField(inputId, btnId, spinnerId) {
  var inp = document.getElementById(inputId), btn = document.getElementById(btnId);
  var spinner = document.getElementById(spinnerId);
  if (!inp || !btn) return;
  inp.addEventListener('input', function () {
    var v = this.value.replace(/\D/g, '').slice(0, 6);
    if (v !== this.value) this.value = v;
  });
  var form = btn.closest('form');
  if (form) form.addEventListener('submit', function () {
    if (spinner) spinner.style.display = 'inline-block';
    btn.disabled = true;
  });
  var panel = inp.closest('[id^=panel-]');
  if (!panel || !panel.hidden) inp.focus();
}

initCodeField('ikaCode',  'btnVerify',   'ikaSpinner');
initCodeField('emailOtp', 'btnEmailOtp', 'spinEmailOtp');

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
    'no_new_tiles' => true,
]);
