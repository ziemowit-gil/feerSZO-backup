<?php
/**
 * panel/org_documents.php — „Dokumenty organizacji" w panelu współpracownika (odczyt).
 * Admin/edytor zarządza w /admin/org_documents.php; pozostali (wolontariusz, konsultant TI,
 * inne role) przeglądają i pobierają tutaj. Wygląd METRO (modules/panel_metro): kafle
 * kategorii, wyszukiwarka, lista dokumentów z paskiem koloru.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/org_docs.php';

require_login();
require_module_enabled('org_documents_enabled', 'Moduł dokumentów organizacji');

$user = current_user();
$_is_volunteer_only = is_viewer()
    && !user_is_k30_consultant((int)$user['id']);

// Zarządzanie tylko dla admina/edytora — reszta ogląda tutaj (wcześniej każdy „nie-wolontariusz”
// trafiał do panelu admina i dostawał „Brak uprawnień do tej strony”).
if (in_array($user['role'] ?? '', ['admin', 'editor'], true) && !isset($_GET['podglad'])) {
    header('Location: ' . APP_URL . '/admin/org_documents.php'); exit;
}

$filter = [
    'q'           => trim($_GET['q'] ?? ''),
    'category'    => trim($_GET['category'] ?? ''),
    'active_only' => true,                 // tylko widoczne
    'user_id'     => (int)($user['id'] ?? 0), // + filtr widoczności wg jednostek
];
$docs       = org_docs_all($filter);
$categories = org_docs_categories();
$all_count  = ($filter['q'] === '' && $filter['category'] === '') ? count($docs)
    : count(org_docs_all(['active_only' => true, 'user_id' => (int)($user['id'] ?? 0)]));
$PAGE_TITLE = 'Dokumenty organizacji — Panel współpracownika';

if ($_is_volunteer_only) {
    include __DIR__ . '/includes/header_panel.php';
} else {
    include dirname(__DIR__) . '/includes/header.php';
}
require_once dirname(__DIR__) . '/modules/panel_metro/lib/panelMetro.php';
panelMetroAssets($_vol_color ?? '#0f6fa8');
$_pal = array_values(panelMetroPalette());
$_self = APP_URL . '/panel/org_documents.php';
$_q    = fn(array $p) => $_self . (($p = array_filter($p, fn($v) => $v !== '')) ? '?' . http_build_query($p) : '');
?>
<style>
.pvm .od-search{display:flex;gap:6px;margin:0 0 1.25rem;max-width:640px}
.pvm .od-search input{flex:1;min-height:44px}
.pvm .od-doc{display:flex;align-items:flex-start;gap:1rem;background:var(--pvm-light);border-left:8px solid var(--c);padding:1rem 1.1rem;margin-bottom:8px;min-width:0}
.pvm .od-doc>.ic{font-size:1.7rem;color:var(--pvm-ink);width:2.25rem;text-align:center;flex-shrink:0;line-height:1.1}
.pvm .od-doc h3{margin:0;font-size:1.02rem;font-weight:600;color:var(--pvm-ink);display:flex;flex-wrap:wrap;align-items:center;gap:.4rem}
.pvm .od-doc p{margin:.2rem 0 0;font-size:.85rem;color:var(--pvm-muted)}
@media(max-width:576px){.pvm .od-doc{flex-wrap:wrap}.pvm .od-doc .mt-btn{width:100%}}
</style>

<div class="pvm">
  <div class="mt-head">
    <div>
      <div class="mt-kicker">Panel współpracownika · Dokumenty</div>
      <h1 class="mt-title">Dokumenty organizacji</h1>
      <p class="mt-lead">Statut, regulaminy, formularze i wzory do pobrania.</p>
    </div>
    <a href="<?= APP_URL ?>/panel/" class="mt-btn mt-btn--ghost"><i class="bi bi-arrow-left" aria-hidden="true"></i>Pulpit</a>
  </div>

  <!-- Kategorie jako kafle (klik = filtr) -->
  <?php if ($categories): ?>
  <h2 class="visually-hidden">Kategorie dokumentów</h2>
  <div class="mt-grid mt-grid--sm" style="margin-bottom:1.5rem">
    <?php
    panelMetroTile(['href'=>$_q(['q'=>$filter['q']]),'icon'=>'bi-folder2-open','label'=>'Wszystkie','val'=>(string)$all_count,
                    'color'=>$filter['category'] === '' ? 'var(--pvm-acc)' : 'dark','tag'=>$filter['category'] === '' ? 'wybrane' : '']);
    foreach (array_values($categories) as $_i => $_c) {
        panelMetroTile(['href'=>$_q(['category'=>$_c,'q'=>$filter['q']]),'icon'=>'bi-tag','label'=>$_c,
                        'color'=>$_pal[$_i % 8],'tag'=>$filter['category'] === $_c ? 'wybrane' : '']);
    }
    ?>
  </div>
  <?php endif; ?>

  <form method="get" class="od-search" role="search" aria-label="Wyszukiwanie dokumentów">
    <?php if ($filter['category'] !== ''): ?><input type="hidden" name="category" value="<?= h($filter['category']) ?>"><?php endif; ?>
    <label for="od-q" class="visually-hidden">Szukaj dokumentu</label>
    <input type="search" id="od-q" name="q" value="<?= h($filter['q']) ?>" class="form-control" placeholder="Szukaj dokumentu…">
    <button type="submit" class="mt-btn"><i class="bi bi-search" aria-hidden="true"></i>Szukaj</button>
  </form>

  <h2 class="mt-h2"><?= $filter['category'] !== '' ? h($filter['category']) : 'Wszystkie <b>dokumenty</b>' ?> <span class="mt-no" style="font-size:1rem">(<?= count($docs) ?>)</span></h2>

  <?php if (empty($docs)): ?>
  <div class="mt-empty">
    <i class="bi bi-folder2-open" style="font-size:2rem;display:block;margin-bottom:.5rem" aria-hidden="true"></i>
    <p class="mb-0">Brak dokumentów<?= $filter['q'] !== '' || $filter['category'] !== '' ? ' dla podanych kryteriów' : '' ?>.</p>
  </div>
  <?php else: ?>
  <?php $_catIdx = array_flip(array_values($categories)); ?>
  <?php foreach ($docs as $d): $_ci = $_catIdx[$d['category'] ?? ''] ?? null; ?>
  <article class="od-doc" style="--c:<?= h($_ci === null ? 'var(--m-grey)' : $_pal[$_ci % 8]) ?>">
    <i class="bi <?= h(org_docs_file_icon($d['original_name'])) ?> ic" aria-hidden="true"></i>
    <div style="flex:1;min-width:0">
      <h3><?= h($d['title']) ?>
        <span class="mt-badge">v<?= h($d['version'] ?? '1') ?></span>
        <?php if (!empty($d['category'])): ?><span class="mt-badge mt-badge--info"><?= h($d['category']) ?></span><?php endif; ?>
        <?php if (($d['visibility'] ?? 'all') === 'unit'): ?><span class="mt-badge mt-badge--warn"><?= h($d['unit_name'] ?: 'Twoja jednostka') ?></span><?php endif; ?>
      </h3>
      <?php if (!empty($d['description'])): ?><p><?= h($d['description']) ?></p><?php endif; ?>
      <p><?= h($d['original_name']) ?> · <?= h(org_docs_filesize_human((int)$d['file_size'])) ?><?php if (!empty($d['owner_name'])): ?> · lider: <?= h($d['owner_name']) ?><?php endif; ?></p>
    </div>
    <a href="<?= APP_URL ?>/org_documents/serve.php?id=<?= (int)$d['id'] ?>&download" class="mt-btn mt-btn--ghost" aria-label="Pobierz: <?= h($d['title']) ?>"><i class="bi bi-download" aria-hidden="true"></i>Pobierz</a>
  </article>
  <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php
if ($_is_volunteer_only) {
    include __DIR__ . '/includes/footer_panel.php';
} else {
    include dirname(__DIR__) . '/includes/footer.php';
}
