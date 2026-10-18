<?php
/**
 * modules/smart_cards/my.php — „Moja karta”: karty zalogowanej osoby, ich strefy
 * oraz wnioski (złożenie nowego, status i powód odrzucenia).
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once __DIR__ . '/logic/smart_cards.php';
require_once __DIR__ . '/partials/application_form.php';

require_login();
ika_require();
require_module_enabled('smart_cards_enabled', 'Moduł Karty dostępu');

$me   = current_user();
$uid  = (int)$me['id'];
$svc  = new SmartCardService($me);
$SELF = APP_URL . '/modules/smart_cards/my.php';
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        // wniosek zawsze za siebie — user_id z sesji, nigdy z formularza
        $svc->submitApplication($uid, ['user_id' => $uid] + $_POST, $_FILES['photo'] ?? null);
        flash_set('success', 'Wniosek złożony. Powiadomimy Cię o decyzji.');
        header('Location: ' . $SELF); exit;
    } catch (SmartCardException $e) {
        $error = $e->getMessage();
    } catch (\Throwable $e) {
        error_log('[smart_cards/my] ' . $e->getMessage());
        $error = 'Nie udało się złożyć wniosku — nic nie zostało zapisane.';
    }
}
$cards = $svc->userCards($uid);
$apps  = $svc->userApplications($uid);
$hasPending = (bool)array_filter($apps, fn($a) => $a['status'] === 'pending');

$PAGE_TITLE = 'Moja karta dostępu';
include dirname(__DIR__, 2) . '/includes/header.php';
include __DIR__ . '/partials/ui.php';
?>
<div class="scm tw-max-w-[960px]">
<header class="dash-h">
  <div>
    <h1><span class="dash-h__ico" aria-hidden="true"><i class="bi bi-person-badge"></i></span> Moja karta dostępu</h1>
    <p>Twoje karty identyfikacyjne, strefy biura, do których masz wstęp, i wnioski o wydanie karty.</p>
  </div>
</header>
<?= flash_html() ?>

<?php if (!$cards): ?>
  <div class="alert-info" role="status"><i class="bi bi-info-circle" aria-hidden="true"></i><div>Nie masz jeszcze karty. Złóż wniosek poniżej.</div></div>
<?php endif; ?>
<div class="tw-grid tw-grid-cols-1 md:tw-grid-cols-2 tw-gap-5 tw-mb-6">
  <?php foreach ($cards as $c): $zones = $svc->cardZones($c); ?>
  <section class="tz-card" aria-label="Karta <?= h(scard_uid_display($c['card_uid'])) ?>">
    <div class="tz-card__bd tw-space-y-3">
      <?= scard_render(scard_design($c['design_config']), ['holder' => $me['name'], 'uid' => $c['card_uid'], 'expires_at' => $c['expires_at'], 'status' => $c['status'], 'template' => $c['template_name']]) ?>
      <p class="tw-m-0"><?= scard_status_badge($c['status']) ?> <span class="sub tw-inline">ważna do <?= h(date('d.m.Y', strtotime($c['expires_at']))) ?></span></p>
      <?php if ($c['status'] === 'active'): ?>
      <p class="tw-m-0 tw-text-sm"><strong>Strefy:</strong> <?= $zones ? h(implode(', ', array_column(array_filter($zones, fn($z) => $z['effective']), 'zone_name'))) : 'brak' ?></p>
      <p class="f-help">Zgubiłeś kartę? Zgłoś to od razu administratorowi — zostanie zablokowana.</p>
      <?php endif; ?>
    </div>
  </section>
  <?php endforeach; ?>
</div>

<div class="tw-grid tw-grid-cols-1 tw-gap-5">
  <section class="tz-card" aria-labelledby="h-new">
    <div class="tz-card__hd"><h2 id="h-new"><i class="bi bi-send" aria-hidden="true"></i> Wniosek o kartę</h2></div>
    <div class="tz-card__bd">
      <?php if ($error): ?><div class="alert-err" role="alert"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i><div><?= h($error) ?></div></div><?php endif; ?>
      <?php if ($hasPending): ?>
        <p class="tw-m-0 tw-text-sm">Masz wniosek oczekujący na decyzję.</p>
      <?php else: ?>
      <form method="post" action="<?= h($SELF) ?>" enctype="multipart/form-data" class="tw-space-y-4">
        <?= csrf_field() ?>
        <?php scard_application_fields([
            'users' => null, 'supervisors' => $svc->supervisors(), 'user_id' => $uid, 'old' => $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : [],
            'defaults' => $svc->applicantDefaults($uid), 'templates' => $svc->templates(), 'zones' => $svc->zones(true), 'prefix' => 'm',
        ]); ?>
        <button class="h-btn h-btn--primary" type="submit">Złóż wniosek</button>
      </form>
      <?php endif; ?>
    </div>
  </section>
  <section class="tz-card" aria-labelledby="h-apps">
    <div class="tz-card__hd"><h2 id="h-apps"><i class="bi bi-inbox" aria-hidden="true"></i> Moje wnioski</h2></div>
    <div class="tz-card__bd">
      <?php if (!$apps): ?><p class="tw-m-0 tw-text-sm" style="color:var(--tz-muted)">Brak wniosków.</p><?php else: ?>
      <ul class="tw-list-none tw-m-0 tw-p-0 tw-space-y-3">
        <?php foreach ($apps as $a): ?>
        <li><?= scard_status_badge($a['status']) ?> <span class="sub tw-inline"><?= h(SmartCardService::applicationNumber($a)) ?> · <?= h(SCARD_APP_TYPES[$a['app_type']]['short'] ?? '') ?> · <?= h(date('d.m.Y', strtotime($a['created_at']))) ?></span>
          <a class="sub tw-inline" href="<?= h(APP_URL . '/modules/smart_cards/application_pdf.php?id=' . (int)$a['id']) ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> wydruk</a>
          <div class="tw-text-sm"><?= h($a['reason']) ?></div>
          <?php if ($a['status'] === 'rejected' && $a['decision_note']): ?><div class="sub"><strong>Powód odrzucenia:</strong> <?= h($a['decision_note']) ?></div><?php endif; ?></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
  </section>
</div>
</div>
<?php scard_application_form_js(); ?>
<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
