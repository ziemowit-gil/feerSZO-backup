<?php
/**
 * komunikaty/index.php — Ogłoszenia organizacji + feed powiadomień użytkownika.
 *
 * Powłoka: _shell.php (panel współpracownika / powłoka SZO). Wygląd METRO jak System
 * Tożsamości i pulpit panelu — wspólny zestaw modules/panel_metro: kafle liczników,
 * zakładki pivot „ogłoszenia / powiadomienia”, filtr kategorii, karty z paskiem koloru.
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
require_once dirname(__DIR__) . '/modules/panel_metro/lib/panelMetro.php';
panelMetroAssets($_vol_color ?? '#0f6fa8');

$_ann_total = count($announcements);
$_imp_count = count(array_filter($announcements, fn($a) => ($a['display_mode'] ?? 'feed') === 'important'));
$_kat_all   = ann_kategoria_options();
?>
<style>
/* ── Komunikaty w METRO: filtr kategorii, karta ogłoszenia, powiadomienia ── */
.pvm .kom-cats{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 1.1rem}
.pvm .kom-cat{display:inline-flex;align-items:center;gap:.5rem;padding:.45rem .85rem;background:var(--pvm-light);color:var(--pvm-ink);text-decoration:none;font-size:.86rem;font-weight:500;min-height:40px;border-left:6px solid var(--c)}
.pvm .kom-cat:hover{background:var(--pvm-light-h);color:var(--pvm-ink)}
.pvm .kom-cat[aria-current="page"]{background:var(--c);color:var(--on,#fff);font-weight:600}
.pvm .kom-ann{background:var(--pvm-light);border-left:8px solid var(--c);padding:1rem 1.15rem;margin-bottom:8px;min-width:0}
.pvm .kom-ann--read{border-left-color:var(--pvm-line)}
.pvm .kom-ann__meta{display:flex;flex-wrap:wrap;align-items:center;gap:.35rem .7rem;font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--pvm-muted);margin-bottom:.35rem}
.pvm .kom-ann__ttl{margin:0;font-size:1.15rem;font-weight:600;line-height:1.3}
.pvm .kom-ann__ttl a{color:var(--pvm-ink);text-decoration:none}
.pvm .kom-ann__ttl a:hover,.pvm .kom-ann__ttl a:focus-visible{text-decoration:underline}
.pvm .kom-body{margin-top:.45rem;font-size:.92rem;line-height:1.6;color:var(--pvm-ink);white-space:pre-wrap;word-break:break-word}
.pvm .kom-body--clip{max-height:5.2rem;overflow:hidden;-webkit-mask-image:linear-gradient(#000 60%,transparent);mask-image:linear-gradient(#000 60%,transparent)}
.pvm .kom-body--clip.is-open{max-height:none;-webkit-mask-image:none;mask-image:none}
.pvm .kom-more{background:none;border:0;padding:.2rem 0;margin-top:.3rem;font:inherit;font-size:.85rem;font-weight:600;color:var(--pvm-ink);text-decoration:underline;text-underline-offset:3px;cursor:pointer;display:inline-flex;align-items:center;gap:.3rem}
.pvm .kom-ann__ft{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.6rem;margin-top:.8rem}
.pvm .kom-ann__author{font-size:.8rem;color:var(--pvm-muted)}
.pvm .kom-ann__acts{display:flex;flex-wrap:wrap;gap:6px;align-items:center}
.pvm .mt-btn--sm{min-height:36px;padding:.3rem .8rem;font-size:.8rem}
.pvm .mt-btn--danger{background:transparent;color:var(--m-red);border-color:var(--m-red)}
.pvm .mt-btn--danger:hover{background:var(--m-red);color:#fff;filter:none}
.pvm .mt-feed a{color:inherit;text-decoration:none;display:flex;gap:.75rem;flex:1;min-width:0}
.pvm .mt-feed li:hover{background:var(--pvm-light-h)}
.pvm .mt-feed .av{background:var(--c,var(--m-grey))}
:root[data-theme="hc"] .pvm .kom-cat{border:2px solid #000;border-left-width:6px}
:root[data-theme="hc"] .pvm .kom-cat[aria-current="page"]{background:#000;color:#fff}
:root[data-theme="hc"] .pvm .kom-ann{border:2px solid #000;border-left-width:8px}
</style>

<div class="pvm">

  <div class="mt-head">
    <div>
      <div class="mt-kicker">Komunikaty · <?= h(defined('ORG_NAME') ? ORG_NAME : '') ?></div>
      <h1 class="mt-title">Komunikaty</h1>
      <p class="mt-lead">Ogłoszenia organizacji i Twoje powiadomienia w jednym miejscu.</p>
    </div>
    <?php if (is_admin()): ?>
    <div class="d-flex flex-wrap gap-2">
      <a href="<?= APP_URL ?>/komunikaty/compose.php" class="mt-btn"><i class="bi bi-plus-lg" aria-hidden="true"></i>Nowe ogłoszenie</a>
      <a href="<?= APP_URL ?>/komunikaty/manage.php" class="mt-btn mt-btn--ghost"><i class="bi bi-sliders" aria-hidden="true"></i>Zarządzaj</a>
    </div>
    <?php endif; ?>
  </div>

  <?= function_exists('flash_html') ? flash_html() : '' ?>

  <!-- ═══════════ KAFLE (Start) ═══════════ -->
  <h2 class="visually-hidden">Podsumowanie</h2>
  <div class="mt-grid mt-grid--sm">
    <?php
    panelMetroTile(['href'=>'#ogloszenia','go'=>'ogloszenia','icon'=>'bi-megaphone','label'=>'Nieprzeczytane ogłoszenia','val'=>(string)$ann_unread_count,
                    'sub'=>$ann_unread_count ? 'do przeczytania' : 'wszystko przeczytane','color'=>$ann_unread_count ? 'orange' : 'green','size'=>'w']);
    panelMetroTile(['href'=>'#powiadomienia','go'=>'powiadomienia','icon'=>'bi-bell','label'=>'Powiadomienia','val'=>(string)$notif_unread,
                    'sub'=>$notif_unread ? 'nowych' : 'brak nowych','color'=>$notif_unread ? 'indigo' : 'blue']);
    panelMetroTile(['href'=>'#ogloszenia','go'=>'ogloszenia','icon'=>'bi-exclamation-square','label'=>'Ważne informacje','val'=>(string)$_imp_count,
                    'sub'=>'na pulpicie panelu','color'=>$_imp_count ? 'red' : 'grey']);
    panelMetroTile(['href'=>APP_URL.'/komunikaty/index.php','icon'=>'bi-collection','label'=>$_kat === '' ? 'Wszystkie ogłoszenia' : ann_kategoria_label($_kat),
                    'val'=>(string)$_ann_total,'sub'=>$_kat === '' ? 'aktywne' : 'w tej kategorii','color'=>'teal']);
    if (is_admin()) {
        panelMetroTile(['href'=>APP_URL.'/komunikaty/compose.php','icon'=>'bi-plus-square','label'=>'Nowe ogłoszenie','sub'=>'lista, ważna info, baner, popup','color'=>'dark']);
    }
    ?>
  </div>

  <!-- ═══════════ PIVOT ═══════════ -->
  <div class="mt-pivot" role="tablist" aria-label="Sekcje komunikatów" data-pvm-pivot>
    <button type="button" role="tab" id="pvm-tab-ogloszenia" data-tab="ogloszenia" aria-controls="ogloszenia" aria-selected="true" tabindex="0">ogłoszenia<?php if ($ann_unread_count): ?><span class="cnt" aria-label="<?= $ann_unread_count ?> nieprzeczytanych"><?= $ann_unread_count ?></span><?php endif; ?></button>
    <button type="button" role="tab" id="pvm-tab-powiadomienia" data-tab="powiadomienia" aria-controls="powiadomienia" aria-selected="false" tabindex="-1">powiadomienia<?php if ($notif_unread): ?><span class="cnt" aria-label="<?= (int)$notif_unread ?> nowych"><?= (int)$notif_unread ?></span><?php endif; ?></button>
  </div>

  <!-- ═══════════ OGŁOSZENIA ═══════════ -->
  <section id="ogloszenia" data-pvm-panel="ogloszenia" role="tabpanel" aria-labelledby="pvm-tab-ogloszenia" tabindex="-1">
    <nav class="kom-cats" aria-label="Filtr kategorii">
      <?php $_acc = $_vol_color ?? '#0f6fa8'; ?>
      <a href="<?= APP_URL ?>/komunikaty/index.php" class="kom-cat" style="--c:<?= h($_acc) ?>;--on:<?= h(panelMetroOnColor($_acc)) ?>"<?= $_kat === '' ? ' aria-current="page"' : '' ?>>Wszystkie</a>
      <?php foreach ($_kat_all as $_kk => $_kl): $_kc = ann_kategoria_color($_kk); ?>
      <a href="<?= APP_URL ?>/komunikaty/index.php?kat=<?= urlencode($_kk) ?>" class="kom-cat" style="--c:<?= h($_kc) ?>;--on:<?= h(panelMetroOnColor($_kc)) ?>"<?= $_kat === $_kk ? ' aria-current="page"' : '' ?>><?= h($_kl) ?></a>
      <?php endforeach; ?>
    </nav>

    <?php if ($ann_unread_count > 0): ?>
    <div class="mt-note">
      <i class="bi bi-bell-fill" aria-hidden="true"></i>
      <span style="flex:1">Masz <strong><?= $ann_unread_count ?></strong> <?= $ann_unread_count === 1 ? 'nieprzeczytane ogłoszenie' : ($ann_unread_count < 5 ? 'nieprzeczytane ogłoszenia' : 'nieprzeczytanych ogłoszeń') ?>.</span>
      <form method="post" class="m-0">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="mark_all_ann" value="1">
        <button type="submit" class="mt-btn mt-btn--ghost mt-btn--sm"><i class="bi bi-check2-all" aria-hidden="true"></i>Oznacz wszystkie</button>
      </form>
    </div>
    <?php endif; ?>

    <?php if (empty($announcements)): ?>
    <div class="mt-empty">
      <i class="bi bi-megaphone" style="font-size:2rem;display:block;margin-bottom:.5rem" aria-hidden="true"></i>
      <p class="fw-semibold mb-1" style="color:var(--pvm-ink)">Brak aktywnych ogłoszeń</p>
      <p class="small mb-0"><?= $_kat === '' ? 'Gdy pojawi się nowe ogłoszenie, zobaczysz je tutaj.' : 'W tej kategorii nic teraz nie ma — sprawdź pozostałe.' ?></p>
    </div>
    <?php else: ?>
    <?php foreach ($announcements as $ann):
      $ann_id    = (int)$ann['id'];
      $is_pinned = (int)($ann['is_pinned'] ?? 0);
      $is_read   = (int)($ann['is_read_by_me'] ?? 0);
      $is_imp    = ($ann['display_mode'] ?? 'feed') === 'important';
      $kat       = $ann['kategoria'] ?? 'ogolne';
      $collapsed = mb_strlen((string)$ann['body']) > 300 && !$is_pinned && !$is_imp;
    ?>
    <article class="kom-ann<?= ($is_read && !$is_imp) ? ' kom-ann--read' : '' ?>" style="--c:<?= h(ann_kategoria_color($kat)) ?>" aria-labelledby="ann-ttl-<?= $ann_id ?>">
      <div class="kom-ann__meta">
        <?php if ($is_imp): ?><span class="mt-badge" style="background:var(--m-red)"><i class="bi bi-exclamation-lg" aria-hidden="true"></i>Ważna informacja</span><?php endif; ?>
        <?php if ($is_pinned): ?><span class="mt-badge mt-badge--warn"><i class="bi bi-pin-angle-fill" aria-hidden="true"></i>Przypięte</span><?php endif; ?>
        <?php if (!$is_read): ?><span class="mt-badge mt-badge--info">Nowe</span><?php endif; ?>
        <span><?= h(ann_kategoria_label($kat)) ?></span>
        <span><i class="bi bi-<?= ($ann['audience'] ?? '') === 'public' ? 'globe2' : 'people' ?>" aria-hidden="true"></i> <?= h(ann_audience_label($ann['audience'] ?? 'all')) ?></span>
        <span><?= h(date('d.m.Y', strtotime($ann['created_at'] ?? 'now'))) ?></span>
        <?php if (!empty($ann['expires_at'])): ?><span>ważne do <?= h(date('d.m.Y', strtotime($ann['expires_at']))) ?></span><?php endif; ?>
      </div>
      <h2 class="kom-ann__ttl" id="ann-ttl-<?= $ann_id ?>"><a href="<?= APP_URL ?>/komunikaty/announcement.php?id=<?= $ann_id ?>"><?= h($ann['title']) ?></a></h2>
      <?php if ($ann['body']): ?>
      <div class="kom-body<?= $collapsed ? ' kom-body--clip' : '' ?>" id="ann-body-<?= $ann_id ?>"><?= h($ann['body']) ?></div>
      <?php if ($collapsed): ?>
      <button type="button" class="kom-more" data-target="ann-body-<?= $ann_id ?>" aria-expanded="false" aria-controls="ann-body-<?= $ann_id ?>">
        <i class="bi bi-chevron-down" aria-hidden="true"></i><span>Rozwiń treść</span>
      </button>
      <?php endif; ?>
      <?php endif; ?>
      <div class="kom-ann__ft">
        <span class="kom-ann__author"><i class="bi bi-person" aria-hidden="true"></i> <?= h($ann['author_name'] ?? '') ?></span>
        <div class="kom-ann__acts">
          <a href="<?= APP_URL ?>/komunikaty/announcement.php?id=<?= $ann_id ?>" class="mt-btn mt-btn--ghost mt-btn--sm"><i class="bi bi-eye" aria-hidden="true"></i>Otwórz<span class="visually-hidden">: <?= h($ann['title']) ?></span></a>
          <?php if (!$is_read): ?>
          <form method="post" class="m-0">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="mark_ann" value="<?= $ann_id ?>">
            <button type="submit" class="mt-btn mt-btn--sm"><i class="bi bi-check2" aria-hidden="true"></i>Przeczytane<span class="visually-hidden">: <?= h($ann['title']) ?></span></button>
          </form>
          <?php else: ?>
          <span class="mt-badge mt-badge--ok"><i class="bi bi-check2-all" aria-hidden="true"></i>Przeczytane</span>
          <?php endif; ?>
          <?php if (is_admin()): ?>
          <form method="post" class="m-0" onsubmit="return confirm('Usunąć ogłoszenie „<?= h(addslashes($ann['title'])) ?>”? Tej operacji nie można cofnąć.')">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="delete_ann" value="<?= $ann_id ?>">
            <button type="submit" class="mt-btn mt-btn--danger mt-btn--sm" aria-label="Usuń ogłoszenie <?= h($ann['title']) ?>"><i class="bi bi-trash3" aria-hidden="true"></i></button>
          </form>
          <?php endif; ?>
        </div>
      </div>
    </article>
    <?php endforeach; ?>
    <?php endif; ?>
  </section>

  <!-- ═══════════ POWIADOMIENIA ═══════════ -->
  <section id="powiadomienia" data-pvm-panel="powiadomienia" role="tabpanel" aria-labelledby="pvm-tab-powiadomienia" tabindex="-1" hidden>
    <h2 class="mt-h2">Twoje <b>powiadomienia</b></h2>
    <?php if (empty($notifications)): ?>
    <div class="mt-empty"><i class="bi bi-bell-slash" style="font-size:2rem;display:block;margin-bottom:.5rem" aria-hidden="true"></i><p class="mb-0">Brak powiadomień.</p></div>
    <?php else: ?>
    <ul class="mt-feed" aria-label="Ostatnie powiadomienia">
      <?php foreach ($notifications as $n):
        $is_read_n = (int)($n['is_read'] ?? 0);
      ?>
      <li class="notif-item<?= $is_read_n ? '' : ' unread' ?>">
        <a href="<?= h($n['url'] ?: APP_URL . '/komunikaty/index.php') ?>" data-notif-id="<?= (int)$n['id'] ?>">
          <span class="av" style="--c:<?= h(notif_type_color($n['type'] ?? 'system')) ?>" aria-hidden="true"><i class="bi <?= notif_type_icon($n['type'] ?? 'system') ?>"></i></span>
          <span style="flex:1;min-width:0">
            <span class="meta"><strong><?= h($n['title']) ?></strong><span><?= h(substr($n['created_at'] ?? '', 0, 16)) ?></span></span>
            <?php if (!$is_read_n): ?><span class="mt-badge mt-badge--info notif-new">nowe</span><?php endif; ?>
          </span>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($notif_unread > 0): ?>
    <button type="button" class="mt-btn mt-btn--ghost mt-3" id="markAllRead"><i class="bi bi-check2-all" aria-hidden="true"></i>Oznacz wszystkie powiadomienia</button>
    <?php endif; ?>
    <?php endif; ?>
  </section>

</div><!-- /.pvm -->

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
        el.classList.remove('unread');
        var b = el.querySelector('.notif-new');
        if (b) b.remove();
      });
      markAllBtn.remove();
    });
  });
}
</script>

<?php require __DIR__ . '/_foot.php'; ?>
