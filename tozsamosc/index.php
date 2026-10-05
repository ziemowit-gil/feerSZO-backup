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

$PAGE_TITLE = 'Tożsamość';
$TZ_ACTIVE  = 'konto';
include __DIR__ . '/_head.php';   // własny chrome podsystemu (bez menu SZO)
?>
<style type="text/tailwindcss">
/* ── Komponenty kaflowe (Tailwind bez preflightu: przy tw-border zawsze tw-border-solid) ── */
.tzx-card      { @apply tw-bg-white tw-rounded-2xl tw-border tw-border-solid tw-border-slate-200 tw-shadow-sm; }
.tzx-card-hd   { @apply tw-flex tw-items-center tw-gap-2.5 tw-px-5 tw-py-3.5 tw-border-b tw-border-solid tw-border-slate-100; }
.tzx-card-bd   { @apply tw-p-5; }
.tzx-h2        { @apply tw-m-0 tw-text-[.95rem] tw-font-semibold tw-text-slate-900 tw-tracking-tight; }
.tzx-h3        { @apply tw-m-0 tw-mb-2 tw-text-sm tw-font-semibold tw-text-slate-900 tw-flex tw-items-center tw-gap-1.5; }
.tzx-eyebrow   { @apply tw-text-[.66rem] tw-font-semibold tw-uppercase tw-tracking-[.08em] tw-text-slate-500; }
.tzx-muted     { @apply tw-text-xs tw-text-slate-500; }
.tzx-btn       { @apply tw-inline-flex tw-items-center tw-justify-center tw-gap-1.5 tw-rounded-lg tw-bg-blue-600 tw-text-white tw-px-3.5 tw-py-2 tw-text-sm tw-font-semibold tw-no-underline tw-border-0 tw-cursor-pointer tw-transition tw-shadow-sm hover:tw-bg-blue-700 hover:tw-text-white focus-visible:tw-outline focus-visible:tw-outline-[3px] focus-visible:tw-outline-offset-2 focus-visible:tw-outline-amber-400 disabled:tw-opacity-50 disabled:tw-cursor-not-allowed; }
.tzx-btn--sm   { @apply tw-px-3 tw-py-1.5 tw-text-xs; }
.tzx-btn--ghost{ @apply tw-bg-white tw-text-slate-700 tw-border tw-border-solid tw-border-slate-300 tw-shadow-none hover:tw-bg-slate-50 hover:tw-text-slate-900 hover:tw-border-slate-400; }
.tzx-btn--danger{ @apply tw-bg-white tw-text-rose-700 tw-border tw-border-solid tw-border-rose-200 tw-shadow-none hover:tw-bg-rose-50 hover:tw-text-rose-800 hover:tw-border-rose-300; }
.tzx-badge     { @apply tw-inline-flex tw-items-center tw-gap-1 tw-rounded-full tw-px-2 tw-py-0.5 tw-text-[.68rem] tw-font-semibold tw-border tw-border-solid tw-whitespace-nowrap; }
.tzx-badge--ok { @apply tw-bg-emerald-50 tw-text-emerald-700 tw-border-emerald-200; }
.tzx-badge--warn{ @apply tw-bg-amber-50 tw-text-amber-700 tw-border-amber-200; }
.tzx-badge--off{ @apply tw-bg-slate-100 tw-text-slate-600 tw-border-slate-200; }
.tzx-badge--info{ @apply tw-bg-blue-50 tw-text-blue-700 tw-border-blue-200; }
.tzx-tab       { @apply tw-inline-flex tw-items-center tw-gap-1.5 tw-rounded-lg tw-px-3 tw-py-2 tw-text-[.82rem] tw-font-medium tw-text-slate-600 tw-bg-transparent tw-border-0 tw-cursor-pointer tw-whitespace-nowrap tw-transition hover:tw-bg-slate-100 hover:tw-text-slate-900 focus-visible:tw-outline focus-visible:tw-outline-[3px] focus-visible:tw-outline-amber-400; }
.tzx-tab[aria-selected="true"] { @apply tw-bg-blue-600 tw-text-white tw-shadow-sm hover:tw-bg-blue-600 hover:tw-text-white; }
.tzx-kv        { @apply tw-rounded-xl tw-bg-slate-50 tw-border tw-border-solid tw-border-slate-100 tw-px-3.5 tw-py-2.5 tw-min-w-0; }
.tzx-kv dt     { @apply tw-text-[.64rem] tw-font-semibold tw-uppercase tw-tracking-[.06em] tw-text-slate-500 tw-m-0; }
.tzx-kv dd     { @apply tw-m-0 tw-mt-0.5 tw-text-[.88rem] tw-font-semibold tw-text-slate-900 tw-break-words; }
.tzx-tile      { @apply tw-flex tw-flex-col tw-gap-3 tw-rounded-2xl tw-bg-white tw-border tw-border-solid tw-border-slate-200 tw-p-4 tw-transition hover:tw-border-blue-300 hover:tw-shadow-md; }
.tzx-ico       { @apply tw-w-10 tw-h-10 tw-rounded-xl tw-flex tw-items-center tw-justify-center tw-text-lg tw-shrink-0; }
.tzx-chip      { @apply tw-inline-flex tw-items-center tw-gap-1 tw-rounded-md tw-bg-slate-50 tw-border tw-border-solid tw-border-slate-200 tw-px-2 tw-py-0.5 tw-text-[.72rem] tw-text-slate-700; }
.tzx-link-tile { @apply tw-flex tw-items-center tw-gap-3 tw-rounded-xl tw-border tw-border-solid tw-border-slate-200 tw-bg-white tw-p-3.5 tw-no-underline tw-text-slate-900 tw-transition hover:tw-border-blue-300 hover:tw-shadow-md hover:tw-text-slate-900 focus-visible:tw-outline focus-visible:tw-outline-[3px] focus-visible:tw-outline-amber-400; }
.tzx-timeline li { @apply tw-relative tw-pl-6 tw-pb-4 tw-border-l tw-border-solid tw-border-slate-200 tw-ml-2; }
.tzx-timeline li:last-child { @apply tw-pb-0; }
.tzx-dot       { @apply tw-absolute tw-w-4 tw-h-4 tw-rounded-full tw-bg-white tw-border-2 tw-border-solid tw-flex tw-items-center tw-justify-center tw-text-[.5rem]; left: -9px; top: 0; }
.tzx-field label { @apply tw-block tw-text-[.8rem] tw-font-semibold tw-text-slate-800 tw-mb-1; }
.tzx-field .form-text { @apply tw-text-xs tw-text-slate-500; }
.tzx-sec-row   { @apply tw-flex tw-items-center tw-gap-2.5 tw-py-2 tw-border-b tw-border-solid tw-border-slate-100 last:tw-border-b-0; }
</style>

