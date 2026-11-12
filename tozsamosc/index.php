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

/* ═══════════════════════ MODEL WIDOKU („Konto Microsoft”: Przegląd + sekcje z lewego menu) ══════════════════
   Wyłącznie dane do prezentacji — cała logika POST jest wyżej. Sekcje = klucze #hash w adresie
   (przekierowania po zapisie: #bezpieczenstwo, #uslugi, #ika; stopka: #opanelu; rodo.php: #rejestr).
   Menu sekcji rysuje tozsamosc/_head.php (lista $__nav_me) — klucze muszą się zgadzać z $tabs. */

// Rola użytkownika (etykieta z tabeli roles)
$role_key = (string)($user['role'] ?? 'viewer');
$role_row = null;
try { $role_row = db_one("SELECT display_name, description FROM roles WHERE name=?", [$role_key]); } catch (\Throwable $e) {}
$role_label = (string)($role_row['display_name'] ?? ucfirst($role_key));
$role_desc  = (string)($role_row['description'] ?? '');
$role_icon  = $role_key === 'admin' ? 'bi-shield-fill-check' : ($role_key === 'editor' ? 'bi-pencil-square' : 'bi-person');

// Dostęp do modułów wynikający z roli (+ uprawnienia indywidualne)
$access = [];
foreach (PERMISSION_MODULES as $mk => $ml) {
    if ($mk === 'admin' && !is_admin()) continue;
    if (can_write($mk))     $access[] = ['label' => $ml, 'level' => 'edycja'];
    elseif (can_read($mk))  $access[] = ['label' => $ml, 'level' => 'odczyt'];
}
$access_write = count(array_filter($access, fn($a) => $a['level'] === 'edycja'));

// Stan zabezpieczeń konta — lista kontrolna (sec = ustawienie w sekcji Zabezpieczenia, tab = inna sekcja)
$sec_items = [
    ['label' => 'Hasło', 'miss' => 'hasło lokalne', 'ok' => $has_local_password, 'sec' => 'haslo', 'icon' => 'bi-key',
     'hint' => $has_local_password ? 'ustawione' . ($has_m365 ? ' · SZO + Microsoft 365' : '') : 'brak hasła lokalnego'],
    ['label' => 'Telefon do SMS', 'miss' => 'zweryfikowany telefon', 'ok' => $phone_verified, 'sec' => 'telefon', 'icon' => 'bi-phone',
     'hint' => $phone_verified ? tz_mask_phone($phone_saved) : ($phone_saved ? 'niezweryfikowany' : 'brak numeru')],
    ['label' => 'Weryfikacja dwuskładnikowa', 'miss' => 'drugi składnik logowania', 'ok' => $mfa_active, 'sec' => 'mfa', 'icon' => 'bi-shield-check',
     'hint' => $mfa_active ? $mfa_label : 'wyłączona'],
    ['label' => 'Klucz bezpieczeństwa', 'miss' => 'klucz lub passkey', 'ok' => $has_wk, 'sec' => 'mfa', 'icon' => 'bi-fingerprint',
     'hint' => $has_wk ? count($wk_keys_idx) . ' szt.' : 'brak'],
];
if ($has_ika) {
    $sec_items[] = ['label' => 'Kod IKA', 'miss' => 'odblokowany kod IKA', 'ok' => !$ika_blocked, 'tab' => 'ika', 'icon' => 'bi-123',
                    'hint' => $ika_blocked ? 'zablokowany' : 'aktywny'];
}
$sec_ok      = count(array_filter($sec_items, fn($i) => $i['ok']));
$sec_total   = count($sec_items);
$sec_pct     = $sec_total ? (int)round($sec_ok / $sec_total * 100) : 0;
$sec_level   = $sec_pct >= 80 ? 'ok' : ($sec_pct >= 50 ? 'warn' : 'err');
$sec_missing = array_values(array_filter($sec_items, fn($i) => !$i['ok']));
// Odnośnik Alpine do brakującego elementu (rozwija ustawienie albo przełącza sekcję)
$sec_go = fn(array $i): string => !empty($i['tab']) ? "go('" . $i['tab'] . "')" : "go('bezpieczenstwo', '" . $i['sec'] . "')";

// Metody MFA / PIN
$totp_on     = !empty($db_user['totp_confirmed']) && !empty($db_user['totp_secret']);
$sms_on      = $sms_available && $mfa_method === 'sms' && !empty($db_user['twofa_phone']);
$pin_is_set  = mobile_pin_is_set($db_user);
$pin_allowed = mobile_pin_allowed($db_user);
$pin_lock    = mobile_pin_lock_left($db_user);
$pin_app_url = APP_URL . '/mobilna/';
$m365_active = $has_m365 && !empty($m365_row['m365_konto_aktywne']);

// Usługi (kolor kafla ikony: --c)
$SVC_C = ['blue' => '#0f6cbd', 'sky' => '#0078d4', 'teal' => '#038387', 'indigo' => '#4f52b2', 'slate' => '#616161',
          'emerald' => '#107c10', 'amber' => '#bc4b09'];
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
    'status' => $m365_active ? 'ok' : ($has_m365 ? 'warn' : 'off'),
    'status_label' => $m365_active ? 'Aktywne' : ($has_m365 ? 'Zawieszone' : 'Brak konta'),
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
    'tab' => 'bezpieczenstwo', 'sec' => 'mfa', 'cta' => 'Konfiguruj',
];
$services[] = [
    'name' => 'Dostęp do komputerów FEER', 'icon' => 'bi-pc-display', 'tone' => 'slate',
    'status' => 'off', 'status_label' => 'Nieaktywna', 'desc' => 'Usługa tymczasowo nieaktywna.', 'soon' => true,
];
$trello_ws_url = org_setting('trello_workspace_url') ?: 'https://trello.com';

// Sekcje (klucze = #hash; kolejność jak w menu _head.php)
$tabs = ['przeglad' => 'Przegląd', 'podstawowe' => 'Dane konta', 'bezpieczenstwo' => 'Zabezpieczenia',
         'urzadzenia' => 'Urządzenia i logowania', 'uslugi' => 'Usługi'];   // urzadzenia: modules/account_security
if ($has_ika) $tabs['ika'] = 'Kod IKA';
$tabs['rejestr'] = 'Moje dane (RODO)';
$tabs['opanelu'] = 'Pomoc i informacje';
if (is_admin()) $tabs['administracja'] = 'Administracja';

// Sekcja startowa: po błędzie formularza — ta, z której przyszedł POST (wygrywa z #hash); inaczej Przegląd.
$__pa = (string)($_POST['_action'] ?? '');
$tab_def = 'przeglad'; $tab_force = false;
if ($errors && $__pa !== '') {
    $tab_force = true;
    $tab_def = match (true) {
        $__pa === 'change_ika'  => 'ika',
        $__pa === 'ldap_create' => 'uslugi',
        default                 => 'bezpieczenstwo',
    };
}

$account_parts = array_values(array_filter(preg_split('/[\s@.\-]+/', mb_strtolower(($user['name'] ?? '') . ' ' . $panel_login)),
                                           fn($p) => mb_strlen($p) > 2));
$STATUS_CLASS = ['ok' => 'tz-badge--ok', 'warn' => 'tz-badge--warn', 'off' => 'tz-badge--off'];

// Zabezpieczenia: które ustawienie rozwinięte na starcie — po błędzie formularza to, którego dotyczył POST;
// przy oczekującym kodzie SMS telefon; w przeciwnym razie pierwszy brak w konfiguracji.
$sec_open = 'haslo';
if (!$has_local_password)                   $sec_open = 'haslo';
elseif ($phone_pending || !$phone_verified) $sec_open = 'telefon';
elseif (!$mfa_active)                       $sec_open = 'mfa';
// ?sec=… (np. po „To nie ja” w Urządzeniach → zmiana hasła) rozwija wskazane ustawienie
$__qsec = (string)($_GET['sec'] ?? '');
if (in_array($__qsec, ['haslo', 'telefon', 'mfa', 'pin', 'powiadomienia'], true)) $sec_open = $__qsec;
if ($errors && $__pa !== '') {
    if (str_starts_with($__pa, 'pwd'))            $sec_open = 'haslo';
    elseif (str_starts_with($__pa, 'phone'))      $sec_open = 'telefon';
    elseif (str_starts_with($__pa, 'mobile_pin')) $sec_open = 'pin';
}

$PAGE_TITLE = 'Tożsamość';
$TZ_ACTIVE  = 'konto';
$TZ_SHELL   = ['tabs' => array_keys($tabs), 'def' => $tab_def, 'force' => $tab_force];
include __DIR__ . '/_head.php';   // własny chrome podsystemu (nagłówek + lewe menu, bez menu SZO)

