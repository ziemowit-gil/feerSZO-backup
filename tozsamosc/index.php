<?php
/**
 * tozsamosc/index.php — Moduł „Tożsamość" (Zarządzanie tożsamością w Entra ID).
 *
 * ODRĘBNY od „Mojego panelu". Panel = bieżąca współpraca (zadania, umowy,
 * komunikaty). Ten moduł to samoobsługowy portal tożsamości — centralny rejestr
 * użytkownika i jego kont w Entra ID / Microsoft 365, wzorowany na uczelnianych
 * portalach tożsamości (np. OPT UJ). Odpowiada wyłącznie za:
 *
 *   • Podstawowe   — dane tożsamości: numer UID, identyfikator sieciowy (login
 *                    panelowy = główny), login Microsoft 365 (może być inny),
 *                    typ konta, źródło danych (umowa).
 *   • Usługi       — dostęp do usług IT (Microsoft 365, panel SZO) + ważność.
 *   • Bezpieczeństwo — samoobsługa poświadczeń: hasło (SZO ↔ M365), numer
 *                    telefonu do SMS/MFA (weryfikacja kodem), kreator MFA.
 *
 * Numer UID = users.id — konto (i UID) powstaje automatycznie przy zawarciu
 * umowy. Login do panelu (users.email) jest GŁÓWNY; login MS365 (users.m365_login)
 * bywa inny — konto M365 wiążemy przez microsoft_id (= m365_user_id w umowie).
 *
 * Dostępność: WCAG 2.1 AA — etykiety powiązane z polami, komunikaty aria-live/
 * role=alert, widoczny fokus, obsługa klawiaturą, etykiety dwujęzyczne (PL/EN).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth_security.php';

// Osobne logowanie do modułu Tożsamość — niezalogowanych kierujemy na dedykowany
// ekran /tozsamosc/login.php (a nie na ogólne logowanie SZO).
auth_start();
if (!current_user()) {
    header('Location: ' . APP_URL . '/tozsamosc/login.php');
    exit;
}
require_login(); // egzekwuje pozostałe zabezpieczenia (np. IP guard) dla zalogowanego

$PAGE_TITLE = 'Tożsamość';
$user = current_user();
$SELF = APP_URL . '/tozsamosc/index.php';

// ── SMS dostępne? ───────────────────────────────────────────────────────────
$sms_available = false;
try {
    require_once dirname(__DIR__) . '/includes/sms.php';
    $sms_available = sms_is_enabled();
} catch (\Throwable $e) {}

// ── Samonaprawa schematu ────────────────────────────────────────────────────
try { db()->exec("ALTER TABLE users ADD COLUMN phone_verified_at DATETIME DEFAULT NULL"); } catch (\Throwable $e) {}
try { db()->exec("ALTER TABLE users ADD COLUMN allow_local_fallback INTEGER NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
try { db()->exec("ALTER TABLE users ADD COLUMN tozsamosc_seen_at DATETIME DEFAULT NULL"); } catch (\Throwable $e) {}
try { db()->exec("ALTER TABLE users ADD COLUMN ldap_created_at DATETIME DEFAULT NULL"); } catch (\Throwable $e) {}

// ── Pełny rekord + identyfikatory ───────────────────────────────────────────
$db_user = db_one("SELECT * FROM users WHERE id=?", [$user['id']]);
$uid                = (int)$db_user['id'];
$has_local_password = !empty($db_user['password']);
$panel_login        = $user['email'] ?? '';          // GŁÓWNY identyfikator sieciowy
$m365_login         = $db_user['m365_login'] ?? '';  // login MS365 (może być inny)
$microsoft_id       = $db_user['microsoft_id'] ?? '';
$has_m365           = ($microsoft_id !== '' || $m365_login !== '');

// ── Konto w katalogu LDAP (samoobsługa) ──────────────────────────────────────
$ldap_enabled = defined('LDAP_ENABLED') && LDAP_ENABLED;
$has_ldap     = !empty($db_user['ldap_created_at']);

// ── Konto M365 z umowy (dopasowanie: microsoft_id → m365_login → e-mail) ─────
$m365_row = null;
foreach ([
    ['m365_user_id = ?', $microsoft_id],
    ['m365_login = ?',    $m365_login],
    ['email = ?',         $panel_login],
] as [$cond, $val]) {
    if ($val === '' || $val === null) continue;
    $m365_row = db_one(
        "SELECT m365_user_id, m365_login, m365_konto_aktywne, m365_licencja_przypisana,
                m365_data_utworzenia, numer_umowy, data_zawarcia
         FROM umowy_wolontariat
         WHERE {$cond} AND m365_user_id != '' AND m365_konto = 1
         ORDER BY id DESC LIMIT 1",
        [$val]
    );
    if ($m365_row) break;
}
if (!$m365_row && $microsoft_id) {
    $m365_row = ['m365_user_id'=>$microsoft_id,'m365_login'=>$m365_login,'m365_konto_aktywne'=>1,
                 'm365_licencja_przypisana'=>0,'m365_data_utworzenia'=>'','numer_umowy'=>'','data_zawarcia'=>''];
}

// ── Umowa źródłowa + typ konta ──────────────────────────────────────────────
$src_contract = null; $account_type_label = 'Konto SZO';
foreach ([
    ['wolontariat','Wolontariusz'], ['zlecenie','Zleceniobiorca'],
    ['praca','Pracownik'],          ['dzielo','Wykonawca dzieła'],
] as [$t,$lbl]) {
    $email_col = ($t === 'praca') ? 'email_login' : 'email';
    try {
        $row = db_one("SELECT numer_umowy, data_zawarcia FROM umowy_{$t}
                       WHERE {$email_col} = ? ORDER BY id DESC LIMIT 1", [$panel_login]);
    } catch (\Throwable $e) { $row = null; }
    if ($row) { $src_contract = $row; $account_type_label = $lbl; break; }
}

// Pierwsza wizyta w podsystemie (info powitalne pokazywane raz).
$first_visit = empty($db_user['tozsamosc_seen_at']);

// ── Stan telefonu / MFA ─────────────────────────────────────────────────────
$phone_saved    = $db_user['phone_number'] ?? '';
$phone_verified = !empty($db_user['phone_verified_at']);
$phone_pending  = $_SESSION['sec_phone_pending'] ?? '';
$mfa_method     = $db_user['twofa_method'] ?? '';
$mfa_active     = $mfa_method !== '';
$mfa_label      = $mfa_method === 'totp' ? 'Aplikacja Authenticator'
                : ($mfa_method === 'sms' ? 'Kod SMS' : 'Nieaktywne');

// ── Rejestr czynności: RODO (co dzieje się z danymi) + historia umowy ────────
require_once dirname(__DIR__) . '/includes/rodo.php';
try { rodo_migrate(); } catch (\Throwable $e) {}
$rodo_org  = function_exists('rodo_org_data') ? rodo_org_data() : ['name'=>'','address'=>'','city'=>'','nip'=>'','krs'=>''];
$rodo_auth = [];
$rodo_ids  = [];
foreach (['wolontariat','zlecenie'] as $ctype) {
    $ids = [];
    try {
        foreach (db_all("SELECT id FROM umowy_{$ctype} WHERE email=? OR m365_login=?", [$panel_login,$panel_login]) as $r) $ids[] = (int)$r['id'];
        if ($microsoft_id) foreach (db_all("SELECT id FROM umowy_{$ctype} WHERE m365_user_id=?", [$microsoft_id]) as $r) { if (!in_array((int)$r['id'],$ids,true)) $ids[] = (int)$r['id']; }
    } catch (\Throwable $e) { $ids = []; }
    if (!$ids) continue;
    $rodo_ids[$ctype] = $ids;
    $ph = implode(',', array_fill(0, count($ids), '?'));
    try { foreach (db_all("SELECT * FROM rodo_authorizations WHERE contract_type=? AND contract_id IN ({$ph}) ORDER BY created_at DESC", array_merge([$ctype],$ids)) as $a) $rodo_auth[] = $a; } catch (\Throwable $e) {}
}
// Historia umowy — TYLKO wejścia i modyfikacje
$REG_ENTER  = ['login','cpc_verify_ok','admin_impersonate','admin_impersonate_stop'];
$REG_MODIFY = ['edit','status','renewal_create','renewed_by','user_role_change','user_password_reset','delete','note','consent_accepted'];
$rodo_history = [];
if ($rodo_ids) {
    $conds = []; $params = [];
    foreach ($rodo_ids as $ct=>$ids) { $ph = implode(',', array_fill(0,count($ids),'?')); $conds[] = "(contract_type=? AND contract_id IN ({$ph}))"; $params[] = $ct; foreach ($ids as $i) $params[] = $i; }
    $inActions = array_merge($REG_ENTER, $REG_MODIFY);
    $aph = implode(',', array_fill(0, count($inActions), '?'));
    try {
        $rodo_history = db_all(
            "SELECT contract_type, contract_id, action, note, created_at FROM contract_audit_log
             WHERE (" . implode(' OR ', $conds) . ") AND action IN ({$aph})
             ORDER BY created_at DESC LIMIT 50", array_merge($params, $inActions));
    } catch (\Throwable $e) { $rodo_history = []; }
}
$ACT = [
    'login'               => ['Wejście do konta', 'bi-box-arrow-in-right'],
    'cpc_verify_ok'       => ['Weryfikacja tożsamości', 'bi-shield-check'],
    'admin_impersonate'   => ['Wejście administratora', 'bi-people'],
    'admin_impersonate_stop'=> ['Koniec wejścia administratora', 'bi-people'],
    'edit'                => ['Modyfikacja danych umowy', 'bi-pencil'],
    'status'              => ['Zmiana statusu umowy', 'bi-flag'],
    'renewal_create'      => ['Przedłużenie umowy', 'bi-arrow-repeat'],
    'renewed_by'          => ['Przedłużenie umowy', 'bi-arrow-repeat'],
    'user_role_change'    => ['Zmiana roli', 'bi-person-gear'],
    'user_password_reset' => ['Reset hasła', 'bi-key'],
    'consent_accepted'    => ['Akceptacja zgody', 'bi-check2-square'],
    'note'                => ['Notatka', 'bi-sticky'],
    'delete'              => ['Usunięcie', 'bi-trash'],
];

// ── IKA (Indywidualny Kod Autoryzacyjny) — zmiana z podsystemu ──────────────
require_once dirname(__DIR__) . '/includes/cpc.php';
try { cpc_migrate(); } catch (\Throwable $e) {}
$has_ika     = !empty($db_user['cpc_code']);
$ika_blocked = !empty($db_user['cpc_blocked_until']) && $db_user['cpc_blocked_until'] > date('Y-m-d H:i:s');

$errors = [];

/** Maskuje numer: +48 ••• ••• 200. */
function tz_mask_phone(string $p): string {
    $d = preg_replace('/\D/', '', $p);
    if (strlen($d) < 3) return $p;
    return '+' . substr($d, 0, max(0, strlen($d) - 9)) . ' ••• ••• ' . substr($d, -3);
}