<div x-data="tzIdentity(<?= h(json_encode(['tabs' => array_keys($tabs), 'firstVisit' => $first_visit])) ?>)" x-cloak>

<!-- ═══════════ NAGŁÓWEK STRONY ═══════════ -->
<div class="tw-flex tw-flex-wrap tw-items-end tw-justify-between tw-gap-3 tw-mb-4">
  <div>
    <div class="tzx-eyebrow tw-mb-1">System Tożsamości · Identity &amp; Access</div>
    <h1 class="tw-m-0 tw-text-[1.45rem] tw-font-bold tw-tracking-tight tw-text-slate-900">
      <i class="bi bi-person-vcard tw-text-blue-600 tw-mr-1" aria-hidden="true"></i>eTożsamość
    </h1>
    <p class="tw-m-0 tw-mt-0.5 tw-text-sm tw-text-slate-500">Centrum dostępów · jeden login, jedno hasło do wszystkich systemów organizacji</p>
  </div>
  <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-2">
    <span class="tzx-badge tw-bg-<?= $role_tone ?>-50 tw-text-<?= $role_tone ?>-700 tw-border-<?= $role_tone ?>-200 tw-py-1 tw-px-2.5 tw-text-xs" title="<?= h($role_desc) ?>">
      <i class="bi <?= $role_icon ?>" aria-hidden="true"></i><?= h($role_label) ?>
    </span>
    <span class="tzx-badge <?= $mfa_active ? 'tzx-badge--ok' : 'tzx-badge--warn' ?> tw-py-1 tw-px-2.5 tw-text-xs">
      <i class="bi <?= $mfa_active ? 'bi-shield-check' : 'bi-shield-exclamation' ?>" aria-hidden="true"></i><?= $mfa_active ? 'MFA aktywne' : 'MFA wyłączone' ?>
    </span>
  </div>
</div>

<?php if ($first_visit): ?>
<!-- Powitanie po pierwszym logowaniu (pokazywane raz) -->
<section class="tzx-card tw-mb-4 tw-border-blue-300 tw-bg-gradient-to-r tw-from-blue-50 tw-to-white" aria-labelledby="welcome-h">
  <div class="tzx-card-bd tw-flex tw-flex-wrap tw-items-center tw-gap-4">
    <span class="tzx-ico tw-bg-blue-600 tw-text-white tw-w-11 tw-h-11 tw-text-xl" aria-hidden="true"><i class="bi bi-stars"></i></span>
    <div class="tw-flex-1 tw-min-w-[220px]">
      <div class="tw-font-bold tw-text-slate-900" id="welcome-h">Witaj w Systemie Tożsamości 👋</div>
      <div class="tzx-muted">To Twoje pierwsze wejście. Zajrzyj do zakładki <strong>„O panelu"</strong> — w 20 sekund zrozumiesz, do czego to służy (a do czego nie).</div>
    </div>
    <div class="tw-flex tw-flex-wrap tw-gap-2">
      <a href="#opanelu" class="tzx-btn" @click.prevent="go('opanelu')"><i class="bi bi-info-circle" aria-hidden="true"></i>Zobacz „O panelu"</a>
      <form method="post" class="tw-m-0">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_action" value="dismiss_intro">
        <button type="submit" class="tzx-btn tzx-btn--ghost">Rozumiem, nie pokazuj</button>
      </form>
    </div>
  </div>
</section>
<?php endif; ?>

<?= function_exists('flash_html') ? flash_html() : '' ?>

<div aria-live="assertive">
<?php if ($errors): ?>
<div class="tw-rounded-2xl tw-border tw-border-solid tw-border-rose-200 tw-bg-rose-50 tw-text-rose-800 tw-px-4 tw-py-3 tw-mb-4 tw-text-sm" role="alert">
  <strong><i class="bi bi-exclamation-triangle-fill tw-mr-1" aria-hidden="true"></i>Popraw następujące błędy:</strong>
  <ul class="tw-mb-0 tw-mt-1 tw-pl-5"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>
