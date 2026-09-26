<?php
/**
 * modules/gdpr_clauses/public/clause.php — publiczna strona klauzuli RODO.
 *
 * Adresy: /klauzula/{slug}[/{lang}] (reguła w .htaccess) lub bezpośrednio
 * clause.php?slug={slug}&lang={lang}. Brak wersji w danym języku → polska. ?embed=1 — wersja do iframe: bez nagłówka
 * i stopki, przezroczyste tło, wysyła wysokość do rodzica (postMessage,
 * odbiera ją public/embed.js). Ramkowanie z obcych domen dopuszcza
 * .htaccess (ta ścieżka jest wyjęta spod X-Frame-Options: SAMEORIGIN).
 *
 * Bez logowania; zmienne globalne czytane przy każdym żądaniu (brak cache),
 * więc zmiana adresu w panelu jest widoczna od razu wszędzie.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__, 3) . '/includes/db.php';
require_once dirname(__DIR__, 3) . '/includes/functions.php';
require_once dirname(__DIR__) . '/logic/gdpr_clauses.php';

$slug   = strtolower(trim((string)($_GET['slug'] ?? '')));
$embed  = !empty($_GET['embed']);
$reqLang = strtolower((string)($_GET['lang'] ?? GDPR_DEFAULT_LANG));
$svc    = new GdprClauseService();
$clause = $svc->getBySlug($slug, true, $reqLang);
$lang   = $clause['lang'] ?? (isset(GDPR_LANGS[$reqLang]) ? $reqLang : GDPR_DEFAULT_LANG);
$ui     = gdpr_clauses_ui($lang);
$langs  = $clause ? $svc->languagesFor($slug) : [];

header('Cache-Control: no-cache, must-revalidate');
header('X-Robots-Tag: ' . ($clause ? 'index, follow' : 'noindex'));
if (!$clause) http_response_code(404);

$vars    = $svc->variables();
$org     = $vars['company_name'] ?? (defined('ORG_NAME') ? ORG_NAME : '');
$title   = $clause['tytul'] ?? $ui['nf_title'];
$bodyHtml = $clause ? $svc->renderClause($clause) : '';
$updated = $clause && $clause['updated_at'] ? date('d.m.Y', strtotime($clause['updated_at'])) : '';
?><!doctype html>
<html lang="<?= h($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?><?= $org ? ' · ' . h($org) : '' ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<style type="text/tailwindcss">
  .gdpr-body p      { @apply mb-4 leading-relaxed; }
  .gdpr-body h2     { @apply text-lg font-semibold text-slate-900 mt-8 mb-3; }
  .gdpr-body h3     { @apply text-base font-semibold text-slate-900 mt-6 mb-2; }
  .gdpr-body ul     { @apply list-disc pl-6 mb-4 space-y-1; }
  .gdpr-body ol     { @apply list-decimal pl-6 mb-4 space-y-1; }
  .gdpr-body a      { @apply text-blue-700 underline underline-offset-2 break-words hover:text-blue-900; }
  .gdpr-body strong { @apply font-semibold text-slate-900; }
</style>
<style>@media print { .no-print { display: none !important; } body { background: #fff !important; } }</style>
</head>
<body class="<?= $embed ? 'bg-transparent' : 'bg-slate-50' ?> text-slate-700 antialiased">

<?php if ($embed): ?>
  <main class="gdpr-body text-[15px] p-1">
    <?php if ($clause): ?>
      <h1 class="text-xl font-bold text-slate-900 mb-4"><?= h($title) ?></h1>
      <?= $bodyHtml ?>
    <?php else: ?>
      <p class="text-slate-500"><?= h($ui['unavailable']) ?></p>
    <?php endif; ?>
  </main>
  <script>
  (function () {
    var send = function () {
      parent.postMessage({ type: 'gdpr-clause-height', slug: <?= json_encode($slug) ?>,
                           height: document.documentElement.scrollHeight }, '*');
    };
    window.addEventListener('load', send);
    if (window.ResizeObserver) new ResizeObserver(send).observe(document.body);
  })();
  </script>
<?php else: ?>
  <div class="min-h-screen flex flex-col">
    <header class="bg-white border-b border-slate-200">
      <div class="max-w-3xl mx-auto px-4 sm:px-6 py-4 flex items-center justify-between gap-4">
        <span class="text-sm font-medium text-slate-900 truncate"><?= h($org) ?></span>
        <div class="flex items-center gap-3 shrink-0">
          <span class="hidden sm:inline text-xs uppercase tracking-wide text-slate-400"><?= h($ui['kicker']) ?></span>
          <?php if (count($langs) > 1): ?>
            <nav aria-label="<?= h($ui['lang']) ?>" class="no-print">
              <ul class="flex gap-1 text-xs">
                <?php foreach ($langs as $code => $_t): ?>
                  <li><a href="<?= h(gdpr_clauses_public_url($slug, false, $code)) ?>" hreflang="<?= h($code) ?>" lang="<?= h($code) ?>"
                         class="px-2 py-1 rounded-md uppercase <?= $code === $lang ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100' ?>"
                         <?= $code === $lang ? 'aria-current="true"' : '' ?> title="<?= h(GDPR_LANGS[$code]) ?>"><?= h($code) ?></a></li>
                <?php endforeach; ?>
              </ul>
            </nav>
          <?php endif; ?>
        </div>
      </div>
    </header>

    <main class="flex-1 max-w-3xl w-full mx-auto px-4 sm:px-6 py-8 sm:py-12">
      <?php if ($clause): ?>
        <article class="bg-white rounded-2xl shadow-sm ring-1 ring-slate-200 px-5 py-8 sm:px-10 sm:py-10">
          <h1 class="text-2xl sm:text-3xl font-bold text-slate-900 leading-tight mb-2"><?= h($title) ?></h1>
          <?php if ($updated): ?>
            <p class="text-sm text-slate-500 mb-8"><?= h($ui['updated']) ?>: <time datetime="<?= h(substr($clause['updated_at'], 0, 10)) ?>"><?= h($updated) ?></time></p>
          <?php endif; ?>
          <div class="gdpr-body text-[15px] sm:text-base"><?= $bodyHtml ?></div>
          <div class="no-print mt-10 pt-6 border-t border-slate-100 flex justify-end">
            <button type="button" onclick="window.print()" class="text-sm text-slate-500 hover:text-slate-800 inline-flex items-center gap-1.5">
              <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9V2h12v7M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2M6 14h12v8H6z"/></svg>
              <?= h($ui['print']) ?>
            </button>
          </div>
        </article>
      <?php else: ?>
        <div class="bg-white rounded-2xl ring-1 ring-slate-200 px-6 py-16 text-center">
          <h1 class="text-xl font-semibold text-slate-900 mb-2"><?= h($ui['nf_title']) ?></h1>
          <p class="text-slate-500"><?= h($ui['nf_body']) ?></p>
        </div>
      <?php endif; ?>
    </main>

    <footer class="no-print text-center text-xs text-slate-400 pb-8 px-4">
      <?= h($org) ?><?= !empty($vars['address']) ? ' · ' . h($vars['address']) : '' ?>
    </footer>
  </div>
<?php endif; ?>
</body>
</html>
