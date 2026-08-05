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

<div class="pv-wrap">

  <div class="pv-page-header">
    <div class="pv-page-head-main">
      <a href="<?= APP_URL ?>/panel/index.php" class="pv-page-back"><i class="bi bi-arrow-left" aria-hidden="true"></i> Panel</a>
      <h1 class="pv-page-title"><i class="bi bi-shield-check" aria-hidden="true"></i>Ochrona danych osobowych</h1>
      <p class="pv-page-sub">Twoja zgoda i informacje o przetwarzaniu danych</p>
    </div>
  </div>

  <?php if (!$already_signed): ?>
  <div class="tz-note tz-note--warning" role="alert">
    <i class="bi bi-exclamation-triangle-fill flex-shrink-0" aria-hidden="true"></i>
    <div>
      <strong>Podpisanie oświadczenia jest wymagane.</strong><br>
      Konto Microsoft 365 zostanie aktywowane dopiero po złożeniu elektronicznego podpisu poniżej.
      Zapoznaj się z treścią i kliknij przycisk „Zapoznałem się i podpisuję elektronicznie".
    </div>
  </div>
  <?php endif; ?>

  <?php if (isset($_GET['signed'])): ?>
  <div class="pv-alert pv-alert-success" role="alert">
    <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
    <strong>Oświadczenie zostało złożone elektronicznie.</strong>&nbsp;
    Konto Microsoft 365 może zostać teraz aktywowane przez administratora.
  </div>
  <?php endif; ?>

  <?php if ($error): ?>
  <div class="pv-alert pv-alert-danger" role="alert">
    <i class="bi bi-x-circle" aria-hidden="true"></i><?= h($error) ?>
  </div>
  <?php endif; ?>

  <div class="tz-card mb-4">
    <div class="tz-card__hd">
      <i class="bi bi-file-text" aria-hidden="true"></i>Treść oświadczenia
    </div>
    <div class="tz-card__bd">

      <div class="gdpr-statement-text"
           tabindex="0" role="region" aria-label="Treść oświadczenia">
        <p class="gdpr-statement-title">OŚWIADCZENIE</p>
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
      <div class="tz-signed-status">
        <i class="bi bi-patch-check-fill tz-signed-status__icon" aria-hidden="true"></i>
        <div>
          <div class="tz-signed-status__label">Oświadczenie złożone elektronicznie</div>
          <div class="tz-signed-status__meta">
            Data i godzina: <?= h(date('d.m.Y, H:i:s', strtotime($row['gdpr_statement_signed_at']))) ?>
            &middot; Adres IP: <?= h($row['gdpr_statement_ip'] ?? '—') ?>
          </div>
        </div>
      </div>
      <?php else: ?>
      <form method="post" class="mt-4">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <div class="form-check mb-3">
          <input class="form-check-input" type="checkbox" id="confirm_statement"
                 name="confirm_statement" value="1" required aria-required="true">
          <label class="form-check-label fw-semibold" for="confirm_statement">
            Zapoznałem/-am się z pełną treścią oświadczenia powyżej i akceptuję jego warunki.
          </label>
        </div>
        <button type="submit" class="tz-btn">
          <i class="bi bi-pen me-1" aria-hidden="true"></i>Zapoznałem się i podpisuję elektronicznie
        </button>
      </form>
      <?php endif; ?>

    </div>
  </div>

  <?php if ($already_signed): ?>
  <div class="text-center mb-4">
    <a href="<?= APP_URL ?>/panel/index.php" class="tz-btn tz-btn--ghost">
      <i class="bi bi-house-door me-1" aria-hidden="true"></i>Przejdź do Pulpitu
    </a>
  </div>
  <?php endif; ?>

</div>

<?php include __DIR__ . '/includes/footer_panel.php'; ?>