</div>

<!-- ═══════════ KARTA TOŻSAMOŚCI + STAN ZABEZPIECZEŃ (zawsze widoczne) ═══════════ -->
<div class="tw-grid tw-grid-cols-1 lg:tw-grid-cols-3 tw-gap-4 tw-mb-4">

  <section class="tzx-card lg:tw-col-span-2" aria-labelledby="id-h">
    <div class="tzx-card-bd">
      <div class="tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-4">
        <div class="tw-flex tw-items-center tw-gap-3.5 tw-min-w-0">
          <span class="tw-w-14 tw-h-14 tw-rounded-2xl tw-bg-gradient-to-br tw-from-blue-600 tw-to-indigo-600 tw-text-white tw-flex tw-items-center tw-justify-center tw-text-lg tw-font-bold tw-shrink-0 tw-shadow-md" aria-hidden="true"><?= h($initials) ?></span>
          <div class="tw-min-w-0">
            <h2 id="id-h" class="tw-m-0 tw-text-lg tw-font-bold tw-text-slate-900 tw-truncate"><?= h($user['name']) ?></h2>
            <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-1.5 tw-mt-1">
              <span class="tzx-badge tw-bg-<?= $role_tone ?>-50 tw-text-<?= $role_tone ?>-700 tw-border-<?= $role_tone ?>-200"><i class="bi <?= $role_icon ?>" aria-hidden="true"></i><?= h($role_label) ?></span>
              <span class="tzx-badge tzx-badge--info"><i class="bi bi-file-earmark-person" aria-hidden="true"></i><?= h($account_type_label) ?></span>
              <?php if ($has_m365): ?><span class="tzx-badge tzx-badge--off"><i class="bi bi-microsoft" aria-hidden="true"></i>Entra ID</span><?php endif; ?>
            </div>
          </div>
        </div>
        <div class="tw-text-right tw-shrink-0">
          <div class="tzx-eyebrow">Numer UID</div>
          <div class="tw-font-mono tw-font-bold tw-text-[1.7rem] tw-leading-none tw-text-blue-700 tw-tracking-wider"><?= h($uid) ?></div>
        </div>
      </div>

      <dl class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 xl:tw-grid-cols-3 tw-gap-2.5 tw-mt-5 tw-mb-0">
        <div class="tzx-kv"><dt>Identyfikator sieciowy <span class="lbl-en">Network ID (login główny)</span></dt><dd><?= h($panel_login ?: '—') ?></dd></div>
        <div class="tzx-kv"><dt>Login Microsoft 365 <span class="lbl-en">M365 sign-in</span></dt><dd><?= h($m365_login ?: ($has_m365 ? $panel_login : '— brak konta M365 —')) ?></dd></div>
        <div class="tzx-kv"><dt>Identyfikator obiektu Entra <span class="lbl-en">Entra object ID</span></dt><dd class="tw-font-mono tw-text-[.74rem] tw-font-medium"><?= h($microsoft_id ?: '—') ?></dd></div>
        <div class="tzx-kv"><dt>Typ konta</dt><dd><?= h($account_type_label) ?></dd></div>
        <div class="tzx-kv"><dt>Źródło danych <span class="lbl-en">Data source</span></dt><dd><?php
          if ($src_contract) { echo 'Umowa ' . h($src_contract['numer_umowy']); }
          elseif (is_admin()) { echo 'Wpis do KRS – powołanie'; }
          else { echo '—'; }
        ?></dd></div>
        <div class="tzx-kv"><dt>UID nadany <span class="lbl-en">Assigned</span></dt><dd><?= h(!empty($db_user['created_at']) ? date('d.m.Y', strtotime($db_user['created_at'])) : ($src_contract['data_zawarcia'] ?? '—')) ?></dd></div>
      </dl>
    </div>
    <p class="tz-note tw-m-0 tw-rounded-b-2xl">
      <i class="bi bi-info-circle" aria-hidden="true"></i>
      <span>Numer UID został nadany automatycznie przy zawarciu umowy i jest Twoim stałym identyfikatorem w rejestrze tożsamości — nie można go zmienić. Login do panelu (identyfikator sieciowy) jest niezależny od loginu Microsoft 365.
      <span class="lbl-en">UID is assigned at contract creation and is immutable. The panel sign-in may differ from the Microsoft 365 sign-in.</span></span>
    </p>
  </section>

  <section class="tzx-card" aria-labelledby="sec-h">
    <div class="tzx-card-hd">
      <i class="bi bi-shield-lock tw-text-blue-600" aria-hidden="true"></i>
      <h2 id="sec-h" class="tzx-h2">Stan zabezpieczeń</h2>
      <span class="tw-ml-auto tzx-badge tw-bg-<?= $sec_tone ?>-50 tw-text-<?= $sec_tone ?>-700 tw-border-<?= $sec_tone ?>-200"><?= $sec_ok ?>/<?= $sec_total ?></span>
    </div>
    <div class="tzx-card-bd tw-pt-4">
      <div class="tw-flex tw-items-end tw-justify-between tw-gap-2 tw-mb-1.5">
        <div class="tw-text-2xl tw-font-bold tw-text-slate-900 tw-leading-none"><?= $sec_pct ?>%</div>
        <div class="tzx-muted tw-text-right"><?= $sec_pct >= 80 ? 'Konto dobrze zabezpieczone' : ($sec_pct >= 50 ? 'Warto dokończyć konfigurację' : 'Zabezpiecz konto') ?></div>
      </div>
      <div class="tw-h-2 tw-rounded-full tw-bg-slate-100 tw-overflow-hidden" role="progressbar" aria-valuenow="<?= $sec_pct ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Stan zabezpieczeń konta">
        <span class="tw-block tw-h-full tw-rounded-full tw-bg-<?= $sec_tone ?>-500" style="width:<?= $sec_pct ?>%"></span>
      </div>
      <ul class="tw-list-none tw-p-0 tw-m-0 tw-mt-3">
        <?php foreach ($sec_items as $it): ?>
        <li class="tzx-sec-row">
          <span class="tw-w-7 tw-h-7 tw-rounded-lg tw-flex tw-items-center tw-justify-center tw-text-sm tw-shrink-0 <?= $it['ok'] ? 'tw-bg-emerald-50 tw-text-emerald-600' : 'tw-bg-amber-50 tw-text-amber-600' ?>" aria-hidden="true"><i class="bi <?= $it['icon'] ?>"></i></span>
          <div class="tw-flex-1 tw-min-w-0">
            <div class="tw-text-[.82rem] tw-font-semibold tw-text-slate-800 tw-leading-tight"><?= h($it['label']) ?></div>
            <div class="tzx-muted tw-truncate"><?= h($it['hint']) ?></div>
          </div>
          <?php if ($it['ok']): ?>
            <i class="bi bi-check-circle-fill tw-text-emerald-500" aria-label="gotowe"></i>
          <?php else: ?>
            <a href="#<?= $it['tab'] ?>" class="tzx-btn tzx-btn--ghost tzx-btn--sm" @click.prevent="go('<?= $it['tab'] ?>')">Ustaw</a>
          <?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </section>
