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
require_once dirname(__DIR__) . '/includes/permissions.php';

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
require_once dirname(__DIR__) . '/includes/mobile_auth.php';
mobile_pin_schema_heal();

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

// Klucze sprzętowe WebAuthn
require_once dirname(__DIR__) . '/includes/webauthn.php';
$wk_keys_idx = webauthn_get_credentials((int)$db_user['id']);
$has_wk      = !empty($wk_keys_idx);

$mfa_active = $mfa_method !== '' || $has_wk;
$mfa_label  = match(true) {
    $has_wk && $mfa_method === 'totp' => 'Klucz + Authenticator',
    $has_wk && $mfa_method === 'sms'  => 'Klucz + SMS',
    $has_wk                            => 'Klucz sprzętowy',
    $mfa_method === 'totp'             => 'Aplikacja Authenticator',
    $mfa_method === 'sms'              => 'Kod SMS',
    default                            => 'Nieaktywne',
};

// ── Rejestr czynności: RODO (co dzieje się z danymi) + historia umowy ────────
require_once dirname(__DIR__) . '/includes/rodo.php';
try { rodo_migrate(); } catch (\Throwable $e) {}
$rodo_org  = function_exists('rodo_org_data') ? rodo_org_data() : ['name'=>'','address'=>'','city'=>'','nip'=>'','krs'=>''];
$rodo_auth = [];
$rodo_ids  = [];
foreach (rodo_contract_ids_for_login((string)$panel_login, (string)($microsoft_id ?? '')) as $ctype => $ids) {
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

$cc_done        = false;
$cc_result      = null;
$cc_numer_input = '';

$errors = [];

if (!function_exists('tz_mask_phone')) {
    function tz_mask_phone(string $p): string {
        $d = preg_replace('/\D/', '', $p);
        if (strlen($d) < 3) return $p;
        return '+' . substr($d, 0, max(0, strlen($d) - 9)) . ' ••• ••• ' . substr($d, -3);
    }
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
                    $via = sms_send_with_fallback($norm, "Kod weryfikacyjny numeru telefonu: {$otp} (ważny 5 min). Nie udostępniaj go nikomu.", $user['email'] ?? '', 'phone_verify_code');
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
    elseif ($action === 'mobile_pin_save') {
        if (!mobile_pin_allowed($db_user)) {
            $errors[] = 'Logowanie PIN-em jest niedostępne dla tego konta.';
        } else {
            $new_pin = preg_replace('/\D/', '', $_POST['mobile_pin'] ?? '');
            $cnf_pin = preg_replace('/\D/', '', $_POST['mobile_pin_confirm'] ?? '');
            if ($new_pin !== $cnf_pin) {
                $errors[] = 'Podane PIN-y nie są identyczne.';
            } elseif ($err = mobile_pin_set($uid, $new_pin)) {
                $errors[] = $err;
            } else {
                flash_set('success', 'PIN do szybkiego dzwonienia został zapisany.');
                header('Location: ' . $SELF . '#bezpieczenstwo'); exit;
            }
        }
    }
    elseif ($action === 'mobile_pin_remove') {
        mobile_pin_clear($uid);
        flash_set('success', 'PIN do szybkiego dzwonienia został usunięty.');
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

/* ═══════════════════════ MODEL WIDOKU (układ kaflowy, Tailwind + Alpine) ══════════════════
   Panel tożsamości nie rozdziela ruchu po modułach (launcher w nagłówku i portal.php to jedyne
   miejsce rozjazdu). Poniżej wyłącznie dane do prezentacji — cała logika POST jest wyżej. */

// Rola użytkownika (etykieta z tabeli roles) + kolor plakietki
$role_key = (string)($user['role'] ?? 'viewer');
$role_row = null;
try { $role_row = db_one("SELECT display_name, description FROM roles WHERE name=?", [$role_key]); } catch (\Throwable $e) {}
$role_label = (string)($role_row['display_name'] ?? ucfirst($role_key));
$role_desc  = (string)($role_row['description'] ?? '');
$ROLE_TONE  = ['admin' => 'rose', 'editor' => 'indigo', 'viewer' => 'slate', 'crm_user' => 'emerald', 'ezd_user' => 'teal',
               'ezd_biuro' => 'teal', 'dydaktyk_sekretariat' => 'violet', 'dydaktyk_ti' => 'violet', 'srs_koordynator' => 'amber'];
$role_tone  = $ROLE_TONE[$role_key] ?? 'slate';
$role_icon  = $role_key === 'admin' ? 'bi-shield-fill-check' : ($role_key === 'editor' ? 'bi-pencil-square' : 'bi-person');

// Dostęp do modułów wynikający z roli (+ uprawnienia indywidualne) — do czipów „Uprawnienia”
$access = [];
foreach (PERMISSION_MODULES as $mk => $ml) {
    if ($mk === 'admin' && !is_admin()) continue;
    if (can_write($mk))     $access[] = ['label' => $ml, 'level' => 'edycja'];
    elseif (can_read($mk))  $access[] = ['label' => $ml, 'level' => 'odczyt'];
}
$access_write = count(array_filter($access, fn($a) => $a['level'] === 'edycja'));

// Stan zabezpieczeń konta — lista kontrolna + wynik procentowy
$sec_items = [
    ['label' => 'Hasło lokalne SZO',    'ok' => $has_local_password, 'tab' => 'bezpieczenstwo', 'icon' => 'bi-key',
     'hint' => $has_local_password ? 'ustawione (dual-sync)' : 'ustaw hasło awaryjne'],
    ['label' => 'Telefon do SMS',       'ok' => $phone_verified,     'tab' => 'bezpieczenstwo', 'icon' => 'bi-phone',
     'hint' => $phone_verified ? 'zweryfikowany' : ($phone_saved ? 'niezweryfikowany' : 'brak numeru')],
    ['label' => 'Logowanie dwuetapowe', 'ok' => $mfa_active,         'tab' => 'bezpieczenstwo', 'icon' => 'bi-shield-check',
     'hint' => $mfa_active ? $mfa_label : 'wyłączone'],
    ['label' => 'Klucz / passkey',      'ok' => $has_wk,             'tab' => 'bezpieczenstwo', 'icon' => 'bi-fingerprint',
     'hint' => $has_wk ? count($wk_keys_idx) . ' szt.' : 'brak'],
];
if ($has_ika) {
    $sec_items[] = ['label' => 'Kod IKA', 'ok' => !$ika_blocked, 'tab' => 'ika', 'icon' => 'bi-123',
                    'hint' => $ika_blocked ? 'zablokowany' : 'aktywny'];
}
$sec_ok    = count(array_filter($sec_items, fn($i) => $i['ok']));
$sec_total = count($sec_items);
$sec_pct   = $sec_total ? (int)round($sec_ok / $sec_total * 100) : 0;
$sec_tone  = $sec_pct >= 80 ? 'emerald' : ($sec_pct >= 50 ? 'amber' : 'rose');

// Metody MFA / PIN (dawniej liczone w środku szablonu)
$totp_on     = !empty($db_user['totp_confirmed']) && !empty($db_user['totp_secret']);
$sms_on      = $sms_available && $mfa_method === 'sms' && !empty($db_user['twofa_phone']);
$pin_is_set  = mobile_pin_is_set($db_user);
$pin_allowed = mobile_pin_allowed($db_user);
$pin_lock    = mobile_pin_lock_left($db_user);
$pin_app_url = APP_URL . '/mobilna/';

// Usługi — kafle (filtr Alpine po nazwie/statusie)
$services = [[
    'name' => 'Panel SZO', 'icon' => 'bi-window-desktop', 'tone' => 'blue',
    'status' => 'ok', 'status_label' => 'Aktywny',
    'desc' => 'Logowanie: ' . $panel_login . ' · dostęp bezterminowy w okresie obowiązywania umowy.',
    'href' => APP_URL . '/portal.php', 'cta' => 'Otwórz',
]];
if (module_enabled('crm_enabled') && (can_read('crm') || can_write('crm') || is_admin())) {
    // CRM ma własny host (crm.feer.org.pl) — jeśli skonfigurowany, prowadzimy tam (osobny ekran logowania, skrócona nawigacja).
    $crm_host = trim((string)(db_one("SELECT value FROM settings WHERE key_='crm_public_url'")['value'] ?? ''));
    $crm_link = $crm_host !== '' ? rtrim($crm_host, '/') . '/crm/dashboard.php' : APP_URL . '/crm/dashboard.php';
    $crm_rw   = can_write('crm') || is_admin();
    $services[] = [
        'name' => 'CRM', 'icon' => 'bi-diagram-2-fill', 'tone' => 'sky',
        'status' => 'ok', 'status_label' => $crm_rw ? 'Pełny dostęp' : 'Tylko odczyt',
        'desc' => 'Kontakty, sprawy, oferty i korespondencja organizacji. Logowanie: ' . $panel_login
                . ($crm_host !== '' ? ' · ' . (parse_url($crm_host, PHP_URL_HOST) ?: $crm_host) : ''),
        'href' => $crm_link, 'cta' => 'Otwórz',
    ];
}
if (module_enabled('ezd_enabled') && (can_read('ezd') || is_admin())) {
    $services[] = [
        'name' => 'Wirtualne biurko (EZD)', 'icon' => 'bi-folder2-open', 'tone' => 'teal',
        'status' => 'ok', 'status_label' => 'Aktywny',
        'desc' => 'Sprawy, pisma, teczki, dekretacje — system obiegu dokumentów.',
        'href' => APP_URL . '/ezd/index.php', 'cta' => 'Otwórz',
    ];
}
$services[] = [
    'name' => 'Microsoft 365 / Entra ID', 'icon' => 'bi-microsoft', 'tone' => 'indigo',
    'status' => ($has_m365 && !empty($m365_row['m365_konto_aktywne'])) ? 'ok' : ($has_m365 ? 'warn' : 'off'),
    'status_label' => ($has_m365 && !empty($m365_row['m365_konto_aktywne'])) ? 'Aktywne' : ($has_m365 ? 'Zawieszone' : 'Brak konta'),
    'desc' => $has_m365
        ? 'Login: ' . ($m365_login ?: $panel_login) . ' · Licencja: ' . (!empty($m365_row['m365_licencja_przypisana']) ? 'przypisana' : 'brak')
          . (!empty($m365_row['m365_data_utworzenia']) ? ' · Utworzone: ' . date('d.m.Y', strtotime($m365_row['m365_data_utworzenia'])) : '')
        : 'Konto Microsoft 365 nie zostało utworzone dla tej tożsamości.',
    'href' => APP_URL . '/panel/m365.php', 'cta' => 'Szczegóły',
];
$services[] = [
    'name' => 'Katalog LDAP', 'icon' => 'bi-diagram-3', 'tone' => 'slate',
    'status' => $has_ldap ? 'ok' : ($ldap_enabled ? 'warn' : 'off'),
    'status_label' => $has_ldap ? 'Utworzone' : ($ldap_enabled ? 'Do utworzenia' : 'Nieaktywna'),
    'desc' => $has_ldap
        ? 'Wpis w katalogu organizacji (uid=' . $uid . ')' . (!empty($db_user['ldap_created_at']) ? ' · utworzono ' . date('d.m.Y', strtotime($db_user['ldap_created_at'])) : '')
        : ($ldap_enabled ? 'Utwórz swój wpis w katalogu LDAP organizacji na podstawie danych tożsamości. Hasło nie jest kopiowane.' : 'Usługa katalogu LDAP jest tymczasowo nieaktywna.'),
    'ldap' => true,
];
$services[] = [
    'name' => 'Uwierzytelnianie wieloskładnikowe (MFA)', 'icon' => 'bi-shield-check', 'tone' => $mfa_active ? 'emerald' : 'amber',
    'status' => $mfa_active ? 'ok' : 'warn', 'status_label' => $mfa_active ? $mfa_label : 'Brak drugiego składnika',
    'desc' => 'Drugi składnik logowania chroniący Twoją tożsamość.',
    'tab' => 'bezpieczenstwo', 'cta' => 'Konfiguruj',
];
$services[] = [
    'name' => 'Dostęp do komputerów FEER', 'icon' => 'bi-pc-display', 'tone' => 'slate',
    'status' => 'off', 'status_label' => 'Nieaktywna', 'desc' => 'Usługa tymczasowo nieaktywna.', 'soon' => true,
];
$trello_ws_url = org_setting('trello_workspace_url') ?: 'https://trello.com';

// Zakładki (kolejność = kolejność w pasku; klucze = #hash w adresie, używane też przez przekierowania po zapisie)
$tabs = [
    'podstawowe'     => ['Podstawowe',        'bi-person-badge'],
    'uslugi'         => ['Usługi',            'bi-grid-3x3-gap'],
    'bezpieczenstwo' => ['Bezpieczeństwo',    'bi-shield-lock'],
];
if ($has_ika) $tabs['ika'] = ['Zmień IKA', 'bi-key'];
$tabs['rejestr'] = ['Rejestr czynności', 'bi-clock-history'];
$tabs['opanelu'] = ['O panelu',          'bi-info-circle'];
if (is_admin()) $tabs['administracja'] = ['Admin', 'bi-shield-fill-gear'];

$account_parts = array_values(array_filter(preg_split('/[\s@.\-]+/', mb_strtolower(($user['name'] ?? '') . ' ' . $panel_login)),
                                           fn($p) => mb_strlen($p) > 2));
$STATUS_CLASS = ['ok' => 'tzx-badge--ok', 'warn' => 'tzx-badge--warn', 'off' => 'tzx-badge--off'];
$STATUS_ICON  = ['ok' => 'bi-check-circle-fill', 'warn' => 'bi-exclamation-circle-fill', 'off' => 'bi-slash-circle'];

// Zakładka „Bezpieczeństwo": która sekcja rozwinięta na starcie — po błędzie formularza ta, której
// dotyczył POST; przy oczekującym kodzie SMS telefon; w przeciwnym razie pierwszy brak w konfiguracji.
$sec_open = 'haslo';
if (!$has_local_password)                   $sec_open = 'haslo';
elseif ($phone_pending || !$phone_verified) $sec_open = 'telefon';
elseif (!$mfa_active)                       $sec_open = 'mfa';
$__pa = (string)($_POST['_action'] ?? '');
if ($errors && $__pa !== '') {
    if (str_starts_with($__pa, 'pwd'))            $sec_open = 'haslo';
    elseif (str_starts_with($__pa, 'phone'))      $sec_open = 'telefon';
    elseif (str_starts_with($__pa, 'mobile_pin')) $sec_open = 'pin';
}
$sec_overview = [
    ['k' => 'haslo',   'icon' => 'bi-key',          'label' => 'Hasło',           'ok' => $has_local_password, 'hint' => $has_local_password ? 'ustawione · SZO' . ($has_m365 ? ' + M365' : '') : 'brak hasła lokalnego'],
    ['k' => 'telefon', 'icon' => 'bi-phone',        'label' => 'Telefon do SMS',  'ok' => $phone_verified,     'hint' => $phone_verified ? tz_mask_phone($phone_saved) : ($phone_saved ? 'niezweryfikowany' : 'brak numeru')],
    ['k' => 'mfa',     'icon' => 'bi-shield-check', 'label' => 'Drugi składnik',  'ok' => $mfa_active,         'hint' => $mfa_active ? $mfa_label : 'wyłączony'],
    ['k' => 'mfa',     'icon' => 'bi-fingerprint',  'label' => 'Klucz / passkey', 'ok' => $has_wk,             'hint' => $has_wk ? count($wk_keys_idx) . ' szt.' : 'brak'],
];
if ($pin_allowed) {
    $sec_overview[] = ['k' => 'pin', 'icon' => 'bi-telephone-outbound', 'label' => 'PIN dialera', 'ok' => $pin_is_set, 'hint' => $pin_is_set ? 'ustawiony' : 'nieustawiony'];
}
// Lista kontrolna → sekcja akordeonu (zdarzenie tz-sec)
$sec_items[0]['sec'] = 'haslo'; $sec_items[1]['sec'] = 'telefon'; $sec_items[2]['sec'] = 'mfa'; $sec_items[3]['sec'] = 'mfa';

$PAGE_TITLE = 'Tożsamość';
$TZ_ACTIVE  = 'konto';
include __DIR__ . '/_head.php';   // własny chrome podsystemu (bez menu SZO)
?>
<style type="text/tailwindcss">
/* ── Warstwa „dokumentowa” (wzorzec: strony usług CRI AGH): białe tło, nagłówki z kwadratowym znacznikiem,
      szara kolumna informacyjna, tabele i listy zamiast kafli. Tailwind bez preflightu: przy tw-border zawsze tw-border-solid. ── */
.tzx-h         { @apply tw-m-0 tw-mb-3 tw-text-[1.2rem] tw-font-bold tw-text-slate-900 tw-flex tw-items-center tw-gap-2.5 tw-tracking-tight; }
.tzx-h::before { content: ''; @apply tw-inline-block tw-w-2.5 tw-h-2.5 tw-bg-blue-600 tw-shrink-0; }
.tzx-h .lbl-en { @apply tw-inline tw-font-normal tw-ml-1; }
.tzx-h3        { @apply tw-m-0 tw-mb-2 tw-mt-7 tw-text-[.98rem] tw-font-bold tw-text-slate-900; }
.tzx-h2        { @apply tw-m-0 tw-text-[.95rem] tw-font-semibold tw-text-slate-900 tw-tracking-tight; }
.tzx-lead      { @apply tw-text-[.92rem] tw-text-slate-700 tw-leading-relaxed tw-m-0 tw-max-w-[70ch]; }
.tzx-sec       { @apply tw-pt-8 tw-pb-6 tw-border-t tw-border-solid tw-border-slate-200 tw-scroll-mt-20 tw-outline-none; }
.tzx-sec:first-child { @apply tw-border-t-0 tw-pt-1; }
.tzx-eyebrow   { @apply tw-text-[.66rem] tw-font-semibold tw-uppercase tw-tracking-[.08em] tw-text-slate-500; }
.tzx-muted     { @apply tw-text-xs tw-text-slate-500; }
/* tabela definicji (dane tożsamości) */
.tzx-dl        { @apply tw-m-0 tw-border-t tw-border-solid tw-border-slate-300; }
.tzx-dl > div  { @apply tw-grid tw-grid-cols-1 sm:tw-grid-cols-[230px_minmax(0,1fr)] tw-gap-x-6 tw-py-2.5 tw-border-b tw-border-solid tw-border-slate-200; }
.tzx-dl dt     { @apply tw-m-0 tw-text-[.8rem] tw-font-semibold tw-text-slate-600; }
.tzx-dl dd     { @apply tw-m-0 tw-text-[.9rem] tw-text-slate-900 tw-break-words; }
/* tabela usług */
.tzx-table     { @apply tw-w-full tw-text-[.86rem] tw-border-collapse; }
.tzx-table th  { @apply tw-text-left tw-text-[.68rem] tw-uppercase tw-tracking-[.08em] tw-text-slate-500 tw-font-semibold tw-py-2 tw-pr-4 tw-border-b-2 tw-border-solid tw-border-slate-300; }
.tzx-table td  { @apply tw-py-2.5 tw-pr-4 tw-border-b tw-border-solid tw-border-slate-200 tw-align-top; }
/* lista odnośników z lewym znacznikiem (jak „Instrukcje” u AGH) */
.tzx-links     { @apply tw-list-none tw-m-0 tw-p-0; }
.tzx-links li  { @apply tw-border-l-[3px] tw-border-solid tw-border-slate-300 tw-pl-3 tw-py-1 tw-my-1.5 tw-transition; }
.tzx-links li:hover { @apply tw-border-blue-600; }
.tzx-links a   { @apply tw-text-slate-900 tw-font-medium tw-no-underline hover:tw-underline hover:tw-text-slate-900; }
.tzx-links small { @apply tw-block tw-text-xs tw-text-slate-500; }
/* kolumna boczna */
.tzx-side      { @apply tw-bg-slate-100 tw-p-5 tw-text-[.86rem] lg:tw-sticky lg:tw-top-[68px]; }
.tzx-side section + section { @apply tw-mt-6 tw-pt-5 tw-border-t tw-border-solid tw-border-slate-300/70; }
.tzx-side h3   { @apply tw-m-0 tw-mb-2 tw-text-[.95rem] tw-font-bold tw-text-slate-900 tw-flex tw-items-center tw-gap-2; }
.tzx-side h3::before { content: ''; @apply tw-inline-block tw-w-2 tw-h-2 tw-bg-blue-600 tw-shrink-0; }
.tzx-side dl   { @apply tw-m-0; }
.tzx-side dt   { @apply tw-text-[.7rem] tw-uppercase tw-tracking-[.06em] tw-text-slate-500 tw-font-semibold tw-mt-2.5; }
.tzx-side dd   { @apply tw-m-0 tw-text-slate-900 tw-break-words; }
.tzx-side a    { @apply tw-text-blue-700; }
.tzx-toc a     { @apply tw-block tw-py-1 tw-pl-3 tw-text-slate-700 tw-no-underline tw-border-l-[3px] tw-border-solid tw-border-transparent hover:tw-text-slate-900 hover:tw-underline; }
.tzx-toc a.on  { @apply tw-border-blue-600 tw-font-semibold tw-text-slate-900; }
.tzx-check     { @apply tw-list-none tw-m-0 tw-p-0; }
.tzx-check li  { @apply tw-flex tw-items-start tw-gap-2 tw-py-1; }
.tzx-check i   { @apply tw-mt-0.5 tw-text-[.9rem]; }
/* przyciski, odznaki, pola */
.tzx-btn       { @apply tw-inline-flex tw-items-center tw-justify-center tw-gap-1.5 tw-rounded tw-bg-slate-800 tw-text-white tw-px-3.5 tw-py-2 tw-text-sm tw-font-semibold tw-no-underline tw-border tw-border-solid tw-border-slate-800 tw-cursor-pointer tw-transition hover:tw-bg-slate-900 hover:tw-text-white focus-visible:tw-outline focus-visible:tw-outline-[3px] focus-visible:tw-outline-offset-2 focus-visible:tw-outline-amber-400 disabled:tw-opacity-50 disabled:tw-cursor-not-allowed; }
.tzx-btn--sm   { @apply tw-px-3 tw-py-1.5 tw-text-xs; }
.tzx-btn--ghost{ @apply tw-bg-white tw-text-slate-800 tw-border-slate-400 hover:tw-bg-slate-100 hover:tw-text-slate-900; }
.tzx-btn--danger{ @apply tw-bg-white tw-text-rose-700 tw-border-rose-300 hover:tw-bg-rose-50 hover:tw-text-rose-800; }
.tzx-badge     { @apply tw-inline-flex tw-items-center tw-gap-1 tw-rounded-sm tw-px-1.5 tw-py-0.5 tw-text-[.7rem] tw-font-semibold tw-border tw-border-solid tw-whitespace-nowrap; }
.tzx-badge--ok { @apply tw-bg-white tw-text-emerald-700 tw-border-emerald-300; }
.tzx-badge--warn{ @apply tw-bg-white tw-text-amber-700 tw-border-amber-300; }
.tzx-badge--off{ @apply tw-bg-white tw-text-slate-600 tw-border-slate-300; }
.tzx-badge--info{ @apply tw-bg-white tw-text-blue-700 tw-border-blue-300; }
.tzx-field label { @apply tw-block tw-text-[.8rem] tw-font-semibold tw-text-slate-800 tw-mb-1; }
.tzx-field .form-text { @apply tw-text-xs tw-text-slate-500; }
/* elementy używane przez pozostałe sekcje (IKA, rejestr, o panelu, administracja) — spłaszczone */
.tzx-card      { @apply tw-bg-white tw-border tw-border-solid tw-border-slate-200 tw-rounded; }
.tzx-card-hd   { @apply tw-flex tw-items-center tw-gap-2.5 tw-px-4 tw-py-3 tw-border-b tw-border-solid tw-border-slate-200 tw-bg-slate-50; }
.tzx-card-bd   { @apply tw-p-4; }
.tzx-kv        { @apply tw-bg-slate-50 tw-border tw-border-solid tw-border-slate-200 tw-px-3 tw-py-2 tw-min-w-0; }
.tzx-kv dt     { @apply tw-text-[.64rem] tw-font-semibold tw-uppercase tw-tracking-[.06em] tw-text-slate-500 tw-m-0; }
.tzx-kv dd     { @apply tw-m-0 tw-mt-0.5 tw-text-[.88rem] tw-font-semibold tw-text-slate-900 tw-break-words; }
.tzx-ico       { @apply tw-w-9 tw-h-9 tw-rounded tw-flex tw-items-center tw-justify-center tw-text-base tw-shrink-0; }
.tzx-chip      { @apply tw-inline-flex tw-items-center tw-gap-1 tw-bg-white tw-border tw-border-solid tw-border-slate-300 tw-px-2 tw-py-0.5 tw-text-[.74rem] tw-text-slate-800; }
.tzx-tile      { @apply tw-flex tw-flex-col tw-gap-2 tw-bg-white tw-border tw-border-solid tw-border-slate-300 tw-p-3.5 tw-transition hover:tw-border-slate-800; }
.tzx-link-tile { @apply tw-flex tw-items-center tw-gap-3 tw-border tw-border-solid tw-border-slate-200 tw-bg-white tw-p-3 tw-no-underline tw-text-slate-900 hover:tw-border-slate-800 hover:tw-text-slate-900; }
.tzx-timeline li { @apply tw-relative tw-pl-6 tw-pb-4 tw-border-l tw-border-solid tw-border-slate-300 tw-ml-2; }
.tzx-timeline li:last-child { @apply tw-pb-0; }
.tzx-dot       { @apply tw-absolute tw-w-4 tw-h-4 tw-rounded-full tw-bg-white tw-border-2 tw-border-solid tw-flex tw-items-center tw-justify-center tw-text-[.5rem]; left: -9px; top: 0; }
.tzx-sec-row   { @apply tw-flex tw-items-center tw-gap-2.5 tw-py-2 tw-border-b tw-border-solid tw-border-slate-200 last:tw-border-b-0; }
/* Bezpieczeństwo: sekcje rozwijane w stylu FAQ (ramka, tytuł, plus/minus po prawej) */
.tzx-acc       { @apply tw-bg-white tw-border tw-border-solid tw-border-slate-300 tw-mb-2; }
.tzx-acc--open { @apply tw-border-slate-800; }
.tzx-acc-btn   { @apply tw-w-full tw-flex tw-items-center tw-gap-3 tw-px-4 tw-py-3 tw-text-left tw-bg-transparent tw-border-0 tw-cursor-pointer hover:tw-bg-slate-50 focus-visible:tw-outline focus-visible:tw-outline-[3px] focus-visible:tw--outline-offset-[3px] focus-visible:tw-outline-amber-400; font: inherit; color: inherit; }
.tzx-acc-plus  { @apply tw-w-7 tw-h-7 tw-rounded-full tw-border tw-border-solid tw-border-slate-800 tw-flex tw-items-center tw-justify-center tw-text-slate-800 tw-shrink-0 tw-text-sm; }
.tzx-acc--open .tzx-acc-plus { @apply tw-bg-slate-800 tw-text-white; }
.tzx-acc-bd    { @apply tw-px-4 tw-pb-5 tw-pt-4 tw-border-t tw-border-solid tw-border-slate-200; }
.tzx-aside     { @apply tw-bg-slate-100 tw-p-4 tw-text-[.82rem] tw-text-slate-700 tw-leading-relaxed; }
.tzx-aside h4  { @apply tw-m-0 tw-mb-1.5 tw-text-[.82rem] tw-font-bold tw-text-slate-900 tw-flex tw-items-center tw-gap-1.5; }
.tzx-aside ul  { @apply tw-m-0 tw-mt-2 tw-pl-4; }
.tzx-aside li  { @apply tw-mb-1; }
</style>

<div x-data="tzIdentity(<?= h(json_encode(['sections' => array_keys($tabs), 'firstVisit' => $first_visit])) ?>)">

<!-- ═══════════ NAGŁÓWEK STRONY ═══════════ -->
<nav aria-label="Ścieżka" class="tw-text-[.76rem] tw-text-slate-500 tw-mb-3">
  <a href="<?= APP_URL ?>/tozsamosc/index.php" class="tw-text-slate-500 tw-no-underline hover:tw-underline">System Tożsamości</a>
  <span class="tw-mx-1.5" aria-hidden="true">/</span><span class="tw-text-slate-700">Moja tożsamość</span>
</nav>
<h1 class="tzx-h tw-text-[1.6rem] tw-mb-2">Moja tożsamość</h1>
<p class="tzx-lead tw-mb-6">
  <strong>System Tożsamości</strong> to centralny rejestr kont organizacji: jeden identyfikator (UID), jeden login i jedno hasło
  do systemów SZO, Microsoft 365 i usług powiązanych. Tutaj sprawdzisz swoje dane, zmienisz hasło, ustawisz telefon odzyskiwania
  i metody weryfikacji dwuskładnikowej oraz zobaczysz, co dzieje się z Twoimi danymi.
</p>

<?php if ($first_visit): ?>
<!-- Powitanie po pierwszym logowaniu (pokazywane raz) -->
<div class="tw-border tw-border-solid tw-border-blue-300 tw-bg-blue-50 tw-px-4 tw-py-3 tw-mb-5 tw-text-sm tw-flex tw-flex-wrap tw-items-center tw-gap-3" role="note" aria-labelledby="welcome-h">
  <div class="tw-flex-1 tw-min-w-[220px]">
    <strong id="welcome-h">To Twoje pierwsze wejście do Systemu Tożsamości.</strong>
    <span class="tw-text-slate-700">Zacznij od sekcji <a href="#opanelu" @click.prevent="go('opanelu')">O panelu</a> — w 20 sekund zrozumiesz, do czego to służy (a do czego nie).</span>
  </div>
  <form method="post" class="tw-m-0">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="_action" value="dismiss_intro">
    <button type="submit" class="tzx-btn tzx-btn--ghost tzx-btn--sm">Rozumiem, nie pokazuj</button>
  </form>
</div>
<?php endif; ?>

<?= function_exists('flash_html') ? flash_html() : '' ?>

<div aria-live="assertive">
<?php if ($errors): ?>
<div class="tw-border tw-border-solid tw-border-rose-300 tw-bg-rose-50 tw-text-rose-800 tw-px-4 tw-py-3 tw-mb-5 tw-text-sm" role="alert">
  <strong><i class="bi bi-exclamation-triangle-fill tw-mr-1" aria-hidden="true"></i>Popraw następujące błędy:</strong>
  <ul class="tw-mb-0 tw-mt-1 tw-pl-5"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>
</div>

<!-- ═══════════ UKŁAD: treść + kolumna boczna ═══════════ -->
<div class="tw-grid tw-grid-cols-1 lg:tw-grid-cols-[minmax(0,1fr)_320px] tw-gap-8 lg:tw-gap-10 tw-items-start">
<div class="tw-min-w-0">

<!-- ═══════════ PODSTAWOWE ═══════════ -->
<section id="podstawowe" class="tzx-sec" tabindex="-1" aria-labelledby="h-podstawowe">
  <h2 id="h-podstawowe" class="tzx-h">Dane tożsamości <span class="lbl-en">Identity record</span></h2>
  <dl class="tzx-dl">
    <div><dt>Imię i nazwisko</dt><dd class="tw-font-semibold"><?= h($user['name']) ?></dd></div>
    <div><dt>Numer UID</dt><dd class="tw-font-mono tw-font-bold"><?= h($uid) ?></dd></div>
    <div><dt>Identyfikator sieciowy <span class="lbl-en">Network ID (login główny)</span></dt><dd><?= h($panel_login ?: '—') ?></dd></div>
    <div><dt>Login Microsoft 365 <span class="lbl-en">M365 sign-in</span></dt><dd><?= h($m365_login ?: ($has_m365 ? $panel_login : '— brak konta M365 —')) ?></dd></div>
    <div><dt>Identyfikator obiektu Entra <span class="lbl-en">Entra object ID</span></dt><dd class="tw-font-mono tw-text-[.8rem]"><?= h($microsoft_id ?: '—') ?></dd></div>
    <div><dt>Rola w systemie</dt><dd><?= h($role_label) ?> <span class="tzx-muted">· klucz <code class="tw-text-[.74rem]"><?= h($role_key) ?></code></span></dd></div>
    <div><dt>Typ konta</dt><dd><?= h($account_type_label) ?></dd></div>
    <div><dt>Źródło danych <span class="lbl-en">Data source</span></dt><dd><?php
      if ($src_contract) { echo 'Umowa ' . h($src_contract['numer_umowy']); }
      elseif (is_admin()) { echo 'Wpis do KRS – powołanie'; }
      else { echo '—'; }
    ?></dd></div>
    <div><dt>UID nadany <span class="lbl-en">Assigned</span></dt><dd><?= h(!empty($db_user['created_at']) ? date('d.m.Y', strtotime($db_user['created_at'])) : ($src_contract['data_zawarcia'] ?? '—')) ?></dd></div>
  </dl>
  <p class="tzx-muted tw-mt-3 tw-mb-0 tw-max-w-[75ch]">
    Numer UID został nadany automatycznie przy zawarciu umowy i jest Twoim stałym identyfikatorem w rejestrze tożsamości — nie można go zmienić.
    Login do panelu (identyfikator sieciowy) jest niezależny od loginu Microsoft 365.
    <span class="lbl-en">UID is assigned at contract creation and is immutable. The panel sign-in may differ from the Microsoft 365 sign-in.</span>
  </p>

  <h3 class="tzx-h3">Rola i uprawnienia <span class="lbl-en tw-inline tw-font-normal">Role-based access</span></h3>
  <p class="tw-text-[.88rem] tw-text-slate-700 tw-m-0 tw-mb-2 tw-max-w-[75ch]">
    <strong><?= h($role_label) ?></strong> — <?= h($role_desc ?: 'rola nadana przez administratora; decyduje o tym, które moduły SZO widzisz i co możesz w nich zmieniać.') ?>
    <?php if ($access): ?>Dostęp do <strong><?= count($access) ?></strong> modułów, w tym <strong><?= $access_write ?></strong> z prawem edycji.<?php endif; ?>
  </p>
  <?php if ($access): ?>
  <div class="tw-flex tw-flex-wrap tw-gap-1.5" x-data="{ all: false }">
    <?php foreach ($access as $i => $a): ?>
    <span class="tzx-chip" <?= $i >= 12 ? 'x-show="all" x-cloak' : '' ?>><?= h($a['label']) ?> <span class="tw-text-slate-500">· <?= $a['level'] ?></span></span>
    <?php endforeach; ?>
    <?php if (count($access) > 12): ?>
    <button type="button" class="tzx-chip tw-cursor-pointer tw-text-blue-700 tw-border-blue-300" @click="all = !all" :aria-expanded="all ? 'true' : 'false'">
      <span x-text="all ? 'Zwiń' : 'Pokaż wszystkie (<?= count($access) ?>)'"></span>
    </button>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <p class="tzx-muted tw-m-0">Twoja rola nie daje dostępu do modułów systemu głównego (konto o zawężonym zakresie).</p>
  <?php endif; ?>
  <p class="tzx-muted tw-mt-3 tw-mb-0">Roli ani uprawnień nie zmienisz tutaj — nadaje je administrator. Jeśli brakuje Ci dostępu, zgłoś to przez helpdesk.</p>

  <h3 class="tzx-h3">Najczęstsze czynności</h3>
  <ul class="tzx-links">
    <li><a href="#bezpieczenstwo" @click.prevent="go('bezpieczenstwo'); $dispatch('tz-sec', 'haslo')">Zmiana hasła</a><small>SZO i Microsoft 365 jednocześnie</small></li>
    <li><a href="<?= APP_URL ?>/tozsamosc/mfa.php">Konfiguracja logowania dwuskładnikowego</a><small>Authenticator · SMS · klucz sprzętowy / passkey</small></li>
    <li><a href="#bezpieczenstwo" @click.prevent="go('bezpieczenstwo'); $dispatch('tz-sec', 'telefon')">Telefon do odzyskiwania dostępu</a><small>numer do kodów SMS</small></li>
    <li><a href="<?= APP_URL ?>/tozsamosc/rodo_export.php" target="_blank" rel="noopener">Eksport moich danych (PDF)</a><small>art. 15 i 20 RODO</small></li>
    <li><a href="<?= APP_URL ?>/helpdesk/index.php">Zgłoszenie problemu z kontem</a><small>helpdesk organizacji</small></li>
  </ul>
</section>

<!-- ═══════════ USŁUGI ═══════════ -->
<section id="uslugi" class="tzx-sec" tabindex="-1" aria-labelledby="h-uslugi">
  <h2 id="h-uslugi" class="tzx-h">Usługi i ważność dostępu <span class="lbl-en">Services &amp; access validity</span></h2>
  <p class="tzx-lead tw-mb-4">Systemy, do których logujesz się tą tożsamością — co jest włączone, co czeka na konfigurację, a co jest niedostępne.</p>
  <div class="tw-overflow-x-auto">
  <table class="tzx-table">
    <thead><tr><th>Usługa</th><th>Stan</th><th class="tw-hidden md:tw-table-cell">Opis</th><th class="tw-text-right">Działanie</th></tr></thead>
    <tbody>
    <?php foreach ($services as $s): ?>
    <tr<?= !empty($s['soon']) ? ' class="tw-text-slate-400"' : '' ?>>
      <td class="tw-font-semibold tw-text-slate-900 tw-whitespace-nowrap"><i class="bi <?= $s['icon'] ?> tw-mr-1.5 tw-text-slate-500" aria-hidden="true"></i><?= h($s['name']) ?>
        <div class="tzx-muted tw-font-normal md:tw-hidden tw-whitespace-normal tw-mt-0.5"><?= h($s['desc']) ?></div></td>
      <td class="tw-whitespace-nowrap"><span class="tzx-badge <?= $STATUS_CLASS[$s['status']] ?>"><i class="bi <?= $STATUS_ICON[$s['status']] ?>" aria-hidden="true"></i><?= h($s['status_label']) ?></span></td>
      <td class="tw-hidden md:tw-table-cell tw-text-slate-600"><?= h($s['desc']) ?></td>
      <td class="tw-text-right tw-whitespace-nowrap">
        <?php if (!empty($s['ldap'])): ?>
          <?php if ($ldap_enabled && !$has_ldap): ?>
          <form method="post" class="tw-m-0 tw-inline">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="_action" value="ldap_create">
            <button type="submit" class="tzx-btn tzx-btn--sm" onclick="return confirm('Utworzyć konto w katalogu LDAP na podstawie Twoich danych tożsamości?');">Utwórz konto</button>
          </form>
          <?php elseif ($has_ldap): ?>
          <form method="post" class="tw-m-0 tw-inline">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="_action" value="ldap_create">
            <button type="submit" class="tzx-btn tzx-btn--ghost tzx-btn--sm">Odśwież wpis</button>
          </form>
          <?php else: ?><span class="tzx-muted">—</span><?php endif; ?>
        <?php elseif (!empty($s['soon'])): ?>
          <span class="tzx-muted">wkrótce</span>
        <?php elseif (!empty($s['tab'])): ?>
          <a href="#<?= $s['tab'] ?>" class="tw-text-blue-700 tw-font-medium" @click.prevent="go('<?= $s['tab'] ?>')"><?= h($s['cta']) ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        <?php else: ?>
          <a href="<?= h($s['href']) ?>" class="tw-text-blue-700 tw-font-medium"><?= h($s['cta']) ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <h3 class="tzx-h3">Narzędzia zewnętrzne</h3>
  <ul class="tzx-links">
    <li><a href="<?= h($trello_ws_url) ?>" target="_blank" rel="noopener noreferrer">Trello <i class="bi bi-box-arrow-up-right tw-text-[.7rem]" aria-hidden="true"></i></a><small>Tablice Kanban — zadania i projekty zespołu<?php if (can_write('admin')): ?> · <a href="<?= APP_URL ?>/admin/trello_import.php" class="tw-text-blue-700">import zadań do SZO</a><?php endif; ?></small></li>
  </ul>
</section>

<!-- ═══════════ BEZPIECZEŃSTWO ═══════════ -->
<?php
// Nagłówki akordeonu: [klucz, ikona, tytuł, podtytuł EN, stan ok?, etykieta stanu]
$sec_heads = [
    'haslo'   => ['bi-key',          'Hasło do SZO' . ($has_m365 ? ' i Microsoft 365' : ''), 'Change / reconcile password',
                  $has_local_password, $has_local_password ? 'Ustawione' : 'Brak hasła lokalnego'],
    'telefon' => ['bi-phone',        'Telefon do SMS', 'Recovery / MFA phone',
                  $phone_verified, $phone_verified ? 'Zweryfikowany' : ($phone_pending ? 'Oczekuje na kod' : ($phone_saved ? 'Niezweryfikowany' : 'Brak numeru'))],
    'mfa'     => ['bi-shield-check', 'Metody weryfikacji (MFA)', 'Second factor & passkeys',
                  $mfa_active, $mfa_active ? $mfa_label : 'Brak drugiego składnika'],
    'pin'     => ['bi-telephone-outbound', 'Szybkie dzwonienie (mobile)', 'Quick dial sign-in',
                  $pin_is_set, !$pin_allowed ? 'Wyłączone przez administratora' : ($pin_is_set ? 'PIN ustawiony' : 'PIN nieustawiony')],
];
$sec_head = function (string $k) use ($sec_heads): void {
    [$ic, $t, $en, $ok, $st] = $sec_heads[$k];
    ?>
    <button type="button" class="tzx-acc-btn" :aria-expanded="sec === '<?= $k ?>' ? 'true' : 'false'" aria-controls="sec-<?= $k ?>-bd" id="sec-<?= $k ?>-hd" @click="open('<?= $k ?>')">
      <span class="tw-flex-1 tw-min-w-0">
        <span class="tw-block tw-text-[.95rem] tw-font-semibold tw-text-slate-900 tw-leading-tight"><?= h($t) ?> <span class="lbl-en tw-inline"><?= h($en) ?></span></span>
        <span class="tw-block tzx-muted tw-mt-0.5"><?= h($st) ?></span>
      </span>
      <span class="tzx-badge <?= $ok ? 'tzx-badge--ok' : 'tzx-badge--warn' ?> tw-hidden sm:tw-inline-flex"><?= $ok ? 'Gotowe' : 'Do uzupełnienia' ?></span>
      <span class="tzx-acc-plus" aria-hidden="true"><i class="bi" :class="sec === '<?= $k ?>' ? 'bi-dash-lg' : 'bi-plus-lg'"></i></span>
    </button>
    <?php
};
?>
<section id="bezpieczenstwo" class="tzx-sec" tabindex="-1" aria-labelledby="h-bezpieczenstwo"
         x-data="{ sec: '<?= $sec_open ?>',
                   open(k) { this.sec = this.sec === k ? '' : k; },
                   reveal(k) { this.sec = k; this.$nextTick(() => { var el = document.getElementById('sec-' + k); if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' }); }); } }"
         @tz-sec.window="reveal($event.detail)">
  <h2 id="h-bezpieczenstwo" class="tzx-h">Bezpieczeństwo konta <span class="lbl-en">Account security</span></h2>
  <p class="tzx-lead tw-mb-4">Hasło, telefon do odzyskiwania dostępu, drugi składnik logowania i PIN do dialera. Skonfigurowane: <strong><?= $sec_ok ?> z <?= $sec_total ?></strong> elementów. Rozwiń sekcję, aby zmienić ustawienia.</p>

  <div>

    <!-- ── HASŁO ── -->
    <article id="sec-haslo" class="tzx-acc" :class="sec === 'haslo' ? 'tzx-acc--open' : ''" x-data="tzPassword(<?= h(json_encode($account_parts)) ?>)">
      <?php $sec_head('haslo'); ?>
      <div id="sec-haslo-bd" class="tzx-acc-bd" role="region" aria-labelledby="sec-haslo-hd" x-show="sec === 'haslo'" x-transition.opacity.duration.150ms>
        <div class="tw-grid tw-grid-cols-1 lg:tw-grid-cols-5 tw-gap-5">
          <aside class="tzx-aside lg:tw-col-span-2 lg:tw-order-2">
            <h4><i class="bi bi-arrow-repeat tw-text-blue-600" aria-hidden="true"></i>Jedno hasło, <?= $has_m365 ? 'dwa systemy' : 'konto lokalne' ?></h4>
            <?php if ($has_m365): ?>
            Zmiana w tym miejscu ustawia to samo hasło w SZO i w Microsoft 365 (Entra ID) jednocześnie. Jeśli hasła się rozjechały — tu je ponownie uzgodnisz.
            <?php else: ?>
            Twoja tożsamość nie ma konta Microsoft 365 — hasło działa w SZO i w modułach, które z niego korzystają (CRM, EZD, panel).
            <?php endif; ?>
            <ul>
              <li>Min. 12 znaków, wielka i mała litera, cyfra, znak specjalny.</li>
              <li>Nie używaj imienia, nazwiska ani fragmentu loginu.</li>
              <li>Najlepiej fraza z kilku słów zapisana w menedżerze haseł.</li>
            </ul>
            <?php if (!$has_local_password): ?>
            <div class="tw-mt-3 tw-rounded-xl tw-bg-blue-50 tw-border tw-border-solid tw-border-blue-200 tw-text-blue-800 tw-px-3 tw-py-2 tw-text-[.78rem]"><i class="bi bi-info-circle tw-mr-1" aria-hidden="true"></i>Nie masz jeszcze hasła lokalnego SZO. Ustaw je — będzie też hasłem awaryjnym obok logowania Microsoft.</div>
            <?php endif; ?>
          </aside>
          <div class="lg:tw-col-span-3 lg:tw-order-1">
            <form method="post" id="pwdForm">
              <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_action" value="pwd_change">
              <?php if ($has_local_password): ?>
              <div class="tzx-field tw-mb-3 tw-max-w-md">
                <label for="pw_cur">Obecne hasło <span class="lbl-en">Current password</span></label>
                <input type="password" id="pw_cur" name="current_password" class="form-control" autocomplete="current-password" required>
              </div>
              <?php endif; ?>
              <div class="tzx-field tw-mb-3 tw-max-w-md">
                <label for="pw_new">Nowe hasło <span class="lbl-en">New password</span></label>
                <div class="input-group">
                  <input :type="show ? 'text' : 'password'" id="pw_new" name="new_password" class="form-control" autocomplete="new-password"
                         aria-describedby="pw_req pw_strength" minlength="12" required x-model="pw">
                  <button class="btn btn-outline-secondary" type="button" @click="show = !show"
                          aria-label="Pokaż lub ukryj hasło" :aria-pressed="show ? 'true' : 'false'"><i class="bi" :class="show ? 'bi-eye-slash' : 'bi-eye'" aria-hidden="true"></i></button>
                </div>
                <div class="tz-meter" aria-hidden="true"><span :style="'width:' + pct + '%;background-color:' + color"></span></div>
                <div class="form-text" id="pw_strength" aria-live="polite">Siła hasła: <span x-text="pw ? label : '—'"></span></div>
              </div>
              <div class="tzx-field tw-mb-3 tw-max-w-md">
                <label for="pw_conf">Powtórz nowe hasło <span class="lbl-en">Confirm new password</span></label>
                <input type="password" id="pw_conf" name="confirm_password" class="form-control" autocomplete="new-password" minlength="12" required x-model="pw2">
                <div class="form-text tw-text-rose-600" x-show="mismatch" x-cloak>Hasła nie są identyczne.</div>
              </div>
              <fieldset class="tw-mb-4 tw-max-w-2xl tw-border-0 tw-p-0 tw-m-0">
                <legend class="tw-text-[.8rem] tw-font-semibold tw-text-slate-800 tw-mb-0">Polityka haseł organizacji <span class="lbl-en">Entra ID password policy</span></legend>
                <ul class="tz-req" id="pw_req" aria-live="polite">
                  <li :class="{ok: req.len}"><span class="dot" aria-hidden="true" x-text="req.len ? '✓' : '○'"></span> Min. 12 znaków</li>
                  <li :class="{ok: req.up}"><span class="dot" aria-hidden="true" x-text="req.up ? '✓' : '○'"></span> Wielka litera (A–Z)</li>
                  <li :class="{ok: req.low}"><span class="dot" aria-hidden="true" x-text="req.low ? '✓' : '○'"></span> Mała litera (a–z)</li>
                  <li :class="{ok: req.num}"><span class="dot" aria-hidden="true" x-text="req.num ? '✓' : '○'"></span> Cyfra (0–9)</li>
                  <li :class="{ok: req.sym}"><span class="dot" aria-hidden="true" x-text="req.sym ? '✓' : '○'"></span> Znak specjalny (!@#…)</li>
                  <li :class="{ok: req.name}"><span class="dot" aria-hidden="true" x-text="req.name ? '✓' : '○'"></span> Nie zawiera nazwy konta</li>
                </ul>
              </fieldset>
              <button type="submit" class="tzx-btn"><i class="bi bi-key" aria-hidden="true"></i><?= $has_local_password ? 'Zmień hasło' : 'Ustaw hasło' ?></button>
            </form>
          </div>
        </div>
      </div>
    </article>

    <!-- ── TELEFON ── -->
    <article id="sec-telefon" class="tzx-acc" :class="sec === 'telefon' ? 'tzx-acc--open' : ''">
      <?php $sec_head('telefon'); ?>
      <div id="sec-telefon-bd" class="tzx-acc-bd" role="region" aria-labelledby="sec-telefon-hd" x-show="sec === 'telefon'" x-transition.opacity.duration.150ms>
        <div class="tw-grid tw-grid-cols-1 lg:tw-grid-cols-5 tw-gap-5">
          <aside class="tzx-aside lg:tw-col-span-2 lg:tw-order-2">
            <h4><i class="bi bi-life-preserver tw-text-blue-600" aria-hidden="true"></i>Po co numer telefonu</h4>
            Numer służy do odzyskiwania dostępu (kod SMS przy ustawianiu nowego hasła) i do powiadomień MFA. Nie jest używany do marketingu ani pokazywany innym użytkownikom.
            <ul>
              <li>Po zapisaniu wyślemy 6-cyfrowy kod — numer działa dopiero po jego wpisaniu.</li>
              <li>Zmiana numeru wymaga ponownej weryfikacji.</li>
            </ul>
            <?php if ($phone_saved && !$phone_pending): ?>
            <div class="tw-mt-3 tw-text-[.78rem]">Aktualny numer: <strong class="tw-font-mono tw-text-slate-900"><?= h(tz_mask_phone($phone_saved)) ?></strong></div>
            <?php endif; ?>
          </aside>
          <div class="lg:tw-col-span-3 lg:tw-order-1">
            <?php if (!$sms_available): ?>
              <div class="tw-rounded-xl tw-bg-amber-50 tw-border tw-border-solid tw-border-amber-200 tw-text-amber-800 tw-px-3.5 tw-py-2.5 tw-text-sm tw-mb-3" role="alert"><i class="bi bi-exclamation-triangle tw-mr-1" aria-hidden="true"></i>Weryfikacja SMS jest obecnie niedostępna.</div>
            <?php endif; ?>

            <?php if ($phone_pending): ?>
              <p class="tw-text-sm tw-mt-0">Wpisz 6-cyfrowy kod wysłany na numer <strong><?= h(tz_mask_phone($phone_pending)) ?></strong>. <span class="lbl-en tw-inline">Enter the SMS code.</span></p>
              <form method="post" class="tw-mt-2">
                <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="_action" value="phone_verify">
                <div class="tzx-field tw-mb-3 tw-max-w-[320px]">
                  <label for="tel_code">Kod weryfikacyjny</label>
                  <input type="text" id="tel_code" name="code" class="form-control tz-otp" inputmode="numeric"
                         autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" placeholder="000000"
                         aria-describedby="tel_code_help" autofocus required>
                  <div id="tel_code_help" class="form-text">Kod jest ważny 5 minut.</div>
                </div>
                <div class="tw-flex tw-flex-wrap tw-gap-2">
                  <button type="submit" class="tzx-btn"><i class="bi bi-check2-circle" aria-hidden="true"></i>Zweryfikuj</button>
                  <button type="submit" form="tel_resend" class="tzx-btn tzx-btn--ghost"><i class="bi bi-arrow-repeat" aria-hidden="true"></i>Wyślij ponownie</button>
                  <button type="submit" form="tel_cancel" class="btn btn-link text-muted">Anuluj</button>
                </div>
              </form>
              <form method="post" id="tel_resend" class="tw-hidden">
                <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="_action" value="phone_send">
                <input type="hidden" name="phone_number" value="<?= h($phone_pending) ?>">
              </form>
              <form method="post" id="tel_cancel" class="tw-hidden">
                <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="_action" value="phone_cancel">
              </form>
            <?php else: ?>
              <form method="post">
                <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="_action" value="phone_send">
                <div class="tzx-field tw-mb-3 tw-max-w-[360px]">
                  <label for="tel_num">Numer komórkowy <span class="lbl-en">Mobile number</span></label>
                  <div class="input-group">
                    <span class="input-group-text" id="tel_prefix">+48</span>
                    <input type="tel" id="tel_num" name="phone_number" class="form-control" inputmode="numeric"
                           autocomplete="tel-national" value="<?= h(substr(preg_replace('/\D/','',$phone_saved), -9)) ?>"
                           placeholder="600 100 200" aria-describedby="tel_prefix tel_help" required>
                  </div>
                  <div id="tel_help" class="form-text">Wyślemy 6-cyfrowy kod SMS. Standardowe opłaty operatora mogą obowiązywać.</div>
                </div>
                <div class="tw-flex tw-flex-wrap tw-gap-2">
                  <button type="submit" class="tzx-btn"><i class="bi bi-send" aria-hidden="true"></i><?= $phone_saved ? 'Zmień numer i wyślij kod' : 'Wyślij kod weryfikacyjny' ?></button>
                  <?php if ($phone_saved): ?>
                  <button type="submit" form="tel_remove" class="tzx-btn tzx-btn--danger" onclick="return confirm('Usunąć numer telefonu z tożsamości?')"><i class="bi bi-trash" aria-hidden="true"></i>Usuń numer</button>
                  <?php endif; ?>
                </div>
              </form>
              <?php if ($phone_saved): ?>
              <form method="post" id="tel_remove" class="tw-hidden">
                <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="_action" value="phone_remove">
              </form>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </article>

    <!-- ── MFA ── -->
    <article id="sec-mfa" class="tzx-acc" :class="sec === 'mfa' ? 'tzx-acc--open' : ''">
      <?php $sec_head('mfa'); ?>
      <div id="sec-mfa-bd" class="tzx-acc-bd" role="region" aria-labelledby="sec-mfa-hd" x-show="sec === 'mfa'" x-transition.opacity.duration.150ms>
        <div class="tw-grid tw-grid-cols-1 lg:tw-grid-cols-5 tw-gap-5">
          <aside class="tzx-aside lg:tw-col-span-2 lg:tw-order-2">
            <h4><i class="bi bi-shield-plus tw-text-blue-600" aria-hidden="true"></i>Drugi składnik logowania</h4>
            Nawet wykradzione hasło nie wystarczy do wejścia na konto. Sparuj smartfon z kontem organizacji — zalecamy aplikację <strong>Microsoft Authenticator</strong>.
            <ul>
              <li><strong>Klucz / passkey</strong> to najmocniejsza ochrona — odporna na phishing.</li>
              <li>Kody zapasowe zapisz w bezpiecznym miejscu; każdy działa raz.</li>
            </ul>
          </aside>
          <div class="lg:tw-col-span-3 lg:tw-order-1">
            <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-3 tw-mb-4">
              <a class="tzx-tile tw-no-underline tw-text-slate-900 hover:tw-text-slate-900<?= $totp_on ? ' tw-border-blue-300 tw-bg-blue-50/40' : '' ?>" href="<?= APP_URL ?>/tozsamosc/mfa.php">
                <div class="tw-flex tw-items-center tw-justify-between tw-gap-2">
                  <span class="tzx-ico <?= $totp_on ? 'tw-bg-blue-600 tw-text-white' : 'tw-bg-slate-100 tw-text-slate-500' ?>" aria-hidden="true"><i class="bi bi-phone"></i></span>
                  <?php if ($totp_on): ?><span class="tzx-badge tzx-badge--ok"><i class="bi bi-check-circle-fill" aria-hidden="true"></i>Aktywne</span><?php else: ?><span class="tzx-badge tzx-badge--off">Nieaktywne</span><?php endif; ?>
                </div>
                <div><span class="tw-block tw-text-sm tw-font-semibold">Aplikacja Authenticator</span><span class="lbl-en">TOTP (Microsoft / Google Authenticator)</span><span class="tzx-muted tw-block tw-mt-1">Kod jednorazowy z aplikacji — działa offline.</span></div>
              </a>
              <?php if ($sms_available): ?>
              <a class="tzx-tile tw-no-underline tw-text-slate-900 hover:tw-text-slate-900<?= $sms_on ? ' tw-border-emerald-300 tw-bg-emerald-50/40' : '' ?>" href="<?= APP_URL ?>/tozsamosc/mfa.php">
                <div class="tw-flex tw-items-center tw-justify-between tw-gap-2">
                  <span class="tzx-ico <?= $sms_on ? 'tw-bg-emerald-600 tw-text-white' : 'tw-bg-slate-100 tw-text-slate-500' ?>" aria-hidden="true"><i class="bi bi-chat-dots"></i></span>
                  <?php if ($sms_on): ?><span class="tzx-badge tzx-badge--ok"><i class="bi bi-check-circle-fill" aria-hidden="true"></i>Aktywne</span><?php else: ?><span class="tzx-badge tzx-badge--off">Nieaktywne</span><?php endif; ?>
                </div>
                <div><span class="tw-block tw-text-sm tw-font-semibold">Kod SMS</span><span class="lbl-en">Text message one-time code</span><span class="tzx-muted tw-block tw-mt-1">Jednorazowy kod na zweryfikowany numer telefonu.</span></div>
              </a>
              <?php endif; ?>
              <a class="tzx-tile tw-no-underline tw-text-slate-900 hover:tw-text-slate-900<?= $has_wk ? ' tw-border-emerald-300 tw-bg-emerald-50/40' : '' ?>" href="<?= APP_URL ?>/tozsamosc/mfa.php#webauthn">
                <div class="tw-flex tw-items-center tw-justify-between tw-gap-2">
                  <span class="tzx-ico <?= $has_wk ? 'tw-bg-emerald-700 tw-text-white' : 'tw-bg-slate-100 tw-text-slate-500' ?>" aria-hidden="true"><i class="bi bi-fingerprint"></i></span>
                  <?php if ($has_wk): ?><span class="tzx-badge tzx-badge--ok"><i class="bi bi-check-circle-fill" aria-hidden="true"></i><?= count($wk_keys_idx) ?> klucz<?= count($wk_keys_idx) > 1 ? 'e' : '' ?></span><?php else: ?><span class="tzx-badge tzx-badge--off">Brak</span><?php endif; ?>
                </div>
                <div><span class="tw-block tw-text-sm tw-font-semibold">Klucz / Passkey</span><span class="lbl-en">WebAuthn / FIDO2 / Passkeys</span><span class="tzx-muted tw-block tw-mt-1">Biometria, YubiKey lub Windows Hello — ochrona przed phishingiem.</span></div>
              </a>
            </div>
            <a href="<?= APP_URL ?>/tozsamosc/mfa.php" class="tzx-btn"><i class="bi bi-shield-plus" aria-hidden="true"></i>Zarządzaj metodami weryfikacji</a>
          </div>
        </div>
      </div>
    </article>

    <!-- ── Szybkie dzwonienie: UID + PIN ── -->
    <article id="sec-pin" class="tzx-acc" :class="sec === 'pin' ? 'tzx-acc--open' : ''">
      <?php $sec_head('pin'); ?>
      <div id="sec-pin-bd" class="tzx-acc-bd" role="region" aria-labelledby="sec-pin-hd" x-show="sec === 'pin'" x-transition.opacity.duration.150ms>
        <div class="tw-grid tw-grid-cols-1 lg:tw-grid-cols-5 tw-gap-5">
          <aside class="tzx-aside lg:tw-col-span-2 lg:tw-order-2">
            <h4><i class="bi bi-phone-vibrate tw-text-blue-600" aria-hidden="true"></i>Dialer CRM na telefonie</h4>
            Aplikacja mobilna <strong>Dzwoń</strong> pozwala wyszukać kontakt CRM i zadzwonić jednym dotknięciem. Wchodzisz do niej numerem konta i PIN-em — a potem, jak wszędzie w CRM, kodem IKA. Ta sesja otwiera <strong>wyłącznie</strong> dialer.
            <dl class="tw-grid tw-grid-cols-1 tw-gap-2 tw-m-0 tw-mt-3">
              <div class="tzx-kv tw-bg-white"><dt>Adres aplikacji <span class="lbl-en">App address</span></dt><dd><a href="<?= h($pin_app_url) ?>" class="tw-text-blue-700"><?= h(preg_replace('#^https?://#', '', $pin_app_url)) ?></a> <span class="tzx-muted tw-font-normal">— dodaj do ekranu głównego</span></dd></div>
              <div class="tzx-kv tw-bg-white"><dt>Numer konta (UID) <span class="lbl-en">Account number</span></dt><dd class="tw-font-mono"><?= (int)$uid ?></dd></div>
              <div class="tzx-kv tw-bg-white"><dt>PIN <span class="lbl-en">Quick dial PIN</span></dt><dd>
                <?php if (!$pin_allowed): ?>Wyłączone przez administratora
                <?php elseif ($pin_is_set): ?>Ustawiony<?php if (!empty($db_user['mobile_pin_set_at'])): ?> <span class="tzx-muted tw-font-normal">od <?= h(date('d.m.Y', strtotime((string)$db_user['mobile_pin_set_at']))) ?></span><?php endif; ?>
                  <?php if ($pin_lock): ?> <span class="tzx-badge tzx-badge--warn">Zablokowany na <?= (int)$pin_lock ?> min po błędnych próbach</span><?php endif; ?>
                <?php else: ?>Nieustawiony<?php endif; ?>
              </dd></div>
            </dl>
          </aside>
          <div class="lg:tw-col-span-3 lg:tw-order-1">
            <?php if ($pin_allowed): ?>
            <form method="post" class="tw-mb-2">
              <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_action" value="mobile_pin_save">
              <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-2 tw-items-end">
                <div class="tzx-field">
                  <label for="mpin"><?= $pin_is_set ? 'Nowy PIN' : 'PIN' ?> (<?= MOBILE_PIN_LENGTH ?> cyfry)</label>
                  <input type="password" id="mpin" name="mobile_pin" class="form-control" required
                         inputmode="numeric" autocomplete="new-password" pattern="[0-9]*"
                         minlength="<?= MOBILE_PIN_LENGTH ?>" maxlength="<?= MOBILE_PIN_LENGTH ?>" aria-describedby="mpin_help">
                </div>
                <div class="tzx-field">
                  <label for="mpin2">Powtórz PIN</label>
                  <input type="password" id="mpin2" name="mobile_pin_confirm" class="form-control" required
                         inputmode="numeric" autocomplete="new-password" pattern="[0-9]*"
                         minlength="<?= MOBILE_PIN_LENGTH ?>" maxlength="<?= MOBILE_PIN_LENGTH ?>">
                </div>
                <div><button type="submit" class="tzx-btn tw-w-full"><i class="bi bi-key" aria-hidden="true"></i><?= $pin_is_set ? 'Zmień PIN' : 'Ustaw PIN' ?></button></div>
              </div>
              <div id="mpin_help" class="form-text tw-mt-2">
                Bez powtórzonej cyfry i bez ciągu kolejnych cyfr. Po <?= MOBILE_PIN_MAX_FAILS ?> błędnych próbach
                logowanie PIN-em blokuje się na <?= MOBILE_PIN_LOCK_MIN ?> min — zwykłe logowanie działa dalej.
                PIN-u nie zobaczy nikt, także administrator; jeśli go zapomnisz, ustaw nowy tutaj.
              </div>
            </form>
            <?php if ($pin_is_set): ?>
            <form method="post" onsubmit="return confirm('Usunąć PIN? Szybkie logowanie do dialera przestanie działać.')">
              <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_action" value="mobile_pin_remove">
              <button type="submit" class="tzx-btn tzx-btn--danger tzx-btn--sm"><i class="bi bi-trash" aria-hidden="true"></i>Usuń PIN</button>
            </form>
            <?php endif; ?>
            <?php else: ?>
            <p class="tzx-muted tw-m-0">Logowanie PIN-em jest wyłączone dla tego konta przez administratora.</p>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </article>

  </div>
</section>

<?php if ($has_ika): ?>
<!-- ═══════════ ZMIEŃ IKA ═══════════ -->
<section id="ika" class="tzx-sec" tabindex="-1" aria-labelledby="h-ika">
  <h2 id="h-ika" class="tzx-h">Zmień kod IKA</h2>
  <div class="tzx-card tw-max-w-3xl">
    <div class="tzx-card-hd">
      <i class="bi bi-key tw-text-blue-600" aria-hidden="true"></i>
      <h2 class="tzx-h2">Zmień kod IKA <span class="lbl-en tw-inline">Change your access code</span></h2>
      <span class="tw-ml-auto tzx-badge <?= $ika_blocked ? 'tzx-badge--warn' : 'tzx-badge--ok' ?>"><?= $ika_blocked ? 'Tymczasowo zablokowany' : 'Aktywny' ?></span>
    </div>
    <div class="tzx-card-bd">
      <p class="tzx-muted tw-mt-0">
        <strong>IKA</strong> (Indywidualny Kod Autoryzacyjny) to 6-cyfrowy kod potwierdzający Twoją tożsamość
        przy dostępie do chronionych danych (np. umów). Aby go zmienić, podaj obecny kod i ustaw nowy.
      </p>
      <?php if ($ika_blocked): ?>
      <div class="tw-rounded-xl tw-bg-amber-50 tw-border tw-border-solid tw-border-amber-200 tw-text-amber-800 tw-px-3.5 tw-py-2.5 tw-text-sm tw-mb-3" role="alert">
        <i class="bi bi-hourglass-split tw-mr-1" aria-hidden="true"></i>Kod IKA jest tymczasowo zablokowany po błędnych próbach. Spróbuj ponownie później.
      </div>
      <?php endif; ?>
      <form method="post" class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-3 tw-items-end tw-max-w-2xl">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_action" value="change_ika">
        <div class="tzx-field">
          <label for="ika_current">Obecny kod IKA</label>
          <input type="text" id="ika_current" name="ika_current" class="form-control tz-otp"
                 inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="••••••" autocomplete="off" required <?= $ika_blocked ? 'disabled' : '' ?>>
        </div>
        <div class="tzx-field">
          <label for="ika_new">Nowy kod IKA</label>
          <input type="text" id="ika_new" name="ika_new" class="form-control tz-otp"
                 inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="000000" autocomplete="off" required <?= $ika_blocked ? 'disabled' : '' ?>>
        </div>
        <div class="tzx-field">
          <label for="ika_confirm">Powtórz nowy kod IKA</label>
          <input type="text" id="ika_confirm" name="ika_confirm" class="form-control tz-otp"
                 inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="000000" autocomplete="off" required <?= $ika_blocked ? 'disabled' : '' ?>>
        </div>
        <div class="sm:tw-col-span-3"><button type="submit" class="tzx-btn" <?= $ika_blocked ? 'disabled' : '' ?>><i class="bi bi-key" aria-hidden="true"></i>Zmień kod IKA</button></div>
      </form>
      <p class="tzx-muted tw-mt-4 tw-mb-0"><i class="bi bi-info-circle tw-mr-1" aria-hidden="true"></i>Kodu IKA nie udostępniaj nikomu. Jeśli go nie pamiętasz, zresetujesz go przy bramie IKA (AdminCode od administratora, kod e-mail lub PESEL).</p>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ═══════════ REJESTR CZYNNOŚCI ═══════════ -->
<section id="rejestr" class="tzx-sec" tabindex="-1" aria-labelledby="h-rejestr">
  <h2 id="h-rejestr" class="tzx-h">Rejestr czynności</h2>
  <div class="tw-grid tw-grid-cols-1 lg:tw-grid-cols-5 tw-gap-4">
    <div class="lg:tw-col-span-2 tw-grid tw-gap-4 tw-content-start">
      <div class="tzx-card">
        <div class="tzx-card-hd">
          <i class="bi bi-info-circle tw-text-blue-600" aria-hidden="true"></i>
          <h2 class="tzx-h2">Co dzieje się z Twoimi danymi <span class="lbl-en tw-inline">How your data is processed</span></h2>
          <a href="<?= APP_URL ?>/tozsamosc/rodo_export.php" target="_blank" rel="noopener" class="tzx-btn tzx-btn--ghost tzx-btn--sm tw-ml-auto" title="Pobierz eksport danych osobowych (PDF) — art. 15 i 20 RODO"><i class="bi bi-file-earmark-arrow-down" aria-hidden="true"></i>Eksport PDF</a>
        </div>
        <div class="tzx-card-bd">
          <dl class="tw-grid tw-grid-cols-1 tw-gap-2 tw-m-0">
            <div class="tzx-kv"><dt>Administrator danych</dt><dd><?= h($rodo_org['name'] ?: '—') ?></dd></div>
            <div class="tzx-kv"><dt>Siedziba</dt><dd><?= h(trim(($rodo_org['address'] ?? '') . ' ' . ($rodo_org['city'] ?? ''))) ?: '—' ?></dd></div>
            <div class="tzx-kv"><dt>NIP / KRS</dt><dd><?= h(trim(($rodo_org['nip'] ?? '') . ($rodo_org['krs'] ? ' / ' . $rodo_org['krs'] : ''))) ?: '—' ?></dd></div>
            <div class="tzx-kv"><dt>Cel przetwarzania</dt><dd>Realizacja umowy i obowiązków organizacji</dd></div>
            <div class="tzx-kv"><dt>Podstawa prawna</dt><dd>Wykonanie umowy (art. 6 ust. 1 lit. b RODO)</dd></div>
            <div class="tzx-kv"><dt>Okres przechowywania</dt><dd>Czas trwania umowy + okres wymagany przepisami</dd></div>
          </dl>
          <p class="tzx-muted tw-mt-3 tw-mb-0"><i class="bi bi-lock tw-mr-1" aria-hidden="true"></i>Masz prawo dostępu do swoich danych, sprostowania i ograniczenia przetwarzania. Upoważnienie oznacza obowiązek <strong>poufności</strong> — również po zakończeniu współpracy.</p>
        </div>
      </div>

      <div class="tzx-card">
        <div class="tzx-card-hd">
          <i class="bi bi-patch-check tw-text-blue-600" aria-hidden="true"></i>
          <h2 class="tzx-h2">Upoważnienia do przetwarzania danych</h2>
          <span class="tw-ml-auto tzx-badge tzx-badge--off"><?= count($rodo_auth) ?></span>
        </div>
        <div class="tzx-card-bd">
          <?php if (!$rodo_auth): ?>
            <p class="tzx-muted tw-m-0">Brak upoważnień powiązanych z Twoim kontem.</p>
          <?php else: ?>
            <div class="tw-grid tw-gap-2">
            <?php foreach ($rodo_auth as $a): $scope = json_decode($a['scope_items'] ?? '[]', true) ?: []; ?>
              <div class="tw-rounded-xl tw-border tw-border-solid tw-border-slate-200 tw-p-3<?= $a['status'] !== 'aktywne' ? ' tw-opacity-70' : '' ?>">
                <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-2">
                  <span class="tw-font-mono tw-font-bold tw-text-slate-500 tw-text-[.8rem]"><?= h($a['number']) ?></span>
                  <?= rodo_status_badge($a['status']) ?>
                  <a href="<?= APP_URL ?>/rodo/print.php?id=<?= (int)$a['id'] ?>" target="_blank" rel="noopener" class="tzx-btn tzx-btn--ghost tzx-btn--sm tw-ml-auto"><i class="bi bi-printer" aria-hidden="true"></i>Drukuj</a>
                </div>
                <div class="tzx-muted tw-mt-1">
                  od <?= !empty($a['authorized_from']) ? h(date('d.m.Y', strtotime($a['authorized_from']))) : '—' ?>
                  <?= !empty($a['authorized_until']) ? ' do ' . h(date('d.m.Y', strtotime($a['authorized_until']))) : ' (do zakończenia umowy)' ?>
                </div>
                <?php if ($scope || !empty($a['scope_custom'])): ?>
                <ul class="tw-mb-0 tw-mt-1 tw-pl-4 tw-text-[.82rem]">
                  <?php foreach ($scope as $k): ?><li><?= h(RODO_SCOPE_ITEMS[$k] ?? $k) ?></li><?php endforeach; ?>
                  <?php if (!empty($a['scope_custom'])): ?><li><?= h($a['scope_custom']) ?></li><?php endif; ?>
                </ul>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="tzx-card lg:tw-col-span-3" x-data="{ f: 'all' }">
      <div class="tzx-card-hd tw-flex-wrap">
        <i class="bi bi-file-earmark-text tw-text-blue-600" aria-hidden="true"></i>
        <h2 class="tzx-h2">Czynności na Twojej umowie</h2>
        <div class="tw-ml-auto tw-inline-flex tw-gap-0.5 tw-p-0.5 tw-rounded-lg tw-bg-slate-100" role="group" aria-label="Filtr rejestru">
          <button type="button" class="tzx-tab tw-py-1 tw-px-2.5 tw-text-xs" :aria-selected="f === 'all' ? 'true' : 'false'" @click="f = 'all'">Wszystko</button>
          <button type="button" class="tzx-tab tw-py-1 tw-px-2.5 tw-text-xs" :aria-selected="f === 'enter' ? 'true' : 'false'" @click="f = 'enter'">Wejścia</button>
          <button type="button" class="tzx-tab tw-py-1 tw-px-2.5 tw-text-xs" :aria-selected="f === 'mod' ? 'true' : 'false'" @click="f = 'mod'">Modyfikacje</button>
        </div>
      </div>
      <div class="tzx-card-bd">
        <p class="tzx-muted tw-mt-0">Rejestr wejść (dostępów) i modyfikacji danych umowy — ostatnie 50 zdarzeń.</p>
        <?php if (!$rodo_history): ?>
          <div class="tzx-muted">Brak zapisanych wejść ani modyfikacji.</div>
        <?php else: ?>
          <ol class="tzx-timeline tw-list-none tw-p-0 tw-m-0">
            <?php foreach ($rodo_history as $ev):
              [$lbl, $ic] = $ACT[$ev['action']] ?? [$ev['action'], 'bi-dot'];
              $is_enter = in_array($ev['action'], $REG_ENTER, true);
            ?>
            <li x-show="f === 'all' || f === '<?= $is_enter ? 'enter' : 'mod' ?>'">
              <span class="tzx-dot <?= $is_enter ? 'tw-border-slate-400 tw-text-slate-500' : 'tw-border-blue-500 tw-text-blue-600' ?>" aria-hidden="true"><i class="bi <?= h($ic) ?>"></i></span>
              <div class="tw-text-[.88rem] tw-font-semibold tw-text-slate-900 tw-flex tw-flex-wrap tw-items-center tw-gap-1.5">
                <?= h($lbl) ?>
                <span class="tzx-badge <?= $is_enter ? 'tzx-badge--off' : 'tzx-badge--info' ?>"><?= $is_enter ? 'Wejście' : 'Modyfikacja' ?></span>
                <span class="tzx-muted tw-font-normal">· <?= h(ucfirst($ev['contract_type'])) ?></span>
              </div>
              <div class="tzx-muted">
                <?= !empty($ev['created_at']) ? h(date('d.m.Y H:i', strtotime($ev['created_at']))) : '' ?>
                <?php if (!empty($ev['note'])): ?> · <?= h(mb_strimwidth($ev['note'], 0, 120, '…')) ?><?php endif; ?>
              </div>
            </li>
            <?php endforeach; ?>
          </ol>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<!-- ═══════════ O PANELU ═══════════ -->
<section id="opanelu" class="tzx-sec" tabindex="-1" aria-labelledby="h-opanelu">
  <h2 id="h-opanelu" class="tzx-h">O panelu</h2>
  <div class="tzx-card">
    <div class="tzx-card-hd"><i class="bi bi-info-circle tw-text-blue-600" aria-hidden="true"></i><h2 class="tzx-h2">O panelu <span class="lbl-en tw-inline">About this panel</span></h2></div>
    <div class="tzx-card-bd">
      <p class="tw-mb-4 tw-text-[.92rem]">
        <strong>System Tożsamości</strong> to Twoje <strong>„konto o koncie"</strong> — jedno miejsce, w którym zarządzasz
        <strong>logowaniem i tożsamością</strong> w organizacji (login, hasło, telefon, uwierzytelnianie).
        <strong>Nie służy do codziennej pracy</strong> — zadania, umowy i komunikaty załatwiasz w systemie SZO.
      </p>
      <div class="tw-grid tw-grid-cols-1 md:tw-grid-cols-2 tw-gap-3 tw-mb-5">
        <div class="tw-rounded-2xl tw-p-4 tw-bg-emerald-50 tw-border tw-border-solid tw-border-emerald-200">
          <div class="tw-font-bold tw-mb-2 tw-text-emerald-800"><i class="bi bi-check-circle-fill tw-mr-1" aria-hidden="true"></i>Do tego służy</div>
          <ul class="tw-mb-0 tw-pl-4 tw-text-[.86rem] tw-leading-7">
            <li>Sprawdzić swój <strong>numer UID</strong> i login (identyfikator sieciowy)</li>
            <li><strong>Zmienić lub zresetować hasło</strong> (panel SZO + Microsoft 365 naraz)</li>
            <li>Ustawić i zweryfikować <strong>numer telefonu</strong> (SMS, odzyskiwanie)</li>
            <li>Włączyć <strong>logowanie dwuetapowe (MFA)</strong></li>
            <li>Zobaczyć swoje <strong>usługi, upoważnienia RODO i rejestr czynności</strong></li>
          </ul>
        </div>
        <div class="tw-rounded-2xl tw-p-4 tw-bg-orange-50 tw-border tw-border-solid tw-border-orange-200">
          <div class="tw-font-bold tw-mb-2 tw-text-orange-800"><i class="bi bi-x-circle-fill tw-mr-1" aria-hidden="true"></i>Czym NIE jest</div>
          <ul class="tw-mb-0 tw-pl-4 tw-text-[.86rem] tw-leading-7">
            <li>To <strong>nie</strong> panel do pracy — <strong>zadań, komunikatów, kalendarza</strong> szukaj w <a href="<?= APP_URL ?>/portal.php">SZO</a></li>
            <li><strong>Nie</strong> znajdziesz tu <strong>treści umów ani dokumentów</strong> (panel SZO / EZD)</li>
            <li><strong>Nie</strong> zgłaszasz tu problemów — od tego jest <strong>helpdesk</strong></li>
            <li><strong>Nie</strong> zmienisz tu swojej <strong>roli ani uprawnień</strong> (robi to administrator)</li>
            <li>To <strong>nie</strong> jest Twoja <strong>skrzynka e-mail</strong> — pocztę masz w Microsoft 365</li>
          </ul>
        </div>
      </div>
      <h3 class="tzx-h3"><i class="bi bi-diagram-3 tw-text-blue-600" aria-hidden="true"></i>Jak to działa</h3>
      <ul class="tw-text-[.86rem] tw-leading-7 tw-mb-5 tw-pl-4">
        <li>Twoje konto i <strong>numer UID</strong> powstają automatycznie w chwili <strong>zawarcia umowy</strong> — UID jest stały i niezmienny.</li>
        <li><strong>Login do panelu</strong> (identyfikator sieciowy) może różnić się od <strong>loginu Microsoft 365</strong> — oba widzisz w karcie tożsamości u góry.</li>
        <li>Zmiana hasła <strong>synchronizuje się</strong> jednocześnie z panelem SZO i Microsoft 365 / Entra ID.</li>
      </ul>
      <h3 class="tzx-h3"><i class="bi bi-life-preserver tw-text-blue-600" aria-hidden="true"></i>Potrzebujesz pomocy?</h3>
      <div class="tw-flex tw-flex-wrap tw-gap-2">
        <a href="<?= APP_URL ?>/helpdesk/index.php" class="tzx-btn tzx-btn--ghost tzx-btn--sm"><i class="bi bi-headset" aria-hidden="true"></i>Zgłoś do helpdesku</a>
        <a href="<?= APP_URL ?>/portal.php" class="tzx-btn tzx-btn--ghost tzx-btn--sm"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>Przejdź do SZO</a>
        <a href="<?= APP_URL ?>/user/verify_reset.php" class="tzx-btn tzx-btn--ghost tzx-btn--sm"><i class="bi bi-key" aria-hidden="true"></i>Nie mogę się zalogować</a>
      </div>
      <?php if (!$first_visit): ?>
      <p class="tzx-muted tw-mt-4 tw-mb-0"><i class="bi bi-check2 tw-mr-1" aria-hidden="true"></i>Zapoznałeś się z wprowadzeniem.</p>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php if (is_admin()): ?>
<!-- ═══════════ ADMINISTRACJA ═══════════ -->
<section id="administracja" class="tzx-sec" tabindex="-1" aria-labelledby="h-administracja">
  <h2 id="h-administracja" class="tzx-h">Administracja tożsamością</h2>
  <div class="tzx-card">
    <div class="tzx-card-hd"><i class="bi bi-shield-fill-gear tw-text-orange-600" aria-hidden="true"></i><h2 class="tzx-h2">Administracja tożsamością <span class="lbl-en tw-inline">Admin controls</span></h2></div>
    <div class="tzx-card-bd">
      <p class="tzx-muted tw-mt-0">Zarządzanie kontami, rolami i federacją — w chrome Systemu Tożsamości.</p>
      <?php $admin_tiles = [
        ['Użytkownicy',        'Konta, role, aktywacja, import z katalogu AD', 'bi-people',          'orange', APP_URL . '/tozsamosc/uzytkownicy.php'],
        ['Role i uprawnienia', 'Macierz modułów per rola',                      'bi-diagram-3',       'indigo', APP_URL . '/tozsamosc/role.php'],
        ['Dodatkowe moduły',   'Uprawnienia indywidualne ponad rolę',           'bi-grid-3x3-gap',    'blue',   APP_URL . '/tozsamosc/moduly.php'],
        ['Kody IKA',           'Nadawanie i reset kodów autoryzacyjnych',       'bi-123',             'emerald',APP_URL . '/tozsamosc/ika_admin.php'],
        ['Synchronizacja kont','Wysyłka zmian do systemów zewnętrznych',        'bi-arrow-repeat',    'teal',   APP_URL . '/tozsamosc/sync.php'],
        ['Katalog LDAP',       'Połączenie i wpisy w katalogu organizacji',    'bi-diagram-2',       'slate',  APP_URL . '/tozsamosc/ldap.php'],
        ['SAML / SSO',         'Federacja tożsamości (IdP)',                    'bi-boxes',           'sky',    APP_URL . '/tozsamosc/saml.php'],
        ['Ustawienia logowania','Metody logowania, polityki',                   'bi-sliders',         'violet', APP_URL . '/tozsamosc/ustawienia.php'],
        ['Macierz dostępu EZD','Kto widzi co w Wirtualnym biurku',              'bi-shield-check',    'teal',   APP_URL . '/tozsamosc/ezd_macierz.php'],
        ['Rejestr audytu',     'Zdarzenia logowania i zmian kont',              'bi-journal-text',    'amber',  APP_URL . '/tozsamosc/audit.php'],
        ['Klucze API',         'Dostęp aplikacji zewnętrznych',                 'bi-key-fill',        'slate',  APP_URL . '/admin/api_manage.php?tab=api'],
      ]; ?>
      <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 xl:tw-grid-cols-3 tw-gap-3">
        <?php foreach ($admin_tiles as [$t, $d, $i, $c, $u]): ?>
        <a class="tzx-link-tile" href="<?= h($u) ?>">
          <span class="tzx-ico tw-bg-<?= $c ?>-50 tw-text-<?= $c ?>-700" aria-hidden="true"><i class="bi <?= $i ?>"></i></span>
          <span class="tw-flex-1 tw-min-w-0"><span class="tw-block tw-text-sm tw-font-semibold"><?= h($t) ?></span><span class="tzx-muted"><?= h($d) ?></span></span>
          <i class="bi bi-chevron-right tw-text-slate-400" aria-hidden="true"></i>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>

</div><!-- /treść -->

<!-- ═══════════ KOLUMNA BOCZNA ═══════════ -->
<aside class="tzx-side" aria-label="Informacje o koncie">
  <section aria-labelledby="side-id">
    <h3 id="side-id">Konto</h3>
    <dl>
      <dt>Użytkownik</dt><dd class="tw-font-semibold"><?= h($user['name']) ?></dd>
      <dt>Numer UID</dt><dd class="tw-font-mono tw-font-bold"><?= h($uid) ?></dd>
      <dt>Identyfikator sieciowy</dt><dd><?= h($panel_login ?: '—') ?></dd>
      <dt>Login Microsoft 365</dt><dd><?= h($m365_login ?: ($has_m365 ? $panel_login : 'brak konta M365')) ?></dd>
      <dt>Rola</dt><dd><?= h($role_label) ?></dd>
      <dt>Typ konta</dt><dd><?= h($account_type_label) ?></dd>
    </dl>
  </section>

  <section aria-labelledby="side-sec">
    <h3 id="side-sec">Stan zabezpieczeń</h3>
    <p class="tw-m-0 tw-mb-1 tw-text-slate-700"><strong><?= $sec_ok ?> z <?= $sec_total ?></strong> elementów skonfigurowanych — <?= $sec_pct >= 80 ? 'konto dobrze zabezpieczone.' : ($sec_pct >= 50 ? 'warto dokończyć konfigurację.' : 'zabezpiecz konto.') ?></p>
    <ul class="tzx-check">
      <?php foreach ($sec_items as $it): ?>
      <li>
        <i class="bi <?= $it['ok'] ? 'bi-check-square-fill tw-text-emerald-600' : 'bi-square tw-text-slate-400' ?>" aria-hidden="true"></i>
        <span class="tw-flex-1"><span class="tw-sr-only"><?= $it['ok'] ? 'gotowe: ' : 'do zrobienia: ' ?></span><?= h($it['label']) ?> <span class="tzx-muted">· <?= h($it['hint']) ?></span>
          <?php if (!$it['ok']): ?> <a href="#<?= $it['tab'] ?>" class="tw-font-semibold" @click.prevent="go('<?= $it['tab'] ?>')<?= !empty($it['sec']) ? "; \$dispatch('tz-sec', '" . $it['sec'] . "')" : '' ?>">ustaw</a><?php endif; ?>
        </span>
      </li>
      <?php endforeach; ?>
    </ul>
  </section>

  <section aria-labelledby="side-toc">
    <h3 id="side-toc">Na tej stronie</h3>
    <nav class="tzx-toc" aria-label="Sekcje">
      <?php foreach ($tabs as $tk => [$tl, $tic]): ?>
      <a href="#<?= $tk ?>" :class="cur === '<?= $tk ?>' ? 'on' : ''" @click.prevent="go('<?= $tk ?>')"><?= h($tl) ?></a>
      <?php endforeach; ?>
      <a href="<?= APP_URL ?>/tozsamosc/mfa.php">Metody weryfikacji <i class="bi bi-arrow-right tw-text-[.7rem]" aria-hidden="true"></i></a>
    </nav>
  </section>

  <section aria-labelledby="side-help">
    <h3 id="side-help">Wsparcie</h3>
    <p class="tw-m-0 tw-text-slate-700">Problem z logowaniem, hasłem lub dostępem: <a href="<?= APP_URL ?>/helpdesk/index.php">helpdesk organizacji</a>.</p>
    <p class="tw-m-0 tw-mt-1.5 tw-text-slate-700">Adres usługi: <span class="tw-font-mono tw-text-[.8rem]"><?= h(preg_replace('#^https?://#', '', APP_URL)) ?>/tozsamosc/</span></p>
  </section>
</aside>
</div><!-- /układ -->
</div><!-- /x-data tzIdentity -->

<script>
document.addEventListener('alpine:init', function () {
  // Strona „dokumentowa": wszystkie sekcje widoczne, #hash prowadzi do sekcji (przekierowania po zapisie używają
  // #bezpieczenstwo itp.), spis treści w kolumnie bocznej podświetla bieżącą sekcję (IntersectionObserver).
  Alpine.data('tzIdentity', function (cfg) {
    return {
      cur: '', sections: cfg.sections || [], q: '', status: '',
      init: function () {
        var self = this;
        var h = (location.hash || '').slice(1);
        if (this.sections.indexOf(h) >= 0) { this.cur = h; this.$nextTick(function () { self.go(h, 'auto'); }); }
        else if (cfg.firstVisit) { this.cur = 'podstawowe'; }
        else { this.cur = this.sections[0] || ''; }
        if ('IntersectionObserver' in window) {
          var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (en) { if (en.isIntersecting) self.cur = en.target.id; });
          }, { rootMargin: '-25% 0px -65% 0px', threshold: 0 });
          this.sections.forEach(function (id) { var el = document.getElementById(id); if (el) io.observe(el); });
        }
        window.addEventListener('hashchange', function () {
          var h2 = (location.hash || '').slice(1);
          if (self.sections.indexOf(h2) >= 0) self.go(h2);
        });
      },
      go: function (t, behavior) {
        var el = document.getElementById(t);
        if (!el) return;
        this.cur = t;
        if (history.replaceState) history.replaceState(null, '', '#' + t);
        el.scrollIntoView({ behavior: behavior || 'smooth', block: 'start' });
        el.focus({ preventScroll: true });
      },
      match: function (hay, st) {
        var q = this.q.trim().toLowerCase();
        if (this.status && this.status !== st) return false;
        return !q || hay.indexOf(q) >= 0;
      }
    };
  });
  // Siła hasła: te same reguły co walidacja po stronie serwera (12 znaków, wielka, mała, cyfra, znak, bez nazwy konta).
  Alpine.data('tzPassword', function (account) {
    account = account || [];
    return {
      pw: '', pw2: '', show: false,
      get req() {
        var v = this.pw;
        return { len: v.length >= 12, up: /[A-ZĄĆĘŁŃÓŚŹŻ]/.test(v), low: /[a-ząćęłńóśźż]/.test(v), num: /[0-9]/.test(v), sym: /[^A-Za-z0-9]/.test(v),
                 name: v.length > 0 && !account.some(function (p) { return v.toLowerCase().indexOf(p) >= 0; }) };
      },
      get score() { var r = this.req, s = 0; for (var k in r) if (r[k]) s++; return s; },
      get pct() { return this.score / 6 * 100; },
      get color() { var s = this.score; return s >= 6 ? '#16a34a' : s >= 5 ? '#22c55e' : s >= 3 ? '#f59e0b' : '#f87171'; },
      get label() { var s = this.score; return s >= 6 ? 'Bardzo silne · Very strong' : s >= 5 ? 'Silne · Strong' : s >= 3 ? 'Średnie · Medium' : 'Słabe · Weak'; },
      get mismatch() { return this.pw2.length > 0 && this.pw2 !== this.pw; }
    };
  });
});
</script>

<?php include __DIR__ . '/_foot.php';
