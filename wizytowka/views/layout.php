<?php
/**
 * layout.php — wspólny layout części publicznej.
 * Zmienne z kontrolera: $content_view, $page_title, $page_desc, $canonical,
 * $page_keys, $noindex, $layout.
 */
$layoutMode = $layout ?? (in_array(setting('site_layout', 'stack'), ['bento', 'split'], true)
    ? setting('site_layout') : 'stack');
$title      = trim((string)($page_title ?? '')) !== '' ? (string)$page_title : setting('site_name', 'Wizytówka');
$fullTitle  = $title === setting('site_name') ? $title : $title . ' — ' . setting('site_name', 'Wizytówka');
$desc       = trim((string)($page_desc ?? setting('meta_description')));
$ogImage    = setting('og_image') ?: setting('avatar');
$cssVer     = @filemtime(APP_ROOT . '/assets/css/site.css') ?: time();
$font       = setting('font_family', 'Inter');
?>
<!DOCTYPE html>
<html lang="pl" class="theme-<?= e($layoutMode) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($fullTitle) ?></title>
<?php if ($desc !== ''): ?>
<meta name="description" content="<?= e($desc) ?>">
<?php endif; ?>
<?php if (!empty($page_keys)): ?>
<meta name="keywords" content="<?= e((string)$page_keys) ?>">
<?php endif; ?>
<?php if (!empty($noindex)): ?>
<meta name="robots" content="noindex, follow">
<?php endif; ?>
<link rel="canonical" href="<?= e($canonical ?? abs_url('/')) ?>">

<!-- Open Graph / Twitter -->
<meta property="og:site_name" content="<?= e(setting('site_name')) ?>">
<meta property="og:title" content="<?= e($title) ?>">
<meta property="og:description" content="<?= e($desc) ?>">
<meta property="og:type" content="website">
<meta property="og:url" content="<?= e($canonical ?? abs_url('/')) ?>">
<?php if ($ogImage !== ''): ?>
<meta property="og:image" content="<?= e(abs_url('/uploads/' . $ogImage)) ?>">
<meta name="twitter:card" content="summary_large_image">
<?php endif; ?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?display=swap&amp;family=<?= e(rawurlencode($font)) ?>:ital,wght@0,400;0,600;0,700;1,400">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= e(url('/assets/css/site.css')) ?>?v=<?= (int)$cssVer ?>">
<style>
:root{
  --bg: <?= e(setting('color_bg', '#F5F5F5')) ?>;
  --accent: <?= e(setting('color_accent', '#FF724F')) ?>;
  --accent-2: <?= e(setting('color_accent2', '#FF8466')) ?>;
  --text: <?= e(setting('color_text', '#D67F69')) ?>;
  --heading: <?= e(setting('color_heading', setting('color_text', '#D67F69'))) ?>;
  --btn-bg: <?= e(setting('color_btn_bg', '#FFFFFF')) ?>;
  --btn-text: <?= e(setting('color_btn_text', '#1A4757')) ?>;
  --rule: <?= e(setting('color_rule', '#D67F69')) ?>;
  --font: '<?= e($font) ?>', system-ui, -apple-system, 'Segoe UI', sans-serif;
}
<?php if (setting_bool('bg_pattern', true)): ?>
body::before{ background-image: url("<?= bg_pattern_svg(setting('color_accent', '#FF724F')) ?>"); }
<?php endif; ?>
</style>
<link rel="icon" href="<?= e(setting('avatar') ? upload_url(setting('avatar')) : url('/assets/favicon.svg')) ?>">
<script defer src="<?= e(url('/assets/js/site.js')) ?>?v=<?= (int)$cssVer ?>"></script>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>
<?= setting('analytics_code') /* zaufany fragment wklejony przez administratora */ ?>
</head>
<body class="layout-<?= e($layoutMode) ?>">

<main class="site" id="top">
  <div class="site__inner">
    <?php require $content_view; ?>
  </div>

  <footer class="site-footer">
    <?php if (setting('footer_text') !== ''): ?>
      <p class="site-footer__text"><?= e(setting('footer_text')) ?></p>
    <?php endif; ?>
    <p class="site-footer__meta">
      <a href="<?= e(url('/')) ?>"><?= e(setting('site_name')) ?></a>
      <?php if (setting('email') !== ''): ?>
        · <a href="mailto:<?= e(setting('email')) ?>"><?= e(setting('email')) ?></a>
      <?php endif; ?>
    </p>
  </footer>
</main>

</body>
</html>