</div>

<!-- ═══════════ ZAKŁADKI ═══════════ -->
<div class="tw-sticky tw-top-[51px] tw-z-[5] tw-bg-[var(--tz-canvas)] tw-py-2 tw-mb-4">
  <div class="tw-inline-flex tw-flex-wrap tw-gap-0.5 tw-p-1 tw-rounded-xl tw-bg-white tw-border tw-border-solid tw-border-slate-200 tw-shadow-sm tw-max-w-full" role="tablist" aria-label="Sekcje tożsamości">
    <?php $ti = 0; foreach ($tabs as $tk => [$tl, $tic]): ?>
    <button type="button" role="tab" id="tab-<?= $tk ?>" class="tzx-tab<?= $tk === 'administracja' ? ' tw-text-orange-700' : '' ?>"
            :aria-selected="tab === '<?= $tk ?>' ? 'true' : 'false'" :tabindex="tab === '<?= $tk ?>' ? 0 : -1"
            aria-controls="<?= $tk ?>" x-ref="tab-<?= $tk ?>"
            @click="go('<?= $tk ?>')" @keydown="key($event, <?= $ti ?>)">
      <i class="bi <?= $tic ?>" aria-hidden="true"></i><?= h($tl) ?>
    </button>
    <?php $ti++; endforeach; ?>
  </div>
</div>