$csrf = h(csrf_token());
/** Nagłówek sekcji: h1 (cel fokusu po przełączeniu) + opis. */
$sec_title = function (string $id, string $title, string $sub = '', string $actions = ''): void { ?>
  <div class="ma-head">
    <div class="ma-head-row">
      <h1 id="<?= $id ?>-h" class="ma-title" tabindex="-1"><?= h($title) ?></h1>
      <?php if ($actions !== ''): ?><div class="ma-actions"><?= $actions ?></div><?php endif; ?>
    </div>
    <?php if ($sub !== ''): ?><p class="ma-sub"><?= h($sub) ?></p><?php endif; ?>
  </div>
<?php };
?>
<style>
/* ══ System Tożsamości — widok „Konto Microsoft” (Fluent 2). Tokeny --tz-* i .tz-btn/.tz-badge: tozsamosc/_head.php ══ */
.ma-sec{min-width:0}
.ma-head{margin:0 0 1.5rem}
.ma-head-row{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.75rem 1rem}
.ma-title{font-size:28px;line-height:36px;font-weight:600;margin:0;color:var(--tz-fg)}
.ma-title:focus{outline:none}
.ma-sub{margin:.35rem 0 0;color:var(--tz-muted);max-width:78ch}
.ma-h2{font-size:20px;line-height:28px;font-weight:600;margin:2rem 0 .75rem;color:var(--tz-fg)}
.ma-card{background:#fff;border-radius:var(--tz-r-lg);box-shadow:var(--tz-shadow);padding:1.25rem 1.5rem;min-width:0}
.ma-card-hd{display:flex;align-items:center;flex-wrap:wrap;gap:.5rem .75rem;margin:0 0 .85rem}
.ma-card-t{font-size:16px;line-height:22px;font-weight:600;margin:0;color:var(--tz-fg)}
.ma-card-note{margin:1rem 0 0;color:var(--tz-muted);font-size:13px;max-width:82ch}
.ma-stack{display:grid;gap:1rem;align-content:start;min-width:0}
.ma-muted{color:var(--tz-muted)}
.ma-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-weight:600}
.ma-link{display:inline-flex;align-items:center;gap:.3rem;color:var(--tz);font-weight:600;text-decoration:none;min-height:28px}
.ma-link:hover{color:var(--tz-strong);text-decoration:underline}
.ma-link .bi{font-size:.72rem}
.ma-actions{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center}
/* MessageBar */
.ma-bar{display:flex;align-items:flex-start;flex-wrap:wrap;gap:.6rem .75rem;padding:.75rem 1rem;border:1px solid;border-radius:var(--tz-r);margin:0 0 1rem}
.ma-bar-ic{font-size:1rem;line-height:1.43;flex-shrink:0}
.ma-bar-tx{flex:1;min-width:220px}
.ma-bar-tx ul{margin:.25rem 0 0;padding-left:1.1rem}
.ma-bar a{color:inherit;font-weight:600;text-decoration:underline}
.ma-bar--info{background:var(--tz-50);border-color:#b4d6fa}.ma-bar--info .ma-bar-ic{color:var(--tz)}
.ma-bar--warn{background:#fff9f5;border-color:#fdcfb4}.ma-bar--warn .ma-bar-ic{color:var(--tz-warn)}
.ma-bar--err{background:#fdf3f4;border-color:#eeacb2}.ma-bar--err .ma-bar-ic{color:var(--tz-err)}
.ma-bar .tz-btn{text-decoration:none}
/* Przegląd */
.ma-ov{display:grid;grid-template-columns:1fr;gap:1rem;align-items:start}
@media (min-width:900px){.ma-ov{grid-template-columns:290px minmax(0,1fr)}}
.ma-profile{text-align:center;padding:1.75rem 1.5rem 1.5rem}
@media (min-width:900px){.ma-profile{position:sticky;top:72px}}
.ma-ava{width:96px;height:96px;border-radius:50%;background:var(--tz-100);color:var(--tz-ink);font-size:34px;font-weight:600;display:flex;align-items:center;justify-content:center;margin:0 auto .9rem;letter-spacing:.02em}
.ma-profile-name{font-size:20px;line-height:26px;font-weight:600;margin:0;word-break:break-word}
.ma-profile-mail{margin:.25rem 0 1rem;color:var(--tz-muted);word-break:break-all}
.ma-mini{margin:0 0 1rem;text-align:left;border-top:1px solid var(--tz-line)}
.ma-mini>div{display:flex;justify-content:space-between;align-items:baseline;gap:1rem;padding:.55rem 0;border-bottom:1px solid var(--tz-line)}
.ma-mini dt{font-weight:400;color:var(--tz-muted);margin:0;flex-shrink:0}
.ma-mini dd{margin:0;font-weight:600;text-align:right;min-width:0;word-break:break-word}
.ma-why{margin-top:1rem;text-align:left;font-size:13px}
.ma-why summary{cursor:pointer;color:var(--tz);font-weight:600;min-height:28px}
.ma-why summary:hover{text-decoration:underline}
.ma-why p{margin:.5rem 0 0;color:var(--tz-fg2)}
.ma-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:1rem}
.ma-tile{display:flex;flex-direction:column;gap:.55rem;padding:1.25rem 1.25rem 1rem}
.ma-tile-hd{display:flex;align-items:center;gap:.75rem}
.ma-tile-t{font-size:16px;line-height:22px;font-weight:600;margin:0}
.ma-tile-p{margin:0;color:var(--tz-fg2)}
.ma-tile-meta{margin:0;color:var(--tz-muted);font-size:13px}
.ma-tile-ft{margin-top:auto;padding-top:.35rem;display:flex;flex-wrap:wrap;gap:.1rem 1.25rem}
.ma-ic{width:40px;height:40px;border-radius:var(--tz-r-lg);display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;background:var(--tz-50);color:var(--tz)}
.ma-ic--ok{background:#f1faf1;color:var(--tz-ok)}
.ma-ic--warn{background:#fff4ec;color:var(--tz-warn)}
.ma-ic--err{background:#fdf3f4;color:var(--tz-err)}
.ma-ic--off{background:var(--tz-hover);color:var(--tz-muted)}
.ma-prog{height:6px;border-radius:3px;background:var(--tz-line);overflow:hidden}
.ma-prog>span{display:block;height:100%;border-radius:3px;background:var(--tz-ok)}
.ma-prog>span.is-warn{background:var(--tz-warn)}
.ma-prog>span.is-err{background:var(--tz-err)}
.ma-dots{display:flex;flex-wrap:wrap;gap:.35rem}
.ma-dot{width:28px;height:28px;border-radius:var(--tz-r);display:flex;align-items:center;justify-content:center;color:#fff;font-size:.85rem;background:var(--c,var(--tz))}
/* Dane konta */
.ma-rows{margin:0}
.ma-row{display:grid;grid-template-columns:1fr;gap:.1rem 1.5rem;padding:.8rem 0;border-top:1px solid var(--tz-line);align-items:center;min-height:52px}
.ma-row:first-child{border-top:0;padding-top:0}
@media (min-width:720px){.ma-row{grid-template-columns:250px minmax(0,1fr)}}
.ma-row dt{font-weight:400;color:var(--tz-muted);margin:0}
.ma-row dd{margin:0;font-weight:600;display:flex;align-items:center;flex-wrap:wrap;gap:.25rem .5rem;min-width:0;word-break:break-word}
.ma-row dd .ma-sm{font-weight:400;color:var(--tz-muted);font-size:13px}
.ma-copy{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border:0;border-radius:var(--tz-r);background:transparent;color:var(--tz-fg2);cursor:pointer;flex-shrink:0}
.ma-copy:hover{background:var(--tz-hover);color:var(--tz)}
.ma-copy .bi-check2{color:var(--tz-ok)}
.ma-role{display:flex;gap:1rem;align-items:flex-start;margin-bottom:1rem}
.ma-tags{display:flex;flex-wrap:wrap;gap:.4rem}
.ma-tag{display:inline-flex;align-items:center;gap:.35rem;padding:.25rem .6rem;border-radius:var(--tz-r);background:var(--tz-hover);border:1px solid var(--tz-line);font-size:13px;color:var(--tz-fg);font-family:inherit;line-height:1.4}
.ma-tag small{color:var(--tz-muted);font-size:12px}
.ma-tag--w{background:var(--tz-50);border-color:#b4d6fa}
button.ma-tag{cursor:pointer;color:var(--tz);font-weight:600;background:#fff;border-color:var(--tz-line-2)}
button.ma-tag:hover{background:var(--tz-hover)}
/* Zabezpieczenia */
.ma-secsum{display:grid;grid-template-columns:1fr;gap:.75rem 2rem;align-items:center}
@media (min-width:760px){.ma-secsum{grid-template-columns:auto minmax(0,1fr)}}
.ma-secsum-pct{font-size:40px;line-height:44px;font-weight:600;display:block}
.ma-secsum p{margin:.6rem 0 0}
.ma-exp{background:#fff;border-radius:var(--tz-r-lg);box-shadow:var(--tz-shadow);margin-top:.5rem;overflow:hidden;scroll-margin-top:64px}
.ma-exp-h{margin:0;font:inherit}
.ma-exp-btn{display:flex;align-items:center;gap:1rem;width:100%;min-height:68px;padding:.75rem 1.25rem;border:0;background:#fff;text-align:left;font:inherit;color:inherit;cursor:pointer}
.ma-exp-btn:hover{background:#fafafa}
.ma-exp-btn:focus-visible{outline:3px solid var(--tz-focus);outline-offset:-3px}
.ma-exp-tx{flex:1;min-width:0}
.ma-exp-t{display:block;font-size:15px;font-weight:600;line-height:1.35}
.ma-exp-d{display:block;color:var(--tz-muted);font-size:13px;margin-top:.1rem}
.ma-exp-chev{font-size:.85rem;color:var(--tz-fg2);transition:transform .15s;flex-shrink:0}
.ma-exp.is-open .ma-exp-chev{transform:rotate(180deg)}
.ma-exp-bd{padding:1.25rem 1.25rem 1.5rem;border-top:1px solid var(--tz-line)}
.ma-split{display:grid;grid-template-columns:1fr;gap:1.25rem}
@media (min-width:992px){.ma-split{grid-template-columns:minmax(0,3fr) minmax(0,2fr)}}
.ma-aside{background:var(--tz-hover);border-radius:var(--tz-r-lg);padding:1rem 1.1rem;color:var(--tz-fg2);font-size:13px;line-height:1.55;align-self:start}
.ma-aside h4{font-size:14px;font-weight:600;margin:0 0 .4rem;color:var(--tz-fg);display:flex;gap:.45rem;align-items:center}
.ma-aside h4 .bi{color:var(--tz)}
.ma-aside ul{margin:.5rem 0 0;padding-left:1.1rem}
.ma-aside li{margin-bottom:.25rem}
.ma-aside .ma-mini{margin:.75rem 0 0;border-top-color:var(--tz-line-2)}
.ma-aside .ma-mini>div{border-bottom-color:var(--tz-line-2)}
.ma-field{margin-bottom:1rem;max-width:28rem}
.ma-field label,.ma-legend{display:block;font-weight:600;margin-bottom:.3rem;color:var(--tz-fg)}
.ma-field .form-text,.ma-help{font-size:12px;color:var(--tz-muted);margin-top:.3rem}
.ma-err-tx{font-size:12px;color:var(--tz-err-ink);margin-top:.3rem}
.ma-methods{list-style:none;margin:0 0 1rem;padding:0;border:1px solid var(--tz-line);border-radius:var(--tz-r-lg)}
.ma-method{display:flex;align-items:center;flex-wrap:wrap;gap:.6rem .85rem;padding:.75rem 1rem;border-top:1px solid var(--tz-line)}
.ma-method:first-child{border-top:0}
.ma-method-tx{flex:1;min-width:180px}
.ma-method-tx b{display:block;font-weight:600}
.ma-method-tx span{display:block;color:var(--tz-muted);font-size:13px}
/* Usługi */
.ma-svcs{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:1rem}
.ma-svc{display:flex;flex-direction:column;gap:.6rem}
.ma-svc.is-soon{background:#fbfbfb}
.ma-svc.is-soon .ma-svc-ic{background:#8a8a8a}
.ma-svc-hd{display:flex;align-items:flex-start;gap:.85rem}
.ma-svc-ic{width:44px;height:44px;border-radius:var(--tz-r-lg);background:var(--c,var(--tz));color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0}
.ma-svc-t{font-size:16px;line-height:22px;font-weight:600;margin:0 0 .3rem}
.ma-svc-p{margin:0;color:var(--tz-fg2);font-size:13px;word-break:break-word}
.ma-svc-ft{margin-top:auto;padding-top:.4rem;display:flex;flex-wrap:wrap;gap:.5rem;align-items:center}
/* Moje dane */
.ma-reg{display:grid;grid-template-columns:1fr;gap:1rem;align-items:start}
@media (min-width:1100px){.ma-reg{grid-template-columns:minmax(0,2fr) minmax(0,3fr)}}
.ma-auth{border:1px solid var(--tz-line);border-radius:var(--tz-r-lg);padding:.75rem 1rem}
.ma-auth+.ma-auth{margin-top:.5rem}
.ma-auth.is-old{background:#fbfbfb}
.ma-auth ul{margin:.35rem 0 0;padding-left:1.1rem;font-size:13px}
.ma-seg{display:inline-flex;padding:2px;border-radius:var(--tz-r);background:var(--tz-hover);border:1px solid var(--tz-line)}
.ma-seg button{border:0;background:transparent;font:inherit;font-size:13px;padding:.3rem .8rem;border-radius:3px;color:var(--tz-fg2);cursor:pointer;min-height:30px}
.ma-seg button:hover{color:var(--tz-fg)}
.ma-seg button[aria-pressed="true"]{background:#fff;color:var(--tz-fg);font-weight:600;box-shadow:var(--tz-shadow)}
.ma-tl{list-style:none;margin:0;padding:0}
.ma-tl li{display:flex;gap:.85rem;padding:.75rem 0;border-top:1px solid var(--tz-line)}
.ma-tl li:first-child{border-top:0;padding-top:.25rem}
.ma-tl-ic{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;background:var(--tz-hover);color:var(--tz-fg2)}
.ma-tl-ic.is-mod{background:var(--tz-50);color:var(--tz)}
.ma-tl-t{font-weight:600;display:flex;flex-wrap:wrap;align-items:center;gap:.4rem}
.ma-tl-m{color:var(--tz-muted);font-size:13px;margin-top:.1rem;word-break:break-word}
/* Pomoc */
.ma-2col{display:grid;grid-template-columns:1fr;gap:1rem}
@media (min-width:800px){.ma-2col{grid-template-columns:1fr 1fr}}
.ma-list{list-style:none;margin:0;padding:0}
.ma-list li{display:flex;gap:.6rem;padding:.35rem 0;line-height:1.5}
.ma-list li>.bi{flex-shrink:0;margin-top:.15rem}
.ma-list--yes li>.bi{color:var(--tz-ok)}
.ma-list--no li>.bi{color:var(--tz-err)}
.ma-list--info li>.bi{color:var(--tz)}
/* Administracja */
.ma-navcards{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem}
.ma-navcard{display:flex;align-items:center;gap:.85rem;text-decoration:none;color:var(--tz-fg);padding:1rem 1.1rem;transition:box-shadow .12s}
.ma-navcard:hover{box-shadow:var(--tz-shadow-8);color:var(--tz-fg)}
.ma-navcard b{display:block;font-weight:600}
.ma-navcard .d{display:block;color:var(--tz-muted);font-size:13px}
.ma-navcard .bi-chevron-right{color:var(--tz-muted);font-size:.8rem;margin-left:auto}
</style>

<?php if ($errors): ?>
<div class="ma-bar ma-bar--err" role="alert" aria-labelledby="tz-err-h">
  <i class="bi bi-x-circle-fill ma-bar-ic" aria-hidden="true"></i>
  <div class="ma-bar-tx">
    <strong id="tz-err-h">Popraw następujące błędy:</strong>
    <ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
  </div>
</div>
<?php endif; ?>

<?= function_exists('flash_html') ? flash_html() : '' ?>

<!-- ═══════════ PRZEGLĄD ═══════════ -->
<section id="przeglad" class="ma-sec" aria-labelledby="przeglad-h" x-show="tab === 'przeglad'" x-cloak>
  <?php $sec_title('przeglad', 'Przegląd', 'Twoje konto w organizacji: jeden identyfikator i jedno hasło do SZO, Microsoft 365 i usług powiązanych.'); ?>

  <?php if ($first_visit): ?>
  <div class="ma-bar ma-bar--info" role="note" aria-labelledby="welcome-h">
    <i class="bi bi-info-circle-fill ma-bar-ic" aria-hidden="true"></i>
    <div class="ma-bar-tx"><strong id="welcome-h">Witaj w Systemie Tożsamości.</strong> To Twoje pierwsze wejście. W „Pomocy i informacjach” w 20 sekund zobaczysz, do czego służy ten panel, a do czego nie.</div>
    <div class="ma-actions">
      <a href="#opanelu" class="tz-btn tz-btn--sm" @click.prevent="go('opanelu')">Pokaż informacje</a>
      <form method="post" class="m-0">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
        <input type="hidden" name="_action" value="dismiss_intro">
        <button type="submit" class="tz-btn tz-btn--subtle tz-btn--sm">Nie pokazuj więcej</button>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($sec_missing): ?>
  <div class="ma-bar ma-bar--warn" role="note">
    <i class="bi bi-exclamation-triangle-fill ma-bar-ic" aria-hidden="true"></i>
    <div class="ma-bar-tx"><strong>Dokończ zabezpieczanie konta.</strong> Brakuje:
      <?php foreach ($sec_missing as $mi => $i): ?><a href="#<?= !empty($i['tab']) ? h($i['tab']) : 'bezpieczenstwo' ?>" @click.prevent="<?= h($sec_go($i)) ?>"><?= h($i['miss']) ?></a><?= $mi < count($sec_missing) - 1 ? ', ' : '.' ?><?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="ma-ov">
    <aside class="ma-card ma-profile" aria-labelledby="prof-name">
      <span class="ma-ava" aria-hidden="true"><?= h($initials) ?></span>
      <h2 id="prof-name" class="ma-profile-name"><?= h($user['name'] ?? '') ?></h2>
      <p class="ma-profile-mail"><?= h($panel_login ?: '—') ?></p>
      <dl class="ma-mini">
        <div><dt>Numer UID</dt><dd class="ma-mono"><?= (int)$uid ?></dd></div>
        <div><dt>Rola</dt><dd><?= h($role_label) ?></dd></div>
        <div><dt>Typ konta</dt><dd><?= h($account_type_label) ?></dd></div>
      </dl>
      <a href="#podstawowe" class="ma-link" @click.prevent="go('podstawowe')">Wyświetl dane konta <i class="bi bi-chevron-right" aria-hidden="true"></i></a>
      <details class="ma-why">
        <summary>Dlaczego nie mogę edytować danych?</summary>
        <p>Imię, nazwisko, login i numer UID pochodzą z umowy i rejestru tożsamości organizacji. Zmienia je administrator — jeśli coś się nie zgadza, zgłoś to przez <a href="<?= APP_URL ?>/helpdesk/index.php">helpdesk</a>.</p>
      </details>
    </aside>

    <div class="ma-tiles">
      <article class="ma-card ma-tile" aria-labelledby="t-sec">
        <div class="ma-tile-hd"><span class="ma-ic ma-ic--<?= $sec_level ?>" aria-hidden="true"><i class="bi bi-shield-lock"></i></span><h2 id="t-sec" class="ma-tile-t">Zabezpieczenia</h2></div>
        <p class="ma-tile-p">Hasło, telefon do odzyskiwania dostępu i drugi składnik logowania.</p>
        <div class="ma-prog" role="progressbar" aria-label="Stan zabezpieczeń" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $sec_pct ?>"><span class="is-<?= $sec_level ?>" style="width:<?= $sec_pct ?>%"></span></div>
        <p class="ma-tile-meta"><strong><?= $sec_pct ?>%</strong> · skonfigurowano <?= $sec_ok ?> z <?= $sec_total ?></p>
        <div class="ma-tile-ft"><a href="#bezpieczenstwo" class="ma-link" @click.prevent="go('bezpieczenstwo')">Zaktualizuj informacje<span class="visually-hidden"> o zabezpieczeniach</span> <i class="bi bi-chevron-right" aria-hidden="true"></i></a></div>
      </article>

      <article class="ma-card ma-tile" aria-labelledby="t-pwd">
        <div class="ma-tile-hd"><span class="ma-ic<?= $has_local_password ? '' : ' ma-ic--warn' ?>" aria-hidden="true"><i class="bi bi-key"></i></span><h2 id="t-pwd" class="ma-tile-t">Hasło</h2></div>
        <p class="ma-tile-p"><?= $has_m365 ? 'Jedno hasło do SZO i Microsoft 365 — zmiana działa w obu systemach naraz.' : 'Hasło do SZO i modułów, które z niego korzystają (CRM, EZD, panel).' ?></p>
        <p class="ma-tile-meta"><?= $has_local_password ? 'Hasło jest ustawione.' : 'Nie masz jeszcze hasła lokalnego.' ?></p>
        <div class="ma-tile-ft"><a href="#bezpieczenstwo" class="ma-link" @click.prevent="go('bezpieczenstwo', 'haslo')"><?= $has_local_password ? 'Zmień hasło' : 'Ustaw hasło' ?> <i class="bi bi-chevron-right" aria-hidden="true"></i></a></div>
      </article>

      <article class="ma-card ma-tile" aria-labelledby="t-mfa">
        <div class="ma-tile-hd"><span class="ma-ic<?= $mfa_active ? ' ma-ic--ok' : ' ma-ic--warn' ?>" aria-hidden="true"><i class="bi bi-shield-check"></i></span><h2 id="t-mfa" class="ma-tile-t">Metody weryfikacji</h2></div>
        <p class="ma-tile-p">Drugi składnik chroni konto nawet wtedy, gdy ktoś pozna Twoje hasło.</p>
        <p class="ma-tile-meta"><?= $mfa_active ? 'Aktywne: ' . h($mfa_label) : 'Drugi składnik nie jest skonfigurowany.' ?></p>
        <div class="ma-tile-ft"><a href="<?= APP_URL ?>/tozsamosc/mfa.php" class="ma-link">Zarządzaj metodami <i class="bi bi-chevron-right" aria-hidden="true"></i></a></div>
      </article>

      <article class="ma-card ma-tile" aria-labelledby="t-m365">
        <div class="ma-tile-hd"><span class="ma-ic<?= $m365_active ? '' : ' ma-ic--off' ?>" aria-hidden="true"><i class="bi bi-microsoft"></i></span><h2 id="t-m365" class="ma-tile-t">Microsoft 365</h2></div>
        <p class="ma-tile-p"><?= $has_m365 ? 'Poczta, Teams i pliki organizacji (Entra ID).' : 'Konto Microsoft 365 nie zostało utworzone dla tej tożsamości.' ?></p>
        <?php if ($has_m365): ?><p class="ma-tile-meta"><?= $m365_active ? 'Aktywne' : 'Zawieszone' ?> · <?= h($m365_login ?: $panel_login) ?></p><?php endif; ?>
        <div class="ma-tile-ft"><a href="<?= APP_URL ?>/panel/m365.php" class="ma-link">Szczegóły konta<span class="visually-hidden"> Microsoft 365</span> <i class="bi bi-chevron-right" aria-hidden="true"></i></a></div>
      </article>

      <article class="ma-card ma-tile" aria-labelledby="t-svc">
        <div class="ma-tile-hd"><span class="ma-ic" aria-hidden="true"><i class="bi bi-grid"></i></span><h2 id="t-svc" class="ma-tile-t">Usługi</h2></div>
        <p class="ma-tile-p">Systemy, do których logujesz się tą tożsamością.</p>
        <div class="ma-dots" aria-hidden="true">
          <?php foreach ($services as $s): if (!empty($s['soon'])) continue; ?><span class="ma-dot" style="--c:<?= $SVC_C[$s['tone']] ?? '#0f6cbd' ?>" title="<?= h($s['name']) ?>"><i class="bi <?= h($s['icon']) ?>"></i></span><?php endforeach; ?>
        </div>
        <div class="ma-tile-ft"><a href="#uslugi" class="ma-link" @click.prevent="go('uslugi')">Wyświetl usługi (<?= count($services) ?>) <i class="bi bi-chevron-right" aria-hidden="true"></i></a></div>
      </article>

      <article class="ma-card ma-tile" aria-labelledby="t-rodo">
        <div class="ma-tile-hd"><span class="ma-ic" aria-hidden="true"><i class="bi bi-file-earmark-lock"></i></span><h2 id="t-rodo" class="ma-tile-t">Moje dane (RODO)</h2></div>
        <p class="ma-tile-p">Kto i po co przetwarza Twoje dane, upoważnienia i rejestr czynności na umowie.</p>
        <div class="ma-tile-ft">
          <a href="#rejestr" class="ma-link" @click.prevent="go('rejestr')">Przejrzyj<span class="visually-hidden"> swoje dane</span> <i class="bi bi-chevron-right" aria-hidden="true"></i></a>
          <a href="<?= APP_URL ?>/tozsamosc/rodo_export.php" target="_blank" rel="noopener" class="ma-link">Eksport PDF <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i><span class="visually-hidden"> (otwiera się w nowej karcie)</span></a>
        </div>
      </article>

      <?php if ($has_ika): ?>
      <article class="ma-card ma-tile" aria-labelledby="t-ika">
        <div class="ma-tile-hd"><span class="ma-ic<?= $ika_blocked ? ' ma-ic--warn' : '' ?>" aria-hidden="true"><i class="bi bi-123"></i></span><h2 id="t-ika" class="ma-tile-t">Kod IKA</h2></div>
        <p class="ma-tile-p">6-cyfrowy kod potwierdzający tożsamość przy dostępie do chronionych danych.</p>
        <p class="ma-tile-meta"><?= $ika_blocked ? 'Tymczasowo zablokowany po błędnych próbach.' : 'Aktywny.' ?></p>
        <div class="ma-tile-ft"><a href="#ika" class="ma-link" @click.prevent="go('ika')">Zmień kod IKA <i class="bi bi-chevron-right" aria-hidden="true"></i></a></div>
      </article>
      <?php endif; ?>

      <article class="ma-card ma-tile" aria-labelledby="t-help">
        <div class="ma-tile-hd"><span class="ma-ic" aria-hidden="true"><i class="bi bi-question-circle"></i></span><h2 id="t-help" class="ma-tile-t">Pomoc</h2></div>
        <p class="ma-tile-p">Do czego służy panel i co zrobić, gdy nie możesz się zalogować.</p>
        <div class="ma-tile-ft">
          <a href="#opanelu" class="ma-link" @click.prevent="go('opanelu')">Informacje o panelu <i class="bi bi-chevron-right" aria-hidden="true"></i></a>
          <a href="<?= APP_URL ?>/helpdesk/index.php" class="ma-link">Helpdesk <i class="bi bi-chevron-right" aria-hidden="true"></i></a>
        </div>
      </article>

      <?php if (is_admin()): ?>
      <article class="ma-card ma-tile" aria-labelledby="t-adm">
        <div class="ma-tile-hd"><span class="ma-ic" aria-hidden="true"><i class="bi bi-shield-fill-gear"></i></span><h2 id="t-adm" class="ma-tile-t">Administracja</h2></div>
        <p class="ma-tile-p">Konta, role, logowanie, katalog i federacja tożsamości.</p>
        <div class="ma-tile-ft"><a href="#administracja" class="ma-link" @click.prevent="go('administracja')">Narzędzia administratora <i class="bi bi-chevron-right" aria-hidden="true"></i></a></div>
      </article>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- ═══════════ DANE KONTA ═══════════ -->
<section id="podstawowe" class="ma-sec" aria-labelledby="podstawowe-h" x-show="tab === 'podstawowe'" x-cloak x-data="tzCopy">
  <?php $sec_title('podstawowe', 'Dane konta', 'Twój rekord w rejestrze tożsamości organizacji i uprawnienia wynikające z roli.'); ?>
  <p class="visually-hidden" aria-live="polite" x-text="msg"></p>

  <div class="ma-card">
    <div class="ma-card-hd"><h2 class="ma-card-t">Dane tożsamości</h2></div>
    <?php
    $copy_btn = function (string $val, string $label): string {
        if ($val === '') return '';
        return '<button type="button" class="ma-copy" @click="copy(' . h(json_encode($val)) . ', ' . h(json_encode($label)) . ')"'
             . ' aria-label="Kopiuj: ' . h($label) . '" title="Kopiuj"><i class="bi" :class="done === ' . h(json_encode($label)) . ' ? \'bi-check2\' : \'bi-copy\'" aria-hidden="true"></i></button>';
    };
    $m365_disp = $m365_login ?: ($has_m365 ? $panel_login : '');
    ?>
    <dl class="ma-rows">
      <div class="ma-row"><dt>Imię i nazwisko</dt><dd><?= h($user['name'] ?? '') ?></dd></div>
      <div class="ma-row"><dt>Numer UID</dt><dd><span class="ma-mono"><?= (int)$uid ?></span><?= $copy_btn((string)$uid, 'Numer UID') ?></dd></div>
      <div class="ma-row"><dt>Identyfikator sieciowy (login do panelu)</dt><dd><?= h($panel_login ?: '—') ?><?= $copy_btn((string)$panel_login, 'Identyfikator sieciowy') ?></dd></div>
      <div class="ma-row"><dt>Login Microsoft 365</dt><dd><?php if ($m365_disp !== ''): ?><?= h($m365_disp) ?><?= $copy_btn($m365_disp, 'Login Microsoft 365') ?><?php else: ?><span class="ma-sm">brak konta Microsoft 365</span><?php endif; ?></dd></div>
      <div class="ma-row"><dt>Identyfikator obiektu Entra ID</dt><dd><?php if ($microsoft_id): ?><span class="ma-mono" style="font-size:13px"><?= h($microsoft_id) ?></span><?= $copy_btn((string)$microsoft_id, 'Identyfikator obiektu Entra ID') ?><?php else: ?>—<?php endif; ?></dd></div>
      <div class="ma-row"><dt>Typ konta</dt><dd><?= h($account_type_label) ?></dd></div>
      <div class="ma-row"><dt>Źródło danych</dt><dd><?php
        if ($src_contract) { echo 'Umowa ' . h($src_contract['numer_umowy']); }
        elseif (is_admin()) { echo 'Wpis do KRS – powołanie'; }
        else { echo '—'; }
      ?></dd></div>
      <div class="ma-row"><dt>UID nadany</dt><dd><?= h(!empty($db_user['created_at']) ? date('d.m.Y', strtotime($db_user['created_at'])) : ($src_contract['data_zawarcia'] ?? '—')) ?></dd></div>
    </dl>
    <p class="ma-card-note">Numer UID został nadany automatycznie przy zawarciu umowy i jest Twoim stałym identyfikatorem w rejestrze tożsamości — nie można go zmienić. Login do panelu (identyfikator sieciowy) jest niezależny od loginu Microsoft 365.</p>
  </div>

  <h2 class="ma-h2">Rola i uprawnienia</h2>
  <div class="ma-card" x-data="{ all: false }">
    <div class="ma-role">
      <span class="ma-ic" aria-hidden="true"><i class="bi <?= $role_icon ?>"></i></span>
      <div class="tw-min-w-0">
        <div class="ma-card-t"><?= h($role_label) ?> <span class="ma-muted" style="font-weight:400;font-size:13px">· <code><?= h($role_key) ?></code></span></div>
        <p class="ma-tile-p" style="margin-top:.2rem"><?= h($role_desc ?: 'Rola nadana przez administratora — decyduje o tym, które moduły SZO widzisz i co możesz w nich zmieniać.') ?><?php if ($access): ?> Dostęp do <strong><?= count($access) ?></strong> modułów, w tym <strong><?= $access_write ?></strong> z edycją.<?php endif; ?></p>
      </div>
    </div>
    <?php if ($access): ?>
    <ul class="ma-tags tw-list-none tw-p-0 tw-m-0" aria-label="Dostępne moduły">
      <?php foreach ($access as $i => $a): ?>
      <li class="ma-tag<?= $a['level'] === 'edycja' ? ' ma-tag--w' : '' ?>"<?= $i >= 12 ? ' x-show="all" x-cloak' : '' ?>><?= h($a['label']) ?> <small><?= $a['level'] ?></small></li>
      <?php endforeach; ?>
      <?php if (count($access) > 12): ?>
      <li><button type="button" class="ma-tag" @click="all = !all" :aria-expanded="all ? 'true' : 'false'"><span x-text="all ? 'Zwiń' : 'Pokaż wszystkie (<?= count($access) ?>)'"></span></button></li>
      <?php endif; ?>
    </ul>
    <?php else: ?>
    <p class="ma-muted tw-m-0">Twoja rola nie daje dostępu do modułów systemu głównego (konto o zawężonym zakresie).</p>
    <?php endif; ?>
    <p class="ma-card-note">Roli ani uprawnień nie zmienisz tutaj — nadaje je administrator. Jeśli brakuje Ci dostępu, zgłoś to przez helpdesk.</p>
  </div>
</section>

<!-- ═══════════ ZABEZPIECZENIA ═══════════ -->
<?php
// Nagłówki ustawień: [ikona, tytuł, stan ok?, opis stanu]
$sec_heads = [
    'haslo'   => ['bi-key',          'Hasło do SZO' . ($has_m365 ? ' i Microsoft 365' : ''),
                  $has_local_password, $has_local_password ? 'Ustawione' . ($has_m365 ? ' · działa w obu systemach' : '') : 'Brak hasła lokalnego'],
    'telefon' => ['bi-phone',        'Telefon do SMS',
                  $phone_verified, $phone_verified ? 'Zweryfikowany · ' . tz_mask_phone($phone_saved) : ($phone_pending ? 'Oczekuje na kod SMS' : ($phone_saved ? 'Niezweryfikowany' : 'Brak numeru'))],
    'mfa'     => ['bi-shield-check', 'Metody weryfikacji (MFA)',
                  $mfa_active, $mfa_active ? $mfa_label : 'Brak drugiego składnika'],
    'pin'     => ['bi-telephone-outbound', 'Szybkie dzwonienie (aplikacja mobilna)',
                  $pin_is_set, $pin_is_set ? 'PIN ustawiony' : 'PIN nieustawiony'],
];
// Powiadomienia o bezpieczeństwie (modules/account_security): „gotowe”, gdy e-mail o nowym logowaniu i zmianie hasła jest włączony
require_once dirname(__DIR__) . '/modules/account_security/lib/accountSecurityNotify.php';
$as_notify_prefs = AccountSecurityNotify::prefs($uid);
$sec_heads['powiadomienia'] = ['bi-bell', 'Powiadomienia o bezpieczeństwie',
    $as_notify_prefs['new_login']['email'] && $as_notify_prefs['password']['email'], AccountSecurityNotify::summary($as_notify_prefs)];
$sec_head = function (string $k) use ($sec_heads): void {
    [$ic, $t, $ok, $st] = $sec_heads[$k];
    ?>
    <h3 class="ma-exp-h">
      <button type="button" class="ma-exp-btn" id="sec-<?= $k ?>-hd" aria-controls="sec-<?= $k ?>-bd" :aria-expanded="sec === '<?= $k ?>' ? 'true' : 'false'" @click="open('<?= $k ?>')">
        <span class="ma-ic <?= $ok ? 'ma-ic--ok' : 'ma-ic--warn' ?>" aria-hidden="true"><i class="bi <?= $ic ?>"></i></span>
        <span class="ma-exp-tx"><span class="ma-exp-t"><?= h($t) ?></span><span class="ma-exp-d"><?= h($st) ?></span></span>
        <span class="tz-badge <?= $ok ? 'tz-badge--ok' : 'tz-badge--warn' ?> tw-hidden sm:tw-inline-flex"><?= $ok ? 'Gotowe' : 'Do uzupełnienia' ?></span>
        <i class="bi bi-chevron-down ma-exp-chev" aria-hidden="true"></i>
      </button>
    </h3>
    <?php
};
?>
<section id="bezpieczenstwo" class="ma-sec" aria-labelledby="bezpieczenstwo-h" x-show="tab === 'bezpieczenstwo'" x-cloak
         x-data="{ sec: '<?= $sec_open ?>',
                   open(k) { this.sec = this.sec === k ? '' : k; },
                   reveal(k) { this.sec = k; this.$nextTick(() => { var el = document.getElementById('sec-' + k), b = document.getElementById('sec-' + k + '-hd');
                     if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' }); if (b) b.focus({ preventScroll: true }); }); } }"
         @tz-sec.window="reveal($event.detail)">
  <?php $sec_title('bezpieczenstwo', 'Zabezpieczenia', 'Metody logowania i odzyskiwania dostępu do konta. Rozwiń ustawienie, aby je zmienić.'); ?>

  <div class="ma-card ma-secsum">
    <div>
      <span class="ma-secsum-pct"><?= $sec_pct ?>%</span>
      <span class="ma-muted">stan zabezpieczeń</span>
    </div>
    <div>
      <div class="ma-prog" role="progressbar" aria-label="Stan zabezpieczeń" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $sec_pct ?>"><span class="is-<?= $sec_level ?>" style="width:<?= $sec_pct ?>%"></span></div>
      <p>Skonfigurowano <strong><?= $sec_ok ?> z <?= $sec_total ?></strong> elementów.
        <?php if ($sec_missing): ?>Brakuje:
          <?php foreach ($sec_missing as $mi => $i): ?><a href="#<?= !empty($i['tab']) ? h($i['tab']) : 'bezpieczenstwo' ?>" class="ma-link" style="min-height:0" @click.prevent="<?= h(!empty($i['tab']) ? $sec_go($i) : "reveal('" . $i['sec'] . "')") ?>"><?= h($i['miss']) ?></a><?= $mi < count($sec_missing) - 1 ? ', ' : '.' ?><?php endforeach; ?>
        <?php else: ?>Konto jest dobrze zabezpieczone.<?php endif; ?>
      </p>
    </div>
  </div>

  <h2 class="ma-h2">Metody logowania</h2>

  <!-- ── HASŁO ── -->
  <article id="sec-haslo" class="ma-exp" :class="sec === 'haslo' && 'is-open'" x-data="tzPassword(<?= h(json_encode($account_parts)) ?>)">
    <?php $sec_head('haslo'); ?>
    <div id="sec-haslo-bd" class="ma-exp-bd" role="region" aria-labelledby="sec-haslo-hd" x-show="sec === 'haslo'">
      <div class="ma-split">
        <form method="post" id="pwdForm">
          <input type="hidden" name="_csrf" value="<?= $csrf ?>">
          <input type="hidden" name="_action" value="pwd_change">
          <?php if (!$has_local_password): ?>
          <div class="ma-bar ma-bar--info" style="max-width:36rem"><i class="bi bi-info-circle-fill ma-bar-ic" aria-hidden="true"></i><div class="ma-bar-tx">Nie masz jeszcze hasła lokalnego SZO. Ustaw je — będzie też hasłem awaryjnym obok logowania Microsoft.</div></div>
          <?php else: ?>
          <div class="ma-field">
            <label for="pw_cur">Obecne hasło <span class="lbl-en">Current password</span></label>
            <input type="password" id="pw_cur" name="current_password" class="form-control" autocomplete="current-password" required>
          </div>
          <?php endif; ?>
          <div class="ma-field">
            <label for="pw_new">Nowe hasło <span class="lbl-en">New password</span></label>
            <div class="input-group">
              <input :type="show ? 'text' : 'password'" type="password" id="pw_new" name="new_password" class="form-control" autocomplete="new-password"
                     aria-describedby="pw_req pw_strength" minlength="12" required x-model="pw">
              <button class="btn btn-outline-secondary" type="button" @click="show = !show"
                      aria-label="Pokaż hasło" :aria-pressed="show ? 'true' : 'false'"><i class="bi" :class="show ? 'bi-eye-slash' : 'bi-eye'" aria-hidden="true"></i></button>
            </div>
            <div class="tz-meter" aria-hidden="true"><span :style="'width:' + pct + '%;background-color:' + color"></span></div>
            <div class="ma-help" id="pw_strength" aria-live="polite">Siła hasła: <span x-text="pw ? label : '—'"></span></div>
          </div>
          <div class="ma-field">
            <label for="pw_conf">Powtórz nowe hasło <span class="lbl-en">Confirm new password</span></label>
            <input type="password" id="pw_conf" name="confirm_password" class="form-control" autocomplete="new-password" minlength="12" required
                   x-model="pw2" :aria-invalid="mismatch ? 'true' : 'false'" aria-describedby="pw_conf_err">
            <div class="ma-err-tx" id="pw_conf_err" x-show="mismatch" x-cloak>Hasła nie są identyczne.</div>
          </div>
          <fieldset class="tw-border-0 tw-p-0 tw-m-0 tw-mb-4" style="max-width:36rem">
            <legend class="ma-legend" style="font-size:14px">Polityka haseł organizacji <span class="lbl-en">Entra ID password policy</span></legend>
            <ul class="tz-req" id="pw_req">
              <li :class="{ ok: req.len }"><span class="dot" aria-hidden="true" x-text="req.len ? '✓' : '○'"></span> Min. 12 znaków</li>
              <li :class="{ ok: req.up }"><span class="dot" aria-hidden="true" x-text="req.up ? '✓' : '○'"></span> Wielka litera (A–Z)</li>
              <li :class="{ ok: req.low }"><span class="dot" aria-hidden="true" x-text="req.low ? '✓' : '○'"></span> Mała litera (a–z)</li>
              <li :class="{ ok: req.num }"><span class="dot" aria-hidden="true" x-text="req.num ? '✓' : '○'"></span> Cyfra (0–9)</li>
              <li :class="{ ok: req.sym }"><span class="dot" aria-hidden="true" x-text="req.sym ? '✓' : '○'"></span> Znak specjalny (!@#…)</li>
              <li :class="{ ok: req.name }"><span class="dot" aria-hidden="true" x-text="req.name ? '✓' : '○'"></span> Nie zawiera nazwy konta</li>
            </ul>
          </fieldset>
          <button type="submit" class="tz-btn"><i class="bi bi-key" aria-hidden="true"></i><?= $has_local_password ? 'Zmień hasło' : 'Ustaw hasło' ?></button>
        </form>
        <aside class="ma-aside" aria-label="Wskazówki do hasła">
          <h4><i class="bi bi-arrow-repeat" aria-hidden="true"></i>Jedno hasło, <?= $has_m365 ? 'dwa systemy' : 'konto lokalne' ?></h4>
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
        </aside>
      </div>
    </div>
  </article>

  <!-- ── TELEFON ── -->
  <article id="sec-telefon" class="ma-exp" :class="sec === 'telefon' && 'is-open'">
    <?php $sec_head('telefon'); ?>
    <div id="sec-telefon-bd" class="ma-exp-bd" role="region" aria-labelledby="sec-telefon-hd" x-show="sec === 'telefon'">
      <div class="ma-split">
        <div>
          <?php if (!$sms_available): ?>
          <div class="ma-bar ma-bar--warn" role="alert"><i class="bi bi-exclamation-triangle-fill ma-bar-ic" aria-hidden="true"></i><div class="ma-bar-tx">Weryfikacja SMS jest obecnie niedostępna.</div></div>
          <?php endif; ?>

          <?php if ($phone_pending): ?>
          <p class="tw-mt-0">Wpisz 6-cyfrowy kod wysłany na numer <strong><?= h(tz_mask_phone($phone_pending)) ?></strong>.</p>
          <form method="post">
            <input type="hidden" name="_csrf" value="<?= $csrf ?>">
            <input type="hidden" name="_action" value="phone_verify">
            <div class="ma-field" style="max-width:320px">
              <label for="tel_code">Kod weryfikacyjny <span class="lbl-en">SMS code</span></label>
              <input type="text" id="tel_code" name="code" class="form-control tz-otp" inputmode="numeric"
                     autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" placeholder="000000"
                     aria-describedby="tel_code_help" autofocus required>
              <div id="tel_code_help" class="ma-help">Kod jest ważny 5 minut.</div>
            </div>
            <div class="ma-actions">
              <button type="submit" class="tz-btn"><i class="bi bi-check2-circle" aria-hidden="true"></i>Zweryfikuj</button>
              <button type="submit" form="tel_resend" class="tz-btn tz-btn--ghost"><i class="bi bi-arrow-repeat" aria-hidden="true"></i>Wyślij ponownie</button>
              <button type="submit" form="tel_cancel" class="tz-btn tz-btn--subtle">Anuluj</button>
            </div>
          </form>
          <form method="post" id="tel_resend" class="tw-hidden">
            <input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_action" value="phone_send">
            <input type="hidden" name="phone_number" value="<?= h($phone_pending) ?>">
          </form>
          <form method="post" id="tel_cancel" class="tw-hidden">
            <input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_action" value="phone_cancel">
          </form>
          <?php else: ?>
          <form method="post">
            <input type="hidden" name="_csrf" value="<?= $csrf ?>">
            <input type="hidden" name="_action" value="phone_send">
            <div class="ma-field" style="max-width:360px">
              <label for="tel_num">Numer komórkowy <span class="lbl-en">Mobile number</span></label>
              <div class="input-group">
                <span class="input-group-text" id="tel_prefix">+48</span>
                <input type="tel" id="tel_num" name="phone_number" class="form-control" inputmode="numeric"
                       autocomplete="tel-national" value="<?= h(substr(preg_replace('/\D/', '', $phone_saved), -9)) ?>"
                       placeholder="600 100 200" aria-describedby="tel_prefix tel_help" required>
              </div>
              <div id="tel_help" class="ma-help">Wyślemy 6-cyfrowy kod SMS. Standardowe opłaty operatora mogą obowiązywać.</div>
            </div>
            <div class="ma-actions">
              <button type="submit" class="tz-btn"><i class="bi bi-send" aria-hidden="true"></i><?= $phone_saved ? 'Zmień numer i wyślij kod' : 'Wyślij kod weryfikacyjny' ?></button>
              <?php if ($phone_saved): ?>
              <button type="submit" form="tel_remove" class="tz-btn tz-btn--danger" onclick="return confirm('Usunąć numer telefonu z tożsamości?')"><i class="bi bi-trash" aria-hidden="true"></i>Usuń numer</button>
              <?php endif; ?>
            </div>
          </form>
          <?php if ($phone_saved): ?>
          <form method="post" id="tel_remove" class="tw-hidden">
            <input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_action" value="phone_remove">
          </form>
          <?php endif; ?>
          <?php endif; ?>
        </div>
        <aside class="ma-aside" aria-label="Po co numer telefonu">
          <h4><i class="bi bi-life-preserver" aria-hidden="true"></i>Po co numer telefonu</h4>
          Numer służy do odzyskiwania dostępu (kod SMS przy ustawianiu nowego hasła) i do powiadomień MFA. Nie jest używany do marketingu ani pokazywany innym użytkownikom.
          <ul>
            <li>Po zapisaniu wyślemy 6-cyfrowy kod — numer działa dopiero po jego wpisaniu.</li>
            <li>Zmiana numeru wymaga ponownej weryfikacji.</li>
          </ul>
          <?php if ($phone_saved && !$phone_pending): ?>
          <dl class="ma-mini"><div><dt>Aktualny numer</dt><dd class="ma-mono"><?= h(tz_mask_phone($phone_saved)) ?></dd></div></dl>
          <?php endif; ?>
        </aside>
      </div>
    </div>
  </article>

  <!-- ── MFA ── -->
  <article id="sec-mfa" class="ma-exp" :class="sec === 'mfa' && 'is-open'">
    <?php $sec_head('mfa'); ?>
    <div id="sec-mfa-bd" class="ma-exp-bd" role="region" aria-labelledby="sec-mfa-hd" x-show="sec === 'mfa'">
      <div class="ma-split">
        <div>
          <ul class="ma-methods">
            <li class="ma-method">
              <span class="ma-ic<?= $totp_on ? ' ma-ic--ok' : ' ma-ic--off' ?>" aria-hidden="true"><i class="bi bi-phone"></i></span>
              <div class="ma-method-tx"><b>Aplikacja Authenticator</b><span>Kod jednorazowy z aplikacji Microsoft lub Google Authenticator — działa offline.</span></div>
              <span class="tz-badge <?= $totp_on ? 'tz-badge--ok' : 'tz-badge--off' ?>"><?= $totp_on ? 'Aktywna' : 'Nieaktywna' ?></span>
            </li>
            <?php if ($sms_available): ?>
            <li class="ma-method">
              <span class="ma-ic<?= $sms_on ? ' ma-ic--ok' : ' ma-ic--off' ?>" aria-hidden="true"><i class="bi bi-chat-dots"></i></span>
              <div class="ma-method-tx"><b>Kod SMS</b><span>Jednorazowy kod na zweryfikowany numer telefonu.</span></div>
              <span class="tz-badge <?= $sms_on ? 'tz-badge--ok' : 'tz-badge--off' ?>"><?= $sms_on ? 'Aktywny' : 'Nieaktywny' ?></span>
            </li>
            <?php endif; ?>
            <li class="ma-method">
              <span class="ma-ic<?= $has_wk ? ' ma-ic--ok' : ' ma-ic--off' ?>" aria-hidden="true"><i class="bi bi-fingerprint"></i></span>
              <div class="ma-method-tx"><b>Klucz bezpieczeństwa / passkey</b><span>Biometria, YubiKey lub Windows Hello — ochrona przed phishingiem.</span></div>
              <span class="tz-badge <?= $has_wk ? 'tz-badge--ok' : 'tz-badge--off' ?>"><?= $has_wk ? count($wk_keys_idx) . ' ' . (count($wk_keys_idx) === 1 ? 'klucz' : (count($wk_keys_idx) < 5 ? 'klucze' : 'kluczy')) : 'Brak' ?></span>
            </li>
          </ul>
          <div class="ma-actions">
            <a href="<?= APP_URL ?>/tozsamosc/mfa.php" class="tz-btn"><i class="bi bi-shield-plus" aria-hidden="true"></i>Zarządzaj metodami weryfikacji</a>
            <a href="<?= APP_URL ?>/tozsamosc/mfa.php#webauthn" class="tz-btn tz-btn--ghost"><i class="bi bi-fingerprint" aria-hidden="true"></i>Dodaj klucz</a>
          </div>
        </div>
        <aside class="ma-aside" aria-label="O drugim składniku logowania">
          <h4><i class="bi bi-shield-plus" aria-hidden="true"></i>Drugi składnik logowania</h4>
          Nawet wykradzione hasło nie wystarczy do wejścia na konto. Sparuj smartfon z kontem organizacji — zalecamy aplikację <strong>Microsoft Authenticator</strong>.
          <ul>
            <li><strong>Klucz / passkey</strong> to najmocniejsza ochrona — odporna na phishing.</li>
            <li>Kody zapasowe zapisz w bezpiecznym miejscu; każdy działa raz.</li>
          </ul>
        </aside>
      </div>
    </div>
  </article>

  <?php if ($pin_allowed): ?>
  <!-- ── Szybkie dzwonienie: UID + PIN ── -->
  <article id="sec-pin" class="ma-exp" :class="sec === 'pin' && 'is-open'">
    <?php $sec_head('pin'); ?>
    <div id="sec-pin-bd" class="ma-exp-bd" role="region" aria-labelledby="sec-pin-hd" x-show="sec === 'pin'">
      <div class="ma-split">
        <div>
          <form method="post" class="tw-mb-3">
            <input type="hidden" name="_csrf" value="<?= $csrf ?>">
            <input type="hidden" name="_action" value="mobile_pin_save">
            <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-x-4" style="max-width:28rem">
              <div class="ma-field">
                <label for="mpin"><?= $pin_is_set ? 'Nowy PIN' : 'PIN' ?> (<?= MOBILE_PIN_LENGTH ?> cyfry)</label>
                <input type="password" id="mpin" name="mobile_pin" class="form-control" required
                       inputmode="numeric" autocomplete="new-password" pattern="[0-9]*"
                       minlength="<?= MOBILE_PIN_LENGTH ?>" maxlength="<?= MOBILE_PIN_LENGTH ?>" aria-describedby="mpin_help">
              </div>
              <div class="ma-field">
                <label for="mpin2">Powtórz PIN</label>
                <input type="password" id="mpin2" name="mobile_pin_confirm" class="form-control" required
                       inputmode="numeric" autocomplete="new-password" pattern="[0-9]*"
                       minlength="<?= MOBILE_PIN_LENGTH ?>" maxlength="<?= MOBILE_PIN_LENGTH ?>">
              </div>
            </div>
            <p id="mpin_help" class="ma-help tw-mt-0 tw-mb-3" style="max-width:36rem">
              Bez powtórzonej cyfry i bez ciągu kolejnych cyfr. Po <?= MOBILE_PIN_MAX_FAILS ?> błędnych próbach
              logowanie PIN-em blokuje się na <?= MOBILE_PIN_LOCK_MIN ?> min — zwykłe logowanie działa dalej.
              PIN-u nie zobaczy nikt, także administrator; jeśli go zapomnisz, ustaw nowy tutaj.
            </p>
            <button type="submit" class="tz-btn"><i class="bi bi-key" aria-hidden="true"></i><?= $pin_is_set ? 'Zmień PIN' : 'Ustaw PIN' ?></button>
          </form>
          <?php if ($pin_is_set): ?>
          <form method="post" onsubmit="return confirm('Usunąć PIN? Szybkie logowanie do dialera przestanie działać.')">
            <input type="hidden" name="_csrf" value="<?= $csrf ?>">
            <input type="hidden" name="_action" value="mobile_pin_remove">
            <button type="submit" class="tz-btn tz-btn--danger tz-btn--sm"><i class="bi bi-trash" aria-hidden="true"></i>Usuń PIN</button>
          </form>
          <?php endif; ?>
        </div>
        <aside class="ma-aside" aria-label="Dialer CRM na telefonie">
          <h4><i class="bi bi-phone-vibrate" aria-hidden="true"></i>Dialer CRM na telefonie</h4>
          Aplikacja mobilna <strong>Dzwoń</strong> pozwala wyszukać kontakt CRM i zadzwonić jednym dotknięciem. Wchodzisz do niej numerem konta i PIN-em — a potem, jak wszędzie w CRM, kodem IKA. Ta sesja otwiera <strong>wyłącznie</strong> dialer.
          <dl class="ma-mini">
            <div><dt>Adres aplikacji</dt><dd><a href="<?= h($pin_app_url) ?>"><?= h(preg_replace('#^https?://#', '', $pin_app_url)) ?></a></dd></div>
            <div><dt>Numer konta (UID)</dt><dd class="ma-mono"><?= (int)$uid ?></dd></div>
            <div><dt>PIN</dt><dd><?php if ($pin_is_set): ?>ustawiony<?php if (!empty($db_user['mobile_pin_set_at'])): ?> od <?= h(date('d.m.Y', strtotime((string)$db_user['mobile_pin_set_at']))) ?><?php endif; ?><?php else: ?>nieustawiony<?php endif; ?></dd></div>
          </dl>
          <?php if ($pin_lock): ?><p class="tw-mt-2 tw-mb-0"><span class="tz-badge tz-badge--warn">Zablokowany na <?= (int)$pin_lock ?> min po błędnych próbach</span></p><?php endif; ?>
        </aside>
      </div>
    </div>
  </article>
  <?php endif; ?>
  <?php include dirname(__DIR__) . '/modules/account_security/view/notifySettings.php'; // ustawienie #sec-powiadomienia ?>
</section>

<?php if ($has_ika): ?>
<!-- ═══════════ KOD IKA ═══════════ -->
<section id="ika" class="ma-sec" aria-labelledby="ika-h" x-show="tab === 'ika'" x-cloak>
  <?php $sec_title('ika', 'Kod IKA', 'Indywidualny Kod Autoryzacyjny — 6 cyfr potwierdzających Twoją tożsamość przy dostępie do chronionych danych (np. umów).'); ?>
  <div class="ma-card" style="max-width:52rem">
    <div class="ma-card-hd">
      <h2 class="ma-card-t">Zmień kod IKA</h2>
      <span class="tz-badge <?= $ika_blocked ? 'tz-badge--warn' : 'tz-badge--ok' ?>"><?= $ika_blocked ? 'Tymczasowo zablokowany' : 'Aktywny' ?></span>
    </div>
    <?php if ($ika_blocked): ?>
    <div class="ma-bar ma-bar--warn" role="alert"><i class="bi bi-hourglass-split ma-bar-ic" aria-hidden="true"></i><div class="ma-bar-tx">Kod IKA jest tymczasowo zablokowany po błędnych próbach. Spróbuj ponownie później.</div></div>
    <?php endif; ?>
    <p class="ma-tile-p tw-mt-0 tw-mb-4">Aby zmienić kod, podaj obecny i dwukrotnie wpisz nowy.</p>
    <form method="post" class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-x-4" style="max-width:40rem">
      <input type="hidden" name="_csrf" value="<?= $csrf ?>">
      <input type="hidden" name="_action" value="change_ika">
      <div class="ma-field">
        <label for="ika_current">Obecny kod IKA</label>
        <input type="text" id="ika_current" name="ika_current" class="form-control tz-otp"
               inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="••••••" autocomplete="off" required <?= $ika_blocked ? 'disabled' : '' ?>>
      </div>
      <div class="ma-field">
        <label for="ika_new">Nowy kod IKA</label>
        <input type="text" id="ika_new" name="ika_new" class="form-control tz-otp"
               inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="000000" autocomplete="off" required <?= $ika_blocked ? 'disabled' : '' ?>>
      </div>
      <div class="ma-field">
        <label for="ika_confirm">Powtórz nowy kod</label>
        <input type="text" id="ika_confirm" name="ika_confirm" class="form-control tz-otp"
               inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="000000" autocomplete="off" required <?= $ika_blocked ? 'disabled' : '' ?>>
      </div>
      <div class="sm:tw-col-span-3"><button type="submit" class="tz-btn" <?= $ika_blocked ? 'disabled' : '' ?>><i class="bi bi-key" aria-hidden="true"></i>Zmień kod IKA</button></div>
    </form>
    <p class="ma-card-note"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Kodu IKA nie udostępniaj nikomu. Jeśli go nie pamiętasz, zresetujesz go przy bramie IKA (AdminCode od administratora, kod e-mail lub PESEL).</p>
  </div>
</section>
<?php endif; ?>

<?php include dirname(__DIR__) . '/modules/account_security/view/devicesSection.php'; // sekcja #urzadzenia ?>

<!-- ═══════════ USŁUGI ═══════════ -->
<section id="uslugi" class="ma-sec" aria-labelledby="uslugi-h" x-show="tab === 'uslugi'" x-cloak>
  <?php $sec_title('uslugi', 'Usługi', 'Systemy, do których logujesz się tą tożsamością — co jest włączone, co czeka na konfigurację, a co jest niedostępne.'); ?>
  <div class="ma-svcs">
    <?php foreach ($services as $si => $s): ?>
    <article class="ma-card ma-svc<?= !empty($s['soon']) ? ' is-soon' : '' ?>" aria-labelledby="svc-<?= $si ?>">
      <div class="ma-svc-hd">
        <span class="ma-svc-ic" style="--c:<?= $SVC_C[$s['tone']] ?? '#0f6cbd' ?>" aria-hidden="true"><i class="bi <?= h($s['icon']) ?>"></i></span>
        <div class="tw-min-w-0">
          <h2 id="svc-<?= $si ?>" class="ma-svc-t"><?= h($s['name']) ?></h2>
          <span class="tz-badge <?= $STATUS_CLASS[$s['status']] ?>"><?= h($s['status_label']) ?></span>
        </div>
      </div>
      <p class="ma-svc-p"><?= h($s['desc']) ?></p>
      <div class="ma-svc-ft">
        <?php if (!empty($s['ldap'])): ?>
          <?php if ($ldap_enabled && !$has_ldap): ?>
          <form method="post" class="m-0"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_action" value="ldap_create">
            <button type="submit" class="tz-btn tz-btn--sm" onclick="return confirm('Utworzyć konto w katalogu LDAP na podstawie Twoich danych tożsamości?');">Utwórz konto LDAP</button></form>
          <?php elseif ($has_ldap): ?>
          <form method="post" class="m-0"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_action" value="ldap_create">
            <button type="submit" class="tz-btn tz-btn--ghost tz-btn--sm">Odśwież wpis LDAP</button></form>
          <?php endif; ?>
        <?php elseif (!empty($s['soon'])): ?>
          <span class="ma-muted">Wkrótce</span>
        <?php elseif (!empty($s['tab'])): ?>
          <a href="#<?= h($s['tab']) ?>" class="ma-link" @click.prevent="go('<?= h($s['tab']) ?>'<?= !empty($s['sec']) ? ", '" . h($s['sec']) . "'" : '' ?>)"><?= h($s['cta']) ?><span class="visually-hidden"> — <?= h($s['name']) ?></span> <i class="bi bi-chevron-right" aria-hidden="true"></i></a>
        <?php else: ?>
          <a href="<?= h($s['href']) ?>" class="ma-link"><?= h($s['cta']) ?><span class="visually-hidden"> — <?= h($s['name']) ?></span> <i class="bi bi-chevron-right" aria-hidden="true"></i></a>
        <?php endif; ?>
      </div>
    </article>
    <?php endforeach; ?>
  </div>

  <?php include dirname(__DIR__) . '/modules/account_security/view/m365Status.php'; // stan konta w Entra ID (odczyt na żądanie) ?>

  <h2 class="ma-h2">Narzędzia zewnętrzne</h2>
  <div class="ma-svcs">
    <article class="ma-card ma-svc" aria-labelledby="svc-trello">
      <div class="ma-svc-hd">
        <span class="ma-svc-ic" style="--c:#0052cc" aria-hidden="true"><i class="bi bi-kanban"></i></span>
        <div class="tw-min-w-0"><h2 id="svc-trello" class="ma-svc-t">Trello</h2><span class="tz-badge tz-badge--info">Zewnętrzne</span></div>
      </div>
      <p class="ma-svc-p">Tablice Kanban — zadania i projekty zespołu.</p>
      <div class="ma-svc-ft"><a href="<?= h($trello_ws_url) ?>" target="_blank" rel="noopener noreferrer" class="ma-link">Otwórz Trello <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i><span class="visually-hidden"> (otwiera się w nowej karcie)</span></a></div>
    </article>
    <?php if (can_write('admin')): ?>
    <article class="ma-card ma-svc" aria-labelledby="svc-trimp">
      <div class="ma-svc-hd">
        <span class="ma-svc-ic" style="--c:#424242" aria-hidden="true"><i class="bi bi-download"></i></span>
        <div class="tw-min-w-0"><h2 id="svc-trimp" class="ma-svc-t">Import z Trello</h2><span class="tz-badge tz-badge--off">Administrator</span></div>
      </div>
      <p class="ma-svc-p">Przeniesienie zadań z tablic Trello do zadań SZO.</p>
      <div class="ma-svc-ft"><a href="<?= APP_URL ?>/admin/trello_import.php" class="ma-link">Importuj zadania <i class="bi bi-chevron-right" aria-hidden="true"></i></a></div>
    </article>
    <?php endif; ?>
  </div>
</section>

<!-- ═══════════ MOJE DANE (RODO) ═══════════ -->
<section id="rejestr" class="ma-sec" aria-labelledby="rejestr-h" x-show="tab === 'rejestr'" x-cloak>
  <?php $sec_title('rejestr', 'Moje dane (RODO)', 'Co dzieje się z Twoimi danymi osobowymi, kto ma upoważnienie do ich przetwarzania i jakie czynności wykonano na Twojej umowie.',
      '<a href="' . APP_URL . '/tozsamosc/rodo_export.php" target="_blank" rel="noopener" class="tz-btn"><i class="bi bi-file-earmark-arrow-down" aria-hidden="true"></i>Pobierz eksport danych (PDF)<span class="visually-hidden"> — otwiera się w nowej karcie</span></a>'); ?>
  <div class="ma-reg">
    <div class="ma-stack">
      <div class="ma-card">
        <div class="ma-card-hd"><h2 class="ma-card-t">Co dzieje się z Twoimi danymi</h2></div>
        <dl class="ma-mini" style="margin-bottom:0">
          <div><dt>Administrator danych</dt><dd><?= h($rodo_org['name'] ?: '—') ?></dd></div>
          <div><dt>Siedziba</dt><dd><?= h(trim(($rodo_org['address'] ?? '') . ' ' . ($rodo_org['city'] ?? ''))) ?: '—' ?></dd></div>
          <div><dt>NIP / KRS</dt><dd><?= h(trim(($rodo_org['nip'] ?? '') . (!empty($rodo_org['krs']) ? ' / ' . $rodo_org['krs'] : ''))) ?: '—' ?></dd></div>
          <div><dt>Cel przetwarzania</dt><dd>Realizacja umowy i obowiązków organizacji</dd></div>
          <div><dt>Podstawa prawna</dt><dd>Wykonanie umowy (art. 6 ust. 1 lit. b RODO)</dd></div>
          <div><dt>Okres przechowywania</dt><dd>Czas trwania umowy + okres wymagany przepisami</dd></div>
        </dl>
        <p class="ma-card-note"><i class="bi bi-lock me-1" aria-hidden="true"></i>Masz prawo dostępu do swoich danych, sprostowania i ograniczenia przetwarzania. Upoważnienie oznacza obowiązek <strong>poufności</strong> — również po zakończeniu współpracy.</p>
      </div>

      <div class="ma-card">
        <div class="ma-card-hd"><h2 class="ma-card-t">Upoważnienia do przetwarzania danych</h2><span class="tz-badge tz-badge--off"><?= count($rodo_auth) ?></span></div>
        <?php if (!$rodo_auth): ?>
        <p class="ma-muted tw-m-0">Brak upoważnień powiązanych z Twoim kontem.</p>
        <?php else: ?>
          <?php foreach ($rodo_auth as $a): $scope = json_decode($a['scope_items'] ?? '[]', true) ?: []; ?>
          <div class="ma-auth<?= $a['status'] !== 'aktywne' ? ' is-old' : '' ?>">
            <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-2">
              <span class="ma-mono" style="font-size:13px"><?= h($a['number']) ?></span>
              <?= rodo_status_badge($a['status']) ?>
              <a href="<?= APP_URL ?>/rodo/print.php?id=<?= (int)$a['id'] ?>" target="_blank" rel="noopener" class="tz-btn tz-btn--ghost tz-btn--sm tw-ml-auto"><i class="bi bi-printer" aria-hidden="true"></i>Drukuj<span class="visually-hidden"> upoważnienie <?= h($a['number']) ?></span></a>
            </div>
            <div class="ma-tl-m">
              od <?= !empty($a['authorized_from']) ? h(date('d.m.Y', strtotime($a['authorized_from']))) : '—' ?>
              <?= !empty($a['authorized_until']) ? ' do ' . h(date('d.m.Y', strtotime($a['authorized_until']))) : ' (do zakończenia umowy)' ?>
            </div>
            <?php if ($scope || !empty($a['scope_custom'])): ?>
            <ul>
              <?php foreach ($scope as $k): ?><li><?= h(RODO_SCOPE_ITEMS[$k] ?? $k) ?></li><?php endforeach; ?>
              <?php if (!empty($a['scope_custom'])): ?><li><?= h($a['scope_custom']) ?></li><?php endif; ?>
            </ul>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="ma-card" x-data="{ f: 'all' }">
      <div class="ma-card-hd">
        <h2 class="ma-card-t">Czynności na Twojej umowie</h2>
        <div class="ma-seg tw-ml-auto" role="group" aria-label="Pokaż czynności">
          <button type="button" :aria-pressed="f === 'all' ? 'true' : 'false'" @click="f = 'all'">Wszystko</button>
          <button type="button" :aria-pressed="f === 'enter' ? 'true' : 'false'" @click="f = 'enter'">Wejścia</button>
          <button type="button" :aria-pressed="f === 'mod' ? 'true' : 'false'" @click="f = 'mod'">Modyfikacje</button>
        </div>
      </div>
      <p class="ma-muted tw-mt-0">Rejestr wejść (dostępów) i modyfikacji danych umowy — ostatnie 50 zdarzeń.</p>
      <?php if (!$rodo_history): ?>
      <p class="ma-muted tw-m-0">Brak zapisanych wejść ani modyfikacji.</p>
      <?php else: ?>
      <ol class="ma-tl">
        <?php foreach ($rodo_history as $ev):
          [$lbl, $ic] = $ACT[$ev['action']] ?? [$ev['action'], 'bi-dot'];
          $is_enter = in_array($ev['action'], $REG_ENTER, true);
        ?>
        <li x-show="f === 'all' || f === '<?= $is_enter ? 'enter' : 'mod' ?>'">
          <span class="ma-tl-ic<?= $is_enter ? '' : ' is-mod' ?>" aria-hidden="true"><i class="bi <?= h($ic) ?>"></i></span>
          <div class="tw-min-w-0">
            <div class="ma-tl-t"><?= h($lbl) ?> <span class="tz-badge <?= $is_enter ? 'tz-badge--off' : 'tz-badge--info' ?>"><?= $is_enter ? 'Wejście' : 'Modyfikacja' ?></span></div>
            <div class="ma-tl-m">
              <?= !empty($ev['created_at']) ? h(date('d.m.Y H:i', strtotime($ev['created_at']))) : '' ?> · <?= h(ucfirst($ev['contract_type'])) ?>
              <?php if (!empty($ev['note'])): ?> · <?= h(mb_strimwidth($ev['note'], 0, 120, '…')) ?><?php endif; ?>
            </div>
          </div>
        </li>
        <?php endforeach; ?>
      </ol>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- ═══════════ POMOC I INFORMACJE ═══════════ -->
<section id="opanelu" class="ma-sec" aria-labelledby="opanelu-h" x-show="tab === 'opanelu'" x-cloak>
  <?php $sec_title('opanelu', 'Pomoc i informacje', 'System Tożsamości to Twoje „konto o koncie” — miejsce, w którym zarządzasz logowaniem i tożsamością w organizacji. Nie służy do codziennej pracy.'); ?>
  <div class="ma-2col">
    <div class="ma-card">
      <div class="ma-card-hd"><span class="ma-ic ma-ic--ok" aria-hidden="true"><i class="bi bi-check-lg"></i></span><h2 class="ma-card-t">Do tego służy</h2></div>
      <ul class="ma-list ma-list--yes">
        <li><i class="bi bi-check-circle-fill" aria-hidden="true"></i><span>Sprawdzić swój <strong>numer UID</strong> i login (identyfikator sieciowy).</span></li>
        <li><i class="bi bi-check-circle-fill" aria-hidden="true"></i><span><strong>Zmienić lub zresetować hasło</strong> — w panelu SZO i Microsoft 365 naraz.</span></li>
        <li><i class="bi bi-check-circle-fill" aria-hidden="true"></i><span>Ustawić i zweryfikować <strong>numer telefonu</strong> (SMS, odzyskiwanie dostępu).</span></li>
        <li><i class="bi bi-check-circle-fill" aria-hidden="true"></i><span>Włączyć <strong>logowanie dwuetapowe (MFA)</strong> albo klucz bezpieczeństwa.</span></li>
        <li><i class="bi bi-check-circle-fill" aria-hidden="true"></i><span>Zobaczyć swoje <strong>usługi, upoważnienia RODO i rejestr czynności</strong>.</span></li>
      </ul>
    </div>
    <div class="ma-card">
      <div class="ma-card-hd"><span class="ma-ic ma-ic--err" aria-hidden="true"><i class="bi bi-x-lg"></i></span><h2 class="ma-card-t">Czym nie jest</h2></div>
      <ul class="ma-list ma-list--no">
        <li><i class="bi bi-x-circle-fill" aria-hidden="true"></i><span>To <strong>nie</strong> panel do pracy — <strong>zadań, komunikatów i kalendarza</strong> szukaj w <a href="<?= APP_URL ?>/portal.php">SZO</a>.</span></li>
        <li><i class="bi bi-x-circle-fill" aria-hidden="true"></i><span><strong>Nie</strong> znajdziesz tu <strong>treści umów ani dokumentów</strong> (panel SZO / EZD).</span></li>
        <li><i class="bi bi-x-circle-fill" aria-hidden="true"></i><span><strong>Nie</strong> zgłaszasz tu problemów — od tego jest <strong>helpdesk</strong>.</span></li>
        <li><i class="bi bi-x-circle-fill" aria-hidden="true"></i><span><strong>Nie</strong> zmienisz tu swojej <strong>roli ani uprawnień</strong> (robi to administrator).</span></li>
        <li><i class="bi bi-x-circle-fill" aria-hidden="true"></i><span>To <strong>nie</strong> jest Twoja <strong>skrzynka e-mail</strong> — pocztę masz w Microsoft 365.</span></li>
      </ul>
    </div>
  </div>

  <h2 class="ma-h2">Jak to działa</h2>
  <div class="ma-card">
    <ul class="ma-list ma-list--info">
      <li><i class="bi bi-1-circle-fill" aria-hidden="true"></i><span>Twoje konto i <strong>numer UID</strong> powstają automatycznie w chwili <strong>zawarcia umowy</strong> — UID jest stały i niezmienny.</span></li>
      <li><i class="bi bi-2-circle-fill" aria-hidden="true"></i><span><strong>Login do panelu</strong> (identyfikator sieciowy) może różnić się od <strong>loginu Microsoft 365</strong> — oba widzisz w <a href="#podstawowe" @click.prevent="go('podstawowe')">danych konta</a>.</span></li>
      <li><i class="bi bi-3-circle-fill" aria-hidden="true"></i><span>Zmiana hasła <strong>synchronizuje się</strong> jednocześnie z panelem SZO i Microsoft 365 / Entra ID.</span></li>
    </ul>
  </div>

  <h2 class="ma-h2">Potrzebujesz pomocy?</h2>
  <div class="ma-actions">
    <a href="<?= APP_URL ?>/helpdesk/index.php" class="tz-btn"><i class="bi bi-headset" aria-hidden="true"></i>Zgłoś do helpdesku</a>
    <a href="<?= APP_URL ?>/user/verify_reset.php" class="tz-btn tz-btn--ghost"><i class="bi bi-key" aria-hidden="true"></i>Nie mogę się zalogować</a>
    <a href="<?= APP_URL ?>/portal.php" class="tz-btn tz-btn--ghost"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>Przejdź do SZO</a>
  </div>
  <?php if (!$first_visit): ?>
  <p class="ma-card-note"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Wprowadzenie zostało już wyświetlone przy pierwszym wejściu.</p>
  <?php endif; ?>
</section>

<?php if (is_admin()): ?>
<!-- ═══════════ ADMINISTRACJA ═══════════ -->
<section id="administracja" class="ma-sec" aria-labelledby="administracja-h" x-show="tab === 'administracja'" x-cloak>
  <?php $sec_title('administracja', 'Administracja', 'Zarządzanie kontami, rolami, logowaniem i federacją tożsamości.'); ?>
  <?php $admin_tiles = [
    ['Użytkownicy',          'Konta, role, aktywacja, import z katalogu AD', 'bi-people',       APP_URL . '/tozsamosc/uzytkownicy.php'],
    ['Raport bezpieczeństwa kont', 'Konta bez MFA, bez telefonu, uśpione; eksport CSV', 'bi-shield-exclamation', APP_URL . '/tozsamosc/raport.php'],
    ['Role i uprawnienia',   'Macierz modułów per rola',                     'bi-diagram-3',    APP_URL . '/tozsamosc/role.php'],
    ['Dodatkowe moduły',     'Uprawnienia indywidualne ponad rolę',          'bi-grid-3x3-gap', APP_URL . '/tozsamosc/moduly.php'],
    ['Kody IKA',             'Nadawanie i reset kodów autoryzacyjnych',      'bi-123',          APP_URL . '/tozsamosc/ika_admin.php'],
    ['Synchronizacja kont',  'Wysyłka zmian do systemów zewnętrznych',       'bi-arrow-repeat', APP_URL . '/tozsamosc/sync.php'],
    ['Katalog LDAP',         'Połączenie i wpisy w katalogu organizacji',   'bi-diagram-2',    APP_URL . '/tozsamosc/ldap.php'],
    ['SAML / SSO',           'Federacja tożsamości (IdP)',                   'bi-boxes',        APP_URL . '/tozsamosc/saml.php'],
    ['Ustawienia logowania', 'Metody logowania, polityki',                   'bi-sliders',      APP_URL . '/tozsamosc/ustawienia.php'],
    ['Macierz dostępu EZD',  'Kto widzi co w Wirtualnym biurku',             'bi-shield-check', APP_URL . '/tozsamosc/ezd_macierz.php'],
    ['Rejestr audytu',       'Zdarzenia logowania i zmian kont',             'bi-journal-text', APP_URL . '/tozsamosc/audit.php'],
    ['Klucze API',           'Dostęp aplikacji zewnętrznych',                'bi-key-fill',     APP_URL . '/admin/api_manage.php?tab=api'],
  ]; ?>
  <div class="ma-navcards">
    <?php foreach ($admin_tiles as [$t, $d, $i, $u]): ?>
    <a class="ma-card ma-navcard" href="<?= h($u) ?>">
      <span class="ma-ic" aria-hidden="true"><i class="bi <?= $i ?>"></i></span>
      <span class="tw-min-w-0"><b><?= h($t) ?></b><span class="d"><?= h($d) ?></span></span>
      <i class="bi bi-chevron-right" aria-hidden="true"></i>
    </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<script>
document.addEventListener('alpine:init', function () {
  // Kopiowanie identyfikatorów (UID, login, obiekt Entra) — komunikat dla czytnika w aria-live, ikona ✓ przez 1,5 s.
  Alpine.data('tzCopy', function () {
    return {
      msg: '', done: '',
      copy: function (text, label) {
        var self = this;
        var ok = function () { self.done = label; self.msg = 'Skopiowano: ' + label; setTimeout(function () { if (self.done === label) self.done = ''; }, 1500); };
        if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(text).then(ok, function () { self.msg = 'Nie udało się skopiować.'; });
        else {
          var ta = document.createElement('textarea'); ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
          document.body.appendChild(ta); ta.select();
          try { document.execCommand('copy'); ok(); } catch (e) { self.msg = 'Nie udało się skopiować.'; }
          document.body.removeChild(ta);
        }
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
      get color() { var s = this.score; return s >= 6 ? '#107c10' : s >= 5 ? '#498205' : s >= 3 ? '#bc4b09' : '#c50f1f'; },
      get label() { var s = this.score; return s >= 6 ? 'bardzo silne' : s >= 5 ? 'silne' : s >= 3 ? 'średnie' : 'słabe'; },
      get mismatch() { return this.pw2.length > 0 && this.pw2 !== this.pw; }
    };
  });
});
</script>

<?php include __DIR__ . '/_foot.php';