// ═══════════════════════ OBSŁUGA POST ═══════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    if ($action === 'dismiss_intro') {
        db()->prepare("UPDATE users SET tozsamosc_seen_at=datetime('now') WHERE id=? AND tozsamosc_seen_at IS NULL")->execute([$uid]);
        header('Location: ' . $SELF); exit;
    }
    elseif ($action === 'change_ika') {
        if (empty($db_user['cpc_code'])) {
            $errors[] = 'Nie masz przypisanego kodu IKA.';
        } else {
            $cur = preg_replace('/\D/', '', $_POST['ika_current'] ?? '');
            $new = preg_replace('/\D/', '', $_POST['ika_new'] ?? '');
            $cnf = preg_replace('/\D/', '', $_POST['ika_confirm'] ?? '');
            $v = cpc_verify($uid, $cur);
            if (!empty($v['blocked'])) {
                $errors[] = 'Kod IKA jest tymczasowo zablokowany po błędnych próbach. Spróbuj później.';
            } elseif (empty($v['ok'])) {
                $errors[] = 'Aktualny kod IKA jest nieprawidłowy.';
            } elseif (!preg_match('/^\d{6}$/', $new)) {
                $errors[] = 'Nowy kod IKA musi składać się dokładnie z 6 cyfr.';
            } elseif ($new !== $cnf) {
                $errors[] = 'Nowe kody IKA nie są identyczne.';
            } elseif ($new === $cur) {
                $errors[] = 'Nowy kod musi różnić się od obecnego.';
            } else {
                db()->prepare("UPDATE users SET cpc_code=?, cpc_fails=0, cpc_blocked_until=NULL WHERE id=?")->execute([$new, $uid]);
                authlog_write($uid, 'ika_changed', $user['email'] ?? '', 'Zmiana kodu IKA z podsystemu Tożsamość');
                flash_set('success', 'Kod IKA został zmieniony.');
                header('Location: ' . $SELF . '#ika'); exit;
            }
        }
    }
    elseif ($action === 'phone_send') {
        if (!$sms_available) {
            $errors[] = 'Wysyłka SMS jest obecnie niedostępna. Skontaktuj się z administratorem.';
        } else {
            $raw = preg_replace('/\D/', '', $_POST['phone_number'] ?? '');
            if (strlen($raw) < 9) {
                $errors[] = 'Podaj poprawny 9-cyfrowy numer telefonu komórkowego.';
            } else {
                $norm = sms_normalize_phone($_POST['phone_number'] ?? '');
                try {
                    $otp = sms_generate_otp($norm, $uid);
                    $via = sms_send_with_fallback($norm, "Kod weryfikacyjny numeru telefonu: {$otp} (ważny 5 min). Nie udostępniaj go nikomu.", $user['email'] ?? '');
                    $_SESSION['sec_phone_pending'] = $norm;
                    flash_set($via === 'email' ? 'warning' : 'success',
                        $via === 'email'
                          ? 'Nie udało się wysłać SMS — kod wysłano na Twój adres e-mail. Sprawdź skrzynkę.'
                          : 'Wysłaliśmy 6-cyfrowy kod SMS na numer ' . tz_mask_phone($norm) . '.');
                    header('Location: ' . $SELF . '#bezpieczenstwo'); exit;
                } catch (\Throwable $e) {
                    $errors[] = 'Błąd wysyłki kodu: ' . $e->getMessage();
                }
            }
        }
    }
    elseif ($action === 'phone_verify') {
        $norm = $_SESSION['sec_phone_pending'] ?? '';
        $code = preg_replace('/\D/', '', $_POST['code'] ?? '');
        if (!$norm) {
            $errors[] = 'Sesja weryfikacji wygasła. Zacznij ponownie.';
        } else {
            $verified = sms_verify_otp($norm, $code);
            if ($verified && (int)$verified['id'] === $uid) {
                db()->prepare("UPDATE users SET phone_number=?, phone_verified_at=datetime('now') WHERE id=?")
                    ->execute([$norm, $uid]);
                unset($_SESSION['sec_phone_pending']);
                authlog_write($uid, 'phone_verified', $user['email'] ?? '', 'Zweryfikowano numer telefonu: ' . $norm);
                flash_set('success', 'Numer telefonu został zweryfikowany i zapisany.');
                header('Location: ' . $SELF . '#bezpieczenstwo'); exit;
            } else {
                $errors[] = 'Nieprawidłowy lub wygasły kod. Spróbuj ponownie lub wyślij nowy.';
            }
        }
    }
    elseif ($action === 'phone_cancel') {
        unset($_SESSION['sec_phone_pending']);
        header('Location: ' . $SELF . '#bezpieczenstwo'); exit;
    }
    elseif ($action === 'phone_remove') {
        db()->prepare("UPDATE users SET phone_number='', phone_verified_at=NULL WHERE id=?")->execute([$uid]);
        unset($_SESSION['sec_phone_pending']);
        authlog_write($uid, 'phone_removed', $user['email'] ?? '', 'Usunięto numer telefonu konta');
        flash_set('success', 'Numer telefonu został usunięty.');
        header('Location: ' . $SELF . '#bezpieczenstwo'); exit;
    }
    elseif ($action === 'pwd_change') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if ($has_local_password && !password_verify($current, $db_user['password'])) {
            $errors[] = 'Aktualne hasło jest nieprawidłowe.';
        }
        $name_parts = array_filter(preg_split('/[\s@.\-]+/', mb_strtolower(($user['name'] ?? '') . ' ' . $panel_login)),
                                   fn($p) => mb_strlen($p) > 2);
        if (mb_strlen($new) < 12)                    $errors[] = 'Hasło musi mieć co najmniej 12 znaków.';
        if (!preg_match('/[A-ZĄĆĘŁŃÓŚŹŻ]/u', $new))  $errors[] = 'Hasło musi zawierać wielką literę.';
        if (!preg_match('/[a-ząćęłńóśźż]/u', $new))  $errors[] = 'Hasło musi zawierać małą literę.';
        if (!preg_match('/[0-9]/', $new))            $errors[] = 'Hasło musi zawierać cyfrę.';
        if (!preg_match('/[^A-Za-z0-9]/', $new))     $errors[] = 'Hasło musi zawierać znak specjalny.';
        foreach ($name_parts as $p) {
            if (mb_stripos($new, $p) !== false) { $errors[] = 'Hasło nie może zawierać Twojego imienia, nazwiska ani loginu.'; break; }
        }
        if ($new !== $confirm) $errors[] = 'Hasła nie są identyczne.';

        if (!$errors) {
            $hash = password_hash($new, PASSWORD_BCRYPT);
            db()->prepare("UPDATE users SET password=?, must_change_password=0, allow_local_fallback=1 WHERE id=?")
                ->execute([$hash, $uid]);
            auth_clear_force_password($uid);
            authlog_write($uid, 'pwd_changed', $user['email'] ?? '', 'Zmiana hasła (SZO)');

            $sync_msg = 'Hasło zaktualizowane w panelu SZO.';
            if ($m365_row && !empty($m365_row['m365_user_id'])) {
                require_once dirname(__DIR__) . '/includes/m365.php';
                $enabled = m365_setting('m365_enabled') === '1';
                $tid = m365_setting('m365_tenant_id');
                $cid = m365_setting('m365_graph_client_id');
                $sec = m365_setting('m365_graph_client_secret');
                if ($enabled && $tid && $cid && $sec) {
                    try {
                        $m365 = new M365Graph(['tenant_id'=>$tid,'client_id'=>$cid,'client_secret'=>$sec]);
                        $m365->set_password($m365_row['m365_user_id'], $new, false);
                        log_user_action($uid, $uid, 'm365_password_reset', 'Synchronizacja hasła M365: ' . ($m365_row['m365_login'] ?? ''));
                        $sync_msg .= ' Zsynchronizowano z Microsoft 365.';
                    } catch (\Throwable $e) {
                        flash_set('warning', $sync_msg . ' UWAGA: synchronizacja z Microsoft 365 nie powiodła się (' . $e->getMessage() . '). Hasło lokalne zostało zmienione.');
                        header('Location: ' . $SELF . '#bezpieczenstwo'); exit;
                    }
                } else {
                    $sync_msg .= ' (Integracja M365 nieskonfigurowana — pominięto synchronizację.)';
                }
            }
            flash_set('success', $sync_msg);
            header('Location: ' . $SELF . '#bezpieczenstwo'); exit;
        }
    }
    elseif ($action === 'ldap_create') {
        // Samodzielne utworzenie/odświeżenie własnego wpisu w katalogu LDAP.
        if (!(defined('LDAP_ENABLED') && LDAP_ENABLED)) {
            $errors[] = 'Usługa katalogu LDAP jest obecnie niedostępna.';
        } else {
            require_once dirname(__DIR__) . '/includes/ldap.php';
            try {
                $ldap = new LdapDirectory();
                if (!$ldap->is_configured()) {
                    throw new RuntimeException('Katalog LDAP nie jest skonfigurowany. Skontaktuj się z administratorem.');
                }
                $row = ldap_user_row($uid) ?? $db_user;
                $ldap->connect();
                $done = $ldap->upsert_user($row);
                $ldap->close();

                db()->prepare("UPDATE users SET ldap_created_at=datetime('now') WHERE id=? AND ldap_created_at IS NULL")
                    ->execute([$uid]);
                authlog_write($uid, 'ldap_created', $user['email'] ?? '',
                    'Samodzielne ' . ($done === 'created' ? 'utworzenie' : 'odświeżenie') . ' konta LDAP z modułu Tożsamość');
                flash_set('success', $done === 'created'
                    ? 'Konto w katalogu LDAP zostało utworzone.'
                    : 'Wpis w katalogu LDAP został zaktualizowany.');
                header('Location: ' . $SELF . '#uslugi'); exit;
            } catch (\Throwable $e) {
                error_log('[LDAP self-service] uid=' . $uid . ': ' . $e->getMessage());
                $errors[] = 'Nie udało się utworzyć konta LDAP: ' . $e->getMessage();
            }
        }
    }
}