<!-- ═══════════ PODSTAWOWE ═══════════ -->
<section id="podstawowe" role="tabpanel" aria-labelledby="tab-podstawowe" tabindex="-1" x-show="tab === 'podstawowe'" x-transition.opacity.duration.150ms>
  <div class="tw-grid tw-grid-cols-1 lg:tw-grid-cols-3 tw-gap-4">
    <div class="tzx-card lg:tw-col-span-2">
      <div class="tzx-card-hd">
        <i class="bi bi-diagram-3 tw-text-blue-600" aria-hidden="true"></i>
        <h2 class="tzx-h2">Rola i uprawnienia <span class="lbl-en tw-inline">Role-based access</span></h2>
        <span class="tw-ml-auto tzx-badge tzx-badge--info"><?= count($access) ?> modułów · <?= $access_write ?> z edycją</span>
      </div>
      <div class="tzx-card-bd" x-data="{ all: false }">
        <div class="tw-flex tw-items-start tw-gap-3 tw-mb-4">
          <span class="tzx-ico tw-bg-<?= $role_tone ?>-50 tw-text-<?= $role_tone ?>-700" aria-hidden="true"><i class="bi <?= $role_icon ?>"></i></span>
          <div>
            <div class="tw-font-semibold tw-text-slate-900"><?= h($role_label) ?> <span class="tzx-muted tw-font-normal">· klucz roli <code class="tw-text-[.72rem]"><?= h($role_key) ?></code></span></div>
            <div class="tzx-muted tw-mt-0.5"><?= h($role_desc ?: 'Rola nadana przez administratora — decyduje o tym, które moduły SZO widzisz i co możesz w nich zmieniać.') ?></div>
          </div>
        </div>
        <?php if ($access): ?>
        <div class="tzx-eyebrow tw-mb-2">Moduły, do których masz dostęp</div>
        <div class="tw-flex tw-flex-wrap tw-gap-1.5">
          <?php foreach ($access as $i => $a): ?>
          <span class="tzx-chip" <?= $i >= 10 ? 'x-show="all" x-cloak' : '' ?>>
            <i class="bi <?= $a['level'] === 'edycja' ? 'bi-pencil-fill tw-text-blue-600' : 'bi-eye-fill tw-text-slate-400' ?>" aria-hidden="true"></i>
            <?= h($a['label']) ?> <span class="tw-text-slate-400">· <?= $a['level'] ?></span>
          </span>
          <?php endforeach; ?>
          <?php if (count($access) > 10): ?>
          <button type="button" class="tzx-chip tw-cursor-pointer tw-text-blue-700 tw-bg-blue-50 tw-border-blue-200" @click="all = !all" :aria-expanded="all ? 'true' : 'false'">
            <span x-text="all ? 'Zwiń' : 'Pokaż wszystkie (<?= count($access) ?>)'"></span>
          </button>
          <?php endif; ?>
        </div>
        <?php else: ?>
        <p class="tzx-muted tw-m-0">Twoja rola nie daje dostępu do modułów systemu głównego (konto o zawężonym zakresie).</p>
        <?php endif; ?>
        <p class="tzx-muted tw-mt-4 tw-mb-0"><i class="bi bi-info-circle tw-mr-1" aria-hidden="true"></i>Roli ani uprawnień nie zmienisz tutaj — nadaje je administrator w <strong>Role i uprawnienia</strong>. Jeśli brakuje Ci dostępu, zgłoś to przez helpdesk.</p>
      </div>
    </div>

    <div class="tzx-card">
      <div class="tzx-card-hd"><i class="bi bi-lightning-charge tw-text-blue-600" aria-hidden="true"></i><h2 class="tzx-h2">Szybkie akcje</h2></div>
      <div class="tzx-card-bd tw-grid tw-gap-2">
        <a href="#bezpieczenstwo" class="tzx-link-tile" @click.prevent="go('bezpieczenstwo')">
          <span class="tzx-ico tw-bg-blue-50 tw-text-blue-700" aria-hidden="true"><i class="bi bi-key"></i></span>
          <span class="tw-flex-1 tw-min-w-0"><span class="tw-block tw-text-sm tw-font-semibold">Zmień hasło</span><span class="tzx-muted">SZO + Microsoft 365 naraz</span></span>
          <i class="bi bi-chevron-right tw-text-slate-400" aria-hidden="true"></i>
        </a>
        <a href="<?= APP_URL ?>/tozsamosc/mfa.php" class="tzx-link-tile">
          <span class="tzx-ico tw-bg-emerald-50 tw-text-emerald-700" aria-hidden="true"><i class="bi bi-shield-plus"></i></span>
          <span class="tw-flex-1 tw-min-w-0"><span class="tw-block tw-text-sm tw-font-semibold">Metody weryfikacji</span><span class="tzx-muted">Authenticator · SMS · klucz</span></span>
          <i class="bi bi-chevron-right tw-text-slate-400" aria-hidden="true"></i>
        </a>
        <a href="<?= APP_URL ?>/tozsamosc/rodo_export.php" target="_blank" rel="noopener" class="tzx-link-tile">
          <span class="tzx-ico tw-bg-violet-50 tw-text-violet-700" aria-hidden="true"><i class="bi bi-file-earmark-arrow-down"></i></span>
          <span class="tw-flex-1 tw-min-w-0"><span class="tw-block tw-text-sm tw-font-semibold">Eksport danych (PDF)</span><span class="tzx-muted">art. 15 i 20 RODO</span></span>
          <i class="bi bi-box-arrow-up-right tw-text-slate-400" aria-hidden="true"></i>
        </a>
        <a href="<?= APP_URL ?>/helpdesk/index.php" class="tzx-link-tile">
          <span class="tzx-ico tw-bg-amber-50 tw-text-amber-700" aria-hidden="true"><i class="bi bi-headset"></i></span>
          <span class="tw-flex-1 tw-min-w-0"><span class="tw-block tw-text-sm tw-font-semibold">Zgłoś problem</span><span class="tzx-muted">helpdesk organizacji</span></span>
          <i class="bi bi-chevron-right tw-text-slate-400" aria-hidden="true"></i>
        </a>
      </div>
    </div>
  </div>
</section>

