<?php
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

// Obsługa oznaczania ogłoszenia jako przeczytanego (prosty POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_ann'])) {
    csrf_check();
    ann_mark_read((int)$_POST['mark_ann'], $_uid);
    header('Location: ' . APP_URL . '/komunikaty/index.php');
    exit;
}

// Usunięcie ogłoszenia (tylko admin) — miękkie, is_active=0
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_ann'])) {
    csrf_check();
    if (is_admin()) {
        ann_delete((int)$_POST['delete_ann']);
        flash_set('success', 'Ogłoszenie zostało usunięte.');
    }
    header('Location: ' . APP_URL . '/komunikaty/index.php');
    exit;
}

$PAGE_TITLE = 'Komunikaty';
$_kat = trim($_GET['kat'] ?? '');
if (!array_key_exists($_kat, ann_kategoria_options())) $_kat = '';

$announcements  = ann_list_for_user($_uid, $_role, $_kat);
$notifications  = notif_latest($_uid, 20);
$notif_unread   = notif_unread_count($_uid);

$ann_unread_count = 0;
foreach ($announcements as $a) {
    if (!(int)($a['is_read_by_me'] ?? 0)) $ann_unread_count++;
}

// Powłoka: panel wolontariusza dla samych wolontariuszy, powłoka admina dla reszty
// (edytorzy/admini potrzebują pełnej nawigacji — wzorzec jak panel/rodo.php)
$_is_volunteer_only = is_viewer() && !db_one("SELECT id FROM users WHERE id=? AND k30_consultant=1", [$_uid]);

if ($_is_volunteer_only) {
    include dirname(__DIR__) . '/panel/includes/header_panel.php';
} else {
    include dirname(__DIR__) . '/includes/header.php';
}
require_once dirname(__DIR__) . '/panel/includes/pv_ui.php';

$_compose_btn = is_admin()
    ? '<a href="' . APP_URL . '/komunikaty/compose.php" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Nowe ogłoszenie</a>'
      . ' <a href="' . APP_URL . '/komunikaty/manage.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-gear me-1"></i>Zarządzaj</a>'
    : '';

pv_page_header('Komunikaty', [
    'icon'    => 'bi-megaphone',
    'sub'     => 'Ogłoszenia organizacji i Twoje powiadomienia',
    'actions' => $_compose_btn,
]);
?>

<?php if ($ann_unread_count > 0): ?>
<div class="pv-note">
  <i class="bi bi-bell-fill"></i>
  <div>Masz <strong><?= $ann_unread_count ?></strong> nieprzeczytane<?= $ann_unread_count === 1 ? '' : ($ann_unread_count < 5 ? ' ogłoszenia' : ' ogłoszeń') ?>.</div>
</div>
<?php endif; ?>

<div class="d-flex flex-wrap gap-2 mb-3">
  <a href="<?= APP_URL ?>/komunikaty/index.php"
     class="badge text-decoration-none <?= $_kat === '' ? 'bg-dark' : 'bg-light text-secondary border' ?>"
     style="font-size:.75rem;padding:.4rem .7rem">Wszystkie</a>
  <?php foreach (ann_kategoria_options() as $_kk => $_kl):
    $_active = $_kat === $_kk;
    $_color  = ann_kategoria_color($_kk);
  ?>
  <a href="<?= APP_URL ?>/komunikaty/index.php?kat=<?= urlencode($_kk) ?>"
     class="badge text-decoration-none"
     style="font-size:.75rem;padding:.4rem .7rem;<?= $_active ? "background:{$_color};color:#fff" : "background:{$_color}1A;color:{$_color};border:1px solid {$_color}55" ?>">
    <?= h($_kl) ?>
  </a>
  <?php endforeach; ?>
</div>

