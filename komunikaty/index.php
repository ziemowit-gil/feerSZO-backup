<?php
/**
 * komunikaty/index.php — Ogłoszenia organizacji + feed powiadomień użytkownika.
 *
 * Powłoka i komponenty: _shell.php (panel wolontariusza / powłoka SZO, język
 * wizualny modułu „Tożsamość").
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

// Oznaczenie pojedynczego ogłoszenia jako przeczytanego
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_ann'])) {
    csrf_check();
    ann_mark_read((int)$_POST['mark_ann'], $_uid);
    header('Location: ' . APP_URL . '/komunikaty/index.php');
    exit;
}

// Oznacz wszystkie jako przeczytane
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['mark_all_ann'])) {
    csrf_check();
    ann_mark_all_read($_uid, $_role);
    flash_set('success', 'Wszystkie ogłoszenia oznaczone jako przeczytane.');
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

$announcements = ann_list_for_user($_uid, $_role, $_kat);
$notifications = notif_latest($_uid, 20);
$notif_unread  = notif_unread_count($_uid);

$ann_unread_count = 0;
foreach ($announcements as $a) {
    if (!(int)($a['is_read_by_me'] ?? 0)) $ann_unread_count++;
}

require_once __DIR__ . '/_shell.php';

$_actions = '';
if (is_admin()) {
    $_actions =
        '<a href="' . APP_URL . '/komunikaty/compose.php" class="tz-btn">'
        . '<i class="bi bi-plus-lg" aria-hidden="true"></i>Nowe ogłoszenie</a> '
        . '<a href="' . APP_URL . '/komunikaty/manage.php" class="tz-btn tz-btn--ghost">'
        . '<i class="bi bi-sliders" aria-hidden="true"></i>Zarządzaj</a>';
}

pv_page_header('Komunikaty', [
    'icon'    => 'bi-megaphone',
    'sub'     => 'Ogłoszenia organizacji i Twoje powiadomienia',
    'actions' => $_actions,
]);
?>

<?php if ($ann_unread_count > 0): ?>
<div class="tz-note tz-note--info">
  <i class="bi bi-bell-fill" aria-hidden="true"></i>
  <div class="flex-grow-1">
    Masz <strong><?= $ann_unread_count ?></strong>
    <?= $ann_unread_count === 1 ? 'nieprzeczytane ogłoszenie' : ($ann_unread_count < 5 ? 'nieprzeczytane ogłoszenia' : 'nieprzeczytanych ogłoszeń') ?>.
  </div>
  <form method="post" class="flex-shrink-0">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="mark_all_ann" value="1">
    <button type="submit" class="tz-btn tz-btn--ghost kom-btn-sm">
      <i class="bi bi-check2-all" aria-hidden="true"></i>Oznacz wszystkie
    </button>
  </form>
</div>
<?php endif; ?>

<nav class="kom-chips" aria-label="Filtr kategorii">
  <a href="<?= APP_URL ?>/komunikaty/index.php" class="kom-chip"
     <?= $_kat === '' ? 'aria-current="page"' : '' ?>
     style="--kom-accent:var(--tz)">
    <span class="kom-chip__dot" aria-hidden="true"></span>Wszystkie
  </a>
  <?php foreach (ann_kategoria_options() as $_kk => $_kl): ?>
  <a href="<?= APP_URL ?>/komunikaty/index.php?kat=<?= urlencode($_kk) ?>" class="kom-chip"
     <?= $_kat === $_kk ? 'aria-current="page"' : '' ?>
     style="--kom-accent:<?= h(ann_kategoria_color($_kk)) ?>">
    <span class="kom-chip__dot" aria-hidden="true"></span><?= h($_kl) ?>
  </a>
  <?php endforeach; ?>
</nav>

<div class="row g-3">
  <!-- Lewa kolumna: ogłoszenia -->
  <div class="col-lg-8">
    <?php if (empty($announcements)): ?>
    <div class="tz-empty">
      <i class="bi bi-megaphone" aria-hidden="true"></i>
      <p class="fw-semibold mb-1">Brak aktywnych ogłoszeń</p>
      <p class="small mb-0"><?= $_kat === '' ? 'Gdy pojawi się nowe ogłoszenie, zobaczysz je tutaj.' : 'W tej kategorii nic teraz nie ma — sprawdź pozostałe.' ?></p>
    </div>
    <?php else: ?>
    <?php foreach ($announcements as $ann):
      $ann_id    = (int)$ann['id'];
      $is_pinned = (int)($ann['is_pinned'] ?? 0);
      $is_read   = (int)($ann['is_read_by_me'] ?? 0);
      $kat       = $ann['kategoria'] ?? 'ogolne';
      $kc        = ann_kategoria_color($kat);
      $accent    = $is_pinned ? '#F59E0B' : ($is_read ? 'var(--tz-line)' : $kc);
      $collapsed = mb_strlen((string)$ann['body']) > 300 && !$is_pinned;
      // Kolor gradientu wygaszenia musi zgadzać się z tłem karty
      $fade      = $is_read ? 'var(--tz-bg)' : 'var(--tz-bg-sub)';
    ?>
    <article class="tz-card kom-ann<?= $is_read ? '' : ' kom-ann--new' ?>"
             style="--kom-accent:<?= h($accent) ?>;--kom-fade:<?= $fade ?>"
             aria-labelledby="ann-ttl-<?= $ann_id ?>">
      <div class="tz-card__bd">

        <div class="kom-ann__top">
          <div class="kom-ann__meta">
            <?php if ($is_pinned): ?>
            <span class="tz-badge tz-badge--warning"><i class="bi bi-pin-angle-fill" aria-hidden="true"></i>Przypięte</span>
            <?php endif; ?>
            <?php if (!$is_read): ?>
            <span class="tz-badge" style="background:var(--tz);color:#fff;border-color:var(--tz)">Nowe</span>
            <?php endif; ?>
            <span class="tz-badge" style="background:<?= h($kc) ?>1A;color:<?= h($kc) ?>;border-color:<?= h($kc) ?>55">
              <?= h(ann_kategoria_label($kat)) ?>
            </span>
            <span class="tz-badge tz-badge--muted">
              <i class="bi bi-<?= ($ann['audience'] ?? '') === 'public' ? 'globe2' : 'people-fill' ?>" aria-hidden="true"></i>
              <?= h(ann_audience_label($ann['audience'] ?? 'all')) ?>
            </span>
            <?php if (!empty($ann['expires_at'])): ?>
            <span class="tz-badge tz-badge--wait">
              <i class="bi bi-clock" aria-hidden="true"></i>Wygasa <?= h(date('d.m.Y', strtotime($ann['expires_at']))) ?>
            </span>
            <?php endif; ?>
          </div>
          <span class="kom-ann__date"><?= h(date('d.m.Y', strtotime($ann['created_at'] ?? 'now'))) ?></span>
        </div>

        <h2 class="kom-ann__ttl" id="ann-ttl-<?= $ann_id ?>">
          <a href="<?= APP_URL ?>/komunikaty/announcement.php?id=<?= $ann_id ?>"><?= h($ann['title']) ?></a>
        </h2>

        <?php if ($ann['body']): ?>
        <div class="kom-body<?= $collapsed ? ' kom-body--clip' : '' ?>" id="ann-body-<?= $ann_id ?>"><?= h($ann['body']) ?></div>
        <?php if ($collapsed): ?>
        <button type="button" class="kom-more" data-target="ann-body-<?= $ann_id ?>"
                aria-expanded="false" aria-controls="ann-body-<?= $ann_id ?>">
          <i class="bi bi-chevron-down" aria-hidden="true"></i><span>Rozwiń treść</span>
        </button>
        <?php endif; ?>
        <?php endif; ?>
      </div>

      <div class="tz-card__ft kom-ann__ft">
        <span class="kom-ann__author"><i class="bi bi-person" aria-hidden="true"></i><?= h($ann['author_name'] ?? '') ?></span>
        <div class="kom-ann__acts">
          <a href="<?= APP_URL ?>/komunikaty/announcement.php?id=<?= $ann_id ?>" class="tz-btn tz-btn--ghost kom-btn-sm">
            <i class="bi bi-eye" aria-hidden="true"></i>Otwórz
          </a>
          <?php if (!$is_read): ?>
          <form method="post" class="d-inline">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="mark_ann" value="<?= $ann_id ?>">
            <button type="submit" class="tz-btn kom-btn-sm"><i class="bi bi-check2" aria-hidden="true"></i>Przeczytane</button>
          </form>
          <?php else: ?>
          <span class="tz-badge tz-badge--ok"><i class="bi bi-check2-all" aria-hidden="true"></i>Przeczytane</span>
          <?php endif; ?>
          <?php if (is_admin()): ?>
          <form method="post" class="d-inline" onsubmit="return confirm('Usunąć ogłoszenie „<?= h(addslashes($ann['title'])) ?>”? Tej operacji nie można cofnąć.')">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="delete_ann" value="<?= $ann_id ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger" title="Usuń"
                    aria-label="Usuń ogłoszenie <?= h($ann['title']) ?>">
              <i class="bi bi-trash3" aria-hidden="true"></i>
            </button>
          </form>
          <?php endif; ?>
        </div>
      </div>
    </article>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Prawa kolumna: feed powiadomień -->
  <div class="col-lg-4">
    <section class="tz-card" style="position:sticky;top:1rem" aria-labelledby="kom-notif-h">
      <div class="tz-card__hd">
        <i class="bi bi-bell" aria-hidden="true"></i>
        <span id="kom-notif-h">Powiadomienia</span>
        <?php if ($notif_unread > 0): ?>
        <span class="tz-badge tz-badge--muted ms-auto"><?= (int)$notif_unread ?> nowe</span>
        <?php endif; ?>
      </div>
      <div class="kom-notif">
        <?php if (empty($notifications)): ?>
        <div class="pv-empty" style="padding:2rem 1rem">
          <i class="bi bi-bell-slash" aria-hidden="true"></i>
          <div class="pv-empty-sub mt-0">Brak powiadomień</div>
        </div>
        <?php else: ?>
        <?php foreach ($notifications as $n):
          $is_read_n = (int)($n['is_read'] ?? 0);
          $n_color   = notif_type_color($n['type'] ?? 'system');
        ?>
        <a href="<?= h($n['url'] ?: APP_URL . '/komunikaty/index.php') ?>"
           class="kom-notif-row notif-item<?= $is_read_n ? '' : ' kom-notif-row--new' ?>"
           data-notif-id="<?= (int)$n['id'] ?>">
          <span class="kom-notif-ico" style="background:<?= h($n_color) ?>22;color:<?= h($n_color) ?>">
            <i class="bi <?= notif_type_icon($n['type'] ?? 'system') ?>" aria-hidden="true"></i>
          </span>
          <span class="flex-grow-1" style="min-width:0">
            <span class="d-block" style="word-break:break-word"><?= h($n['title']) ?></span>
            <span class="kom-notif-date"><?= h(substr($n['created_at'] ?? '', 0, 16)) ?></span>
          </span>
          <?php if (!$is_read_n): ?>
          <span class="kom-notif-dot" aria-hidden="true"></span>
          <span class="visually-hidden">nieprzeczytane</span>
          <?php endif; ?>
        </a>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
      <?php if ($notif_unread > 0): ?>
      <div class="tz-card__ft">
        <button type="button" class="tz-btn tz-btn--ghost kom-btn-sm w-100 justify-content-center" id="markAllRead">
          <i class="bi bi-check2-all" aria-hidden="true"></i>Oznacz wszystkie powiadomienia
        </button>
      </div>
      <?php endif; ?>
    </section>
  </div>
</div>

<script>
// Rozwijanie skróconej treści ogłoszenia
document.querySelectorAll('.kom-more').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var box = document.getElementById(btn.dataset.target);
    if (!box) return;
    var open = box.classList.toggle('is-open');
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    btn.querySelector('span').textContent = open ? 'Zwiń' : 'Rozwiń treść';
    var ico = btn.querySelector('i');
    ico.classList.toggle('bi-chevron-down', !open);
    ico.classList.toggle('bi-chevron-up', open);
  });
});

// Kliknięcie w powiadomienie → oznacz jako przeczytane
document.querySelectorAll('[data-notif-id]').forEach(function (el) {
  el.addEventListener('click', function () {
    fetch(APP_URL + '/api/notifications/mark_read.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
      body: JSON.stringify({id: parseInt(el.dataset.notifId)})
    });
  });
});

// Oznacz wszystkie powiadomienia
var markAllBtn = document.getElementById('markAllRead');
if (markAllBtn) {
  markAllBtn.addEventListener('click', function () {
    fetch(APP_URL + '/api/notifications/mark_read.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
      body: JSON.stringify({all: true})
    }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d.ok) return;
      document.querySelectorAll('.notif-item').forEach(function (el) {
        el.classList.remove('kom-notif-row--new');
        var dot = el.querySelector('.kom-notif-dot');
        if (dot) dot.remove();
      });
      markAllBtn.closest('.tz-card__ft').remove();
    });
  });
}
</script>

<?php require __DIR__ . '/_foot.php'; ?>
