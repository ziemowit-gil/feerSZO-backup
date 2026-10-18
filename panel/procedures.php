<?php
/**
 * panel/procedures.php — Procedury (tryb tylko-do-odczytu) w panelu współpracownika.
 * Personel korzysta z pełnego modułu /procedures/. Wolontariusz widzi listę
 * opublikowanych procedur i ich treść w spójnej powłoce panelu.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/procedures.php';

require_login();
require_module_enabled('procedures_enabled', 'Moduł procedur');

$user = current_user();
// Osoba z umową / wolontariusz (panel_volunteer_shell) czyta procedury tutaj, w Panelu
// współpracownika. Do pełnego modułu z edycją (menu SZO) trafiają tylko admin i edytor.
$_is_volunteer_only = panel_volunteer_shell($user) && !in_array($user['role'] ?? '', ['admin', 'editor'], true);
if (!$_is_volunteer_only) {
    $to = isset($_GET['id'])
        ? '/procedures/view.php?id=' . (int)$_GET['id']
        : '/procedures/index.php';
    header('Location: ' . APP_URL . $to);
    exit;
}

$pid = (int)($_GET['id'] ?? 0);

if ($pid) {
    $proc = proc_get($pid);
    if (!$proc || $proc['status'] === 'deleted') {
        flash_set('danger', 'Procedura nie istnieje lub została usunięta.');
        header('Location: procedures.php'); exit;
    }
    $attachments = proc_get_attachments($pid);
    $PAGE_TITLE  = $proc['title'] . ' — Procedury';
} else {
    $filter = [
        'q'        => trim($_GET['q'] ?? ''),
        'category' => trim($_GET['category'] ?? ''),
        'status'   => 'active',   // wolontariusz widzi tylko opublikowane
    ];
    $procs      = proc_get_all($filter);
    $categories = proc_get_categories();
    $PAGE_TITLE = 'Procedury — Panel współpracownika';
}

include __DIR__ . '/includes/header_panel.php';
?>

<?php
require_once dirname(__DIR__) . '/modules/panel_metro/lib/panelMetro.php';
panelMetroAssets($_vol_color ?? '#0f6fa8');
$_pal = array_values(panelMetroPalette());
?>
<style>
.pvm .pr-search{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 1.25rem;max-width:760px}
.pvm .pr-search input{flex:1 1 260px;min-height:44px}
.pvm .pr-search select{flex:0 1 240px;min-height:44px}
.pvm .pr-list{display:grid;gap:8px;grid-template-columns:1fr}
@media(min-width:900px){.pvm .pr-list{grid-template-columns:1fr 1fr}}
.pvm .pr-item{display:flex;gap:.9rem;background:var(--pvm-light);border-left:8px solid var(--c);padding:1rem 1.1rem;text-decoration:none;color:var(--pvm-ink);min-width:0}
.pvm .pr-item:hover{background:var(--pvm-light-h);color:var(--pvm-ink)}
.pvm .pr-item h3{margin:0;font-size:1.02rem;font-weight:600;line-height:1.3}
.pvm .pr-item p{margin:.3rem 0 0;font-size:.84rem;color:var(--pvm-muted);overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
.pvm .pr-doc{background:var(--pvm-light);padding:1.5rem;line-height:1.65;white-space:pre-wrap;word-break:break-word;max-width:80ch}
</style>

<div class="pvm">
<?php if ($pid): /* ── Szczegóły procedury ──────────────────────────────── */ ?>

  <div class="mt-head">
    <div>
      <div class="mt-kicker">Procedury<?= !empty($proc['category']) ? ' · ' . h($proc['category']) : '' ?></div>
      <h1 class="mt-title"><?= h($proc['title']) ?></h1>
      <p class="mt-lead">Wersja <?= (int)$proc['version'] ?><?php if (!empty($proc['updated_at'])): ?> · aktualizacja <?= h(date_pl($proc['updated_at'])) ?><?php endif; ?></p>
    </div>
    <a href="procedures.php" class="mt-btn mt-btn--ghost"><i class="bi bi-arrow-left" aria-hidden="true"></i>Wszystkie procedury</a>
  </div>

  <?php if (trim((string)($proc['content'] ?? '')) !== ''): ?>
  <div class="pr-doc"><?= nl2br(h($proc['content'])) ?></div>
  <?php else: ?>
  <div class="mt-empty"><p class="mb-0">Brak treści — sprawdź załączniki poniżej.</p></div>
  <?php endif; ?>

  <?php if (!empty($attachments)): ?>
  <h2 class="mt-h2" style="margin-top:1.75rem"><b>Załączniki</b></h2>
  <?php foreach ($attachments as $a): $an = $a['original_name'] ?? $a['filename']; ?>
  <div class="mt-svc" style="--c:var(--m-blue);align-items:center">
    <i class="bi <?= h(proc_file_icon($an)) ?> ic" aria-hidden="true"></i>
    <div class="mt-svc__bd"><h3 style="word-break:break-all"><?= h($an) ?></h3><p><?= h(proc_filesize_human((int)($a['filesize'] ?? 0))) ?></p></div>
    <a href="<?= APP_URL ?>/procedures/serve.php?id=<?= (int)$a['id'] ?>&download" class="mt-btn mt-btn--ghost" aria-label="Pobierz <?= h($an) ?>"><i class="bi bi-download" aria-hidden="true"></i>Pobierz</a>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

