<?php
/**
 * komunikaty/manage.php — Zarządzanie ogłoszeniami (admin).
 *
 * Pełna lista ogłoszeń niezależna od audytorium bieżącego admina
 * (ann_list_for_user() pokazuje tylko to, co widzi zalogowany użytkownik —
 * tu widać wszystko, co zostało wysłane). Filtr po kategorii + usuwanie.
 * Układ w języku wizualnym modułu „Tożsamość" (_shell.php + .pv-table).
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/notifications.php';
require_login();
notif_migrate();

if (!is_admin()) {
    flash_set('danger', 'Brak uprawnień.');
    header('Location: ' . APP_URL . '/komunikaty/index.php');
    exit;
}

$_kat = trim($_GET['kat'] ?? '');
if (!array_key_exists($_kat, ann_kategoria_options())) $_kat = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_ann'])) {
    csrf_check();
    ann_delete((int)$_POST['delete_ann']);
    flash_set('success', 'Ogłoszenie zostało usunięte.');
    header('Location: ' . APP_URL . '/komunikaty/manage.php' . ($_kat !== '' ? '?kat=' . urlencode($_kat) : ''));
    exit;
}

$PAGE_TITLE = 'Zarządzanie ogłoszeniami';
$rows  = ann_all_list($_kat);
$_all  = $_kat === '' ? $rows : ann_all_list('');
$_pin  = 0;
$_exp  = 0;
$_today = date('Y-m-d');
foreach ($_all as $_r) {
    if ((int)($_r['is_pinned'] ?? 0)) $_pin++;
    if (!empty($_r['expires_at']) && $_r['expires_at'] < $_today) $_exp++;
}

require_once __DIR__ . '/_shell.php';

pv_page_header('Zarządzanie ogłoszeniami', [
    'icon'    => 'bi-sliders',
    'sub'     => 'Wszystkie opublikowane ogłoszenia — niezależnie od odbiorcy',
    'back'    => ['url' => APP_URL . '/komunikaty/index.php', 'label' => 'Komunikaty'],
    'actions' => '<a href="' . APP_URL . '/komunikaty/compose.php" class="tz-btn">'
               . '<i class="bi bi-plus-lg" aria-hidden="true"></i>Nowe ogłoszenie</a>',
]);
?>

<div class="pv-stats-bar">
  <span class="pv-stat-pill"><i class="bi bi-megaphone" aria-hidden="true"></i>Wszystkich: <span class="pv-stat-num"><?= count($_all) ?></span></span>
  <span class="pv-stat-pill"><i class="bi bi-pin-angle" aria-hidden="true"></i>Przypiętych: <span class="pv-stat-num"><?= $_pin ?></span></span>
  <span class="pv-stat-pill"><i class="bi bi-clock-history" aria-hidden="true"></i>Wygasłych: <span class="pv-stat-num"><?= $_exp ?></span></span>
</div>

<nav class="kom-chips" aria-label="Filtr kategorii">
  <a href="<?= APP_URL ?>/komunikaty/manage.php" class="kom-chip"
     <?= $_kat === '' ? 'aria-current="page"' : '' ?> style="--kom-accent:var(--tz)">
    <span class="kom-chip__dot" aria-hidden="true"></span>Wszystkie
  </a>
  <?php foreach (ann_kategoria_options() as $_kk => $_kl): ?>
  <a href="<?= APP_URL ?>/komunikaty/manage.php?kat=<?= urlencode($_kk) ?>" class="kom-chip"
     <?= $_kat === $_kk ? 'aria-current="page"' : '' ?>
     style="--kom-accent:<?= h(ann_kategoria_color($_kk)) ?>">
    <span class="kom-chip__dot" aria-hidden="true"></span><?= h($_kl) ?>
  </a>
  <?php endforeach; ?>
</nav>

<?php if (empty($rows)): ?>
<div class="tz-empty">
  <i class="bi bi-megaphone" aria-hidden="true"></i>
  <p class="fw-semibold mb-1">Brak ogłoszeń<?= $_kat !== '' ? ' w tej kategorii' : '' ?></p>
  <p class="small mb-0"><a href="<?= APP_URL ?>/komunikaty/compose.php">Opublikuj pierwsze ogłoszenie</a>.</p>
</div>
<?php else: ?>
<div class="pv-table-wrap">
  <table class="pv-table">
    <caption class="visually-hidden">Lista wszystkich ogłoszeń organizacji</caption>
    <thead>
      <tr>
        <th scope="col">Tytuł</th>
        <th scope="col">Kategoria</th>
        <th scope="col">Odbiorca</th>
        <th scope="col" class="d-none d-md-table-cell">Autor</th>
        <th scope="col" class="d-none d-sm-table-cell">Utworzono</th>
        <th scope="col" class="text-center">Odczytania</th>
        <th scope="col" class="text-end"><span class="visually-hidden">Akcje</span></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r):
        $kc      = ann_kategoria_color($r['kategoria'] ?? 'ogolne');
        $expired = !empty($r['expires_at']) && $r['expires_at'] < $_today;
      ?>
      <tr>
        <td>
          <span class="d-inline-flex align-items-center gap-2">
            <?php if ((int)($r['is_pinned'] ?? 0)): ?>
            <i class="bi bi-pin-angle-fill" style="color:#F59E0B" title="Przypięte" aria-label="Przypięte"></i>
            <?php endif; ?>
            <span class="fw-semibold"><?= h($r['title']) ?></span>
          </span>
          <?php if ($expired): ?>
          <div><span class="tz-badge tz-badge--muted mt-1"><i class="bi bi-clock-history" aria-hidden="true"></i>Wygasło <?= h(date('d.m.Y', strtotime($r['expires_at']))) ?></span></div>
          <?php endif; ?>
        </td>
        <td>
          <span class="tz-badge" style="background:<?= h($kc) ?>1A;color:<?= h($kc) ?>;border-color:<?= h($kc) ?>55">
            <?= h(ann_kategoria_label($r['kategoria'] ?? 'ogolne')) ?>
          </span>
        </td>
        <td><?= h(ann_audience_label($r['audience'] ?? 'all')) ?></td>
        <td class="d-none d-md-table-cell" style="color:var(--tz-muted)"><?= h($r['author_name'] ?? '') ?></td>
        <td class="d-none d-sm-table-cell text-nowrap" style="color:var(--tz-muted)"><?= h(substr($r['created_at'] ?? '', 0, 16)) ?></td>
        <td class="text-center"><span class="tz-badge tz-badge--muted"><?= (int)($r['read_count'] ?? 0) ?></span></td>
        <td class="text-end text-nowrap">
          <a href="<?= APP_URL ?>/komunikaty/announcement.php?id=<?= (int)$r['id'] ?>"
             class="btn btn-sm btn-outline-secondary" aria-label="Podgląd ogłoszenia <?= h($r['title']) ?>">
            <i class="bi bi-eye" aria-hidden="true"></i>
          </a>
          <form method="post" class="d-inline" onsubmit="return confirm('Usunąć ogłoszenie „<?= h(addslashes($r['title'])) ?>”? Tej operacji nie można cofnąć.')">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="delete_ann" value="<?= (int)$r['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="Usuń ogłoszenie <?= h($r['title']) ?>">
              <i class="bi bi-trash3" aria-hidden="true"></i>
            </button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php require __DIR__ . '/_foot.php'; ?>
