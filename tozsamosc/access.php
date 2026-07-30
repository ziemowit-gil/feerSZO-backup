<?php
/**
 * tozsamosc/access.php — Zarządzanie dostępem (MS365 + panel) dla osoby z umowy.
 *
 * Panel ADMINA/EDYTORA w podsystemie Tożsamość. Konsoliduje akcje dostępowe
 * przeniesione z widoku umowy (contracts/&lt;typ&gt;/view.php):
 *   • Panel: link do ustawienia hasła, reset hasła + dane, jednorazowy kod dostępu.
 *   • Microsoft 365: reset hasła, usunięcie konta.
 *
 * Wywołanie: /tozsamosc/access.php?type=wolontariat&id=<id_umowy>
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/approval.php';   // log_contract_action, email_*
require_once dirname(__DIR__) . '/includes/mail_queue.php';
require_once dirname(__DIR__) . '/includes/m365.php';

auth_start();
if (!current_user()) { header('Location: ' . APP_URL . '/tozsamosc/login.php'); exit; }
require_role('admin', 'editor');

$ALLOWED = ['wolontariat', 'zlecenie', 'dzielo'];
$TYPE = $_GET['type'] ?? $_POST['type'] ?? 'wolontariat';
if (!in_array($TYPE, $ALLOWED, true)) $TYPE = 'wolontariat';
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

$row = $id ? db_one("SELECT * FROM umowy_{$TYPE} WHERE id=?", [$id]) : null;
if (!$row) { http_response_code(404); $PAGE_TITLE='Nie znaleziono'; include __DIR__.'/_head.php'; echo '<div class="alert alert-danger">Nie znaleziono umowy.</div>'; include __DIR__.'/_foot.php'; exit; }

$email       = trim($row['email'] ?? '');
$org         = defined('ORG_NAME') ? ORG_NAME : 'Organizacja';
$portal_user = ($email && filter_var($email, FILTER_VALIDATE_EMAIL))
    ? db_one("SELECT id, name FROM users WHERE LOWER(email)=LOWER(?)", [$email]) : null;
$m365_uid    = trim($row['m365_user_id'] ?? '');
$m365_login  = trim($row['m365_login'] ?? '');
$has_m365    = ($m365_uid !== '' && !empty($row['m365_konto']));

// Numer telefonu wolontariusza (do wysyłki SMS): konto → telefon z umowy.
$sms_ok    = false;
try { require_once dirname(__DIR__) . '/includes/sms.php'; $sms_ok = sms_is_enabled(); } catch (\Throwable $e) {}
$vol_phone = '';
if ($portal_user) { $pu = db_one("SELECT phone_number FROM users WHERE id=?", [(int)$portal_user['id']]); $vol_phone = trim($pu['phone_number'] ?? ''); }
if ($vol_phone === '') $vol_phone = trim($row['telefon'] ?? '');
$back_view   = APP_URL . "/contracts/{$TYPE}/view.php?id={$id}";
$self        = APP_URL . "/tozsamosc/access.php?type={$TYPE}&id={$id}";

function _acc_m365(): ?M365Graph {
    if (m365_setting('m365_enabled') !== '1') return null;
    $t = m365_setting('m365_tenant_id'); $c = m365_setting('m365_graph_client_id'); $s = m365_setting('m365_graph_client_secret');
    if (!$t || !$c || !$s) return null;
    return new M365Graph(['tenant_id'=>$t,'client_id'=>$c,'client_secret'=>$s]);
}

// ═══════════════ POST ═══════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['_action'] ?? '';
    $me  = (int)current_user()['id'];

    // ── Panel: link do ustawienia hasła ──
    if ($act === 'set_portal_pass') {
        if (!$portal_user) { flash_set('warning', 'Osoba nie ma konta w panelu.'); }
        else {
            $tok = auth_generate_setup_token((int)$portal_user['id']);
            $url = APP_URL . '/auth/set_password.php?token=' . $tok;
            $body = '<p>Witaj, <strong>'.h($row['imie_nazwisko'] ?? $email).'</strong>!</p>'
                  . '<p>Administrator wysłał link do ustawienia hasła do panelu (umowa <strong>'.h($row['numer_umowy'] ?? '').'</strong>). Login: <strong>'.h($email).'</strong>.</p>'
                  . '<p><a href="'.$url.'" style="background:#1656d6;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:700">Ustaw hasło →</a></p>'
                  . '<p style="font-size:.8em;color:#6c757d">Link jednorazowy.</p>';
            if (email_rate_limit_ok($email, 3)) {
                mail_queue_add($email, $row['imie_nazwisko'] ?? $email, "Ustaw hasło do panelu — {$org}", $body, '', $TYPE, $id, '', true);
                email_log($email, "Link do ustawienia hasła — {$org}", $TYPE, $id);
                log_contract_action($TYPE, $id, $me, 'note', 'Wysłano link do ustawienia hasła panelu: ' . $email);
                flash_set('success', 'Link do ustawienia hasła wysłany na ' . $email . '.');
            } else flash_set('warning', 'Limit 3 wiadomości dziennie do tego adresu został osiągnięty.');
        }
        header('Location: ' . $self); exit;
    }

    // ── Panel: reset hasła + wyślij dane ──
    elseif ($act === 'reset_portal_pass') {
        if (!$portal_user) { flash_set('warning', 'Osoba nie ma konta w panelu.'); }
        else {
            $plain = substr(str_replace(['+','/','-'],'',base64_encode(random_bytes(18))),0,12);
            db()->prepare("UPDATE users SET password=?, login_code=NULL WHERE id=?")->execute([password_hash($plain, PASSWORD_BCRYPT), (int)$portal_user['id']]);
            $login = APP_URL . '/auth/login.php';
            $body = '<p>Cześć, <strong>'.h($row['imie_nazwisko'] ?? $email).'</strong>!</p>'
                  . '<p>Hasło do panelu zostało zresetowane. Dane logowania:</p>'
                  . '<table style="background:#f8f9fa;border-radius:8px;padding:14px;border-collapse:collapse">'
                  . '<tr><td style="padding:5px 14px;color:#6c757d">Login</td><td style="padding:5px 14px"><strong>'.h($email).'</strong></td></tr>'
                  . '<tr><td style="padding:5px 14px;color:#6c757d">Hasło</td><td style="padding:5px 14px"><strong style="font-family:monospace;font-size:1.15em">'.h($plain).'</strong></td></tr></table>'
                  . '<p><a href="'.$login.'" style="background:#1656d6;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600">Zaloguj się →</a></p>'
                  . '<p style="font-size:.82em;color:#6c757d">Po zalogowaniu zmień hasło w Systemie Tożsamości.</p>';
            if (email_rate_limit_ok($email, 3)) {
                mail_queue_add($email, $row['imie_nazwisko'] ?? $email, "Nowe dane logowania — {$org}", $body, '', $TYPE, $id, '', true);
                email_log($email, "Nowe dane logowania — {$org}", $TYPE, $id);
                log_contract_action($TYPE, $id, $me, 'note', 'Reset hasła panelu + wysłano dane: ' . $email);
                flash_set('success', 'Hasło zresetowane, dane wysłane na ' . $email . '.');
            } else flash_set('warning', 'Hasło zresetowane, ale limit wiadomości dziennie osiągnięty — przekaż dane ręcznie.');
        }
        header('Location: ' . $self); exit;
    }

    // ── Panel: jednorazowy kod dostępu ──
    elseif ($act === 'resend_code') {
        if (!$portal_user) { flash_set('warning', 'Osoba nie ma konta w panelu.'); }
        else {
            $code = strtoupper(bin2hex(random_bytes(5)));
            db()->prepare("UPDATE users SET login_code=? WHERE id=?")->execute([$code, (int)$portal_user['id']]);
            $body = '<p>Witaj, <strong>'.h($row['imie_nazwisko'] ?? $email).'</strong>!</p>'
                  . '<p>Jednorazowy kod dostępu do panelu (ważny 7 dni):</p>'
                  . '<div style="font-family:monospace;font-size:2rem;font-weight:700;letter-spacing:.15em;color:#1656d6;text-align:center;margin:16px 0">'.h($code).'</div>'
                  . '<p><a href="'.APP_URL.'/auth/login.php?tab=code" style="background:#1656d6;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;display:inline-block">Zaloguj kodem →</a></p>';
            mail_queue_add($email, $row['imie_nazwisko'] ?? $email, "Kod dostępu do panelu — {$org}", $body);
            mail_queue_process();
            log_contract_action($TYPE, $id, $me, 'note', 'Wysłano jednorazowy kod dostępu do panelu: ' . $email);
            flash_set('success', 'Jednorazowy kod dostępu wysłany na ' . $email . '.');
        }
        header('Location: ' . $self); exit;
    }

    // ── Panel: wyślij login + hasło SMS-em (reset hasła) ──
    elseif ($act === 'sms_credentials') {
        if (!$sms_ok) { flash_set('danger', 'Wysyłka SMS jest niedostępna (integracja wyłączona).'); }
        elseif (!$portal_user) { flash_set('warning', 'Osoba nie ma konta w panelu.'); }
        elseif ($vol_phone === '') { flash_set('warning', 'Brak numeru telefonu — uzupełnij numer na koncie lub w umowie.'); }
        else {
            $plain = substr(str_replace(['+','/','-'],'',base64_encode(random_bytes(18))),0,12);
            db()->prepare("UPDATE users SET password=?, login_code=NULL, must_change_password=1 WHERE id=?")
                ->execute([password_hash($plain, PASSWORD_BCRYPT), (int)$portal_user['id']]);
            $org_short = mb_substr($org, 0, 24);
            $msg = "Panel {$org_short}\nLogin: {$email}\nHaslo: {$plain}\nZmien haslo po zalogowaniu.";
            try {
                $via = sms_send_with_fallback(sms_normalize_phone($vol_phone), $msg, $email);
                log_contract_action($TYPE, $id, $me, 'note', 'Wyslano login+haslo panelu SMS-em na: ' . $vol_phone . ' (reset hasla)');
                flash_set($via === 'email' ? 'warning' : 'success',
                    $via === 'email'
                      ? 'SMS nie przeszedł — login i hasło wysłano na e-mail ' . $email . '. Hasło zostało zresetowane.'
                      : 'Login i hasło wysłano SMS-em na ' . $vol_phone . '. Hasło zostało zresetowane.');
            } catch (\Throwable $e) { flash_set('danger', 'Błąd wysyłki SMS: ' . $e->getMessage() . ' (hasło zostało zresetowane).'); }
        }
        header('Location: ' . $self); exit;
    }

    // ── M365: reset hasła ──
    elseif ($act === 'm365_reset') {
        if (!$has_m365) { flash_set('warning', 'Osoba nie ma konta Microsoft 365.'); }
        else {
            $m = _acc_m365();
            if (!$m) flash_set('danger', 'Integracja Microsoft 365 nie jest skonfigurowana.');
            else {
                try {
                    $new = M365Graph::generate_password();
                    $m->set_password($m365_uid, $new);
                    $_SESSION['acc_m365_pass'] = $new;
                    log_contract_action($TYPE, $id, $me, 'note', 'Reset hasła Microsoft 365: ' . $m365_login);
                    flash_set('success', 'Hasło Microsoft 365 zresetowane. Nowe hasło pokazano poniżej.');
                } catch (\Throwable $e) { flash_set('danger', 'Błąd resetu M365: ' . $e->getMessage()); }
            }
        }
        header('Location: ' . $self); exit;
    }

    // ── M365: usuń konto ──
    elseif ($act === 'm365_delete') {
        if (!$has_m365) { flash_set('warning', 'Osoba nie ma konta Microsoft 365.'); }
        elseif (strcasecmp(trim($_POST['confirm_login'] ?? ''), $m365_login) !== 0) {
            flash_set('danger', 'Potwierdzenie nieprawidłowe — wpisz dokładnie login M365.');
        } else {
            $m = _acc_m365();
            if (!$m) flash_set('danger', 'Integracja Microsoft 365 nie jest skonfigurowana.');
            else {
                try {
                    $m->delete_user($m365_uid);
                    db()->prepare("UPDATE umowy_{$TYPE} SET m365_konto_aktywne=0, m365_user_id='' WHERE id=?")->execute([$id]);
                    if ($portal_user) db()->prepare("UPDATE users SET microsoft_id=NULL WHERE id=?")->execute([(int)$portal_user['id']]);
                    log_contract_action($TYPE, $id, $me, 'note', 'Usunięto konto Microsoft 365: ' . $m365_login);
                    flash_set('success', 'Konto Microsoft 365 zostało usunięte.');
                } catch (\Throwable $e) { flash_set('danger', 'Błąd usuwania M365: ' . $e->getMessage()); }
            }
        }
        header('Location: ' . $self); exit;
    }
}

$m365_new = null;
if (!empty($_SESSION['acc_m365_pass'])) { $m365_new = $_SESSION['acc_m365_pass']; unset($_SESSION['acc_m365_pass']); }

$PAGE_TITLE = 'Zarządzanie dostępem';
include __DIR__ . '/_head.php';
?>

<div class="tz-h">
  <h1><i class="bi bi-shield-lock me-2" style="color:#1E6DFF" aria-hidden="true"></i>Zarządzanie dostępem</h1>
  <p>MS365 i panel · <?= h($row['imie_nazwisko'] ?? $email) ?> · umowa <?= h($row['numer_umowy'] ?? ('#'.$id)) ?></p>
</div>

<p class="mb-3"><a href="<?= h($back_view) ?>" class="tz-btn tz-btn--ghost btn-sm"><i class="bi bi-arrow-left" aria-hidden="true"></i> Wróć do umowy</a></p>

<?= flash_html() ?>

<?php if ($m365_new): ?>
<div class="tz-card" style="border-color:#a7f3d0">
  <div class="tz-card__bd">
    <div class="fw-bold mb-1" style="color:#047857"><i class="bi bi-check-circle-fill me-1"></i>Nowe hasło Microsoft 365 (pokazane raz)</div>
    <div class="d-flex align-items-center gap-2">
      <code class="fs-5 fw-bold"><?= h($m365_new) ?></code>
      <button type="button" class="tz-btn tz-btn--ghost btn-sm" onclick="navigator.clipboard.writeText('<?= h($m365_new) ?>');this.textContent='Skopiowano'"><i class="bi bi-clipboard"></i> Kopiuj</button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── Panel wolontariusza ── -->
<section class="tz-card">
  <div class="tz-card__hd"><i class="bi bi-window-desktop" aria-hidden="true"></i>
    <span>Dostęp do panelu</span>
    <span class="tz-badge <?= $portal_user ? 'tz-badge--ok' : 'tz-badge--off' ?> ms-auto">
      <?= $portal_user ? 'Konto istnieje' : 'Brak konta' ?>
    </span>
  </div>
  <div class="tz-card__bd">
    <?php if (!$portal_user): ?>
      <p class="text-muted mb-0">Osoba nie ma jeszcze konta w panelu. Konto powstaje przy zapisie umowy (poprawny e-mail wymagany). E-mail umowy: <strong><?= h($email ?: '—') ?></strong>.</p>
    <?php else: ?>
      <p class="text-muted small">Login: <strong><?= h($email) ?></strong></p>
      <div class="d-flex gap-2 flex-wrap">
        <form method="post" class="m-0"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="_action" value="set_portal_pass">
          <button class="tz-btn tz-btn--ghost btn-sm" type="submit"><i class="bi bi-link-45deg" aria-hidden="true"></i> Wyślij link do ustawienia hasła</button></form>
        <form method="post" class="m-0" onsubmit="return confirm('Zresetować hasło i wysłać nowe dane logowania?')"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="_action" value="reset_portal_pass">
          <button class="tz-btn tz-btn--ghost btn-sm" type="submit"><i class="bi bi-key" aria-hidden="true"></i> Resetuj hasło + wyślij dane</button></form>
        <form method="post" class="m-0"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="_action" value="resend_code">
          <button class="tz-btn tz-btn--ghost btn-sm" type="submit"><i class="bi bi-123" aria-hidden="true"></i> Wyślij jednorazowy kod</button></form>
        <?php if ($sms_ok && $vol_phone !== ''): ?>
        <form method="post" class="m-0" onsubmit="return confirm('Zresetować hasło i wysłać login + hasło SMS-em na <?= h($vol_phone) ?>?')">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="_action" value="sms_credentials">
          <button class="tz-btn btn-sm" type="submit"><i class="bi bi-phone-vibrate" aria-hidden="true"></i> Wyślij login + hasło SMS-em</button></form>
        <?php endif; ?>
      </div>
      <?php if ($sms_ok): ?>
      <p class="text-muted mt-2 mb-0" style="font-size:.8rem">
        <i class="bi bi-phone me-1" aria-hidden="true"></i>
        <?= $vol_phone !== '' ? 'SMS na numer: <strong>' . h($vol_phone) . '</strong> (hasło zostanie zresetowane).' : 'Brak numeru telefonu — wysyłka SMS niedostępna, uzupełnij numer na koncie lub w umowie.' ?>
      </p>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>

<!-- ── Microsoft 365 ── -->
<section class="tz-card">
  <div class="tz-card__hd"><i class="bi bi-microsoft" aria-hidden="true"></i>
    <span>Dostęp do Microsoft 365</span>
    <span class="tz-badge <?= $has_m365 ? 'tz-badge--ok' : 'tz-badge--off' ?> ms-auto">
      <?= $has_m365 ? 'Konto istnieje' : 'Brak konta' ?>
    </span>
  </div>
  <div class="tz-card__bd">
    <?php if (!$has_m365): ?>
      <p class="text-muted mb-0">Brak aktywnego konta Microsoft 365 dla tej umowy. Konto zakładasz w kreatorze umowy / edycji.</p>
    <?php else: ?>
      <p class="text-muted small">Login M365: <strong><?= h($m365_login) ?></strong></p>
      <div class="d-flex gap-2 flex-wrap align-items-start">
        <form method="post" class="m-0" onsubmit="return confirm('Zresetować hasło konta Microsoft 365?')"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="_action" value="m365_reset">
          <button class="tz-btn tz-btn--ghost btn-sm" type="submit"><i class="bi bi-key" aria-hidden="true"></i> Resetuj hasło M365</button></form>
        <button type="button" class="btn btn-outline-danger btn-sm" onclick="var d=document.getElementById('m365del');d.hidden=!d.hidden">
          <i class="bi bi-trash" aria-hidden="true"></i> Usuń konto M365</button>
      </div>
      <div id="m365del" hidden class="mt-3 p-3 rounded border border-danger-subtle bg-danger bg-opacity-10" style="max-width:420px">
        <div class="fw-semibold text-danger mb-1"><i class="bi bi-exclamation-octagon me-1"></i>Nieodwracalne usunięcie konta M365</div>
        <form method="post" onsubmit="return confirm('TRWALE usunąć konto Microsoft 365? Tej operacji nie można cofnąć.')">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="_action" value="m365_delete">
          <label class="form-label small fw-semibold mb-1">Wpisz <code><?= h($m365_login) ?></code> aby potwierdzić</label>
          <input type="text" name="confirm_login" class="form-control form-control-sm mb-2" autocomplete="off" placeholder="<?= h($m365_login) ?>" required>
          <button class="btn btn-danger btn-sm w-100" type="submit"><i class="bi bi-trash me-1"></i>Usuń konto na stałe</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php include __DIR__ . '/_foot.php';
