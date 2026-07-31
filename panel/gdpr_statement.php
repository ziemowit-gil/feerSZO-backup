<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_login();
$PAGE_TITLE = 'Oświadczenie o ochronie danych';
$user = current_user();
$uid  = (int)$user['id'];

$row            = db_one("SELECT gdpr_statement_signed_at, gdpr_statement_ip FROM users WHERE id=?", [$uid]);
$already_signed = !empty($row['gdpr_statement_signed_at']);
$error          = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$already_signed) {
    csrf_check();
    if (empty($_POST['confirm_statement'])) {
        $error = 'Proszę zaznaczyć potwierdzenie przed złożeniem elektronicznego podpisu.';
    } else {
        $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '')[0]);
        db()->prepare(
            "UPDATE users SET gdpr_statement_signed_at=?, gdpr_statement_ip=? WHERE id=?"
        )->execute([date('Y-m-d H:i:s'), $ip, $uid]);
        header('Location: ' . APP_URL . '/panel/gdpr_statement.php?signed=1'); exit;
    }
}

include __DIR__ . '/includes/header_panel.php';
?>

<div class="pv-page-header d-flex gap-2 flex-wrap mb-4">
  <h1 class="pv-page-title"><i class="bi bi-file-earmark-check me-2" aria-hidden="true"></i>Oświadczenie o ochronie danych</h1>
  <p class="pv-page-sub">Wymagane przed aktywacją konta Microsoft 365</p>
</div>

<?php if (!$already_signed): ?>
<div class="alert alert-warning d-flex align-items-start gap-2 mb-4" role="alert">
  <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1" aria-hidden="true"></i>
  <div>
    <strong>Podpisanie oświadczenia jest wymagane.</strong><br>
    Konto Microsoft 365 zostanie aktywowane dopiero po złożeniu elektronicznego podpisu poniżej.
    Zapoznaj się z treścią i kliknij przycisk „Zapoznałem się i podpisuję elektronicznie".
  </div>
</div>
<?php endif; ?>

<?php if (isset($_GET['signed'])): ?>
<div class="alert alert-success d-flex align-items-center gap-2 mb-4" role="alert">
  <i class="bi bi-check-circle-fill fs-5" aria-hidden="true"></i>
  <strong>Oświadczenie zostało złożone elektronicznie.</strong>&nbsp;
  Konto Microsoft 365 może zostać teraz aktywowane przez administratora.
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger d-flex align-items-center gap-2 mb-3" role="alert">
  <i class="bi bi-x-circle me-1" aria-hidden="true"></i><?= h($error) ?>
</div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
  <div class="card-header fw-semibold d-flex align-items-center gap-2">
    <i class="bi bi-file-text text-primary" aria-hidden="true"></i>Treść oświadczenia
  </div>
  <div class="card-body">
    <div class="border rounded p-3 mb-4"
         style="background:#F9FAFB;font-size:.9rem;line-height:1.75;max-height:440px;overflow-y:auto"
         tabindex="0" role="region" aria-label="Treść oświadczenia">
      <p class="fw-bold text-center mb-3" style="font-size:1rem">OŚWIADCZENIE</p>
      <p>Oświadczam, że zapoznałem się z Polityką Ochrony Danych Osobowych oraz Regulaminem
         informatycznym, które obowiązują w placówce i zobowiązuję się do przestrzegania zasad
         w nich zawartych w szczególności zasad poufności dotyczących ochrony danych osobowych
         w poufności.</p>
      <p>Zostałem również zapoznany z ogólnymi zasadami zabezpieczenia i przetwarzania danych
         osobowych wynikającymi z rozporządzenia Parlamentu Europejskiego i Rady (UE) 2016/679
         z dnia 27 kwietnia 2016 r. w sprawie ochrony osób fizycznych w związku z przetwarzaniem
         danych osobowych i w sprawie swobodnego przepływu takich danych oraz uchylenia dyrektywy
         95/46/WE (ogólne rozporządzenie o ochronie danych osobowych Dz.&nbsp;Urz.&nbsp;UE L&nbsp;119,
         s.&nbsp;1).</p>
      <p class="mb-0">Oświadczam, że zachowam w poufności wszelkie dane osobowe, które przetwarzałem
         lub przetwarzam w ramach wykonywanych obowiązków oraz metody ich zabezpieczeń,
         także po ustaniu wykonywania obowiązku lub świadczeniu pracy.</p>
    </div>

    <?php if ($already_signed): ?>
    <div class="d-flex align-items-center gap-3 p-3 rounded"
         style="background:#F0FDF4;border:1px solid #BBF7D0">
      <i class="bi bi-patch-check-fill text-success fs-2 flex-shrink-0" aria-hidden="true"></i>
      <div>
        <div class="fw-bold text-success">Oświadczenie złożone elektronicznie</div>
        <div class="text-muted small mt-1">
          Data i godzina: <?= h(date('d.m.Y, H:i:s', strtotime($row['gdpr_statement_signed_at']))) ?>
          &middot; Adres IP: <?= h($row['gdpr_statement_ip'] ?? '—') ?>
        </div>
      </div>
    </div>
    <?php else: ?>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" id="confirm_statement"
               name="confirm_statement" value="1" required>
        <label class="form-check-label fw-semibold" for="confirm_statement">
          Zapoznałem/-am się z pełną treścią oświadczenia powyżej i akceptuję jego warunki.
        </label>
      </div>
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-pen me-1" aria-hidden="true"></i>Zapoznałem się i podpisuję elektronicznie
      </button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($already_signed): ?>
<div class="text-center mt-2 mb-4">
  <a href="<?= APP_URL ?>/panel/index.php" class="btn btn-outline-secondary">
    <i class="bi bi-house-door me-1" aria-hidden="true"></i>Przejdź do Pulpitu
  </a>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer_panel.php'; ?>
