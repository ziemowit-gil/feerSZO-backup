<?php
/**
 * crm/moja_skrzynka.php — hasło do własnej skrzynki (dPoczta), z której CRM wysyła wiadomości „z mojej skrzynki”.
 *
 * Zastępuje „Moją skrzynkę Microsoft 365” (Graph). Hasło do poczty jest inne niż hasło logowania jednokrotnego,
 * więc podaje się je tutaj raz; sprawdzamy je logowaniem do serwera SMTP i zapisujemy zaszyfrowane
 * (modules/userSmtp/lib/UserSmtp.php). Po każdej zmianie hasła do poczty (/nowehaslo albo gdziekolwiek indziej)
 * trzeba je podać ponownie — do tego czasu wysyłka z własnej skrzynki jest wstrzymana (nie przechodzi po cichu
 * na Konto Fundacji).
 *
 * WCAG: etykiety powiązane z polami, błąd w role="alert" i aria-invalid/aria-describedby przy polu,
 * stan opisany tekstem (nie tylko kolorem), autocomplete dla menedżerów haseł.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crm.php';
require_once dirname(__DIR__) . '/modules/userSmtp/lib/UserSmtp.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_require('contacts', 'read');

$PAGE_TITLE = 'Moja skrzynka';
$me   = current_user();
$uid  = (int)$me['id'];
$box  = UserSmtp::mailboxOf($me);
$err  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'forget') {
        UserSmtp::forget($uid);
        flash_set('success', 'Usunięto zapisane hasło do poczty. Wiadomości z CRM wyjdą z Konta Fundacji.');
        header('Location: ' . APP_URL . '/crm/moja_skrzynka.php'); exit;
    }
    if ($box === '') {
        $err = 'Twoje konto nie ma skrzynki pocztowej Fundacji.';
    } else {
        try {
            UserSmtp::save($uid, $box, (string)($_POST['password'] ?? ''));
            flash_set('success', 'Hasło do poczty sprawdzone i zapisane. Możesz wysyłać z CRM ze swojej skrzynki.');
            $back = (string)($_POST['back'] ?? '');
            header('Location: ' . ($back !== '' && str_starts_with($back, APP_URL . '/crm/') ? $back : APP_URL . '/crm/moja_skrzynka.php')); exit;
        } catch (\Throwable $e) {
            $err = $e instanceof RuntimeException ? $e->getMessage() : 'Nie udało się zapisać hasła. Spróbuj za kilka minut.';
            if (!($e instanceof RuntimeException)) error_log('[moja_skrzynka] ' . $e->getMessage());
        }
    }
}

$acc  = UserSmtp::get($uid);
$back = (string)($_GET['back'] ?? ($_POST['back'] ?? ($_SERVER['HTTP_REFERER'] ?? '')));
if (!str_starts_with($back, APP_URL . '/crm/') || str_contains($back, 'moja_skrzynka')) $back = '';
[$smtpHost, $smtpPort] = UserSmtp::server();

$status = 'none';
if ($acc) $status = (string)$acc['status'];
$statusText = [
    'none'        => 'Hasło do poczty nie jest zapisane — wiadomości z CRM wychodzą z Konta Fundacji.',
    'ok'          => 'Hasło zapisane i aktualne — możesz wybrać „Moja skrzynka” jako nadawcę.',
    'changed'     => 'Hasło do poczty zostało zmienione — podaj nowe, aby znów wysyłać ze swojej skrzynki.',
    'auth_failed' => 'Serwer poczty odrzucił zapisane hasło (zapewne zostało zmienione) — podaj aktualne hasło.',
][$status] ?? '';

include __DIR__ . '/includes/header_crm.php';
?>
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <a href="<?= h($back !== '' ? $back : APP_URL . '/crm/dashboard.php') ?>" class="btn btn-sm btn-outline-secondary" aria-label="Wróć"><i class="bi bi-arrow-left" aria-hidden="true"></i></a>
  <h1 class="h4 mb-0"><i class="bi bi-envelope-at me-2" aria-hidden="true"></i>Moja skrzynka</h1>
</div>

<div class="card shadow-sm" style="max-width:640px"><div class="card-body">
<?php if ($box === ''): ?>
  <p class="mb-0">Twoje konto nie ma skrzynki pocztowej Fundacji, więc wiadomości z CRM wychodzą z <strong>Konta Fundacji</strong>.
    Jeśli to pomyłka, napisz do Helpdesku IT.</p>
<?php else: ?>
  <p class="mb-2">Aby CRM mógł wysyłać wiadomości z Twojej skrzynki <strong><?= h($box) ?></strong>, podaj hasło do poczty.
    To <strong>inne hasło</strong> niż do logowania jednokrotnego. Po każdej zmianie hasła do poczty trzeba je tu podać ponownie.</p>
  <p class="small mb-3" role="status"><strong>Stan:</strong> <?= h($statusText) ?></p>

  <?php if ($err !== ''): ?>
  <div class="alert alert-danger py-2 small" role="alert" id="ms-pass-err"><?= h($err) ?></div>
  <?php endif; ?>

  <form method="post" class="row g-2" autocomplete="on">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="back" value="<?= h($back) ?>">
    <input type="hidden" name="username" value="<?= h($box) ?>" autocomplete="username">
    <div class="col-12">
      <label for="ms-pass" class="form-label small fw-semibold mb-1">Hasło do poczty <?= h($box) ?></label>
      <input id="ms-pass" type="password" name="password" class="form-control" required autocomplete="current-password"
             aria-describedby="ms-pass-help<?= $err !== '' ? ' ms-pass-err' : '' ?>"<?= $err !== '' ? ' aria-invalid="true" autofocus' : '' ?>>
      <div id="ms-pass-help" class="form-text">Sprawdzimy je logowaniem do serwera poczty (<?= h($smtpHost . ':' . $smtpPort) ?>) i zapiszemy w postaci zaszyfrowanej.</div>
    </div>
    <div class="col-12 d-flex gap-2 flex-wrap mt-2">
      <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Sprawdź i zapisz</button>
    </div>
  </form>

  <?php if ($acc): ?>
  <form method="post" class="mt-3">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="forget">
    <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash me-1" aria-hidden="true"></i>Usuń zapisane hasło</button>
  </form>
  <?php endif; ?>
<?php endif; ?>
</div></div>
<?php include __DIR__ . '/includes/footer_crm.php'; ?>
