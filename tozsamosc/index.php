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

$_is_volunteer_only = is_viewer() && !db_one("SELECT id FROM users WHERE id=? AND k30_consultant=1", [$uid]);
if ($_is_volunteer_only) {
    include dirname(__DIR__) . '/panel/includes/header_panel.php';
} else {
    include dirname(__DIR__) . '/includes/header.php';
}

$initials = mb_strtoupper(mb_substr($user['name'] ?? 'U', 0, 1));
if (preg_match('/\s(\S)/u', $user['name'] ?? '', $m2)) $initials .= mb_strtoupper($m2[1]);
?>

<style>
.tz{--tz:#1E6DFF;--tz-strong:#1656d6;--tz-50:#eef4ff;--tz-line:#E5E9F0;--tz-muted:#6B7280;}
.tz .lbl-en{font-size:.72rem;color:var(--tz-muted);font-weight:500;display:block;margin-top:.1rem}
.tz-card{background:#fff;border:1px solid var(--tz-line);border-radius:14px;box-shadow:0 1px 3px rgba(16,24,40,.08);overflow:hidden;margin-bottom:1.25rem}
.tz-card__hd{padding:1rem 1.25rem;border-bottom:1px solid var(--tz-line);display:flex;align-items:center;gap:.65rem;font-weight:600}
.tz-card__hd i{color:var(--tz)}
.tz-card__bd{padding:1.25rem}
.tz-id{background:linear-gradient(90deg,var(--tz),var(--tz-strong));color:#fff;padding:1.1rem 1.25rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}
.tz-id .tz-ava{width:46px;height:46px;border-radius:12px;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1.1rem}
.tz-uid{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-weight:700;font-size:1.55rem;letter-spacing:.06em;line-height:1}
.tz-dl{display:grid;grid-template-columns:repeat(1,1fr)}
@media(min-width:576px){.tz-dl{grid-template-columns:repeat(2,1fr)}}
@media(min-width:992px){.tz-dl{grid-template-columns:repeat(3,1fr)}}
.tz-dl>div{padding:.8rem 1.1rem;border-top:1px solid var(--tz-line);position:relative}
@media(min-width:576px){.tz-dl>div:nth-child(2n)::before,.tz-dl>div:nth-child(n+2)::before{content:none}}
.tz-dl dt{font-size:.7rem;color:var(--tz-muted);margin:0;text-transform:uppercase;letter-spacing:.03em;font-weight:600}
.tz-dl dd{font-weight:600;margin:.2rem 0 0;font-size:.94rem;word-break:break-word;color:#0f172a}
.tz-dl dd .tz-copy{border:0;background:none;color:var(--tz-strong);padding:0 .25rem;cursor:pointer}
.tz-note{background:#F4F6F9;border-top:1px solid var(--tz-line);padding:.6rem 1rem;font-size:.8rem;color:var(--tz-muted);display:flex;gap:.4rem;align-items:flex-start}
.tz-btn{background:var(--tz-strong);color:#fff;border:none;border-radius:9px;padding:.6rem 1.3rem;font-weight:600;display:inline-flex;align-items:center;gap:.45rem;text-decoration:none}
.tz-btn:hover{background:#0f3c9c;color:#fff}
.tz-btn:focus-visible{outline:3px solid #FBBF24;outline-offset:2px}
.tz-btn--ghost{background:#fff;color:var(--tz-strong);border:1px solid var(--tz-line)}
.tz-btn--ghost:hover{background:var(--tz-50);color:var(--tz-strong)}
.tz-badge{font-size:.72rem;font-weight:600;padding:.2rem .6rem;border-radius:999px;display:inline-flex;align-items:center;gap:.3rem}
.tz-badge--ok{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0}
.tz-badge--warn{background:#fff7ed;color:#c2410c;border:1px solid #fed7aa}
.tz-badge--off{background:#f3f4f6;color:#6B7280;border:1px solid #e5e7eb}
.tz-otp{font-size:1.6rem;letter-spacing:.5rem;text-align:center;font-weight:700;font-family:ui-monospace,monospace}
.tz-req{list-style:none;padding:0;margin:.5rem 0 0;display:grid;grid-template-columns:1fr;gap:.35rem;font-size:.85rem}
@media(min-width:576px){.tz-req{grid-template-columns:1fr 1fr}}
.tz-req li{color:var(--tz-muted);display:flex;align-items:center;gap:.4rem}
.tz-req li.ok{color:#047857}
.tz-req li .dot{display:inline-block;width:1.1em;text-align:center;font-weight:700}
.tz-meter{height:7px;border-radius:999px;background:var(--tz-line);overflow:hidden;margin-top:.5rem}
.tz-meter>span{display:block;height:100%;width:0;background:#f87171;transition:width .3s,background-color .3s}
.tz-subnav{position:sticky;top:0;z-index:5;background:#F4F6F9;padding:.6rem 0;margin-bottom:1.1rem}
.tz-subnav .seg{display:inline-flex;gap:.2rem;padding:.25rem;background:#fff;border:1px solid var(--tz-line);border-radius:12px;flex-wrap:wrap;box-shadow:0 1px 2px rgba(16,24,40,.05)}
.tz-subnav a{font-size:.85rem;padding:.4rem .9rem;border-radius:9px;text-decoration:none;color:var(--tz-muted);display:inline-flex;align-items:center;gap:.4rem;font-weight:500}
.tz-subnav a:hover,.tz-subnav a:focus-visible{background:var(--tz-50);color:var(--tz-strong);outline:none}
.tz-subnav a.on{background:var(--tz);color:#fff}
.tz-subnav a.on i{color:#fff}
.tz-svc{display:flex;align-items:center;gap:.9rem;padding:.85rem 0;border-top:1px solid var(--tz-line)}
.tz-svc:first-child{border-top:0}
.tz-svc__ico{width:42px;height:42px;border-radius:11px;background:var(--tz-50);color:var(--tz-strong);display:flex;align-items:center;justify-content:center;font-size:1.25rem;flex-shrink:0}
.tz-tiles{display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));margin:.25rem 0 .75rem}
.tz-tile{display:block;text-align:left;background:#fff;border:1px solid var(--tz-line);border-radius:14px;padding:1.1rem;text-decoration:none;color:inherit;transition:transform .15s,border-color .15s,box-shadow .15s}
.tz-tile:hover,.tz-tile:focus-visible{transform:translateY(-2px);border-color:var(--tz);box-shadow:0 8px 24px -6px rgba(30,109,255,.28);color:inherit;outline:none}
.tz-tile__ico{width:42px;height:42px;border-radius:11px;background:var(--tz);color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.2rem;margin-bottom:.7rem}
</style>

<div class="tz">

<?php if ($_is_volunteer_only): ?>
<div class="pv-page-header d-flex gap-2 flex-wrap">
  <h1 class="pv-page-title"><i class="bi bi-person-vcard me-2" aria-hidden="true"></i>Tożsamość</h1>
  <p class="pv-page-sub">Zarządzanie tożsamością w Entra ID</p>
</div>
<?php else: ?>
<div class="d-flex align-items-center gap-3 mb-4">
  <div class="rounded-circle d-flex align-items-center justify-content-center" style="width:52px;height:52px;flex-shrink:0;background:#eef4ff">
    <i class="bi bi-person-vcard fs-4" style="color:#1E6DFF"></i>
  </div>
  <div>
    <h1 class="h4 mb-0">Tożsamość</h1>
    <div class="text-muted small">Zarządzanie tożsamością w Entra ID · Identity management</div>
  </div>
</div>
<?php endif; ?>

<div class="alert d-flex align-items-start gap-2" style="background:#eef4ff;border:1px solid #dbe7ff;color:#1146ad">
  <i class="bi bi-diagram-3 fs-5 flex-shrink-0" aria-hidden="true"></i>
  <div class="small">
    Ten moduł zarządza <strong>Twoją tożsamością w centralnym rejestrze użytkowników i kont (Entra ID)</strong> — identyfikatory, hasło, telefon i uwierzytelnianie.
    Sprawy bieżącej współpracy (umowy, zadania, komunikaty) załatwiasz w <a href="<?= APP_URL ?>/panel/index.php">Moim panelu</a>.
  </div>
</div>

<?= flash_html() ?>

<div aria-live="assertive">
<?php if ($errors): ?>
<div class="alert alert-danger" role="alert">
  <strong><i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>Popraw następujące błędy:</strong>
  <ul class="mb-0 mt-1"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>
</div>

<nav class="tz-subnav" aria-label="Sekcje tożsamości">
  <span class="seg">
    <a href="#podstawowe" class="on"><i class="bi bi-person-badge" aria-hidden="true"></i>Podstawowe</a>
    <a href="#uslugi"><i class="bi bi-grid-3x3-gap" aria-hidden="true"></i>Usługi</a>
    <a href="#bezpieczenstwo"><i class="bi bi-shield-lock" aria-hidden="true"></i>Bezpieczeństwo</a>
  </span>
</nav>

<!-- ═══════════ PODSTAWOWE ═══════════ -->
<section class="tz-card" id="podstawowe" aria-labelledby="pod-h" tabindex="-1">
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
<section class="tz-card" id="uslugi" aria-labelledby="usl-h" tabindex="-1">
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
  </div>
</section>

<!-- ═══════════ BEZPIECZEŃSTWO ═══════════ -->
<section class="tz-card" id="bezpieczenstwo" aria-labelledby="bez-h" tabindex="-1">
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
      <a class="tz-tile" href="<?= APP_URL ?>/panel/2fa_settings.php">
        <span class="tz-tile__ico"><i class="bi bi-phone" aria-hidden="true"></i></span>
        <span class="fw-semibold d-block">Aplikacja Authenticator</span>
        <span class="lbl-en">Authenticator app (TOTP)</span>
        <span class="d-block text-muted mt-1" style="font-size:.85rem">Kreator: instalacja → skan kodu QR → potwierdzenie.</span>
      </a>
      <?php if ($sms_available): ?>
      <a class="tz-tile" href="<?= APP_URL ?>/panel/2fa_settings.php">
        <span class="tz-tile__ico"><i class="bi bi-chat-dots" aria-hidden="true"></i></span>
        <span class="fw-semibold d-block">Kod SMS</span>
        <span class="lbl-en">Text message code</span>
        <span class="d-block text-muted mt-1" style="font-size:.85rem">Jednorazowy kod na zweryfikowany numer telefonu.</span>
      </a>
      <?php endif; ?>
    </div>
    <a href="<?= APP_URL ?>/panel/2fa_settings.php" class="tz-btn"><i class="bi bi-shield-plus" aria-hidden="true"></i> Otwórz kreator MFA</a>
  </div>
</section>

</div><!-- /.tz -->

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
  document.querySelectorAll('.tz-subnav a[href^="#"],a[href="#bezpieczenstwo"]').forEach(function(a){
    a.addEventListener('click',function(e){
      var t=document.querySelector(a.getAttribute('href'));
      if(t){e.preventDefault();t.scrollIntoView({behavior:'smooth',block:'start'});t.focus({preventScroll:true});}
    });
  });
  // Scrollspy — podświetl aktywną zakładkę segmentu wg widocznej sekcji
  var navLinks=[].slice.call(document.querySelectorAll('.tz-subnav .seg a'));
  var secs=navLinks.map(function(a){return document.querySelector(a.getAttribute('href'));}).filter(Boolean);
  if('IntersectionObserver' in window && secs.length){
    var obs=new IntersectionObserver(function(entries){
      entries.forEach(function(en){
        if(en.isIntersecting){
          navLinks.forEach(function(a){a.classList.toggle('on', a.getAttribute('href')==='#'+en.target.id);});
        }
      });
    },{rootMargin:'-45% 0px -50% 0px',threshold:0});
    secs.forEach(function(s){obs.observe(s);});
  }
})();
</script>

<?php
if ($_is_volunteer_only) {
    include dirname(__DIR__) . '/panel/includes/footer_panel.php';
} else {
    include dirname(__DIR__) . '/includes/footer.php';
}
