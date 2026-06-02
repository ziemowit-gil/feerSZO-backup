<?php
/**
 * org_intro/view.php — Widok pojedynczej zasady/artykułu.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/org_rules.php';

require_login();
org_rules_migrate();

$id   = (int)($_GET['id'] ?? 0);
$rule = db_one("SELECT * FROM org_rules WHERE id=? AND status='active'", [$id]);
if (!$rule) {
    flash_set('danger', 'Zasada nie istnieje.');
    header('Location: index.php'); exit;
}

$uid       = (int)current_user()['id'];
$is_read   = org_rule_is_read($id, $uid);
$is_mand   = (bool)$rule['is_mandatory'];
$cat_cfg   = ORG_RULE_CATEGORIES[$rule['category']] ?? ['label'=>'Inne','icon'=>'bi-file-text','color'=>'#6B7280'];

$PAGE_TITLE = $rule['title'];

// POST: potwierdź
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_ack'])) {
    csrf_check();
    org_rule_acknowledge($id, $uid);
    flash_set('success', 'Potwierdzono przeczytanie „' . $rule['title'] . '".');
    header('Location: view.php?id=' . $id); exit;
}

include dirname(__DIR__) . '/includes/header.php';
?>

<nav aria-label="Ścieżka nawigacji" class="mb-3" style="font-size:.82rem">
  <ol class="breadcrumb mb-0">
    <li class="breadcrumb-item"><a href="index.php">Wprowadzenie do organizacji</a></li>
    <li class="breadcrumb-item"><a href="index.php?cat=<?= h($rule['category']) ?>"><?= h($cat_cfg['label']) ?></a></li>
    <li class="breadcrumb-item active" aria-current="page"><?= h($rule['title']) ?></li>
  </ol>
</nav>

<div class="row g-4">
<div class="col-lg-8">

  <!-- Nagłówek artykułu -->
  <div style="background:<?= h($rule['color']?:$cat_cfg['color']) ?>12;border:1.5px solid <?= h($rule['color']?:$cat_cfg['color']) ?>30;border-radius:12px;padding:1.25rem 1.5rem;margin-bottom:1.25rem">
    <div class="d-flex align-items-center gap-3 mb-2">
      <div style="width:44px;height:44px;border-radius:10px;background:<?= h($rule['color']?:$cat_cfg['color']) ?>;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0">
        <i class="bi <?= h($rule['icon'] ?? $cat_cfg['icon']) ?>" style="color:#fff" aria-hidden="true"></i>
      </div>
      <div>
        <h1 style="font-size:1.2rem;font-weight:800;margin:0;color:#111827"><?= h($rule['title']) ?></h1>
        <div style="font-size:.78rem;color:#6B7280;margin-top:.15rem">
          <?= h($cat_cfg['label']) ?> · Aktualizacja: <?= date_pl($rule['updated_at']) ?>
          <?php if ($is_mand): ?>
          · <span style="color:#DC2626;font-weight:600"><i class="bi bi-asterisk me-1" aria-hidden="true"></i>Obowiązkowe do przeczytania</span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php if ($is_read): ?>
    <div style="display:inline-flex;align-items:center;gap:.35rem;padding:.3rem .75rem;border-radius:2rem;background:#F0FDF4;color:#16A34A;border:1px solid #BBF7D0;font-size:.8rem;font-weight:600">
      <i class="bi bi-check-circle-fill" aria-hidden="true"></i>Przeczytano i potwierdzone
    </div>
    <?php endif; ?>
  </div>

  <!-- Treść -->
  <div class="card shadow-sm">
    <div class="card-body" style="font-size:.95rem;line-height:1.75;color:#1F2937">
      <?php if ($rule['content']): ?>
        <?= nl2br(h($rule['content'])) ?>
      <?php else: ?>
        <p class="text-muted fst-italic">Brak treści.</p>
      <?php endif; ?>
    </div>
  </div>

  <!-- Przycisk potwierdzenia -->
  <?php if ($is_mand && !$is_read): ?>
  <div class="mt-3 p-3 rounded-3" style="background:#FFF7ED;border:2px solid #FED7AA">
    <div class="d-flex align-items-start gap-3">
      <i class="bi bi-exclamation-triangle-fill text-warning fs-4 flex-shrink-0 mt-1" aria-hidden="true"></i>
      <div style="flex:1">
        <div class="fw-bold" style="color:#92400E">Ta zasada wymaga potwierdzenia</div>
        <div style="font-size:.85rem;color:#B45309;margin:.25rem 0 .75rem">
          Przeczytaj powyższy dokument, a następnie kliknij przycisk potwierdzenia.
          Twoje potwierdzenie wraz z adresem IP i datą zostaną zapisane w systemie.
        </div>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_ack"  value="1">
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="confirm_read" required
                   onchange="document.getElementById('ackBtn').disabled=!this.checked">
            <label class="form-check-label fw-semibold" for="confirm_read">
              Zapoznałem/am się z treścią i akceptuję powyższe zasady.
            </label>
          </div>
          <button type="submit" id="ackBtn" class="btn btn-success" disabled
                  aria-label="Potwierdź przeczytanie zasady: <?= h($rule['title']) ?>">
            <i class="bi bi-check-circle-fill me-2" aria-hidden="true"></i>
            Potwierdzam przeczytanie
          </button>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

</div>
<div class="col-lg-4">

  <!-- Nawigacja: poprzednia / następna -->
  <?php
  $prev = db_one("SELECT id, title FROM org_rules WHERE status='active' AND category=? AND (sort_order < ? OR (sort_order=? AND id < ?)) ORDER BY sort_order DESC, id DESC LIMIT 1",
    [$rule['category'], $rule['sort_order'], $rule['sort_order'], $id]);
  $next = db_one("SELECT id, title FROM org_rules WHERE status='active' AND category=? AND (sort_order > ? OR (sort_order=? AND id > ?)) ORDER BY sort_order, id LIMIT 1",
    [$rule['category'], $rule['sort_order'], $rule['sort_order'], $id]);
  ?>
  <?php if ($prev || $next): ?>
  <div class="card shadow-sm mb-3">
    <div class="card-body py-2">
      <div class="d-flex justify-content-between gap-2">
        <?php if ($prev): ?>
        <a href="view.php?id=<?= $prev['id'] ?>" class="btn btn-outline-secondary btn-sm text-start" style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= h($prev['title']) ?>">
          <i class="bi bi-arrow-left me-1" aria-hidden="true"></i><?= h(mb_substr($prev['title'],0,25)) ?>
        </a>
        <?php endif; ?>
        <?php if ($next): ?>
        <a href="view.php?id=<?= $next['id'] ?>" class="btn btn-outline-primary btn-sm text-end ms-auto" style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= h($next['title']) ?>">
          <?= h(mb_substr($next['title'],0,25)) ?><i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>
        </a>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Statystyki (admin) -->
  <?php if (can_edit()): ?>
  <?php $stats = org_rule_ack_stats($id); ?>
  <div class="card shadow-sm mb-3">
    <div class="card-header py-2 fw-semibold small">
      <i class="bi bi-bar-chart me-1"></i>Statystyki potwierdzenia
    </div>
    <div class="card-body py-2">
      <div class="d-flex justify-content-between mb-1" style="font-size:.82rem">
        <span class="text-muted">Przeczytało</span>
        <strong><?= $stats['read'] ?>/<?= $stats['total'] ?> (<?= $stats['pct'] ?>%)</strong>
      </div>
      <div style="background:#F3F4F6;border-radius:4px;height:8px">
        <div style="background:#16A34A;height:8px;border-radius:4px;width:<?= $stats['pct'] ?>%"></div>
      </div>
      <div class="mt-2 d-flex gap-2">
        <a href="<?= APP_URL ?>/admin/org_rules.php?acks=<?= $id ?>" class="btn btn-outline-secondary btn-sm w-100" style="font-size:.75rem">
          <i class="bi bi-people me-1" aria-hidden="true"></i>Lista potwierdzeń
        </a>
        <a href="<?= APP_URL ?>/admin/org_rules.php?edit=<?= $id ?>" class="btn btn-outline-primary btn-sm w-100" style="font-size:.75rem">
          <i class="bi bi-pencil me-1" aria-hidden="true"></i>Edytuj
        </a>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <a href="index.php" class="btn btn-outline-secondary btn-sm w-100">
    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Wróć do listy
  </a>

</div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
