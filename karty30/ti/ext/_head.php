<?php
/**
 * karty30/ti/ext/_head.php — chrome stron modułu (layout paneli + skórka ti-skin).
 * Zmienne wejściowe: $EXT_TITLE, $EXT_TAB ('katalog'|'wgraj'|'ustawienia'|'dziennik'),
 *                    $EXT_SUBJECT, $EXT_MANAGE.
 */
$KP_TITLE  = ($EXT_TITLE ?? 'Materiały zewnętrzne') . ' — Materiały zewnętrzne';
$KP_TOPBAR = [
    'brand'  => 'Materiały zewnętrzne',
    'icon'   => 'book',
    'user'   => (string)($EXT_SUBJECT['name'] ?? ''),
    // Wylogowanie musi trafić w TĘ sesję, w której użytkownik siedzi — moduł
    // obsługuje trzy i każda ma własny adres (patrz ext_logout_url).
    'logout' => ext_logout_url($EXT_LAYER ?? null),
    // Powrót do panelu obok wylogowania, a nie w pasku sekcji: to nie jest sekcja modułu.
    'extra'  => '<a href="' . h(ext_back_url($EXT_LAYER ?? null)) . '" class="btn btn-outline-secondary btn-sm">'
              . '<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Wróć do panelu</a>',
];
$KP_BODY_CLASS = 'ti-skin ext-mod';
// Stopka panelu kursanta przekierowuje na ostatnio oglądaną zakładkę, gdy
// w adresie nie ma ?tab= — tutaj adresów z zakładkami nie ma wcale, więc
// bez tej flagi moduł wyrzucałby użytkownika do panelu przy każdym wejściu.
$KP_SKIP_TAB_MEMORY = true;
include __DIR__ . '/../kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<style>
/* Skórka panelowa nie zna kart katalogu — dokładamy tylko to, czego brakuje. */
body.ext-mod .ext-grid { display:grid; gap:.75rem; grid-template-columns:repeat(auto-fill,minmax(15rem,1fr)); }
body.ext-mod .ext-card { border:1px solid var(--ti-grid); background:#fff; padding:.6rem; }
body.ext-mod .ext-card h3 { font-size:.85rem; margin:0 0 .2rem; }
body.ext-mod .ext-meta { font-size:.72rem; color:#45516b; }
body.ext-mod .ext-deny { font-size:.72rem; color:#8a1c1c; }
body.ext-mod .ext-drop { border:2px dashed var(--ti-bar-border); padding:1.5rem; text-align:center; background:#fafbfc; }
body.ext-mod .ext-drop.over { background:#eef3f9; border-color:var(--ti-blue); }
body.ext-mod .ext-tree-depth-1 { padding-left:1.2rem; }
body.ext-mod .ext-tree-depth-2 { padding-left:2.4rem; }
/* Plakietki w treści: jaskrawa paleta Bootstrapa gryzła się ze stonowaną
   skórką, a żółte tło z białym tekstem miało 1,9:1. Te odcienie trzymają
   ten sam język kolorystyczny i mają powyżej 7:1 — a stan i tak niesie SŁOWO,
   nie sam kolor. */
body.ext-mod .badge.text-bg-warning   { background:#fdf0d0 !important; color:#6b4a00 !important; }
body.ext-mod .badge.text-bg-success   { background:#dcefdc !important; color:#14532d !important; }
body.ext-mod .badge.text-bg-danger    { background:#ffd6d6 !important; color:#8a1c1c !important; }
body.ext-mod .badge.text-bg-secondary { background:#e3e8ef !important; color:#33415c !important; }
</style>

<nav class="skin-sections" aria-label="Sekcje modułu">
  <a href="index.php" <?= ($EXT_TAB ?? '') === 'katalog' ? 'aria-current="page"' : '' ?>>Katalog</a>
  <?php if (!empty($EXT_MANAGE)): ?>
  <a href="upload.php"   <?= ($EXT_TAB ?? '') === 'wgraj'      ? 'aria-current="page"' : '' ?>>Wgraj materiał</a>
  <a href="admin.php"    <?= ($EXT_TAB ?? '') === 'ustawienia' ? 'aria-current="page"' : '' ?>>Wydawcy i dostęp</a>
  <a href="log.php"      <?= ($EXT_TAB ?? '') === 'dziennik'   ? 'aria-current="page"' : '' ?>>Dziennik</a>
  <?php endif; ?>
</nav>

<main id="main" class="dyd-wrap">
<?= function_exists('flash_html') ? flash_html() : '' ?>
<?php if (!ext_enabled()): ?>
<div class="alert alert-warning" role="status">
  <i class="bi bi-pause-circle me-1" aria-hidden="true"></i>
  <strong>Moduł jest wyłączony.</strong> Katalog widać, ale żaden plik się nie otworzy
  <?php if (!empty($EXT_MANAGE)): ?>— włącz go w <a href="admin.php?tab=wydawcy" class="alert-link">ustawieniach</a><?php endif; ?>.
</div>
<?php endif; ?>
