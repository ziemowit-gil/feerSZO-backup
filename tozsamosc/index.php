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

// ── Pełny rekord + identyfikatory ───────────────────────────────────────────
$db_user = db_one("SELECT * FROM users WHERE id=?", [$user['id']]);
$uid                = (int)$db_user['id'];
$has_local_password = !empty($db_user['password']);
$panel_login        = $user['email'] ?? '';          // GŁÓWNY identyfikator sieciowy
$m365_login         = $db_user['m365_login'] ?? '';  // login MS365 (może być inny)
$microsoft_id       = $db_user['microsoft_id'] ?? '';
$has_m365           = ($microsoft_id !== '' || $m365_login !== '');

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

// ── Stan telefonu / MFA ─────────────────────────────────────────────────────
$phone_saved    = $db_user['phone_number'] ?? '';
$phone_verified = !empty($db_user['phone_verified_at']);
$phone_pending  = $_SESSION['sec_phone_pending'] ?? '';
$mfa_method     = $db_user['twofa_method'] ?? '';
$mfa_active     = $mfa_method !== '';
$mfa_label      = $mfa_method === 'totp' ? 'Aplikacja Authenticator'
                : ($mfa_method === 'sms' ? 'Kod SMS' : 'Nieaktywne');

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

    if ($action === 'phone_send') {
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

<div class="alert d-flex align-items-start gap-2" style="background:#eef4ff;border:1px solid #dbe7ff;color:#1146ad">
  <i class="bi bi-diagram-3 fs-5 flex-shrink-0" aria-hidden="true"></i>
  <div class="small">
    To <strong>centralny katalog z Twoimi dostępami</strong> (Entra ID) — identyfikatory, hasło, telefon i uwierzytelnianie.
    Sprawy bieżącej współpracy (umowy, zadania, komunikaty) załatwiasz w <a href="<?= APP_URL ?>/portal.php">systemie SZO</a>.
  </div>
</div>

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

<script>
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
  // Na starcie: otwórz sekcję wskazaną w #hash (np. po zapisie hasła → #bezpieczenstwo)
  var initial=(location.hash||'').slice(1);
  if(initial && document.getElementById(initial) && document.getElementById(initial).classList.contains('tz-panel')){
    activate(initial,false);
  }
})();
</script>

<?php include __DIR__ . '/_foot.php';
