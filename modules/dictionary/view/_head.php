<?php
/**
 * modules/dictionary/view/_head.php — wspólny początek stron Słownika Centralnego.
 * Zmienne: $PAGE_TITLE, $crumbs ([etykieta => adres|null]), opcjonalnie $actions (HTML przycisków).
 * Szata Metro (akcent Admin) także dla osób spoza administracji, które widzą moduł dzięki uprawnieniu 'slowniki'.
 */
include dirname(__DIR__, 3) . '/includes/header.php';
if (empty($_szo_metro_group) && function_exists('szo_metro_assets')) szo_metro_assets('admin');
?>
<style>
  .dict-wrap .dict-tag{display:inline-block;padding:.05rem .45rem;font-size:.72rem;font-weight:600;letter-spacing:.02em;border:1px solid currentColor;border-radius:0;white-space:nowrap}
  .dict-wrap .dict-code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.82rem;color:#5f5f5f}
  .dict-wrap .dict-tree-pad{display:inline-block}
  .dict-wrap .dict-tile{display:block;padding:.9rem 1rem;background:#f2f2f2;color:#111827;text-decoration:none;border-left:4px solid var(--sm-acc,#a20025);height:100%}
  .dict-wrap .dict-tile:hover{background:#e6e6e6}
  .dict-wrap .dict-tile .n{font-size:1.6rem;font-weight:300;line-height:1}
  .dict-wrap .dict-muted{opacity:.55}
  .dict-wrap pre.dict-json{white-space:pre-wrap;word-break:break-word;font-size:.75rem;margin:0;max-width:34rem;background:#f2f2f2;padding:.35rem .5rem}
  .dict-wrap .table td,.dict-wrap .table th{vertical-align:middle}
</style>
<div class="dict-wrap">
<nav aria-label="Ścieżka" class="small mb-1">
  <?php $i = 0; foreach (($crumbs ?? []) as $label => $url): ?>
    <?= $i++ ? '<span class="text-body-secondary mx-1" aria-hidden="true">›</span>' : '' ?>
    <?php if ($url): ?><a href="<?= h($url) ?>"><?= h($label) ?></a><?php else: ?><span aria-current="page"><?= h($label) ?></span><?php endif; ?>
  <?php endforeach; ?>
</nav>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h1 class="h3 mb-0 fw-light"><i class="bi bi-book-half" aria-hidden="true"></i> <?= h($PAGE_TITLE) ?></h1>
  <div class="d-flex flex-wrap gap-2"><?= $actions ?? '' ?></div>
</div>
<?= flash_html() ?>