<?php else: /* ── Lista procedur ───────────────────────────────────────── */
  $_cats = [];
  foreach ((array)$categories as $c) { $cn = is_array($c) ? ($c['category'] ?? '') : $c; if ($cn !== '') $_cats[] = $cn; }
  $_catIdx = array_flip($_cats);
  $_self = APP_URL . '/panel/procedures.php';
?>

  <div class="mt-head">
    <div>
      <div class="mt-kicker">Panel współpracownika · Wiedza</div>
      <h1 class="mt-title">Procedury</h1>
      <p class="mt-lead">Dokumenty i instrukcje organizacji — jak coś zrobić krok po kroku.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
      <?php if (function_exists('asai_enabled') && asai_enabled()): ?>
      <a href="<?= APP_URL ?>/panel/asystent.php" class="mt-btn"><i class="bi bi-stars" aria-hidden="true"></i>Zapytaj Asystenta AI</a>
      <?php endif; ?>
      <a href="<?= APP_URL ?>/panel/" class="mt-btn mt-btn--ghost"><i class="bi bi-arrow-left" aria-hidden="true"></i>Pulpit</a>
    </div>
  </div>

  <?php if ($_cats): ?>
  <h2 class="visually-hidden">Kategorie procedur</h2>
  <div class="mt-grid mt-grid--sm" style="margin-bottom:1.25rem">
    <?php
    panelMetroTile(['href'=>$_self . ($filter['q'] !== '' ? '?q=' . urlencode($filter['q']) : ''),'icon'=>'bi-journal-text','label'=>'Wszystkie',
                    'val'=>(string)count($procs),'color'=>$filter['category'] === '' ? 'var(--pvm-acc)' : 'dark','tag'=>$filter['category'] === '' ? 'wybrane' : '']);
    foreach ($_cats as $_i => $_c) {
        panelMetroTile(['href'=>$_self . '?' . http_build_query(array_filter(['category'=>$_c,'q'=>$filter['q']])),'icon'=>'bi-tag','label'=>$_c,
                        'color'=>$_pal[$_i % 8],'tag'=>$filter['category'] === $_c ? 'wybrane' : '']);
    }
    ?>
  </div>
  <?php endif; ?>

  <form method="get" class="pr-search" role="search" aria-label="Wyszukiwanie procedur">
    <?php if ($filter['category'] !== ''): ?><input type="hidden" name="category" value="<?= h($filter['category']) ?>"><?php endif; ?>
    <label for="pr-q" class="visually-hidden">Szukaj procedury</label>
    <input type="search" id="pr-q" name="q" value="<?= h($filter['q']) ?>" class="form-control" placeholder="Szukaj procedury…">
    <button type="submit" class="mt-btn"><i class="bi bi-search" aria-hidden="true"></i>Szukaj</button>
  </form>

  <h2 class="mt-h2"><?= $filter['category'] !== '' ? h($filter['category']) : 'Wszystkie <b>procedury</b>' ?> <span class="mt-no" style="font-size:1rem">(<?= count($procs) ?>)</span></h2>

  <?php if (empty($procs)): ?>
  <div class="mt-empty"><i class="bi bi-folder2-open" style="font-size:2rem;display:block;margin-bottom:.5rem" aria-hidden="true"></i>
    <p class="mb-0">Brak procedur<?= $filter['q'] !== '' || $filter['category'] !== '' ? ' dla podanych kryteriów' : '' ?>.</p></div>
  <?php else: ?>
  <div class="pr-list">
    <?php foreach ($procs as $p): $_ci = $_catIdx[$p['category'] ?? ''] ?? null; ?>
    <a href="procedures.php?id=<?= (int)$p['id'] ?>" class="pr-item" style="--c:<?= h($_ci === null ? 'var(--m-grey)' : $_pal[$_ci % 8]) ?>">
      <i class="bi bi-file-earmark-text" style="font-size:1.6rem;line-height:1.1" aria-hidden="true"></i>
      <span style="min-width:0;flex:1">
        <h3><?= h($p['title']) ?></h3>
        <span class="d-flex flex-wrap gap-1 mt-1">
          <span class="mt-badge">v<?= (int)$p['version'] ?></span>
          <?php if (!empty($p['category'])): ?><span class="mt-badge mt-badge--info"><?= h($p['category']) ?></span><?php endif; ?>
        </span>
        <?php if (!empty($p['content'])): ?><p><?= h(mb_substr(strip_tags($p['content']), 0, 220, 'UTF-8')) ?></p><?php endif; ?>
      </span>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

<?php endif; /* lista / szczegóły */ ?>
</div><!-- /.pvm -->

<?php include __DIR__ . '/includes/footer_panel.php'; ?>
