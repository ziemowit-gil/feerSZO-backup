<?php
/**
 * komunikaty/announcement.php — Pojedyncze ogłoszenie (widok pełny).
 *
 * Kolejność ma znaczenie: cała logika i przekierowania PRZED dołączeniem
 * powłoki (_shell.php zaczyna wypisywać HTML — wcześniej strona ładowała
 * header.php w pierwszej linii, więc każdy header('Location: …') leciał już
 * po wysłanych nagłówkach).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/notifications.php';
require_login();
notif_migrate();

$_cu   = current_user();
$_uid  = (int)$_cu['id'];
$_role = $_cu['role'] ?? 'viewer';

$_back = APP_URL . '/komunikaty/index.php';
$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    flash_set('danger', 'Nieprawidłowe ogłoszenie.');
    header('Location: ' . $_back);
    exit;
}

try {
    $ann = db_one("SELECT * FROM announcements WHERE id=? AND is_active=1", [$id]);
} catch (\Throwable $e) {
    $ann = null;
}

if (!$ann) {
    flash_set('danger', 'Ogłoszenie nie zostało znalezione.');
    header('Location: ' . $_back);
    exit;
}

if (!_ann_user_can_see($ann['audience'] ?? 'all', $_uid, $_role)) {
    flash_set('danger', 'Brak dostępu do tego ogłoszenia.');
    header('Location: ' . $_back);
    exit;
}

if (!empty($ann['expires_at']) && $ann['expires_at'] < date('Y-m-d')) {
    flash_set('warning', 'To ogłoszenie wygasło.');
    header('Location: ' . $_back);
    exit;
}

// Otwarcie = przeczytanie
ann_mark_read($id, $_uid);

$PAGE_TITLE = $ann['title'];
$_kat = $ann['kategoria'] ?? 'ogolne';
$_kc  = ann_kategoria_color($_kat);
$_pin = (int)($ann['is_pinned'] ?? 0);

require_once __DIR__ . '/_shell.php';

$_actions = is_admin()
    ? '<a href="' . APP_URL . '/komunikaty/manage.php" class="tz-btn tz-btn--ghost">'
      . '<i class="bi bi-sliders" aria-hidden="true"></i>Zarządzaj</a>'
    : '';

pv_page_header('Ogłoszenie', [
    'icon'    => 'bi-megaphone',
    'sub'     => ann_kategoria_label($_kat),
    'back'    => ['url' => $_back, 'label' => 'Komunikaty'],
    'actions' => $_actions,
]);
?>

<div class="pv-wrap">

  <article class="tz-card kom-ann" style="--kom-accent:<?= $_pin ? '#F59E0B' : h($_kc) ?>">
    <div class="tz-card__bd">
      <div class="kom-ann__meta mb-2">
        <?php if ($_pin): ?>
        <span class="tz-badge tz-badge--warning"><i class="bi bi-pin-angle-fill" aria-hidden="true"></i>Przypięte</span>
        <?php endif; ?>
        <span class="tz-badge" style="background:<?= h($_kc) ?>1A;color:<?= h($_kc) ?>;border-color:<?= h($_kc) ?>55">
          <?= h(ann_kategoria_label($_kat)) ?>
        </span>
        <span class="tz-badge tz-badge--ok"><i class="bi bi-check2-all" aria-hidden="true"></i>Przeczytane</span>
      </div>

      <h2 class="kom-ann__ttl" style="font-size:1.2rem"><?= h($ann['title']) ?></h2>

      <div class="kom-body"><?= h($ann['body']) ?></div>
    </div>

    <dl class="tz-dl">
      <div>
        <dt>Autor</dt>
        <dd><?= h($ann['author_name'] ?: '—') ?></dd>
      </div>
      <div>
        <dt>Opublikowano</dt>
        <dd><?= h(substr($ann['created_at'] ?? '', 0, 16) ?: '—') ?></dd>
      </div>
      <div>
        <dt>Odbiorcy</dt>
        <dd><?= h(ann_audience_label($ann['audience'] ?? 'all')) ?></dd>
      </div>
      <?php if (!empty($ann['expires_at'])): ?>
      <div>
        <dt>Wygasa</dt>
        <dd><?= h(date('d.m.Y', strtotime($ann['expires_at']))) ?></dd>
      </div>
      <?php endif; ?>
    </dl>

    <div class="tz-card__ft kom-ann__ft">
      <a href="<?= h($_back) ?>" class="tz-btn tz-btn--ghost kom-btn-sm">
        <i class="bi bi-arrow-left" aria-hidden="true"></i>Powrót do listy
      </a>
      <?php if (is_admin()): ?>
      <span class="kom-ann__author"><i class="bi bi-hash" aria-hidden="true"></i>ID <?= (int)$ann['id'] ?></span>
      <?php endif; ?>
    </div>
  </article>

</div>

<?php require __DIR__ . '/_foot.php'; ?>