<!-- ═══════════ USŁUGI ═══════════ -->
<section id="uslugi" role="tabpanel" aria-labelledby="tab-uslugi" tabindex="-1" x-show="tab === 'uslugi'" x-transition.opacity.duration.150ms>
  <div class="tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-3 tw-mb-3">
    <div>
      <h2 class="tw-m-0 tw-text-base tw-font-bold tw-text-slate-900">Usługi i ważność dostępu <span class="lbl-en tw-inline">Services &amp; access validity</span></h2>
      <p class="tzx-muted tw-m-0">Co masz włączone, co czeka na konfigurację i co jest niedostępne.</p>
    </div>
    <div class="tw-flex tw-items-center tw-gap-2">
      <label for="svc-q" class="tw-sr-only">Filtruj usługi</label>
      <div class="tw-relative">
        <i class="bi bi-search tw-absolute tw-left-3 tw-top-1/2 tw--translate-y-1/2 tw-text-slate-400" aria-hidden="true"></i>
        <input id="svc-q" type="search" x-model="q" placeholder="Filtruj: nazwa, status…" class="form-control form-control-sm tw-pl-9 tw-w-56">
      </div>
      <select class="form-select form-select-sm tw-w-auto" x-model="status" aria-label="Filtr statusu">
        <option value="">Wszystkie statusy</option>
        <option value="ok">Aktywne</option>
        <option value="warn">Do konfiguracji</option>
        <option value="off">Nieaktywne</option>
      </select>
    </div>
  </div>

  <div class="tw-grid tw-grid-cols-1 md:tw-grid-cols-2 xl:tw-grid-cols-3 tw-gap-3">
    <?php foreach ($services as $s): $hay = mb_strtolower($s['name'] . ' ' . $s['status_label'] . ' ' . $s['desc']); ?>
    <article class="tzx-tile<?= !empty($s['soon']) ? ' tw-opacity-70' : '' ?>" x-show="match(<?= h(json_encode($hay)) ?>, '<?= $s['status'] ?>')" x-transition.opacity.duration.100ms>
      <div class="tw-flex tw-items-start tw-justify-between tw-gap-2">
        <span class="tzx-ico tw-bg-<?= $s['tone'] ?>-50 tw-text-<?= $s['tone'] ?>-700" aria-hidden="true"><i class="bi <?= $s['icon'] ?>"></i></span>
        <span class="tzx-badge <?= $STATUS_CLASS[$s['status']] ?>"><i class="bi <?= $STATUS_ICON[$s['status']] ?>" aria-hidden="true"></i><?= h($s['status_label']) ?></span>
      </div>
      <div class="tw-flex-1">
        <h3 class="tw-m-0 tw-text-sm tw-font-semibold tw-text-slate-900"><?= h($s['name']) ?></h3>
        <p class="tzx-muted tw-m-0 tw-mt-1 tw-leading-relaxed"><?= h($s['desc']) ?></p>
      </div>
      <div class="tw-flex tw-items-center tw-gap-2">
        <?php if (!empty($s['ldap'])): ?>
          <?php if ($ldap_enabled && !$has_ldap): ?>
          <form method="post" class="tw-m-0">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="_action" value="ldap_create">
            <button type="submit" class="tzx-btn tzx-btn--sm" onclick="return confirm('Utworzyć konto w katalogu LDAP na podstawie Twoich danych tożsamości?');"><i class="bi bi-plus-lg" aria-hidden="true"></i>Utwórz konto</button>
          </form>
          <?php elseif ($has_ldap): ?>
          <form method="post" class="tw-m-0">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="_action" value="ldap_create">
            <button type="submit" class="tzx-btn tzx-btn--ghost tzx-btn--sm"><i class="bi bi-arrow-repeat" aria-hidden="true"></i>Odśwież wpis</button>
          </form>
          <?php endif; ?>
        <?php elseif (!empty($s['soon'])): ?>
          <button type="button" class="tzx-btn tzx-btn--ghost tzx-btn--sm" disabled aria-disabled="true">Wkrótce</button>
        <?php elseif (!empty($s['tab'])): ?>
          <a href="#<?= $s['tab'] ?>" class="tzx-btn tzx-btn--ghost tzx-btn--sm" @click.prevent="go('<?= $s['tab'] ?>')"><?= h($s['cta']) ?><i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        <?php else: ?>
          <a href="<?= h($s['href']) ?>" class="tzx-btn tzx-btn--ghost tzx-btn--sm"><?= h($s['cta']) ?><i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        <?php endif; ?>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
  <p class="tzx-muted tw-mt-3 tw-mb-0" x-show="q.trim() !== '' || status !== ''" x-cloak><i class="bi bi-funnel tw-mr-1" aria-hidden="true"></i>Filtr aktywny — <button type="button" class="tw-bg-transparent tw-border-0 tw-p-0 tw-text-blue-700 tw-underline tw-cursor-pointer" @click="q = ''; status = ''">wyczyść</button>.</p>

  <!-- Narzędzia zewnętrzne -->
  <div class="tw-mt-6">
    <div class="tzx-eyebrow tw-mb-2"><i class="bi bi-grid tw-mr-1" aria-hidden="true"></i>Narzędzia zewnętrzne</div>
    <div class="tz-app-grid">
      <a href="<?= h($trello_ws_url) ?>" target="_blank" rel="noopener noreferrer"
         class="tz-app" style="background:linear-gradient(135deg,#026AA7,#0052CC)" aria-label="Otwórz Trello">
        <span class="tz-app-ic"><i class="bi bi-kanban" aria-hidden="true"></i></span>
        <div class="tz-app-title">Trello</div>
        <div class="tz-app-desc">Tablice Kanban — zarządzanie zadaniami i projektami zespołu</div>
        <div class="tz-app-foot">
          <?php if (can_write('admin')): ?>
          <a href="<?= APP_URL ?>/admin/trello_import.php" class="tz-app-cta me-auto" onclick="event.stopPropagation()" rel="noopener" title="Import zadań z Trello do SZO">
            <i class="bi bi-download" aria-hidden="true"></i> Importuj
          </a>
          <?php endif; ?>
          <span class="tz-app-cta ms-auto"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Otwórz</span>
        </div>
      </a>
    </div>
  </div>
</section>