$initials = mb_strtoupper(mb_substr($user['name'] ?? 'U', 0, 1));
if (preg_match('/\s(\S)/u', $user['name'] ?? '', $m2)) $initials .= mb_strtoupper($m2[1]);

$PAGE_TITLE = 'Tożsamość';
$TZ_ACTIVE  = 'konto';
include __DIR__ . '/_head.php';   // własny chrome podsystemu (bez menu SZO)
?>

<div class="tz-h">
  <h1><i class="bi bi-person-vcard me-2" style="color:#1E6DFF" aria-hidden="true"></i>Tożsamość</h1>
  <p>Zarządzanie tożsamością w Entra ID · Twoje konto i dostępy</p>
</div>

<?php if ($first_visit): ?>
<!-- Powitanie po pierwszym logowaniu (pokazywane raz) -->
<section class="tz-card" style="border:2px solid #1E6DFF" aria-labelledby="welcome-h">
  <div class="tz-card__bd d-flex flex-wrap align-items-center gap-3">
    <i class="bi bi-stars fs-3" style="color:#1E6DFF" aria-hidden="true"></i>
    <div class="flex-grow-1" style="min-width:220px">
      <div class="fw-bold" id="welcome-h">Witaj w Systemie Tożsamości 👋</div>
      <div class="text-muted small">To Twoje pierwsze wejście. Zajrzyj do zakładki <strong>„O panelu"</strong> — w 20 sekund zrozumiesz, do czego to służy (a do czego nie).</div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <a href="#opanelu" class="tz-btn"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Zobacz „O panelu"</a>
      <form method="post" class="m-0">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_action" value="dismiss_intro">
        <button type="submit" class="tz-btn tz-btn--ghost">Rozumiem, nie pokazuj</button>
      </form>
    </div>
  </div>
</section>
<?php endif; ?>

<?= function_exists('flash_html') ? flash_html() : '' ?>

<div aria-live="assertive">
<?php if ($errors): ?>
<div class="alert alert-danger" role="alert">
  <strong><i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>Popraw następujące błędy:</strong>
  <ul class="mb-0 mt-1"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>
</div>

