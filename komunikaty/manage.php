<?php
/**
 * komunikaty/manage.php — Zarządzanie ogłoszeniami (admin).
 *
 * Pełna lista ogłoszeń niezależna od audytorium bieżącego admina
 * (ann_list_for_user() pokazuje tylko to, co widzi zalogowany użytkownik —
 * tu widać wszystko, co zostało wysłane). Filtr po kategorii + usuwanie.
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_ann'])) {
    csrf_check();
    ann_delete((int)$_POST['delete_ann']);
    flash_set('success', 'Ogłoszenie zostało usunięte.');
    header('Location: ' . APP_URL . '/komunikaty/manage.php' . (!empty($_GET['kat']) ? '?kat=' . urlencode($_GET['kat']) : ''));
    exit;
}

$PAGE_TITLE = 'Zarządzanie ogłoszeniami';
$_kat = trim($_GET['kat'] ?? '');
if (!array_key_exists($_kat, ann_kategoria_options())) $_kat = '';

$rows = ann_all_list($_kat);

require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-3">
  <a href="<?= APP_URL ?>/komunikaty/index.php" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left"></i>
  </a>
  <h5 class="mb-0 fw-semibold"><i class="bi bi-megaphone me-2 text-warning"></i>Zarządzanie ogłoszeniami</h5>
  <a href="<?= APP_URL ?>/komunikaty/compose.php" class="btn btn-sm btn-primary ms-auto">
    <i class="bi bi-plus-lg me-1"></i>Nowe ogłoszenie
  </a>
</div>

<div class="d-flex flex-wrap gap-2 mb-3">
  <a href="<?= APP_URL ?>/komunikaty/manage.php"
     class="badge text-decoration-none <?= $_kat === '' ? 'bg-dark' : 'bg-light text-secondary border' ?>"
     style="font-size:.75rem;padding:.4rem .7rem">Wszystkie</a>
  <?php foreach (ann_kategoria_options() as $_kk => $_kl):
    $_active = $_kat === $_kk;
    $_color  = ann_kategoria_color($_kk);
  ?>
  <a href="<?= APP_URL ?>/komunikaty/manage.php?kat=<?= urlencode($_kk) ?>"
     class="badge text-decoration-none"
     style="font-size:.75rem;padding:.4rem .7rem;<?= $_active ? "background:{$_color};color:#fff" : "background:{$_color}1A;color:{$_color};border:1px solid {$_color}55" ?>">
    <?= h($_kl) ?>
  </a>
  <?php endforeach; ?>
</div>

<div class="card border-0 shadow-sm rounded-3">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Tytuł</th>
          <th>Kategoria</th>
          <th>Odbiorca</th>
          <th class="d-none d-md-table-cell">Autor</th>
          <th class="d-none d-sm-table-cell">Utworzono</th>
          <th class="text-center">Odczytania</th>
          <th class="text-end">Akcje</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
        <tr><td colspan="7" class="text-center text-muted py-4">Brak ogłoszeń w tej kategorii.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r):
          $kc = ann_kategoria_color($r['kategoria'] ?? 'ogolne');
        ?>
        <tr>
          <td>
            <?php if ((int)($r['is_pinned'] ?? 0)): ?><i class="bi bi-pin-angle-fill text-warning me-1" title="Przypięte"></i><?php endif; ?>
            <?= h($r['title']) ?>
          </td>
          <td>
            <span class="badge" style="font-size:.68rem;background:<?= $kc ?>1A;color:<?= $kc ?>;border:1px solid <?= $kc ?>55">
              <?= h(ann_kategoria_label($r['kategoria'] ?? 'ogolne')) ?>
            </span>
          </td>
          <td style="font-size:.82rem"><?= h(ann_audience_label($r['audience'] ?? 'all')) ?></td>
          <td class="d-none d-md-table-cell text-muted" style="font-size:.82rem"><?= h($r['author_name'] ?? '') ?></td>
          <td class="d-none d-sm-table-cell text-muted" style="font-size:.8rem;white-space:nowrap"><?= h(substr($r['created_at'] ?? '', 0, 16)) ?></td>
          <td class="text-center"><span class="badge bg-light text-secondary border"><?= (int)($r['read_count'] ?? 0) ?></span></td>
          <td class="text-end">
            <a href="<?= APP_URL ?>/komunikaty/announcement.php?id=<?= (int)$r['id'] ?>"
               class="btn btn-sm btn-outline-secondary" style="font-size:.78rem" title="Podgląd">
              <i class="bi bi-eye"></i>
            </a>
            <form method="post" class="d-inline" onsubmit="return confirm('Usunąć ogłoszenie „<?= h(addslashes($r['title'])) ?>”? Tej operacji nie można cofnąć.')">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="delete_ann" value="<?= (int)$r['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger" style="font-size:.78rem" title="Usuń">
                <i class="bi bi-trash3"></i>
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