<!-- ═══════════ BEZPIECZEŃSTWO ═══════════ -->
<section id="bezpieczenstwo" role="tabpanel" aria-labelledby="tab-bezpieczenstwo" tabindex="-1" x-show="tab === 'bezpieczenstwo'" x-transition.opacity.duration.150ms>
  <div class="tw-grid tw-grid-cols-1 lg:tw-grid-cols-5 tw-gap-4">

    <!-- ── HASŁO ── -->
    <div class="tzx-card lg:tw-col-span-3" x-data="tzPassword(<?= h(json_encode($account_parts)) ?>)">
      <div class="tzx-card-hd">
        <i class="bi bi-key tw-text-blue-600" aria-hidden="true"></i>
        <h2 class="tzx-h2">Zmiana hasła <span class="lbl-en tw-inline">Change / reconcile password</span></h2>
        <span class="tw-ml-auto tzx-badge tzx-badge--info"><i class="bi bi-arrow-repeat" aria-hidden="true"></i>SZO <?= $has_m365 ? '↔ Microsoft 365' : '(konto lokalne)' ?></span>
      </div>
      <div class="tzx-card-bd">
        <form method="post" id="pwdForm">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_action" value="pwd_change">
          <?php if ($has_local_password): ?>
          <div class="tzx-field tw-mb-3 tw-max-w-md">
            <label for="pw_cur">Obecne hasło <span class="lbl-en">Current password</span></label>
            <input type="password" id="pw_cur" name="current_password" class="form-control" autocomplete="current-password" required>
          </div>
          <?php else: ?>
          <div class="tw-rounded-xl tw-bg-blue-50 tw-border tw-border-solid tw-border-blue-200 tw-text-blue-800 tw-px-3.5 tw-py-2.5 tw-text-sm tw-mb-3" role="note">
            <i class="bi bi-info-circle tw-mr-1" aria-hidden="true"></i>
            Twoja tożsamość nie ma jeszcze hasła lokalnego SZO. Ustaw je poniżej — będzie działać także jako hasło awaryjne obok logowania Microsoft.
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
          <button type="submit" class="tzx-btn"><i class="bi bi-key" aria-hidden="true"></i>Zmień hasło</button>
        </form>
      </div>
    </div>

    <!-- ── TELEFON ── -->
    <div class="tzx-card lg:tw-col-span-2">
      <div class="tzx-card-hd">
        <i class="bi bi-phone tw-text-blue-600" aria-hidden="true"></i>
        <h2 class="tzx-h2">Telefon do SMS <span class="lbl-en tw-inline">Recovery / MFA phone</span></h2>
        <?php if ($phone_saved): ?>
        <span class="tw-ml-auto tzx-badge <?= $phone_verified ? 'tzx-badge--ok' : 'tzx-badge--warn' ?>"><i class="bi <?= $phone_verified ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill' ?>" aria-hidden="true"></i><?= $phone_verified ? 'Zweryfikowany' : 'Niezweryfikowany' ?></span>
        <?php endif; ?>
      </div>
      <div class="tzx-card-bd">
        <p class="tzx-muted tw-mt-0">Numer służy do odzyskiwania dostępu (kod SMS do zdefiniowania nowego hasła) oraz powiadomień MFA.</p>
        <?php if (!$sms_available): ?>
          <div class="tw-rounded-xl tw-bg-amber-50 tw-border tw-border-solid tw-border-amber-200 tw-text-amber-800 tw-px-3.5 tw-py-2.5 tw-text-sm tw-mb-3" role="alert"><i class="bi bi-exclamation-triangle tw-mr-1" aria-hidden="true"></i>Weryfikacja SMS jest obecnie niedostępna.</div>
        <?php endif; ?>
        <?php if ($phone_saved && !$phone_pending): ?>
          <p class="tw-mb-3 tw-text-sm">Aktualny numer: <strong class="tw-font-mono"><?= h(tz_mask_phone($phone_saved)) ?></strong></p>
        <?php endif; ?>

        <?php if ($phone_pending): ?>
          <p class="tw-text-sm">Wpisz 6-cyfrowy kod wysłany na numer <strong><?= h(tz_mask_phone($phone_pending)) ?></strong>. <span class="lbl-en tw-inline">Enter the SMS code.</span></p>
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
              <button type="submit" class="tzx-btn"><i class="bi bi-send" aria-hidden="true"></i>Wyślij kod weryfikacyjny</button>
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

    <!-- ── MFA ── -->
    <div class="tzx-card lg:tw-col-span-5">
      <div class="tzx-card-hd">
        <i class="bi bi-shield-check tw-text-blue-600" aria-hidden="true"></i>
        <h2 class="tzx-h2">Metody weryfikacji (MFA) <span class="lbl-en tw-inline">Set up authenticator</span></h2>
        <span class="tw-ml-auto tzx-badge <?= $mfa_active ? 'tzx-badge--ok' : 'tzx-badge--warn' ?>"><?= $mfa_active ? h($mfa_label) : 'Brak drugiego składnika' ?></span>
      </div>
      <div class="tzx-card-bd">
        <p class="tzx-muted tw-mt-0">Sparuj smartfon z kontem organizacji. Zalecamy aplikację <strong>Microsoft Authenticator</strong>.</p>
        <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 xl:tw-grid-cols-3 tw-gap-3 tw-mb-4">
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

    <!-- ── Szybkie dzwonienie: UID + PIN ── -->
    <div class="tzx-card lg:tw-col-span-5">
      <div class="tzx-card-hd">
        <i class="bi bi-telephone-outbound tw-text-emerald-600" aria-hidden="true"></i>
        <h2 class="tzx-h2">Szybkie dzwonienie (mobile) <span class="lbl-en tw-inline">Quick dial sign-in</span></h2>
        <span class="tw-ml-auto">
          <?php if (!$pin_allowed): ?><span class="tzx-badge tzx-badge--off">Wyłączone przez administratora</span>
          <?php elseif ($pin_is_set): ?><span class="tzx-badge tzx-badge--ok"><i class="bi bi-check-circle-fill" aria-hidden="true"></i>PIN ustawiony</span>
          <?php else: ?><span class="tzx-badge tzx-badge--warn">PIN nieustawiony</span><?php endif; ?>
        </span>
      </div>
      <div class="tzx-card-bd">
        <div class="tw-grid tw-grid-cols-1 lg:tw-grid-cols-5 tw-gap-4">
          <div class="lg:tw-col-span-2">
            <p class="tzx-muted tw-mt-0">
              Aplikacja mobilna <strong>Dzwoń</strong> pozwala wyszukać kontakt CRM i zadzwonić jednym dotknięciem.
              Wchodzisz do niej numerem konta i PIN-em — a potem, jak wszędzie w CRM, kodem IKA.
              Ta sesja otwiera <strong>wyłącznie</strong> dialer; do reszty systemu nadal logujesz się normalnie.
            </p>
            <dl class="tw-grid tw-grid-cols-1 tw-gap-2 tw-m-0">
              <div class="tzx-kv"><dt>Adres aplikacji <span class="lbl-en">App address</span></dt><dd><a href="<?= h($pin_app_url) ?>" class="tw-text-blue-700"><?= h(preg_replace('#^https?://#', '', $pin_app_url)) ?></a> <span class="tzx-muted tw-font-normal">— dodaj do ekranu głównego telefonu</span></dd></div>
              <div class="tzx-kv"><dt>Numer konta (UID) <span class="lbl-en">Account number</span></dt><dd class="tw-font-mono"><?= (int)$uid ?></dd></div>
              <div class="tzx-kv"><dt>PIN <span class="lbl-en">Quick dial PIN</span></dt><dd>
                <?php if (!$pin_allowed): ?>Wyłączone przez administratora
                <?php elseif ($pin_is_set): ?>Ustawiony<?php if (!empty($db_user['mobile_pin_set_at'])): ?> <span class="tzx-muted tw-font-normal">od <?= h(date('d.m.Y', strtotime((string)$db_user['mobile_pin_set_at']))) ?></span><?php endif; ?>
                  <?php if ($pin_lock): ?> <span class="tzx-badge tzx-badge--warn">Zablokowany na <?= (int)$pin_lock ?> min po błędnych próbach</span><?php endif; ?>
                <?php else: ?>Nieustawiony<?php endif; ?>
              </dd></div>
            </dl>
          </div>
          <div class="lg:tw-col-span-3">
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
    </div>
  </div>