<nav class="tz-subnav" aria-label="Sekcje tożsamości">
  <span class="seg" role="tablist">
    <a href="#podstawowe" class="on" role="tab" id="tab-podstawowe" aria-controls="podstawowe" aria-selected="true"><i class="bi bi-person-badge" aria-hidden="true"></i>Podstawowe</a>
    <a href="#uslugi" role="tab" id="tab-uslugi" aria-controls="uslugi" aria-selected="false" tabindex="-1"><i class="bi bi-grid-3x3-gap" aria-hidden="true"></i>Usługi</a>
    <a href="#bezpieczenstwo" role="tab" id="tab-bezpieczenstwo" aria-controls="bezpieczenstwo" aria-selected="false" tabindex="-1"><i class="bi bi-shield-lock" aria-hidden="true"></i>Bezpieczeństwo</a>
    <?php if ($has_ika): ?>
    <a href="#ika" role="tab" id="tab-ika" aria-controls="ika" aria-selected="false" tabindex="-1"><i class="bi bi-key" aria-hidden="true"></i>Zmień IKA</a>
    <?php endif; ?>
    <a href="#rejestr" role="tab" id="tab-rejestr" aria-controls="rejestr" aria-selected="false" tabindex="-1"><i class="bi bi-clock-history" aria-hidden="true"></i>Rejestr czynności</a>
    <a href="#opanelu" role="tab" id="tab-opanelu" aria-controls="opanelu" aria-selected="false" tabindex="-1"><i class="bi bi-info-circle" aria-hidden="true"></i>O panelu</a>
  </span>
</nav>

<!-- ═══════════ PODSTAWOWE ═══════════ -->
<section class="tz-card tz-panel active" id="podstawowe" role="tabpanel" aria-labelledby="tab-podstawowe" tabindex="-1">
  <h2 id="pod-h" class="visually-hidden">Podstawowe dane tożsamości</h2>
  <div class="tz-id">
    <div class="d-flex align-items-center gap-3">
      <span class="tz-ava" aria-hidden="true"><?= h($initials) ?></span>
      <div>
        <div style="font-weight:600;font-size:1.05rem;line-height:1.2"><?= h($user['name']) ?></div>
        <div style="font-size:.78rem;opacity:.8"><?= h($account_type_label) ?> · Organizational identity</div>
      </div>
    </div>
    <div class="text-end">
      <div style="font-size:.7rem;opacity:.75;text-transform:uppercase;letter-spacing:.05em">Numer UID</div>
      <div class="tz-uid"><?= h($uid) ?></div>
    </div>
  </div>
  <dl class="tz-dl mb-0">
    <div>
      <dt>Identyfikator sieciowy <span class="lbl-en">Network ID (login główny)</span></dt>
      <dd><?= h($panel_login ?: '—') ?></dd>
    </div>
    <div>
      <dt>Login Microsoft 365 <span class="lbl-en">M365 sign-in</span></dt>
      <dd><?= h($m365_login ?: ($has_m365 ? $panel_login : '— brak konta M365 —')) ?></dd>
    </div>
    <div>
      <dt>Identyfikator obiektu Entra <span class="lbl-en">Entra object ID</span></dt>
      <dd style="font-size:.78rem;font-family:ui-monospace,monospace"><?= h($microsoft_id ?: '—') ?></dd>
    </div>
    <div><dt>Typ konta</dt><dd><?= h($account_type_label) ?></dd></div>
    <div><dt>Źródło danych <span class="lbl-en">Data source</span></dt><dd>Umowa <?= h($src_contract['numer_umowy'] ?? '—') ?></dd></div>
    <div><dt>UID nadany <span class="lbl-en">Assigned</span></dt><dd><?= h(!empty($db_user['created_at']) ? date('d.m.Y', strtotime($db_user['created_at'])) : ($src_contract['data_zawarcia'] ?? '—')) ?></dd></div>
  </dl>
  <p class="tz-note mb-0">
    <i class="bi bi-info-circle" aria-hidden="true"></i>
    <span>Numer UID został nadany automatycznie przy zawarciu umowy i jest Twoim stałym identyfikatorem w rejestrze tożsamości — nie można go zmienić. Login do panelu (identyfikator sieciowy) jest niezależny od loginu Microsoft 365.
    <span class="lbl-en">UID is assigned at contract creation and is immutable. The panel sign-in may differ from the Microsoft 365 sign-in.</span></span>
  </p>
</section>

<!-- ═══════════ USŁUGI ═══════════ -->
<section class="tz-card tz-panel" id="uslugi" role="tabpanel" aria-labelledby="tab-uslugi" tabindex="-1">
  <div class="tz-card__hd" id="usl-h">
    <i class="bi bi-grid-3x3-gap" aria-hidden="true"></i>
    <span>Usługi <span class="lbl-en">Services &amp; access validity</span></span>
  </div>
  <div class="tz-card__bd py-2">
    <!-- Panel SZO -->
    <div class="tz-svc">
      <span class="tz-svc__ico"><i class="bi bi-window-desktop" aria-hidden="true"></i></span>
      <div class="flex-grow-1">
        <div class="fw-semibold">Panel SZO <span class="tz-badge tz-badge--ok ms-1"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Aktywny</span></div>
        <div class="text-muted small">Logowanie: <?= h($panel_login) ?> · dostęp bezterminowy w okresie obowiązywania umowy</div>
      </div>
    </div>
    <!-- Microsoft 365 -->
    <div class="tz-svc">
      <span class="tz-svc__ico"><i class="bi bi-microsoft" aria-hidden="true"></i></span>
      <div class="flex-grow-1">
        <div class="fw-semibold">Microsoft 365 / Entra ID
          <?php if ($has_m365 && !empty($m365_row['m365_konto_aktywne'])): ?>
            <span class="tz-badge tz-badge--ok ms-1"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Aktywne</span>
          <?php elseif ($has_m365): ?>
            <span class="tz-badge tz-badge--warn ms-1"><i class="bi bi-pause-circle-fill" aria-hidden="true"></i> Zawieszone</span>
          <?php else: ?>
            <span class="tz-badge tz-badge--off ms-1">Brak konta</span>
          <?php endif; ?>
        </div>
        <div class="text-muted small">
          <?php if ($has_m365): ?>
            Login: <?= h($m365_login ?: $panel_login) ?>
            · Licencja: <?= !empty($m365_row['m365_licencja_przypisana']) ? 'przypisana' : 'brak' ?>
            <?php if (!empty($m365_row['m365_data_utworzenia'])): ?> · Utworzone: <?= h(date('d.m.Y', strtotime($m365_row['m365_data_utworzenia']))) ?><?php endif; ?>
          <?php else: ?>
            Konto Microsoft 365 nie zostało utworzone dla tej tożsamości.
          <?php endif; ?>
        </div>
      </div>
      <a href="<?= APP_URL ?>/panel/m365.php" class="tz-btn--ghost tz-btn btn-sm">Szczegóły</a>
    </div>
    <!-- Katalog LDAP -->
    <div class="tz-svc">
      <span class="tz-svc__ico"><i class="bi bi-diagram-3" aria-hidden="true"></i></span>
      <div class="flex-grow-1">
        <div class="fw-semibold">Katalog LDAP
          <?php if ($has_ldap): ?>
            <span class="tz-badge tz-badge--ok ms-1"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Utworzone</span>
          <?php elseif ($ldap_enabled): ?>
            <span class="tz-badge tz-badge--warn ms-1"><i class="bi bi-plus-circle-fill" aria-hidden="true"></i> Do utworzenia</span>
          <?php else: ?>
            <span class="tz-badge tz-badge--off ms-1"><i class="bi bi-slash-circle" aria-hidden="true"></i> Nieaktywna</span>
          <?php endif; ?>
        </div>
        <div class="text-muted small">
          <?php if ($has_ldap): ?>
            Wpis w katalogu organizacji (uid=<?= h($uid) ?>)
            <?php if (!empty($db_user['ldap_created_at'])): ?> · utworzono <?= h(date('d.m.Y', strtotime($db_user['ldap_created_at']))) ?><?php endif; ?>
          <?php elseif ($ldap_enabled): ?>
            Utwórz swój wpis w katalogu LDAP organizacji na podstawie danych tożsamości. Hasło nie jest kopiowane.
          <?php else: ?>
            Usługa katalogu LDAP jest tymczasowo nieaktywna.
          <?php endif; ?>
        </div>
      </div>
      <?php if ($ldap_enabled && !$has_ldap): ?>
      <form method="post" class="m-0">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_action" value="ldap_create">
        <button type="submit" class="tz-btn btn-sm" onclick="return confirm('Utworzyć konto w katalogu LDAP na podstawie Twoich danych tożsamości?');">
          <i class="bi bi-plus-lg" aria-hidden="true"></i> Utwórz konto
        </button>
      </form>
      <?php elseif ($has_ldap): ?>
      <form method="post" class="m-0">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_action" value="ldap_create">
        <button type="submit" class="tz-btn--ghost tz-btn btn-sm">
          <i class="bi bi-arrow-repeat" aria-hidden="true"></i> Odśwież wpis
        </button>
      </form>
      <?php endif; ?>
    </div>
    <!-- MFA -->
    <div class="tz-svc">
      <span class="tz-svc__ico"><i class="bi bi-shield-check" aria-hidden="true"></i></span>
      <div class="flex-grow-1">
        <div class="fw-semibold">Uwierzytelnianie wieloskładnikowe (MFA)
          <span class="tz-badge <?= $mfa_active ? 'tz-badge--ok' : 'tz-badge--warn' ?> ms-1">
            <i class="bi <?= $mfa_active ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill' ?>" aria-hidden="true"></i>
            <?= $mfa_active ? h($mfa_label) : 'Wymaga konfiguracji' ?>
          </span>
        </div>
        <div class="text-muted small">Drugi składnik logowania chroniący Twoją tożsamość.</div>
      </div>
      <a href="#bezpieczenstwo" class="tz-btn--ghost tz-btn btn-sm">Konfiguruj</a>
    </div>
    <!-- Dostęp do komputerów FEER -->
    <div class="tz-svc" style="opacity:.75">
      <span class="tz-svc__ico"><i class="bi bi-pc-display" aria-hidden="true"></i></span>
      <div class="flex-grow-1">
        <div class="fw-semibold">Dostęp do komputerów FEER
          <span class="tz-badge tz-badge--off ms-1"><i class="bi bi-slash-circle" aria-hidden="true"></i> Nieaktywna</span>
        </div>
        <div class="text-muted small">Usługa tymczasowo nieaktywna.</div>
      </div>
      <button type="button" class="tz-btn--ghost tz-btn btn-sm" disabled aria-disabled="true" style="opacity:.6;cursor:not-allowed">Wkrótce</button>
    </div>
  </div>