<div class="row g-3">
  <!-- Lewa kolumna: ogłoszenia -->
  <div class="col-lg-8">
    <?php if (empty($announcements)): ?>
    <div class="pv-empty">
      <i class="bi bi-megaphone" aria-hidden="true"></i>
      <div class="pv-empty-title">Brak aktywnych ogłoszeń</div>
    </div>
    <?php else: ?>
    <?php foreach ($announcements as $ann):
      $is_pinned  = (int)($ann['is_pinned']     ?? 0);
      $is_read    = (int)($ann['is_read_by_me'] ?? 0);
      $ann_id     = (int)$ann['id'];
      $body_len   = mb_strlen($ann['body']);
      $collapsed  = $body_len > 300 && !$is_pinned;
      $border_clr = $is_pinned ? '#F59E0B' : ($is_read ? '#E2E8F0' : 'var(--vol-color,#2563EB)');
      $bg_clr     = $is_pinned ? '#FFFBEB' : ($is_read ? '' : 'var(--vol-bg,#EFF6FF)');
    ?>
    <div class="pv-card"
         style="border-left:3px solid <?= $border_clr ?>!important;<?= $bg_clr ? 'background:'.$bg_clr : '' ?>">
      <div class="pv-card-bd pb-2">

        <!-- Nagłówek karty -->
        <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
          <div class="d-flex align-items-center gap-2 flex-wrap">
            <?php if ($is_pinned): ?>
            <span class="badge" style="background:#F59E0B;font-size:.68rem"><i class="bi bi-pin-angle-fill me-1"></i>Przypięte</span>
            <?php endif; ?>
            <?php if (!$is_read): ?>
            <span class="badge" style="background:var(--vol-color,#2563EB);font-size:.68rem">Nowe</span>
            <?php endif; ?>
            <span class="badge bg-light text-secondary border" style="font-size:.67rem">
              <i class="bi bi-<?= ($ann['audience'] ?? '') === 'public' ? 'globe2' : 'people-fill' ?> me-1"></i><?= h(ann_audience_label($ann['audience'] ?? 'all')) ?>
            </span>
            <?php $_kc = ann_kategoria_color($ann['kategoria'] ?? 'ogolne'); ?>
            <span class="badge" style="font-size:.67rem;background:<?= $_kc ?>1A;color:<?= $_kc ?>;border:1px solid <?= $_kc ?>55">
              <?= h(ann_kategoria_label($ann['kategoria'] ?? 'ogolne')) ?>
            </span>
            <?php if (!empty($ann['expires_at'])): ?>
            <span class="badge bg-light text-warning border" style="font-size:.67rem">
              <i class="bi bi-clock me-1"></i>Wygasa: <?= h(date('d.m.Y', strtotime($ann['expires_at']))) ?>
            </span>
            <?php endif; ?>
          </div>
          <span class="text-muted" style="font-size:.73rem;white-space:nowrap"><?= h(date('d.m.Y', strtotime($ann['created_at'] ?? 'now'))) ?></span>
        </div>

        <!-- Tytuł -->
        <h2 class="<?= !$is_read ? 'fw-bold' : 'fw-semibold' ?> mb-2" style="font-size:.95rem">
          <?= h($ann['title']) ?>
        </h2>

        <!-- Treść — pełna lub kolapsowalna -->
        <?php if ($ann['body']): ?>
        <?php if ($collapsed): ?>
        <div class="ann-body-collapse" id="ann-body-<?= $ann_id ?>" style="overflow:hidden;max-height:5.5rem;position:relative;transition:max-height .3s ease">
          <div style="font-size:.875rem;color:#374151;line-height:1.65;white-space:pre-wrap"><?= h($ann['body']) ?></div>
          <div class="ann-fade" id="ann-fade-<?= $ann_id ?>" style="position:absolute;bottom:0;left:0;right:0;height:2rem;background:linear-gradient(transparent,<?= $bg_clr ?: '#fff' ?>)"></div>
        </div>
        <button type="button" class="btn btn-link btn-sm p-0 mt-1 ann-toggle" data-id="<?= $ann_id ?>"
                style="font-size:.8rem;text-decoration:none;color:var(--vol-color,#2563EB)">
          <i class="bi bi-chevron-down me-1" id="ann-icon-<?= $ann_id ?>"></i><span id="ann-lbl-<?= $ann_id ?>">Rozwiń treść</span>
        </button>
        <?php else: ?>
        <div style="font-size:.875rem;color:#374151;line-height:1.65;white-space:pre-wrap"><?= h($ann['body']) ?></div>
        <?php endif; ?>
        <?php endif; ?>

        <!-- Stopka karty -->
        <div class="d-flex align-items-center justify-content-between mt-3 pt-2 border-top flex-wrap gap-2">
          <small class="text-muted">
            <i class="bi bi-person me-1"></i><?= h($ann['author_name'] ?? '') ?>
          </small>
          <div class="d-flex gap-2 align-items-center">
            <a href="<?= APP_URL ?>/komunikaty/announcement.php?id=<?= $ann_id ?>"
               class="btn btn-sm btn-outline-secondary" style="font-size:.78rem;padding:.2rem .65rem">
              <i class="bi bi-eye me-1"></i>Otwórz
            </a>
            <?php if (!$is_read): ?>
            <form method="post" class="d-inline">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="mark_ann" value="<?= $ann_id ?>">
              <button type="submit" class="btn btn-sm" style="background:var(--vol-color,#2563EB);color:#fff;font-size:.78rem;padding:.2rem .65rem">
                <i class="bi bi-check2 me-1"></i>Przeczytane
              </button>
            </form>
            <?php else: ?>
            <small class="text-success" style="font-size:.78rem"><i class="bi bi-check2-all me-1"></i>Przeczytane</small>
            <?php endif; ?>
            <?php if (is_admin()): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Usunąć ogłoszenie „<?= h(addslashes($ann['title'])) ?>”? Tej operacji nie można cofnąć.')">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="delete_ann" value="<?= $ann_id ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger" style="font-size:.78rem;padding:.2rem .5rem" title="Usuń" aria-label="Usuń ogłoszenie">
                <i class="bi bi-trash3"></i>
              </button>
            </form>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Prawa kolumna: feed powiadomień -->
  <div class="col-lg-4">
    <div class="vol-detail-card" style="position:sticky;top:1rem">
      <div class="vol-detail-header justify-content-between">
        <span><i class="bi bi-bell me-1" aria-hidden="true"></i>Powiadomienia</span>
        <?php if ($notif_unread > 0): ?>
        <button type="button" class="btn btn-link btn-sm p-0 text-muted" style="font-size:.75rem" id="markAllRead">
          Oznacz wszystkie
        </button>
        <?php endif; ?>
      </div>
      <div style="max-height:520px;overflow-y:auto">
        <?php if (empty($notifications)): ?>
        <div class="pv-empty" style="padding:2rem 1rem">
          <i class="bi bi-bell-slash" style="font-size:1.5rem" aria-hidden="true"></i>
          <div class="pv-empty-sub mt-0">Brak powiadomień</div>
        </div>
        <?php else: ?>
        <?php foreach ($notifications as $n):
          $is_read_n = (int)($n['is_read'] ?? 0);
          $n_color   = notif_type_color($n['type'] ?? 'system');
        ?>
        <a href="<?= h($n['url'] ?: APP_URL . '/komunikaty/index.php') ?>"
           class="vol-activity-row text-decoration-none notif-item <?= $is_read_n ? '' : 'fw-semibold' ?>"
           style="<?= $is_read_n ? 'color:#374151' : 'background:var(--vol-bg,#f0f5ff);color:#1e293b' ?>"
           data-notif-id="<?= (int)$n['id'] ?>">
          <div class="vol-activity-icon" style="background:<?= h($n_color) ?>22;color:<?= h($n_color) ?>">
            <i class="bi <?= notif_type_icon($n['type'] ?? 'system') ?>" aria-hidden="true"></i>
          </div>
          <div class="flex-grow-1" style="min-width:0">
            <div style="word-break:break-word"><?= h($n['title']) ?></div>
            <div class="fw-normal" style="font-size:.73rem;color:#64748b"><?= h(substr($n['created_at'] ?? '', 0, 16)) ?></div>
          </div>
          <?php if (!$is_read_n): ?>
          <span class="rounded-circle flex-shrink-0" style="width:7px;height:7px;background:var(--vol-color,#2563EB)"></span>
          <?php endif; ?>
        </a>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
      <div class="vol-detail-body border-top">
        <a href="<?= APP_URL ?>/komunikaty/index.php" class="btn btn-sm w-100"
           style="background:#f1f5f9;color:#374151;font-size:.8rem">
          <i class="bi bi-envelope-open me-1"></i>Odśwież
        </a>
      </div>
    </div>
  </div>