</section>

<?php if ($has_ika): ?>
<!-- ═══════════ ZMIEŃ IKA ═══════════ -->
<section id="ika" role="tabpanel" aria-labelledby="tab-ika" tabindex="-1" x-show="tab === 'ika'" x-transition.opacity.duration.150ms>
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
<section id="rejestr" role="tabpanel" aria-labelledby="tab-rejestr" tabindex="-1" x-show="tab === 'rejestr'" x-transition.opacity.duration.150ms>
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
<section id="opanelu" role="tabpanel" aria-labelledby="tab-opanelu" tabindex="-1" x-show="tab === 'opanelu'" x-transition.opacity.duration.150ms>
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
<section id="administracja" role="tabpanel" aria-labelledby="tab-administracja" tabindex="-1" x-show="tab === 'administracja'" x-transition.opacity.duration.150ms>
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

</div><!-- /x-data tzIdentity -->

<script>
document.addEventListener('alpine:init', function () {
  // Zakładki: jedna sekcja naraz, #hash w adresie (przekierowania po zapisie używają #bezpieczenstwo itp.),
  // klawiatura (strzałki/Home/End) w pasku zakładek, filtr usług.
  Alpine.data('tzIdentity', function (cfg) {
    return {
      tab: 'podstawowe', tabs: cfg.tabs || [], q: '', status: '',
      init: function () {
        var h = (location.hash || '').slice(1);
        if (this.tabs.indexOf(h) >= 0) this.tab = h;
        else if (cfg.firstVisit) this.tab = 'opanelu';
        var self = this;
        window.addEventListener('hashchange', function () {
          var h2 = (location.hash || '').slice(1);
          if (self.tabs.indexOf(h2) >= 0) self.tab = h2;
        });
      },
      go: function (t, focusPanel) {
        if (this.tabs.indexOf(t) < 0) return;
        this.tab = t;
        if (history.replaceState) history.replaceState(null, '', '#' + t);
        var self = this;
        this.$nextTick(function () {
          if (focusPanel !== false) { var p = document.getElementById(t); if (p) p.focus({ preventScroll: true }); }
          window.scrollTo({ top: 0, behavior: 'smooth' });
        });
      },
      key: function (e, i) {
        var n = this.tabs.length, j = null;
        if (e.key === 'ArrowRight' || e.key === 'ArrowDown') j = (i + 1) % n;
        else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') j = (i - 1 + n) % n;
        else if (e.key === 'Home') j = 0;
        else if (e.key === 'End') j = n - 1;
        if (j === null) return;
        e.preventDefault();
        this.go(this.tabs[j], false);
        var btn = this.$refs['tab-' + this.tabs[j]];
        if (btn) btn.focus();
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