</section>

<!-- ═══════════ BEZPIECZEŃSTWO ═══════════ -->
<section class="tz-card tz-panel" id="bezpieczenstwo" role="tabpanel" aria-labelledby="tab-bezpieczenstwo" tabindex="-1">
  <div class="tz-card__hd" id="bez-h">
    <i class="bi bi-shield-lock" aria-hidden="true"></i>
    <span>Bezpieczeństwo <span class="lbl-en">Credentials &amp; sign-in</span></span>
  </div>
  <div class="tz-card__bd">

    <!-- ── HASŁO ── -->
    <h3 class="h6 fw-bold mb-2"><i class="bi bi-key me-1" style="color:#1E6DFF" aria-hidden="true"></i>Zmiana hasła <span class="lbl-en d-inline">Change / reconcile password</span></h3>
    <div class="d-inline-flex align-items-center gap-2 mb-3 px-3 py-2 rounded" style="background:#eef4ff;color:#1656d6;font-size:.85rem;font-weight:600">
      <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
      Podwójna synchronizacja: Panel SZO
      <?php if ($has_m365): ?>↔ Microsoft 365 / Entra ID<?php else: ?>(konto lokalne)<?php endif; ?>
    </div>

    <form method="post" id="pwdForm" class="mb-4">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_action" value="pwd_change">
      <?php if ($has_local_password): ?>
      <div class="mb-3" style="max-width:420px">
        <label for="pw_cur" class="form-label fw-semibold">Obecne hasło <span class="lbl-en">Current password</span></label>
        <input type="password" id="pw_cur" name="current_password" class="form-control" autocomplete="current-password" required>
      </div>
      <?php else: ?>
      <div class="alert alert-info small" role="note">
        <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
        Twoja tożsamość nie ma jeszcze hasła lokalnego SZO. Ustaw je poniżej — będzie działać także jako hasło awaryjne obok logowania Microsoft.
      </div>
      <?php endif; ?>
      <div class="mb-3" style="max-width:420px">
        <label for="pw_new" class="form-label fw-semibold">Nowe hasło <span class="lbl-en">New password</span></label>
        <div class="input-group">
          <input type="password" id="pw_new" name="new_password" class="form-control" autocomplete="new-password"
                 aria-describedby="pw_req" minlength="12" required>
          <button class="btn btn-outline-secondary" type="button" id="pw_toggle"
                  aria-label="Pokaż lub ukryj hasło" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
        </div>
        <div class="tz-meter" aria-hidden="true"><span id="pw_meter"></span></div>
        <div class="form-text" id="pw_strength" aria-live="polite">Siła hasła · Password strength</div>
      </div>
      <div class="mb-3" style="max-width:420px">
        <label for="pw_conf" class="form-label fw-semibold">Powtórz nowe hasło <span class="lbl-en">Confirm new password</span></label>
        <input type="password" id="pw_conf" name="confirm_password" class="form-control" autocomplete="new-password" minlength="12" required>
        <div class="form-text text-danger" id="pw_match" hidden>Hasła nie są identyczne.</div>
      </div>
      <fieldset class="mb-3" style="max-width:640px">
        <legend class="form-label fw-semibold" style="font-size:.85rem">Polityka haseł organizacji <span class="lbl-en">Entra ID password policy</span></legend>
        <ul class="tz-req" id="pw_req" aria-live="polite">
          <li data-r="len"><span class="dot" aria-hidden="true">○</span> Min. 12 znaków</li>
          <li data-r="up"><span class="dot" aria-hidden="true">○</span> Wielka litera (A–Z)</li>
          <li data-r="low"><span class="dot" aria-hidden="true">○</span> Mała litera (a–z)</li>
          <li data-r="num"><span class="dot" aria-hidden="true">○</span> Cyfra (0–9)</li>
          <li data-r="sym"><span class="dot" aria-hidden="true">○</span> Znak specjalny (!@#…)</li>
          <li data-r="name"><span class="dot" aria-hidden="true">○</span> Nie zawiera nazwy konta</li>
        </ul>
      </fieldset>
      <button type="submit" class="tz-btn"><i class="bi bi-key" aria-hidden="true"></i> Zmień hasło</button>
    </form>

    <hr class="my-4">

    <!-- ── TELEFON ── -->
    <h3 class="h6 fw-bold mb-2"><i class="bi bi-phone me-1" style="color:#1E6DFF" aria-hidden="true"></i>Numer telefonu dla SMS
      <span class="lbl-en d-inline">Recovery / MFA phone</span>
      <?php if ($phone_saved): ?>
        <span class="tz-badge <?= $phone_verified ? 'tz-badge--ok' : 'tz-badge--warn' ?> ms-1">
          <i class="bi <?= $phone_verified ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill' ?>" aria-hidden="true"></i>
          <?= $phone_verified ? 'Zweryfikowany' : 'Niezweryfikowany' ?>
        </span>
      <?php endif; ?>
    </h3>
    <p class="text-muted small">Numer służy do odzyskiwania dostępu (kod SMS do zdefiniowania nowego hasła) oraz powiadomień MFA.</p>

    <?php if (!$sms_available): ?>
      <div class="alert alert-warning" role="alert"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Weryfikacja SMS jest obecnie niedostępna.</div>
    <?php endif; ?>

    <?php if ($phone_saved && !$phone_pending): ?>
      <p class="mb-3">Aktualny numer: <strong><?= h(tz_mask_phone($phone_saved)) ?></strong></p>
    <?php endif; ?>

    <?php if ($phone_pending): ?>
      <p>Wpisz 6-cyfrowy kod wysłany na numer <strong><?= h(tz_mask_phone($phone_pending)) ?></strong>. <span class="lbl-en d-inline">Enter the SMS code.</span></p>
      <form method="post" class="mt-2">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_action" value="phone_verify">
        <div class="mb-3" style="max-width:320px">
          <label for="tel_code" class="form-label fw-semibold">Kod weryfikacyjny</label>
          <input type="text" id="tel_code" name="code" class="form-control tz-otp" inputmode="numeric"
                 autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" placeholder="000000"
                 aria-describedby="tel_code_help" autofocus required>
          <div id="tel_code_help" class="form-text">Kod jest ważny 5 minut.</div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <button type="submit" class="tz-btn"><i class="bi bi-check2-circle" aria-hidden="true"></i> Zweryfikuj</button>
          <button type="submit" form="tel_resend" class="tz-btn tz-btn--ghost"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Wyślij ponownie</button>
          <button type="submit" form="tel_cancel" class="btn btn-link text-muted">Anuluj</button>
        </div>
      </form>
      <form method="post" id="tel_resend" class="d-none">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="_action" value="phone_send">
        <input type="hidden" name="phone_number" value="<?= h($phone_pending) ?>">
      </form>
      <form method="post" id="tel_cancel" class="d-none">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="_action" value="phone_cancel">
      </form>
    <?php else: ?>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_action" value="phone_send">
        <div class="mb-3" style="max-width:360px">
          <label for="tel_num" class="form-label fw-semibold">Numer komórkowy <span class="lbl-en">Mobile number</span></label>
          <div class="input-group">
            <span class="input-group-text" id="tel_prefix">+48</span>
            <input type="tel" id="tel_num" name="phone_number" class="form-control" inputmode="numeric"
                   autocomplete="tel-national" value="<?= h(substr(preg_replace('/\D/','',$phone_saved), -9)) ?>"
                   placeholder="600 100 200" aria-describedby="tel_prefix tel_help" required>
          </div>
          <div id="tel_help" class="form-text">Wyślemy 6-cyfrowy kod SMS. Standardowe opłaty operatora mogą obowiązywać.</div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <button type="submit" class="tz-btn"><i class="bi bi-send" aria-hidden="true"></i> Wyślij kod weryfikacyjny</button>
          <?php if ($phone_saved): ?>
          <button type="submit" form="tel_remove" class="btn btn-outline-danger" onclick="return confirm('Usunąć numer telefonu z tożsamości?')"><i class="bi bi-trash" aria-hidden="true"></i> Usuń numer</button>
          <?php endif; ?>
        </div>
      </form>
      <?php if ($phone_saved): ?>
      <form method="post" id="tel_remove" class="d-none">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="_action" value="phone_remove">
      </form>
      <?php endif; ?>
    <?php endif; ?>

    <hr class="my-4">

    <!-- ── MFA ── -->
    <h3 class="h6 fw-bold mb-2"><i class="bi bi-shield-check me-1" style="color:#1E6DFF" aria-hidden="true"></i>Konfiguracja telefonu i MFA <span class="lbl-en d-inline">Set up authenticator</span></h3>
    <p class="text-muted small mb-2">Sparuj smartfon z kontem organizacji. Zalecamy aplikację <strong>Microsoft Authenticator</strong>.</p>
    <div class="tz-tiles">
      <a class="tz-tile" href="<?= APP_URL ?>/tozsamosc/mfa.php">
        <span class="tz-tile__ico"><i class="bi bi-phone" aria-hidden="true"></i></span>
        <span class="fw-semibold d-block">Aplikacja Authenticator</span>
        <span class="lbl-en">Authenticator app (TOTP)</span>
        <span class="d-block text-muted mt-1" style="font-size:.85rem">Kreator: instalacja → skan kodu QR → potwierdzenie.</span>
      </a>
      <?php if ($sms_available): ?>
      <a class="tz-tile" href="<?= APP_URL ?>/tozsamosc/mfa.php">
        <span class="tz-tile__ico"><i class="bi bi-chat-dots" aria-hidden="true"></i></span>
        <span class="fw-semibold d-block">Kod SMS</span>
        <span class="lbl-en">Text message code</span>
        <span class="d-block text-muted mt-1" style="font-size:.85rem">Jednorazowy kod na zweryfikowany numer telefonu.</span>
      </a>
      <?php endif; ?>
    </div>
    <a href="<?= APP_URL ?>/tozsamosc/mfa.php" class="tz-btn"><i class="bi bi-shield-plus" aria-hidden="true"></i> Otwórz kreator MFA</a>
  </div>
</section>

<?php if ($has_ika): ?>
<!-- ═══════════ ZMIEŃ IKA ═══════════ -->
<section class="tz-card tz-panel" id="ika" role="tabpanel" aria-labelledby="tab-ika" tabindex="-1">
  <div class="tz-card__hd" id="ika-h">
    <i class="bi bi-key" aria-hidden="true"></i>
    <span>Zmień kod IKA <span class="lbl-en">Change your access code</span></span>
  </div>
  <div class="tz-card__bd">
    <p class="text-muted small mb-3">
      <strong>IKA</strong> (Indywidualny Kod Autoryzacyjny) to 6-cyfrowy kod potwierdzający Twoją tożsamość
      przy dostępie do chronionych danych (np. umów). Aby go zmienić, podaj obecny kod i ustaw nowy.
    </p>

    <?php if ($ika_blocked): ?>
    <div class="alert alert-warning" role="alert">
      <i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>
      Kod IKA jest tymczasowo zablokowany po błędnych próbach. Spróbuj ponownie później.
    </div>
    <?php endif; ?>

    <form method="post" style="max-width:340px">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_action" value="change_ika">
      <div class="mb-3">
        <label for="ika_current" class="form-label fw-semibold">Obecny kod IKA</label>
        <input type="text" id="ika_current" name="ika_current" class="form-control tz-otp"
               inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="••••••" autocomplete="off" required <?= $ika_blocked ? 'disabled' : '' ?>>
      </div>
      <div class="mb-3">
        <label for="ika_new" class="form-label fw-semibold">Nowy kod IKA</label>
        <input type="text" id="ika_new" name="ika_new" class="form-control tz-otp"
               inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="000000" autocomplete="off" required <?= $ika_blocked ? 'disabled' : '' ?>>
      </div>
      <div class="mb-3">
        <label for="ika_confirm" class="form-label fw-semibold">Powtórz nowy kod IKA</label>
        <input type="text" id="ika_confirm" name="ika_confirm" class="form-control tz-otp"
               inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="000000" autocomplete="off" required <?= $ika_blocked ? 'disabled' : '' ?>>
      </div>
      <button type="submit" class="tz-btn" <?= $ika_blocked ? 'disabled' : '' ?>><i class="bi bi-key" aria-hidden="true"></i> Zmień kod IKA</button>
    </form>

    <p class="tz-note mt-3 mb-0" style="border:0;padding-left:0">
      <i class="bi bi-info-circle" aria-hidden="true"></i>
      <span>Kodu IKA nie udostępniaj nikomu. Jeśli go nie pamiętasz, zresetujesz go przy bramie IKA (AdminCode od administratora, kod e-mail lub PESEL).</span>
    </p>
  </div>
</section>
<?php endif; ?>

<!-- ═══════════ REJESTR CZYNNOŚCI ═══════════ -->
<section class="tz-card tz-panel" id="rejestr" role="tabpanel" aria-labelledby="tab-rejestr" tabindex="-1">
  <div class="tz-card__hd" id="rej-h">
    <i class="bi bi-clock-history" aria-hidden="true"></i>
    <span>Rejestr czynności <span class="lbl-en">Record of processing &amp; activity</span></span>
  </div>
  <div class="tz-card__bd">

    <!-- Co dzieje się z Twoimi danymi -->
    <h3 class="h6 fw-bold mb-2"><i class="bi bi-info-circle me-1" style="color:#1E6DFF" aria-hidden="true"></i>Co dzieje się z Twoimi danymi <span class="lbl-en d-inline">How your data is processed</span></h3>
    <dl class="tz-dl mb-3" style="border:1px solid var(--tz-line);border-radius:10px;overflow:hidden">
      <div style="border-top:0"><dt>Administrator danych</dt><dd><?= h($rodo_org['name'] ?: '—') ?></dd></div>
      <div style="border-top:0"><dt>Siedziba</dt><dd><?= h(trim(($rodo_org['address'] ?? '') . ' ' . ($rodo_org['city'] ?? ''))) ?: '—' ?></dd></div>
      <div style="border-top:0"><dt>NIP / KRS</dt><dd><?= h(trim(($rodo_org['nip'] ?? '') . ($rodo_org['krs'] ? ' / ' . $rodo_org['krs'] : ''))) ?: '—' ?></dd></div>
      <div><dt>Cel przetwarzania</dt><dd>Realizacja umowy i obowiązków organizacji</dd></div>
      <div><dt>Podstawa prawna</dt><dd>Wykonanie umowy (art. 6 ust. 1 lit. b RODO)</dd></div>
      <div><dt>Okres przechowywania</dt><dd>Czas trwania umowy + okres wymagany przepisami</dd></div>
    </dl>

    <!-- Upoważnienia -->
    <h3 class="h6 fw-bold mb-2"><i class="bi bi-patch-check me-1" style="color:#1E6DFF" aria-hidden="true"></i>Twoje upoważnienia do przetwarzania danych</h3>
    <?php if (!$rodo_auth): ?>
      <p class="text-muted small mb-4">Brak upoważnień powiązanych z Twoim kontem.</p>
    <?php else: ?>
      <div class="mb-4">
      <?php foreach ($rodo_auth as $a):
        $scope = json_decode($a['scope_items'] ?? '[]', true) ?: [];
      ?>
        <div class="border rounded p-3 mb-2 <?= $a['status'] !== 'aktywne' ? 'opacity-75' : '' ?>" style="border-color:var(--tz-line)!important">
          <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="font-monospace fw-bold text-muted" style="font-size:.82rem"><?= h($a['number']) ?></span>
            <?= rodo_status_badge($a['status']) ?>
            <a href="<?= APP_URL ?>/rodo/print.php?id=<?= (int)$a['id'] ?>" target="_blank" rel="noopener" class="tz-btn tz-btn--ghost btn-sm ms-auto"><i class="bi bi-printer me-1" aria-hidden="true"></i>Drukuj</a>
          </div>
          <div class="text-muted small mt-1">
            od <?= !empty($a['authorized_from']) ? h(date('d.m.Y', strtotime($a['authorized_from']))) : '—' ?>
            <?= !empty($a['authorized_until']) ? ' do ' . h(date('d.m.Y', strtotime($a['authorized_until']))) : ' (do zakończenia umowy)' ?>
          </div>
          <?php if ($scope || !empty($a['scope_custom'])): ?>
          <ul class="mb-0 ps-3 mt-1" style="font-size:.85rem">
            <?php foreach ($scope as $k): ?><li><?= h(RODO_SCOPE_ITEMS[$k] ?? $k) ?></li><?php endforeach; ?>
            <?php if (!empty($a['scope_custom'])): ?><li><?= h($a['scope_custom']) ?></li><?php endif; ?>
          </ul>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <!-- Rejestr czynności na umowie — TYLKO wejścia i modyfikacje -->
    <h3 class="h6 fw-bold mb-1"><i class="bi bi-file-earmark-text me-1" style="color:#1E6DFF" aria-hidden="true"></i>Czynności na Twojej umowie</h3>
    <p class="text-muted small mb-2">Rejestr wejść (dostępów) i modyfikacji danych umowy.</p>
    <?php if (!$rodo_history): ?>
      <div class="text-muted small">Brak zapisanych wejść ani modyfikacji.</div>
    <?php else: ?>
      <ol class="list-unstyled mb-0">
        <?php foreach ($rodo_history as $ev):
          [$lbl, $ic] = $ACT[$ev['action']] ?? [$ev['action'], 'bi-dot'];
          $is_enter = in_array($ev['action'], $REG_ENTER, true);
          $col = $is_enter ? '#6B7280' : '#1E6DFF';
          $cat = $is_enter ? 'Wejście' : 'Modyfikacja';
        ?>
        <li class="d-flex gap-3 pb-3" style="border-left:2px solid var(--tz-line);margin-left:14px;padding-left:16px;position:relative">
          <span style="position:absolute;left:-9px;top:0;width:16px;height:16px;border-radius:50%;background:#fff;border:2px solid <?= $col ?>;display:flex;align-items:center;justify-content:center">
            <i class="bi <?= h($ic) ?>" style="font-size:.55rem;color:<?= $col ?>" aria-hidden="true"></i>
          </span>
          <div>
            <div class="fw-semibold" style="font-size:.9rem"><?= h($lbl) ?>
              <span class="tz-badge <?= $is_enter ? 'tz-badge--off' : '' ?> ms-1" style="<?= $is_enter ? '' : 'background:#eef4ff;color:#1656d6;border:1px solid #dbe7ff' ?>"><?= $cat ?></span>
              <span class="text-muted fw-normal" style="font-size:.78rem">· <?= h(ucfirst($ev['contract_type'])) ?></span>
            </div>
            <div class="text-muted" style="font-size:.8rem">
              <?= !empty($ev['created_at']) ? h(date('d.m.Y H:i', strtotime($ev['created_at']))) : '' ?>
              <?php if (!empty($ev['note'])): ?> · <?= h(mb_strimwidth($ev['note'], 0, 120, '…')) ?><?php endif; ?>
            </div>
          </div>
        </li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>

    <p class="tz-note mt-3 mb-0" style="border:0;padding-left:0">
      <i class="bi bi-lock" aria-hidden="true"></i>
      <span>Masz prawo dostępu do swoich danych, sprostowania i ograniczenia przetwarzania. Upoważnienie oznacza obowiązek <strong>poufności</strong> — również po zakończeniu współpracy.</span>
    </p>
  </div>
</section>

<!-- ═══════════ O PANELU ═══════════ -->
<section class="tz-card tz-panel" id="opanelu" role="tabpanel" aria-labelledby="tab-opanelu" tabindex="-1">
  <div class="tz-card__hd" id="op-h">
    <i class="bi bi-info-circle" aria-hidden="true"></i>
    <span>O panelu <span class="lbl-en">About this panel</span></span>
  </div>
  <div class="tz-card__bd">

    <p class="mb-3">
      <strong>System Tożsamości</strong> to Twoje <strong>„konto o koncie"</strong> — jedno miejsce, w którym zarządzasz
      <strong>logowaniem i tożsamością</strong> w organizacji (login, hasło, telefon, uwierzytelnianie).
      <strong>Nie służy do codziennej pracy</strong> — zadania, umowy i komunikaty załatwiasz w systemie SZO.
    </p>

    <div class="row g-3 mb-4">
      <div class="col-md-6">
        <div class="h-100 rounded p-3" style="background:#ecfdf5;border:1px solid #a7f3d0">
          <div class="fw-bold mb-2" style="color:#047857"><i class="bi bi-check-circle-fill me-1" aria-hidden="true"></i>Do tego służy</div>
          <ul class="mb-0 ps-3 small" style="line-height:1.7">
            <li>Sprawdzić swój <strong>numer UID</strong> i login (identyfikator sieciowy)</li>
            <li><strong>Zmienić lub zresetować hasło</strong> (panel SZO + Microsoft 365 naraz)</li>
            <li>Ustawić i zweryfikować <strong>numer telefonu</strong> (SMS, odzyskiwanie)</li>
            <li>Włączyć <strong>logowanie dwuetapowe (MFA)</strong></li>
            <li>Zobaczyć swoje <strong>usługi, upoważnienia RODO i rejestr czynności</strong></li>
          </ul>
        </div>
      </div>
      <div class="col-md-6">
        <div class="h-100 rounded p-3" style="background:#fff7ed;border:1px solid #fed7aa">
          <div class="fw-bold mb-2" style="color:#c2410c"><i class="bi bi-x-circle-fill me-1" aria-hidden="true"></i>Czym NIE jest</div>
          <ul class="mb-0 ps-3 small" style="line-height:1.7">
            <li>To <strong>nie</strong> panel do pracy — <strong>zadań, komunikatów, kalendarza</strong> szukaj w <a href="<?= APP_URL ?>/portal.php">SZO</a></li>
            <li><strong>Nie</strong> znajdziesz tu <strong>treści umów ani dokumentów</strong> (panel SZO / EZD)</li>
            <li><strong>Nie</strong> zgłaszasz tu problemów — od tego jest <strong>helpdesk</strong></li>
            <li><strong>Nie</strong> zmienisz tu swojej <strong>roli ani uprawnień</strong> (robi to administrator)</li>
            <li>To <strong>nie</strong> jest Twoja <strong>skrzynka e-mail</strong> — pocztę masz w Microsoft 365</li>
          </ul>
        </div>
      </div>
    </div>

    <h3 class="h6 fw-bold mb-2"><i class="bi bi-diagram-3 me-1" style="color:#1E6DFF" aria-hidden="true"></i>Jak to działa</h3>
    <ul class="small mb-4" style="line-height:1.8">
      <li>Twoje konto i <strong>numer UID</strong> powstają automatycznie w chwili <strong>zawarcia umowy</strong> — UID jest stały i niezmienny.</li>
      <li><strong>Login do panelu</strong> (identyfikator sieciowy) może różnić się od <strong>loginu Microsoft 365</strong> — oba widzisz w zakładce „Podstawowe".</li>
      <li>Zmiana hasła <strong>synchronizuje się</strong> jednocześnie z panelem SZO i Microsoft 365 / Entra ID.</li>
    </ul>

    <h3 class="h6 fw-bold mb-2"><i class="bi bi-life-preserver me-1" style="color:#1E6DFF" aria-hidden="true"></i>Potrzebujesz pomocy?</h3>
    <div class="d-flex gap-2 flex-wrap">
      <a href="<?= APP_URL ?>/helpdesk/index.php" class="tz-btn tz-btn--ghost btn-sm"><i class="bi bi-headset me-1" aria-hidden="true"></i>Zgłoś do helpdesku</a>
      <a href="<?= APP_URL ?>/portal.php" class="tz-btn tz-btn--ghost btn-sm"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Przejdź do SZO</a>
      <a href="<?= APP_URL ?>/user/verify_reset.php" class="tz-btn tz-btn--ghost btn-sm"><i class="bi bi-key me-1" aria-hidden="true"></i>Nie mogę się zalogować</a>
    </div>

    <?php if (!$first_visit): ?>
    <p class="text-muted mt-3 mb-0" style="font-size:.78rem"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Zapoznałeś się z wprowadzeniem.</p>
    <?php endif; ?>
  </div>
</section>

<script>
window.__tzFirstVisit = <?= $first_visit ? 'true' : 'false' ?>;
(function(){
  var toggle=document.getElementById('pw_toggle'), pwNew=document.getElementById('pw_new');
  if(toggle&&pwNew){toggle.addEventListener('click',function(){
    var show=pwNew.type==='password'; pwNew.type=show?'text':'password';
    toggle.setAttribute('aria-pressed',show?'true':'false');
    toggle.querySelector('i').className=show?'bi bi-eye-slash':'bi bi-eye';
  });}
  var conf=document.getElementById('pw_conf'), matchEl=document.getElementById('pw_match');
  var meter=document.getElementById('pw_meter'), strengthEl=document.getElementById('pw_strength');
  var account='<?= h(mb_strtolower(($user['name']??'').' '.$panel_login)) ?>'.split(/[\s@.\-]+/).filter(function(p){return p.length>2;});
  function setReq(k,ok){var li=document.querySelector('#pw_req li[data-r="'+k+'"]');if(!li)return;li.classList.toggle('ok',ok);li.querySelector('.dot').textContent=ok?'✓':'○';}
  function check(){
    var v=pwNew?pwNew.value:'';
    var req={len:v.length>=12,up:/[A-ZĄĆĘŁŃÓŚŹŻ]/.test(v),low:/[a-ząćęłńóśźż]/.test(v),num:/[0-9]/.test(v),sym:/[^A-Za-z0-9]/.test(v),
             name:v.length>0&&!account.some(function(p){return v.toLowerCase().indexOf(p)>=0;})};
    Object.keys(req).forEach(function(k){setReq(k,req[k]);});
    var score=Object.keys(req).filter(function(k){return req[k];}).length;
    var pct=(score/6)*100, color='#f87171', txt='Słabe · Weak';
    if(score>=6){color='#16a34a';txt='Bardzo silne · Very strong';}
    else if(score>=5){color='#22c55e';txt='Silne · Strong';}
    else if(score>=3){color='#f59e0b';txt='Średnie · Medium';}
    if(meter){meter.style.width=pct+'%';meter.style.backgroundColor=color;}
    if(strengthEl)strengthEl.textContent='Siła hasła: '+txt;
    if(matchEl&&conf)matchEl.hidden=(conf.value.length===0||conf.value===v);
  }
  if(pwNew)pwNew.addEventListener('input',check);
  if(conf)conf.addEventListener('input',check);
  // ── Zakładki: pokazuj tylko wybraną sekcję ──
  var tabs=[].slice.call(document.querySelectorAll('.tz-subnav .seg a[role="tab"]'));
  var panels=[].slice.call(document.querySelectorAll('.tz-panel'));
  function activate(id, focusPanel){
    var found=false;
    panels.forEach(function(p){var on=p.id===id;p.classList.toggle('active',on);found=found||on;});
    if(!found){id='podstawowe';panels.forEach(function(p){p.classList.toggle('active',p.id===id);});}
    tabs.forEach(function(t){
      var on=t.getAttribute('aria-controls')===id;
      t.classList.toggle('on',on);
      t.setAttribute('aria-selected',on?'true':'false');
      t.tabIndex=on?0:-1;
    });
    if(history.replaceState) history.replaceState(null,'','#'+id);
    if(focusPanel){var pl=document.getElementById(id);if(pl)pl.focus({preventScroll:true});}
    window.scrollTo({top:0,behavior:'smooth'});
  }
  // Klik w dowolny odnośnik do panelu (zakładki + np. „Konfiguruj" → #bezpieczenstwo)
  document.querySelectorAll('a[href^="#"]').forEach(function(a){
    var id=a.getAttribute('href').slice(1);
    if(!document.getElementById(id)||!document.getElementById(id).classList.contains('tz-panel'))return;
    a.addEventListener('click',function(e){e.preventDefault();activate(id,true);});
  });
  // Klawiatura: strzałki/Home/End w obrębie listy zakładek
  tabs.forEach(function(t,i){
    t.addEventListener('keydown',function(e){
      var n=null;
      if(e.key==='ArrowRight'||e.key==='ArrowDown')n=tabs[(i+1)%tabs.length];
      else if(e.key==='ArrowLeft'||e.key==='ArrowUp')n=tabs[(i-1+tabs.length)%tabs.length];
      else if(e.key==='Home')n=tabs[0];
      else if(e.key==='End')n=tabs[tabs.length-1];
      if(n){e.preventDefault();activate(n.getAttribute('aria-controls'),false);n.focus();}
    });
  });
  // Na starcie: #hash (np. po zapisie hasła → #bezpieczenstwo); przy 1. wizycie → „O panelu".
  var initial=(location.hash||'').slice(1);
  if(initial && document.getElementById(initial) && document.getElementById(initial).classList.contains('tz-panel')){
    activate(initial,false);
  } else if(window.__tzFirstVisit){
    activate('opanelu',false);
  }
})();
</script>

<?php include __DIR__ . '/_foot.php';
