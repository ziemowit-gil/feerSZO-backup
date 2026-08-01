<?php
/**
 * verify_reset.php — Autonomiczne odzyskiwanie dostępu metodą cross-match.
 *
 * 3 kroki w jednej stronie (state machine via POST + session):
 *   1. Cross-match (email + numer_umowy + PESEL/dokument)
 *   2. Weryfikacja kodu SMS
 *   3. Ustawienie nowego hasła
 *
 * Strona STANDALONE — nie includuje header.php.
 * Dostępna bez logowania.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/cpc.php';
require_once dirname(__DIR__) . '/includes/approval.php';
require_once dirname(__DIR__) . '/includes/sms.php';
require_once dirname(__DIR__) . '/includes/password_validator.php';

// ── Sesja (bez wymagania logowania) ──────────────────────────────────────────
auth_start();

// ── Rate limiting (max 5 prób / IP / godzina) ─────────────────────────────────
function _vr_rate_check(): bool {
    $pdo = db();
    // Upewnij się, że tabela istnieje (tworzona przez auth_security, ale tu może nie być includowana)
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            identifier TEXT NOT NULL,
            ip         TEXT NOT NULL DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } catch (\Throwable $e) {}

    $ip    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $since = date('Y-m-d H:i:s', time() - 3600);
    $stmt  = $pdo->prepare(
        "SELECT COUNT(*) AS c FROM login_attempts WHERE identifier = ? AND created_at > ?"
    );
    $stmt->execute([$ip, $since]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return (int) ($row['c'] ?? 0) < 5;
}

function _vr_rate_record(): void {
    $ip  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    try {
        db()->prepare("INSERT INTO login_attempts (identifier, ip) VALUES (?, ?)")
            ->execute([$ip, $ip]);
    } catch (\Throwable $e) {}
}

// ── Stała błędu (unikamy enumeracji) ─────────────────────────────────────────
const VR_ERR_CROSSMATCH  = 'Nie udało się zweryfikować danych. Sprawdź wprowadzone informacje.';
const VR_ERR_SMS         = 'Podany kod jest nieprawidłowy lub wygasł. Spróbuj ponownie.';
const VR_ERR_RATE        = 'Zbyt wiele prób weryfikacji. Spróbuj ponownie za godzinę.';

/**
 * Zakłada konto portalu dla wolontariusza, który ma umowę, ale nie ma jeszcze
 * konta w `users` (np. e-mail nie przeszedł walidacji przy zapisie umowy).
 * Wywoływane WYŁĄCZNIE po pozytywnej weryfikacji cross-match (numer umowy +
 * e-mail + PESEL/dokument) — ten sam próg bezpieczeństwa co reset hasła.
 * Zwraca id nowego konta lub 0.
 */
