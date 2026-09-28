<?php
/**
 * modules/smart_cards/index.php — Karty dostępu: podsumowanie stanów i lista kart
 * z filtrem statusu i wyszukiwaniem (posiadacz, e-mail, UID).
 * Logika: modules/smart_cards/logic/smart_cards.php.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once __DIR__ . '/logic/smart_cards.php';

require_role('admin', 'editor');
require_module_enabled('smart_cards_enabled', 'Moduł Karty dostępu');

$svc  = new SmartCardService(current_user());
$BASE = APP_URL . '/modules/smart_cards/index.php';

$f_status = isset(SCARD_STATUSES[$_GET['status'] ?? '']) ? $_GET['status'] : '';
$f_q      = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 64);
$page     = max(1, (int)($_GET['page'] ?? 1));
$PER_PAGE = 30;

$stats = $svc->stats();
$res   = $svc->cards($f_status, $f_q, $PER_PAGE, ($page - 1) * $PER_PAGE);
$pages = max(1, (int)ceil($res['total'] / $PER_PAGE));
$url   = fn(array $o = []) => $BASE . (($qs = http_build_query(array_filter(array_merge(['status' => $f_status, 'q' => $f_q], $o), 'strlen'))) ? '?' . $qs : '');

$PAGE_TITLE = 'Karty dostępu';
include dirname(__DIR__, 2) . '/includes/header.php';
include __DIR__ . '/partials/ui.php';
?>
<div class="scm">
<header class="dash-h">
  <div>
    <h1><span class="dash-h__ico" aria-hidden="true"><i class="bi bi-credit-card-2-front"></i></span> Karty dostępu</h1>
    <p>Karty identyfikacyjne NFC i uprawnienia do stref biura — wydawanie z wniosków, blokady, zagubienia i ważność.</p>
  </div>
  <div class="tw-flex tw-flex-wrap tw-gap-2">
    <a class="h-btn h-btn--ghost" href="<?= h(APP_URL . '/modules/smart_cards/nfc/index.php') ?>"><i class="bi bi-broadcast" aria-hidden="true"></i> Programator NFC</a>
    <a class="h-btn h-btn--primary" href="<?= h(APP_URL . '/modules/smart_cards/applications.php?new=1') ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> Nowy wniosek</a>
  </div>
</header>

<?php scard_tabs('cards', $stats['pending']); ?>
<?= flash_html() ?>

<section aria-label="Podsumowanie stanów" class="kpi-grid">
  <a class="kpi-tile is-all" href="<?= h($url(['status' => '', 'page' => ''])) ?>"<?= $f_status === '' ? ' aria-current="true"' : '' ?>>
    <span class="kpi-tile__ico" aria-hidden="true"><i class="bi bi-collection"></i></span>
    <span class="kpi-tile__val"><?= $stats['total'] ?></span><span class="kpi-tile__lbl">Wszystkie karty</span>
  </a>
  <?php foreach (SCARD_STATUSES as $k => $s): ?>
  <a class="kpi-tile is-<?= h($k) ?>" href="<?= h($url(['status' => $k, 'page' => ''])) ?>"<?= $f_status === $k ? ' aria-current="true"' : '' ?>>
    <span class="kpi-tile__ico" aria-hidden="true"><i class="bi <?= h($s['icon']) ?>"></i></span>
    <span class="kpi-tile__val"><?= $stats[$k] ?></span><span class="kpi-tile__lbl"><?= h($s['plural']) ?></span>
  </a>
  <?php endforeach; ?>
  <a class="kpi-tile is-pending" href="<?= h(APP_URL . '/modules/smart_cards/applications.php?status=pending') ?>">
    <span class="kpi-tile__ico" aria-hidden="true"><i class="bi bi-inbox"></i></span>
    <span class="kpi-tile__val"><?= $stats['pending'] ?></span><span class="kpi-tile__lbl">Wnioski do decyzji</span>
  </a>
</section>

<?php if ($stats['expiring']): ?>
<div class="alert-info" role="status"><i class="bi bi-hourglass-split" aria-hidden="true"></i>
  <div><strong><?= $stats['expiring'] ?></strong> aktywnych kart traci ważność w ciągu 30 dni — przedłuż je na karcie szczegółów.</div></div>
<?php endif; ?>

<section class="tz-card" aria-labelledby="h-list">
  <div class="tz-card__hd">
    <h2 id="h-list"><i class="bi bi-list-ul" aria-hidden="true"></i> Karty<?= $f_status ? ' — ' . h(mb_strtolower(SCARD_STATUSES[$f_status]['plural'])) : '' ?></h2>
    <form method="get" action="<?= h($BASE) ?>" role="search" class="tw-ml-auto tw-flex tw-gap-2">
      <?php if ($f_status): ?><input type="hidden" name="status" value="<?= h($f_status) ?>"><?php endif; ?>
      <label for="q" class="tw-sr-only">Szukaj karty</label>
      <input id="q" name="q" value="<?= h($f_q) ?>" class="f-input tw-w-64" placeholder="Posiadacz, e-mail lub UID">
      <button class="h-btn h-btn--ghost" type="submit"><i class="bi bi-search" aria-hidden="true"></i><span class="tw-sr-only">Szukaj</span></button>
    </form>
  </div>
  <?php if (!$res['rows']): ?>
    <p class="empty"><i class="bi bi-credit-card" aria-hidden="true"></i>Brak kart<?= $f_q !== '' ? ' pasujących do „' . h($f_q) . '”' : '' ?>. Karty powstają przy zatwierdzeniu wniosku.</p>
  <?php else: ?>
  <div class="tw-overflow-x-auto">
  <table class="h-table">
    <thead><tr><th scope="col">Karta</th><th scope="col">Posiadacz</th><th scope="col">UID</th><th scope="col">Status</th><th scope="col">Ważna do</th><th scope="col">Chip NFC</th></tr></thead>
    <tbody>
    <?php foreach ($res['rows'] as $c):
        $d = scard_design($c['design_config']);
        $soon = $c['status'] === 'active' && strtotime($c['expires_at']) < strtotime('+30 days'); ?>
      <tr>
        <td class="tw-w-[170px]"><a href="<?= h(APP_URL . '/modules/smart_cards/card.php?id=' . (int)$c['id']) ?>" aria-label="Szczegóły karty <?= h(scard_uid_display($c['card_uid'])) ?>">
          <?= scard_render($d, ['holder' => $c['user_name'], 'uid' => $c['card_uid'], 'expires_at' => $c['expires_at'], 'status' => $c['status'], 'template' => $c['template_name']], 'sm') ?></a></td>
        <td><a href="<?= h(APP_URL . '/modules/smart_cards/card.php?id=' . (int)$c['id']) ?>" class="tw-font-semibold"><?= h($c['user_name'] ?: '#' . $c['user_id']) ?></a>
            <span class="sub"><?= h($c['user_email']) ?> · <?= h($c['template_name']) ?></span></td>
        <td class="mono"><?= h(scard_uid_display($c['card_uid'])) ?></td>
        <td><?= scard_status_badge($c['status']) ?><?php if ($c['status_reason'] && $c['status'] !== 'active'): ?><span class="sub"><?= h(mb_strimwidth($c['status_reason'], 0, 60, '…')) ?></span><?php endif; ?></td>
        <td class="tw-whitespace-nowrap"<?= $soon ? ' style="color:#b45309;font-weight:600"' : '' ?>><?= h(date('d.m.Y', strtotime($c['expires_at']))) ?><?= $soon ? ' <span class="tw-sr-only">(wkrótce wygasa)</span><i class="bi bi-exclamation-triangle" aria-hidden="true"></i>' : '' ?></td>
        <td><?= $c['programmed_at'] ? '<span class="sc-badge is-active"><i class="bi bi-check2" aria-hidden="true"></i> Zaprogramowany</span>' : '<span class="sc-badge is-pending">Do zaprogramowania</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php if ($pages > 1): ?>
  <nav class="tw-flex tw-justify-between tw-items-center tw-p-3 tw-text-sm" aria-label="Stronicowanie">
    <?php if ($page > 1): ?><a class="h-btn h-btn--ghost" rel="prev" href="<?= h($url(['page' => (string)($page - 1)])) ?>">‹ Poprzednia</a><?php else: ?><span></span><?php endif; ?>
    <span>Strona <strong><?= $page ?></strong> z <?= $pages ?></span>
    <?php if ($page < $pages): ?><a class="h-btn h-btn--ghost" rel="next" href="<?= h($url(['page' => (string)($page + 1)])) ?>">Następna ›</a><?php else: ?><span></span><?php endif; ?>
  </nav>
  <?php endif; ?>
  <?php endif; ?>
</section>
</div>
<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
