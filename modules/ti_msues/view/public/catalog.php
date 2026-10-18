<?php
/**
 * modules/ti_msues/view/public/catalog.php — strona publicznego katalogu szkoleń (lista i karta szkolenia).
 * Wygląd jak publiczny plan zajęć: portal.css (+ portal_metro.css w szacie Metro), lista z lewej, niebieski pasek z nazwą.
 * Zmienne: $entries (MsuesCatalog::entries), $entry (wybrany wpis albo null), $id. Wejście: karty30/ti/dydaktyk/katalog.php.
 */
$org = ti_org_contact_info();
$orgName = trim((string)($org['name'] ?? ''));
$skin = tiPlanPortalSkin();
$planUi = dirname(__DIR__, 3) . '/ti_plan_public/ui';
$heading = $entry ? $entry['title'] : ($id ? 'Nie ma takiego szkolenia' : 'Katalog szkoleń');
$fmtD = fn(?string $d) => $d ? date('d.m.Y', strtotime($d)) : '';
$fmtH = fn(float $h) => rtrim(rtrim(number_format($h, 1, ',', ''), '0'), ',') . ' h';
$lines = fn(string $t) => array_values(array_filter(array_map('trim', preg_split('/\R/u', $t) ?: [])));
$para = function (string $t) use ($lines): string {
    $l = $lines($t);
    if (count($l) > 1) return '<ul class="note-ul">' . implode('', array_map(fn($x) => '<li>' . h(ltrim($x, "-•* \t")) . '</li>', $l)) . '</ul>';
    return $l ? '<p class="note-p">' . h($l[0]) . '</p>' : '';
};
header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="referrer" content="no-referrer">
<title><?= h($heading) ?><?= $orgName !== '' ? ' — ' . h($orgName) : '' ?></title>
<?php if ($entry && trim($entry['summary']) !== ''): ?><meta name="description" content="<?= h(mb_strimwidth($entry['summary'], 0, 160, '…')) ?>"><?php endif; ?>
<style><?php readfile($planUi . '/portal.css'); ?></style>
<?php if ($skin === 'metro'): ?><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
<style><?php readfile($planUi . '/portal_metro.css'); ?></style><?php endif; ?>
<style>
  .cat-facts { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 8px; margin: 0 auto 10px; max-width: 900px; padding: 0; list-style: none; }
  .cat-facts li { border: 1px solid #9aa6cf; border-top: 4px solid var(--title-bg); background: var(--hour-bg); padding: 8px 10px; }
  .cat-facts b { display: block; font-size: 11px; color: var(--muted); font-weight: normal; }
  .cat-facts span { font-weight: bold; }
  .cat-prog h3 { font: bold 12px Verdana, sans-serif; margin: 6px 0 2px; }
  .tile .badge-msues { align-self: flex-start; font-size: 10px; border: 1px solid var(--ok); color: var(--ok); padding: 0 4px; }
</style>
</head>
<body class="skin-<?= $skin ?>">
<a class="skip" href="#tresc">Przejdź do katalogu</a>
<div class="layout">
  <nav class="side" aria-label="Lista szkoleń">
    <button type="button" class="side-toggle" aria-expanded="false" aria-controls="sideBody" data-side-toggle>☰ Szkolenia</button>
    <div class="side-body" id="sideBody" data-collapsed="1">
      <p class="org"><a href="<?= h(MsuesCatalog::url()) ?>"><?= h($orgName !== '' ? $orgName : 'Katalog szkoleń') ?></a><br>Katalog szkoleń</p>
      <h2>Szkolenia</h2>
      <?php if (!$entries): ?><p class="none">brak</p><?php else: ?>
      <ul><?php foreach ($entries as $e): ?>
        <li><a href="<?= h(MsuesCatalog::url($e['id'])) ?>"<?= $entry && $entry['id'] === $e['id'] ? ' aria-current="page"' : '' ?>><?= h($e['title']) ?></a></li>
      <?php endforeach; ?></ul>
      <?php endif; ?>
      <h2><a href="<?= h(tiPlanPortalUrlSafe([])) ?>" style="color:inherit;text-decoration:none">Plan zajęć</a></h2>
    </div>
  </nav>
  <main class="page" id="tresc">
    <div class="titlebar">
      <h1 class="title"><?= h($heading) ?></h1>
      <a class="skin-btn" href="<?= tiPlanPortalSkinUrl($skin === 'metro' ? 'klasyczny' : 'metro') ?>" title="Przełącz wygląd (zapamiętany w tej przeglądarce)"><?= $skin === 'metro' ? 'Widok klasyczny' : 'Widok Metro' ?></a>
    </div>
    <?php if ($entry): ?>
    <nav class="crumbs" aria-label="Okruszki"><a href="<?= h(MsuesCatalog::url()) ?>">Katalog szkoleń</a> / <strong aria-current="page"><?= h($entry['title']) ?></strong></nav>
    <?php endif; ?>
    <div class="content">

<?php if (!$entry && $id): ?>
    <div class="box"><p class="note-p">To szkolenie nie jest (już) w katalogu. <a href="<?= h(MsuesCatalog::url()) ?>">Wróć do listy szkoleń</a>.</p></div>

<?php elseif (!$entry): ?>
    <div class="box"><p class="note-p">Szkolenia prowadzone przez <?= h($orgName !== '' ? $orgName : 'naszą organizację') ?>: cele opisane jako efekty uczenia się, wymagania, program i czas trwania. Wybierz szkolenie, aby zobaczyć szczegóły i terminy.</p></div>
    <?php if (!$entries): ?><p class="empty">Katalog jest w przygotowaniu.</p><?php else: ?>
    <ul class="tiles">
      <?php foreach ($entries as $e): ?>
      <li><a class="tile" href="<?= h(MsuesCatalog::url($e['id'])) ?>">
        <strong><?= h($e['title']) ?></strong>
        <?php if ($e['msues']): ?><span class="badge-msues">standard MSUES</span><?php endif; ?>
        <small><?= h(implode(' · ', array_filter([$e['hours'] ? $fmtH($e['hours']) : '', $e['form'], $e['next'] ? 'od ' . $fmtD($e['next']) : ($e['ended'] ? 'zakończone' : '')]))) ?></small>
        <?php if (trim($e['summary']) !== ''): ?><small><?= h(mb_strimwidth($e['summary'], 0, 140, '…')) ?></small><?php endif; ?>
      </a></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>

<?php else: $e = $entry; ?>
    <ul class="cat-facts">
      <li><b>Czas trwania</b><span><?= $e['hours'] ? h($fmtH($e['hours'])) . ($e['hours_src'] === 'program' ? ' <small>(wg programu)</small>' : '') : 'do ustalenia' ?></span></li>
      <li><b>Forma</b><span><?= h($e['form']) ?></span><?= $e['location'] !== '' ? '<br><small>' . h($e['location']) . '</small>' : '' ?></li>
      <li><b>Terminy</b><span><?= $e['first'] ? h($fmtD($e['first'])) . ($e['last'] !== $e['first'] ? ' – ' . h($fmtD($e['last'])) : '') : 'do ustalenia' ?></span><?= $e['days'] ? '<br><small>' . (int)$e['days'] . ' dni zajęć</small>' : '' ?></li>
      <li><b>Najbliższe zajęcia</b><span><?= $e['next'] ? h($fmtD($e['next'])) : ($e['ended'] ? 'szkolenie zakończone' : '—') ?></span><?= $e['plan_url'] !== '' ? '<br><small><a href="' . h($e['plan_url']) . '">plan zajęć</a></small>' : '' ?></li>
    </ul>
    <?php if (trim($e['summary']) !== ''): ?>
    <section class="box"><h2>O szkoleniu</h2><?= $para($e['summary']) ?></section>
    <?php endif; ?>
    <?php if (trim($e['audience']) !== ''): ?><section class="box"><h2>Dla kogo</h2><?= $para($e['audience']) ?></section><?php endif; ?>
    <section class="box"><h2>Efekty uczenia się</h2><?= trim($e['outcomes']) !== '' ? $para($e['outcomes']) : '<p class="note-p">W przygotowaniu.</p>' ?></section>
    <section class="box"><h2>Wymagania wstępne</h2><?= trim($e['requirements']) !== '' ? $para($e['requirements']) : '<p class="note-p">Brak szczególnych wymagań.</p>' ?></section>
    <section class="box cat-prog"><h2>Program</h2>
      <?php if (!$e['program']): ?><p class="note-p">W przygotowaniu.</p><?php endif; ?>
      <?php foreach ($e['program'] as $sec => $topics): ?>
        <?php if ($sec !== ''): ?><h3><?= h($sec) ?></h3><?php endif; ?>
        <ul class="note-ul"><?php foreach ($topics as $t): ?><li><?= h($t) ?></li><?php endforeach; ?></ul>
      <?php endforeach; ?>
    </section>
    <?php if ($e['staff']): ?><section class="box"><h2>Kadra</h2><p class="note-p"><?= h(implode(', ', $e['staff'])) ?></p>
      <?php $kadra = msuesSetting('msues_doc_kadra'); if (preg_match('#^https?://\S+$#i', $kadra)): ?><p class="note-p"><a href="<?= h($kadra) ?>">Kwalifikacje i doświadczenie trenerów</a></p><?php endif; ?></section><?php endif; ?>
    <?php if (trim($e['support']) !== ''): ?><section class="box"><h2>Wsparcie po szkoleniu</h2><?= $para($e['support']) ?></section><?php endif; ?>
    <?php if (trim($e['certificate']) !== ''): ?><section class="box"><h2>Dokument po ukończeniu</h2><?= $para($e['certificate']) ?></section><?php endif; ?>
<?php endif; ?>

    <?php $links = MsuesCatalog::orgLinks(); if ($links): ?>
    <section class="box"><h2>O organizacji</h2><ul>
      <?php foreach ($links as [$l, $u]): ?><li><a href="<?= h($u) ?>"><?= h($l) ?></a></li><?php endforeach; ?>
    </ul></section>
    <?php endif; ?>
    </div>
    <footer class="site-foot">
      <span>Katalog może ulec zmianie — aktualna wersja zawsze pod tym adresem. Wygenerowano <?= h(date('d.m.Y H:i')) ?>.</span>
      <span><?= h(implode(' · ', array_filter([(string)($org['name'] ?? ''), (string)($org['phone'] ?? ''), (string)($org['email'] ?? '')]))) ?></span>
    </footer>
  </main>
</div>
<script>
(function () {
  var tg = document.querySelector('[data-side-toggle]'), body = document.getElementById('sideBody');
  if (tg && body) tg.addEventListener('click', function () {
    var open = body.getAttribute('data-collapsed') === '1';
    body.setAttribute('data-collapsed', open ? '0' : '1'); tg.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
})();
</script>
</body>
</html>