function _vr_provision_wolontariat_account(array $contract): int {
    $email = trim((string)($contract['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return 0;
    if (db_one("SELECT id FROM users WHERE email = ?", [$email])) return 0; // nie duplikuj

    $hash = password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT);
    db_insert('users', [
        'name'         => $contract['imie_nazwisko'] ?: $email,
        'email'        => $email,
        'password'     => $hash,
        'role'         => 'viewer',
        'is_active'    => 1,
        'portal_scope' => $contract['portal_scope'] ?: null,
        'created_at'   => date('Y-m-d H:i:s'),
    ]);
    $uid = (int) db()->lastInsertId();
    log_system_action(
        $uid,
        'self_register_wolontariat',
        'Konto założone samodzielnie (umowa ' . ($contract['numer_umowy'] ?? '') . ') po weryfikacji cross-match, IP: ' . ($_SERVER['REMOTE_ADDR'] ?? '')
    );
    return $uid;
}

/**
 * Wykrywa, czy dla podanych danych (e-mail LUB nazwisko) istnieje umowa, ale NIE
 * istnieje aktywne konto panelowe (users). Służy do zaproponowania założenia konta.
 * Zwraca ['email','name','type','type_label','has_numer'] albo null.
 *
 * Uwaga prywatność: potwierdza istnienie umowy → wymagamy DOKŁADNEGO e-maila albo
 * pełnego nazwiska (min. 3 znaki), a właściwe założenie konta i tak jest bramkowane
 * pełnym cross-matchem (numer umowy + PESEL/dokument).
 */
function _vr_detect_contract_no_account(string $email, string $surname): ?array {
    $email   = trim(mb_strtolower($email));
    $surname = trim($surname);
    if ($email === '' && mb_strlen($surname) < 3) return null;

    $types = [
        ['umowy_wolontariat','email','Wolontariusz'],
        ['umowy_zlecenie',   'email','Zleceniobiorca'],
        ['umowy_praca',      'email_login','Pracownik'],
        ['umowy_dzielo',     'email','Wykonawca dzieła'],
    ];
    foreach ($types as [$tbl,$ecol,$lbl]) {
        $conds = []; $params = [];
        if ($email !== '')          { $conds[] = "LOWER({$ecol}) = ?"; $params[] = $email; }
        if (mb_strlen($surname)>=3) { $conds[] = "imie_nazwisko LIKE ?"; $params[] = '%' . $surname . '%'; }
        if (!$conds) continue;
        try {
            $c = db_one("SELECT numer_umowy, imie_nazwisko, {$ecol} AS c_email
                         FROM {$tbl} WHERE (" . implode(' OR ', $conds) . ")
                         ORDER BY id DESC LIMIT 1", $params);
        } catch (\Throwable $e) { $c = null; }
        if (!$c) continue;

        $c_email = trim((string)($c['c_email'] ?? ''));
        // Czy istnieje AKTYWNE konto panelowe dla tej umowy?
        $acct = $c_email !== ''
            ? db_one("SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND is_active = 1", [$c_email])
            : null;
        if ($acct) continue; // konto już jest — nic nie proponujemy

        return [
            'email'      => $c_email,
            'name'       => trim((string)($c['imie_nazwisko'] ?? '')),
            'type'       => $tbl,
            'type_label' => $lbl,
            'has_numer'  => trim((string)($c['numer_umowy'] ?? '')) !== '',
        ];
    }
    return null;
}

/**
 * Sprawdza, czy w rejestrze istnieje umowa o podanym numerze powiązana z danym
 * adresem e-mail — niezależnie od tego, czy istnieje konto panelowe.
 * Zwraca etykietę typu umowy (np. 'Wolontariusz') albo null gdy nie znaleziono.
 * Ujawnia wyłącznie fakt istnienia umowy — bez danych osobowych.
 */
function _vr_check_contract_exists(string $email, string $numer): ?string {
    $email = trim(mb_strtolower($email));
    $numer = trim($numer);
    if ($email === '' || $numer === '') return null;
    $types = [
        ['umowy_wolontariat', 'email',       'Wolontariusz'],
        ['umowy_zlecenie',    'email',       'Zleceniobiorca'],
        ['umowy_praca',       'email_login', 'Pracownik'],
        ['umowy_dzielo',      'email',       'Wykonawca dzieła'],
    ];
    foreach ($types as [$tbl, $ecol, $lbl]) {
        try {
            $c = db_one("SELECT id FROM {$tbl} WHERE LOWER({$ecol}) = ? AND numer_umowy = ? LIMIT 1",
                        [$email, $numer]);
        } catch (\Throwable $e) { $c = null; }
        if ($c) return $lbl;
    }
    return null;
}

// ── Odczyt stanu z sesji ──────────────────────────────────────────────────────
$propose      = null;   // propozycja założenia konta (umowa jest, konta brak)
$detect_done  = false;  // czy uruchomiono detekcję (do komunikatu „nie znaleziono")
$cc_done      = false;  // czy uruchomiono sprawdzenie numeru umowy
$cc_result    = null;   // etykieta typu umowy (jeśli znaleziono) lub null
$cc_email     = '';
$cc_numer     = '';
$reset_step   = (int) ($_SESSION['vr_step']       ?? 1);
$reset_user_id  = (int) ($_SESSION['vr_user_id']    ?? 0);
$sms_fails      = (int) ($_SESSION['vr_sms_fails']  ?? 0);
$vr_new_account = !empty($_SESSION['vr_new_account']); // konto właśnie założone (brak wcześniejszego konta)

$error   = '';
$success = '';

// ── Obsługa POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    // Powrót do kroku 1 (reset stanu) — obsługiwany przed wszystkimi innymi akcjami
    if ($action === 'restart') {
        unset(
            $_SESSION['vr_step'],
            $_SESSION['vr_user_id'],
            $_SESSION['vr_sms_fails'],
            $_SESSION['vr_new_account']
        );
        header('Location: ' . APP_URL . '/user/verify_reset.php');
        exit;
    }

    // ────────────────────────────────────────────────────────────────────────
    // KROK 1: Cross-match
    // ────────────────────────────────────────────────────────────────────────
    if ($action === 'crossmatch' && $reset_step === 1) {
        if (!_vr_rate_check()) {
            $error = VR_ERR_RATE;
        } else {
            _vr_rate_record();

            $email        = trim($_POST['email']         ?? '');
            $numer_umowy  = trim($_POST['numer_umowy']   ?? '');
            $pesel_or_doc = trim($_POST['pesel_or_doc']  ?? '');
            $no_numer     = !empty($_POST['no_numer_umowy']);   // ścieżka "bez numeru"
            $data_ur      = trim($_POST['data_urodzenia'] ?? '');

            $found_user = null;
            $pdo        = db();

            // ── Ścieżka BEZ numeru umowy (umowy przed 2026-06-01) ────────────────
            $recovery_input = trim($_POST['recovery_code'] ?? '');
            if ($no_numer && $email && $pesel_or_doc && preg_match('/^\d{8}$/', $recovery_input)) {
                // Szukamy umowy po email + PESEL/dokument, weryfikujemy hash kodu
                $candidates = [];

                // Wolontariat
                $stmt = $pdo->prepare(
                    "SELECT u.id, u.email, u.phone_number, u.twofa_phone,
                            u.is_minor, u.guardian_phone, u.guardian_email,
                            c.recovery_code_hash
                     FROM umowy_wolontariat c
                     JOIN users u ON u.email = c.email
                     WHERE c.email = ?
                       AND (c.numer_umowy IS NULL OR c.numer_umowy = '')
                       AND (c.data_zawarcia IS NULL OR c.data_zawarcia < '2026-06-01')
                       AND c.recovery_code_hash IS NOT NULL
                       AND (SUBSTR(c.pesel,-5) = ? OR c.id_document_number = ?)
                     LIMIT 5"
                );
                $stmt->execute([$email, $pesel_or_doc, $pesel_or_doc]);
                $candidates = array_merge($candidates, $stmt->fetchAll(\PDO::FETCH_ASSOC));

                // Pozostałe typy (jeśli mają kolumnę recovery_code_hash)
                foreach (['umowy_zlecenie','umowy_dzielo','umowy_praca'] as $_tbl) {
                    try {
                        $stmt = $pdo->prepare(
                            "SELECT u.id, u.email, u.phone_number, u.twofa_phone,
                                    u.is_minor, u.guardian_phone, u.guardian_email,
                                    c.recovery_code_hash
                             FROM {$_tbl} c
                             JOIN users u ON u.email = c.email
                             WHERE c.email = ?
                               AND (c.numer_umowy IS NULL OR c.numer_umowy = '')
                               AND (c.data_zawarcia IS NULL OR c.data_zawarcia < '2026-06-01')
                               AND c.recovery_code_hash IS NOT NULL
                               AND SUBSTR(c.pesel,-5) = ?
                             LIMIT 5"
                        );
                        $stmt->execute([$email, $pesel_or_doc]);
                        $candidates = array_merge($candidates, $stmt->fetchAll(\PDO::FETCH_ASSOC));
                    } catch (\Throwable $e) {}
                }

                // Sprawdź hash dla każdego kandydata
                foreach ($candidates as $_c) {
                    if (!empty($_c['recovery_code_hash']) &&
                        password_verify($recovery_input, $_c['recovery_code_hash'])) {
                        $found_user = $_c;
                        break;
                    }
                }
            }

            // ── Standardowa ścieżka Z numerem umowy ──────────────────────────────
            if (!$found_user && !$no_numer && $numer_umowy) {
                // --- Sprawdzenie w tabeli wolontariat (z auto-zakładaniem konta, gdy go brak) ---
                $stmt = $pdo->prepare(
                    "SELECT * FROM umowy_wolontariat
                     WHERE email = ?
                       AND numer_umowy = ?
                       AND (
                           SUBSTR(pesel, -5) = ?
                           OR id_document_number = ?
                       )
                     LIMIT 1"
                );
                $stmt->execute([$email, $numer_umowy, $pesel_or_doc, $pesel_or_doc]);
                $contract_row = $stmt->fetch(\PDO::FETCH_ASSOC);

                if ($contract_row) {
                    $existing_user = db_one(
                        "SELECT id, email, phone_number, twofa_phone, is_minor, guardian_phone, guardian_email
                         FROM users WHERE email = ?",
                        [$email]
                    );
                    if ($existing_user) {
                        $found_user = $existing_user;
                    } else {
                        // Umowa istnieje, ale konto jeszcze nie — zakładamy je teraz.
                        $new_uid = _vr_provision_wolontariat_account($contract_row);
                        if ($new_uid) {
                            $found_user = [
                                'id' => $new_uid, 'email' => $email,
                                // Brak jeszcze danych na koncie — telefon do SMS bierzemy wprost z umowy.
                                'phone_number' => trim((string)($contract_row['telefon'] ?? '')),
                                'twofa_phone' => '', 'is_minor' => 0,
                                'guardian_phone' => '', 'guardian_email' => '',
                            ];
                            $_SESSION['vr_new_account'] = true;
                        }
                    }
                }

                foreach (['umowy_zlecenie','umowy_dzielo','umowy_praca'] as $_tbl) {
                    if ($found_user) break;
                    try {
                        $stmt = $pdo->prepare(
                            "SELECT u.id, u.email, u.phone_number, u.twofa_phone,
                                    u.is_minor, u.guardian_phone, u.guardian_email
                             FROM {$_tbl} c
                             JOIN users u ON u.email = c.email
                             WHERE c.email = ?
                               AND c.numer_umowy = ?
                               AND SUBSTR(c.pesel, -5) = ?
                             LIMIT 1"
                        );
                        $stmt->execute([$email, $numer_umowy, $pesel_or_doc]);
                        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                        if ($row) $found_user = $row;
                    } catch (\Throwable $e) {}
                }
            }

            if (!$found_user) {
                $error = VR_ERR_CROSSMATCH;
            } else {
                $uid  = (int) $found_user['id'];
                $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $exp  = date('Y-m-d H:i:s', time() + 10 * 60);

                // Zapisz kod do bazy
                db()->prepare(
                    "UPDATE users SET sms_fallback_code = ?, sms_fallback_expires_at = ? WHERE id = ?"
                )->execute([$code, $exp, $uid]);

                // Ustal docelowy numer telefonu
                $is_minor       = !empty($found_user['is_minor']);
                $guardian_phone = trim($found_user['guardian_phone'] ?? '');
                $primary_phone  = trim($found_user['phone_number']   ?? '');
                $twofa_phone    = trim($found_user['twofa_phone']    ?? '');

                $target_phone = '';
                if ($is_minor && $guardian_phone !== '') {
                    $target_phone = $guardian_phone;
                } elseif ($primary_phone !== '') {
                    $target_phone = $primary_phone;
                } elseif ($twofa_phone !== '') {
                    $target_phone = $twofa_phone;
                }

                // Wyślij SMS jeśli numer jest dostępny
                if ($target_phone !== '') {
                    try {
                        $org_name = defined('ORG_NAME') ? ORG_NAME : '';
                        sms_send($target_phone, "Kod odzyskiwania dostępu: {$code}. Ważny 10 minut. [{$org_name}]");
                    } catch (\Throwable $e) {
                        // Milcząco ignorujemy — nie ujawniamy błędu SMS
                    }
                }

                // Log
                log_system_action(
                    $uid,
                    'password_reset_sms_sent',
                    'Wysłano kod odzyskiwania (cross-match) na IP: ' . ($_SERVER['REMOTE_ADDR'] ?? '')
                );

                // Zapisz stan w sesji i przejdź do kroku 2
                $_SESSION['vr_step']      = 2;
                $_SESSION['vr_user_id']   = $uid;
                $_SESSION['vr_sms_fails'] = 0;

                $success = 'Jeśli podane dane są poprawne, kod weryfikacyjny zostanie wysłany SMS-em na numer przypisany do Twojego konta.';
                $reset_step = 2;
            }
        }
    }

    // ── Wykrycie umowy bez aktywnego konta panelowego + propozycja założenia ──
    elseif ($action === 'detect_account' && $reset_step === 1) {
        if (!_vr_rate_check()) {
            $error = VR_ERR_RATE;
        } else {
            _vr_rate_record();
            $detect_done = true;
            $propose = _vr_detect_contract_no_account($_POST['detect_email'] ?? '', $_POST['detect_surname'] ?? '');
        }
    }

    // ── Sprawdzenie istnienia umowy po e-mail + numer ─────────────────────────
    elseif ($action === 'check_contract' && $reset_step === 1) {
        if (!_vr_rate_check()) {
            $error = VR_ERR_RATE;
        } else {
            _vr_rate_record();
            $cc_done  = true;
            $cc_email = trim($_POST['cc_email'] ?? '');
            $cc_numer = trim($_POST['cc_numer'] ?? '');
            $cc_result = _vr_check_contract_exists($cc_email, $cc_numer);
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // KROK 2: Weryfikacja kodu SMS
    // ────────────────────────────────────────────────────────────────────────
    elseif ($action === 'verify_sms' && $reset_step === 2 && $reset_user_id > 0) {
        $input_code = trim($_POST['sms_code'] ?? '');

        $user_row = db()->prepare(
            "SELECT sms_fallback_code, sms_fallback_expires_at FROM users WHERE id = ?"
        );
        $user_row->execute([$reset_user_id]);
        $u = $user_row->fetch(\PDO::FETCH_ASSOC);

        $stored  = (string) ($u['sms_fallback_code']         ?? '');
        $expires = (string) ($u['sms_fallback_expires_at']   ?? '');

        $code_ok = $stored !== ''
            && $expires > date('Y-m-d H:i:s')
            && hash_equals($stored, $input_code);

        if ($code_ok) {
            // Wyczyść kod
            db()->prepare(
                "UPDATE users SET sms_fallback_code = NULL, sms_fallback_expires_at = NULL WHERE id = ?"
            )->execute([$reset_user_id]);

            $_SESSION['vr_step']     = 3;
            $_SESSION['vr_sms_fails'] = 0;
            $reset_step = 3;
        } else {
            $sms_fails++;
            $_SESSION['vr_sms_fails'] = $sms_fails;

            if ($sms_fails >= 3) {
                // Po 3 błędnych próbach resetujemy i wysyłamy z powrotem do kroku 1
                unset(
                    $_SESSION['vr_step'],
                    $_SESSION['vr_user_id'],
                    $_SESSION['vr_sms_fails'],
                    $_SESSION['vr_new_account']
                );
                $reset_step    = 1;
                $reset_user_id = 0;
                $error = 'Zbyt wiele błędnych kodów. Zacznij proces od początku.';
            } else {
                $error = VR_ERR_SMS;
            }
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // KROK 3: Nowe hasło
    // ────────────────────────────────────────────────────────────────────────
    elseif ($action === 'set_password' && $reset_step === 3 && $reset_user_id > 0) {
        $pass_new     = $_POST['password_new']     ?? '';
        $pass_confirm = $_POST['password_confirm'] ?? '';

        if ($pass_new !== $pass_confirm) {
            $error = 'Hasła nie są identyczne.';
        } else {
            $validation = PasswordValidator::validate($pass_new);
            if (!$validation['ok']) {
                $error = implode(' ', $validation['errors']);
            } else {
                $hash = password_hash($pass_new, PASSWORD_BCRYPT);
                db()->prepare("UPDATE users SET password = ?, allow_local_fallback = 1 WHERE id = ?")
                     ->execute([$hash, $reset_user_id]);

                // ── Synchronizacja z Microsoft 365 / Entra ID ────────────────
                // Reset dotyczy zarówno panelu SZO, jak i konta M365. Awaria po
                // stronie M365 NIE blokuje resetu lokalnego — informujemy usera.
                $m365_reset_note = '';
                try {
                    $ru = db_one("SELECT email, m365_login, microsoft_id FROM users WHERE id = ?", [$reset_user_id]);
                    $ms_id = trim((string)($ru['microsoft_id'] ?? ''));
                    $m365_ct = null;
                    foreach ([
                        ['m365_user_id = ?', $ms_id],
                        ['m365_login = ?',   trim((string)($ru['m365_login'] ?? ''))],
                        ['email = ?',        trim((string)($ru['email'] ?? ''))],
                    ] as [$cond, $val]) {
                        if ($val === '' ) continue;
                        $m365_ct = db_one(
                            "SELECT m365_user_id, m365_login FROM umowy_wolontariat
                             WHERE {$cond} AND m365_user_id != '' AND m365_konto = 1
                             ORDER BY id DESC LIMIT 1", [$val]);
                        if ($m365_ct) break;
                    }
                    if (!$m365_ct && $ms_id !== '') $m365_ct = ['m365_user_id' => $ms_id, 'm365_login' => ($ru['m365_login'] ?? '')];

                    if ($m365_ct && !empty($m365_ct['m365_user_id'])) {
                        require_once dirname(__DIR__) . '/includes/m365.php';
                        if (m365_setting('m365_enabled') === '1'
                            && m365_setting('m365_tenant_id') && m365_setting('m365_graph_client_id') && m365_setting('m365_graph_client_secret')) {
                            $m365 = new M365Graph([
                                'tenant_id'     => m365_setting('m365_tenant_id'),
                                'client_id'     => m365_setting('m365_graph_client_id'),
                                'client_secret' => m365_setting('m365_graph_client_secret'),
                            ]);
                            $m365->set_password($m365_ct['m365_user_id'], $pass_new, false);
                            log_system_action($reset_user_id, 'm365_password_reset',
                                'Synchronizacja hasła M365 przy resecie: ' . ($m365_ct['m365_login'] ?? ''));
                            $m365_reset_note = ' Hasło zsynchronizowano także z Microsoft 365.';
                        }
                    }
                } catch (\Throwable $e) {
                    log_system_action($reset_user_id, 'm365_password_reset_failed',
                        'Nie udało się zsynchronizować hasła M365 przy resecie: ' . $e->getMessage());
                    $m365_reset_note = ' UWAGA: hasło do panelu zmieniono, ale synchronizacja z Microsoft 365 nie powiodła się — skontaktuj się z administratorem, jeśli logowanie Microsoft nie zadziała.';
                }

                $is_new_account = !empty($_SESSION['vr_new_account']);
                log_system_action(
                    $reset_user_id,
                    $is_new_account ? 'self_register_password_set' : 'password_reset',
                    $is_new_account
                        ? 'Ustawiono hasło do nowo założonego konta (samodzielna rejestracja)'
                        : 'Użytkownik zresetował hasło metodą cross-match'
                );

                // Wyczyść stan sesji resetu
                unset(
                    $_SESSION['vr_step'],
                    $_SESSION['vr_user_id'],
                    $_SESSION['vr_sms_fails'],
                    $_SESSION['vr_new_account']
                );

                flash_set('success', ($is_new_account
                    ? 'Konto zostało założone, a hasło ustawione. Zaloguj się.'
                    : 'Hasło zostało zmienione. Zaloguj się.') . $m365_reset_note);
                header('Location: ' . APP_URL . '/auth/login.php');
                exit;
            }
        }
    }
}

// ── Pomocnicze zmienne dla szablonu ──────────────────────────────────────────
$org_name = defined('ORG_NAME') ? ORG_NAME : '';

// Etykiety kroków
$step_labels = [
    1 => 'Weryfikacja danych',
    2 => 'Kod SMS',
    3 => 'Nowe hasło',
];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Odzyskiwanie dostępu — <?= h($org_name) ?></title>
  <link rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
        crossorigin="anonymous">
  <link rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <style>
    :root{--tz:#1E6DFF;--tz-strong:#1656d6;--tz-50:#eef4ff;--tz-line:#E5E9F0;}
    body {
      background:
        radial-gradient(1200px 500px at 50% -10%, #e7f0ff 0%, rgba(231,240,255,0) 60%),
        #F4F6F9;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
      color:#111827;
    }
    .reset-wrapper {
      max-width: 540px;
      width: 100%;
    }
    .tz-brandbar{display:flex;align-items:center;justify-content:center;gap:.55rem;margin-bottom:1.25rem}
    .tz-brandbar .mark{width:34px;height:34px;border-radius:10px;background:var(--tz);color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.05rem}
    .tz-brandbar .txt{font-weight:700;letter-spacing:.02em;color:#1146ad}
    .tz-brandbar .txt small{display:block;font-weight:500;font-size:.68rem;letter-spacing:.06em;color:#6B7280;text-transform:uppercase}
    .reset-wrapper .card{border:1px solid var(--tz-line);border-radius:16px;box-shadow:0 12px 40px -12px rgba(30,109,255,.25)}
    .reset-wrapper .btn-primary{--bs-btn-bg:var(--tz-strong);--bs-btn-border-color:var(--tz-strong);--bs-btn-hover-bg:#0f3c9c;--bs-btn-hover-border-color:#0f3c9c;--bs-btn-active-bg:#0f3c9c}
    .reset-wrapper .btn-outline-primary{--bs-btn-color:var(--tz-strong);--bs-btn-border-color:var(--tz-line);--bs-btn-hover-bg:var(--tz-50);--bs-btn-hover-color:var(--tz-strong);--bs-btn-hover-border-color:var(--tz)}
    .reset-wrapper .text-primary{color:var(--tz-strong)!important}
    .reset-wrapper .form-control:focus{border-color:var(--tz);box-shadow:0 0 0 .2rem rgba(30,109,255,.18)}
    .reset-wrapper .card.border-primary{border-color:var(--tz)!important}
    .reset-wrapper a{color:var(--tz-strong)}
    /* Pasek postępu kroków */
    .step-bar {
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 2rem;
    }
    .step-item {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 4px;
    }
    .step-circle {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: .9rem;
      border: 2px solid;
    }
    .step-circle.done {
      background: #1E6DFF;
      border-color: #1E6DFF;
      color: #fff;
    }
    .step-circle.active {
      background: #fff;
      border-color: #1E6DFF;
      color: #1E6DFF;
    }
    .step-circle.pending {
      background: #fff;
      border-color: #dee2e6;
      color: #adb5bd;
    }
    .step-label {
      font-size: .72rem;
      text-align: center;
      color: #6c757d;
      max-width: 80px;
    }
    .step-label.active { color: #1E6DFF; font-weight: 600; }
    .step-connector {
      flex: 1;
      height: 2px;
      background: #dee2e6;
      margin: 0 8px;
      margin-bottom: 20px;
    }
    .step-connector.done { background: #1E6DFF; }
  </style>
</head>
<body>
<div class="reset-wrapper">

  <!-- Pasek systemu Tożsamości -->
  <div class="tz-brandbar">
    <span class="mark" aria-hidden="true"><i class="bi bi-person-vcard-fill"></i></span>
    <span class="txt">System Tożsamości<small><?= h($org_name) ?></small></span>
  </div>

  <!-- Nagłówek -->
  <div class="text-center mb-4">
    <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3"
         style="width:64px;height:64px;background:#eef4ff">
      <i class="bi bi-shield-lock-fill fs-2" style="color:#1E6DFF"></i>
    </div>
    <h1 class="h5 fw-bold mb-1">Odzyskiwanie dostępu</h1>
    <p class="text-muted small mb-0">Zresetuj hasło do panelu SZO i Microsoft 365 · autoryzacja kodem SMS</p>
  </div>

  <!-- Pasek kroków -->
  <div class="step-bar">
    <?php for ($i = 1; $i <= 3; $i++): ?>
      <?php if ($i > 1): ?>
        <div class="step-connector<?= $reset_step > $i - 1 ? ' done' : '' ?>"></div>
      <?php endif; ?>
      <div class="step-item">
        <div class="step-circle <?=
          $reset_step > $i  ? 'done'    :
          ($reset_step == $i ? 'active' : 'pending')
        ?>">
          <?php if ($reset_step > $i): ?>
            <i class="bi bi-check-lg"></i>
          <?php else: ?>
            <?= $i ?>
          <?php endif; ?>
        </div>
        <span class="step-label<?= $reset_step == $i ? ' active' : '' ?>">
          <?= h($step_labels[$i]) ?>
        </span>
      </div>
    <?php endfor; ?>
  </div>

  <!-- Komunikaty -->
  <?php if ($error !== ''): ?>
  <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
    <i class="bi bi-exclamation-triangle-fill fs-5 flex-shrink-0 mt-1"></i>
    <div><?= h($error) ?></div>
  </div>
  <?php endif; ?>

  <?php if ($success !== ''): ?>
  <div class="alert alert-info d-flex align-items-start gap-2" role="alert">
    <i class="bi bi-info-circle-fill fs-5 flex-shrink-0 mt-1"></i>
    <div><?= h($success) ?></div>
  </div>
  <?php endif; ?>

  <!-- ─────────────────────────────────────────────────────────────────────── -->
  <!-- KROK 1: Formularz cross-match                                          -->
  <!-- ─────────────────────────────────────────────────────────────────────── -->
  <?php if ($reset_step === 1): ?>

  <?php if ($propose): ?>
  <!-- Wykryto umowę bez aktywnego konta → propozycja -->
  <div class="card shadow-sm border-primary mb-3" style="border-width:2px">
    <div class="card-body p-4">
      <div class="d-flex align-items-start gap-2">
        <i class="bi bi-person-plus-fill text-primary fs-4 flex-shrink-0" aria-hidden="true"></i>
        <div>
          <h6 class="fw-bold mb-1">Znaleźliśmy Twoją umowę — utwórz konto panelowe</h6>
          <p class="text-muted small mb-0">
            Dla danych <strong><?= h($propose['name'] ?: $propose['email']) ?></strong>
            (<?= h($propose['type_label']) ?>) istnieje umowa bez aktywnego konta w panelu.
            Potwierdź tożsamość danymi z umowy w formularzu poniżej<?= $propose['email'] ? ' (e-mail wpisaliśmy za Ciebie)' : '' ?>.
          </p>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="card shadow-sm">
    <div class="card-body p-4">
      <h6 class="fw-semibold mb-1">Weryfikacja tożsamości</h6>
      <p class="text-muted small mb-3">
        Podaj dane z umowy, aby potwierdzić swoją tożsamość — zresetujesz w ten sposób hasło,
        a jeśli to Twoja pierwsza wizyta i nie masz jeszcze konta w systemie, formularz założy je automatycznie.
      </p>
      <form method="post" novalidate id="crossmatch-form">
        <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_action" value="crossmatch">

        <div class="mb-3">
          <label for="email" class="form-label fw-semibold small">Adres e-mail</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-envelope"></i></span>
            <input type="email" class="form-control" id="email" name="email"
                   placeholder="Twój adres e-mail z umowy"
                   value="<?= h($_POST['email'] ?? ($propose['email'] ?? '')) ?>"
                   required autofocus>
          </div>
        </div>

        <!-- Przełącznik: mam / nie mam numeru umowy -->
        <div class="mb-3">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="no_numer_umowy"
                   name="no_numer_umowy" value="1"
                   <?= !empty($_POST['no_numer_umowy']) ? 'checked' : '' ?>>
            <label class="form-check-label small" for="no_numer_umowy">
              Nie znam numeru umowy
              <span class="text-muted">(umowy zawarte przed 1 czerwca 2026)</span>
            </label>
          </div>
        </div>

        <!-- Pole numeru umowy (ukrywane gdy brak) -->
        <div class="mb-3" id="row-numer-umowy" <?= !empty($_POST['no_numer_umowy']) ? 'style="display:none"' : '' ?>>
          <label for="numer_umowy" class="form-label fw-semibold small">Numer umowy</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-file-earmark-text"></i></span>
            <input type="text" class="form-control" id="numer_umowy" name="numer_umowy"
                   placeholder="np. RU/0001/2024/AB"
                   value="<?= h($_POST['numer_umowy'] ?? '') ?>">
          </div>
        </div>

        <!-- Kod odzyskiwania (widoczny tylko gdy brak numeru) -->
        <div class="mb-3" id="row-data-ur" <?= empty($_POST['no_numer_umowy']) ? 'style="display:none"' : '' ?>>
          <label for="recovery_code" class="form-label fw-semibold small">
            <i class="bi bi-shield-lock me-1 text-primary"></i>Kod odzyskiwania (8 cyfr)
          </label>
          <div class="input-group" style="max-width:260px">
            <span class="input-group-text"><i class="bi bi-key"></i></span>
            <input type="text" class="form-control font-monospace text-center fw-bold"
                   id="recovery_code" name="recovery_code"
                   placeholder="00000000" maxlength="8" inputmode="numeric" pattern="\d{8}"
                   style="letter-spacing:.25em;font-size:1.1rem"
                   value="<?= h($_POST['recovery_code'] ?? '') ?>">
          </div>
          <div class="form-text">
            Kod wysłany SMS-em lub e-mailem przy tworzeniu umowy.
          </div>
        </div>

        <div class="mb-4">
          <label for="pesel_or_doc" class="form-label fw-semibold small">
            PESEL lub numer dokumentu tożsamości
          </label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-person-vcard"></i></span>
            <input type="text" class="form-control" id="pesel_or_doc" name="pesel_or_doc"
                   placeholder="Ostatnie 5 cyfr PESEL lub pełny numer dokumentu"
                   value="<?= h($_POST['pesel_or_doc'] ?? '') ?>"
                   required>
          </div>
          <div class="form-text">
            Ostatnie 5 cyfr PESEL (np. <code>12345</code>) lub pełny numer dokumentu tożsamości.
          </div>
        </div>

        <div class="d-grid">
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-arrow-right-circle me-1"></i>Weryfikuj dane
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Zakładki: Nie masz konta? / Sprawdź numer umowy -->
  <div class="card shadow-sm mt-3">
    <div class="card-header p-0" style="background:transparent">
      <ul class="nav nav-tabs border-0 px-2 pt-2" id="helpTabs" role="tablist">
        <li class="nav-item" role="presentation">
          <button class="nav-link <?= !$cc_done ? 'active' : '' ?> small fw-semibold px-3"
                  id="tab-detect-btn" data-bs-toggle="tab" data-bs-target="#tab-detect"
                  type="button" role="tab" aria-controls="tab-detect"
                  aria-selected="<?= !$cc_done ? 'true' : 'false' ?>">
            <i class="bi bi-person-plus me-1" aria-hidden="true"></i>Nie masz konta?
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link <?= $cc_done ? 'active' : '' ?> small fw-semibold px-3"
                  id="tab-check-btn" data-bs-toggle="tab" data-bs-target="#tab-check"
                  type="button" role="tab" aria-controls="tab-check"
                  aria-selected="<?= $cc_done ? 'true' : 'false' ?>">
            <i class="bi bi-file-earmark-search me-1" aria-hidden="true"></i>Sprawdź numer umowy
          </button>
        </li>
      </ul>
    </div>
    <div class="card-body p-4">
      <div class="tab-content">

        <!-- Zakładka: Nie masz konta? -->
        <div class="tab-pane fade <?= !$cc_done ? 'show active' : '' ?>"
             id="tab-detect" role="tabpanel" aria-labelledby="tab-detect-btn">
          <p class="text-muted small mb-3">
            Podaj e-mail lub nazwisko — sprawdzimy, czy istnieje umowa bez konta panelowego
            i zaproponujemy jego założenie.
          </p>
          <?php if ($propose): ?>
          <div class="alert alert-primary d-flex align-items-start gap-2 mb-3" role="status">
            <i class="bi bi-person-plus-fill fs-5 flex-shrink-0" aria-hidden="true"></i>
            <div class="small">Znaleziono umowę dla <strong><?= h($propose['name'] ?: $propose['email']) ?></strong>
              (<?= h($propose['type_label']) ?>) bez aktywnego konta. Potwierdź tożsamość
              w <strong>formularzu powyżej</strong>, aby je założyć.</div>
          </div>
          <?php elseif ($detect_done): ?>
          <div class="alert alert-secondary d-flex align-items-start gap-2 mb-3" role="status">
            <i class="bi bi-info-circle fs-5 flex-shrink-0" aria-hidden="true"></i>
            <div class="small">Nie znaleziono umowy bez aktywnego konta. Jeśli masz konto — zresetuj hasło powyżej. W razie wątpliwości skontaktuj się z administratorem.</div>
          </div>
          <?php endif; ?>
          <form method="post" novalidate>
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="_action" value="detect_account">
            <div class="row g-2">
              <div class="col-sm-7">
                <label for="detect_email" class="form-label small fw-semibold">Adres e-mail</label>
                <input type="email" class="form-control form-control-sm" id="detect_email" name="detect_email"
                       placeholder="e-mail z umowy" value="<?= h($_POST['detect_email'] ?? ($propose['email'] ?? '')) ?>">
              </div>
              <div class="col-sm-5">
                <label for="detect_surname" class="form-label small fw-semibold">lub nazwisko</label>
                <input type="text" class="form-control form-control-sm" id="detect_surname" name="detect_surname"
                       placeholder="nazwisko" value="<?= h($_POST['detect_surname'] ?? '') ?>">
              </div>
            </div>
            <button type="submit" class="btn btn-outline-primary btn-sm mt-3">
              <i class="bi bi-search me-1" aria-hidden="true"></i>Sprawdź
            </button>
          </form>
        </div>

        <!-- Zakładka: Sprawdź numer umowy -->
        <div class="tab-pane fade <?= $cc_done ? 'show active' : '' ?>"
             id="tab-check" role="tabpanel" aria-labelledby="tab-check-btn">
          <p class="text-muted small mb-3">
            Wpisz e-mail i numer umowy, aby potwierdzić jej obecność w rejestrze. Nie wymaga PESEL-u.
          </p>
          <?php if ($cc_done): ?>
            <?php if ($cc_result !== null): ?>
            <div class="alert alert-success d-flex align-items-start gap-2 mb-3" role="status">
              <i class="bi bi-check-circle-fill fs-5 flex-shrink-0" aria-hidden="true"></i>
              <div class="small">Umowa <strong><?= h($cc_numer) ?></strong> dla adresu <strong><?= h($cc_email) ?></strong>
                jest zarejestrowana (typ: <?= h($cc_result) ?>).
                Aby uzyskać dostęp — wypełnij <strong>formularz weryfikacji powyżej</strong>.</div>
            </div>
            <?php else: ?>
            <div class="alert alert-secondary d-flex align-items-start gap-2 mb-3" role="status">
              <i class="bi bi-info-circle fs-5 flex-shrink-0" aria-hidden="true"></i>
              <div class="small">Nie znaleziono umowy o numerze <strong><?= h($cc_numer) ?></strong>
                powiązanej z adresem <strong><?= h($cc_email) ?></strong>.
                Sprawdź dane lub skontaktuj się z administratorem.</div>
            </div>
            <?php endif; ?>
          <?php endif; ?>
          <form method="post" novalidate>
            <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="_action" value="check_contract">
            <div class="row g-2">
              <div class="col-sm-7">
                <label for="cc_email" class="form-label small fw-semibold">Adres e-mail z umowy</label>
                <input type="email" class="form-control form-control-sm" id="cc_email" name="cc_email"
                       placeholder="e-mail" value="<?= h($cc_email) ?>" required>
              </div>
              <div class="col-sm-5">
                <label for="cc_numer" class="form-label small fw-semibold">Numer umowy</label>
                <input type="text" class="form-control form-control-sm" id="cc_numer" name="cc_numer"
                       placeholder="np. RU/0001/2024/AB" value="<?= h($cc_numer) ?>" required>
              </div>
            </div>
            <button type="submit" class="btn btn-outline-primary btn-sm mt-3">
              <i class="bi bi-search me-1" aria-hidden="true"></i>Sprawdź
            </button>
          </form>
        </div>

      </div>
    </div>
  </div>

  <script>
  (function() {
    var cb      = document.getElementById('no_numer_umowy');
    var rowNum  = document.getElementById('row-numer-umowy');
    var rowDur  = document.getElementById('row-data-ur');
    var inpNum  = document.getElementById('numer_umowy');
    var inpCode = document.getElementById('recovery_code');
    if (!cb) return;
    function toggle() {
      var noNum = cb.checked;
      rowNum.style.display = noNum ? 'none' : '';
      rowDur.style.display = noNum ? ''     : 'none';
      inpNum.required  = !noNum;
      if (inpCode) inpCode.required = noNum;
    }
    cb.addEventListener('change', toggle);
    toggle();
  })();
  </script>

  <!-- ─────────────────────────────────────────────────────────────────────── -->
  <!-- KROK 2: Kod SMS                                                        -->
  <!-- ─────────────────────────────────────────────────────────────────────── -->
  <?php elseif ($reset_step === 2): ?>
  <div class="card shadow-sm">
    <div class="card-body p-4">
      <h6 class="fw-semibold mb-1">Wprowadź kod SMS</h6>
      <p class="text-muted small mb-3">
        Jeśli dane były poprawne, na Twój numer telefonu wysłaliśmy 6-cyfrowy kod weryfikacyjny.
        Kod jest ważny przez <strong>10 minut</strong>.
      </p>
      <form method="post" novalidate>
        <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_action" value="verify_sms">

        <div class="mb-4">
          <label for="sms_code" class="form-label fw-semibold small">Kod SMS (6 cyfr)</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-chat-square-dots"></i></span>
            <input type="text" class="form-control form-control-lg text-center"
                   id="sms_code" name="sms_code"
                   maxlength="6" minlength="6"
                   inputmode="numeric" pattern="[0-9]{6}"
                   placeholder="000000"
                   autocomplete="one-time-code"
                   required autofocus
                   style="letter-spacing:.3em; font-size:1.3rem; font-weight:600">
          </div>
          <?php if ($sms_fails > 0): ?>
          <div class="form-text text-danger">
            Błędna próba <?= $sms_fails ?>/3. Po 3 błędach będziesz musiał zacząć od nowa.
          </div>
          <?php endif; ?>
        </div>

        <div class="d-grid mb-3">
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-circle me-1"></i>Potwierdź kod
          </button>
        </div>
      </form>

      <!-- Powrót do kroku 1 -->
      <div class="text-center">
        <form method="post" style="display:inline">
          <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_action" value="restart">
          <button type="submit" class="btn btn-link btn-sm text-muted p-0">
            <i class="bi bi-arrow-left me-1"></i>Wróć i popraw dane
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- ─────────────────────────────────────────────────────────────────────── -->
  <!-- KROK 3: Nowe hasło                                                     -->
  <!-- ─────────────────────────────────────────────────────────────────────── -->
  <?php elseif ($reset_step === 3): ?>
  <div class="card shadow-sm">
    <div class="card-body p-4">
      <h6 class="fw-semibold mb-1"><?= $vr_new_account ? 'Ustaw hasło do nowego konta' : 'Ustaw nowe hasło' ?></h6>
      <p class="text-muted small mb-3">
        <?php if ($vr_new_account): ?>
        Tożsamość potwierdzona — Twoje konto w systemie zostało właśnie założone. Ustaw hasło, aby się zalogować.
        <?php else: ?>
        Wybierz nowe hasło do swojego konta.
        <?php endif; ?>
        Hasło musi mieć co najmniej 8 znaków, zawierać wielką literę, małą literę i cyfrę.
      </p>
      <form method="post" novalidate>
        <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_action" value="set_password">

        <div class="mb-3">
          <label for="password_new" class="form-label fw-semibold small">Nowe hasło</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-lock"></i></span>
            <input type="password" class="form-control" id="password_new" name="password_new"
                   minlength="8" required autofocus
                   autocomplete="new-password">
            <button class="btn btn-outline-secondary" type="button" id="togglePwdNew"
                    aria-label="Pokaż/ukryj hasło">
              <i class="bi bi-eye" id="eyeNew"></i>
            </button>
          </div>
          <div class="form-text">Min. 8 znaków, wielka litera, mała litera, cyfra.</div>
        </div>

        <div class="mb-4">
          <label for="password_confirm" class="form-label fw-semibold small">Powtórz nowe hasło</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
            <input type="password" class="form-control" id="password_confirm" name="password_confirm"
                   minlength="8" required
                   autocomplete="new-password">
            <button class="btn btn-outline-secondary" type="button" id="togglePwdConfirm"
                    aria-label="Pokaż/ukryj powtórzone hasło">
              <i class="bi bi-eye" id="eyeConfirm"></i>
            </button>
          </div>
        </div>

        <div class="d-grid">
          <button type="submit" class="btn btn-success btn-lg">
            <i class="bi bi-check-circle me-1"></i>Ustaw hasło i zaloguj się
          </button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- Link powrotu do logowania -->
  <div class="text-center mt-3">
    <a href="<?= h(APP_URL . '/auth/login.php') ?>" class="text-muted small text-decoration-none">
      <i class="bi bi-arrow-left me-1"></i>Wróć do strony logowania
    </a>
  </div>

</div><!-- /.reset-wrapper -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmY+ASSbXX+/Y0DmfhVpJkJEYJA3"
        crossorigin="anonymous"></script>
<script>
  // Toggle widoczności hasła
  function togglePwd(btnId, inputId, eyeId) {
    var btn = document.getElementById(btnId);
    if (!btn) return;
    btn.addEventListener('click', function () {
      var inp = document.getElementById(inputId);
      var eye = document.getElementById(eyeId);
      if (!inp) return;
      var show = inp.type === 'password';
      inp.type = show ? 'text' : 'password';
      if (eye) {
        eye.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
      }
    });
  }
  togglePwd('togglePwdNew',     'password_new',     'eyeNew');
  togglePwd('togglePwdConfirm', 'password_confirm', 'eyeConfirm');

  // Automatyczne przejście do następnego pola po wpisaniu 6 cyfr kodu SMS
  var smsInput = document.getElementById('sms_code');
  if (smsInput) {
    smsInput.addEventListener('input', function () {
      if (this.value.replace(/\D/g, '').length === 6) {
        this.form.submit();
      }
    });
  }

</script>
</body>
</html>