</div>

<script>
// Kolapsowanie treści ogłoszeń
document.querySelectorAll('.ann-toggle').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var id   = btn.dataset.id;
    var wrap = document.getElementById('ann-body-' + id);
    var fade = document.getElementById('ann-fade-' + id);
    var icon = document.getElementById('ann-icon-' + id);
    var lbl  = document.getElementById('ann-lbl-' + id);
    if (!wrap) return;
    var expanded = wrap.style.maxHeight !== '5.5rem';
    if (expanded) {
      wrap.style.maxHeight = '5.5rem';
      if (fade) fade.style.display = '';
      if (icon) { icon.classList.remove('bi-chevron-up'); icon.classList.add('bi-chevron-down'); }
      if (lbl)  lbl.textContent = 'Rozwiń treść';
    } else {
      wrap.style.maxHeight = wrap.scrollHeight + 'px';
      if (fade) fade.style.display = 'none';
      if (icon) { icon.classList.remove('bi-chevron-down'); icon.classList.add('bi-chevron-up'); }
      if (lbl)  lbl.textContent = 'Zwiń';
    }
  });
});

// Kliknięcie w powiadomienie → oznacz jako przeczytane
document.querySelectorAll('[data-notif-id]').forEach(function(el) {
  el.addEventListener('click', function() {
    var id = parseInt(el.dataset.notifId);
    fetch(APP_URL + '/api/notifications/mark_read.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
      body: JSON.stringify({id: id})
    });
  });
});

// Oznacz wszystkie
var markAllBtn = document.getElementById('markAllRead');
if (markAllBtn) {
  markAllBtn.addEventListener('click', function() {
    fetch(APP_URL + '/api/notifications/mark_read.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
      body: JSON.stringify({all: true})
    }).then(function(r) { return r.json(); }).then(function(d) {
      if (d.ok) {
        document.querySelectorAll('.notif-item').forEach(function(el) {
          el.classList.remove('fw-semibold');
          el.style.background = '';
          el.style.color = '#374151';
          var dot = el.querySelector('.rounded-circle');
          if (dot) dot.remove();
        });
        markAllBtn.remove();
      }
    });
  });
}
</script>

<?php if ($_is_volunteer_only): ?>
<?php include dirname(__DIR__) . '/panel/includes/footer_panel.php'; ?>
<?php else: ?>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
<?php endif; ?>
